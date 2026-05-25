<?php
if (!defined('ABSPATH')) exit;

class JDL_Industry {

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
        add_action('wp_footer', [$this, 'inject_industry_tracking'], 98);
    }

    public function inject_industry_tracking() {
        $scripts = [];

        if ($this->settings->is_enabled('track_lead_generation')) {
            $scripts[] = $this->get_lead_gen_script();
        }
        if ($this->settings->is_enabled('track_saas_events')) {
            $scripts[] = $this->get_saas_script();
        }
        if ($this->settings->is_enabled('track_education_events')) {
            $scripts[] = $this->get_education_script();
        }
        if ($this->settings->is_enabled('track_real_estate_events')) {
            $scripts[] = $this->get_real_estate_script();
        }
        if ($this->settings->is_enabled('track_healthcare_events')) {
            $scripts[] = $this->get_healthcare_script();
        }
        if ($this->settings->is_enabled('track_travel_events')) {
            $scripts[] = $this->get_travel_script();
        }
        if ($this->settings->is_enabled('track_finance_events')) {
            $scripts[] = $this->get_finance_script();
        }
        if ($this->settings->is_enabled('track_media_events')) {
            $scripts[] = $this->get_media_script();
        }

        if (empty($scripts)) return;

        echo '<script>' . "\n";
        echo '(function() {' . "\n";
        echo '    "use strict";' . "\n";
        echo '    window.dataLayer = window.dataLayer || [];' . "\n\n";
        foreach ($scripts as $script) {
            echo $script . "\n\n";
        }
        echo '})();' . "\n";
        echo '</script>' . "\n";
    }

    private function get_lead_gen_script() {
        return <<<'JS'
    // Lead Generation Tracking
    // Track Contact Form 7, Gravity Forms, WPForms, Elementor Forms
    document.addEventListener('wpcf7mailsent', function(e) {
        window.dataLayer.push({
            'event': 'generate_lead',
            'lead_source': 'contact_form_7',
            'form_id': e.detail.contactFormId,
            'form_name': e.detail.contactFormId,
            'lead_type': 'form_submission',
            'page_path': window.location.pathname
        });
    });

    // Gravity Forms
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('gform_confirmation_loaded', function(e, formId) {
            window.dataLayer.push({
                'event': 'generate_lead',
                'lead_source': 'gravity_forms',
                'form_id': formId,
                'lead_type': 'form_submission',
                'page_path': window.location.pathname
            });
        });
    }

    // WPForms
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('wpformsAjaxSubmitSuccess', function(e, response) {
            window.dataLayer.push({
                'event': 'generate_lead',
                'lead_source': 'wpforms',
                'form_id': response.data ? response.data.form_id : '',
                'lead_type': 'form_submission',
                'page_path': window.location.pathname
            });
        });
    }

    // Newsletter Signup Detection
    document.querySelectorAll('form[class*="newsletter"], form[class*="subscribe"], form[class*="signup"], form[id*="newsletter"]').forEach(function(form) {
        form.addEventListener('submit', function() {
            window.dataLayer.push({
                'event': 'newsletter_signup',
                'lead_source': 'newsletter_form',
                'lead_type': 'email_signup',
                'page_path': window.location.pathname
            });
        });
    });

    // Quote/Consultation Request Detection
    document.querySelectorAll('form[class*="quote"], form[class*="consultation"], a[href*="book"], a[href*="schedule"], a[href*="appointment"]').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 'request_quote',
                'lead_source': 'cta_click',
                'lead_type': 'consultation_request',
                'click_text': (el.textContent || '').trim().substring(0, 50),
                'page_path': window.location.pathname
            });
        });
    });

    // WhatsApp/Chat Click
    document.querySelectorAll('a[href*="wa.me"], a[href*="whatsapp"], .whatsapp-btn, [class*="chat-btn"]').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 'chat_click',
                'lead_source': 'whatsapp',
                'lead_type': 'chat_initiation',
                'page_path': window.location.pathname
            });
        });
    });
