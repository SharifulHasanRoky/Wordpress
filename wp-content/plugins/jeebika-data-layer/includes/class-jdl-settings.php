<?php
if (!defined('ABSPATH')) exit;

class JDL_Settings {
    private static $instance = null;
    private $opts = [];

    public static function init() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->opts = get_option('jdl_options', Jeebika_Data_Layer::defaults());
    }

    public function get($key, $default = '') {
        return $this->opts[$key] ?? $default;
    }

    public function on($key) {
        return !empty($this->opts[$key]);
    }

    public function all() {
        return $this->opts;
    }

    public function save($data) {
        $this->opts = $data;
        update_option('jdl_options', $data);
    }

    public function gtm_id() { return $this->get('gtm_id'); }
    public function server_url() { return $this->get('gtm_server_url'); }
}
