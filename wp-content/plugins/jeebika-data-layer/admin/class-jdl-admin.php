<?php
if (!defined('ABSPATH')) exit;

class JDL_Admin {

    private static $instance = null;

    public static function init() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_jdl_save', [$this, 'ajax_save']);
    }

    public function register_menu() {
        add_menu_page(
            'Jeebika Tracking',
            'Jeebika Tracking',
            'manage_options',
            'jeebika-tracking',
            [$this, 'render_page'],
            'dashicons-chart-area',
            80
        );

        add_submenu_page('jeebika-tracking', 'GTM Settings', 'GTM Settings', 'manage_options', 'jeebika-tracking', [$this, 'render_page']);
        add_submenu_page('jeebika-tracking', 'Events', 'Events', 'manage_options', 'jeebika-tracking-events', [$this, 'render_page']);
        add_submenu_page('jeebika-tracking', 'Platforms (Server-Side)', 'Platforms (Server-Side)', 'manage_options', 'jeebika-tracking-platforms', [$this, 'render_page']);
        add_submenu_page('jeebika-tracking', 'User Data', 'User Data', 'manage_options', 'jeebika-tracking-userdata', [$this, 'render_page']);
        add_submenu_page('jeebika-tracking', 'Niches', 'Niches', 'manage_options', 'jeebika-tracking-niches', [$this, 'render_page']);
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'jeebika-tracking') === false) return;

        wp_enqueue_style('jdl-admin', JDL_URL . 'assets/css/jdl-admin.css', [], JDL_VERSION);
        wp_enqueue_script('jdl-admin', JDL_URL . 'assets/js/jdl-admin.js', ['jquery'], JDL_VERSION, true);
        wp_localize_script('jdl-admin', 'jdl_admin', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jdl_save_nonce'),
        ]);
    }

    public function ajax_save() {
        check_ajax_referer('jdl_save_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $defaults = Jeebika_Data_Layer::defaults();
        $current  = get_option('jdl_options', $defaults);
        $posted   = isset($_POST['settings']) ? $_POST['settings'] : [];

        if (is_string($posted)) {
            parse_str($posted, $posted);
        }

        // Merge posted settings with current
        foreach ($posted as $key => $value) {
            if (array_key_exists($key, $defaults)) {
                $current[$key] = sanitize_text_field($value);
            }
        }

        update_option('jdl_options', $current);
        wp_send_json_success(['message' => 'Settings saved successfully.']);
    }

    public function render_page() {
        $opts = get_option('jdl_options', Jeebika_Data_Layer::defaults());
        $tab  = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'jeebika-tracking';
        ?>
        <div class="wrap jdl-wrap">
            <h1>Jeebika Tracking</h1>

            <nav class="jdl-tabs">
                <a href="<?php echo admin_url('admin.php?page=jeebika-tracking'); ?>" class="jdl-tab <?php echo $tab === 'jeebika-tracking' ? 'active' : ''; ?>">GTM Settings</a>
                <a href="<?php echo admin_url('admin.php?page=jeebika-tracking-events'); ?>" class="jdl-tab <?php echo $tab === 'jeebika-tracking-events' ? 'active' : ''; ?>">Events</a>
                <a href="<?php echo admin_url('admin.php?page=jeebika-tracking-platforms'); ?>" class="jdl-tab <?php echo $tab === 'jeebika-tracking-platforms' ? 'active' : ''; ?>">Platforms (Server-Side)</a>
                <a href="<?php echo admin_url('admin.php?page=jeebika-tracking-userdata'); ?>" class="jdl-tab <?php echo $tab === 'jeebika-tracking-userdata' ? 'active' : ''; ?>">User Data</a>
                <a href="<?php echo admin_url('admin.php?page=jeebika-tracking-niches'); ?>" class="jdl-tab <?php echo $tab === 'jeebika-tracking-niches' ? 'active' : ''; ?>">Niches</a>
            </nav>

            <form id="jdl-settings-form" class="jdl-form">
                <?php
                switch ($tab) {
                    case 'jeebika-tracking':
                        $this->render_gtm_settings($opts);
                        break;
                    case 'jeebika-tracking-events':
                        $this->render_events($opts);
                        break;
                    case 'jeebika-tracking-platforms':
                        $this->render_platforms($opts);
                        break;
                    case 'jeebika-tracking-userdata':
                        $this->render_userdata($opts);
                        break;
                    case 'jeebika-tracking-niches':
                        $this->render_niches($opts);
                        break;
                }
                ?>
                <div class="jdl-actions">
                    <button type="submit" class="button button-primary jdl-save-btn">Save Settings</button>
                </div>
            </form>
        </div>
        <div id="jdl-toast" class="jdl-toast"></div>
        <?php
    }

    private function render_gtm_settings($opts) {
        ?>
        <div class="jdl-card">
            <h2>Google Tag Manager</h2>
            <div class="jdl-section-actions">
                <button type="button" class="button jdl-enable-all">Enable All</button>
                <button type="button" class="button jdl-disable-all">Disable All</button>
            </div>
            <div class="jdl-grid">
                <div class="jdl-field">
                    <label for="gtm_id">GTM Container ID</label>
                    <input type="text" id="gtm_id" name="gtm_id" data-key="gtm_id" value="<?php echo esc_attr($opts['gtm_id']); ?>" placeholder="GTM-XXXXXXX" />
                </div>
                <div class="jdl-field">
                    <label for="gtm_server_url">GTM Server-Side URL</label>
                    <input type="text" id="gtm_server_url" name="gtm_server_url" data-key="gtm_server_url" value="<?php echo esc_attr($opts['gtm_server_url']); ?>" placeholder="https://gtm.yourdomain.com" />
                </div>
            </div>
            <div class="jdl-toggles">
                <?php
                $this->render_toggle('gtm_head', 'GTM in &lt;head&gt;', $opts);
                $this->render_toggle('gtm_body', 'GTM in &lt;body&gt; (noscript)', $opts);
                ?>
            </div>
        </div>
        <?php
    }

    private function render_events($opts) {
        $events = [
            'ev_page_view'        => 'Page View',
            'ev_view_item'        => 'View Item',
            'ev_view_item_list'   => 'View Item List',
            'ev_select_item'      => 'Select Item',
            'ev_add_to_cart'      => 'Add to Cart',
            'ev_remove_from_cart' => 'Remove from Cart',
            'ev_view_cart'        => 'View Cart',
            'ev_begin_checkout'   => 'Begin Checkout',
            'ev_add_shipping_info'=> 'Add Shipping Info',
            'ev_add_payment_info' => 'Add Payment Info',
            'ev_purchase'         => 'Purchase',
            'ev_refund'           => 'Refund',
            'ev_add_to_wishlist'  => 'Add to Wishlist',
            'ev_login'            => 'Login',
            'ev_sign_up'          => 'Sign Up',
            'ev_search'           => 'Search',
            'ev_generate_lead'    => 'Generate Lead',
            'ev_scroll'           => 'Scroll',
            'ev_click'            => 'Click',
            'ev_file_download'    => 'File Download',
            'ev_form_submit'      => 'Form Submit',
            'ev_video'            => 'Video',
            'ev_phone_click'      => 'Phone Click',
            'ev_email_click'      => 'Email Click',
            'ev_outbound_click'   => 'Outbound Click',
            'ev_404'              => '404 Error',
        ];
        ?>
        <div class="jdl-card">
            <h2>Events</h2>
            <div class="jdl-section-actions">
                <button type="button" class="button jdl-enable-all">Enable All</button>
                <button type="button" class="button jdl-disable-all">Disable All</button>
            </div>
            <div class="jdl-toggles jdl-toggles-grid">
                <?php foreach ($events as $key => $label) : ?>
                    <?php $this->render_toggle($key, $label, $opts); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function render_platforms($opts) {
        ?>
        <div class="jdl-card">
            <h2>Platforms (Server-Side)</h2>
            <div class="jdl-section-actions">
                <button type="button" class="button jdl-enable-all">Enable All</button>
                <button type="button" class="button jdl-disable-all">Disable All</button>
            </div>
            <div class="jdl-toggles">
                <?php $this->render_toggle('ss_enabled', 'Enable Server-Side Tracking', $opts); ?>
            </div>

            <h3>Google Analytics 4</h3>
            <div class="jdl-grid">
                <div class="jdl-field">
                    <label for="ss_ga4_id">GA4 Measurement ID</label>
                    <input type="text" id="ss_ga4_id" name="ss_ga4_id" data-key="ss_ga4_id" value="<?php echo esc_attr($opts['ss_ga4_id']); ?>" placeholder="G-XXXXXXXXXX" />
                </div>
                <div class="jdl-field">
                    <label for="ss_ga4_secret">GA4 API Secret</label>
                    <input type="text" id="ss_ga4_secret" name="ss_ga4_secret" data-key="ss_ga4_secret" value="<?php echo esc_attr($opts['ss_ga4_secret']); ?>" />
                </div>
            </div>

            <h3>Facebook / Meta</h3>
            <div class="jdl-grid">
                <div class="jdl-field">
                    <label for="ss_fb_pixel">Pixel ID</label>
                    <input type="text" id="ss_fb_pixel" name="ss_fb_pixel" data-key="ss_fb_pixel" value="<?php echo esc_attr($opts['ss_fb_pixel']); ?>" />
                </div>
                <div class="jdl-field">
                    <label for="ss_fb_token">Conversions API Token</label>
                    <input type="text" id="ss_fb_token" name="ss_fb_token" data-key="ss_fb_token" value="<?php echo esc_attr($opts['ss_fb_token']); ?>" />
                </div>
            </div>

            <h3>TikTok</h3>
            <div class="jdl-grid">
                <div class="jdl-field">
                    <label for="ss_tt_pixel">Pixel ID</label>
                    <input type="text" id="ss_tt_pixel" name="ss_tt_pixel" data-key="ss_tt_pixel" value="<?php echo esc_attr($opts['ss_tt_pixel']); ?>" />
                </div>
                <div class="jdl-field">
                    <label for="ss_tt_token">Access Token</label>
                    <input type="text" id="ss_tt_token" name="ss_tt_token" data-key="ss_tt_token" value="<?php echo esc_attr($opts['ss_tt_token']); ?>" />
                </div>
            </div>

            <h3>Google Ads</h3>
            <div class="jdl-grid">
                <div class="jdl-field">
                    <label for="ss_gads_id">Conversion ID</label>
                    <input type="text" id="ss_gads_id" name="ss_gads_id" data-key="ss_gads_id" value="<?php echo esc_attr($opts['ss_gads_id']); ?>" />
                </div>
                <div class="jdl-field">
                    <label for="ss_gads_action">Conversion Action</label>
                    <input type="text" id="ss_gads_action" name="ss_gads_action" data-key="ss_gads_action" value="<?php echo esc_attr($opts['ss_gads_action']); ?>" />
                </div>
                <div class="jdl-field">
                    <label for="ss_gads_token">API Token</label>
                    <input type="text" id="ss_gads_token" name="ss_gads_token" data-key="ss_gads_token" value="<?php echo esc_attr($opts['ss_gads_token']); ?>" />
                </div>
                <div class="jdl-field">
                    <label for="ss_gads_oauth">OAuth Refresh Token</label>
                    <input type="text" id="ss_gads_oauth" name="ss_gads_oauth" data-key="ss_gads_oauth" value="<?php echo esc_attr($opts['ss_gads_oauth']); ?>" />
                </div>
            </div>

            <h3>Microsoft / Bing Ads</h3>
            <div class="jdl-grid">
                <div class="jdl-field">
                    <label for="ss_bing_tag_id">UET Tag ID</label>
                    <input type="text" id="ss_bing_tag_id" name="ss_bing_tag_id" data-key="ss_bing_tag_id" value="<?php echo esc_attr($opts['ss_bing_tag_id']); ?>" />
                </div>
                <div class="jdl-field">
                    <label for="ss_bing_action">Action Name</label>
                    <input type="text" id="ss_bing_action" name="ss_bing_action" data-key="ss_bing_action" value="<?php echo esc_attr($opts['ss_bing_action']); ?>" />
                </div>
            </div>

            <h3>LinkedIn</h3>
            <div class="jdl-grid">
                <div class="jdl-field">
                    <label for="ss_li_id">Partner ID</label>
                    <input type="text" id="ss_li_id" name="ss_li_id" data-key="ss_li_id" value="<?php echo esc_attr($opts['ss_li_id']); ?>" />
                </div>
                <div class="jdl-field">
                    <label for="ss_li_conversion">Conversion ID</label>
                    <input type="text" id="ss_li_conversion" name="ss_li_conversion" data-key="ss_li_conversion" value="<?php echo esc_attr($opts['ss_li_conversion']); ?>" />
                </div>
                <div class="jdl-field">
                    <label for="ss_li_token">Access Token</label>
                    <input type="text" id="ss_li_token" name="ss_li_token" data-key="ss_li_token" value="<?php echo esc_attr($opts['ss_li_token']); ?>" />
                </div>
            </div>

            <h3>X / Twitter</h3>
            <div class="jdl-grid">
                <div class="jdl-field">
                    <label for="ss_x_pixel">Pixel ID</label>
                    <input type="text" id="ss_x_pixel" name="ss_x_pixel" data-key="ss_x_pixel" value="<?php echo esc_attr($opts['ss_x_pixel']); ?>" />
                </div>
                <div class="jdl-field">
                    <label for="ss_x_event">Event ID</label>
                    <input type="text" id="ss_x_event" name="ss_x_event" data-key="ss_x_event" value="<?php echo esc_attr($opts['ss_x_event']); ?>" />
                </div>
                <div class="jdl-field">
                    <label for="ss_x_token">Access Token</label>
                    <input type="text" id="ss_x_token" name="ss_x_token" data-key="ss_x_token" value="<?php echo esc_attr($opts['ss_x_token']); ?>" />
                </div>
            </div>

            <h3>Pinterest</h3>
            <div class="jdl-grid">
                <div class="jdl-field">
                    <label for="ss_pin_account">Ad Account ID</label>
                    <input type="text" id="ss_pin_account" name="ss_pin_account" data-key="ss_pin_account" value="<?php echo esc_attr($opts['ss_pin_account']); ?>" />
                </div>
                <div class="jdl-field">
                    <label for="ss_pin_token">Access Token</label>
                    <input type="text" id="ss_pin_token" name="ss_pin_token" data-key="ss_pin_token" value="<?php echo esc_attr($opts['ss_pin_token']); ?>" />
                </div>
            </div>

            <h3>Snapchat</h3>
            <div class="jdl-grid">
                <div class="jdl-field">
                    <label for="ss_snap_pixel">Pixel ID</label>
                    <input type="text" id="ss_snap_pixel" name="ss_snap_pixel" data-key="ss_snap_pixel" value="<?php echo esc_attr($opts['ss_snap_pixel']); ?>" />
                </div>
                <div class="jdl-field">
                    <label for="ss_snap_token">Access Token</label>
                    <input type="text" id="ss_snap_token" name="ss_snap_token" data-key="ss_snap_token" value="<?php echo esc_attr($opts['ss_snap_token']); ?>" />
                </div>
            </div>
        </div>
        <?php
    }

    private function render_userdata($opts) {
        ?>
        <div class="jdl-card">
            <h2>User Data</h2>
            <div class="jdl-section-actions">
                <button type="button" class="button jdl-enable-all">Enable All</button>
                <button type="button" class="button jdl-disable-all">Disable All</button>
            </div>
            <div class="jdl-toggles">
                <?php
                $this->render_toggle('ud_enabled', 'Enable User Data Collection', $opts);
                $this->render_toggle('ud_hash_email', 'Hash Email (SHA-256)', $opts);
                $this->render_toggle('ud_hash_phone', 'Hash Phone (SHA-256)', $opts);
                $this->render_toggle('ud_hash_name', 'Hash Name (SHA-256)', $opts);
                $this->render_toggle('ud_hash_address', 'Hash Address (SHA-256)', $opts);
                $this->render_toggle('ud_customer_data', 'Include Customer Data in DataLayer', $opts);
                ?>
            </div>
        </div>
        <?php
    }

    private function render_niches($opts) {
        $niches = [
            'niche_ecommerce'   => 'E-commerce',
            'niche_lead_gen'    => 'Lead Generation',
            'niche_saas'        => 'SaaS',
            'niche_education'   => 'Education',
            'niche_real_estate' => 'Real Estate',
            'niche_healthcare'  => 'Healthcare',
            'niche_travel'      => 'Travel',
            'niche_finance'     => 'Finance',
            'niche_media'       => 'Media / Publishing',
            'niche_restaurant'  => 'Restaurant / Food',
            'niche_automotive'  => 'Automotive',
            'niche_jobs'        => 'Jobs / Recruitment',
        ];
        ?>
        <div class="jdl-card">
            <h2>Niches</h2>
            <div class="jdl-section-actions">
                <button type="button" class="button jdl-enable-all">Enable All</button>
                <button type="button" class="button jdl-disable-all">Disable All</button>
            </div>
            <div class="jdl-toggles jdl-toggles-grid">
                <?php foreach ($niches as $key => $label) : ?>
                    <?php $this->render_toggle($key, $label, $opts); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function render_toggle($key, $label, $opts) {
        $checked = !empty($opts[$key]) ? 'checked' : '';
        ?>
        <div class="jdl-toggle-row">
            <label class="jdl-toggle">
                <input type="hidden" name="<?php echo esc_attr($key); ?>" value="0" />
                <input type="checkbox" name="<?php echo esc_attr($key); ?>" data-key="<?php echo esc_attr($key); ?>" value="1" <?php echo $checked; ?> />
                <span class="jdl-toggle-slider"></span>
            </label>
            <span class="jdl-toggle-label"><?php echo $label; ?></span>
        </div>
        <?php
    }
}