JS;
    }

    private function get_saas_script() {
        return <<<'JS'
    // SaaS Industry Tracking
    // Free Trial / Demo Request
    document.querySelectorAll('a[href*="trial"], a[href*="demo"], button[class*="trial"], button[class*="demo"], .free-trial-btn, .demo-btn').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 'saas_trial_start',
                'saas_action': 'trial_request',
                'click_text': (el.textContent || '').trim().substring(0, 50),
                'page_path': window.location.pathname
            });
        });
    });

    // Pricing Page View
    if (window.location.pathname.match(/pricing|plans|packages/i)) {
        window.dataLayer.push({
            'event': 'saas_pricing_view',
            'saas_action': 'pricing_page_view',
            'page_path': window.location.pathname
        });
    }

    // Feature Comparison Interaction
    document.querySelectorAll('.pricing-table, .comparison-table, [class*="pricing"]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            var target = e.target.closest('button, a, .plan-select');
            if (target) {
                window.dataLayer.push({
                    'event': 'saas_plan_select',
                    'saas_action': 'plan_selected',
                    'plan_name': (target.textContent || '').trim().substring(0, 50),
                    'page_path': window.location.pathname
                });
            }
        });
    });

    // Documentation/Knowledge Base views
    if (window.location.pathname.match(/docs|documentation|help|knowledge|support/i)) {
        window.dataLayer.push({
            'event': 'saas_docs_view',
            'saas_action': 'documentation_view',
            'doc_path': window.location.pathname
        });
    }
JS;
    }

    private function get_education_script() {
        return <<<'JS'
    // Education Industry Tracking
    // Course Enrollment
    document.querySelectorAll('.enroll-btn, a[href*="enroll"], button[class*="enroll"], .course-signup, a[href*="course"]').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 'edu_enrollment',
                'edu_action': 'course_enrollment',
                'course_name': document.querySelector('h1, .course-title') ? document.querySelector('h1, .course-title').textContent.trim() : '',
                'page_path': window.location.pathname
            });
        });
    });

    // Course/Program Page Views
    if (window.location.pathname.match(/course|program|class|workshop|training|lesson/i)) {
        window.dataLayer.push({
            'event': 'edu_content_view',
            'edu_action': 'course_page_view',
            'content_title': document.title,
            'page_path': window.location.pathname
        });
    }

    // Download Syllabus/Brochure
    document.querySelectorAll('a[href*="syllabus"], a[href*="brochure"], a[href*=".pdf"], a[download]').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 'edu_download',
                'edu_action': 'resource_download',
                'resource_name': el.textContent.trim().substring(0, 50) || el.href,
                'page_path': window.location.pathname
            });
        });
    });

    // Application Form
    document.querySelectorAll('form[class*="application"], form[class*="admission"], form[id*="apply"]').forEach(function(form) {
        form.addEventListener('submit', function() {
            window.dataLayer.push({
                'event': 'edu_application',
                'edu_action': 'application_submit',
                'form_id': form.id || '',
                'page_path': window.location.pathname
            });
        });
    });
JS;
    }

    private function get_real_estate_script() {
        return <<<'JS'
    // Real Estate Industry Tracking
    // Property View
    if (window.location.pathname.match(/property|listing|apartment|house|villa|plot|flat/i)) {
        window.dataLayer.push({
            'event': 're_property_view',
            're_action': 'property_view',
            'property_title': document.title,
            'page_path': window.location.pathname
        });
    }

    // Schedule Visit / Book Tour
    document.querySelectorAll('a[href*="visit"], a[href*="tour"], button[class*="visit"], button[class*="tour"], .schedule-visit, .book-tour').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 're_schedule_visit',
                're_action': 'visit_scheduled',
                'click_text': (el.textContent || '').trim().substring(0, 50),
                'page_path': window.location.pathname
            });
        });
    });

    // Property Search/Filter
    document.querySelectorAll('form[class*="search"], form[class*="filter"], .property-search, .listing-filter').forEach(function(form) {
        form.addEventListener('submit', function() {
            window.dataLayer.push({
                'event': 're_property_search',
                're_action': 'property_search',
                'page_path': window.location.pathname
            });
        });
    });

    // Contact Agent
    document.querySelectorAll('.contact-agent, a[href*="agent"], button[class*="inquiry"]').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 're_contact_agent',
                're_action': 'agent_contact',
                'page_path': window.location.pathname
            });
        });
    });

    // Mortgage Calculator Interaction
    document.querySelectorAll('.mortgage-calculator, [class*="calculator"], form[class*="mortgage"]').forEach(function(el) {
        el.addEventListener('change', function() {
            window.dataLayer.push({
                'event': 're_calculator_use',
                're_action': 'mortgage_calculator',
                'page_path': window.location.pathname
            });
        }, {once: true});
    });
