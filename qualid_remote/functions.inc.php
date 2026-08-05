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
define('QUALID_CLOUD_IP',      '13.140.143.85');  // VPS direct IP — bypasses Cloudflare for SIP/UDP trunk
define('QUALID_CLOUD_SIP_PORT', 5060);
define('QUALID_TRUNK_NAME',    'QualidRemote');
define('QUALID_CONTEXT',       'qualid-remote-agents');

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
    $sip_port   = QUALID_CLOUD_SIP_PORT;
    $trunk_name = QUALID_TRUNK_NAME;

    // FreePBX→Kamailio uses plain SIP/UDP directly to the VPS IP (bypassing Cloudflare).
    // The outbound_proxy routes packets to the VPS while the AOR contact keeps the SIP
    // domain intact so Kamailio's auth lookup finds the trunk subscriber correctly.
    // Agents still connect via WSS through Cloudflare — only the trunk link changes.
    $conf = <<<CONF
; =============================================================================
; QUALI-D Remote Agent — PJSIP Trunk Configuration
; Auto-generated by the qualid_remote FreePBX module. DO NOT EDIT manually.
; To reconfigure, use the QUALI-D Remote Agent admin page.
; =============================================================================

[{$trunk_name}_auth]
type=auth
auth_type=userpass
username={$trunk_user}
password={$trunk_pass}

[{$trunk_name}_aor]
type=aor
contact=sip:{$cloud_srv}:{$sip_port}

[{$trunk_name}_endpoint]
type=endpoint
transport=0.0.0.0-udp
outbound_auth={$trunk_name}_auth
outbound_proxy=sip:{$cloud_ip}:{$sip_port};lr
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
