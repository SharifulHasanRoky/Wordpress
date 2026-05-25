<?php
if (!defined('ABSPATH')) exit;

/**
 * Server-Side Tracking - Sends to ALL platforms from server
 * GA4, Facebook CAPI, TikTok, Google Ads, Bing, LinkedIn, X, Pinterest, Snapchat
 */
class JDL_ServerSide {
    private static $instance = null;
    private $s;

    public static function init() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->s = JDL_Settings::init();
        add_action('woocommerce_order_status_completed', [$this, 'order_complete']);
        add_action('woocommerce_order_status_refunded', [$this, 'order_refund']);
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes() {
        register_rest_route('jdl/v1', '/event', [
            'methods' => 'POST',
            'callback' => [$this, 'api_event'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function api_event($req) {
        if (!$this->s->on('ss_enabled')) return new WP_REST_Response(['ok' => false], 200);
        $data = $req->get_json_params();
        if (empty($data['event'])) return new WP_REST_Response(['ok' => false], 400);
        return new WP_REST_Response(['ok' => true, 'results' => $this->send($data)], 200);
    }

    public function send($data) {
        if (!$this->s->on('ss_enabled')) return [];
        $r = [];
        if ($this->s->get('ss_ga4_id') && $this->s->get('ss_ga4_secret'))
            $r['ga4'] = $this->ga4($data);
        if ($this->s->get('ss_fb_pixel') && $this->s->get('ss_fb_token'))
            $r['facebook'] = $this->facebook($data);
        if ($this->s->get('ss_tt_pixel') && $this->s->get('ss_tt_token'))
            $r['tiktok'] = $this->tiktok($data);
        if ($this->s->get('ss_gads_id') && $this->s->get('ss_gads_action'))
            $r['google_ads'] = $this->gads($data);
        if ($this->s->get('ss_bing_tag_id'))
            $r['bing'] = $this->bing($data);
        if ($this->s->get('ss_li_id') && $this->s->get('ss_li_token'))
            $r['linkedin'] = $this->linkedin($data);
        if ($this->s->get('ss_x_pixel') && $this->s->get('ss_x_token'))
            $r['x'] = $this->x_twitter($data);
        if ($this->s->get('ss_pin_account') && $this->s->get('ss_pin_token'))
            $r['pinterest'] = $this->pinterest($data);
        if ($this->s->get('ss_snap_pixel') && $this->s->get('ss_snap_token'))
            $r['snapchat'] = $this->snapchat($data);
        return $r;
    }

    // ===== GA4 Measurement Protocol =====
    private function ga4($d) {
        $url = "https://www.google-analytics.com/mp/collect?measurement_id=" . $this->s->get('ss_ga4_id') . "&api_secret=" . $this->s->get('ss_ga4_secret');
        $body = ['client_id' => $d['_client']['cid'] ?? $this->cid(), 'events' => [['name' => $this->ga4_name($d['event']), 'params' => $this->ga4_params($d)]]];
        if (!empty($d['user_data']['external_id'])) $body['user_id'] = $d['user_data']['external_id'];
        return $this->post($url, $body);
    }

    // ===== Facebook Conversions API =====
    private function facebook($d) {
        $url = "https://graph.facebook.com/v21.0/" . $this->s->get('ss_fb_pixel') . "/events?access_token=" . $this->s->get('ss_fb_token');
        $ev = ['event_name' => $this->fb_name($d['event']), 'event_time' => time(), 'action_source' => 'website', 'event_id' => wp_generate_uuid4()];
        $ev['user_data'] = $this->fb_user($d);
        $ev['custom_data'] = $this->fb_custom($d);
        if (!empty($d['_client']['ua'])) $ev['user_data']['client_user_agent'] = $d['_client']['ua'];
        if (!empty($d['_client']['ip'])) $ev['user_data']['client_ip_address'] = $d['_client']['ip'];
        return $this->post($url, ['data' => [$ev]]);
    }

    // ===== TikTok Events API =====
    private function tiktok($d) {
        $url = "https://business-api.tiktok.com/open_api/v1.3/event/track/";
        $ev = ['pixel_code' => $this->s->get('ss_tt_pixel'), 'event' => $this->tt_name($d['event']), 'event_id' => wp_generate_uuid4(), 'timestamp' => date('c')];
        $ev['context'] = ['user_agent' => $d['_client']['ua'] ?? '', 'ip' => $d['_client']['ip'] ?? ''];
        if (!empty($d['user_data']['email_sha256'])) $ev['context']['user'] = ['email' => $d['user_data']['email_sha256']];
        $ev['properties'] = ['value' => $d['value'] ?? 0, 'currency' => $d['currency'] ?? 'USD'];
        if (!empty($d['items'])) {
            $ev['properties']['contents'] = array_map(function($i) { return ['content_id' => $i['content_id'] ?? $i['item_id'], 'content_name' => $i['content_name'] ?? $i['item_name'], 'quantity' => $i['quantity'] ?? 1, 'price' => $i['price'] ?? 0]; }, $d['items']);
            $ev['properties']['content_type'] = 'product';
        }
        return $this->post($url, ['data' => [$ev]], ['Access-Token' => $this->s->get('ss_tt_token')]);
    }

    // ===== Google Ads Enhanced Conversions =====
    private function gads($d) {
        if ($d['event'] !== 'purchase') return 'skip';
        $cid = $this->s->get('ss_gads_id');
        $url = "https://googleads.googleapis.com/v17/customers/{$cid}:uploadConversionAdjustments";
        $conv = ['conversionAction' => "customers/{$cid}/conversionActions/" . $this->s->get('ss_gads_action'), 'conversionDateTime' => date('Y-m-d H:i:sP'), 'conversionValue' => $d['value'] ?? 0, 'currencyCode' => $d['currency'] ?? 'USD', 'orderId' => $d['transaction_id'] ?? ''];
        $ids = [];
        if (!empty($d['user_data']['email_sha256'])) $ids[] = ['hashedEmail' => $d['user_data']['email_sha256']];
        if (!empty($d['user_data']['phone_sha256'])) $ids[] = ['hashedPhoneNumber' => $d['user_data']['phone_sha256']];
        if ($ids) $conv['userIdentifiers'] = $ids;
        return $this->post($url, ['conversions' => [$conv], 'partialFailure' => true], ['developer-token' => $this->s->get('ss_gads_token'), 'Authorization' => 'Bearer ' . $this->s->get('ss_gads_oauth')]);
    }

    // ===== Microsoft/Bing UET =====
    private function bing($d) {
        if ($d['event'] !== 'purchase' && $d['event'] !== 'generate_lead') return 'skip';
        $tag = $this->s->get('ss_bing_tag_id');
        $url = "https://bat.bing.com/action/0?ti={$tag}&evt=custom&ec=purchase&ea=purchase&el=" . urlencode($d['transaction_id'] ?? '') . "&ev=" . ($d['value'] ?? 0);
        $response = wp_remote_get($url, ['timeout' => 5]);
        return !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 'error';
    }

    // ===== LinkedIn Conversions API =====
    private function linkedin($d) {
        if ($d['event'] !== 'purchase' && $d['event'] !== 'generate_lead') return 'skip';
        $url = "https://api.linkedin.com/rest/conversionEvents";
        $ev = ['conversion' => "urn:lla:llaPartnerConversion:" . $this->s->get('ss_li_conversion'), 'conversionHappenedAt' => round(microtime(true) * 1000), 'eventId' => wp_generate_uuid4()];
        $ev['conversionValue'] = ['currencyCode' => $d['currency'] ?? 'USD', 'amount' => (string) ($d['value'] ?? 0)];
        if (!empty($d['user_data']['email_sha256'])) $ev['user'] = ['userIds' => [['idType' => 'SHA256_EMAIL', 'idValue' => $d['user_data']['email_sha256']]]];
        return $this->post($url, ['elements' => [$ev]], ['Authorization' => 'Bearer ' . $this->s->get('ss_li_token'), 'LinkedIn-Version' => '202401', 'X-Restli-Protocol-Version' => '2.0.0']);
    }

    // ===== X/Twitter Conversions API =====
    private function x_twitter($d) {
        if ($d['event'] !== 'purchase' && $d['event'] !== 'generate_lead') return 'skip';
        $url = "https://ads-api.x.com/12/measurement/conversions/" . $this->s->get('ss_x_pixel');
        $ev = ['conversions' => [['conversion_time' => date('c'), 'event_id' => wp_generate_uuid4(), 'conversion_id' => $this->s->get('ss_x_event'), 'value' => (string) ($d['value'] ?? 0), 'currency' => $d['currency'] ?? 'USD']]];
        if (!empty($d['user_data']['email_sha256'])) $ev['conversions'][0]['identifiers'] = [['hashed_email' => $d['user_data']['email_sha256']]];
        return $this->post($url, $ev, ['Authorization' => 'Bearer ' . $this->s->get('ss_x_token')]);
    }

    // ===== Pinterest Conversions API =====
    private function pinterest($d) {
        $url = "https://api.pinterest.com/v5/ad_accounts/" . $this->s->get('ss_pin_account') . "/events";
        $ev = ['event_name' => $this->pin_name($d['event']), 'action_source' => 'web', 'event_time' => time(), 'event_id' => wp_generate_uuid4()];
        $ev['user_data'] = [];
        if (!empty($d['user_data']['email_sha256'])) $ev['user_data']['em'] = [$d['user_data']['email_sha256']];
        if (!empty($d['_client']['ip'])) $ev['user_data']['client_ip_address'] = $d['_client']['ip'];
        $ev['custom_data'] = ['value' => (string) ($d['value'] ?? 0), 'currency' => $d['currency'] ?? 'USD'];
        if (!empty($d['items'])) $ev['custom_data']['num_items'] = count($d['items']);
        if (!empty($d['transaction_id'])) $ev['custom_data']['order_id'] = $d['transaction_id'];
        return $this->post($url, ['data' => [$ev]], ['Authorization' => 'Bearer ' . $this->s->get('ss_pin_token')]);
    }

    // ===== Snapchat Conversions API =====
    private function snapchat($d) {
        $url = "https://tr.snapchat.com/v2/conversion";
        $ev = ['pixel_id' => $this->s->get('ss_snap_pixel'), 'event_type' => $this->snap_name($d['event']), 'event_conversion_type' => 'WEB', 'timestamp' => date('c')];
        if (!empty($d['user_data']['email_sha256'])) $ev['hashed_email'] = $d['user_data']['email_sha256'];
        if (!empty($d['user_data']['phone_sha256'])) $ev['hashed_phone_number'] = $d['user_data']['phone_sha256'];
        if (!empty($d['_client']['ip'])) $ev['hashed_ip_address'] = hash('sha256', $d['_client']['ip']);
        $ev['price'] = (string) ($d['value'] ?? 0);
        $ev['currency'] = $d['currency'] ?? 'USD';
        if (!empty($d['transaction_id'])) $ev['transaction_id'] = $d['transaction_id'];
        return $this->post($url, $ev, ['Authorization' => 'Bearer ' . $this->s->get('ss_snap_token')]);
    }

    // ===== WooCommerce Hooks =====
    public function order_complete($oid) {
        if (!$this->s->on('ss_enabled')) return;
        if (get_post_meta($oid, '_jdl_ss', true)) return;
        // Handled in purchase event already
    }
    public function order_refund($oid) {
        if (!$this->s->on('ss_enabled')) return;
        $order = wc_get_order($oid);
        if (!$order) return;
        $this->send(['event' => 'refund', 'transaction_id' => (string) $order->get_order_number(), 'value' => (float) $order->get_total(), 'currency' => $order->get_currency(), '_client' => ['ip' => '', 'ua' => '', 'cid' => $this->cid()]]);
    }

    // ===== Helpers =====
    private function post($url, $body, $extra_headers = []) {
        $headers = array_merge(['Content-Type' => 'application/json'], $extra_headers);
        $r = wp_remote_post($url, ['headers' => $headers, 'body' => wp_json_encode($body), 'timeout' => 10]);
        return !is_wp_error($r) ? wp_remote_retrieve_response_code($r) : 'error';
    }
    private function cid() { return wp_generate_uuid4(); }

    private function ga4_name($e) { $m = ['purchase'=>'purchase','refund'=>'refund','generate_lead'=>'generate_lead']; return $m[$e] ?? $e; }
    private function ga4_params($d) { $p = []; foreach (['transaction_id','value','currency','tax','shipping','coupon','items'] as $k) { if (isset($d[$k])) $p[$k] = $d[$k]; } return $p; }
    private function fb_name($e) { $m = ['view_item'=>'ViewContent','add_to_cart'=>'AddToCart','begin_checkout'=>'InitiateCheckout','purchase'=>'Purchase','generate_lead'=>'Lead','sign_up'=>'CompleteRegistration','search'=>'Search','add_payment_info'=>'AddPaymentInfo']; return $m[$e] ?? $e; }
    private function fb_user($d) { $u = []; if (!empty($d['user_data']['email_sha256'])) $u['em'] = [$d['user_data']['email_sha256']]; if (!empty($d['user_data']['phone_sha256'])) $u['ph'] = [$d['user_data']['phone_sha256']]; if (!empty($d['user_data']['country'])) $u['country'] = [$d['user_data']['country']]; if (!empty($d['user_data']['city'])) $u['ct'] = [$d['user_data']['city']]; if (!empty($d['user_data']['external_id'])) $u['external_id'] = [$d['user_data']['external_id']]; return $u; }
    private function fb_custom($d) { $c = []; if (isset($d['value'])) $c['value'] = $d['value']; if (isset($d['currency'])) $c['currency'] = $d['currency']; if (isset($d['transaction_id'])) $c['order_id'] = $d['transaction_id']; if (!empty($d['items'])) { $c['contents'] = array_map(function($i) { return ['id' => $i['content_id'] ?? $i['item_id'], 'quantity' => $i['quantity'] ?? 1, 'item_price' => $i['price'] ?? 0]; }, $d['items']); $c['content_type'] = 'product'; $c['num_items'] = count($d['items']); } return $c; }
    private function tt_name($e) { $m = ['view_item'=>'ViewContent','add_to_cart'=>'AddToCart','begin_checkout'=>'InitiateCheckout','purchase'=>'CompletePayment','generate_lead'=>'SubmitForm','sign_up'=>'CompleteRegistration','search'=>'Search']; return $m[$e] ?? 'ViewContent'; }
    private function pin_name($e) { $m = ['view_item'=>'page_visit','add_to_cart'=>'add_to_cart','begin_checkout'=>'checkout','purchase'=>'checkout','generate_lead'=>'lead','sign_up'=>'signup','search'=>'search']; return $m[$e] ?? 'custom'; }
    private function snap_name($e) { $m = ['view_item'=>'VIEW_CONTENT','add_to_cart'=>'ADD_CART','begin_checkout'=>'START_CHECKOUT','purchase'=>'PURCHASE','generate_lead'=>'SIGN_UP','sign_up'=>'SIGN_UP','search'=>'SEARCH']; return $m[$e] ?? 'PAGE_VIEW'; }
}
