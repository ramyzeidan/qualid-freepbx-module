<?php
/**
 * QUALI-D Remote Agent — FreePBX Module Helper Functions
 * Handles config storage, QUALI-D API calls, and Asterisk config generation.
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define('QUALID_MAIN_API',      'https://api.quali-d.com');
define('QUALID_RELAY_URL',     'https://qualidapi1.1215515.xyz');
define('QUALID_CLOUD_SERVER',  'qualidsip1.1215515.xyz');
define('QUALID_CLOUD_PORT',    443);
define('QUALID_CLOUD_IP',      '13.140.143.85');  // VPS direct IP — used for SIP/TCP trunk on port 443
define('QUALID_TRUNK_NAME',    'QualidRemote');
define('QUALID_CONTEXT',       'qualid-remote-agents');
define('QUALID_IVR_CONF',      '/etc/asterisk/qualid_ivr.conf');
define('QUALID_AGI_BIN',       '/var/lib/asterisk/agi-bin/qualid_ivr.php');
define('QUALID_AGI_SRC',       dirname(__FILE__) . '/agi/qualid_ivr.php');

// ---------------------------------------------------------------------------
// Config storage (JSON file — no FreePBX API dependency)
// ---------------------------------------------------------------------------

function qualid_config_path() {
    return dirname(__FILE__) . '/config.json';
}

function qualid_load_config() {
    $path = qualid_config_path();
    if (!file_exists($path)) { return []; }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function qualid_save_config($cfg) {
    file_put_contents(qualid_config_path(), json_encode($cfg));
}

function qualid_get($key, $default = null) {
    $cfg = qualid_load_config();
    return isset($cfg[$key]) ? $cfg[$key] : $default;
}

function qualid_set($key, $value) {
    $cfg = qualid_load_config();
    $cfg[$key] = $value;
    qualid_save_config($cfg);
}

function qualid_get_all() {
    return [
        'token'              => qualid_get('token',              ''),
        'user_name'          => qualid_get('user_name',          ''),
        'company_name'       => qualid_get('company_name',       ''),
        'sip_domain'         => qualid_get('sip_domain',         ''),
        'trunk_user'         => qualid_get('trunk_user',         ''),
        'trunk_pass'         => qualid_get('trunk_pass',         ''),
        'turn_server'        => qualid_get('turn_server',        ''),
        'connected'          => qualid_get('connected',          '0'),
        'connected_at'       => qualid_get('connected_at',       ''),
        'last_error'         => qualid_get('last_error',         ''),
        'provisioned_agents' => qualid_get('provisioned_agents', []),
        'agi_secret'         => qualid_get('agi_secret',         ''),
    ];
}

// ---------------------------------------------------------------------------
// HTTP helper
// ---------------------------------------------------------------------------

function qualid_curl_post($url, $payload, $extra_headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $extra_headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['_error' => $err];
    $data = json_decode($raw, true);
    return ($data !== null) ? $data : ['_http_error' => $code];
}

// ---------------------------------------------------------------------------
// QUALI-D Main API — Authentication
// ---------------------------------------------------------------------------

/**
 * Step 1: Login with phone + password + Cloudflare Turnstile token.
 * Country code is hardcoded to "2" (Egypt only).
 *
 * Returns one of:
 *   ['success'=>true, 'totp_required'=>true, 'temp_token'=>'...']
 *   ['success'=>true, 'token'=>'...', 'name'=>'...', 'company_name'=>'...']
 *   ['success'=>false, 'error'=>'...']
 */
function qualid_login($phone, $password, $turnstile_token) {
    $data = qualid_curl_post(QUALID_MAIN_API . '/login', [
        'country_code'          => '2',
        'phone'                 => $phone,
        'password'              => $password,
        'platform'              => 'web',
        'device_fingerprint'    => 'freepbx_module',
        'device_name'           => 'FreePBX QUALI-D Module',
        'cf_turnstile_response' => $turnstile_token,
    ]);

    if (isset($data['_error'])) {
        return ['success' => false, 'error' => 'Network error: ' . $data['_error']];
    }
    if (!(isset($data['success']) ? $data['success'] : false)) {
        $msg = isset($data['message']) ? $data['message'] : (isset($data['error']) ? $data['error'] : 'Login failed');
        return ['success' => false, 'error' => $msg];
    }
    return $data;
}

