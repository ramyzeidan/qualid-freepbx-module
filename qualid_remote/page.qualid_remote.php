<?php
/**
 * QUALI-D Remote Agent — Admin Page
 * Rendered by FreePBX module framework under Admin → QUALI-D Remote Agent
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Enqueue our assets — skipped for AJAX calls (pure JSON response)
if (!isset($_GET['qual_ajax'])) {
    $asset_path = 'modules/qualid_remote/assets';
    echo '<link rel="stylesheet" href="' . $asset_path . '/css/qualid_remote.css">';
    echo '<script src="' . $asset_path . '/js/qualid_remote.js"></script>';
}

// ---------------------------------------------------------------------------
// Internal helper — called after successful login/TOTP to provision trunk
// ---------------------------------------------------------------------------
function qualid_complete_login($token, $user_name, $company_name) {
    if (!$token) {
        return ['success' => false, 'error' => 'No token received from QUALI-D API.'];
    }

    $result = qualid_provision_trunk($token);
    if (!$result['success']) {
        return $result;
    }

    $data = $result['data'];
    qualid_set('token',        $token);
    qualid_set('user_name',    $user_name);
    qualid_set('company_name', $company_name);
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

    return ['success' => true];
}

// ---------------------------------------------------------------------------
// Handle AJAX requests
// ---------------------------------------------------------------------------
if (isset($_GET['qual_ajax'])) {
    // FreePBX has already buffered its own HTML (security banners, etc.) by the
    // time page.php runs.  Wipe the entire output buffer so the response is
    // pure JSON and jQuery's dataType:'json' can parse it.
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $ajax_action = isset($_POST['ajax_action']) ? $_POST['ajax_action'] : (isset($_GET['ajax_action']) ? $_GET['ajax_action'] : '');

    switch ($ajax_action) {

        // -- Step 1: Login with phone + password -----------------------------
        case 'login':
            $phone    = trim(isset($_POST['phone'])    ? $_POST['phone']    : '');
            $password = trim(isset($_POST['password']) ? $_POST['password'] : '');

            if (!$phone || !$password) {
                echo json_encode(['success' => false, 'error' => 'Phone and password are required.']);
                exit;
            }

            $result = qualid_login($phone, $password, '');

            if (!$result['success']) {
                echo json_encode($result);
                exit;
            }

            // TOTP required — return temp_token to JS for step 2
            if (isset($result['totp_required']) && $result['totp_required']) {
                echo json_encode([
                    'success'       => true,
                    'totp_required' => true,
                    'temp_token'    => isset($result['temp_token']) ? $result['temp_token'] : '',
                ]);
                exit;
            }

            // No TOTP — provision trunk immediately
            $token        = isset($result['token'])        ? $result['token']        : '';
            $user_name    = isset($result['name'])         ? $result['name']         : '';
            $company_name = isset($result['company_name']) ? $result['company_name'] : '';
            echo json_encode(qualid_complete_login($token, $user_name, $company_name));
            exit;

        // -- Step 2: Verify TOTP code ----------------------------------------
        case 'verify_totp':
            $temp_token = trim(isset($_POST['temp_token']) ? $_POST['temp_token'] : '');
            $code       = trim(isset($_POST['code'])       ? $_POST['code']       : '');

            if (!$temp_token || !$code) {
                echo json_encode(['success' => false, 'error' => 'Verification code is required.']);
                exit;
            }

            $result = qualid_verify_totp($temp_token, $code);

            if (!$result['success']) {
                echo json_encode($result);
                exit;
            }

            $token        = isset($result['token'])        ? $result['token']        : '';
            $user_name    = isset($result['name'])         ? $result['name']         : '';
            $company_name = isset($result['company_name']) ? $result['company_name'] : '';
            echo json_encode(qualid_complete_login($token, $user_name, $company_name));
            exit;

        // -- Disconnect -------------------------------------------------------
        case 'disconnect':
            qualid_save_config([]);
            qualid_remove_asterisk_config();
            echo json_encode(['success' => true]);
            exit;

        // -- Get agents -------------------------------------------------------
        case 'get_agents':
            $token = qualid_get('token');
            if (!$token) {
                echo json_encode(['success' => false, 'agents' => []]);
                exit;
            }

            $api_agents  = qualid_fetch_agents($token);
            $provisioned = qualid_get('provisioned_agents', []);
            $sip_domain  = qualid_get('sip_domain', '');

            $merged = [];
            foreach ($api_agents as $agent) {
                $id   = (string) (isset($agent['id']) ? $agent['id'] : '');
                $code = isset($agent['agent_code']) ? $agent['agent_code'] : '';
                $prov = isset($provisioned[$id]) ? $provisioned[$id] : null;

                $merged[] = [
                    'id'           => $id,
                    'name'         => isset($agent['name'])   ? $agent['name']   : '',
                    'agent_code'   => $code,
                    'active'       => isset($agent['active']) ? $agent['active'] : 0,
                    'sip_username' => $prov ? $code : null,
                    'sip_domain'   => $prov ? $sip_domain : null,
                    'registered'   => false,
                ];
            }

            echo json_encode(['success' => true, 'agents' => $merged]);
            exit;

        // -- Provision single agent -------------------------------------------
        case 'provision_agent':
            $token      = qualid_get('token');
            $agent_id   = trim(isset($_POST['agent_id'])   ? $_POST['agent_id']   : '');
            $agent_name = trim(isset($_POST['agent_name']) ? $_POST['agent_name'] : '');
            $agent_code = trim(isset($_POST['agent_code']) ? $_POST['agent_code'] : '');

            if (!$token || !$agent_id || !$agent_code) {
                echo json_encode(['success' => false, 'error' => 'Not configured or missing agent data.']);
                exit;
            }

            $result = qualid_provision_agent($token, $agent_id, $agent_name, $agent_code);

            if (isset($result['success']) && $result['success']) {
                $provisioned = qualid_get('provisioned_agents', []);
                $provisioned[$agent_id] = [
                    'agent_code' => $agent_code,
                    'name'       => $agent_name,
                ];
                qualid_set('provisioned_agents', $provisioned);
            }

            echo json_encode($result);
            exit;

        // -- Test connection --------------------------------------------------
        case 'test_connection':
            $token = qualid_get('token');
            if (!$token) {
                echo json_encode(['success' => false, 'error' => 'Not connected.']);
                exit;
            }
            $agents = qualid_fetch_agents($token);
            if (is_array($agents)) {
                echo json_encode(['success' => true, 'registered' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Token may be expired. Please reconnect.']);
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
$cfg          = qualid_get_all();
$status       = qualid_connection_status();
$is_connected = $status['status'] === 'connected';

?>

<!-- Hero Banner -->
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
        <span class="qualid-version">v1.0.9</span>
        <span class="qualid-status-pill <?= htmlspecialchars($status['status']) ?>">
            <span class="status-dot"></span>
            <?= htmlspecialchars($status['label']) ?>
        </span>
    </div>
</div>

<!-- Setup Steps -->
<div class="qualid-steps">
    <div class="qualid-step <?= $is_connected ? 'done' : 'active' ?>">
        <div class="qualid-step-num"><?= $is_connected ? '&#10003;' : '1' ?></div>
        <div><strong>Connect</strong><br><span style="font-size:11px;">Login with QUALI-D</span></div>
    </div>
    <div class="qualid-step <?= $is_connected ? 'active' : '' ?>">
        <div class="qualid-step-num">2</div>
        <div><strong>Provision Agents</strong><br><span style="font-size:11px;">Assign SIP accounts</span></div>
    </div>
    <div class="qualid-step">
        <div class="qualid-step-num">3</div>
        <div><strong>Configure Queues</strong><br><span style="font-size:11px;">Route calls to agents</span></div>
    </div>
    <div class="qualid-step">
        <div class="qualid-step-num">4</div>
        <div><strong>Agent App</strong><br><span style="font-size:11px;">Install QUALI-D on Android/iOS</span></div>
    </div>
</div>

<div class="row">
    <div class="col-md-5">

        <!-- Connection Card -->
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

                <!-- Step 1: Phone + Password -->
                <div id="qualid-step-login">
                    <p style="font-size:13px;color:#5a6a80;margin-bottom:16px;">
                        Login with your QUALI-D account to automatically configure the SIP trunk
                        and connect your FreePBX to the QUALI-D cloud relay.
                    </p>

                    <div id="qualid-connect-alert"></div>

                    <div style="margin-bottom:12px;">
                        <label style="font-size:12px;font-weight:600;color:#4a5a70;margin-bottom:5px;display:block;">
                            Phone Number
                        </label>
                        <input type="text"
                               id="qualid-phone"
                               class="form-control"
                               placeholder="01xxxxxxxxx"
                               autocomplete="off"
                               style="font-size:13px;border:2px solid #dde5f0;border-radius:8px;">
                        <div style="margin-top:4px;font-size:11px;color:#a0b0c0;">Egypt numbers only (country code +20)</div>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="font-size:12px;font-weight:600;color:#4a5a70;margin-bottom:5px;display:block;">
                            Password
                        </label>
                        <div style="position:relative;">
                            <input type="password"
                                   id="qualid-password"
                                   class="form-control"
                                   placeholder="Your QUALI-D password"
                                   autocomplete="off"
                                   style="font-size:13px;padding-right:40px;border:2px solid #dde5f0;border-radius:8px;">
                            <button id="qualid-toggle-pass" type="button"
                                    style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#8a9db5;cursor:pointer;padding:0;">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button class="qualid-btn qualid-btn-primary" id="qualid-connect-btn" style="width:100%;">
                        <i class="fa fa-sign-in"></i> Login &amp; Connect
                    </button>
                </div>

                <!-- Step 2: TOTP (hidden until needed) -->
                <div id="qualid-step-totp" style="display:none;">
                    <p style="font-size:13px;color:#5a6a80;margin-bottom:16px;">
                        <i class="fa fa-shield" style="color:#f0a500;"></i>
                        Two-factor authentication required. Enter the code from your authenticator app.
                    </p>

                    <div id="qualid-totp-alert"></div>
                    <input type="hidden" id="qualid-temp-token" value="">

                    <div style="margin-bottom:14px;">
                        <label style="font-size:12px;font-weight:600;color:#4a5a70;margin-bottom:5px;display:block;">
                            Verification Code
                        </label>
                        <input type="text"
                               id="qualid-totp-code"
                               class="form-control"
                               placeholder="000000"
                               maxlength="6"
                               autocomplete="off"
                               style="font-size:22px;letter-spacing:8px;text-align:center;border:2px solid #dde5f0;border-radius:8px;">
                    </div>

                    <div style="display:flex;gap:8px;">
                        <button class="qualid-btn qualid-btn-outline" id="qualid-totp-back-btn" style="flex:0 0 auto;">
                            <i class="fa fa-arrow-left"></i> Back
                        </button>
                        <button class="qualid-btn qualid-btn-primary" id="qualid-totp-verify-btn" style="flex:1;">
                            <i class="fa fa-check"></i> Verify &amp; Connect
                        </button>
                    </div>
                </div>

                <?php else: ?>

                <!-- Connected — show account info -->
                <div id="qualid-connect-alert"></div>

                <div class="qualid-info-grid">
                    <div class="qualid-info-item">
                        <label>Account</label>
                        <span><?= htmlspecialchars($cfg['user_name']) ?></span>
                    </div>
                    <div class="qualid-info-item">
                        <label>Company</label>
                        <span><?= htmlspecialchars($cfg['company_name']) ?></span>
                    </div>
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
                        <span><?= htmlspecialchars($cfg['turn_server'] ?: 'qualidturn1.1215515.xyz') ?></span>
                    </div>
                    <div class="qualid-info-item">
                        <label>Connected At</label>
                        <span style="font-size:11px;"><?= htmlspecialchars($cfg['connected_at']) ?></span>
                    </div>
                </div>

                <div style="margin-top:14px;display:flex;justify-content:flex-end;">
                    <button class="qualid-btn qualid-btn-outline qualid-btn-sm"
                            id="qualid-copy-domain"
                            data-value="<?= htmlspecialchars($cfg['sip_domain']) ?>">
                        <i class="fa fa-copy"></i> Copy Domain
                    </button>
                </div>

                <hr class="qualid-divider">

                <div id="qualid-test-alert"></div>
                <button class="qualid-btn qualid-btn-outline" id="qualid-test-btn" style="width:100%;">
                    <i class="fa fa-stethoscope"></i> Test Connection
                </button>

                <?php endif; ?>

            </div>
        </div>

        <!-- Asterisk Config Card (connected only) -->
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
                                DIAL(PJSIP/&lt;agent_code&gt;@<?= htmlspecialchars($cfg['sip_domain']) ?>,QualidRemote_endpoint,60)
                            </code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <div class="col-md-7">

        <!-- Agents Card -->
        <div class="qualid-card" id="qualid-agents-section"
             style="<?= $is_connected ? '' : 'opacity:0.5;pointer-events:none;' ?>">
            <div class="qualid-card-header">
                <h3>
                    <span class="card-icon"><i class="fa fa-users"></i></span>
                    Remote Agents
                    <?php if (!$is_connected): ?>
                        <span style="font-size:11px;color:#a0b0c0;font-weight:400;margin-left:8px;">(connect first)</span>
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
                            <th>Agent Code</th>
                            <th>SIP / Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="qualid-agents-body">
                        <tr>
                            <td colspan="4" class="text-center" style="padding:30px;color:#8a9db5;">
                                <span class="qualid-spinner dark"></span> Loading…
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php endif; ?>

            </div>
        </div>

        <!-- Quick Setup Guide -->
        <div class="qualid-card">
            <div class="qualid-card-header">
                <h3>
                    <span class="card-icon"><i class="fa fa-book"></i></span>
                    Quick Setup Guide
                </h3>
            </div>
            <div class="qualid-card-body">
                <ol style="font-size:13px;color:#3a4a60;padding-left:20px;margin:0;line-height:2;">
                    <li>Login with your <strong>QUALI-D account</strong> and click <strong>Login &amp; Connect</strong>.</li>
                    <li>Click <strong>Provision</strong> next to each agent who will work remotely.</li>
                    <li>In your <strong>Ring Groups</strong> or <strong>Queues</strong>, use the agent's <strong>agent code</strong> as the SIP username via the <em>QualidRemote</em> trunk.</li>
                    <li>The agent installs the <strong>QUALI-D mobile app</strong> and logs in with their agent code.</li>
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
