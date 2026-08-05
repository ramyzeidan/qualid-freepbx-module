/* =============================================================================
   QUALI-D Remote Agent — Admin Page JavaScript
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
    // Agents
    // -------------------------------------------------------------------------

    function loadAgents() {
        var $container = $('#qualid-agents-body');
        $container.html(
            '<tr><td colspan="4" class="text-center" style="padding:30px;color:#8a9db5;">' +
            '<span class="qualid-spinner dark"></span> Loading agents\u2026</td></tr>'
        );

        $.ajax({
            url:    BASE_URL,
            method: 'GET',
            data:   { ajax_action: 'get_agents' },
            dataType: 'json',
        }).done(function (res) {
            if (!res.success || !res.agents || res.agents.length === 0) {
                $container.html(
                    '<tr><td colspan="4">' +
                    '<div class="qualid-empty"><div class="empty-icon">\uD83D\uDC64</div>' +
                    '<p>No agents found in your QUALI-D account.</p></div>' +
                    '</td></tr>'
                );
                return;
            }
            renderAgents(res.agents);
        }).fail(function () {
            $container.html('<tr><td colspan="4"><div class="qualid-alert qualid-alert-error">' +
                '<i class="fa fa-exclamation-circle"></i> Failed to load agents.</div></td></tr>');
        });
    }

    function renderAgents(agents) {
        var $container = $('#qualid-agents-body');
        $container.empty();

        agents.forEach(function (agent) {
            var isProvisioned = !!agent.sip_username;
            var rowStyle      = agent.active ? '' : ' style="opacity:0.55;"';

            var statusBadge = isProvisioned
                ? '<span class="reg-badge provisioned"><span class="dot"></span>Provisioned</span>'
                : '<span class="reg-badge offline"><span class="dot"></span>Not provisioned</span>';

            var actions = isProvisioned
                ? '<button class="qualid-btn qualid-btn-outline qualid-btn-sm" ' +
                  'onclick="QualidRemote.copyAgentSip(\'' + escHtml(agent.sip_username) + '\',this)">' +
                  '<i class="fa fa-copy"></i> Copy SIP</button>'
                : '<button class="qualid-btn qualid-btn-primary qualid-btn-sm" ' +
                  'onclick="QualidRemote.provisionAgent(\'' + escHtml(String(agent.id)) + '\',\'' +
                  escHtml(agent.name) + '\',\'' + escHtml(agent.agent_code) + '\',this)">' +
                  '<i class="fa fa-plug"></i> Provision</button>';

            var nameSuffix = agent.active ? '' : ' <span style="font-size:10px;color:#c0ccd8;">(inactive)</span>';

            var $row = $(
                '<tr' + rowStyle + '>' +
                '<td class="agent-name-cell">' + escHtml(agent.name) + nameSuffix + '</td>' +
                '<td style="font-family:monospace;font-size:12px;color:#5a6a80;">' + escHtml(agent.agent_code) + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>'
            );
            $container.append($row);
        });
    }

    function provisionAgent(agentId, agentName, agentCode, btn) {
        var $btn = $(btn);
        setButtonLoading($btn, 'Provisioning\u2026');

        $.ajax({
            url:    BASE_URL,
            method: 'POST',
            data: {
                ajax_action: 'provision_agent',
                agent_id:    agentId,
                agent_name:  agentName,
                agent_code:  agentCode,
            },
            dataType: 'json',
        }).done(function (res) {
            if (res.success) {
                loadAgents();
            } else {
                alert('Error provisioning agent: ' + (res.error || 'Unknown'));
                resetButton($btn);
            }
        }).fail(function () {
            alert('Network error.');
            resetButton($btn);
        });
    }

    function copyAgentSip(sipUsername, btn) {
        copyToClipboard(sipUsername, $(btn));
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
    // Init
    // -------------------------------------------------------------------------

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

        // Refresh agents
        $('#qualid-refresh-agents').on('click', loadAgents);

        // Auto-load agents when connected
        if ($('#qualid-agents-body').length) {
            loadAgents();
        }

        // Copy SIP domain
        $('#qualid-copy-domain').on('click', function () {
            copyToClipboard($(this).data('value'), $(this));
        });
    }

    return {
        init:           init,
        provisionAgent: provisionAgent,
        copyAgentSip:   copyAgentSip,
    };

}(jQuery));

$(document).ready(function () {
    QualidRemote.init();
});
