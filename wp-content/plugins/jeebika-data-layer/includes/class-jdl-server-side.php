<?php
if (!defined('ABSPATH')) exit;

class JDL_Server_Side {

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

        // REST API endpoint for receiving events from frontend
        add_action('rest_api_init', [$this, 'register_routes']);

        // WooCommerce server-side hooks
        add_action('woocommerce_order_status_completed', [$this, 'track_order_completed']);
        add_action('woocommerce_order_status_refunded', [$this, 'track_refund']);
    }

    public function register_routes() {
        register_rest_route('jdl/v1', '/event', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_event'],
            'permission_callback' => [$this, 'verify_request'],
        ]);

        register_rest_route('jdl/v1', '/collect', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_collect'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function verify_request($request) {
        $secret = $request->get_header('X-JDL-Secret');
        return $secret === $this->settings->get('ss_endpoint_secret');
    }

    public function handle_event($request) {
        if (!$this->settings->is_enabled('enable_server_side')) {
            return new WP_REST_Response(['status' => 'disabled'], 200);
        }

        $data = $request->get_json_params();
        $event_name = sanitize_text_field($data['event'] ?? '');

        if (empty($event_name)) {
            return new WP_REST_Response(['error' => 'No event name'], 400);
        }

        $result = $this->send_event($data);

        return new WP_REST_Response([
            'status' => 'sent',
            'event' => $event_name,
            'results' => $result,
        ], 200);
    }

    public function handle_collect($request) {
        if (!$this->settings->is_enabled('enable_server_side')) {
            return new WP_REST_Response(['status' => 'disabled'], 200);
        }

        $data = $request->get_json_params();

        // Collect user consent and client info
        $client_data = [
            'client_id' => sanitize_text_field($data['client_id'] ?? ''),
            'session_id' => sanitize_text_field($data['session_id'] ?? ''),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address' => $this->get_client_ip(),
            'events' => $data['events'] ?? [],
        ];

        $results = [];
        foreach ($client_data['events'] as $event) {
            $event['_client_data'] = $client_data;
            $results[] = $this->send_event($event);
        }

        return new WP_REST_Response(['status' => 'collected', 'count' => count($results)], 200);
    }

    public function send_event($event_data) {
        $results = [];

        // Send to GA4 Measurement Protocol
        if ($this->settings->get('ss_ga4_measurement_id') && $this->settings->get('ss_ga4_api_secret')) {
            $results['ga4'] = $this->send_to_ga4($event_data);
        }

        // Send to Facebook Conversions API
        if ($this->settings->get('ss_fb_pixel_id') && $this->settings->get('ss_fb_access_token')) {
            $results['facebook'] = $this->send_to_facebook($event_data);
        }

        // Send to GTM Server Container
        $server_url = $this->settings->get_server_url();
        if (!empty($server_url)) {
            $results['gtm_server'] = $this->send_to_gtm_server($event_data);
        }

        // Log the event
        $this->log_event($event_data, $results);

        return $results;
    }

    private function send_to_ga4($event_data) {
        $measurement_id = $this->settings->get('ss_ga4_measurement_id');
        $api_secret = $this->settings->get('ss_ga4_api_secret');

        $url = "https://www.google-analytics.com/mp/collect?measurement_id={$measurement_id}&api_secret={$api_secret}";

        $client_id = $event_data['_client_data']['client_id'] ?? $this->generate_client_id();

        $ga4_event = [
            'client_id' => $client_id,
            'events' => [[
                'name' => $this->map_to_ga4_event($event_data['event'] ?? ''),
                'params' => $this->build_ga4_params($event_data),
            ]],
        ];

        // Add user_id if available
        if (!empty($event_data['user_id'])) {
            $ga4_event['user_id'] = $event_data['user_id'];
        }

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($ga4_event),
            'timeout' => 10,
        ]);

        return !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 'error';
    }

    private function send_to_facebook($event_data) {
        $pixel_id = $this->settings->get('ss_fb_pixel_id');
        $access_token = $this->settings->get('ss_fb_access_token');

        $url = "https://graph.facebook.com/v18.0/{$pixel_id}/events?access_token={$access_token}";

        $fb_event = [
            'data' => [[
                'event_name' => $this->map_to_fb_event($event_data['event'] ?? ''),
                'event_time' => time(),
                'action_source' => 'website',
                'event_source_url' => $event_data['page_url'] ?? home_url(),
                'user_data' => $this->build_fb_user_data($event_data),
                'custom_data' => $this->build_fb_custom_data($event_data),
            ]],
        ];

        // Add event_id for deduplication
        $fb_event['data'][0]['event_id'] = $event_data['event_id'] ?? wp_generate_uuid4();

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($fb_event),
            'timeout' => 10,
        ]);

        return !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 'error';
    }

    private function send_to_gtm_server($event_data) {
        $server_url = rtrim($this->settings->get_server_url(), '/');
        $url = $server_url . '/collect';

        $payload = [
            'event_name' => $event_data['event'] ?? '',
            'client_id' => $event_data['_client_data']['client_id'] ?? $this->generate_client_id(),
            'ip_override' => $event_data['_client_data']['ip_address'] ?? '',
            'user_agent' => $event_data['_client_data']['user_agent'] ?? '',
        ];

        // Merge event params
        $payload = array_merge($payload, $event_data['ecommerce'] ?? $event_data);
        unset($payload['_client_data']);

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
            'timeout' => 10,
        ]);

        return !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 'error';
    }

    public function track_order_completed($order_id) {
        if (!$this->settings->is_enabled('enable_server_side')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        // Skip if already tracked server-side
        if (get_post_meta($order_id, '_jdl_ss_tracked', true)) return;

        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $items[] = [
                'item_id' => (string) $product->get_id(),
                'item_name' => $product->get_name(),
                'price' => (float) $product->get_price(),
                'quantity' => $item->get_quantity(),
            ];
        }

        $event_data = [
            'event' => 'purchase',
            'transaction_id' => (string) $order->get_order_number(),
            'value' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'tax' => (float) $order->get_total_tax(),
            'shipping' => (float) $order->get_shipping_total(),
            'items' => $items,
            'user_id' => (string) $order->get_customer_id(),
            'page_url' => home_url(),
            '_client_data' => [
                'client_id' => $this->get_customer_client_id($order),
                'ip_address' => $order->get_customer_ip_address(),
                'user_agent' => $order->get_customer_user_agent(),
            ],
        ];

        $this->send_event($event_data);
        update_post_meta($order_id, '_jdl_ss_tracked', true);
    }

    public function track_refund($order_id) {
        if (!$this->settings->is_enabled('enable_server_side')) return;
        if (!$this->settings->is_enabled('track_refund')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $event_data = [
            'event' => 'refund',
            'transaction_id' => (string) $order->get_order_number(),
            'value' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            '_client_data' => [
                'client_id' => $this->get_customer_client_id($order),
                'ip_address' => $order->get_customer_ip_address(),
                'user_agent' => $order->get_customer_user_agent(),
            ],
        ];

        $this->send_event($event_data);
    }

    // ============ Helper Methods ============

    private function map_to_ga4_event($event) {
        $map = [
            'page_view' => 'page_view',
            'view_item' => 'view_item',
            'view_item_list' => 'view_item_list',
            'select_item' => 'select_item',
            'add_to_cart' => 'add_to_cart',
            'remove_from_cart' => 'remove_from_cart',
            'view_cart' => 'view_cart',
            'begin_checkout' => 'begin_checkout',
            'add_shipping_info' => 'add_shipping_info',
            'add_payment_info' => 'add_payment_info',
            'purchase' => 'purchase',
            'refund' => 'refund',
            'user_login' => 'login',
            'user_register' => 'sign_up',
            'form_submit' => 'generate_lead',
            'phone_click' => 'phone_call',
        ];
        return $map[$event] ?? $event;
    }

    private function map_to_fb_event($event) {
        $map = [
            'page_view' => 'PageView',
            'view_item' => 'ViewContent',
            'add_to_cart' => 'AddToCart',
            'begin_checkout' => 'InitiateCheckout',
            'add_payment_info' => 'AddPaymentInfo',
            'purchase' => 'Purchase',
            'user_register' => 'CompleteRegistration',
            'form_submit' => 'Lead',
            'search' => 'Search',
            'view_item_list' => 'ViewContent',
        ];
        return $map[$event] ?? $event;
    }

    private function build_ga4_params($event_data) {
        $params = [];

        if (isset($event_data['ecommerce'])) {
            $ecom = $event_data['ecommerce'];
            if (isset($ecom['transaction_id'])) $params['transaction_id'] = $ecom['transaction_id'];
            if (isset($ecom['value'])) $params['value'] = $ecom['value'];
            if (isset($ecom['currency'])) $params['currency'] = $ecom['currency'];
            if (isset($ecom['tax'])) $params['tax'] = $ecom['tax'];
            if (isset($ecom['shipping'])) $params['shipping'] = $ecom['shipping'];
            if (isset($ecom['coupon'])) $params['coupon'] = $ecom['coupon'];
            if (isset($ecom['items'])) $params['items'] = $ecom['items'];
        }

        // Non-ecommerce params
        $pass_through = ['page_title', 'page_location', 'search_term', 'form_id'];
        foreach ($pass_through as $key) {
            if (isset($event_data[$key])) $params[$key] = $event_data[$key];
        }

        return $params;
    }

    private function build_fb_user_data($event_data) {
        $user_data = [];

        if (!empty($event_data['_client_data']['ip_address'])) {
            $user_data['client_ip_address'] = $event_data['_client_data']['ip_address'];
        }
        if (!empty($event_data['_client_data']['user_agent'])) {
            $user_data['client_user_agent'] = $event_data['_client_data']['user_agent'];
        }
        if (!empty($event_data['user_email_hash'])) {
            $user_data['em'] = [$event_data['user_email_hash']];
        }
        if (!empty($event_data['customer_country'])) {
            $user_data['country'] = [strtolower($event_data['customer_country'])];
        }
        if (!empty($event_data['customer_city'])) {
            $user_data['ct'] = [strtolower($event_data['customer_city'])];
        }

        return $user_data;
    }

    private function build_fb_custom_data($event_data) {
        $custom_data = [];

        if (isset($event_data['ecommerce'])) {
            $ecom = $event_data['ecommerce'];
            if (isset($ecom['value'])) $custom_data['value'] = $ecom['value'];
            if (isset($ecom['currency'])) $custom_data['currency'] = $ecom['currency'];
            if (isset($ecom['transaction_id'])) $custom_data['order_id'] = $ecom['transaction_id'];
            if (isset($ecom['items'])) {
                $custom_data['contents'] = array_map(function($item) {
                    return [
                        'id' => $item['item_id'] ?? '',
                        'quantity' => $item['quantity'] ?? 1,
                        'item_price' => $item['price'] ?? 0,
                    ];
                }, $ecom['items']);
                $custom_data['num_items'] = count($ecom['items']);
                $custom_data['content_type'] = 'product';
            }
        }

        return $custom_data;
    }

    private function get_client_ip() {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', $_SERVER[$header])[0];
                return trim($ip);
            }
        }
        return '0.0.0.0';
    }

    private function generate_client_id() {
        return wp_generate_uuid4();
    }

    private function get_customer_client_id($order) {
        $customer_id = $order->get_customer_id();
        if ($customer_id) {
            return 'wp_' . $customer_id . '.' . strtotime($order->get_date_created()->format('Y-m-d'));
        }
        return $this->generate_client_id();
    }

    private function log_event($event_data, $results) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) return;

        $log_entry = [
            'time' => current_time('c'),
            'event' => $event_data['event'] ?? 'unknown',
            'results' => $results,
        ];

        error_log('[JDL Server-Side] ' . wp_json_encode($log_entry));
    }
}
