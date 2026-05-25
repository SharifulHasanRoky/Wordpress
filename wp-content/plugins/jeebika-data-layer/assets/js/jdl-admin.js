(function($) {
    'use strict';

    // Form Submit - AJAX Save
    $(document).on('submit', '#jdl-settings-form', function(e) {
        e.preventDefault();

        var $form = $(this);
        var formData = {};

        // Collect all inputs
        $form.find('input[type="text"], input[type="url"], input[type="password"]').each(function() {
            formData[$(this).attr('name')] = $(this).val();
        });

        $form.find('input[type="checkbox"]').each(function() {
            formData[$(this).attr('name')] = $(this).is(':checked') ? 1 : 0;
        });

        $.ajax({
            url: jdlAdmin.ajax_url,
            method: 'POST',
            data: {
                action: 'jdl_save_settings',
                nonce: jdlAdmin.nonce,
                settings: formData
            },
            success: function(response) {
                if (response.success) {
                    showToast('Settings saved successfully!', 'success');
                } else {
                    showToast('Error saving settings', 'error');
                }
            },
            error: function() {
                showToast('Network error. Please try again.', 'error');
            }
        });
    });

    // Enable All Button
    $(document).on('click', '.jdl-enable-all', function() {
        var group = $(this).data('group');
        $('[data-group="' + group + '"] .jdl-event-toggle').prop('checked', true);

        $.ajax({
            url: jdlAdmin.ajax_url,
            method: 'POST',
            data: {
                action: 'jdl_toggle_all',
                nonce: jdlAdmin.nonce,
                group: group,
                enable: 1
            },
            success: function() {
                showToast('All events enabled!', 'success');
            }
        });
    });

    // Disable All Button
    $(document).on('click', '.jdl-disable-all', function() {
        var group = $(this).data('group');
        $('[data-group="' + group + '"] .jdl-event-toggle').prop('checked', false);

        $.ajax({
            url: jdlAdmin.ajax_url,
            method: 'POST',
            data: {
                action: 'jdl_toggle_all',
                nonce: jdlAdmin.nonce,
                group: group,
                enable: 0
            },
            success: function() {
                showToast('All events disabled!', 'success');
            }
        });
    });

    // Toast notification
    function showToast(message, type) {
        var $toast = $('<div class="jdl-toast ' + (type === 'error' ? 'error' : '') + '">' + message + '</div>');
        $('body').append($toast);
        setTimeout(function() {
            $toast.fadeOut(300, function() { $(this).remove(); });
        }, 3000);
    }

})(jQuery);