/**
 * Step 2: Verify TOTP code (only needed when totp_required was true).
 * Returns: ['success'=>true, 'token'=>'...', 'name'=>'...', 'company_name'=>'...']
 *       or ['success'=>false, 'error'=>'...']
 */
function qualid_verify_totp($temp_token, $code) {
    $data = qualid_curl_post(QUALID_MAIN_API . '/login/verify-totp', [
        'temp_token'          => $temp_token,
        'code'                => $code,
        'platform'            => 'web',
        'device_fingerprint'  => 'freepbx_module',
        'device_name'         => 'FreePBX QUALI-D Module',
    ]);

    if (isset($data['_error'])) {
        return ['success' => false, 'error' => 'Network error: ' . $data['_error']];
    }
    if (!(isset($data['success']) ? $data['success'] : false)) {
        $msg = isset($data['message']) ? $data['message'] : 'TOTP verification failed';
        return ['success' => false, 'error' => $msg];
    }
    return $data;
}

// ---------------------------------------------------------------------------
// QUALI-D Main API — Agents
// ---------------------------------------------------------------------------

/**
 * Fetch all agents for the account (agent_role_id=1 hardcoded).
 * Returns a plain array of agent objects.
 */
function qualid_fetch_agents($token) {
    $ch = curl_init(QUALID_MAIN_API . '/agents/list');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['agent_role_id' => 1]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || !$raw) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    // Response is a direct JSON array (not wrapped in success/agents)
    if (isset($data[0])) return $data;
    return isset($data['agents']) ? $data['agents'] : [];
}

// ---------------------------------------------------------------------------
// QUALI-D Relay Server — Provisioning
// ---------------------------------------------------------------------------

/**
 * Provision the FreePBX SIP trunk via the relay server.
 * Passes the bearer token as identity (relay derives company_id from it).
 */
function qualid_provision_trunk($token) {
    $data = qualid_curl_post(QUALID_RELAY_URL . '/api/pbx/provision', [
        'bearer_token' => $token,
        'pbx_label'    => gethostname(),
    ]);

    if (isset($data['_error'])) {
        return ['success' => false, 'error' => 'Network error: ' . $data['_error']];
    }
    if (!(isset($data['success']) ? $data['success'] : false)) {
        return ['success' => false, 'error' => isset($data['error']) ? $data['error'] : 'Trunk provisioning failed'];
    }
    return ['success' => true, 'data' => $data];
}

/**
 * Provision SIP credentials for a specific agent.
 * The relay server uses agent_code as SIP username and password.
 */
function qualid_provision_agent($token, $agent_id, $agent_name, $agent_code) {
    $data = qualid_curl_post(QUALID_RELAY_URL . '/api/agent/sip-credentials', [
        'bearer_token' => $token,
        'agent_id'     => (string) $agent_id,
        'agent_name'   => $agent_name,
        'agent_code'   => $agent_code,
    ]);

    if (isset($data['_error'])) {
        return ['success' => false, 'error' => 'Network error: ' . $data['_error']];
    }
    return $data ? $data : ['success' => false, 'error' => 'No response'];
}

// ---------------------------------------------------------------------------
// Asterisk config generation
// ---------------------------------------------------------------------------

function qualid_write_asterisk_config($config) {
    qualid_write_pjsip($config);
    qualid_write_dialplan($config);
    needreload();
}

