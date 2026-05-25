<?php
if (!defined('ABSPATH')) exit;

/**
 * WooCommerce eCommerce Events - Full GA4 Schema
 * Every event has: ecommerce.currency, ecommerce.value, ecommerce.items[]
 * items[] has: item_id, item_name, item_brand, item_category(1-5),
 *              item_variant, price, quantity, discount, index,
 *              content_id, content_type (for FB/TikTok/Pinterest)
 */
class JDL_Ecommerce {
    private static $instance = null;
    private $s;

    public static function init() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->s = JDL_Settings::init();

        if ($this->s->on('ev_view_item'))
            add_action('woocommerce_after_single_product', [$this, 'view_item']);
        if ($this->s->on('ev_view_item_list'))
            add_action('woocommerce_after_shop_loop', [$this, 'view_item_list']);
        if ($this->s->on('ev_view_cart'))
            add_action('woocommerce_after_cart', [$this, 'view_cart']);
        if ($this->s->on('ev_begin_checkout'))
            add_action('woocommerce_before_checkout_form', [$this, 'begin_checkout']);
        if ($this->s->on('ev_add_shipping_info') || $this->s->on('ev_add_payment_info'))
            add_action('woocommerce_checkout_after_customer_details', [$this, 'checkout_progress']);
        if ($this->s->on('ev_purchase'))
            add_action('woocommerce_thankyou', [$this, 'purchase'], 5);

