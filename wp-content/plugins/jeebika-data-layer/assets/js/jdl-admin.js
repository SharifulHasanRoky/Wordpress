(function ($) {
    'use strict';

    var JDLAdmin = {

        init: function () {
            this.bindSave();
            this.bindEnableAll();
            this.bindDisableAll();
        },

        bindSave: function () {
            $('#jdl-settings-form').on('submit', function (e) {
                e.preventDefault();
                JDLAdmin.save();
            });
        },

        bindEnableAll: function () {
            $(document).on('click', '.jdl-enable-all', function () {
                var $card = $(this).closest('.jdl-card');
                $card.find('input[type="checkbox"]').prop('checked', true);
            });
        },

        bindDisableAll: function () {
            $(document).on('click', '.jdl-disable-all', function () {
                var $card = $(this).closest('.jdl-card');
                $card.find('input[type="checkbox"]').prop('checked', false);
            });
        },

        save: function () {
            var $btn = $('.jdl-save-btn');
            $btn.prop('disabled', true).text('Saving...');

            var settings = {};

            // Collect all text fields
            $('#jdl-settings-form input[type="text"]').each(function () {
                var key = $(this).attr('data-key') || $(this).attr('name');
                if (key) {
                    settings[key] = $(this).val();
                }
            });

            // Collect all checkboxes
            $('#jdl-settings-form input[type="checkbox"]').each(function () {
                var key = $(this).attr('data-key') || $(this).attr('name');
                if (key) {
                    settings[key] = $(this).is(':checked') ? '1' : '0';
                }
            });

            $.ajax({
                url: jdl_admin.ajax,
                type: 'POST',
                data: {
                    action: 'jdl_save',
                    nonce: jdl_admin.nonce,
                    settings: settings
                },
                success: function (response) {
                    if (response.success) {
                        JDLAdmin.toast(response.data.message || 'Settings saved!', 'success');
                    } else {
                        JDLAdmin.toast(response.data || 'Error saving settings.', 'error');
                    }
                },
                error: function () {
                    JDLAdmin.toast('Network error. Please try again.', 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false).text('Save Settings');
                }
            });
        },

        toast: function (message, type) {
            var $toast = $('#jdl-toast');
            $toast.text(message)
                .removeClass('success error')
                .addClass(type)
                .addClass('show');

            setTimeout(function () {
                $toast.removeClass('show');
            }, 3000);
        }
    };

    $(document).ready(function () {
        JDLAdmin.init();
    });

})(jQuery);
