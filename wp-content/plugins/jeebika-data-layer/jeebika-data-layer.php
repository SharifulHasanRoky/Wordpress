<?php
/**
 * Plugin Name: Jeebika Data Layer & Server-Side Tracking
 * Plugin URI: https://jeebika.com/data-layer
 * Description: Full dynamic data layer and server-side tracking for WordPress & WooCommerce. Supports eCommerce, user data, and multi-industry tracking via Google Tag Manager. One-click enable/disable for all events.
 * Version: 1.0.0
 * Author: Jeebika
 * Author URI: https://jeebika.com
 * Text Domain: jeebika-data-layer
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin Constants
define('JDL_VERSION', '1.0.0');
define('JDL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JDL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('JDL_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 */
final class Jeebika_Data_Layer {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once JDL_PLUGIN_DIR . 'includes/class-jdl-settings.php';
        require_once JDL_PLUGIN_DIR . 'includes/class-jdl-gtm.php';
        require_once JDL_PLUGIN_DIR . 'includes/class-jdl-data-layer.php';
        require_once JDL_PLUGIN_DIR . 'includes/class-jdl-user-tracking.php';
        require_once JDL_PLUGIN_DIR . 'includes/class-jdl-server-side.php';
        require_once JDL_PLUGIN_DIR . 'includes/class-jdl-industry.php';

        if (class_exists('WooCommerce')) {
            require_once JDL_PLUGIN_DIR . 'includes/class-jdl-woocommerce.php';
        }

        if (is_admin()) {
            require_once JDL_PLUGIN_DIR . 'admin/class-jdl-admin.php';
        }
    }

    private function init_hooks() {
        add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function on_plugins_loaded() {
        // Initialize modules
        JDL_Settings::get_instance();
        JDL_GTM::get_instance();
        JDL_Data_Layer::get_instance();
        JDL_User_Tracking::get_instance();
        JDL_Server_Side::get_instance();
        JDL_Industry::get_instance();

        if (class_exists('WooCommerce')) {
            JDL_WooCommerce::get_instance();
        }

        if (is_admin()) {
            JDL_Admin::get_instance();
        }
    }

    public function activate() {
        $default_options = [
            'gtm_container_id' => '',
            'gtm_server_container_url' => '',
            'enable_gtm_head' => 1,
            'enable_gtm_body' => 1,
            // eCommerce Events
            'track_view_item' => 1,
            'track_view_item_list' => 1,
            'track_select_item' => 1,
            'track_add_to_cart' => 1,
            'track_remove_from_cart' => 1,
            'track_view_cart' => 1,
            'track_begin_checkout' => 1,
            'track_add_shipping_info' => 1,
            'track_add_payment_info' => 1,
            'track_purchase' => 1,
            'track_refund' => 1,
            // User Events
            'track_user_login' => 1,
            'track_user_register' => 1,
            'track_user_data' => 1,
            // Page Events
            'track_page_view' => 1,
            'track_scroll_depth' => 1,
            'track_click_events' => 1,
            'track_form_submit' => 1,
            'track_search' => 1,
            'track_404' => 1,
            // Industry
            'industry_type' => 'ecommerce',
            'track_lead_generation' => 0,
            'track_saas_events' => 0,
            'track_education_events' => 0,
            'track_real_estate_events' => 0,
            'track_healthcare_events' => 0,
            'track_travel_events' => 0,
            'track_finance_events' => 0,
            'track_media_events' => 0,
            // Server-Side
            'enable_server_side' => 0,
            'ss_ga4_measurement_id' => '',
            'ss_ga4_api_secret' => '',
            'ss_fb_pixel_id' => '',
            'ss_fb_access_token' => '',
            'ss_tiktok_pixel_id' => '',
            'ss_tiktok_access_token' => '',
            'ss_gads_customer_id' => '',
            'ss_gads_conversion_action' => '',
            'ss_gads_developer_token' => '',
            'ss_gads_oauth_token' => '',
            'ss_linkedin_partner_id' => '',
            'ss_linkedin_conversion_id' => '',
            'ss_linkedin_access_token' => '',
            'ss_x_pixel_id' => '',
            'ss_x_conversion_id' => '',
            'ss_x_access_token' => '',
            'ss_pinterest_ad_account_id' => '',
            'ss_pinterest_access_token' => '',
            'ss_endpoint_secret' => wp_generate_password(32, false),
        ];

        if (!get_option('jdl_settings')) {
            add_option('jdl_settings', $default_options);
        }

        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }
}

// Initialize the plugin
Jeebika_Data_Layer::get_instance();