        // Client-side JS events
        add_action('wp_enqueue_scripts', [$this, 'scripts']);
        add_action('wp_ajax_jdl_item', [$this, 'ajax_item']);
        add_action('wp_ajax_nopriv_jdl_item', [$this, 'ajax_item']);
    }

    public function scripts() {
        if (!is_woocommerce() && !is_cart() && !is_checkout() && !is_order_received_page()) return;
        wp_enqueue_script('jdl-ecom', JDL_URL . 'assets/js/jdl-ecom.js', ['jquery'], JDL_VERSION, true);
        wp_localize_script('jdl-ecom', 'jdl', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jdl_n'),
            'cur' => get_woocommerce_currency(),
            'atc' => $this->s->on('ev_add_to_cart'),
            'rfc' => $this->s->on('ev_remove_from_cart'),
            'si' => $this->s->on('ev_select_item'),
            'wl' => $this->s->on('ev_add_to_wishlist'),
        ]);
    }

    // ===== view_item =====
    public function view_item() {
        global $product;
        if (!$product) return;
        $this->push('view_item', [
            'currency' => get_woocommerce_currency(),
            'value' => (float) $product->get_price(),
            'items' => [$this->item($product, 0, 1)],
        ]);
    }

    // ===== view_item_list =====
    public function view_item_list() {
        global $wp_query;
        if (!$wp_query->posts) return;
        $items = [];
        foreach ($wp_query->posts as $i => $p) {
            $prod = wc_get_product($p->ID);
            if (!$prod) continue;
            $it = $this->item($prod, $i, 1);
            $it['item_list_name'] = $this->list_name();
            $it['item_list_id'] = $this->list_id();
            $items[] = $it;
            if ($i >= 24) break;
        }
        if (!$items) return;
        $this->push('view_item_list', [
            'currency' => get_woocommerce_currency(),
            'item_list_name' => $this->list_name(),
            'item_list_id' => $this->list_id(),
            'items' => $items,
        ]);
    }

    // ===== view_cart =====
    public function view_cart() {
        $cart = WC()->cart;
        $items = []; $i = 0;
        foreach ($cart->get_cart() as $ci) {
            $items[] = $this->item($ci['data'], $i++, $ci['quantity']);
        }
        $this->push('view_cart', [
            'currency' => get_woocommerce_currency(),
            'value' => (float) $cart->get_cart_contents_total(),
            'items' => $items,
        ]);
    }

    // ===== begin_checkout =====
    public function begin_checkout() {
        $cart = WC()->cart;
        $items = []; $i = 0;
        foreach ($cart->get_cart() as $ci) {
            $items[] = $this->item($ci['data'], $i++, $ci['quantity']);
        }
        $coupons = $cart->get_applied_coupons();
        $this->push('begin_checkout', [
            'currency' => get_woocommerce_currency(),
            'value' => (float) $cart->get_cart_contents_total(),
            'coupon' => implode(',', $coupons),
            'items' => $items,
        ]);
    }

    // ===== add_shipping_info + add_payment_info (JS) =====
    public function checkout_progress() {
        $cur = get_woocommerce_currency();
        ?>
<script>
(function($){
var fired={s:0,p:0};
$(document).on('updated_checkout',function(){
    if(fired.s) return;
    var m=$('[name^=shipping_method]:checked').val()||$('[name^=shipping_method]').val()||'';
    if(!m) return; fired.s=1;
    window.dataLayer.push({ecommerce:null});
    window.dataLayer.push({event:'add_shipping_info',ecommerce:{currency:'<?php echo esc_js($cur);?>',shipping_tier:m}});
});
$(document).on('change','[name=payment_method]',function(){
    if(fired.p) return; fired.p=1;
    window.dataLayer.push({ecommerce:null});
    window.dataLayer.push({event:'add_payment_info',ecommerce:{currency:'<?php echo esc_js($cur);?>',payment_type:$(this).val()}});
});
})(jQuery);
</script>
        <?php
    }

    // ===== purchase =====
    public function purchase($order_id) {
        if (!$order_id) return;
        if (get_post_meta($order_id, '_jdl_done', true)) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

        $items = []; $i = 0;
        foreach ($order->get_items() as $oi) {
            $prod = $oi->get_product();
            if (!$prod) continue;
            $it = $this->item($prod, $i++, $oi->get_quantity());
            $it['discount'] = (float) round($oi->get_subtotal() - $oi->get_total(), 2);
            $items[] = $it;
        }

        $coupons = $order->get_coupon_codes();
        $ship = [];
        foreach ($order->get_shipping_methods() as $m) $ship[] = $m->get_method_title();

        $email = strtolower(trim($order->get_billing_email()));
        $phone = preg_replace('/[^0-9+]/', '', $order->get_billing_phone());
        $fname = strtolower(trim($order->get_billing_first_name()));
        $lname = strtolower(trim($order->get_billing_last_name()));

        $ecom = [
            'transaction_id' => (string) $order->get_order_number(),
            'value' => (float) $order->get_total(),
            'tax' => (float) $order->get_total_tax(),
            'shipping' => (float) $order->get_shipping_total(),
            'currency' => $order->get_currency(),
            'coupon' => implode(',', $coupons),
            'payment_type' => $order->get_payment_method_title(),
            'shipping_tier' => implode(', ', $ship),
            'discount_amount' => (float) $order->get_discount_total(),
            'new_customer' => $this->is_new($order),
            'items' => $items,
            // User data for Enhanced Conversions / CAPI matching
            'user_data' => [
                'email' => $email,
                'email_sha256' => hash('sha256', $email),
                'phone' => $phone,
                'phone_sha256' => $phone ? hash('sha256', $phone) : '',
                'fn' => $fname,
                'fn_sha256' => hash('sha256', $fname),
                'ln' => $lname,
                'ln_sha256' => hash('sha256', $lname),
                'city' => strtolower(trim($order->get_billing_city())),
                'state' => strtolower(trim($order->get_billing_state())),
                'country' => strtolower(trim($order->get_billing_country())),
                'zip' => trim($order->get_billing_postcode()),
                'external_id' => (string) $order->get_customer_id(),
            ],
        ];

        update_post_meta($order_id, '_jdl_done', '1');
        $this->push('purchase', $ecom);

        // Server-side
        if ($this->s->on('ss_enabled')) {
            $ss_data = $ecom;
            $ss_data['event'] = 'purchase';
            $ss_data['_client'] = [
                'ip' => $order->get_customer_ip_address(),
                'ua' => $order->get_customer_user_agent(),
                'cid' => 'wp_' . ($order->get_customer_id() ?: 'g') . '.' . time(),
            ];
            JDL_ServerSide::init()->send($ss_data);
        }
    }

    // ===== AJAX: return item data for add_to_cart =====
    public function ajax_item() {
        check_ajax_referer('jdl_n', 'nonce');
        $pid = absint($_POST['pid'] ?? 0);
        $qty = absint($_POST['qty'] ?? 1);
        $vid = absint($_POST['vid'] ?? 0);
        $prod = $vid ? wc_get_product($vid) : wc_get_product($pid);
        if (!$prod) wp_send_json_error();
        wp_send_json_success([
            'item' => $this->item($prod, 0, $qty),
            'value' => (float) $prod->get_price() * $qty,
        ]);
    }

    // ===== ITEM BUILDER =====
    private function item($product, $index = 0, $qty = 1) {
        $pid = $product->get_id();
        $parent = $product->get_parent_id();
        $base = $parent ?: $pid;

        $cats = wp_get_post_terms($base, 'product_cat', ['fields' => 'names']);
        if (is_wp_error($cats)) $cats = [];
        $brands = wp_get_post_terms($base, 'product_brand', ['fields' => 'names']);
        $brand = (!is_wp_error($brands) && !empty($brands)) ? $brands[0] : ($product->get_attribute('brand') ?: '');
        $name = $parent ? wc_get_product($parent)->get_name() : $product->get_name();

        $it = [
            'item_id' => (string) $base,
            'item_name' => $name,
            'affiliation' => get_bloginfo('name'),
            'coupon' => '',
            'discount' => 0,
            'index' => $index,
            'item_brand' => $brand,
            'item_category' => $cats[0] ?? '',
            'item_category2' => $cats[1] ?? '',
            'item_category3' => $cats[2] ?? '',
            'item_category4' => $cats[3] ?? '',
            'item_category5' => $cats[4] ?? '',
            'item_variant' => '',
            'price' => (float) $product->get_price(),
            'quantity' => (int) $qty,
            // All platforms
            'content_id' => (string) $base,
            'content_type' => 'product',
            'content_name' => $name,
            'item_sku' => $product->get_sku() ?: '',
            'item_url' => get_permalink($base),
            'item_image' => wp_get_attachment_url($product->get_image_id()) ?: '',
        ];

        if ($product->is_type('variation')) {
            $it['item_variant'] = implode(' / ', array_filter($product->get_variation_attributes()));
        }
        if ($product->is_on_sale() && $product->get_regular_price()) {
            $it['discount'] = (float) round((float) $product->get_regular_price() - (float) $product->get_price(), 2);
        }
        return $it;
    }

    // ===== HELPERS =====
    private function push($event, $ecom) {
        echo '<script>window.dataLayer=window.dataLayer||[];window.dataLayer.push({ecommerce:null});window.dataLayer.push({event:"' . esc_js($event) . '",ecommerce:' . wp_json_encode($ecom, JSON_UNESCAPED_SLASHES) . '});</script>' . "\n";
    }

    private function list_name() {
        if (is_shop()) return 'Shop';
        if (is_product_category()) return 'Category: ' . single_term_title('', false);
        if (is_search()) return 'Search Results';
        return 'Product List';
    }
    private function list_id() {
        if (is_shop()) return 'shop';
        if (is_product_category()) { $t = get_queried_object(); return 'cat_' . ($t ? $t->slug : ''); }
        return 'list';
    }
    private function is_new($order) {
        $cid = $order->get_customer_id();
        if (!$cid) return true;
        return count(wc_get_orders(['customer_id' => $cid, 'limit' => 2, 'status' => ['completed', 'processing']])) <= 1;
    }
}
