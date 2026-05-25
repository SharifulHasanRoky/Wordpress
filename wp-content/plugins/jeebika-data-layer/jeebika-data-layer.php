<?php
/**
 * Plugin Name: Jeebika Data Layer & Server-Side Tracking
 * Description: Ultimate data layer + server-side tracking for all ad platforms. GA4 schema. Just add GTM ID and tick what you need.
 * Version: 2.0.0
 * Author: Jeebika
 * Author URI: https://jeebika.com
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) exit;

define('JDL_VERSION', '2.0.0');
define('JDL_DIR', plugin_dir_path(__FILE__));
define('JDL_URL', plugin_dir_url(__FILE__));

final class Jeebika_Data_Layer {

    private static $instance = null;

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        add_action('plugins_loaded', [$this, 'boot']);
        register_activation_hook(__FILE__, [$this, 'activate']);
    }

    private function includes() {
        require_once JDL_DIR . 'includes/class-jdl-settings.php';
        require_once JDL_DIR . 'includes/class-jdl-gtm.php';
        require_once JDL_DIR . 'includes/class-jdl-datalayer.php';
        require_once JDL_DIR . 'includes/class-jdl-ecommerce.php';
        require_once JDL_DIR . 'includes/class-jdl-engagement.php';
        require_once JDL_DIR . 'includes/class-jdl-serverside.php';
        require_once JDL_DIR . 'includes/class-jdl-niche.php';
        if (is_admin()) {
            require_once JDL_DIR . 'admin/class-jdl-admin.php';
        }
    }

    public function boot() {
        JDL_Settings::init();
        JDL_GTM::init();
        JDL_DataLayer::init();
        JDL_Engagement::init();
        JDL_ServerSide::init();
        JDL_Niche::init();
        if (class_exists('WooCommerce')) {
            JDL_Ecommerce::init();
        }
        if (is_admin()) {
            JDL_Admin::init();
        }
    }

    public function activate() {
        if (get_option('jdl_options')) return;
        add_option('jdl_options', self::defaults());
    }

    public static function defaults() {
        return [
            // GTM
            'gtm_id' => '',
            'gtm_server_url' => '',
            'gtm_head' => 1,
            'gtm_body' => 1,

            // Platforms - Server Side
            'ss_enabled' => 0,
            // GA4
            'ss_ga4_id' => '',
            'ss_ga4_secret' => '',
            // Facebook/Meta
            'ss_fb_pixel' => '',
            'ss_fb_token' => '',
            // TikTok
            'ss_tt_pixel' => '',
            'ss_tt_token' => '',
            // Google Ads
            'ss_gads_id' => '',
            'ss_gads_action' => '',
            'ss_gads_token' => '',
            'ss_gads_oauth' => '',
            // Microsoft/Bing Ads
            'ss_bing_tag_id' => '',
            'ss_bing_action' => '',
            // LinkedIn
            'ss_li_id' => '',
            'ss_li_conversion' => '',
            'ss_li_token' => '',
            // X/Twitter
            'ss_x_pixel' => '',
            'ss_x_event' => '',
            'ss_x_token' => '',
            // Pinterest
            'ss_pin_account' => '',
            'ss_pin_token' => '',
            // Snapchat
            'ss_snap_pixel' => '',
            'ss_snap_token' => '',

            // Events (all ON by default)
            'ev_page_view' => 1,
            'ev_view_item' => 1,
            'ev_view_item_list' => 1,
            'ev_select_item' => 1,
            'ev_add_to_cart' => 1,
            'ev_remove_from_cart' => 1,
            'ev_view_cart' => 1,
            'ev_begin_checkout' => 1,
            'ev_add_shipping_info' => 1,
            'ev_add_payment_info' => 1,
            'ev_purchase' => 1,
            'ev_refund' => 1,
            'ev_add_to_wishlist' => 1,
            'ev_login' => 1,
            'ev_sign_up' => 1,
            'ev_search' => 1,
            'ev_generate_lead' => 1,
            'ev_scroll' => 1,
            'ev_click' => 1,
            'ev_file_download' => 1,
            'ev_form_submit' => 1,
            'ev_video' => 1,
            'ev_phone_click' => 1,
            'ev_email_click' => 1,
            'ev_outbound_click' => 1,
            'ev_404' => 1,

            // User Data
            'ud_enabled' => 1,
            'ud_hash_email' => 1,
            'ud_hash_phone' => 1,
            'ud_hash_name' => 1,
            'ud_hash_address' => 1,
            'ud_customer_data' => 1,

            // Niches (all OFF by default, toggle on)
            'niche_ecommerce' => 1,
            'niche_lead_gen' => 0,
            'niche_saas' => 0,
            'niche_education' => 0,
            'niche_real_estate' => 0,
            'niche_healthcare' => 0,
            'niche_travel' => 0,
            'niche_finance' => 0,
            'niche_media' => 0,
            'niche_restaurant' => 0,
            'niche_automotive' => 0,
            'niche_jobs' => 0,
        ];
    }
}

Jeebika_Data_Layer::init();
