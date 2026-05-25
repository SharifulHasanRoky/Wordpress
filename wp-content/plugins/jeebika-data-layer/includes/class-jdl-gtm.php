<?php
if (!defined('ABSPATH')) exit;

class JDL_GTM {
    private static $instance = null;

    public static function init() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $s = JDL_Settings::init();
        if (!$s->gtm_id()) return;
        if ($s->on('gtm_head')) add_action('wp_head', [$this, 'head'], 1);
        if ($s->on('gtm_body')) add_action('wp_body_open', [$this, 'body'], 1);
        add_action('wp_head', [$this, 'dl_init'], 0);
    }

    public function dl_init() {
        echo '<script>window.dataLayer=window.dataLayer||[];</script>' . "\n";
    }

    public function head() {
        $id = JDL_Settings::init()->gtm_id();
        $url = JDL_Settings::init()->server_url();
        $src = $url ? rtrim($url, '/') . '/gtm.js' : 'https://www.googletagmanager.com/gtm.js';
        ?>
<!-- Jeebika GTM -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='<?php echo esc_url($src); ?>?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','<?php echo esc_attr($id); ?>');</script>
        <?php
    }

    public function body() {
        $id = JDL_Settings::init()->gtm_id();
        $url = JDL_Settings::init()->server_url();
        $src = $url ? rtrim($url, '/') . '/ns.html' : 'https://www.googletagmanager.com/ns.html';
        ?>
<noscript><iframe src="<?php echo esc_url($src); ?>?id=<?php echo esc_attr($id); ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <?php
    }
}
