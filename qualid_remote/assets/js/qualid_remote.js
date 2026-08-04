/* =============================================================================
   QUALI-D Remote Agent — Admin Page JavaScript
   jQuery is available from FreePBX's admin panel
   ============================================================================= */

var QualidRemote = (function ($) {
    'use strict';

    var BASE_URL = window.location.pathname + '?display=qualid_remote&action=ajax';

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

    function animateIn($el) {
        $el.css({ opacity: 0, transform: 'translateY(10px)' })
           .animate({ opacity: 1 }, 300)
           .css('transform', 'translateY(0)');
    }

    function copyToClipboard(text, $btn) {
        navigator.clipboard.writeText(text).then(function () {
            var orig = $btn.html();
            $btn.html('<i class="fa fa-check"></i> Copied!');
            setTimeout(function () { $btn.html(orig); }, 1800);
        });
    }

    // -------------------------------------------------------------------------
    // Connect / Provision
    // -------------------------------------------------------------------------

    function handleConnect() {
        var $btn = $('#qualid-connect-btn');
        var apiKey = $('#qualid-api-key').val().trim();

        clearAlert('#qualid-connect-alert');

        if (!apiKey) {
            showAlert('#qualid-connect-alert', 'error', 'Please enter your QUALI-D API key.');
            return;
        }

        setButtonLoading($btn, 'Connecting…');

        $.ajax({
            url: BASE_URL,
            method: 'POST',
            data: { ajax_action: 'provision', api_key: apiKey },
            dataType: 'json',
        }).done(function (res) {
            if (res.success) {
                showAlert('#qualid-connect-alert', 'success', 'Connected successfully! Asterisk configs have been written.');
                setTimeout(function () { window.location.reload(); }, 1200);
            } else {
                showAlert('#qualid-connect-alert', 'error', res.error || 'Connection failed. Check your API key.');
                resetButton($btn);
            }
        }).fail(function () {
            showAlert('#qualid-connect-alert', 'error', 'Network error — could not reach the QUALI-D API.');
            resetButton($btn);
        });
    }

    function handleDisconnect() {
        if (!confirm('This will remove the QUALI-D SIP trunk and dialplan from Asterisk. Are you sure?')) return;
        var $btn = $('#qualid-disconnect-btn');
        setButtonLoading($btn, 'Disconnecting…');

        $.ajax({
            url: BASE_URL,
            method: 'POST',
            data: { ajax_action: 'disconnect' },
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
            '<tr><td colspan="5" class="text-center" style="padding:30px;color:#8a9db5;">' +
            '<span class="qualid-spinner dark"></span> Loading agents…</td></tr>'
        );

        $.ajax({
            url: BASE_URL,
            method: 'GET',
            data: { ajax_action: 'get_agents' },
            dataType: 'json',
        }).done(function (res) {
            if (!res.success || !res.agents || res.agents.length === 0) {
                $container.html(
                    '<tr><td colspan="5">' +
                    '<div class="qualid-empty"><div class="empty-icon">👤</div>' +
                    '<p>No agents found. Add agents in the QUALI-D dashboard first.</p></div>' +
                    '</td></tr>'
                );
                return;
            }
            renderAgents(res.agents);
        }).fail(function () {
            $container.html('<tr><td colspan="5"><div class="qualid-alert qualid-alert-error">' +
                '<i class="fa fa-exclamation-circle"></i> Failed to load agents.</div></td></tr>');
        });
    }

    function renderAgents(agents) {
        var $container = $('#qualid-agents-body');
        $container.empty();

        agents.forEach(function (agent) {
            var regBadge = agent.sip_username
                ? (agent.registered
                    ? '<span class="reg-badge online"><span class="dot"></span>Online</span>'
                    : '<span class="reg-badge provisioned"><span class="dot"></span>Provisioned</span>')
                : '<span class="reg-badge offline"><span class="dot"></span>Not provisioned</span>';

            var sipCell = agent.sip_username
                ? '<span class="agent-sip-cell">' + escHtml(agent.sip_username) + '</span>'
                : '<span style="color:#c0ccd8;font-size:12px;">—</span>';

            var actions = agent.sip_username
                ? '<button class="qualid-btn qualid-btn-outline qualid-btn-sm" onclick="QualidRemote.copyAgentSip(\'' + escHtml(agent.sip_username) + '\', this)">' +
                  '<i class="fa fa-copy"></i> Copy SIP</button>'
                : '<button class="qualid-btn qualid-btn-primary qualid-btn-sm" onclick="QualidRemote.provisionAgent(\'' + escHtml(agent.id) + '\',\'' + escHtml(agent.name) + '\',this)">' +
                  '<i class="fa fa-plug"></i> Provision</button>';

            var $row = $(
                '<tr>' +
                '<td class="agent-name-cell">' + escHtml(agent.name) + '</td>' +
                '<td style="color:#8a9db5;font-size:12px;">' + escHtml(agent.extension || '—') + '</td>' +
                '<td>' + sipCell + '</td>' +
                '<td>' + regBadge + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>'
            );
            $container.append($row);
        });

        animateIn($container);
    }

    function provisionAgent(agentId, agentName, btn) {
        var $btn = $(btn);
        setButtonLoading($btn, 'Provisioning…');

        $.ajax({
            url: BASE_URL,
            method: 'POST',
            data: { ajax_action: 'provision_agent', agent_id: agentId, agent_name: agentName },
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
        setButtonLoading($btn, 'Testing…');
        clearAlert('#qualid-test-alert');

        $.ajax({
            url: BASE_URL,
            method: 'POST',
            data: { ajax_action: 'test_connection' },
            dataType: 'json',
        }).done(function (res) {
            if (res.success) {
                showAlert('#qualid-test-alert', 'success',
                    '✓ Connection OK — SIP domain reachable. Trunk registered: ' + (res.registered ? 'Yes' : 'Pending'));
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
    // API key visibility toggle
    // -------------------------------------------------------------------------

    function toggleApiKeyVisibility() {
        var $input = $('#qualid-api-key');
        var $icon = $('#qualid-toggle-key i');
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
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    function init() {
        // Connect button
        $('#qualid-connect-btn').on('click', handleConnect);

        // Enter key in API field
        $('#qualid-api-key').on('keydown', function (e) {
            if (e.key === 'Enter') handleConnect();
        });

        // Disconnect
        $('#qualid-disconnect-btn').on('click', handleDisconnect);

        // Toggle API key visibility
        $('#qualid-toggle-key').on('click', toggleApiKeyVisibility);

        // Test connection
        $('#qualid-test-btn').on('click', testConnection);

        // Refresh agents
        $('#qualid-refresh-agents').on('click', loadAgents);

        // Auto-load agents if connected
        if ($('#qualid-agents-section').length) {
            loadAgents();
        }

        // Copy SIP domain
        $('#qualid-copy-domain').on('click', function () {
            var domain = $(this).data('value');
            copyToClipboard(domain, $(this));
        });
    }

    // Public API
    return {
        init: init,
        provisionAgent: provisionAgent,
        copyAgentSip: copyAgentSip,
    };

}(jQuery));

$(document).ready(function () {
    QualidRemote.init();
});