JS;
    }

    private function get_healthcare_script() {
        return <<<'JS'
    // Healthcare Industry Tracking
    // Appointment Booking
    document.querySelectorAll('a[href*="appointment"], a[href*="booking"], button[class*="appointment"], .book-appointment, .schedule-appointment').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 'health_appointment',
                'health_action': 'appointment_click',
                'click_text': (el.textContent || '').trim().substring(0, 50),
                'page_path': window.location.pathname
            });
        });
    });

    // Doctor/Service Pages
    if (window.location.pathname.match(/doctor|physician|specialist|service|treatment|department/i)) {
        window.dataLayer.push({
            'event': 'health_service_view',
            'health_action': 'service_page_view',
            'service_title': document.title,
            'page_path': window.location.pathname
        });
    }

    // Patient Portal Login
    document.querySelectorAll('a[href*="patient-portal"], a[href*="my-account"], .patient-login').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 'health_portal_click',
                'health_action': 'patient_portal_access',
                'page_path': window.location.pathname
            });
        });
    });

    // Emergency/Urgent Action
    document.querySelectorAll('a[href*="emergency"], .emergency-btn, a[href^="tel:"]').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 'health_emergency_click',
                'health_action': 'emergency_contact',
                'contact_type': el.href && el.href.indexOf('tel:') === 0 ? 'phone' : 'page',
                'page_path': window.location.pathname
            });
        });
    });
JS;
    }

    private function get_travel_script() {
        return <<<'JS'
    // Travel & Hospitality Industry Tracking
    // Booking/Reservation
    document.querySelectorAll('.book-now, a[href*="book"], a[href*="reserve"], button[class*="book"], form[class*="booking"]').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 'travel_booking_click',
                'travel_action': 'booking_initiated',
                'click_text': (el.textContent || '').trim().substring(0, 50),
                'page_path': window.location.pathname
            });
        });
    });

    // Destination/Package Page Views
    if (window.location.pathname.match(/destination|package|tour|trip|hotel|resort|flight/i)) {
        window.dataLayer.push({
            'event': 'travel_content_view',
            'travel_action': 'destination_view',
            'content_title': document.title,
            'page_path': window.location.pathname
        });
    }

    // Search Availability
    document.querySelectorAll('form[class*="search"], form[class*="availability"], .search-form, .availability-check').forEach(function(form) {
        form.addEventListener('submit', function() {
            var checkin = form.querySelector('[name*="checkin"], [name*="check_in"], [name*="date_from"]');
            var checkout = form.querySelector('[name*="checkout"], [name*="check_out"], [name*="date_to"]');
            window.dataLayer.push({
                'event': 'travel_search',
                'travel_action': 'availability_search',
                'check_in': checkin ? checkin.value : '',
                'check_out': checkout ? checkout.value : '',
                'page_path': window.location.pathname
            });
        });
    });

    // Review/Testimonial Interaction
    document.querySelectorAll('.review-section, .testimonials, [class*="review"]').forEach(function(el) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    window.dataLayer.push({
                        'event': 'travel_reviews_view',
                        'travel_action': 'reviews_section_viewed',
                        'page_path': window.location.pathname
                    });
                    observer.disconnect();
                }
            });
        });
        observer.observe(el);
    });
