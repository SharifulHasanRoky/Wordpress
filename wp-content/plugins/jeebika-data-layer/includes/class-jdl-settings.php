<?php
if (!defined('ABSPATH')) exit;

class JDL_Settings {

    private static $instance = null;
    private $options = [];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->options = get_option('jdl_settings', []);
    }

    public function get($key, $default = '') {
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }

    public function get_all() {
        return $this->options;
    }

    public function is_enabled($key) {
        return !empty($this->options[$key]);
    }

    public function update($key, $value) {
        $this->options[$key] = $value;
        update_option('jdl_settings', $this->options);
    }

    public function save_all($options) {
        $this->options = $options;
        update_option('jdl_settings', $this->options);
    }

    public function get_gtm_id() {
        return $this->get('gtm_container_id', '');
    }

    public function get_server_url() {
        return $this->get('gtm_server_container_url', '');
    }
}
