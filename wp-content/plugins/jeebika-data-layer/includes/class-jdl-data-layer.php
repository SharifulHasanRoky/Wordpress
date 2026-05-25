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

    public function push_page_data() {
        if (!$this->settings->is_enabled('track_page_view')) return;

        $page_data = $this->get_page_data();
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push(<?php echo wp_json_encode($page_data); ?>);
        </script>
        <?php
    }

    private function get_page_data() {
        global $post;

        $data = [
            'event' => 'page_view',
            'page_type' => $this->get_page_type(),
            'page_title' => wp_title('', false),
            'page_url' => home_url(add_query_arg(null, null)),
            'page_path' => wp_parse_url(home_url(add_query_arg(null, null)), PHP_URL_PATH),
            'site_name' => get_bloginfo('name'),
            'site_language' => get_locale(),
            'timestamp' => current_time('c'),
        ];

        if (is_singular() && $post) {
            $data['content_id'] = $post->ID;
            $data['content_type'] = $post->post_type;
            $data['content_author'] = get_the_author_meta('display_name', $post->post_author);
            $data['content_date'] = get_the_date('Y-m-d', $post);

            $categories = get_the_category($post->ID);
            if ($categories) {
                $data['content_category'] = $categories[0]->name;
            }
        }

        if (is_search()) {
            $data['search_term'] = get_search_query();
            $data['search_results_count'] = $GLOBALS['wp_query']->found_posts;
        }

        if (is_404()) {
            $data['page_type'] = '404';
            $data['event'] = 'page_not_found';
        }

        return $data;
    }

    private function get_page_type() {
        if (is_front_page()) return 'home';
        if (is_shop()) return 'shop';
        if (is_product_category()) return 'product_category';
        if (is_product()) return 'product';
        if (is_cart()) return 'cart';
        if (is_checkout()) return 'checkout';
        if (is_account_page()) return 'account';
        if (is_category()) return 'category';
        if (is_tag()) return 'tag';
        if (is_single()) return 'post';
        if (is_page()) return 'page';
        if (is_archive()) return 'archive';
        if (is_search()) return 'search';
        if (is_404()) return '404';
        return 'other';
    }

    public function inject_tracking_scripts() {
        ?>
        <script>
        (function() {
            'use strict';
            window.dataLayer = window.dataLayer || [];
            var jdlConfig = <?php echo wp_json_encode($this->get_js_config()); ?>;

            // Scroll Depth Tracking
            if (jdlConfig.track_scroll_depth) {
                var scrollThresholds = [25, 50, 75, 90, 100];
                var scrollFired = {};
                window.addEventListener('scroll', function() {
                    var scrollPercent = Math.round((window.scrollY / (document.documentElement.scrollHeight - window.innerHeight)) * 100);
                    scrollThresholds.forEach(function(threshold) {
                        if (scrollPercent >= threshold && !scrollFired[threshold]) {
                            scrollFired[threshold] = true;
                            window.dataLayer.push({
                                'event': 'scroll_depth',
                                'scroll_percentage': threshold,
                                'page_path': window.location.pathname
                            });
                        }
                    });
                });
            }

            // Click Tracking
            if (jdlConfig.track_click_events) {
                document.addEventListener('click', function(e) {
                    var target = e.target.closest('a, button, [data-track-click]');
                    if (!target) return;

                    var clickData = {
                        'event': 'click',
                        'click_element': target.tagName.toLowerCase(),
                        'click_text': (target.textContent || '').trim().substring(0, 100),
                        'click_url': target.href || '',
                        'click_id': target.id || '',
                        'click_classes': target.className || ''
                    };

                    if (target.dataset.trackClick) {
                        clickData.click_category = target.dataset.trackClick;
                    }

                    // Outbound link detection
                    if (target.href && target.hostname !== window.location.hostname) {
                        clickData.event = 'outbound_click';
                        clickData.outbound_url = target.href;
                    }

                    // Phone/Email clicks
                    if (target.href) {
                        if (target.href.indexOf('tel:') === 0) {
                            clickData.event = 'phone_click';
                            clickData.phone_number = target.href.replace('tel:', '');
                        }
                        if (target.href.indexOf('mailto:') === 0) {
                            clickData.event = 'email_click';
                            clickData.email_address = target.href.replace('mailto:', '');
                        }
                    }

                    // CTA button detection
                    if (target.classList.contains('cta') || target.classList.contains('btn-primary') || target.dataset.trackClick === 'cta') {
                        clickData.event = 'cta_click';
                    }

                    window.dataLayer.push(clickData);
                });
            }

            // Form Submit Tracking
            if (jdlConfig.track_form_submit) {
                document.addEventListener('submit', function(e) {
                    var form = e.target;
                    window.dataLayer.push({
                        'event': 'form_submit',
                        'form_id': form.id || '',
                        'form_name': form.getAttribute('name') || '',
                        'form_action': form.action || '',
                        'form_classes': form.className || '',
                        'page_path': window.location.pathname
                    });
                });
            }

            // Video Tracking (YouTube embeds)
            if (jdlConfig.track_click_events) {
                var videos = document.querySelectorAll('iframe[src*="youtube"], iframe[src*="vimeo"]');
                videos.forEach(function(video) {
                    window.dataLayer.push({
                        'event': 'video_present',
                        'video_url': video.src,
                        'video_title': video.title || '',
                        'page_path': window.location.pathname
                    });
                });
            }

            // Session & Engagement Tracking
            (function() {
                var startTime = Date.now();
                var engaged = false;

                function markEngaged() {
                    if (!engaged) {
                        engaged = true;
                        window.dataLayer.push({
                            'event': 'user_engaged',
                            'engagement_time': Math.round((Date.now() - startTime) / 1000)
                        });
                    }
                }

                setTimeout(markEngaged, 10000);
                document.addEventListener('click', markEngaged, {once: true});

                window.addEventListener('beforeunload', function() {
                    var timeOnPage = Math.round((Date.now() - startTime) / 1000);
                    window.dataLayer.push({
                        'event': 'page_exit',
                        'time_on_page': timeOnPage,
                        'page_path': window.location.pathname
                    });
                });
            })();

        })();
        </script>
        <?php
    }

    private function get_js_config() {
        return [
            'track_scroll_depth' => $this->settings->is_enabled('track_scroll_depth'),
            'track_click_events' => $this->settings->is_enabled('track_click_events'),
            'track_form_submit' => $this->settings->is_enabled('track_form_submit'),
        ];
    }
}
