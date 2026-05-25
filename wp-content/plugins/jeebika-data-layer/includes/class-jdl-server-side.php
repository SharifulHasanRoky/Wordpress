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
        add_action('rest_api_init', [$this, 'register_routes']);
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
        return new WP_REST_Response(['status' => 'sent', 'event' => $event_name, 'results' => $result], 200);
    }

    public function handle_collect($request) {
        if (!$this->settings->is_enabled('enable_server_side')) {
            return new WP_REST_Response(['status' => 'disabled'], 200);
        }
        $data = $request->get_json_params();
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

        // GA4 Measurement Protocol
        if ($this->settings->get('ss_ga4_measurement_id') && $this->settings->get('ss_ga4_api_secret')) {
            $results['ga4'] = $this->send_to_ga4($event_data);
        }
        // Facebook Conversions API
        if ($this->settings->get('ss_fb_pixel_id') && $this->settings->get('ss_fb_access_token')) {
            $results['facebook'] = $this->send_to_facebook($event_data);
        }
        // TikTok Events API
        if ($this->settings->get('ss_tiktok_pixel_id') && $this->settings->get('ss_tiktok_access_token')) {
            $results['tiktok'] = $this->send_to_tiktok($event_data);
        }
        // Google Ads Conversions API (Enhanced Conversions)
        if ($this->settings->get('ss_gads_customer_id') && $this->settings->get('ss_gads_conversion_action') && $this->settings->get('ss_gads_developer_token')) {
            $results['google_ads'] = $this->send_to_google_ads($event_data);
        }
        // LinkedIn Conversions API
        if ($this->settings->get('ss_linkedin_partner_id') && $this->settings->get('ss_linkedin_access_token')) {
            $results['linkedin'] = $this->send_to_linkedin($event_data);
        }
        // X (Twitter) Conversions API
        if ($this->settings->get('ss_x_pixel_id') && $this->settings->get('ss_x_access_token')) {
            $results['x_twitter'] = $this->send_to_x($event_data);
        }
        // Pinterest Conversions API
        if ($this->settings->get('ss_pinterest_ad_account_id') && $this->settings->get('ss_pinterest_access_token')) {
            $results['pinterest'] = $this->send_to_pinterest($event_data);
        }
        // GTM Server Container
        $server_url = $this->settings->get_server_url();
        if (!empty($server_url)) {
            $results['gtm_server'] = $this->send_to_gtm_server($event_data);
        }

        $this->log_event($event_data, $results);
        return $results;
    }


    // ============ GA4 Measurement Protocol ============
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

    // ============ Facebook Conversions API ============
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
                'event_id' => $event_data['event_id'] ?? wp_generate_uuid4(),
                'user_data' => $this->build_fb_user_data($event_data),
                'custom_data' => $this->build_fb_custom_data($event_data),
            ]],
        ];

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($fb_event),
            'timeout' => 10,
        ]);
        return !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 'error';
    }


    // ============ TikTok Events API ============
    private function send_to_tiktok($event_data) {
        $pixel_id = $this->settings->get('ss_tiktok_pixel_id');
        $access_token = $this->settings->get('ss_tiktok_access_token');
        $url = "https://business-api.tiktok.com/open_api/v1.3/event/track/";

        $tiktok_event = [
            'pixel_code' => $pixel_id,
            'event' => $this->map_to_tiktok_event($event_data['event'] ?? ''),
            'event_id' => $event_data['event_id'] ?? wp_generate_uuid4(),
            'timestamp' => date('Y-m-d\TH:i:s\Z'),
            'context' => [
                'page' => [
                    'url' => $event_data['page_url'] ?? home_url(),
                ],
                'user_agent' => $event_data['_client_data']['user_agent'] ?? '',
                'ip' => $event_data['_client_data']['ip_address'] ?? '',
            ],
            'properties' => $this->build_tiktok_properties($event_data),
        ];

        // Add user data
        if (!empty($event_data['user_email_hash'])) {
            $tiktok_event['context']['user'] = ['email' => $event_data['user_email_hash']];
        }

        $payload = ['data' => [$tiktok_event]];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Access-Token' => $access_token,
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 10,
        ]);
        return !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 'error';
    }

    // ============ Google Ads Enhanced Conversions ============
    private function send_to_google_ads($event_data) {
        $customer_id = $this->settings->get('ss_gads_customer_id');
        $conversion_action = $this->settings->get('ss_gads_conversion_action');
        $developer_token = $this->settings->get('ss_gads_developer_token');

        // Google Ads uses OAuth - simplified offline conversion upload
        $url = "https://googleads.googleapis.com/v15/customers/{$customer_id}:uploadConversionAdjustments";

        $conversion = [
            'conversionAction' => "customers/{$customer_id}/conversionActions/{$conversion_action}",
            'conversionDateTime' => date('Y-m-d H:i:sP'),
            'conversionValue' => $this->extract_value($event_data),
            'currencyCode' => $event_data['currency'] ?? $event_data['ecommerce']['currency'] ?? 'USD',
            'orderId' => $event_data['transaction_id'] ?? ($event_data['ecommerce']['transaction_id'] ?? ''),
        ];

        // Enhanced conversion user identifiers
        $user_identifiers = [];
        if (!empty($event_data['user_email_hash'])) {
            $user_identifiers[] = ['hashedEmail' => $event_data['user_email_hash']];
        }
        if (!empty($event_data['_client_data']['ip_address'])) {
            $user_identifiers[] = ['addressInfo' => ['hashedFirstName' => '', 'hashedLastName' => '']];
        }
        if (!empty($user_identifiers)) {
            $conversion['userIdentifiers'] = $user_identifiers;
        }

        $payload = [
            'conversions' => [$conversion],
            'partialFailure' => true,
        ];

        $oauth_token = $this->settings->get('ss_gads_oauth_token');
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'developer-token' => $developer_token,
                'Authorization' => 'Bearer ' . $oauth_token,
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 15,
        ]);
        return !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 'error';
    }


    // ============ LinkedIn Conversions API ============
    private function send_to_linkedin($event_data) {
        $partner_id = $this->settings->get('ss_linkedin_partner_id');
        $access_token = $this->settings->get('ss_linkedin_access_token');
        $conversion_id = $this->settings->get('ss_linkedin_conversion_id');

        $url = "https://api.linkedin.com/rest/conversionEvents";

        $li_event = [
            'conversion' => "urn:lla:llaPartnerConversion:{$conversion_id}",
            'conversionHappenedAt' => round(microtime(true) * 1000),
            'eventId' => $event_data['event_id'] ?? wp_generate_uuid4(),
            'conversionValue' => [
                'currencyCode' => $event_data['currency'] ?? ($event_data['ecommerce']['currency'] ?? 'USD'),
                'amount' => (string) $this->extract_value($event_data),
            ],
            'user' => $this->build_linkedin_user_data($event_data),
        ];

        $payload = ['elements' => [$li_event]];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
                'LinkedIn-Version' => '202401',
                'X-Restli-Protocol-Version' => '2.0.0',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 10,
        ]);
        return !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 'error';
    }

    // ============ X (Twitter) Conversions API ============
    private function send_to_x($event_data) {
        $pixel_id = $this->settings->get('ss_x_pixel_id');
        $access_token = $this->settings->get('ss_x_access_token');

        $url = "https://ads-api.x.com/12/measurement/conversions/{$pixel_id}";

        $x_event = [
            'conversions' => [[
                'conversion_time' => date('Y-m-d\TH:i:s.000\Z'),
                'event_id' => $event_data['event_id'] ?? wp_generate_uuid4(),
                'conversion_id' => $this->settings->get('ss_x_conversion_id'),
                'identifiers' => $this->build_x_identifiers($event_data),
                'value' => (string) $this->extract_value($event_data),
                'currency' => $event_data['currency'] ?? ($event_data['ecommerce']['currency'] ?? 'USD'),
                'number_items' => $this->count_items($event_data),
                'description' => $event_data['event'] ?? '',
                'conversion_qualifier' => $this->map_to_x_event($event_data['event'] ?? ''),
            ]],
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'body' => wp_json_encode($x_event),
            'timeout' => 10,
        ]);
        return !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 'error';
    }


    // ============ Pinterest Conversions API ============
    private function send_to_pinterest($event_data) {
        $ad_account_id = $this->settings->get('ss_pinterest_ad_account_id');
        $access_token = $this->settings->get('ss_pinterest_access_token');

        $url = "https://api.pinterest.com/v5/ad_accounts/{$ad_account_id}/events";

        $pin_event = [
            'event_name' => $this->map_to_pinterest_event($event_data['event'] ?? ''),
            'action_source' => 'web',
            'event_time' => time(),
            'event_id' => $event_data['event_id'] ?? wp_generate_uuid4(),
            'event_source_url' => $event_data['page_url'] ?? home_url(),
            'user_data' => $this->build_pinterest_user_data($event_data),
            'custom_data' => $this->build_pinterest_custom_data($event_data),
            'partner_name' => 'jeebika_data_layer',
        ];

        $payload = ['data' => [$pin_event]];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 10,
        ]);
        return !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 'error';
    }

    // ============ GTM Server Container ============
    private function send_to_gtm_server($event_data) {
        $server_url = rtrim($this->settings->get_server_url(), '/');
        $url = $server_url . '/collect';

        $payload = [
            'event_name' => $event_data['event'] ?? '',
            'client_id' => $event_data['_client_data']['client_id'] ?? $this->generate_client_id(),
            'ip_override' => $event_data['_client_data']['ip_address'] ?? '',
            'user_agent' => $event_data['_client_data']['user_agent'] ?? '',
        ];
        $payload = array_merge($payload, $event_data['ecommerce'] ?? $event_data);
        unset($payload['_client_data']);

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
            'timeout' => 10,
        ]);
        return !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 'error';
    }


    // ============ WooCommerce Server-Side Hooks ============
    public function track_order_completed($order_id) {
        if (!$this->settings->is_enabled('enable_server_side')) return;
        $order = wc_get_order($order_id);
        if (!$order) return;
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
            'user_email_hash' => hash('sha256', strtolower(trim($order->get_billing_email()))),
            'customer_country' => $order->get_billing_country(),
            'customer_city' => $order->get_billing_city(),
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


    // ============ Event Mapping Methods ============
    private function map_to_ga4_event($event) {
        $map = [
            'page_view' => 'page_view', 'view_item' => 'view_item', 'view_item_list' => 'view_item_list',
            'select_item' => 'select_item', 'add_to_cart' => 'add_to_cart', 'remove_from_cart' => 'remove_from_cart',
            'view_cart' => 'view_cart', 'begin_checkout' => 'begin_checkout', 'add_shipping_info' => 'add_shipping_info',
            'add_payment_info' => 'add_payment_info', 'purchase' => 'purchase', 'refund' => 'refund',
            'user_login' => 'login', 'user_register' => 'sign_up', 'form_submit' => 'generate_lead',
        ];
        return $map[$event] ?? $event;
    }

    private function map_to_fb_event($event) {
        $map = [
            'page_view' => 'PageView', 'view_item' => 'ViewContent', 'add_to_cart' => 'AddToCart',
            'begin_checkout' => 'InitiateCheckout', 'add_payment_info' => 'AddPaymentInfo',
            'purchase' => 'Purchase', 'user_register' => 'CompleteRegistration',
            'form_submit' => 'Lead', 'search' => 'Search', 'view_item_list' => 'ViewContent',
        ];
        return $map[$event] ?? $event;
    }

    private function map_to_tiktok_event($event) {
        $map = [
            'page_view' => 'ViewContent', 'view_item' => 'ViewContent', 'add_to_cart' => 'AddToCart',
            'begin_checkout' => 'InitiateCheckout', 'purchase' => 'CompletePayment',
            'user_register' => 'CompleteRegistration', 'form_submit' => 'SubmitForm',
            'add_payment_info' => 'AddPaymentInfo', 'view_item_list' => 'ViewContent',
            'search' => 'Search', 'add_to_wishlist' => 'AddToWishlist',
        ];
        return $map[$event] ?? 'ViewContent';
    }

    private function map_to_x_event($event) {
        $map = [
            'purchase' => 'PURCHASE', 'add_to_cart' => 'ADD_TO_CART', 'begin_checkout' => 'CHECKOUT_INITIATED',
            'page_view' => 'PAGE_VIEW', 'view_item' => 'CONTENT_VIEW', 'form_submit' => 'LEAD',
            'user_register' => 'SIGN_UP', 'search' => 'SEARCH', 'add_to_wishlist' => 'ADD_TO_WISHLIST',
        ];
        return $map[$event] ?? 'PAGE_VIEW';
    }

    private function map_to_pinterest_event($event) {
        $map = [
            'page_view' => 'page_visit', 'view_item' => 'view_category', 'add_to_cart' => 'add_to_cart',
            'begin_checkout' => 'checkout', 'purchase' => 'checkout', 'form_submit' => 'lead',
            'user_register' => 'signup', 'search' => 'search', 'view_item_list' => 'view_category',
        ];
        return $map[$event] ?? 'custom';
    }


    // ============ Data Building Methods ============
    private function build_ga4_params($event_data) {
        $params = [];
        if (isset($event_data['ecommerce'])) {
            $ecom = $event_data['ecommerce'];
            foreach (['transaction_id', 'value', 'currency', 'tax', 'shipping', 'coupon', 'items'] as $k) {
                if (isset($ecom[$k])) $params[$k] = $ecom[$k];
            }
        }
        foreach (['page_title', 'page_location', 'search_term', 'form_id'] as $key) {
            if (isset($event_data[$key])) $params[$key] = $event_data[$key];
        }
        return $params;
    }

    private function build_fb_user_data($event_data) {
        $user_data = [];
        if (!empty($event_data['_client_data']['ip_address'])) $user_data['client_ip_address'] = $event_data['_client_data']['ip_address'];
        if (!empty($event_data['_client_data']['user_agent'])) $user_data['client_user_agent'] = $event_data['_client_data']['user_agent'];
        if (!empty($event_data['user_email_hash'])) $user_data['em'] = [$event_data['user_email_hash']];
        if (!empty($event_data['customer_country'])) $user_data['country'] = [strtolower($event_data['customer_country'])];
        if (!empty($event_data['customer_city'])) $user_data['ct'] = [strtolower($event_data['customer_city'])];
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
                    return ['id' => $item['item_id'] ?? '', 'quantity' => $item['quantity'] ?? 1, 'item_price' => $item['price'] ?? 0];
                }, $ecom['items']);
                $custom_data['num_items'] = count($ecom['items']);
                $custom_data['content_type'] = 'product';
            }
        }
        return $custom_data;
    }

    private function build_tiktok_properties($event_data) {
        $props = [];
        $value = $this->extract_value($event_data);
        if ($value > 0) $props['value'] = $value;
        $props['currency'] = $event_data['currency'] ?? ($event_data['ecommerce']['currency'] ?? 'USD');
        if (isset($event_data['ecommerce']['items'])) {
            $props['contents'] = array_map(function($item) {
                return [
                    'content_id' => $item['item_id'] ?? '',
                    'content_name' => $item['item_name'] ?? '',
                    'quantity' => $item['quantity'] ?? 1,
                    'price' => $item['price'] ?? 0,
                ];
            }, $event_data['ecommerce']['items']);
            $props['content_type'] = 'product';
        }
        if (!empty($event_data['ecommerce']['transaction_id'])) {
            $props['order_id'] = $event_data['ecommerce']['transaction_id'];
        }
        return $props;
    }


    private function build_linkedin_user_data($event_data) {
        $user_data = [];
        if (!empty($event_data['user_email_hash'])) {
            $user_data['userIds'] = [['idType' => 'SHA256_EMAIL', 'idValue' => $event_data['user_email_hash']]];
        }
        if (!empty($event_data['_client_data']['user_agent'])) {
            $user_data['userInfo'] = ['userAgent' => $event_data['_client_data']['user_agent']];
        }
        return $user_data;
    }

    private function build_x_identifiers($event_data) {
        $identifiers = [];
        if (!empty($event_data['user_email_hash'])) {
            $identifiers[] = ['hashed_email' => $event_data['user_email_hash']];
        }
        if (!empty($event_data['_client_data']['ip_address'])) {
            $identifiers[] = ['twclid' => '', 'ip_address' => $event_data['_client_data']['ip_address']];
        }
        return $identifiers;
    }

    private function build_pinterest_user_data($event_data) {
        $user_data = [];
        if (!empty($event_data['user_email_hash'])) $user_data['em'] = [$event_data['user_email_hash']];
        if (!empty($event_data['_client_data']['ip_address'])) $user_data['client_ip_address'] = $event_data['_client_data']['ip_address'];
        if (!empty($event_data['_client_data']['user_agent'])) $user_data['client_user_agent'] = $event_data['_client_data']['user_agent'];
        return $user_data;
    }

    private function build_pinterest_custom_data($event_data) {
        $custom_data = [];
        $value = $this->extract_value($event_data);
        if ($value > 0) {
            $custom_data['value'] = (string) $value;
            $custom_data['currency'] = $event_data['currency'] ?? ($event_data['ecommerce']['currency'] ?? 'USD');
        }
        if (isset($event_data['ecommerce']['items'])) {
            $custom_data['line_items'] = array_map(function($item) {
                return [
                    'product_id' => $item['item_id'] ?? '',
                    'product_name' => $item['item_name'] ?? '',
                    'product_quantity' => $item['quantity'] ?? 1,
                    'product_price' => (string) ($item['price'] ?? 0),
                ];
            }, $event_data['ecommerce']['items']);
            $custom_data['num_items'] = count($event_data['ecommerce']['items']);
        }
        if (!empty($event_data['ecommerce']['transaction_id'])) {
            $custom_data['order_id'] = $event_data['ecommerce']['transaction_id'];
        }
        return $custom_data;
    }


    // ============ Utility Methods ============
    private function extract_value($event_data) {
        if (isset($event_data['value'])) return (float) $event_data['value'];
        if (isset($event_data['ecommerce']['value'])) return (float) $event_data['ecommerce']['value'];
        return 0;
    }

    private function count_items($event_data) {
        if (isset($event_data['ecommerce']['items'])) return count($event_data['ecommerce']['items']);
        if (isset($event_data['items'])) return count($event_data['items']);
        return 0;
    }

    private function get_client_ip() {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                return trim(explode(',', $_SERVER[$header])[0]);
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
        error_log('[JDL Server-Side] ' . wp_json_encode([
            'time' => current_time('c'),
            'event' => $event_data['event'] ?? 'unknown',
            'results' => $results,
        ]));
    }
}
