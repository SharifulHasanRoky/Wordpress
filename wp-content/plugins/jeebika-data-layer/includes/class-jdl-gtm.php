<?php
if (!defined('ABSPATH')) exit;

class JDL_GTM {

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
        add_action('wp_head', [$this, 'inject_gtm_head'], 1);
        add_action('wp_body_open', [$this, 'inject_gtm_body'], 1);
        add_action('wp_head', [$this, 'inject_datalayer_init'], 0);
    }

    public function inject_datalayer_init() {
        $gtm_id = $this->settings->get_gtm_id();
        if (empty($gtm_id)) return;
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
        </script>
        <?php
    }

    public function inject_gtm_head() {
        $gtm_id = $this->settings->get_gtm_id();
        if (empty($gtm_id) || !$this->settings->is_enabled('enable_gtm_head')) return;

        $server_url = $this->settings->get_server_url();
        $gtm_url = !empty($server_url) ? rtrim($server_url, '/') . '/gtm.js' : 'https://www.googletagmanager.com/gtm.js';
        ?>
        <!-- Jeebika Data Layer - GTM Head -->
        <script>
            (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
            new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
            j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
            '<?php echo esc_url($gtm_url); ?>?id='+i+dl;f.parentNode.insertBefore(j,f);
            })(window,document,'script','dataLayer','<?php echo esc_attr($gtm_id); ?>');
        </script>
        <!-- End Jeebika Data Layer - GTM Head -->
        <?php
    }

    public function inject_gtm_body() {
        $gtm_id = $this->settings->get_gtm_id();
        if (empty($gtm_id) || !$this->settings->is_enabled('enable_gtm_body')) return;

        $server_url = $this->settings->get_server_url();
        $ns_url = !empty($server_url) ? rtrim($server_url, '/') . '/ns.html' : 'https://www.googletagmanager.com/ns.html';
        ?>
        <!-- Jeebika Data Layer - GTM Body -->
        <noscript><iframe src="<?php echo esc_url($ns_url); ?>?id=<?php echo esc_attr($gtm_id); ?>"
        height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Jeebika Data Layer - GTM Body -->
        <?php
    }
}