function qualid_write_pjsip($cfg) {
    $trunk_user = $cfg['trunk_user'];
    $trunk_pass = $cfg['trunk_pass'];
    $cloud_srv  = QUALID_CLOUD_SERVER;
    $cloud_ip   = QUALID_CLOUD_IP;
    $trunk_port = QUALID_CLOUD_PORT;   // 443 — SIP/TCP on port 443 bypasses Egyptian ISP blocking
    $trunk_name = QUALID_TRUNK_NAME;

    // FreePBX→Kamailio uses SIP/TLS on port 443 directly to the VPS IP.
    // Plain TCP on port 443 is blocked by Egyptian ISP DPI (detects INVITE keyword).
    // TLS encrypts the payload so DPI sees only an HTTPS-looking stream.
    // verify_server=no / verify_client=no because Kamailio uses a self-signed cert.
    $conf = <<<CONF
; =============================================================================
; QUALI-D Remote Agent — PJSIP Trunk Configuration
; Auto-generated by the qualid_remote FreePBX module. DO NOT EDIT manually.
; To reconfigure, use the QUALI-D Remote Agent admin page.
; =============================================================================

[{$trunk_name}_tls_transport]
type=transport
protocol=tls
bind=0.0.0.0
verify_server=no
verify_client=no

[{$trunk_name}_auth]
type=auth
auth_type=userpass
username={$trunk_user}
password={$trunk_pass}

[{$trunk_name}_aor]
type=aor
contact=sip:{$cloud_ip}:{$trunk_port};transport=tls

[{$trunk_name}_endpoint]
type=endpoint
transport={$trunk_name}_tls_transport
outbound_auth={$trunk_name}_auth
aors={$trunk_name}_aor
context={$trunk_name}-inbound
disallow=all
allow=ulaw
allow=alaw
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
from_user={$trunk_user}
from_domain={$cloud_srv}
CONF;

    $path = '/etc/asterisk/pjsip_qualid.conf';
    file_put_contents($path, $conf);
    qualid_ensure_include('/etc/asterisk/pjsip_custom_post.conf', 'pjsip_qualid.conf');
}

function qualid_write_dialplan($cfg) {
    $sip_domain = $cfg['sip_domain'];
    $trunk_name = QUALID_TRUNK_NAME;
    $context    = QUALID_CONTEXT;

    $conf = <<<CONF
; =============================================================================
; QUALI-D Remote Agent — Dialplan
; Auto-generated. DO NOT EDIT manually.
; =============================================================================

[{$trunk_name}-inbound]
exten => _.,1,NoOp(QUALI-D Remote Agent inbound call from \${CALLERID(all)})
 same => n,Set(CALLERID(name)=\${CALLERID(name)})
 same => n,Goto(from-trunk,\${EXTEN},1)

[{$context}]
exten => _.,1,NoOp(Routing call to remote agent \${EXTEN})
 same => n,Dial(PJSIP/\${EXTEN}@{$trunk_name}_endpoint,60,rU)
 same => n,Hangup()
CONF;

    $path = '/etc/asterisk/extensions_qualid.conf';
    file_put_contents($path, $conf);
    qualid_ensure_include('/etc/asterisk/extensions_custom.conf', 'extensions_qualid.conf');
}

function qualid_ensure_include($target_file, $include_file) {
    $include_line = '#include ' . $include_file;
    if (!file_exists($target_file)) return;
    $contents = file_get_contents($target_file);
    if (strpos($contents, $include_file) === false) {
        file_put_contents($target_file, $contents . "\n" . $include_line . "\n");
    }
}

function qualid_remove_asterisk_config() {
    $files = [
        '/etc/asterisk/pjsip_qualid.conf',
        '/etc/asterisk/extensions_qualid.conf',
    ];
    foreach ($files as $f) {
        if (file_exists($f)) unlink($f);
    }
    foreach (['/etc/asterisk/pjsip_custom_post.conf', '/etc/asterisk/extensions_custom.conf'] as $f) {
        if (!file_exists($f)) continue;
        $lines = file($f, FILE_IGNORE_NEW_LINES);
        $lines = array_filter($lines, function($line) {
            return strpos($line, 'qualid') === false;
        });
        file_put_contents($f, implode("\n", $lines) . "\n");
    }
    needreload();
}

