<?php
if (!defined('ABSPATH')) exit;

/**
 * Universal Data Layer - Fires on EVERY page
 * Contains: page data, user data (hashed), customer metrics, ecommerce context
 * GA4 schema - all platforms read from this
 */
class JDL_DataLayer {
    private static $instance = null;
    private $s;

    public static function init() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->s = JDL_Settings::init();
        add_action('wp_head', [$this, 'push'], 2);
    }

    public function push() {
        $dl = [
            'event' => 'jdl_ready',
            'page' => $this->page(),
            'site' => $this->site(),
        ];

        if ($this->s->on('ud_enabled')) {
            $dl['user'] = $this->user();
        }
        if ($this->s->on('ud_customer_data') && class_exists('WooCommerce') && is_user_logged_in()) {
            $dl['customer'] = $this->customer();
        }

        echo '<script>window.dataLayer=window.dataLayer||[];window.dataLayer.push(' . wp_json_encode($dl, JSON_UNESCAPED_SLASHES) . ');</script>' . "\n";
    }

    private function page() {
        global $post, $wp_query;
        $p = [
            'location' => home_url(add_query_arg(null, null)),
            'path' => wp_parse_url(home_url(add_query_arg(null, null)), PHP_URL_PATH) ?: '/',
            'title' => wp_get_document_title(),
            'referrer' => wp_get_referer() ?: '',
            'type' => $this->page_type(),
            'language' => get_locale(),
        ];
        if (is_singular() && $post) {
            $p['id'] = $post->ID;
            $p['content_type'] = $post->post_type;
            $p['author'] = get_the_author_meta('display_name', $post->post_author);
            $cats = get_the_category($post->ID);
            $p['category'] = !empty($cats) ? $cats[0]->name : '';
        }
        if (is_search()) {
            $p['search_term'] = get_search_query();
            $p['search_results'] = (int) $wp_query->found_posts;
        }
        return $p;
    }

    private function site() {
        return [
            'name' => get_bloginfo('name'),
            'url' => home_url(),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'environment' => (defined('WP_DEBUG') && WP_DEBUG) ? 'dev' : 'production',
        ];
    }

    private function user() {
        $u = ['logged_in' => is_user_logged_in(), 'id' => '', 'role' => 'guest'];
        if (!is_user_logged_in()) return $u;

        $wp = wp_get_current_user();
        $u['id'] = (string) $wp->ID;
        $u['role'] = $wp->roles[0] ?? 'subscriber';

        $email = strtolower(trim($wp->user_email));
        $fname = strtolower(trim($wp->first_name));
        $lname = strtolower(trim($wp->last_name));

        if ($this->s->on('ud_hash_email')) {
            $u['email_sha256'] = hash('sha256', $email);
            $u['email'] = $email;
        }
        if ($this->s->on('ud_hash_name')) {
            $u['fn_sha256'] = hash('sha256', $fname);
            $u['ln_sha256'] = hash('sha256', $lname);
            $u['fn'] = $fname;
            $u['ln'] = $lname;
        }

        if (class_exists('WooCommerce')) {
            $c = new WC_Customer($wp->ID);
            $phone = preg_replace('/[^0-9+]/', '', $c->get_billing_phone());
            if ($this->s->on('ud_hash_phone') && $phone) {
                $u['phone_sha256'] = hash('sha256', $phone);
                $u['phone'] = $phone;
            }
            if ($this->s->on('ud_hash_address')) {
                $u['city'] = strtolower(trim($c->get_billing_city()));
                $u['state'] = strtolower(trim($c->get_billing_state()));
                $u['country'] = strtolower(trim($c->get_billing_country()));
                $u['zip'] = trim($c->get_billing_postcode());
            }
        }

        $u['registered'] = $wp->user_registered ? date('Y-m-d', strtotime($wp->user_registered)) : '';
        return $u;
    }

    private function customer() {
        $uid = get_current_user_id();
        $c = new WC_Customer($uid);
        $orders = (int) $c->get_order_count();
        $spent = (float) $c->get_total_spent();
        $aov = $orders > 0 ? round($spent / $orders, 2) : 0;

        return [
            'orders' => $orders,
            'spent' => $spent,
            'ltv' => $spent,
            'aov' => $aov,
            'type' => $orders === 0 ? 'prospect' : ($orders === 1 ? 'new' : 'returning'),
            'segment' => $this->segment($spent, $orders),
            'currency' => get_woocommerce_currency(),
        ];
    }

    private function segment($spent, $orders) {
        if ($spent >= 5000 || $orders >= 20) return 'vip';
        if ($spent >= 2000 || $orders >= 10) return 'champion';
        if ($spent >= 1000 || $orders >= 5) return 'loyal';
        if ($orders >= 2) return 'repeat';
        if ($orders >= 1) return 'new';
        return 'prospect';
    }

    private function page_type() {
        if (function_exists('is_shop') && is_shop()) return 'shop';
        if (function_exists('is_product') && is_product()) return 'product';
        if (function_exists('is_product_category') && is_product_category()) return 'product_category';
        if (function_exists('is_cart') && is_cart()) return 'cart';
        if (function_exists('is_checkout') && is_checkout()) return 'checkout';
        if (function_exists('is_order_received_page') && is_order_received_page()) return 'purchase';
        if (function_exists('is_account_page') && is_account_page()) return 'account';
        if (is_front_page()) return 'home';
        if (is_single()) return 'post';
        if (is_page()) return 'page';
        if (is_search()) return 'search';
        if (is_404()) return '404';
        if (is_archive()) return 'archive';
        return 'other';
    }
}
