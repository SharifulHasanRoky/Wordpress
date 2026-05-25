<?php
/**
 * JEEBIKA UNIVERSAL ECOMMERCE DATA LAYER
 * 
 * Inline dataLayer pushes for all WooCommerce events.
 * Each event = ONE self-contained push with FULL data.
 * GTM তে Custom HTML + All Pages trigger = auto কাজ করবে।
 * 
 * Events: view_item, view_item_list, add_to_cart, remove_from_cart,
 *         view_cart, begin_checkout, add_shipping_info, add_payment_info, purchase
 */

if (!defined('ABSPATH')) exit;

class JDL_Universal_Ecommerce {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!class_exists('WooCommerce')) return;

        // Product page
        add_action('woocommerce_after_single_product', [$this, 'push_view_item']);

        // Shop/Category
        add_action('woocommerce_after_shop_loop', [$this, 'push_view_item_list']);

        // Cart
        add_action('woocommerce_after_cart', [$this, 'push_view_cart']);

        // Checkout
        add_action('woocommerce_before_checkout_form', [$this, 'push_begin_checkout']);

        // Thank You / Purchase
        add_action('woocommerce_thankyou', [$this, 'push_purchase'], 5);

        // Client-side events (add_to_cart, remove, shipping, payment)
        add_action('wp_footer', [$this, 'push_client_side_events'], 50);

        // AJAX for add_to_cart item fetch
        add_action('wp_ajax_jdl_universal_item', [$this, 'ajax_get_item']);
        add_action('wp_ajax_nopriv_jdl_universal_item', [$this, 'ajax_get_item']);
    }

    // ================================================================
    // VIEW ITEM (Product Page)
    // ================================================================
    public function push_view_item() {
        global $product;
        if (!$product) return;

        $item = $this->build_item($product, 0, 1);

        $this->output_push('view_item', [
            'currency' => get_woocommerce_currency(),
            'value' => (float) $product->get_price(),
            'items' => [$item],
            'content_ids' => [(string) $product->get_id()],
            'content_type' => 'product',
            'content_name' => $product->get_name(),
            'content_category' => $item['item_category'],
        ]);
    }

    // ================================================================
    // VIEW ITEM LIST (Shop/Category/Tag/Search)
    // ================================================================
    public function push_view_item_list() {
        global $wp_query;
        if (!$wp_query->posts) return;

        $items = [];
        $content_ids = [];
        $list_name = $this->get_list_name();
        $list_id = $this->get_list_id();

        foreach ($wp_query->posts as $i => $p) {
            $prod = wc_get_product($p->ID);
            if (!$prod) continue;

            $item = $this->build_item($prod, $i, 1);
            $item['item_list_name'] = $list_name;
            $item['item_list_id'] = $list_id;
            $items[] = $item;
            $content_ids[] = (string) $prod->get_id();

            if ($i >= 29) break; // max 30 items
        }

        if (empty($items)) return;

        $this->output_push('view_item_list', [
            'currency' => get_woocommerce_currency(),
            'item_list_name' => $list_name,
            'item_list_id' => $list_id,
            'items' => $items,
            'content_ids' => $content_ids,
            'content_type' => 'product',
            'num_items' => count($items),
        ]);
    }

    // ================================================================
    // VIEW CART
    // ================================================================
    public function push_view_cart() {
        $cart = WC()->cart;
        if (!$cart) return;

        $items = [];
        $content_ids = [];
        $i = 0;

        foreach ($cart->get_cart() as $cart_item) {
            $prod = $cart_item['data'];
            $item = $this->build_item($prod, $i, $cart_item['quantity']);
            $items[] = $item;
            $content_ids[] = $item['content_id'];
            $i++;
        }

        $this->output_push('view_cart', [
            'currency' => get_woocommerce_currency(),
            'value' => (float) $cart->get_cart_contents_total(),
            'items' => $items,
            'content_ids' => $content_ids,
            'content_type' => 'product',
            'num_items' => $cart->get_cart_contents_count(),
        ]);
    }

    // ================================================================
    // BEGIN CHECKOUT
    // ================================================================
    public function push_begin_checkout() {
        $cart = WC()->cart;
        if (!$cart) return;

        $items = [];
        $content_ids = [];
        $i = 0;

        foreach ($cart->get_cart() as $cart_item) {
            $prod = $cart_item['data'];
            $item = $this->build_item($prod, $i, $cart_item['quantity']);
            $items[] = $item;
            $content_ids[] = $item['content_id'];
            $i++;
        }

        $coupons = $cart->get_applied_coupons();

        $this->output_push('begin_checkout', [
            'currency' => get_woocommerce_currency(),
            'value' => (float) $cart->get_cart_contents_total(),
            'coupon' => !empty($coupons) ? implode(',', $coupons) : '',
            'items' => $items,
            'content_ids' => $content_ids,
            'content_type' => 'product',
            'num_items' => $cart->get_cart_contents_count(),
        ]);
    }

    // ================================================================
    // PURCHASE (Thank You Page) - THE BIG ONE
    // ================================================================
    public function push_purchase($order_id) {
        if (!$order_id) return;
        if (get_post_meta($order_id, '_jdl_universal_tracked', true)) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $items = [];
        $content_ids = [];
        $i = 0;

        foreach ($order->get_items() as $order_item) {
            $prod = $order_item->get_product();
            if (!$prod) continue;

            $item = $this->build_item($prod, $i, $order_item->get_quantity());
            $item['discount'] = (float) round($order_item->get_subtotal() - $order_item->get_total(), 2);
            $items[] = $item;
            $content_ids[] = $item['content_id'];
            $i++;
        }

        $coupons = $order->get_coupon_codes();
        $shipping_methods = [];
        foreach ($order->get_shipping_methods() as $m) {
            $shipping_methods[] = $m->get_method_title();
        }

        // User data for server-side matching (all platforms)
        $email = strtolower(trim($order->get_billing_email()));
        $phone = preg_replace('/[^0-9+]/', '', $order->get_billing_phone());
        $fname = strtolower(trim($order->get_billing_first_name()));
        $lname = strtolower(trim($order->get_billing_last_name()));

        $purchase = [
            // GA4 Standard
            'transaction_id' => (string) $order->get_order_number(),
            'value' => (float) $order->get_total(),
            'tax' => (float) $order->get_total_tax(),
            'shipping' => (float) $order->get_shipping_total(),
            'currency' => $order->get_currency(),
            'coupon' => !empty($coupons) ? implode(',', $coupons) : '',
            'items' => $items,

            // Enhanced parameters
            'payment_type' => $order->get_payment_method_title(),
            'shipping_tier' => implode(', ', $shipping_methods),
            'discount_amount' => (float) $order->get_discount_total(),
            'new_customer' => $this->is_new_customer($order),
            'item_count' => count($items),
            'order_id' => (string) $order->get_id(),

            // Content IDs (Facebook/TikTok/Pinterest)
            'content_ids' => $content_ids,
            'content_type' => 'product',
            'num_items' => count($items),

            // User data for ALL platforms matching
            'user_data' => [
                'email' => $email,
                'phone' => $phone,
                'first_name' => $fname,
                'last_name' => $lname,
                'city' => strtolower(trim($order->get_billing_city())),
                'state' => strtolower(trim($order->get_billing_state())),
                'country' => strtolower(trim($order->get_billing_country())),
                'zip' => trim($order->get_billing_postcode()),
                'email_sha256' => hash('sha256', $email),
                'phone_sha256' => $phone ? hash('sha256', $phone) : '',
                'first_name_sha256' => hash('sha256', $fname),
                'last_name_sha256' => hash('sha256', $lname),
                'external_id' => (string) $order->get_customer_id(),
            ],
        ];

        update_post_meta($order_id, '_jdl_universal_tracked', '1');

        $this->output_push('purchase', $purchase);
    }

    // ================================================================
    // CLIENT-SIDE EVENTS (add_to_cart, remove, shipping, payment, select, wishlist)
    // ================================================================
    public function push_client_side_events() {
        if (!function_exists('is_woocommerce')) return;
        if (!is_woocommerce() && !is_cart() && !is_checkout() && !is_order_received_page()) return;

        $currency = get_woocommerce_currency();
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('jdl_universal_nonce');
        ?>
<script>
(function($){
'use strict';
var DL = window.dataLayer = window.dataLayer || [];
var C = '<?php echo esc_js($currency); ?>';
var AX = '<?php echo esc_js($ajax_url); ?>';
var NK = '<?php echo esc_js($nonce); ?>';

function pushEcom(evt, ecom) {
    DL.push({ecommerce:null});
    DL.push({event:evt, ecommerce:ecom});
}

function getItem(pid, qty, vid, cb) {
    $.post(AX, {action:'jdl_universal_item',nonce:NK,product_id:pid,quantity:qty,variation_id:vid||0}, function(r){
        if(r.success) cb(r.data);
    });
}

// ADD TO CART - Single product
$(document).on('click','.single_add_to_cart_button',function(){
    var f=$(this).closest('form.cart');
    var pid=f.find('[name=product_id]').val()||f.find('[name=add-to-cart]').val()||$(this).val();
    var qty=parseInt(f.find('[name=quantity]').val())||1;
    var vid=parseInt(f.find('[name=variation_id]').val())||0;
    if(pid) getItem(pid,qty,vid,function(d){
        pushEcom('add_to_cart',{currency:C,value:d.value,items:[d.item],content_ids:[d.item.content_id],content_type:'product',num_items:qty});
    });
});

// ADD TO CART - Archive/AJAX
$(document.body).on('added_to_cart',function(e,f,h,$b){
    var pid=$b.data('product_id');
    var qty=parseInt($b.data('quantity'))||1;
    if(pid) getItem(pid,qty,0,function(d){
        pushEcom('add_to_cart',{currency:C,value:d.value,items:[d.item],content_ids:[d.item.content_id],content_type:'product',num_items:qty});
    });
});

// REMOVE FROM CART
$(document).on('click','.product-remove a, .cart_item .remove, .mini_cart_item .remove',function(){
    var r=$(this).closest('tr,.cart_item,.mini_cart_item');
    var nm=r.find('.product-name a,.product-name').first().text().trim();
    var pr=parseFloat(r.find('.product-price .amount,.quantity .amount').first().text().replace(/[^0-9.]/g,''))||0;
    pushEcom('remove_from_cart',{currency:C,value:pr,items:[{item_name:nm,price:pr,quantity:1,content_type:'product'}]});
});

// SELECT ITEM (click product in list)
$(document).on('click','.products .product a:not(.add_to_cart_button):not(.added_to_cart)',function(){
    var p=$(this).closest('.product');
    if(!p.length) return;
    var nm=p.find('.woocommerce-loop-product__title,h2,h3').first().text().trim();
    var pr=parseFloat(p.find('.price .amount:last,.price ins .amount').first().text().replace(/[^0-9.]/g,''))||0;
    var pid=p.find('.add_to_cart_button').data('product_id')||'';
    pushEcom('select_item',{currency:C,items:[{item_id:String(pid),item_name:nm,price:pr,index:p.index(),content_id:String(pid),content_type:'product',quantity:1}]});
});

// ADD TO WISHLIST
$(document).on('click','.add_to_wishlist,.yith-wcwl-add-to-wishlist a,.tinvwl_add_to_wishlist_button',function(){
    var p=$(this).closest('.product,.summary,.single-product');
    var nm=p.find('.product_title,h1,h2').first().text().trim();
    var pr=parseFloat(p.find('.price .amount:last,.price ins .amount').first().text().replace(/[^0-9.]/g,''))||0;
    var pid=$(this).data('product-id')||$(this).data('product_id')||'';
    pushEcom('add_to_wishlist',{currency:C,value:pr,items:[{item_id:String(pid),item_name:nm,price:pr,quantity:1,content_id:String(pid),content_type:'product'}]});
});

// ADD SHIPPING INFO (checkout)
var shipFired=false;
$(document).on('updated_checkout',function(){
    if(shipFired) return;
    var m=$('[name^=shipping_method]:checked').val()||$('[name^=shipping_method]').val()||'';
    if(!m) return;
    shipFired=true;
    pushEcom('add_shipping_info',{currency:C,shipping_tier:m});
});

// ADD PAYMENT INFO
$(document).on('change','[name=payment_method]',function(){
    pushEcom('add_payment_info',{currency:C,payment_type:$(this).val()});
});

// VARIATION CHANGE = view_item update
$(document).on('found_variation','.variations_form',function(e,v){
    var nm=$('h1.product_title,.product_title').first().text().trim();
    var vars=[];
    $(this).find('.variations select').each(function(){var t=$(this).find(':selected').text().trim();if(t)vars.push(t);});
    pushEcom('view_item',{currency:C,value:parseFloat(v.display_price)||0,items:[{
        item_id:String(v.variation_id),item_name:nm,item_variant:vars.join(' / '),
        price:parseFloat(v.display_price)||0,item_sku:v.sku||'',
        item_availability:v.is_in_stock?'in_stock':'out_of_stock',
        item_image:v.image?v.image.full_src:'',
        discount:Math.max(0,(parseFloat(v.display_regular_price)||0)-(parseFloat(v.display_price)||0)),
        quantity:1,content_id:String(v.variation_id),content_type:'product',content_name:nm
    }]});
});

})(jQuery);
</script>
        <?php
    }

    // ================================================================
    // AJAX: Return full item data
    // ================================================================
    public function ajax_get_item() {
        check_ajax_referer('jdl_universal_nonce', 'nonce');

        $pid = absint($_POST['product_id'] ?? 0);
        $qty = absint($_POST['quantity'] ?? 1);
        $vid = absint($_POST['variation_id'] ?? 0);

        $product = $vid ? wc_get_product($vid) : wc_get_product($pid);
        if (!$product) wp_send_json_error();

        $item = $this->build_item($product, 0, $qty);

        wp_send_json_success([
            'item' => $item,
            'value' => (float) $product->get_price() * $qty,
        ]);
    }

    // ================================================================
    // BUILD ITEM - Complete GA4 + all platforms
    // ================================================================
    private function build_item($product, $index = 0, $qty = 1) {
        $pid = $product->get_id();
        $parent = $product->get_parent_id();
        $base = $parent ?: $pid;

        $cats = wp_get_post_terms($base, 'product_cat', ['fields' => 'names']);
        if (is_wp_error($cats)) $cats = [];

        $brands = wp_get_post_terms($base, 'product_brand', ['fields' => 'names']);
        $brand = (!is_wp_error($brands) && !empty($brands)) ? $brands[0] : '';
        if (!$brand) $brand = $product->get_attribute('brand') ?: '';

        $name = $parent ? (wc_get_product($parent) ? wc_get_product($parent)->get_name() : $product->get_name()) : $product->get_name();

        $item = [
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

            // Extended
            'item_sku' => $product->get_sku() ?: '',
            'item_url' => get_permalink($base),
            'item_image' => wp_get_attachment_url($product->get_image_id()) ?: '',
            'item_stock' => $product->get_stock_status(),
            'item_regular_price' => (float) ($product->get_regular_price() ?: $product->get_price()),
            'item_on_sale' => $product->is_on_sale(),
            'item_rating' => (float) $product->get_average_rating(),
            'item_reviews' => (int) $product->get_review_count(),

            // All platforms
            'content_id' => (string) $base,
            'content_type' => 'product',
            'content_name' => $name,
            'content_category' => $cats[0] ?? '',
        ];

        if ($product->is_type('variation')) {
            $item['item_variant'] = implode(' / ', array_filter($product->get_variation_attributes()));
        }
        if ($product->is_on_sale() && $product->get_regular_price()) {
            $item['discount'] = (float) round((float) $product->get_regular_price() - (float) $product->get_price(), 2);
        }

        return $item;
    }

    // ================================================================
    // OUTPUT PUSH - Single inline script
    // ================================================================
    private function output_push($event, $ecommerce) {
        ?>
<script>
window.dataLayer=window.dataLayer||[];
window.dataLayer.push({ecommerce:null});
window.dataLayer.push({"event":"<?php echo esc_js($event); ?>","ecommerce":<?php echo wp_json_encode($ecommerce, JSON_UNESCAPED_SLASHES); ?>});
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
        return 'Product List';
    }

    private function get_list_id() {
        if (is_shop()) return 'shop';
        if (is_product_category()) {
            $t = get_queried_object();
            return 'category_' . ($t ? $t->slug : '');
        }
        if (is_search()) return 'search';
        return 'list';
    }

    private function is_new_customer($order) {
        $cid = $order->get_customer_id();
        if (!$cid) return true;
        $o = wc_get_orders(['customer_id' => $cid, 'limit' => 2, 'status' => ['completed', 'processing']]);
        return count($o) <= 1;
    }
}
