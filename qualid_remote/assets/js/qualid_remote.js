/* =============================================================================
   Quali-D Connect — Admin Page JavaScript
   jQuery is available from FreePBX's admin panel
   ============================================================================= */

var QualidRemote = (function ($) {
    'use strict';

    var BASE_URL = window.location.pathname + '?display=qualid_remote&qual_ajax=1';

    // -------------------------------------------------------------------------
    // UI helpers
    // -------------------------------------------------------------------------

    function showAlert(container, type, message) {
        var icons = { error: 'fa-exclamation-circle', success: 'fa-check-circle', info: 'fa-info-circle' };
        var $el = $(
            '<div class="qualid-alert qualid-alert-' + type + '">' +
            '<i class="fa ' + (icons[type] || 'fa-info-circle') + '"></i>' +
            '<span>' + message + '</span>' +
            '</div>'
        );
        $(container).empty().append($el);
    }

    function clearAlert(container) {
        $(container).empty();
    }

    function setButtonLoading($btn, text) {
        $btn.data('orig-html', $btn.html());
        $btn.html('<span class="qualid-spinner"></span> ' + text).prop('disabled', true);
    }

    function resetButton($btn) {
        $btn.html($btn.data('orig-html') || $btn.text()).prop('disabled', false);
    }

    function copyToClipboard(text, $btn) {
        navigator.clipboard.writeText(text).then(function () {
            var orig = $btn.html();
            $btn.html('<i class="fa fa-check"></i> Copied!');
            setTimeout(function () { $btn.html(orig); }, 1800);
        });
    }

    // -------------------------------------------------------------------------
    // Step 1: Login with phone + password
    // -------------------------------------------------------------------------

    function handleConnect() {
        var $btn     = $('#qualid-connect-btn');
        var phone    = $('#qualid-phone').val().trim();
        var password = $('#qualid-password').val().trim();

        clearAlert('#qualid-connect-alert');

        if (!phone) {
            showAlert('#qualid-connect-alert', 'error', 'Please enter your phone number.');
            return;
        }
        if (!password) {
            showAlert('#qualid-connect-alert', 'error', 'Please enter your password.');
            return;
        }

        setButtonLoading($btn, 'Logging in\u2026');

        $.ajax({
            url:      BASE_URL,
            method:   'POST',
            data: {
                ajax_action: 'login',
                phone:       phone,
                password:    password,
            },
            dataType: 'json',
        }).done(function (res) {
            if (!res.success) {
                showAlert('#qualid-connect-alert', 'error', res.error || 'Login failed. Check your credentials.');
                resetButton($btn);
                return;
            }

            if (res.totp_required) {
                // Switch to TOTP step
                $('#qualid-temp-token').val(res.temp_token || '');
                $('#qualid-step-login').hide();
                $('#qualid-step-totp').show();
                $('#qualid-totp-code').val('').focus();
                resetButton($btn);
                return;
            }

            // No TOTP — trunk provisioned, reload
            showAlert('#qualid-connect-alert', 'success', 'Connected! Configuring Asterisk\u2026');
            setTimeout(function () { window.location.reload(); }, 1200);

        }).fail(function () {
            showAlert('#qualid-connect-alert', 'error', 'Network error \u2014 could not reach the QUALI-D API.');
            resetButton($btn);
        });
    }

    // -------------------------------------------------------------------------
    // Step 2: TOTP verification
    // -------------------------------------------------------------------------

    function handleVerifyTotp() {
        var $btn       = $('#qualid-totp-verify-btn');
        var temp_token = $('#qualid-temp-token').val();
        var code       = $('#qualid-totp-code').val().trim();

        clearAlert('#qualid-totp-alert');

        if (!code || code.length < 6) {
            showAlert('#qualid-totp-alert', 'error', 'Enter the 6-digit code from your authenticator app.');
            return;
        }

        setButtonLoading($btn, 'Verifying\u2026');

        $.ajax({
            url:    BASE_URL,
            method: 'POST',
            data: {
                ajax_action: 'verify_totp',
                temp_token:  temp_token,
                code:        code,
            },
            dataType: 'json',
        }).done(function (res) {
            if (!res.success) {
                showAlert('#qualid-totp-alert', 'error', res.error || 'Invalid code. Please try again.');
                resetButton($btn);
                return;
            }
            showAlert('#qualid-totp-alert', 'success', 'Verified! Configuring Asterisk\u2026');
            setTimeout(function () { window.location.reload(); }, 1200);
        }).fail(function () {
            showAlert('#qualid-totp-alert', 'error', 'Network error.');
            resetButton($btn);
        });
    }

    // -------------------------------------------------------------------------
    // Disconnect
    // -------------------------------------------------------------------------

    function handleDisconnect() {
        if (!confirm('This will remove the QUALI-D SIP trunk and dialplan from Asterisk. Are you sure?')) return;
        var $btn = $('#qualid-disconnect-btn');
        setButtonLoading($btn, 'Disconnecting\u2026');

        $.ajax({
            url:    BASE_URL,
            method: 'POST',
            data:   { ajax_action: 'disconnect' },
            dataType: 'json',
        }).done(function (res) {
            if (res.success) {
                window.location.reload();
            } else {
                alert('Error: ' + (res.error || 'Unknown error'));
                resetButton($btn);
            }
        }).fail(function () {
            alert('Network error.');
            resetButton($btn);
        });
    }

    // -------------------------------------------------------------------------
    // Test connection
    // -------------------------------------------------------------------------

    function testConnection() {
        var $btn = $('#qualid-test-btn');
        setButtonLoading($btn, 'Testing\u2026');
        clearAlert('#qualid-test-alert');

        $.ajax({
            url:    BASE_URL,
            method: 'POST',
            data:   { ajax_action: 'test_connection' },
            dataType: 'json',
        }).done(function (res) {
            if (res.success) {
                showAlert('#qualid-test-alert', 'success', '\u2713 Connection OK \u2014 QUALI-D API reachable.');
            } else {
                showAlert('#qualid-test-alert', 'error', 'Test failed: ' + (res.error || 'Unknown'));
            }
            resetButton($btn);
        }).fail(function () {
            showAlert('#qualid-test-alert', 'error', 'Could not reach QUALI-D API.');
            resetButton($btn);
        });
    }

    // -------------------------------------------------------------------------
    // Password visibility toggle
    // -------------------------------------------------------------------------

    function togglePasswordVisibility() {
        var $input = $('#qualid-password');
        var $icon  = $('#qualid-toggle-pass i');
        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $icon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            $input.attr('type', 'password');
            $icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    function escHtml(str) {
        return String(str)
            .replace(/&/g,  '&amp;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;')
            .replace(/"/g,  '&quot;')
            .replace(/'/g,  '&#39;');
    }

    // -------------------------------------------------------------------------
    // IVR Status Card
    // -------------------------------------------------------------------------

    function loadIvrStatus() {
        var $body = $('#qualid-ivr-status-body');
        if (!$body.length) return;

        $.ajax({
            url:      BASE_URL,
            method:   'GET',
            data:     { ajax_action: 'get_ivr_status' },
            dataType: 'json',
        }).done(function (res) {
            renderIvrStatus(res);
        }).fail(function () {
            $body.html('<div class="qualid-alert qualid-alert-error" style="margin:12px;">' +
                '<i class="fa fa-exclamation-circle"></i> Could not check IVR status.</div>');
        });
    }

    function renderIvrStatus(s) {
        var $body = $('#qualid-ivr-status-body');
        if (!$body.length) return;

        function row(label, ok, detail) {
            var icon  = ok ? '<i class="fa fa-check-circle" style="color:#22c55e;"></i>'
                           : '<i class="fa fa-times-circle" style="color:#ef4444;"></i>';
            var dspan = detail ? '<span style="color:#8a9db5;font-size:11px;margin-left:6px;">' + detail + '</span>' : '';
            return '<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f0f4fa;">' +
                   icon + '<span style="font-size:13px;color:#3a4a60;">' + label + '</span>' + dspan + '</div>';
        }

        var syncNote = s.last_sync
            ? 'Last sync: ' + s.last_sync
            : 'Never synced';

        var html = row('QUALI-D API reachable',   !!s.api_reachable,  '');
        html    += row('AGI script deployed',      !!s.agi_deployed,   '');
        html    += row('AGI secret registered',    !!s.agi_secret_set, '');
        html    += '<div style="padding:8px 0;font-size:11px;color:#8a9db5;">' + escHtml(syncNote) + '</div>';

        $body.html(html);
    }

    // -------------------------------------------------------------------------
    // Auto-sync (page load + every 5 min)
    // -------------------------------------------------------------------------

    function autoSync() {
        // Sync queues from cloud → write queues_qualid.conf + reload app_queue.so
        $.ajax({
            url:      BASE_URL,
            method:   'POST',
            data:     { ajax_action: 'sync_queues' },
            dataType: 'json',
        });
        // Push new CDR records to cloud (incremental) + sends heartbeat
        $.ajax({
            url:      BASE_URL,
            method:   'POST',
            data:     { ajax_action: 'sync_cdr' },
            dataType: 'json',
        });
    }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // GitHub self-update check
    // -------------------------------------------------------------------------

    function checkForUpdate(btn) {
        var $icon = btn ? $(btn).find('i') : null;
        if ($icon) {
            $icon.addClass('fa-spin');
            $(btn).prop('disabled', true);
        }

        $.ajax({
            url:      BASE_URL,
            method:   'GET',
            data:     { ajax_action: 'check_update' },
            dataType: 'json',
        }).done(function (res) {
            if (res.success && res.update_available) {
                $('#qualid-update-label').text('v' + res.latest_version + ' available');
                $('#qualid-update-badge').show();
            } else if (btn && res.success && !res.update_available) {
                // Manual check — briefly show "Up to date" feedback on the icon
                if ($icon) {
                    $icon.removeClass('fa-refresh fa-spin').addClass('fa-check');
                    setTimeout(function () {
                        $icon.removeClass('fa-check').addClass('fa-refresh');
                    }, 2000);
                }
            } else if (btn && !res.success) {
                if ($icon) { $icon.removeClass('fa-spin'); }
                alert('Update check failed: ' + (res.error || 'Could not reach GitHub'));
            }
        }).fail(function () {
            if (btn) { alert('Update check failed \u2014 network error.'); }
        }).always(function () {
            if ($icon) { $icon.removeClass('fa-spin'); }
            if (btn) { $(btn).prop('disabled', false); }
        });
    }

    function init() {
        // Login
        $('#qualid-connect-btn').on('click', handleConnect);
        $('#qualid-phone').on('keydown',    function (e) { if (e.key === 'Enter') handleConnect(); });
        $('#qualid-password').on('keydown', function (e) { if (e.key === 'Enter') handleConnect(); });

        // TOTP
        $('#qualid-totp-verify-btn').on('click', handleVerifyTotp);
        $('#qualid-totp-code').on('keydown', function (e) { if (e.key === 'Enter') handleVerifyTotp(); });
        $('#qualid-totp-back-btn').on('click', function () {
            $('#qualid-step-totp').hide();
            $('#qualid-step-login').show();
            clearAlert('#qualid-totp-alert');
        });

        // Disconnect
        $('#qualid-disconnect-btn').on('click', handleDisconnect);

        // Password visibility
        $('#qualid-toggle-pass').on('click', togglePasswordVisibility);

        // Test connection
        $('#qualid-test-btn').on('click', testConnection);

        // IVR status refresh
        $('#qualid-ivr-refresh-btn').on('click', loadIvrStatus);

        // Copy SIP domain
        $('#qualid-copy-domain').on('click', function () {
            copyToClipboard($(this).data('value'), $(this));
        });

        // Auto-sync CDR + IVR status on page load, then every 5 minutes
        if ($('#qualid-ivr-status-card').length) {
            autoSync();
            loadIvrStatus();
            setInterval(function () {
                autoSync();
                loadIvrStatus();
            }, 5 * 60 * 1000);
        }

        // Check GitHub for a newer release (silently, on page load)
        if ($('#qualid-update-badge').length) {
            checkForUpdate();
        }

        // Update button click
        $(document).on('click', '#qualid-do-update-btn', function () {
            var $btn = $(this);
            if (!confirm('Update Quali-D Connect to the latest version from GitHub?\n\nThe page will reload after the update.')) return;
            $btn.prop('disabled', true);
            $('#qualid-update-label').text('Updating...');
            $.ajax({
                url:      BASE_URL,
                method:   'POST',
                data:     { ajax_action: 'do_update' },
                dataType: 'json',
            }).done(function (res) {
                if (res.success) {
                    $('#qualid-update-label').text('Done! Reloading...');
                    setTimeout(function () { location.reload(); }, 1500);
                } else {
                    alert('Update failed: ' + (res.error || 'Unknown error'));
                    $btn.prop('disabled', false);
                    $('#qualid-update-label').text('Update available');
                }
            }).fail(function () {
                alert('Update request failed. Check server logs.');
                $btn.prop('disabled', false);
                $('#qualid-update-label').text('Update available');
            });
        });
    }

    return {
        init:           init,
        checkForUpdate: checkForUpdate,
    };

}(jQuery));

$(document).ready(function () {
    QualidRemote.init();
});
