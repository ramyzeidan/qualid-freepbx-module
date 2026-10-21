<?php
/**
 * Quali-D Connect — Admin Page
 * Rendered by FreePBX module framework under Admin → Quali-D Connect
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Ensure the FreePBX asset symlink exists.
// FreePBX rewrites 'modules/qualid_remote/assets' → '/admin/assets/qualid_remote'
// and serves files from that symlink. On a fresh install the symlink may be
// missing if the module was deployed manually (not via fwconsole).
$_qualid_symlink = dirname(dirname(__DIR__)) . '/assets/qualid_remote';
if (!file_exists($_qualid_symlink) && is_dir(__DIR__ . '/assets')) {
    @symlink(__DIR__ . '/assets', $_qualid_symlink);
}

// Enqueue our assets — skipped for AJAX calls (pure JSON response)
if (!isset($_GET['qual_ajax'])) {
    $asset_path = 'modules/qualid_remote/assets';
    $_js_ver    = @filemtime(__DIR__ . '/assets/js/qualid_remote.js') ?: time();
    echo '<link rel="stylesheet" href="' . $asset_path . '/css/qualid_remote.css">';
    echo '<script src="' . $asset_path . '/js/qualid_remote.js?v=' . $_js_ver . '"></script>';
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

    // Generate a per-company AGI secret, register it with the QUALI-D cloud,
    // and deploy the IVR AGI script to /var/lib/asterisk/agi-bin/ automatically.
    $agi_secret = qualid_generate_agi_secret();
    qualid_register_agi_secret($token, $agi_secret);  // best-effort — failure is non-fatal
    qualid_set('agi_secret', $agi_secret);
    qualid_write_agi_files($agi_secret);

    // Register this FreePBX server's IP with the QUALI-D cloud so agents
    // know which host to SIP-register against (best-effort — non-fatal).
    qualid_push_pbx_host($token);

    // Sync all extensions, queues, and CDR immediately on login.
    qualid_sync_extensions($token);
    qualid_sync_queues($token);
    qualid_sync_cdr_to_qualid($token);

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

            // Turnstile token is intentionally empty — the caller is already
            // authenticated as a FreePBX admin, so bot protection is not needed here.
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
            qualid_remove_agi_files();
            qualid_remove_cron();
            echo json_encode(['success' => true]);
            exit;

        // -- Test connection --------------------------------------------------
        case 'test_connection':
            $token = qualid_get('token');
            if (!$token) {
                echo json_encode(['success' => false, 'error' => 'Not connected.']);
                exit;
            }
            // Ping the Quali-D API with the stored token
            $ping = qualid_curl_post(QUALID_MAIN_API . '/ping', [], ['Authorization: Bearer ' . $token]);
            if (is_array($ping) && !isset($ping['_error'])) {
                echo json_encode(['success' => true, 'registered' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Token may be expired. Please reconnect.']);
            }
            exit;

        // -- IVR connection status check --------------------------------------
        case 'get_ivr_status':
            $token = qualid_get('token');
            if ($token) qualid_send_heartbeat($token);
            echo json_encode(array_merge(
                ['success' => true],
                qualid_test_ivr_connection()
            ));
            exit;

        // -- Sync CDR records to QUALI-D cloud (also sends heartbeat) ---------
        case 'sync_cdr':
            $token = qualid_get('token');
            if (!$token) {
                echo json_encode(['success' => false, 'error' => 'Not connected.']);
                exit;
            }
            qualid_send_heartbeat($token);
            echo json_encode(qualid_sync_cdr_to_qualid($token));
            exit;

        // -- Sync FreePBX extensions to QUALI-D cloud -------------------------
        case 'sync_extensions':
            $token = qualid_get('token');
            if (!$token) {
                echo json_encode(['success' => false, 'error' => 'Not connected.']);
                exit;
            }
            echo json_encode(qualid_sync_extensions($token));
            exit;

        // -- Sync queues from QUALI-D cloud → write queues_qualid.conf --------
        case 'sync_queues':
            $token = qualid_get('token');
            if (!$token) {
                echo json_encode(['success' => false, 'error' => 'Not connected.']);
                exit;
            }
            echo json_encode(qualid_sync_queues($token));
            exit;

        // -- Check GitHub for a newer release ---------------------------------
        case 'check_update':
            echo json_encode(qualid_check_github_update());
            exit;

        // -- Download + extract the latest release from GitHub ----------------
        case 'do_update':
            echo json_encode(qualid_do_github_update());
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

// Sync extensions synchronously on every page load — guaranteed to run,
// no AJAX, no JavaScript, no CSRF. Typically < 1 second.
if ($is_connected && !empty($cfg['token'])) {
    qualid_sync_extensions($cfg['token']);
}

// Read version dynamically from module.xml so the badge always matches
$_xml          = @simplexml_load_file(QUALID_MODULE_DIR . '/module.xml');
$_module_ver   = $_xml ? 'v' . trim((string) $_xml->version) : 'v?';

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
            <h1>Quali-D Connect</h1>
            <p>Enterprise voice connectivity, powered by Quali-D Cloud</p>
        </div>
    </div>
    <div class="qualid-hero-badge">
        <span class="qualid-version"><?= htmlspecialchars($_module_ver) ?></span>
        <button id="qualid-check-update-btn"
                style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.8);cursor:pointer;padding:2px 10px;font-size:11px;border-radius:10px;line-height:1.6;margin-left:4px;"
                onclick="QualidRemote.checkForUpdate(this)">
            <i class="fa fa-refresh" style="margin-right:4px;"></i>Check For Updates
        </button>
        <span id="qualid-update-badge" style="display:none;margin-left:8px;">
            <button id="qualid-do-update-btn"
                    class="btn btn-xs btn-warning"
                    style="font-size:11px;padding:2px 8px;border-radius:10px;">
                <i class="fa fa-arrow-circle-up"></i>
                <span id="qualid-update-label">Update available</span>
            </button>
        </span>
        <span class="qualid-status-pill <?= htmlspecialchars($status['status']) ?>">
            <span class="status-dot"></span>
            <?= htmlspecialchars($status['label']) ?>
        </span>
    </div>
</div>

<div class="row">
    <div class="col-md-7">

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
                    <div class="qualid-info-item">
                        <label>Last Apply Config</label>
                        <?php
                            $_last_ac = qualid_get('last_apply_config_at', '');
                        ?>
                        <span style="font-size:11px;<?= $_last_ac ? '' : 'color:#c0a020;' ?>">
                            <?= $_last_ac ? htmlspecialchars($_last_ac) : 'Not yet triggered — click Apply Config' ?>
                        </span>
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

        <!-- IVR Connection Status Card -->
        <?php if ($is_connected): ?>
        <div class="qualid-card" id="qualid-ivr-status-card">
            <div class="qualid-card-header">
                <h3>
                    <span class="card-icon"><i class="fa fa-exchange"></i></span>
                    IVR Connection Status
                </h3>
                <button class="qualid-btn qualid-btn-outline qualid-btn-sm" id="qualid-ivr-refresh-btn">
                    <i class="fa fa-refresh"></i>
                </button>
            </div>
            <div class="qualid-card-body" id="qualid-ivr-status-body">
                <div style="text-align:center;padding:16px;color:#8a9db5;">
                    <span class="qualid-spinner dark"></span> Checking&hellip;
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
