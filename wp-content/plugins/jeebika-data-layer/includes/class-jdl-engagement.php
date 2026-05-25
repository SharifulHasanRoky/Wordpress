<?php
if (!defined('ABSPATH')) exit;

/**
 * Engagement & Interaction Tracking
 * scroll, click, outbound, phone, email, form_submit, file_download, video, 404, login, sign_up, search
 */
class JDL_Engagement {
    private static $instance = null;
    private $s;

    public static function init() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->s = JDL_Settings::init();
        add_action('wp_footer', [$this, 'output'], 99);
        if ($this->s->on('ev_login')) add_action('wp_login', [$this, 'login'], 10, 2);
        if ($this->s->on('ev_sign_up')) add_action('user_register', [$this, 'signup']);
        add_action('wp_footer', [$this, 'deferred'], 5);
    }

    public function output() {
        $cfg = wp_json_encode([
            'scroll' => $this->s->on('ev_scroll'),
            'click' => $this->s->on('ev_click'),
            'form' => $this->s->on('ev_form_submit'),
            'video' => $this->s->on('ev_video'),
            'download' => $this->s->on('ev_file_download'),
            'outbound' => $this->s->on('ev_outbound_click'),
            'phone' => $this->s->on('ev_phone_click'),
            'email' => $this->s->on('ev_email_click'),
            'is404' => is_404(),
            'ev404' => $this->s->on('ev_404'),
        ]);
        ?>
<script>
(function(){
'use strict';
var DL=window.dataLayer=window.dataLayer||[],C=<?php echo $cfg; ?>;

if(C.scroll){var th=[25,50,75,90,100],sf={};window.addEventListener('scroll',function(){var p=Math.round(window.scrollY/(document.documentElement.scrollHeight-window.innerHeight)*100);if(isNaN(p))return;th.forEach(function(t){if(p>=t&&!sf[t]){sf[t]=1;DL.push({event:'scroll',percent_scrolled:t})}})});}

if(C.click){document.addEventListener('click',function(e){var el=e.target.closest('a,button,[data-track]');if(!el)return;var h=el.href||'',t=(el.textContent||el.value||'').trim().substring(0,100);
if(C.phone&&h.indexOf('tel:')===0){DL.push({event:'phone_click',phone_number:h.replace('tel:','').replace(/\s/g,''),link_text:t});return;}
if(C.email&&h.indexOf('mailto:')===0){DL.push({event:'email_click',link_text:t});return;}
if(C.download&&h.match(/\.(pdf|docx?|xlsx?|pptx?|zip|csv)(\?|$)/i)){DL.push({event:'file_download',file_name:h.split('/').pop().split('?')[0],file_extension:h.match(/\.(\w+)(\?|$)/)[1],link_url:h});return;}
if(C.outbound&&el.hostname&&el.hostname!==location.hostname){DL.push({event:'outbound_click',link_url:h,link_domain:el.hostname,link_text:t});return;}
DL.push({event:'select_content',content_type:el.tagName.toLowerCase(),link_text:t,link_url:h});});}

if(C.form){document.addEventListener('submit',function(e){var f=e.target;var isSearch=f.querySelector('[type=search],[name=s]');
if(isSearch){DL.push({event:'search',search_term:(isSearch.value||'')});return;}
DL.push({event:'generate_lead',form_id:f.id||'',form_name:f.getAttribute('name')||f.id||'',form_destination:f.action||''});});}

if(C.video){document.querySelectorAll('video').forEach(function(v){var n=v.title||v.src||'video',pf={};
v.addEventListener('play',function(){DL.push({event:'video_start',video_title:n,video_provider:'html5',video_duration:Math.round(v.duration)})},{once:1});
v.addEventListener('timeupdate',function(){if(!v.duration)return;var p=Math.round(v.currentTime/v.duration*100);[25,50,75].forEach(function(t){if(p>=t&&!pf[t]){pf[t]=1;DL.push({event:'video_progress',video_title:n,video_percent:t})}})});
v.addEventListener('ended',function(){DL.push({event:'video_complete',video_title:n})});});}

if(C.is404&&C.ev404){DL.push({event:'page_not_found',page_location:location.href,page_referrer:document.referrer});}

})();
</script>
        <?php
    }

    public function login($login, $user) {
        set_transient('jdl_ev_' . $user->ID, ['event' => 'login', 'method' => 'standard'], 120);
    }

    public function signup($uid) {
        set_transient('jdl_ev_' . $uid, ['event' => 'sign_up', 'method' => 'standard'], 120);
    }

    public function deferred() {
        if (!is_user_logged_in()) return;
        $uid = get_current_user_id();
        $ev = get_transient('jdl_ev_' . $uid);
        if (!$ev) return;
        delete_transient('jdl_ev_' . $uid);
        echo '<script>window.dataLayer=window.dataLayer||[];window.dataLayer.push(' . wp_json_encode($ev) . ');</script>' . "\n";
    }
}
