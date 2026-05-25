<?php
if (!defined('ABSPATH')) exit;

class JDL_Data_Layer {

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
        add_action('wp_head', [$this, 'push_page_data'], 5);
        add_action('wp_footer', [$this, 'inject_tracking_scripts'], 99);
    }

    /**
     * Push unified page_view data layer (GA4 schema)
     * All platforms read from this single push
     */
    public function push_page_data() {
        if (!$this->settings->is_enabled('track_page_view')) return;

        $data = $this->get_page_data();
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push(<?php echo wp_json_encode($data); ?>);
        </script>
        <?php
    }

    private function get_page_data() {
        global $post, $wp_query;

        $data = [
            // GA4 standard event
            'event' => 'page_view',

            // Page parameters (GA4 auto-collected params)
            'page_location' => $this->get_current_url(),
            'page_path' => wp_parse_url($this->get_current_url(), PHP_URL_PATH) ?: '/',
            'page_title' => wp_get_document_title(),
            'page_referrer' => wp_get_referer() ?: '',

            // Custom page parameters
            'page_type' => $this->get_page_type(),
            'page_id' => is_singular() && $post ? $post->ID : 0,
            'page_template' => is_singular() && $post ? get_page_template_slug($post) : '',

            // Site parameters
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url(),
            'site_language' => get_locale(),
            'site_currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',

            // Content parameters (for content pages)
            'content_type' => is_singular() && $post ? $post->post_type : '',
            'content_id' => is_singular() && $post ? (string) $post->ID : '',
            'content_author' => is_singular() && $post ? get_the_author_meta('display_name', $post->post_author) : '',
            'content_date' => is_singular() && $post ? get_the_date('Y-m-d', $post) : '',
            'content_modified_date' => is_singular() && $post ? get_the_modified_date('Y-m-d', $post) : '',
            'content_category' => $this->get_content_category(),
            'content_tags' => $this->get_content_tags(),
            'content_word_count' => is_singular() && $post ? str_word_count(strip_tags($post->post_content)) : 0,

            // Search parameters
            'search_term' => is_search() ? get_search_query() : '',
            'search_results_count' => is_search() ? (int) $wp_query->found_posts : 0,

            // Environment
            'environment' => defined('WP_DEBUG') && WP_DEBUG ? 'development' : 'production',
            'timestamp' => current_time('c'),
            'unix_timestamp' => time(),
        ];

        // WooCommerce specific page data
        if (function_exists('is_shop')) {
            $data['is_woocommerce'] = is_woocommerce() || is_cart() || is_checkout() || is_account_page();

            if (is_product() && $post) {
                $product = wc_get_product($post->ID);
                if ($product) {
                    $data['product_id'] = (string) $product->get_id();
                    $data['product_sku'] = $product->get_sku();
                    $data['product_name'] = $product->get_name();
                    $data['product_price'] = (float) $product->get_price();
                    $data['product_stock_status'] = $product->get_stock_status();
                    $data['product_type'] = $product->get_type();
                }
            }
        }

        return $data;
    }

    private function get_current_url() {
        $url = home_url(add_query_arg(null, null));
        return $url ?: home_url('/');
    }

    private function get_page_type() {
        if (function_exists('is_shop')) {
            if (is_shop()) return 'shop';
            if (is_product_category()) return 'product_category';
            if (is_product_tag()) return 'product_tag';
            if (is_product()) return 'product';
            if (is_cart()) return 'cart';
            if (is_checkout()) return 'checkout';
            if (is_order_received_page()) return 'order_received';
            if (is_account_page()) return 'my_account';
        }
        if (is_front_page()) return 'home';
        if (is_home()) return 'blog';
        if (is_single()) return 'post';
        if (is_page()) return 'page';
        if (is_category()) return 'category';
        if (is_tag()) return 'tag';
        if (is_author()) return 'author';
        if (is_archive()) return 'archive';
        if (is_search()) return 'search';
        if (is_404()) return '404';
        return 'other';
    }

    private function get_content_category() {
        global $post;
        if (!is_singular() || !$post) return '';

        if ($post->post_type === 'product') {
            $terms = wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'names']);
        } else {
            $terms = wp_get_post_terms($post->ID, 'category', ['fields' => 'names']);
        }
        return !empty($terms) && !is_wp_error($terms) ? $terms[0] : '';
    }

    private function get_content_tags() {
        global $post;
        if (!is_singular() || !$post) return '';

        if ($post->post_type === 'product') {
            $terms = wp_get_post_terms($post->ID, 'product_tag', ['fields' => 'names']);
        } else {
            $terms = wp_get_post_terms($post->ID, 'post_tag', ['fields' => 'names']);
        }
        return !empty($terms) && !is_wp_error($terms) ? implode(', ', $terms) : '';
    }

    /**
     * Inject engagement & interaction tracking scripts
     * All fire GA4-schema events into dataLayer
     */
    public function inject_tracking_scripts() {
        $config = $this->get_js_config();
        ?>
        <script>
        (function() {
            'use strict';
            window.dataLayer = window.dataLayer || [];
            var cfg = <?php echo wp_json_encode($config); ?>;

            // ========== SCROLL DEPTH (GA4: scroll) ==========
            if (cfg.scroll) {
                var thresholds = [10, 25, 50, 75, 90, 100];
                var fired = {};
                window.addEventListener('scroll', function() {
                    var pct = Math.round((window.scrollY / (document.documentElement.scrollHeight - window.innerHeight)) * 100);
                    if (isNaN(pct)) return;
                    thresholds.forEach(function(t) {
                        if (pct >= t && !fired[t]) {
                            fired[t] = true;
                            window.dataLayer.push({
                                'event': 'scroll',
                                'percent_scrolled': t,
                                'page_location': window.location.href,
                                'page_path': window.location.pathname,
                                'page_title': document.title
                            });
                        }
                    });
                });
            }

            // ========== CLICK EVENTS (GA4: select_content / outbound_click) ==========
            if (cfg.click) {
                document.addEventListener('click', function(e) {
                    var el = e.target.closest('a, button, [data-track-click], input[type="submit"]');
                    if (!el) return;

                    var href = el.href || '';
                    var text = (el.textContent || el.value || '').trim().substring(0, 100);
                    var isOutbound = href && el.hostname && el.hostname !== window.location.hostname;
                    var isTel = href.indexOf('tel:') === 0;
                    var isMail = href.indexOf('mailto:') === 0;
                    var isDownload = href.match(/\.(pdf|docx?|xlsx?|pptx?|zip|rar|csv|txt)(\?|$)/i);

                    var eventData = {
                        'event': 'select_content',
                        'content_type': el.tagName.toLowerCase(),
                        'link_text': text,
                        'link_url': href,
                        'link_id': el.id || '',
                        'link_classes': el.className || '',
                        'page_location': window.location.href,
                        'outbound': isOutbound || false
                    };

                    // GA4 outbound click
                    if (isOutbound) {
                        eventData.event = 'click';
                        eventData.link_domain = el.hostname;
                        eventData.outbound = true;
                    }

                    // Phone click
                    if (isTel) {
                        eventData.event = 'generate_lead';
                        eventData.lead_source = 'phone_click';
                        eventData.phone_number = href.replace('tel:', '').replace(/\s/g, '');
                        eventData.currency = cfg.currency;
                        eventData.value = 0;
                    }

                    // Email click
                    if (isMail) {
                        eventData.event = 'generate_lead';
                        eventData.lead_source = 'email_click';
                        eventData.value = 0;
                        eventData.currency = cfg.currency;
                    }

                    // File download (GA4: file_download)
                    if (isDownload) {
                        eventData.event = 'file_download';
                        eventData.file_name = href.split('/').pop().split('?')[0];
                        eventData.file_extension = href.match(/\.(\w+)(\?|$)/)[1];
                        eventData.link_url = href;
                    }

                    // CTA detection
                    if (el.classList.contains('cta') || el.classList.contains('btn-primary') ||
                        el.classList.contains('wp-block-button__link') || el.dataset.trackClick === 'cta') {
                        eventData.event = 'select_promotion';
                        eventData.promotion_name = text;
                        eventData.creative_slot = el.closest('section,header,footer,.hero')
                            ? el.closest('section,header,footer,.hero').className.substring(0, 50) : '';
                    }

                    window.dataLayer.push(eventData);
                });
            }

            // ========== FORM SUBMIT (GA4: generate_lead) ==========
            if (cfg.form) {
                document.addEventListener('submit', function(e) {
                    var form = e.target;
                    var formId = form.id || form.getAttribute('name') || '';
                    var formAction = form.action || '';
                    var formMethod = form.method || 'post';

                    // Detect form type
                    var formType = 'form_submit';
                    var hasEmail = form.querySelector('input[type="email"]');
                    var hasPhone = form.querySelector('input[type="tel"]');
                    var isSearch = form.querySelector('input[type="search"]') || form.role === 'search' || formId.match(/search/i);
                    var isNewsletter = formId.match(/newsletter|subscribe|mailchimp|mc4wp/i) ||
                                       form.className.match(/newsletter|subscribe/i);

                    var eventData = {
                        'event': 'generate_lead',
                        'form_id': formId,
                        'form_name': form.getAttribute('name') || formId,
                        'form_classes': form.className.substring(0, 100),
                        'form_action': formAction,
                        'form_method': formMethod,
                        'form_destination': formAction,
                        'form_has_email': !!hasEmail,
                        'form_has_phone': !!hasPhone,
                        'form_type': 'contact',
                        'currency': cfg.currency,
                        'value': 0,
                        'page_location': window.location.href,
                        'page_path': window.location.pathname
                    };

                    if (isSearch) {
                        var searchInput = form.querySelector('input[type="search"], input[name="s"]');
                        eventData.event = 'search';
                        eventData.search_term = searchInput ? searchInput.value : '';
                        delete eventData.currency;
                        delete eventData.value;
                    } else if (isNewsletter) {
                        eventData.event = 'sign_up';
                        eventData.method = 'newsletter';
                        eventData.form_type = 'newsletter';
                    } else if (hasEmail || hasPhone) {
                        eventData.form_type = 'lead';
                    }

                    window.dataLayer.push(eventData);
                });
            }

            // ========== VIDEO TRACKING (GA4: video_start, video_progress, video_complete) ==========
            if (cfg.click) {
                var videos = document.querySelectorAll('video');
                videos.forEach(function(video) {
                    var videoTitle = video.getAttribute('title') || video.src || 'untitled';
                    var progressFired = {};

                    video.addEventListener('play', function() {
                        window.dataLayer.push({
                            'event': 'video_start',
                            'video_title': videoTitle,
                            'video_url': video.currentSrc || video.src,
                            'video_provider': 'html5',
                            'video_duration': Math.round(video.duration),
                            'visible': true
                        });
                    }, {once: true});

                    video.addEventListener('timeupdate', function() {
                        if (!video.duration) return;
                        var pct = Math.round((video.currentTime / video.duration) * 100);
                        [25, 50, 75].forEach(function(t) {
                            if (pct >= t && !progressFired[t]) {
                                progressFired[t] = true;
                                window.dataLayer.push({
                                    'event': 'video_progress',
                                    'video_title': videoTitle,
                                    'video_percent': t,
                                    'video_current_time': Math.round(video.currentTime),
                                    'video_duration': Math.round(video.duration),
                                    'video_provider': 'html5',
                                    'visible': true
                                });
                            }
                        });
                    });

                    video.addEventListener('ended', function() {
                        window.dataLayer.push({
                            'event': 'video_complete',
                            'video_title': videoTitle,
                            'video_url': video.currentSrc || video.src,
                            'video_duration': Math.round(video.duration),
                            'video_provider': 'html5',
                            'visible': true
                        });
                    });
                });
            }

            // ========== USER ENGAGEMENT (GA4: user_engagement) ==========
            (function() {
                var startTime = Date.now();
                var engaged = false;
                var engagementTime = 0;

                function markEngaged() {
                    if (!engaged) {
                        engaged = true;
                        engagementTime = Math.round((Date.now() - startTime) / 1000);
                        window.dataLayer.push({
                            'event': 'user_engagement',
                            'engagement_time_msec': engagementTime * 1000,
                            'page_location': window.location.href,
                            'page_title': document.title
                        });
                    }
                }

                // GA4 considers 10s+ or scroll/click as engaged
                setTimeout(markEngaged, 10000);
                document.addEventListener('click', markEngaged, {once: true});
                document.addEventListener('scroll', function() {
                    if (window.scrollY > 300) markEngaged();
                }, {once: true});

                // Track time on page on exit
                window.addEventListener('beforeunload', function() {
                    var timeOnPage = Math.round((Date.now() - startTime) / 1000);
                    if (navigator.sendBeacon) {
                        window.dataLayer.push({
                            'event': 'page_unload',
                            'engagement_time_msec': timeOnPage * 1000,
                            'page_location': window.location.href
                        });
                    }
                });
            })();

            // ========== 404 TRACKING ==========
            if (cfg.page_type === '404') {
                window.dataLayer.push({
                    'event': 'page_not_found',
                    'page_location': window.location.href,
                    'page_path': window.location.pathname,
                    'page_referrer': document.referrer,
                    'page_title': document.title
                });
            }

        })();
        </script>
        <?php
    }

    private function get_js_config() {
        return [
            'scroll' => $this->settings->is_enabled('track_scroll_depth'),
            'click' => $this->settings->is_enabled('track_click_events'),
            'form' => $this->settings->is_enabled('track_form_submit'),
            'page_type' => $this->get_page_type(),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
        ];
    }
}
