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
    }

    public function push_user_data() {
        if (!$this->settings->is_enabled('track_user_data')) return;

        $user_data = $this->get_user_data();
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push(<?php echo wp_json_encode($user_data); ?>);
        </script>
        <?php
    }

    private function get_user_data() {
        $data = [
            'event' => 'user_data',
            'user_logged_in' => is_user_logged_in(),
            'user_id' => '',
            'user_role' => 'guest',
            'user_type' => 'visitor',
        ];

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $data['user_id'] = (string) $user->ID;
            $data['user_role'] = $user->roles[0] ?? 'subscriber';
            $data['user_type'] = 'registered';
            $data['user_email_hash'] = hash('sha256', strtolower(trim($user->user_email)));
            $data['user_registration_date'] = $user->user_registered;
            $data['user_display_name'] = $user->display_name;

            // WooCommerce customer data
            if (class_exists('WooCommerce')) {
                $customer = new WC_Customer($user->ID);
                $data['customer_total_orders'] = $customer->get_order_count();
                $data['customer_total_spent'] = (float) $customer->get_total_spent();
                $data['customer_average_order_value'] = $data['customer_total_orders'] > 0
                    ? round($data['customer_total_spent'] / $data['customer_total_orders'], 2)
                    : 0;
                $data['customer_first_order_date'] = $this->get_first_order_date($user->ID);
                $data['customer_last_order_date'] = $this->get_last_order_date($user->ID);
                $data['customer_lifetime_value'] = (float) $customer->get_total_spent();

                // Customer segment
                $data['customer_segment'] = $this->get_customer_segment($customer);

                // Billing info (hashed)
                $data['customer_country'] = $customer->get_billing_country();
                $data['customer_city'] = $customer->get_billing_city();
                $data['customer_state'] = $customer->get_billing_state();
            }
        }

        return $data;
    }

    private function get_customer_segment($customer) {
        $total_spent = (float) $customer->get_total_spent();
        $order_count = $customer->get_order_count();

        if ($total_spent >= 1000 || $order_count >= 10) return 'vip';
        if ($total_spent >= 500 || $order_count >= 5) return 'loyal';
        if ($total_spent >= 100 || $order_count >= 2) return 'repeat';
        if ($order_count >= 1) return 'first_time';
        return 'prospect';
    }

    private function get_first_order_date($user_id) {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'ASC',
            'status' => ['completed', 'processing'],
        ]);
        return !empty($orders) ? $orders[0]->get_date_created()->format('Y-m-d') : '';
    }

    private function get_last_order_date($user_id) {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => ['completed', 'processing'],
        ]);
        return !empty($orders) ? $orders[0]->get_date_created()->format('Y-m-d') : '';
    }

    public function track_login($user_login, $user) {
        if (!$this->settings->is_enabled('track_user_login')) return;

        $login_data = [
            'jdl_event' => 'user_login',
            'user_id' => (string) $user->ID,
            'user_role' => $user->roles[0] ?? 'subscriber',
            'login_method' => 'standard',
            'timestamp' => current_time('c'),
        ];

        // Store in transient for next page load
        set_transient('jdl_login_event_' . $user->ID, $login_data, 60);
    }

    public function track_registration($user_id) {
        if (!$this->settings->is_enabled('track_user_register')) return;

        $user = get_userdata($user_id);
        $reg_data = [
            'jdl_event' => 'user_register',
            'user_id' => (string) $user_id,
            'user_role' => $user->roles[0] ?? 'subscriber',
            'registration_method' => 'standard',
            'timestamp' => current_time('c'),
        ];

        set_transient('jdl_register_event_' . $user_id, $reg_data, 60);
    }
}
