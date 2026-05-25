<?php
if (!defined('ABSPATH')) exit;

class JDL_Admin {

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
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_jdl_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_jdl_toggle_all', [$this, 'ajax_toggle_all']);
    }

    public function add_menu() {
        add_menu_page(
            'Jeebika Data Layer',
            'Data Layer',
            'manage_options',
            'jeebika-data-layer',
            [$this, 'render_settings_page'],
            'dashicons-chart-area',
            80
        );

        add_submenu_page(
            'jeebika-data-layer',
            'GTM Settings',
            'GTM Settings',
            'manage_options',
            'jeebika-data-layer',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'jeebika-data-layer',
            'eCommerce Tracking',
            'eCommerce',
            'manage_options',
            'jdl-ecommerce',
            [$this, 'render_ecommerce_page']
        );

        add_submenu_page(
            'jeebika-data-layer',
            'User & Events',
            'User & Events',
            'manage_options',
            'jdl-events',
            [$this, 'render_events_page']
        );

        add_submenu_page(
            'jeebika-data-layer',
            'Industry Tracking',
            'Industries',
            'manage_options',
            'jdl-industry',
            [$this, 'render_industry_page']
        );

        add_submenu_page(
            'jeebika-data-layer',
            'Server-Side Tracking',
            'Server-Side',
            'manage_options',
            'jdl-server-side',
            [$this, 'render_server_side_page']
        );
    }

    public function register_settings() {
        register_setting('jdl_settings_group', 'jdl_settings');
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'jeebika-data-layer') === false && strpos($hook, 'jdl-') === false) return;

        wp_enqueue_style('jdl-admin', JDL_PLUGIN_URL . 'assets/css/jdl-admin.css', [], JDL_VERSION);
        wp_enqueue_script('jdl-admin', JDL_PLUGIN_URL . 'assets/js/jdl-admin.js', ['jquery'], JDL_VERSION, true);
        wp_localize_script('jdl-admin', 'jdlAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jdl_admin_nonce'),
        ]);
    }

    public function render_settings_page() {
        $options = $this->settings->get_all();
        ?>
        <div class="wrap jdl-wrap">
            <h1>🚀 Jeebika Data Layer & Server-Side Tracking</h1>
            <p class="jdl-description">Connect your Google Tag Manager and everything works automatically. One-click enable/disable for all tracking events.</p>

            <div class="jdl-card">
                <h2>📦 Google Tag Manager Setup</h2>
                <p>Enter your GTM Container ID. That's it - everything else is automatic!</p>

                <form method="post" action="" class="jdl-form" id="jdl-settings-form">
                    <?php wp_nonce_field('jdl_admin_nonce', 'jdl_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="gtm_container_id">GTM Container ID</label></th>
                            <td>
                                <input type="text" id="gtm_container_id" name="gtm_container_id" 
                                       value="<?php echo esc_attr($options['gtm_container_id'] ?? ''); ?>" 
                                       placeholder="GTM-XXXXXXX" class="regular-text">
                                <p class="description">Enter your Google Tag Manager Container ID (e.g., GTM-ABC123)</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="gtm_server_container_url">Server-Side GTM URL (Optional)</label></th>
                            <td>
                                <input type="url" id="gtm_server_container_url" name="gtm_server_container_url" 
                                       value="<?php echo esc_url($options['gtm_server_container_url'] ?? ''); ?>" 
                                       placeholder="https://gtm.yourdomain.com" class="regular-text">
                                <p class="description">If you have a server-side GTM container, enter its URL here</p>
                            </td>
                        </tr>
                        <tr>
                            <th>GTM Injection</th>
                            <td>
                                <label class="jdl-toggle">
                                    <input type="checkbox" name="enable_gtm_head" value="1" <?php checked($options['enable_gtm_head'] ?? 1); ?>>
                                    <span class="jdl-toggle-slider"></span>
                                    Inject GTM in &lt;head&gt;
                                </label>
                                <br><br>
                                <label class="jdl-toggle">
                                    <input type="checkbox" name="enable_gtm_body" value="1" <?php checked($options['enable_gtm_body'] ?? 1); ?>>
                                    <span class="jdl-toggle-slider"></span>
                                    Inject GTM noscript in &lt;body&gt;
                                </label>
                            </td>
                        </tr>
                    </table>

                    <button type="submit" class="button button-primary button-hero jdl-save-btn">💾 Save Settings</button>
                </form>
            </div>

            <div class="jdl-card jdl-status-card">
                <h2>📊 Status Overview</h2>
                <div class="jdl-status-grid">
                    <div class="jdl-status-item <?php echo !empty($options['gtm_container_id']) ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <span>GTM: <?php echo !empty($options['gtm_container_id']) ? '<strong>Connected</strong>' : '<em>Not Set</em>'; ?></span>
                    </div>
                    <div class="jdl-status-item <?php echo class_exists('WooCommerce') ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-cart"></span>
                        <span>WooCommerce: <?php echo class_exists('WooCommerce') ? '<strong>Active</strong>' : '<em>Not Found</em>'; ?></span>
                    </div>
                    <div class="jdl-status-item <?php echo !empty($options['enable_server_side']) ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-cloud"></span>
                        <span>Server-Side: <?php echo !empty($options['enable_server_side']) ? '<strong>Active</strong>' : '<em>Disabled</em>'; ?></span>
                    </div>
                    <div class="jdl-status-item active">
                        <span class="dashicons dashicons-admin-page"></span>
                        <span>Data Layer: <strong>Active</strong></span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_ecommerce_page() {
        $options = $this->settings->get_all();
        $ecom_events = [
            'track_view_item' => ['View Item (Product Page)', 'Fires when a user views a product detail page'],
            'track_view_item_list' => ['View Item List (Category/Shop)', 'Fires on product listing pages'],
            'track_select_item' => ['Select Item (Click Product)', 'Fires when clicking a product in a list'],
            'track_add_to_cart' => ['Add to Cart', 'Fires when a product is added to cart'],
            'track_remove_from_cart' => ['Remove from Cart', 'Fires when a product is removed from cart'],
            'track_view_cart' => ['View Cart', 'Fires when the cart page is viewed'],
            'track_begin_checkout' => ['Begin Checkout', 'Fires when checkout process starts'],
            'track_add_shipping_info' => ['Add Shipping Info', 'Fires when shipping method is selected'],
            'track_add_payment_info' => ['Add Payment Info', 'Fires when payment method is selected'],
            'track_purchase' => ['Purchase (Conversion)', 'Fires on the thank you/order confirmation page'],
            'track_refund' => ['Refund', 'Fires when an order is refunded (server-side)'],
        ];
        ?>
        <div class="wrap jdl-wrap">
            <h1>🛒 eCommerce Data Layer Events</h1>
            <p class="jdl-description">All WooCommerce events are tracked dynamically. Toggle individual events on/off.</p>

            <?php if (!class_exists('WooCommerce')): ?>
            <div class="notice notice-warning"><p>⚠️ WooCommerce is not active. eCommerce tracking requires WooCommerce.</p></div>
            <?php endif; ?>

            <div class="jdl-card">
                <div class="jdl-card-header">
                    <h2>eCommerce Events</h2>
                    <div class="jdl-bulk-actions">
                        <button type="button" class="button jdl-enable-all" data-group="ecom">✅ Enable All</button>
                        <button type="button" class="button jdl-disable-all" data-group="ecom">❌ Disable All</button>
                    </div>
                </div>

                <form method="post" class="jdl-form" id="jdl-settings-form">
                    <?php wp_nonce_field('jdl_admin_nonce', 'jdl_nonce'); ?>

                    <div class="jdl-events-grid">
                        <?php foreach ($ecom_events as $key => $info): ?>
                        <div class="jdl-event-item" data-group="ecom">
                            <label class="jdl-toggle">
                                <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" 
                                       <?php checked($options[$key] ?? 0); ?> class="jdl-event-toggle">
                                <span class="jdl-toggle-slider"></span>
                                <span class="jdl-event-name"><?php echo esc_html($info[0]); ?></span>
                            </label>
                            <p class="jdl-event-desc"><?php echo esc_html($info[1]); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="button button-primary button-hero jdl-save-btn">💾 Save eCommerce Settings</button>
                </form>
            </div>

            <div class="jdl-card">
                <h2>📝 Data Layer Output Example</h2>
                <pre class="jdl-code-preview">
// Purchase Event Example (auto-generated)
dataLayer.push({
  "event": "purchase",
  "ecommerce": {
    "transaction_id": "ORD-12345",
    "value": 150.00,
    "tax": 12.50,
    "shipping": 5.00,
    "currency": "BDT",
    "coupon": "SAVE10",
    "payment_method": "bKash",
    "is_new_customer": true,
    "items": [{
      "item_id": "123",
      "item_name": "Product Name",
      "item_brand": "Brand",
      "item_category": "Category",
      "price": 150.00,
      "quantity": 1
    }]
  }
});</pre>
            </div>
        </div>
        <?php
    }

    public function render_events_page() {
        $options = $this->settings->get_all();
        $user_events = [
            'track_user_login' => ['User Login', 'Track when users log in'],
            'track_user_register' => ['User Registration', 'Track new user registrations'],
            'track_user_data' => ['User Properties', 'Push user data (role, LTV, segment) to data layer'],
        ];
        $page_events = [
            'track_page_view' => ['Page View', 'Track all page views with page type, title, URL'],
            'track_scroll_depth' => ['Scroll Depth', 'Track scroll at 25%, 50%, 75%, 90%, 100%'],
            'track_click_events' => ['Click Events', 'Track all clicks (CTA, outbound, phone, email)'],
            'track_form_submit' => ['Form Submissions', 'Track all form submissions'],
            'track_search' => ['Site Search', 'Track internal site search queries'],
            'track_404' => ['404 Page', 'Track 404 error pages'],
        ];
        ?>
        <div class="wrap jdl-wrap">
            <h1>👤 User & Page Events</h1>
            <p class="jdl-description">Track user behavior and page interactions dynamically.</p>

            <div class="jdl-card">
                <div class="jdl-card-header">
                    <h2>User Events</h2>
                    <div class="jdl-bulk-actions">
                        <button type="button" class="button jdl-enable-all" data-group="user">✅ Enable All</button>
                        <button type="button" class="button jdl-disable-all" data-group="user">❌ Disable All</button>
                    </div>
                </div>

                <form method="post" class="jdl-form" id="jdl-settings-form">
                    <?php wp_nonce_field('jdl_admin_nonce', 'jdl_nonce'); ?>

                    <div class="jdl-events-grid">
                        <?php foreach ($user_events as $key => $info): ?>
                        <div class="jdl-event-item" data-group="user">
                            <label class="jdl-toggle">
                                <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" 
                                       <?php checked($options[$key] ?? 0); ?> class="jdl-event-toggle">
                                <span class="jdl-toggle-slider"></span>
                                <span class="jdl-event-name"><?php echo esc_html($info[0]); ?></span>
                            </label>
                            <p class="jdl-event-desc"><?php echo esc_html($info[1]); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <hr>
                    <div class="jdl-card-header">
                        <h2>Page & Interaction Events</h2>
                        <div class="jdl-bulk-actions">
                            <button type="button" class="button jdl-enable-all" data-group="page">✅ Enable All</button>
                            <button type="button" class="button jdl-disable-all" data-group="page">❌ Disable All</button>
                        </div>
                    </div>

                    <div class="jdl-events-grid">
                        <?php foreach ($page_events as $key => $info): ?>
                        <div class="jdl-event-item" data-group="page">
                            <label class="jdl-toggle">
                                <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" 
                                       <?php checked($options[$key] ?? 0); ?> class="jdl-event-toggle">
                                <span class="jdl-toggle-slider"></span>
                                <span class="jdl-event-name"><?php echo esc_html($info[0]); ?></span>
                            </label>
                            <p class="jdl-event-desc"><?php echo esc_html($info[1]); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="button button-primary button-hero jdl-save-btn">💾 Save Event Settings</button>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_industry_page() {
        $options = $this->settings->get_all();
        $industries = [
            'track_lead_generation' => ['Lead Generation', 'Forms, newsletter, WhatsApp clicks, quote requests - works with CF7, Gravity Forms, WPForms, Elementor'],
            'track_saas_events' => ['SaaS / Software', 'Free trial, demo request, pricing page, plan selection, docs views'],
            'track_education_events' => ['Education', 'Course enrollment, application forms, syllabus downloads, program views'],
            'track_real_estate_events' => ['Real Estate', 'Property views, schedule visits, agent contact, mortgage calculator, property search'],
            'track_healthcare_events' => ['Healthcare', 'Appointment booking, doctor/service views, patient portal, emergency clicks'],
            'track_travel_events' => ['Travel & Hospitality', 'Booking clicks, destination views, availability search, review interactions'],
            'track_finance_events' => ['Finance & Banking', 'Application clicks, product views, calculator usage, branch locator'],
            'track_media_events' => ['Media & Content', 'Article reading, subscription clicks, social shares, comments, read completion'],
        ];
        ?>
        <div class="wrap jdl-wrap">
            <h1>🏭 Industry-Specific Tracking</h1>
            <p class="jdl-description">Enable tracking modules for your industry. Each module auto-detects relevant elements on your pages.</p>

            <div class="jdl-card">
                <div class="jdl-card-header">
                    <h2>Industry Modules</h2>
                    <div class="jdl-bulk-actions">
                        <button type="button" class="button jdl-enable-all" data-group="industry">✅ Enable All</button>
                        <button type="button" class="button jdl-disable-all" data-group="industry">❌ Disable All</button>
                    </div>
                </div>

                <form method="post" class="jdl-form" id="jdl-settings-form">
                    <?php wp_nonce_field('jdl_admin_nonce', 'jdl_nonce'); ?>

                    <div class="jdl-events-grid jdl-industry-grid">
                        <?php foreach ($industries as $key => $info): ?>
                        <div class="jdl-event-item jdl-industry-item" data-group="industry">
                            <label class="jdl-toggle">
                                <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" 
                                       <?php checked($options[$key] ?? 0); ?> class="jdl-event-toggle">
                                <span class="jdl-toggle-slider"></span>
                                <span class="jdl-event-name"><?php echo esc_html($info[0]); ?></span>
                            </label>
                            <p class="jdl-event-desc"><?php echo esc_html($info[1]); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="button button-primary button-hero jdl-save-btn">💾 Save Industry Settings</button>
                </form>
            </div>

            <div class="jdl-card">
                <h2>💡 How It Works</h2>
                <ul class="jdl-info-list">
                    <li>✅ Each module auto-detects relevant elements (forms, buttons, links) on your pages</li>
                    <li>✅ No code changes needed - just enable the toggle</li>
                    <li>✅ Works with popular plugins (CF7, Gravity Forms, WPForms, Elementor, etc.)</li>
                    <li>✅ All events push to Google Tag Manager dataLayer automatically</li>
                    <li>✅ Compatible with GA4, Facebook Pixel, TikTok, and any GTM tag</li>
                </ul>
            </div>
        </div>
        <?php
    }

    public function render_server_side_page() {
        $options = $this->settings->get_all();
        ?>
        <div class="wrap jdl-wrap">
            <h1>☁️ Server-Side Tracking</h1>
            <p class="jdl-description">Send events directly from your server for better accuracy and ad-blocker bypass.</p>

            <div class="jdl-card">
                <h2>Server-Side Configuration</h2>
                <form method="post" class="jdl-form" id="jdl-settings-form">
                    <?php wp_nonce_field('jdl_admin_nonce', 'jdl_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th>Enable Server-Side Tracking</th>
                            <td>
                                <label class="jdl-toggle">
                                    <input type="checkbox" name="enable_server_side" value="1" <?php checked($options['enable_server_side'] ?? 0); ?>>
                                    <span class="jdl-toggle-slider"></span>
                                    Enable server-side event forwarding
                                </label>
                            </td>
                        </tr>
                    </table>

                    <h3>📊 GA4 Measurement Protocol</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="ss_ga4_measurement_id">GA4 Measurement ID</label></th>
                            <td>
                                <input type="text" id="ss_ga4_measurement_id" name="ss_ga4_measurement_id" 
                                       value="<?php echo esc_attr($options['ss_ga4_measurement_id'] ?? ''); ?>" 
                                       placeholder="G-XXXXXXXXXX" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ss_ga4_api_secret">GA4 API Secret</label></th>
                            <td>
                                <input type="password" id="ss_ga4_api_secret" name="ss_ga4_api_secret" 
                                       value="<?php echo esc_attr($options['ss_ga4_api_secret'] ?? ''); ?>" 
                                       class="regular-text">
                                <p class="description">Found in GA4 > Admin > Data Streams > Measurement Protocol API secrets</p>
                            </td>
                        </tr>
                    </table>

                    <h3>📘 Facebook Conversions API</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="ss_fb_pixel_id">Facebook Pixel ID</label></th>
                            <td>
                                <input type="text" id="ss_fb_pixel_id" name="ss_fb_pixel_id" 
                                       value="<?php echo esc_attr($options['ss_fb_pixel_id'] ?? ''); ?>" 
                                       placeholder="123456789012345" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ss_fb_access_token">Facebook Access Token</label></th>
                            <td>
                                <input type="password" id="ss_fb_access_token" name="ss_fb_access_token" 
                                       value="<?php echo esc_attr($options['ss_fb_access_token'] ?? ''); ?>" 
                                       class="regular-text">
                                <p class="description">Found in Facebook Events Manager > Settings > Conversions API</p>
                            </td>
                        </tr>
                    </table>

                    <h3>🔐 API Endpoint</h3>
                    <table class="form-table">
                        <tr>
                            <th>REST API Endpoint</th>
                            <td>
                                <code><?php echo esc_html(rest_url('jdl/v1/event')); ?></code>
                                <p class="description">Use this endpoint to send events from external systems</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Endpoint Secret</th>
                            <td>
                                <input type="text" value="<?php echo esc_attr($options['ss_endpoint_secret'] ?? ''); ?>" 
                                       class="regular-text" readonly>
                                <p class="description">Send as X-JDL-Secret header for API authentication</p>
                            </td>
                        </tr>
                    </table>

                    <button type="submit" class="button button-primary button-hero jdl-save-btn">💾 Save Server-Side Settings</button>
                </form>
            </div>
        </div>
        <?php
    }

    public function ajax_save_settings() {
        check_ajax_referer('jdl_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $current_options = $this->settings->get_all();
        $posted = $_POST['settings'] ?? [];

        // Update text fields
        $text_fields = ['gtm_container_id', 'gtm_server_container_url', 'ss_ga4_measurement_id', 
                        'ss_ga4_api_secret', 'ss_fb_pixel_id', 'ss_fb_access_token', 'industry_type'];
        foreach ($text_fields as $field) {
            if (isset($posted[$field])) {
                $current_options[$field] = sanitize_text_field($posted[$field]);
            }
        }

        // Update toggle fields
        $toggle_fields = array_keys(array_filter($current_options, function($v, $k) {
            return strpos($k, 'track_') === 0 || strpos($k, 'enable_') === 0;
        }, ARRAY_FILTER_USE_BOTH));

        foreach ($toggle_fields as $field) {
            $current_options[$field] = isset($posted[$field]) ? 1 : 0;
        }

        $this->settings->save_all($current_options);
        wp_send_json_success('Settings saved!');
    }

    public function ajax_toggle_all() {
        check_ajax_referer('jdl_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $enable = (bool) ($_POST['enable'] ?? true);
        $group = sanitize_text_field($_POST['group'] ?? '');

        $current_options = $this->settings->get_all();

        $group_map = [
            'ecom' => ['track_view_item', 'track_view_item_list', 'track_select_item', 'track_add_to_cart', 
                       'track_remove_from_cart', 'track_view_cart', 'track_begin_checkout', 
                       'track_add_shipping_info', 'track_add_payment_info', 'track_purchase', 'track_refund'],
            'user' => ['track_user_login', 'track_user_register', 'track_user_data'],
            'page' => ['track_page_view', 'track_scroll_depth', 'track_click_events', 
                       'track_form_submit', 'track_search', 'track_404'],
            'industry' => ['track_lead_generation', 'track_saas_events', 'track_education_events',
                          'track_real_estate_events', 'track_healthcare_events', 'track_travel_events',
                          'track_finance_events', 'track_media_events'],
        ];

        if (isset($group_map[$group])) {
            foreach ($group_map[$group] as $field) {
                $current_options[$field] = $enable ? 1 : 0;
            }
        }

        $this->settings->save_all($current_options);
        wp_send_json_success('All toggled!');
    }
}