JS;
    }

    private function get_finance_script() {
        return <<<'JS'
    // Finance Industry Tracking
    // Application/Apply Now
    document.querySelectorAll('a[href*="apply"], button[class*="apply"], .apply-now, a[href*="loan"], a[href*="credit"]').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 'finance_application',
                'finance_action': 'application_click',
                'product_type': (el.textContent || '').trim().substring(0, 50),
                'page_path': window.location.pathname
            });
        });
    });

    // Product Pages (Loans, Cards, Insurance)
    if (window.location.pathname.match(/loan|credit|insurance|investment|savings|mortgage|account/i)) {
        window.dataLayer.push({
            'event': 'finance_product_view',
            'finance_action': 'product_page_view',
            'product_title': document.title,
            'page_path': window.location.pathname
        });
    }

    // Calculator Usage
    document.querySelectorAll('[class*="calculator"], form[class*="calc"], .emi-calculator, .roi-calculator').forEach(function(el) {
        el.addEventListener('change', function() {
            window.dataLayer.push({
                'event': 'finance_calculator',
                'finance_action': 'calculator_used',
                'calculator_type': el.className,
                'page_path': window.location.pathname
            });
        }, {once: true});
    });

    // Branch/ATM Locator
    document.querySelectorAll('a[href*="branch"], a[href*="atm"], a[href*="locator"], .find-branch').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 'finance_locator',
                'finance_action': 'branch_locator_click',
                'page_path': window.location.pathname
            });
        });
    });
JS;
    }

    private function get_media_script() {
        return <<<'JS'
    // Media & Content Industry Tracking
    // Article/Content Reading
    (function() {
        var articleBody = document.querySelector('article, .post-content, .entry-content, .article-body');
        if (articleBody) {
            var wordCount = articleBody.textContent.trim().split(/\s+/).length;
            var readTime = Math.ceil(wordCount / 200); // avg reading speed

            window.dataLayer.push({
                'event': 'media_article_view',
                'media_action': 'article_start',
                'article_title': document.title,
                'article_word_count': wordCount,
                'estimated_read_time': readTime,
                'page_path': window.location.pathname
            });

            // Track if user reaches end of article
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        window.dataLayer.push({
                            'event': 'media_article_complete',
                            'media_action': 'article_finished',
                            'article_title': document.title,
                            'page_path': window.location.pathname
                        });
                        observer.disconnect();
                    }
                });
            });

            var lastParagraph = articleBody.querySelector('p:last-of-type');
            if (lastParagraph) observer.observe(lastParagraph);
        }
    })();

    // Subscription/Paywall
    document.querySelectorAll('.subscribe-btn, a[href*="subscribe"], button[class*="subscribe"], .paywall-cta').forEach(function(el) {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': 'media_subscribe_click',
                'media_action': 'subscription_cta_click',
                'click_text': (el.textContent || '').trim().substring(0, 50),
                'page_path': window.location.pathname
            });
        });
    });

    // Share Buttons
    document.querySelectorAll('.share-btn, a[href*="facebook.com/share"], a[href*="twitter.com/intent"], a[href*="linkedin.com/share"], [class*="social-share"]').forEach(function(el) {
        el.addEventListener('click', function() {
            var platform = 'unknown';
            if (el.href) {
                if (el.href.indexOf('facebook') > -1) platform = 'facebook';
                else if (el.href.indexOf('twitter') > -1 || el.href.indexOf('x.com') > -1) platform = 'twitter';
                else if (el.href.indexOf('linkedin') > -1) platform = 'linkedin';
                else if (el.href.indexOf('whatsapp') > -1) platform = 'whatsapp';
            }
            window.dataLayer.push({
                'event': 'media_share',
                'media_action': 'content_shared',
                'share_platform': platform,
                'page_path': window.location.pathname
            });
        });
    });

    // Comment Interaction
    document.querySelectorAll('#commentform, .comment-form, form[class*="comment"]').forEach(function(form) {
        form.addEventListener('submit', function() {
            window.dataLayer.push({
                'event': 'media_comment',
                'media_action': 'comment_submitted',
                'page_path': window.location.pathname
            });
        });
    });
JS;
    }
}