// ---------------------------------------------------------------------------
// Utility
// ---------------------------------------------------------------------------

function qualid_connection_status() {
    $connected = qualid_get('connected', '0') === '1';
    if (!$connected) return ['status' => 'disconnected', 'label' => 'Not Connected', 'class' => 'danger'];
    if (!file_exists('/etc/asterisk/pjsip_qualid.conf')) {
        return ['status' => 'warning', 'label' => 'Config Missing', 'class' => 'warning'];
    }
    return ['status' => 'connected', 'label' => 'Connected', 'class' => 'success'];
}

// ---------------------------------------------------------------------------
// IVR AGI Helpers
// ---------------------------------------------------------------------------

/**
 * Generate a cryptographically random AGI secret (48 hex chars).
 */
function qualid_generate_agi_secret() {
    return bin2hex(random_bytes(24));
}

/**
 * Write /etc/asterisk/qualid_ivr.conf (read by the AGI script at runtime)
 * and copy the AGI script from the module's agi/ directory to the Asterisk
 * agi-bin directory.  Called automatically during qualid_complete_login().
 */
function qualid_write_agi_files($agi_secret) {
    // 1. Write the conf file (api_base + secret — the only runtime variables)
    $conf = "; QUALI-D IVR — auto-generated by the qualid_remote FreePBX module.\n"
          . "; Do NOT edit manually — reconnect via Admin → QUALI-D Remote Agent.\n"
          . "api_base=" . QUALID_MAIN_API . "\n"
          . "agi_secret=" . $agi_secret . "\n";
    file_put_contents(QUALID_IVR_CONF, $conf);

    // 2. Copy the AGI script from the module to agi-bin
    if (file_exists(QUALID_AGI_SRC)) {
        $agi_dir = dirname(QUALID_AGI_BIN);
        if (!is_dir($agi_dir)) {
            @mkdir($agi_dir, 0755, true);
        }
        copy(QUALID_AGI_SRC, QUALID_AGI_BIN);
        chmod(QUALID_AGI_BIN, 0755);
        @chown(QUALID_AGI_BIN, 'asterisk');
        @chgrp(QUALID_AGI_BIN, 'asterisk');
    }
}

/**
 * Remove the AGI conf file and the deployed AGI script.
 * Called on disconnect and uninstall.
 */
function qualid_remove_agi_files() {
    foreach ([QUALID_IVR_CONF, QUALID_AGI_BIN] as $f) {
        if (file_exists($f)) {
            unlink($f);
        }
    }
}

/**
 * Register the AGI secret with the QUALI-D cloud API so the middleware
 * can validate inbound requests from this FreePBX instance.
 * Returns the API response array.
 */
function qualid_register_agi_secret($token, $agi_secret) {
    return qualid_curl_post(
        QUALID_MAIN_API . '/ivr/set-agi-secret',
        ['agi_secret' => $agi_secret],
        ['Authorization: Bearer ' . $token]
    );
}

// ---------------------------------------------------------------------------
// Extension Management
// ---------------------------------------------------------------------------

/**
 * Get all local FreePBX extensions with PJSIP registration status.
 * Returns array of: [{extension, display_name, type, status}]
 */
function qualid_get_local_extensions() {
    global $db;
    $extensions = [];

    // Query FreePBX core users table for extension list
    $rows = $db->getAll(
        "SELECT extension, name FROM users ORDER BY extension+0",
        null, DB_FETCHMODE_ASSOC
    );
    if (!$rows || PEAR::isError($rows)) $rows = [];

    // Get registered extensions from Asterisk CLI
    $registered = qualid_get_registered_pjsip_extensions();

    foreach ($rows as $row) {
        $ext          = (string) $row['extension'];
        $extensions[] = [
            'extension'    => $ext,
            'display_name' => $row['name'],
            'name'         => $row['name'],
            'type'         => 'pjsip',
            'status'       => isset($registered[$ext]) ? 'online' : 'offline',
        ];
    }

    return $extensions;
}

