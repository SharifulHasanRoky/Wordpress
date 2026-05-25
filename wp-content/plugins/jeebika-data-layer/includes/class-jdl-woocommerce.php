<?php
if (!defined('ABSPATH')) exit;

class JDL_WooCommerce {

    private static $instance = null;
    private $settings;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = JDL_Settings::get_instance();

        // view_item - Product page
        add_action('woocommerce_after_single_product', [$this, 'track_view_item']);

        // view_item_list - Shop/Category pages
        add_action('woocommerce_after_shop_loop', [$this, 'track_view_item_list']);

        // view_cart - Cart page
        add_action('woocommerce_after_cart', [$this, 'track_view_cart']);

        // remove_from_cart
        add_action('woocommerce_cart_item_removed', [$this, 'track_remove_from_cart'], 10, 2);

        // begin_checkout
        add_action('woocommerce_before_checkout_form', [$this, 'track_begin_checkout']);

        // add_shipping_info + add_payment_info
        add_action('woocommerce_checkout_after_customer_details', [$this, 'track_checkout_progress']);

        // purchase
        add_action('woocommerce_thankyou', [$this, 'track_purchase']);

        // AJAX endpoint for add_to_cart item data
        add_action('wp_ajax_jdl_get_cart_item', [$this, 'ajax_get_cart_item']);
        add_action('wp_ajax_nopriv_jdl_get_cart_item', [$this, 'ajax_get_cart_item']);

        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function enqueue_scripts() {
        if (!is_woocommerce() && !is_cart() && !is_checkout() && !is_order_received_page()) return;

        wp_enqueue_script(
            'jdl-woocommerce',
            JDL_PLUGIN_URL . 'assets/js/jdl-woocommerce.js',
            ['jquery'],
            JDL_VERSION,
            true
        );

        wp_localize_script('jdl-woocommerce', 'jdlWoo', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jdl_woo_nonce'),
            'currency' => get_woocommerce_currency(),
            'add_to_cart' => $this->settings->is_enabled('track_add_to_cart'),
            'remove_from_cart' => $this->settings->is_enabled('track_remove_from_cart'),
            'select_item' => $this->settings->is_enabled('track_select_item'),
        ]);
    }

    // ======================== view_item ========================
    public function track_view_item() {
        if (!$this->settings->is_enabled('track_view_item')) return;
        global $product;
        if (!$product) return;

        $item = $this->build_item($product, 0);
        $this->push_ecommerce_event('view_item', [
            'currency' => get_woocommerce_currency(),
            'value' => (float) $product->get_price(),
            'items' => [$item],
        ]);
    }

    // ======================== view_item_list ========================
    public function track_view_item_list() {
        if (!$this->settings->is_enabled('track_view_item_list')) return;
        global $wp_query;

        $items = [];
        $list_name = $this->get_list_name();
        $list_id = $this->get_list_id();

        if ($wp_query->posts) {
            foreach ($wp_query->posts as $index => $post) {
                $product = wc_get_product($post->ID);
                if (!$product) continue;

                $item = $this->build_item($product, $index);
                $item['item_list_name'] = $list_name;
                $item['item_list_id'] = $list_id;
                $items[] = $item;
            }
        }

        if (empty($items)) return;

        $this->push_ecommerce_event('view_item_list', [
            'currency' => get_woocommerce_currency(),
            'item_list_name' => $list_name,
            'item_list_id' => $list_id,
            'items' => $items,
        ]);
    }

    // ======================== view_cart ========================
    public function track_view_cart() {
        if (!$this->settings->is_enabled('track_view_cart')) return;
        $cart = WC()->cart;

        $items = [];
        $index = 0;
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $item = $this->build_item($product, $index);
            $item['quantity'] = $cart_item['quantity'];
            $items[] = $item;
            $index++;
        }

        $this->push_ecommerce_event('view_cart', [
            'currency' => get_woocommerce_currency(),
            'value' => (float) $cart->get_cart_contents_total(),
            'items' => $items,
        ]);
    }

    // ======================== remove_from_cart ========================
    public function track_remove_from_cart($cart_item_key, $cart) {
        if (!$this->settings->is_enabled('track_remove_from_cart')) return;

        $removed = $cart->removed_cart_contents[$cart_item_key] ?? null;
        if (!$removed) return;

        $product = wc_get_product($removed['variation_id'] ?: $removed['product_id']);
        if (!$product) return;

        $item = $this->build_item($product, 0);
        $item['quantity'] = $removed['quantity'];

        WC()->session->set('jdl_removed_item', [
            'currency' => get_woocommerce_currency(),
            'value' => (float) $product->get_price() * $removed['quantity'],
            'items' => [$item],
        ]);
    }

    // ======================== begin_checkout ========================
    public function track_begin_checkout() {
        if (!$this->settings->is_enabled('track_begin_checkout')) return;
        $cart = WC()->cart;

        $items = [];
        $index = 0;
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $item = $this->build_item($product, $index);
            $item['quantity'] = $cart_item['quantity'];
            $items[] = $item;
            $index++;
        }

        $coupons = $cart->get_applied_coupons();

        $this->push_ecommerce_event('begin_checkout', [
            'currency' => get_woocommerce_currency(),
            'value' => (float) $cart->get_cart_contents_total(),
            'coupon' => !empty($coupons) ? implode(',', $coupons) : '',
            'items' => $items,
        ]);
    }

    // ======================== add_shipping_info + add_payment_info ========================
    public function track_checkout_progress() {
        if (!$this->settings->is_enabled('track_add_shipping_info')) return;
        $currency = get_woocommerce_currency();
        ?>
        <script>
        (function($) {
            var currency = '<?php echo esc_js($currency); ?>';
            var shippingFired = false;

            $(document).on('updated_checkout', function() {
                if (shippingFired) return;
                var method = $('input[name^="shipping_method"]:checked').val() ||
                             $('input[name^="shipping_method"]').val() || '';
                if (!method) return;
                shippingFired = true;

                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({ecommerce: null});
                window.dataLayer.push({
                    'event': 'add_shipping_info',
                    'ecommerce': {
                        'currency': currency,
                        'value': parseFloat($('.order-total .amount').text().replace(/[^0-9.]/g,'')) || 0,
                        'shipping_tier': method,
                        'items': window.jdlCheckoutItems || []
                    }
                });
            });

            $(document).on('change', 'input[name="payment_method"]', function() {
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({ecommerce: null});
                window.dataLayer.push({
                    'event': 'add_payment_info',
                    'ecommerce': {
                        'currency': currency,
                        'value': parseFloat($('.order-total .amount').text().replace(/[^0-9.]/g,'')) || 0,
                        'payment_type': $(this).val(),
                        'items': window.jdlCheckoutItems || []
                    }
                });
            });
        })(jQuery);
        </script>
        <?php

        // Store checkout items in JS for shipping/payment events
        $cart = WC()->cart;
        $items = [];
        $index = 0;
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $item = $this->build_item($product, $index);
            $item['quantity'] = $cart_item['quantity'];
            $items[] = $item;
            $index++;
        }
        ?>
        <script>
            window.jdlCheckoutItems = <?php echo wp_json_encode($items); ?>;
        </script>
        <?php
    }

    // ======================== purchase ========================
    public function track_purchase($order_id) {
        if (!$this->settings->is_enabled('track_purchase')) return;
        if (get_post_meta($order_id, '_jdl_tracked', true)) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $items = [];
        $index = 0;
        foreach ($order->get_items() as $order_item) {
            $product = $order_item->get_product();
            if (!$product) continue;

            $item = $this->build_item($product, $index);
            $item['quantity'] = $order_item->get_quantity();
            $item['discount'] = (float) round($order_item->get_subtotal() - $order_item->get_total(), 2);
            $items[] = $item;
            $index++;
        }

        $coupons = [];
        foreach ($order->get_coupon_codes() as $code) {
            $coupons[] = $code;
        }

        $shipping_methods = [];
        foreach ($order->get_shipping_methods() as $method) {
            $shipping_methods[] = $method->get_method_title();
        }

        // Full GA4 purchase event with all parameters
        $purchase_data = [
            'transaction_id' => (string) $order->get_order_number(),
            'value' => (float) $order->get_total(),
            'tax' => (float) $order->get_total_tax(),
            'shipping' => (float) $order->get_shipping_total(),
            'currency' => $order->get_currency(),
            'coupon' => !empty($coupons) ? implode(',', $coupons) : '',
            'items' => $items,

            // Enhanced parameters (for all platforms)
            'payment_type' => $order->get_payment_method_title(),
            'shipping_tier' => implode(', ', $shipping_methods),
            'discount_amount' => (float) $order->get_discount_total(),
            'item_count' => count($items),
            'new_customer' => $this->is_new_customer($order),

            // User data for server-side matching
            'user_data' => [
                'email_address' => strtolower(trim($order->get_billing_email())),
                'phone_number' => preg_replace('/[^0-9+]/', '', $order->get_billing_phone()),
                'address' => [
                    'first_name' => strtolower(trim($order->get_billing_first_name())),
                    'last_name' => strtolower(trim($order->get_billing_last_name())),
                    'street' => strtolower(trim($order->get_billing_address_1())),
                    'city' => strtolower(trim($order->get_billing_city())),
                    'region' => strtolower(trim($order->get_billing_state())),
                    'postal_code' => trim($order->get_billing_postcode()),
                    'country' => strtolower(trim($order->get_billing_country())),
                ],
                'sha256_email_address' => hash('sha256', strtolower(trim($order->get_billing_email()))),
                'sha256_phone_number' => hash('sha256', preg_replace('/[^0-9+]/', '', $order->get_billing_phone())),
                'sha256_first_name' => hash('sha256', strtolower(trim($order->get_billing_first_name()))),
                'sha256_last_name' => hash('sha256', strtolower(trim($order->get_billing_last_name()))),
            ],
        ];

        update_post_meta($order_id, '_jdl_tracked', true);

        $this->push_ecommerce_event('purchase', $purchase_data);

        // Server-side tracking
        if ($this->settings->is_enabled('enable_server_side')) {
            $ss_data = $purchase_data;
            $ss_data['event'] = 'purchase';
            $ss_data['ecommerce'] = $purchase_data;
            $ss_data['user_id'] = (string) $order->get_customer_id();
            $ss_data['page_url'] = $order->get_checkout_order_received_url();
            $ss_data['_client_data'] = [
                'client_id' => 'wp_' . ($order->get_customer_id() ?: 'guest') . '.' . time(),
                'ip_address' => $order->get_customer_ip_address(),
                'user_agent' => $order->get_customer_user_agent(),
            ];
            JDL_Server_Side::get_instance()->send_event($ss_data);
        }
    }

    // ======================== AJAX: get item data for add_to_cart ========================
    public function ajax_get_cart_item() {
        check_ajax_referer('jdl_woo_nonce', 'nonce');

        $product_id = absint($_POST['product_id'] ?? 0);
        $quantity = absint($_POST['quantity'] ?? 1);
        $variation_id = absint($_POST['variation_id'] ?? 0);

        $product = $variation_id ? wc_get_product($variation_id) : wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Product not found');
        }

        $item = $this->build_item($product, 0);
        $item['quantity'] = $quantity;

        wp_send_json_success([
            'item' => $item,
            'currency' => get_woocommerce_currency(),
            'value' => (float) $product->get_price() * $quantity,
        ]);
    }

    // ================================================================
    // ITEM BUILDER - Full GA4 items[] schema with ALL parameters
    // ================================================================
    private function build_item($product, $index = 0) {
        $product_id = $product->get_id();
        $parent_id = $product->get_parent_id();

        // Categories (up to 5 levels)
        $cat_product_id = $parent_id ?: $product_id;
        $categories = wp_get_post_terms($cat_product_id, 'product_cat', ['fields' => 'names']);
        if (is_wp_error($categories)) $categories = [];

        // Brand
        $brands = wp_get_post_terms($cat_product_id, 'product_brand', ['fields' => 'names']);
        if (is_wp_error($brands)) $brands = [];
        $brand = !empty($brands) ? $brands[0] : '';

        // If no brand taxonomy, try pa_brand attribute
        if (!$brand) {
            $brand_attr = $product->get_attribute('brand');
            if ($brand_attr) $brand = $brand_attr;
        }

        // Base item data - GA4 required + recommended parameters
        $item = [
            'item_id' => (string) ($parent_id ?: $product_id),
            'item_name' => $parent_id ? wc_get_product($parent_id)->get_name() : $product->get_name(),
            'affiliation' => get_bloginfo('name'),
            'coupon' => '',
            'discount' => 0,
            'index' => $index,
            'item_brand' => $brand,
            'item_category' => isset($categories[0]) ? $categories[0] : '',
            'item_category2' => isset($categories[1]) ? $categories[1] : '',
            'item_category3' => isset($categories[2]) ? $categories[2] : '',
            'item_category4' => isset($categories[3]) ? $categories[3] : '',
            'item_category5' => isset($categories[4]) ? $categories[4] : '',
            'item_list_id' => '',
            'item_list_name' => '',
            'item_variant' => '',
            'location_id' => '',
            'price' => (float) $product->get_price(),
            'quantity' => 1,
        ];

        // Variant info
        if ($product->is_type('variation')) {
            $attributes = $product->get_variation_attributes();
            $item['item_variant'] = implode(' / ', array_filter($attributes));
            $item['item_id'] = (string) $parent_id;
        }

        // Discount (sale)
        if ($product->is_on_sale() && $product->get_regular_price()) {
            $item['discount'] = (float) round(
                (float) $product->get_regular_price() - (float) $product->get_price(), 2
            );
        }

        // ===== Extended parameters (for all platforms) =====
        $item['item_sku'] = $product->get_sku() ?: '';
        $item['item_stock_status'] = $product->get_stock_status();
        $item['item_type'] = $product->get_type();
        $item['item_url'] = get_permalink($parent_id ?: $product_id);
        $item['item_image_url'] = wp_get_attachment_url($product->get_image_id()) ?: '';

        // Regular price (for calculating discount %)
        $item['item_regular_price'] = (float) ($product->get_regular_price() ?: $product->get_price());
        $item['item_sale_price'] = $product->is_on_sale() ? (float) $product->get_price() : 0;

        // Availability
        $stock_qty = $product->get_stock_quantity();
        $item['item_availability'] = $product->is_in_stock() ? 'in_stock' : 'out_of_stock';
        $item['item_stock_quantity'] = $stock_qty !== null ? (int) $stock_qty : -1;

        // Rating
        $item['item_rating'] = (float) $product->get_average_rating();
        $item['item_review_count'] = (int) $product->get_review_count();

        // Tags
        $tags = wp_get_post_terms($cat_product_id, 'product_tag', ['fields' => 'names']);
        $item['item_tags'] = !is_wp_error($tags) && !empty($tags) ? implode(', ', $tags) : '';

        // Weight/dimensions (for shipping platforms)
        $item['item_weight'] = $product->get_weight() ?: '';
        $item['item_length'] = $product->get_length() ?: '';
        $item['item_width'] = $product->get_width() ?: '';
        $item['item_height'] = $product->get_height() ?: '';

        // Content IDs for Facebook/TikTok/Pinterest
        $item['content_id'] = (string) ($parent_id ?: $product_id);
        $item['content_type'] = 'product';
        $item['content_name'] = $item['item_name'];

        return $item;
    }

    // ================================================================
    // HELPER: Push ecommerce event with null clear
    // ================================================================
    private function push_ecommerce_event($event_name, $ecommerce_data) {
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ecommerce: null});
            window.dataLayer.push({
                'event': '<?php echo esc_js($event_name); ?>',
                'ecommerce': <?php echo wp_json_encode($ecommerce_data); ?>
            });
        </script>
        <?php
    }

    // ================================================================
    // HELPERS
    // ================================================================
    private function get_list_name() {
        if (is_shop()) return 'Shop';
        if (is_product_category()) return 'Category: ' . single_term_title('', false);
        if (is_product_tag()) return 'Tag: ' . single_term_title('', false);
        if (is_search()) return 'Search Results';
        if (is_archive()) return 'Archive';
        return 'Product List';
    }

    private function get_list_id() {
        if (is_shop()) return 'shop';
        if (is_product_category()) {
            $term = get_queried_object();
            return 'category_' . ($term ? $term->slug : '');
        }
        if (is_product_tag()) {
            $term = get_queried_object();
            return 'tag_' . ($term ? $term->slug : '');
        }
        if (is_search()) return 'search_results';
        return 'product_list';
    }

    private function is_new_customer($order) {
        $customer_id = $order->get_customer_id();
        if (!$customer_id) return true;

        $orders = wc_get_orders([
            'customer_id' => $customer_id,
            'limit' => 2,
            'status' => ['completed', 'processing'],
        ]);
        return count($orders) <= 1;
    }
}
