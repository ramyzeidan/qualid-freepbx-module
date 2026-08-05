<?php
/**
 * QUALI-D Remote Agent — Admin Page
 * Rendered by FreePBX module framework under Admin → QUALI-D Remote Agent
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Enqueue our assets
$asset_path = 'modules/qualid_remote/assets';
echo '<link rel="stylesheet" href="' . $asset_path . '/css/qualid_remote.css">';
echo '<script src="' . $asset_path . '/js/qualid_remote.js"></script>';

// ---------------------------------------------------------------------------
// Handle AJAX requests
// ---------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'ajax') {
    header('Content-Type: application/json');

    $ajax_action = isset($_POST['ajax_action']) ? $_POST['ajax_action'] : (isset($_GET['ajax_action']) ? $_GET['ajax_action'] : '');

    switch ($ajax_action) {

        // -- Provision (connect) -------------------------------------------
        case 'provision':
            $api_key = trim(isset($_POST['api_key']) ? $_POST['api_key'] : '');
            if (!$api_key) {
                echo json_encode(['success' => false, 'error' => 'API key is required.']);
                exit;
            }

            $result = qualid_provision($api_key);
            if (!$result['success']) {
                qualid_set('last_error', $result['error']);
                echo json_encode($result);
                exit;
            }

            $data = $result['data'];
            qualid_set('api_key',      $api_key);
            qualid_set('company_id',   isset($data['company_id']) ? $data['company_id'] : '');
            qualid_set('sip_domain',   $data['sip_domain']);
            qualid_set('trunk_user',   $data['trunk_username']);
            qualid_set('trunk_pass',   $data['trunk_password']);
            qualid_set('turn_server',  isset($data['turn_server']) ? $data['turn_server'] : '');
            qualid_set('connected',    '1');
            qualid_set('connected_at', date('Y-m-d H:i:s'));
            qualid_set('last_error',   '');

            qualid_write_asterisk_config([
                'sip_domain' => $data['sip_domain'],
                'trunk_user' => $data['trunk_username'],
                'trunk_pass' => $data['trunk_password'],
            ]);

            echo json_encode(['success' => true]);
            exit;

        // -- Disconnect ---------------------------------------------------
        case 'disconnect':
            qualid_set('connected',  '0');
            qualid_set('api_key',    '');
            qualid_set('sip_domain', '');
            qualid_set('trunk_user', '');
            qualid_set('trunk_pass', '');
            qualid_remove_asterisk_config();
            echo json_encode(['success' => true]);
            exit;

        // -- Get agents ---------------------------------------------------
        case 'get_agents':
            $api_key = qualid_get('api_key');
            if (!$api_key) {
                echo json_encode(['success' => false, 'agents' => []]);
                exit;
            }
            $agents = qualid_get_agents($api_key);
            echo json_encode(['success' => true, 'agents' => $agents]);
            exit;

        // -- Provision single agent ---------------------------------------
        case 'provision_agent':
            $api_key    = qualid_get('api_key');
            $agent_id   = trim(isset($_POST['agent_id']) ? $_POST['agent_id'] : '');
            $agent_name = trim(isset($_POST['agent_name']) ? $_POST['agent_name'] : '');
            if (!$api_key || !$agent_id) {
                echo json_encode(['success' => false, 'error' => 'Not configured.']);
                exit;
            }
            $result = qualid_provision_agent($api_key, $agent_id, $agent_name);
            echo json_encode($result);
            exit;

        // -- Test connection ----------------------------------------------
        case 'test_connection':
            $api_key = qualid_get('api_key');
            if (!$api_key) {
                echo json_encode(['success' => false, 'error' => 'Not connected.']);
                exit;
            }
            // Ping the provision endpoint to verify API key is still valid
            $result = qualid_provision($api_key);
            if ($result['success']) {
                echo json_encode(['success' => true, 'registered' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $result['error']]);
            }
            exit;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
            exit;
    }
}

// ---------------------------------------------------------------------------
// Render the page
// ---------------------------------------------------------------------------
$cfg    = qualid_get_all();
$status = qualid_connection_status();
$is_connected = $status['status'] === 'connected';

?>

<!-- =========================================================
     Hero Banner
     ========================================================= -->
<div class="qualid-hero">
    <div class="qualid-hero-left">
        <img src="https://quali-d.com/logo.png"
             alt="QUALI-D"
             class="qualid-logo"
             onerror="this.style.display='none';document.getElementById('qualid-fallback-logo').style.display='block'">
        <span id="qualid-fallback-logo"
              style="display:none;font-size:22px;font-weight:900;color:#fff;letter-spacing:1px;">
            QUALI-D
        </span>
        <div class="qualid-hero-text">
            <h1>Remote Agent</h1>
            <p>Cloud SIP relay — agents work from anywhere on port 443</p>
        </div>
    </div>
    <div class="qualid-hero-badge">
        <span class="qualid-version">v1.0.0</span>
        <span class="qualid-status-pill <?= htmlspecialchars($status['status']) ?>">
            <span class="status-dot"></span>
            <?= htmlspecialchars($status['label']) ?>
        </span>
    </div>
</div>

<!-- =========================================================
     Setup Progress Steps
     ========================================================= -->
<div class="qualid-steps">
    <div class="qualid-step <?= $is_connected ? 'done' : 'active' ?>">
        <div class="qualid-step-num"><?= $is_connected ? '✓' : '1' ?></div>
        <div>
            <strong>Connect</strong><br>
            <span style="font-size:11px;">Enter API key</span>
        </div>
    </div>
    <div class="qualid-step <?= $is_connected ? 'active' : '' ?>">
        <div class="qualid-step-num">2</div>
        <div>
            <strong>Provision Agents</strong><br>
            <span style="font-size:11px;">Assign SIP accounts</span>
        </div>
    </div>
    <div class="qualid-step">
        <div class="qualid-step-num">3</div>
        <div>
            <strong>Configure Queues</strong><br>
            <span style="font-size:11px;">Route calls to remote agents</span>
        </div>
    </div>
    <div class="qualid-step">
        <div class="qualid-step-num">4</div>
        <div>
            <strong>Agent App</strong><br>
            <span style="font-size:11px;">Install QUALI-D on Android/iOS</span>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-5">

        <!-- =====================================================
             Connection Card
             ===================================================== -->
        <div class="qualid-card">
            <div class="qualid-card-header">
                <h3>
                    <span class="card-icon"><i class="fa fa-plug"></i></span>
                    Cloud Connection
                </h3>
                <?php if ($is_connected): ?>
                    <button class="qualid-btn qualid-btn-danger qualid-btn-sm" id="qualid-disconnect-btn">
                        <i class="fa fa-unlink"></i> Disconnect
                    </button>
                <?php endif; ?>
            </div>
            <div class="qualid-card-body">

                <?php if (!$is_connected): ?>
                <!-- Not connected — show API key input -->
                <p style="font-size:13px;color:#5a6a80;margin-bottom:16px;">
                    Enter your QUALI-D API key to automatically configure the SIP trunk
                    and connect your FreePBX to the QUALI-D cloud relay.
                </p>

                <div id="qualid-connect-alert"></div>

                <div style="margin-bottom:14px;">
                    <label style="font-size:12px;font-weight:600;color:#4a5a70;margin-bottom:6px;display:block;">
                        QUALI-D API Key
                    </label>
                    <div style="display:flex;gap:8px;">
                        <div style="position:relative;flex:1;">
                            <input type="password"
                                   id="qualid-api-key"
                                   class="form-control"
                                   placeholder="qd_live_xxxxxxxxxxxxxxxxxxxxxxxx"
                                   autocomplete="off"
                                   style="font-family:monospace;font-size:13px;padding-right:40px;border:2px solid #dde5f0;border-radius:8px;">
                            <button id="qualid-toggle-key"
                                    type="button"
                                    style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#8a9db5;cursor:pointer;padding:0;">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div style="margin-top:8px;font-size:11px;color:#a0b0c0;">
                        Find your API key in the QUALI-D dashboard under Settings → API Keys
                    </div>
                </div>

                <button class="qualid-btn qualid-btn-primary" id="qualid-connect-btn" style="width:100%;">
                    <i class="fa fa-cloud"></i> Connect to QUALI-D Cloud
                </button>

                <?php else: ?>
                <!-- Connected — show connection info -->
                <div id="qualid-connect-alert"></div>

                <div class="qualid-info-grid">
                    <div class="qualid-info-item">
                        <label>SIP Domain</label>
                        <span><?= htmlspecialchars($cfg['sip_domain']) ?></span>
                    </div>
                    <div class="qualid-info-item">
                        <label>Trunk User</label>
                        <span><?= htmlspecialchars($cfg['trunk_user']) ?></span>
                    </div>
                    <div class="qualid-info-item">
                        <label>TURN Server</label>
                        <span><?= htmlspecialchars($cfg['turn_server'] ?: 'turn.quali-d.com') ?></span>
                    </div>
                    <div class="qualid-info-item">
                        <label>Connected At</label>
                        <span style="font-size:11px;font-family:inherit;"><?= htmlspecialchars($cfg['connected_at']) ?></span>
                    </div>
                </div>

                <div class="qualid-info-item" style="margin-top:14px;">
                    <label>API Key</label>
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <span><?= htmlspecialchars(qualid_mask_key($cfg['api_key'])) ?></span>
                        <button class="qualid-btn qualid-btn-outline qualid-btn-sm"
                                id="qualid-copy-domain"
                                data-value="<?= htmlspecialchars($cfg['sip_domain']) ?>">
                            <i class="fa fa-copy"></i> Copy Domain
                        </button>
                    </div>
                </div>

                <hr class="qualid-divider">

                <div id="qualid-test-alert"></div>
                <button class="qualid-btn qualid-btn-outline" id="qualid-test-btn" style="width:100%;">
                    <i class="fa fa-stethoscope"></i> Test Connection
                </button>

                <?php endif; ?>

            </div>
        </div>

        <!-- =====================================================
             Asterisk Config Card (only when connected)
             ===================================================== -->
        <?php if ($is_connected): ?>
        <div class="qualid-card">
            <div class="qualid-card-header">
                <h3>
                    <span class="card-icon"><i class="fa fa-cog"></i></span>
                    Asterisk Configuration
                </h3>
            </div>
            <div class="qualid-card-body">
                <div class="qualid-alert qualid-alert-info" style="margin-bottom:0;">
                    <i class="fa fa-info-circle"></i>
                    <div>
                        <strong>Auto-generated files:</strong>
                        <ul style="margin:8px 0 0 0;padding-left:18px;font-size:12px;">
                            <li><code>/etc/asterisk/pjsip_qualid.conf</code> — WSS trunk</li>
                            <li><code>/etc/asterisk/extensions_qualid.conf</code> — dialplan</li>
                        </ul>
                        <div style="margin-top:10px;font-size:12px;">
                            To route a call to a remote agent, use:<br>
                            <code style="background:rgba(26,127,255,0.08);padding:2px 6px;border-radius:4px;display:inline-block;margin-top:4px;">
                                DIAL(PJSIP/agent_&lt;id&gt;@<?= htmlspecialchars($cfg['sip_domain']) ?>,QualidRemote_endpoint,60)
                            </code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <div class="col-md-7">

        <!-- =====================================================
             Agents Card
             ===================================================== -->
        <div class="qualid-card" id="qualid-agents-section"
             style="<?= $is_connected ? '' : 'opacity:0.5;pointer-events:none;' ?>">
            <div class="qualid-card-header">
                <h3>
                    <span class="card-icon"><i class="fa fa-users"></i></span>
                    Remote Agents
                    <?php if (!$is_connected): ?>
                        <span style="font-size:11px;color:#a0b0c0;font-weight:400;margin-left:8px;">
                            (connect first)
                        </span>
                    <?php endif; ?>
                </h3>
                <?php if ($is_connected): ?>
                <button class="qualid-btn qualid-btn-outline qualid-btn-sm" id="qualid-refresh-agents">
                    <i class="fa fa-refresh"></i> Refresh
                </button>
                <?php endif; ?>
            </div>
            <div class="qualid-card-body" style="padding:0;">

                <?php if (!$is_connected): ?>
                <div class="qualid-empty">
                    <div class="empty-icon">🔌</div>
                    <p>Connect to QUALI-D first to manage agents.</p>
                </div>
                <?php else: ?>
                <table class="qualid-agent-table">
                    <thead>
                        <tr>
                            <th>Agent Name</th>
                            <th>Extension</th>
                            <th>SIP Username</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="qualid-agents-body">
                        <tr>
                            <td colspan="5" class="text-center" style="padding:30px;color:#8a9db5;">
                                <span class="qualid-spinner dark"></span> Loading…
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php endif; ?>

            </div>
        </div>

        <!-- =====================================================
             Quick Setup Guide Card
             ===================================================== -->
        <div class="qualid-card">
            <div class="qualid-card-header">
                <h3>
                    <span class="card-icon"><i class="fa fa-book"></i></span>
                    Quick Setup Guide
                </h3>
            </div>
            <div class="qualid-card-body">
                <ol style="font-size:13px;color:#3a4a60;padding-left:20px;margin:0;line-height:2;">
                    <li>Enter your <strong>QUALI-D API Key</strong> above and click <strong>Connect</strong>.</li>
                    <li>Click <strong>Provision</strong> next to each agent who will work remotely.</li>
                    <li>In your <strong>Ring Groups</strong> or <strong>Queues</strong>, add the agent using their SIP username as the destination via the <em>QualidRemote</em> trunk.</li>
                    <li>The agent installs the <strong>QUALI-D mobile app</strong> and logs in — they will automatically register to the cloud relay.</li>
                    <li>Calls route: <strong>PSTN → FreePBX → QUALI-D Cloud → Agent App</strong>, all over port 443.</li>
                </ol>

                <hr class="qualid-divider">

                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a href="https://quali-d.com" target="_blank" class="qualid-btn qualid-btn-outline qualid-btn-sm">
                        <i class="fa fa-external-link"></i> QUALI-D Dashboard
                    </a>
                    <a href="https://quali-d.com/docs/remote-agent" target="_blank" class="qualid-btn qualid-btn-outline qualid-btn-sm">
                        <i class="fa fa-book"></i> Documentation
                    </a>
                    <a href="https://quali-d.com/support" target="_blank" class="qualid-btn qualid-btn-outline qualid-btn-sm">
                        <i class="fa fa-life-ring"></i> Support
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>
