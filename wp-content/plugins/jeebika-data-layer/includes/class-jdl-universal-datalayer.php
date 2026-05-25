<?php
/**
 * JEEBIKA UNIVERSAL DATA LAYER
 * 
 * Single comprehensive dataLayer push on EVERY page.
 * GTM তে শুধু Custom HTML + All Pages trigger দিলেই সব কাজ হয়ে যাবে।
 * 
 * সব platform এর জন্য একটাই data layer:
 * GA4, Facebook CAPI, TikTok, Google Ads, LinkedIn, X, Pinterest
 */

if (!defined('ABSPATH')) exit;

class JDL_Universal_DataLayer {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Fire BEFORE GTM loads (priority 1 in wp_head)
        add_action('wp_head', [$this, 'output_universal_datalayer'], 2);
    }

    /**
     * Output the SINGLE universal dataLayer object
     * This contains EVERYTHING - page, user, customer, ecommerce context
     */
    public function output_universal_datalayer() {
        $data = $this->build_complete_datalayer();
        ?>
<!-- Jeebika Universal Data Layer v1.0 - All Platforms -->
<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push(<?php echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>);
</script>
<!-- /Jeebika Universal Data Layer -->
        <?php
    }

    /**
     * Build the complete data layer object
     */
    private function build_complete_datalayer() {
        $dl = [];

        // Event name
        $dl['event'] = 'jdl_page_load';

        // ===== PAGE DATA =====
        $dl['page'] = $this->get_page_data();

        // ===== USER DATA =====
        $dl['user'] = $this->get_user_data();

        // ===== CUSTOMER DATA (WooCommerce) =====
        $dl['customer'] = $this->get_customer_data();

        // ===== ECOMMERCE CONTEXT =====
        $dl['ecommerce_context'] = $this->get_ecommerce_context();

        // ===== SITE DATA =====
        $dl['site'] = $this->get_site_data();

        // ===== CONSENT STATE =====
        $dl['consent'] = $this->get_consent_state();

        // ===== TIMESTAMP =====
        $dl['timestamp'] = current_time('c');
        $dl['unix_timestamp'] = time();

        return $dl;
    }

    // ================================================================
    // PAGE DATA
    // ================================================================
    private function get_page_data() {
        global $post, $wp_query;

        $page = [
            'location' => $this->get_current_url(),
            'path' => wp_parse_url($this->get_current_url(), PHP_URL_PATH) ?: '/',
            'title' => wp_get_document_title(),
            'referrer' => wp_get_referer() ?: '',
            'type' => $this->get_page_type(),
            'id' => 0,
            'template' => '',
            'language' => get_locale(),
            'is_mobile' => wp_is_mobile(),
        ];

        if (is_singular() && $post) {
            $page['id'] = $post->ID;
            $page['template'] = get_page_template_slug($post) ?: '';
            $page['content_type'] = $post->post_type;
            $page['content_author'] = get_the_author_meta('display_name', $post->post_author);
            $page['content_date'] = get_the_date('Y-m-d', $post);
            $page['content_modified'] = get_the_modified_date('Y-m-d', $post);
            $page['content_word_count'] = str_word_count(strip_tags($post->post_content));

            // Categories
            $cats = get_the_category($post->ID);
            $page['content_category'] = !empty($cats) ? $cats[0]->name : '';

            // Tags
            $tags = get_the_tags($post->ID);
            $page['content_tags'] = !empty($tags) ? implode(', ', wp_list_pluck($tags, 'name')) : '';
        }

        // Search
        if (is_search()) {
            $page['search_term'] = get_search_query();
            $page['search_results'] = (int) $wp_query->found_posts;
        }

        // Archive
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term) {
                $page['archive_name'] = $term->name;
                $page['archive_id'] = $term->term_id;
                $page['archive_count'] = $term->count;
            }
        }

        return $page;
    }

    // ================================================================
    // USER DATA - Hashed for all ad platforms
    // ================================================================
    private function get_user_data() {
        $user = [
            'logged_in' => is_user_logged_in(),
            'id' => '',
            'role' => 'guest',
            'type' => 'visitor',
            // PII - hashed for ad platforms (SHA256)
            'email_sha256' => '',
            'phone_sha256' => '',
            'first_name_sha256' => '',
            'last_name_sha256' => '',
            // Address (lowercase, for matching)
            'city' => '',
            'state' => '',
            'country' => '',
            'zip' => '',
            // Raw (only for server-side, not exposed to client if you don't want)
            'email' => '',
            'phone' => '',
            'first_name' => '',
            'last_name' => '',
        ];

        if (!is_user_logged_in()) return $user;

        $wp_user = wp_get_current_user();
        $uid = $wp_user->ID;

        $user['logged_in'] = true;
        $user['id'] = (string) $uid;
        $user['role'] = !empty($wp_user->roles) ? $wp_user->roles[0] : 'subscriber';
        $user['type'] = 'registered';

        // PII
        $email = strtolower(trim($wp_user->user_email));
        $first = strtolower(trim($wp_user->first_name));
        $last = strtolower(trim($wp_user->last_name));

        $user['email'] = $email;
        $user['first_name'] = $first;
        $user['last_name'] = $last;
        $user['email_sha256'] = hash('sha256', $email);
        $user['first_name_sha256'] = hash('sha256', $first);
        $user['last_name_sha256'] = hash('sha256', $last);

        // WooCommerce billing data
        if (class_exists('WooCommerce')) {
            $customer = new WC_Customer($uid);
            $phone = preg_replace('/[^0-9+]/', '', $customer->get_billing_phone());
            $user['phone'] = $phone;
            $user['phone_sha256'] = $phone ? hash('sha256', $phone) : '';
            $user['city'] = strtolower(trim($customer->get_billing_city()));
            $user['state'] = strtolower(trim($customer->get_billing_state()));
            $user['country'] = strtolower(trim($customer->get_billing_country()));
            $user['zip'] = trim($customer->get_billing_postcode());
        }

        // Registration
        $user['registered_date'] = $wp_user->user_registered ? date('Y-m-d', strtotime($wp_user->user_registered)) : '';
        $user['account_age_days'] = $wp_user->user_registered ? (int) ((time() - strtotime($wp_user->user_registered)) / 86400) : 0;

        return $user;
    }

    // ================================================================
    // CUSTOMER DATA - WooCommerce metrics
    // ================================================================
    private function get_customer_data() {
        $customer = [
            'is_customer' => false,
            'type' => 'new',
            'segment' => 'anonymous',
            'lifetime_value' => 0,
            'total_orders' => 0,
            'total_spent' => 0,
            'aov' => 0,
            'first_order' => '',
            'last_order' => '',
            'days_since_order' => 0,
            'preferred_payment' => '',
            'preferred_category' => '',
            'currency' => 'USD',
        ];

        if (!is_user_logged_in() || !class_exists('WooCommerce')) return $customer;

        $uid = get_current_user_id();
        $wc_customer = new WC_Customer($uid);

        $orders = (int) $wc_customer->get_order_count();
        $spent = (float) $wc_customer->get_total_spent();
        $aov = $orders > 0 ? round($spent / $orders, 2) : 0;

        $customer['is_customer'] = $orders > 0;
        $customer['total_orders'] = $orders;
        $customer['total_spent'] = $spent;
        $customer['lifetime_value'] = $spent;
        $customer['aov'] = $aov;
        $customer['currency'] = get_woocommerce_currency();

        // Type
        if ($orders === 0) $customer['type'] = 'prospect';
        elseif ($orders === 1) $customer['type'] = 'first_time';
        else $customer['type'] = 'returning';

        // Segment (RFM based)
        if ($spent >= 5000 || $orders >= 20) $customer['segment'] = 'vip';
        elseif ($spent >= 2000 || $orders >= 10) $customer['segment'] = 'champion';
        elseif ($spent >= 1000 || $orders >= 5) $customer['segment'] = 'loyal';
        elseif ($spent >= 300 || $orders >= 2) $customer['segment'] = 'repeat';
        elseif ($orders >= 1) $customer['segment'] = 'first_time';
        else $customer['segment'] = 'prospect';

        // Dates
        $first = $this->get_customer_order_date($uid, 'ASC');
        $last = $this->get_customer_order_date($uid, 'DESC');
        $customer['first_order'] = $first;
        $customer['last_order'] = $last;
        $customer['days_since_order'] = $last ? (int) ((time() - strtotime($last)) / 86400) : 0;

        // Preferred payment
        $customer['preferred_payment'] = $this->get_top_payment_method($uid);

        // Preferred category
        $customer['preferred_category'] = $this->get_top_category($uid);

        return $customer;
    }

    // ================================================================
    // ECOMMERCE CONTEXT - Current page product/cart/checkout data
    // ================================================================
    private function get_ecommerce_context() {
        if (!class_exists('WooCommerce')) {
            return ['active' => false];
        }

        $ctx = [
            'active' => true,
            'currency' => get_woocommerce_currency(),
            'page_type' => $this->get_woo_page_type(),
            'cart_total' => 0,
            'cart_items_count' => 0,
            'cart_items' => [],
            'product' => null,
        ];

        // Cart data (available on all pages after add-to-cart)
        if (WC()->cart) {
            $cart = WC()->cart;
            $ctx['cart_total'] = (float) $cart->get_cart_contents_total();
            $ctx['cart_items_count'] = $cart->get_cart_contents_count();
            $ctx['cart_coupons'] = $cart->get_applied_coupons();

            // Cart items (for remarketing)
            $items = [];
            $i = 0;
            foreach ($cart->get_cart() as $cart_item) {
                $product = $cart_item['data'];
                $items[] = $this->build_item_object($product, $i, $cart_item['quantity']);
                $i++;
                if ($i >= 20) break; // limit for performance
            }
            $ctx['cart_items'] = $items;
        }

        // Current product (product page)
        if (is_product()) {
            global $product;
            if ($product) {
                $ctx['product'] = $this->build_item_object($product, 0, 1);
            }
        }

        return $ctx;
    }

    // ================================================================
    // SITE DATA
    // ================================================================
    private function get_site_data() {
        return [
            'name' => get_bloginfo('name'),
            'url' => home_url(),
            'language' => get_locale(),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'environment' => (defined('WP_DEBUG') && WP_DEBUG) ? 'development' : 'production',
            'woocommerce' => class_exists('WooCommerce'),
            'plugin_version' => JDL_VERSION,
        ];
    }

    // ================================================================
    // CONSENT STATE
    // ================================================================
    private function get_consent_state() {
        // Default denied - should be updated by CMP (cookie consent tool)
        return [
            'ad_storage' => 'denied',
            'analytics_storage' => 'granted',
            'ad_user_data' => 'denied',
            'ad_personalization' => 'denied',
            'functionality_storage' => 'granted',
            'personalization_storage' => 'granted',
            'security_storage' => 'granted',
        ];
    }

    // ================================================================
    // ITEM BUILDER - Full GA4 + all platform fields
    // ================================================================
    private function build_item_object($product, $index = 0, $quantity = 1) {
        $product_id = $product->get_id();
        $parent_id = $product->get_parent_id();
        $base_id = $parent_id ?: $product_id;

        // Categories
        $categories = wp_get_post_terms($base_id, 'product_cat', ['fields' => 'names']);
        if (is_wp_error($categories)) $categories = [];

        // Brand
        $brands = wp_get_post_terms($base_id, 'product_brand', ['fields' => 'names']);
        $brand = (!is_wp_error($brands) && !empty($brands)) ? $brands[0] : '';
        if (!$brand) {
            $brand = $product->get_attribute('brand') ?: $product->get_attribute('pa_brand') ?: '';
        }

        $item = [
            // GA4 Standard
            'item_id' => (string) $base_id,
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
            'quantity' => (int) $quantity,

            // Extended (all platforms)
            'item_sku' => $product->get_sku() ?: '',
            'item_url' => get_permalink($base_id),
            'item_image' => wp_get_attachment_url($product->get_image_id()) ?: '',
            'item_stock' => $product->get_stock_status(),
            'item_stock_qty' => $product->get_stock_quantity(),
            'item_type' => $product->get_type(),
            'item_regular_price' => (float) ($product->get_regular_price() ?: $product->get_price()),
            'item_sale_price' => $product->is_on_sale() ? (float) $product->get_price() : 0,
            'item_on_sale' => $product->is_on_sale(),
            'item_rating' => (float) $product->get_average_rating(),
            'item_reviews' => (int) $product->get_review_count(),
            'item_weight' => $product->get_weight() ?: '',

            // Facebook/TikTok/Pinterest compatible
            'content_id' => (string) $base_id,
            'content_type' => 'product',
            'content_name' => $parent_id ? wc_get_product($parent_id)->get_name() : $product->get_name(),
            'content_category' => isset($categories[0]) ? $categories[0] : '',
        ];

        // Variant
        if ($product->is_type('variation')) {
            $item['item_variant'] = implode(' / ', array_filter($product->get_variation_attributes()));
        }

        // Discount
        if ($product->is_on_sale() && $product->get_regular_price()) {
            $item['discount'] = (float) round((float) $product->get_regular_price() - (float) $product->get_price(), 2);
        }

        return $item;
    }

    // ================================================================
    // HELPERS
    // ================================================================
    private function get_current_url() {
        return home_url(add_query_arg(null, null)) ?: home_url('/');
    }

    private function get_page_type() {
        if (function_exists('is_shop')) {
            if (is_shop()) return 'shop';
            if (is_product_category()) return 'product_category';
            if (is_product_tag()) return 'product_tag';
            if (is_product()) return 'product';
            if (is_cart()) return 'cart';
            if (is_checkout() && !is_order_received_page()) return 'checkout';
            if (is_order_received_page()) return 'purchase';
            if (is_account_page()) return 'account';
        }
        if (is_front_page()) return 'home';
        if (is_home()) return 'blog';
        if (is_single()) return 'article';
        if (is_page()) return 'page';
        if (is_category()) return 'category';
        if (is_tag()) return 'tag';
        if (is_author()) return 'author';
        if (is_archive()) return 'archive';
        if (is_search()) return 'search';
        if (is_404()) return '404';
        return 'other';
    }

    private function get_woo_page_type() {
        if (!function_exists('is_shop')) return 'none';
        if (is_shop()) return 'shop';
        if (is_product_category()) return 'category';
        if (is_product()) return 'product';
        if (is_cart()) return 'cart';
        if (is_checkout() && !is_order_received_page()) return 'checkout';
        if (is_order_received_page()) return 'purchase';
        if (is_account_page()) return 'account';
        return 'other';
    }

    private function get_customer_order_date($uid, $order = 'DESC') {
        $orders = wc_get_orders([
            'customer_id' => $uid,
            'limit' => 1,
            'orderby' => 'date',
            'order' => $order,
            'status' => ['completed', 'processing'],
        ]);
        if (!empty($orders)) {
            $d = $orders[0]->get_date_created();
            return $d ? $d->format('Y-m-d') : '';
        }
        return '';
    }

    private function get_top_payment_method($uid) {
        $orders = wc_get_orders([
            'customer_id' => $uid,
            'limit' => 10,
            'status' => ['completed', 'processing'],
        ]);
        $methods = [];
        foreach ($orders as $o) {
            $m = $o->get_payment_method_title();
            if ($m) $methods[$m] = ($methods[$m] ?? 0) + 1;
        }
        if (empty($methods)) return '';
        arsort($methods);
        return array_key_first($methods);
    }

    private function get_top_category($uid) {
        $orders = wc_get_orders([
            'customer_id' => $uid,
            'limit' => 5,
            'status' => ['completed', 'processing'],
        ]);
        $cats = [];
        foreach ($orders as $o) {
            foreach ($o->get_items() as $item) {
                $terms = wp_get_post_terms($item->get_product_id(), 'product_cat', ['fields' => 'names']);
                if (!is_wp_error($terms)) {
                    foreach ($terms as $t) $cats[$t] = ($cats[$t] ?? 0) + 1;
                }
            }
        }
        if (empty($cats)) return '';
        arsort($cats);
        return array_key_first($cats);
    }
}
