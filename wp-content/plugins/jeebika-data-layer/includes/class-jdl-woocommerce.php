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

        // Product Page - view_item
        add_action('woocommerce_after_single_product', [$this, 'track_view_item']);

        // Product Lists - view_item_list
        add_action('woocommerce_after_shop_loop', [$this, 'track_view_item_list']);

        // Add to Cart
        add_action('wp_footer', [$this, 'track_add_to_cart_js']);

        // Cart Page
        add_action('woocommerce_after_cart', [$this, 'track_view_cart']);

        // Remove from Cart
        add_action('woocommerce_cart_item_removed', [$this, 'track_remove_from_cart'], 10, 2);

        // Checkout
        add_action('woocommerce_before_checkout_form', [$this, 'track_begin_checkout']);
        add_action('woocommerce_checkout_after_customer_details', [$this, 'track_add_shipping_info']);

        // Purchase (Thank You Page)
        add_action('woocommerce_thankyou', [$this, 'track_purchase']);

        // AJAX Add to Cart
        add_action('wp_ajax_jdl_get_cart_item', [$this, 'ajax_get_cart_item']);
        add_action('wp_ajax_nopriv_jdl_get_cart_item', [$this, 'ajax_get_cart_item']);

        // Enqueue WooCommerce specific scripts
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

        wp_localize_script('jdl-woocommerce', 'jdlWooConfig', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jdl_woo_nonce'),
            'currency' => get_woocommerce_currency(),
            'track_add_to_cart' => $this->settings->is_enabled('track_add_to_cart'),
            'track_remove_from_cart' => $this->settings->is_enabled('track_remove_from_cart'),
            'track_select_item' => $this->settings->is_enabled('track_select_item'),
        ]);
    }

    public function track_view_item() {
        if (!$this->settings->is_enabled('track_view_item')) return;

        global $product;
        if (!$product) return;

        $item_data = $this->get_product_data($product);
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push({
                'event': 'view_item',
                'ecommerce': {
                    'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                    'value': <?php echo (float) $product->get_price(); ?>,
                    'items': [<?php echo wp_json_encode($item_data); ?>]
                }
            });
        </script>
        <?php
    }

    public function track_view_item_list() {
        if (!$this->settings->is_enabled('track_view_item_list')) return;

        global $wp_query;
        $items = [];
        $index = 0;

        if ($wp_query->posts) {
            foreach ($wp_query->posts as $post) {
                $product = wc_get_product($post->ID);
                if (!$product) continue;

                $item = $this->get_product_data($product);
                $item['index'] = $index++;
                $item['item_list_name'] = $this->get_list_name();
                $item['item_list_id'] = $this->get_list_id();
                $items[] = $item;
            }
        }

        if (empty($items)) return;
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push({
                'event': 'view_item_list',
                'ecommerce': {
                    'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                    'item_list_name': '<?php echo esc_js($this->get_list_name()); ?>',
                    'item_list_id': '<?php echo esc_js($this->get_list_id()); ?>',
                    'items': <?php echo wp_json_encode($items); ?>
                }
            });
        </script>
        <?php
    }

    public function track_view_cart() {
        if (!$this->settings->is_enabled('track_view_cart')) return;

        $cart = WC()->cart;
        $items = [];
        $index = 0;

        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $item = $this->get_product_data($product);
            $item['quantity'] = $cart_item['quantity'];
            $item['index'] = $index++;
            $items[] = $item;
        }
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push({
                'event': 'view_cart',
                'ecommerce': {
                    'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                    'value': <?php echo (float) $cart->get_cart_contents_total(); ?>,
                    'items': <?php echo wp_json_encode($items); ?>
                }
            });
        </script>
        <?php
    }

    public function track_remove_from_cart($cart_item_key, $cart) {
        if (!$this->settings->is_enabled('track_remove_from_cart')) return;

        $removed_item = $cart->removed_cart_contents[$cart_item_key] ?? null;
        if (!$removed_item) return;

        $product = wc_get_product($removed_item['product_id']);
        if (!$product) return;

        $item_data = $this->get_product_data($product);
        $item_data['quantity'] = $removed_item['quantity'];

        // Store for next page load
        WC()->session->set('jdl_removed_item', $item_data);
    }

    public function track_begin_checkout() {
        if (!$this->settings->is_enabled('track_begin_checkout')) return;

        $cart = WC()->cart;
        $items = [];
        $index = 0;

        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $item = $this->get_product_data($product);
            $item['quantity'] = $cart_item['quantity'];
            $item['index'] = $index++;
            $items[] = $item;
        }
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push({
                'event': 'begin_checkout',
                'ecommerce': {
                    'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                    'value': <?php echo (float) $cart->get_cart_contents_total(); ?>,
                    'coupon': '<?php echo esc_js(implode(',', $cart->get_applied_coupons())); ?>',
                    'items': <?php echo wp_json_encode($items); ?>
                }
            });
        </script>
        <?php
    }

    public function track_add_shipping_info() {
        if (!$this->settings->is_enabled('track_add_shipping_info')) return;
        ?>
        <script>
            jQuery(document).on('updated_checkout', function() {
                var shippingMethod = jQuery('input[name^="shipping_method"]:checked').val() || 
                                     jQuery('input[name^="shipping_method"]').val() || '';
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({ ecommerce: null });
                window.dataLayer.push({
                    'event': 'add_shipping_info',
                    'ecommerce': {
                        'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                        'shipping_tier': shippingMethod
                    }
                });
            });

            // Payment method selection
            jQuery(document).on('change', 'input[name="payment_method"]', function() {
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({ ecommerce: null });
                window.dataLayer.push({
                    'event': 'add_payment_info',
                    'ecommerce': {
                        'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                        'payment_type': jQuery(this).val()
                    }
                });
            });
        </script>
        <?php
    }

    public function track_purchase($order_id) {
        if (!$this->settings->is_enabled('track_purchase')) return;

        // Prevent duplicate tracking
        if (get_post_meta($order_id, '_jdl_tracked', true)) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $items = [];
        $index = 0;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;

            $item_data = $this->get_product_data($product);
            $item_data['quantity'] = $item->get_quantity();
            $item_data['discount'] = (float) ($item->get_subtotal() - $item->get_total());
            $item_data['index'] = $index++;
            $items[] = $item_data;
        }

        $purchase_data = [
            'event' => 'purchase',
            'ecommerce' => [
                'transaction_id' => (string) $order->get_order_number(),
                'value' => (float) $order->get_total(),
                'tax' => (float) $order->get_total_tax(),
                'shipping' => (float) $order->get_shipping_total(),
                'currency' => $order->get_currency(),
                'coupon' => implode(',', $this->get_order_coupons($order)),
                'items' => $items,
                // Enhanced data
                'payment_method' => $order->get_payment_method_title(),
                'shipping_method' => $this->get_shipping_method($order),
                'order_status' => $order->get_status(),
                'is_new_customer' => $this->is_new_customer($order),
                'discount_amount' => (float) $order->get_discount_total(),
                'item_count' => count($items),
            ],
        ];

        // Mark as tracked
        update_post_meta($order_id, '_jdl_tracked', true);
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push(<?php echo wp_json_encode($purchase_data); ?>);
        </script>
        <?php

        // Send server-side event
        if ($this->settings->is_enabled('enable_server_side')) {
            JDL_Server_Side::get_instance()->send_event($purchase_data);
        }
    }

    // ============ Helper Methods ============

    private function get_product_data($product) {
        $categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
        $brands = wp_get_post_terms($product->get_id(), 'product_brand', ['fields' => 'names']);

        $data = [
            'item_id' => (string) $product->get_id(),
            'item_name' => $product->get_name(),
            'item_brand' => !empty($brands) ? $brands[0] : '',
            'item_category' => !empty($categories) ? $categories[0] : '',
            'price' => (float) $product->get_price(),
            'item_variant' => '',
            'quantity' => 1,
        ];

        // Add up to 5 category levels
        if (count($categories) > 1) {
            for ($i = 1; $i < min(count($categories), 5); $i++) {
                $data['item_category' . ($i + 1)] = $categories[$i];
            }
        }

        // Variable product
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            $data['item_name'] = $parent ? $parent->get_name() : $product->get_name();
            $data['item_variant'] = implode(' / ', $product->get_variation_attributes());
            $data['item_id'] = (string) $product->get_parent_id();
        }

        // Sale info
        if ($product->is_on_sale()) {
            $data['discount'] = (float) ($product->get_regular_price() - $product->get_price());
        }

        // Stock status
        $data['item_stock_status'] = $product->get_stock_status();

        // SKU
        if ($product->get_sku()) {
            $data['item_sku'] = $product->get_sku();
        }

        return $data;
    }

    private function get_list_name() {
        if (is_shop()) return 'Shop';
        if (is_product_category()) return 'Category: ' . single_term_title('', false);
        if (is_product_tag()) return 'Tag: ' . single_term_title('', false);
        if (is_search()) return 'Search Results';
        return 'Product List';
    }

    private function get_list_id() {
        if (is_shop()) return 'shop';
        if (is_product_category()) {
            $term = get_queried_object();
            return 'cat_' . ($term ? $term->term_id : '');
        }
        return 'list';
    }

    private function get_order_coupons($order) {
        $coupons = [];
        foreach ($order->get_coupon_codes() as $coupon) {
            $coupons[] = $coupon;
        }
        return $coupons;
    }

    private function get_shipping_method($order) {
        $methods = [];
        foreach ($order->get_shipping_methods() as $method) {
            $methods[] = $method->get_method_title();
        }
        return implode(', ', $methods);
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

    public function ajax_get_cart_item() {
        check_ajax_referer('jdl_woo_nonce', 'nonce');

        $product_id = absint($_POST['product_id'] ?? 0);
        $quantity = absint($_POST['quantity'] ?? 1);
        $variation_id = absint($_POST['variation_id'] ?? 0);

        $product = $variation_id ? wc_get_product($variation_id) : wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Product not found');
        }

        $item_data = $this->get_product_data($product);
        $item_data['quantity'] = $quantity;

        wp_send_json_success([
            'item' => $item_data,
            'currency' => get_woocommerce_currency(),
            'value' => (float) $product->get_price() * $quantity,
        ]);
    }
}
