<?php
if (!defined('ABSPATH')) exit;

class JDL_Niche {
    private static $instance = null;
    private $s;

    public static function init() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->s = JDL_Settings::init();
        add_action('wp_footer', [$this, 'output'], 98);
    }

    public function output() {
        $scripts = '';
        if ($this->s->on('niche_lead_gen')) $scripts .= $this->lead_gen();
        if ($this->s->on('niche_saas')) $scripts .= $this->saas();
        if ($this->s->on('niche_education')) $scripts .= $this->education();
        if ($this->s->on('niche_real_estate')) $scripts .= $this->real_estate();
        if ($this->s->on('niche_healthcare')) $scripts .= $this->healthcare();
        if ($this->s->on('niche_travel')) $scripts .= $this->travel();
        if ($this->s->on('niche_finance')) $scripts .= $this->finance();
        if ($this->s->on('niche_media')) $scripts .= $this->media();
        if ($this->s->on('niche_restaurant')) $scripts .= $this->restaurant();
        if ($this->s->on('niche_automotive')) $scripts .= $this->automotive();
        if ($this->s->on('niche_jobs')) $scripts .= $this->jobs();
        if (!$scripts) return;
        echo "<script>(function(){var DL=window.dataLayer||[];{$scripts}})()</script>\n";
    }

    private function lead_gen() { return 'document.addEventListener("wpcf7mailsent",function(e){DL.push({event:"generate_lead",lead_source:"cf7",form_id:e.detail.contactFormId})});document.addEventListener("submit",function(e){var f=e.target;if(f.className.match(/newsletter|subscribe|mc4wp/i))DL.push({event:"sign_up",method:"newsletter"})});document.querySelectorAll("a[href*=\'wa.me\'],a[href*=whatsapp]").forEach(function(e){e.addEventListener("click",function(){DL.push({event:"generate_lead",lead_source:"whatsapp"})})});'; }
    private function saas() { return 'if(location.pathname.match(/pricing|plans/i))DL.push({event:"view_pricing"});document.querySelectorAll("a[href*=trial],a[href*=demo],.free-trial-btn,.demo-btn").forEach(function(e){e.addEventListener("click",function(){DL.push({event:"generate_lead",lead_source:"trial_demo",click_text:(e.textContent||"").trim().substring(0,50)})})});'; }
    private function education() { return 'if(location.pathname.match(/course|program|class/i))DL.push({event:"view_content",content_type:"course"});document.querySelectorAll(".enroll-btn,a[href*=enroll]").forEach(function(e){e.addEventListener("click",function(){DL.push({event:"generate_lead",lead_source:"enrollment"})})});document.querySelectorAll("a[href*=\'.pdf\'],a[download]").forEach(function(e){e.addEventListener("click",function(){DL.push({event:"file_download",file_name:(e.textContent||"").trim()})})});'; }
    private function real_estate() { return 'if(location.pathname.match(/property|listing|apartment|house/i))DL.push({event:"view_content",content_type:"property"});document.querySelectorAll("a[href*=visit],a[href*=tour],.schedule-visit").forEach(function(e){e.addEventListener("click",function(){DL.push({event:"generate_lead",lead_source:"property_visit"})})});'; }
    private function healthcare() { return 'document.querySelectorAll("a[href*=appointment],a[href*=booking],.book-appointment").forEach(function(e){e.addEventListener("click",function(){DL.push({event:"generate_lead",lead_source:"appointment"})})});if(location.pathname.match(/doctor|service|treatment/i))DL.push({event:"view_content",content_type:"health_service"});'; }
    private function travel() { return 'document.querySelectorAll(".book-now,a[href*=book],a[href*=reserve]").forEach(function(e){e.addEventListener("click",function(){DL.push({event:"generate_lead",lead_source:"booking"})})});if(location.pathname.match(/destination|package|tour|hotel/i))DL.push({event:"view_content",content_type:"travel"});'; }
    private function finance() { return 'document.querySelectorAll("a[href*=apply],.apply-now").forEach(function(e){e.addEventListener("click",function(){DL.push({event:"generate_lead",lead_source:"finance_application"})})});if(location.pathname.match(/loan|insurance|credit|investment/i))DL.push({event:"view_content",content_type:"finance_product"});'; }
    private function media() { return 'var art=document.querySelector("article,.entry-content,.post-content");if(art){var wc=art.textContent.trim().split(/\\s+/).length;DL.push({event:"view_content",content_type:"article",word_count:wc,read_time:Math.ceil(wc/200)});var obs=new IntersectionObserver(function(e){e.forEach(function(en){if(en.isIntersecting){DL.push({event:"article_complete"});obs.disconnect()}})});var lp=art.querySelector("p:last-of-type");if(lp)obs.observe(lp)}document.querySelectorAll(".share-btn,[class*=social-share]").forEach(function(e){e.addEventListener("click",function(){DL.push({event:"share",method:"social"})})});'; }
    private function restaurant() { return 'document.querySelectorAll("a[href*=reservation],a[href*=order],.order-now,.reserve-btn").forEach(function(e){e.addEventListener("click",function(){DL.push({event:"generate_lead",lead_source:"restaurant_order"})})});if(location.pathname.match(/menu|food/i))DL.push({event:"view_content",content_type:"menu"});'; }
    private function automotive() { return 'document.querySelectorAll("a[href*=test-drive],a[href*=quote],.book-test-drive").forEach(function(e){e.addEventListener("click",function(){DL.push({event:"generate_lead",lead_source:"test_drive"})})});if(location.pathname.match(/vehicle|car|bike|inventory/i))DL.push({event:"view_content",content_type:"vehicle"});'; }
    private function jobs() { return 'document.querySelectorAll("a[href*=apply],.apply-job,.job-apply").forEach(function(e){e.addEventListener("click",function(){DL.push({event:"generate_lead",lead_source:"job_application"})})});if(location.pathname.match(/job|career|vacancy|position/i))DL.push({event:"view_content",content_type:"job_listing"});'; }
}