/**
 * Parse `asterisk -rx "pjsip show contacts"` to find registered (available) extensions.
 * Returns associative array: ['1001' => true, ...]
 */
function qualid_get_registered_pjsip_extensions() {
    $registered = [];
    $output = @shell_exec('asterisk -rx "pjsip show contacts" 2>/dev/null');
    if (!$output) return $registered;

    foreach (explode("\n", $output) as $line) {
        // Lines look like:  " 1001/sip:1001@...    <hash>  3600  Avail  0.500"
        // An extension is "online" if its contact line contains "Avail" but NOT "Unavail"
        if (preg_match('/^\s+(\d{2,6})\s*[\/:]/', $line, $m)) {
            if (stripos($line, 'Avail') !== false && stripos($line, 'Unavail') === false) {
                $registered[$m[1]] = true;
            }
        }
    }

    return $registered;
}

/**
 * Create a new local FreePBX PJSIP extension via FreePBX Core BMO.
 * Returns ['success'=>true] or ['success'=>false, 'error'=>'...']
 */
function qualid_create_extension($extension, $display_name, $secret) {
    if (!preg_match('/^\d{2,6}$/', $extension)) {
        return ['success' => false, 'error' => 'Extension must be 2-6 digits'];
    }

    global $db;
    $existing = $db->getOne(
        "SELECT extension FROM users WHERE extension = " . $db->quote($extension)
    );
    if ($existing) {
        return ['success' => false, 'error' => 'Extension ' . $extension . ' already exists'];
    }

    try {
        $fpbx = FreePBX::create();
        $fpbx->Core->addUser($extension, $display_name, [
            'tech'   => 'pjsip',
            'secret' => $secret,
        ]);
        needreload();
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Delete a local FreePBX extension.
 */
function qualid_delete_extension($extension) {
    try {
        FreePBX::create()->Core->delUser($extension);
        needreload();
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Push the current extension list to the QUALI-D cloud API.
 * Stores last_sync_at timestamp in config.json on success.
 */
function qualid_sync_extensions_to_qualid($token, $extensions) {
    $result = qualid_curl_post(
        QUALID_MAIN_API . '/extensions/sync',
        ['extensions' => $extensions],
        ['Authorization: Bearer ' . $token]
    );
    if (isset($result['success']) && $result['success']) {
        qualid_set('last_sync_at', date('Y-m-d H:i:s'));
    }
    return $result;
}

/**
 * Send a heartbeat ping to QUALI-D so the cloud knows FreePBX is alive.
 */
function qualid_send_heartbeat($token) {
    return qualid_curl_post(
        QUALID_MAIN_API . '/ivr/heartbeat',
        [],
        ['Authorization: Bearer ' . $token]
    );
}

/**
 * Run all IVR connectivity checks:
 *   1. Can we reach the QUALI-D API?
 *   2. Is the AGI script deployed?
 *   3. Is the AGI secret set?
 * Returns a status array consumed by the IVR Status card.
 */
function qualid_test_ivr_connection() {
    $token      = qualid_get('token', '');
    $agi_secret = qualid_get('agi_secret', '');
    $last_sync  = qualid_get('last_sync_at', '');

    $api_ok = false;
    if ($token) {
        $ch = curl_init(QUALID_MAIN_API . '/ivr/connection-status');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $api_ok = ($code >= 200 && $code < 300);
        curl_close($ch);
    }

    return [
        'api_reachable'  => $api_ok,
        'agi_deployed'   => file_exists(QUALID_AGI_BIN) && file_exists(QUALID_IVR_CONF),
        'agi_secret_set' => !empty($agi_secret),
        'last_sync'      => $last_sync,
    ];
}
