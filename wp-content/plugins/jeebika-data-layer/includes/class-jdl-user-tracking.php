<?php
if (!defined('ABSPATH')) exit;

class JDL_User_Tracking {

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
        add_action('wp_head', [$this, 'push_user_data'], 3);
        add_action('wp_login', [$this, 'track_login'], 10, 2);
        add_action('user_register', [$this, 'track_registration']);
        add_action('wp_footer', [$this, 'push_deferred_events'], 5);
    }

    /**
     * Push complete user/customer data to dataLayer
     * GA4 user properties + enhanced user data for all platforms
     * This fires on EVERY page load before any other event
     */
    public function push_user_data() {
        if (!$this->settings->is_enabled('track_user_data')) return;

        $user_data = $this->build_user_data();
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push(<?php echo wp_json_encode($user_data); ?>);
        </script>
        <?php
    }

    /**
     * Build unified user data object
     * Compatible with: GA4, Facebook CAPI, TikTok, Google Ads, LinkedIn, X, Pinterest
     */
    private function build_user_data() {
        $data = [
            'event' => 'user_data_ready',

            // ===== GA4 User Properties =====
            'user_id' => '',
            'user_logged_in' => is_user_logged_in(),
            'user_type' => 'visitor',
            'user_role' => 'guest',

            // ===== User Data (hashed for server-side) =====
            'user_data' => [
                'email_address' => '',
                'phone_number' => '',
                'address' => [
                    'first_name' => '',
                    'last_name' => '',
                    'street' => '',
                    'city' => '',
                    'region' => '',
                    'postal_code' => '',
                    'country' => '',
                ],
                // Hashed versions for ad platforms (SHA256)
                'sha256_email_address' => '',
                'sha256_phone_number' => '',
                'sha256_first_name' => '',
                'sha256_last_name' => '',
            ],

            // ===== Customer Properties =====
            'customer' => [
                'id' => '',
                'type' => 'new',
                'segment' => 'anonymous',
                'lifetime_value' => 0,
                'total_orders' => 0,
                'total_spent' => 0,
                'average_order_value' => 0,
                'first_order_date' => '',
                'last_order_date' => '',
                'days_since_last_order' => 0,
                'preferred_payment_method' => '',
                'preferred_categories' => '',
                'registration_date' => '',
                'account_age_days' => 0,
            ],

            // ===== Consent / Privacy =====
            'consent' => [
                'ad_storage' => 'denied',
                'analytics_storage' => 'denied',
                'ad_user_data' => 'denied',
                'ad_personalization' => 'denied',
            ],
        ];

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $user_id = $user->ID;

            // GA4 User Properties
            $data['user_id'] = (string) $user_id;
            $data['user_type'] = 'registered';
            $data['user_role'] = !empty($user->roles) ? $user->roles[0] : 'subscriber';

            // User Data (for enhanced conversions / CAPI matching)
            $email = strtolower(trim($user->user_email));
            $first_name = strtolower(trim($user->first_name));
            $last_name = strtolower(trim($user->last_name));

            $data['user_data']['email_address'] = $email;
            $data['user_data']['sha256_email_address'] = hash('sha256', $email);
            $data['user_data']['address']['first_name'] = $first_name;
            $data['user_data']['address']['last_name'] = $last_name;
            $data['user_data']['sha256_first_name'] = hash('sha256', $first_name);
            $data['user_data']['sha256_last_name'] = hash('sha256', $last_name);

            // Registration info
            $reg_date = $user->user_registered;
            $data['customer']['registration_date'] = $reg_date ? date('Y-m-d', strtotime($reg_date)) : '';
            $data['customer']['account_age_days'] = $reg_date ? (int) ((time() - strtotime($reg_date)) / 86400) : 0;
            $data['customer']['id'] = (string) $user_id;

            // WooCommerce customer data
            if (class_exists('WooCommerce')) {
                $data = $this->enrich_with_woocommerce_data($data, $user_id, $user);
            }
        }

        return $data;
    }

    /**
     * Enrich user data with WooCommerce customer information
     */
    private function enrich_with_woocommerce_data($data, $user_id, $user) {
        $customer = new WC_Customer($user_id);

        // Phone number
        $phone = $customer->get_billing_phone();
        if ($phone) {
            $clean_phone = preg_replace('/[^0-9+]/', '', $phone);
            $data['user_data']['phone_number'] = $clean_phone;
            $data['user_data']['sha256_phone_number'] = hash('sha256', $clean_phone);
        }

        // Full address
        $data['user_data']['address']['street'] = strtolower(trim($customer->get_billing_address_1()));
        $data['user_data']['address']['city'] = strtolower(trim($customer->get_billing_city()));
        $data['user_data']['address']['region'] = strtolower(trim($customer->get_billing_state()));
        $data['user_data']['address']['postal_code'] = trim($customer->get_billing_postcode());
        $data['user_data']['address']['country'] = strtolower(trim($customer->get_billing_country()));

        // Customer metrics
        $order_count = $customer->get_order_count();
        $total_spent = (float) $customer->get_total_spent();
        $avg_order = $order_count > 0 ? round($total_spent / $order_count, 2) : 0;

        $data['customer']['total_orders'] = $order_count;
        $data['customer']['total_spent'] = $total_spent;
        $data['customer']['lifetime_value'] = $total_spent;
        $data['customer']['average_order_value'] = $avg_order;

        // Customer type (GA4 new_customer parameter)
        if ($order_count === 0) {
            $data['customer']['type'] = 'new';
        } elseif ($order_count === 1) {
            $data['customer']['type'] = 'first_time';
        } else {
            $data['customer']['type'] = 'returning';
        }

        // Customer segment (for audience building)
        $data['customer']['segment'] = $this->calculate_segment($total_spent, $order_count);

        // Order history dates
        $first_order = $this->get_order_date($user_id, 'ASC');
        $last_order = $this->get_order_date($user_id, 'DESC');

        $data['customer']['first_order_date'] = $first_order;
        $data['customer']['last_order_date'] = $last_order;

        if ($last_order) {
            $data['customer']['days_since_last_order'] = (int) ((time() - strtotime($last_order)) / 86400);
        }

        // Preferred payment method
        $data['customer']['preferred_payment_method'] = $this->get_preferred_payment($user_id);

        // Preferred categories
        $data['customer']['preferred_categories'] = $this->get_preferred_categories($user_id);

        // GA4 user properties for audiences
        $data['user_ltv'] = $total_spent;
        $data['user_order_count'] = $order_count;
        $data['user_segment'] = $data['customer']['segment'];
        $data['new_customer'] = $order_count <= 1;

        return $data;
    }

    /**
     * Calculate customer segment based on RFM
     */
    private function calculate_segment($total_spent, $order_count) {
        if ($total_spent >= 5000 || $order_count >= 20) return 'vip';
        if ($total_spent >= 2000 || $order_count >= 10) return 'champion';
        if ($total_spent >= 1000 || $order_count >= 5) return 'loyal';
        if ($total_spent >= 500 || $order_count >= 3) return 'repeat';
        if ($order_count >= 1) return 'first_time';
        return 'prospect';
    }

    private function get_order_date($user_id, $order = 'DESC') {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => 1,
            'orderby' => 'date',
            'order' => $order,
            'status' => ['completed', 'processing'],
        ]);
        if (!empty($orders)) {
            $date = $orders[0]->get_date_created();
            return $date ? $date->format('Y-m-d') : '';
        }
        return '';
    }

    private function get_preferred_payment($user_id) {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => 10,
            'status' => ['completed', 'processing'],
        ]);
        $methods = [];
        foreach ($orders as $order) {
            $method = $order->get_payment_method_title();
            if ($method) {
                $methods[$method] = ($methods[$method] ?? 0) + 1;
            }
        }
        if (empty($methods)) return '';
        arsort($methods);
        return array_key_first($methods);
    }

    private function get_preferred_categories($user_id) {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => 10,
            'status' => ['completed', 'processing'],
        ]);
        $cats = [];
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']);
                if (!is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $cats[$term] = ($cats[$term] ?? 0) + 1;
                    }
                }
            }
        }
        if (empty($cats)) return '';
        arsort($cats);
        return implode(', ', array_slice(array_keys($cats), 0, 3));
    }

    /**
     * Track login event (GA4: login)
     */
    public function track_login($user_login, $user) {
        if (!$this->settings->is_enabled('track_user_login')) return;

        $login_data = [
            'jdl_event' => 'login',
            'user_id' => (string) $user->ID,
            'method' => 'standard',
            'user_role' => !empty($user->roles) ? $user->roles[0] : 'subscriber',
        ];

        set_transient('jdl_login_event_' . $user->ID, $login_data, 120);
    }

    /**
     * Track registration event (GA4: sign_up)
     */
    public function track_registration($user_id) {
        if (!$this->settings->is_enabled('track_user_register')) return;

        $user = get_userdata($user_id);
        $reg_data = [
            'jdl_event' => 'sign_up',
            'user_id' => (string) $user_id,
            'method' => 'standard',
            'user_role' => !empty($user->roles) ? $user->roles[0] : 'subscriber',
        ];

        set_transient('jdl_register_event_' . $user_id, $reg_data, 120);
    }

    /**
     * Push deferred login/signup events on next page load
     */
    public function push_deferred_events() {
        if (!is_user_logged_in()) return;

        $user_id = get_current_user_id();

        // Check for login event
        $login_data = get_transient('jdl_login_event_' . $user_id);
        if ($login_data) {
            delete_transient('jdl_login_event_' . $user_id);
            ?>
            <script>
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({
                    'event': 'login',
                    'method': '<?php echo esc_js($login_data['method']); ?>',
                    'user_id': '<?php echo esc_js($login_data['user_id']); ?>',
                    'page_location': window.location.href
                });
            </script>
            <?php
        }

        // Check for registration event
        $reg_data = get_transient('jdl_register_event_' . $user_id);
        if ($reg_data) {
            delete_transient('jdl_register_event_' . $user_id);
            ?>
            <script>
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({
                    'event': 'sign_up',
                    'method': '<?php echo esc_js($reg_data['method']); ?>',
                    'user_id': '<?php echo esc_js($reg_data['user_id']); ?>',
                    'page_location': window.location.href
                });
            </script>
            <?php
        }
    }
}
