<?php
/**
 * QUALI-D Remote Agent — FreePBX Module Helper Functions
 * Handles config storage, QUALI-D API calls, and Asterisk config generation.
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define('QUALID_API_BASE',     'https://qualidapi1.1215515.xyz');
define('QUALID_CLOUD_SERVER', 'qualidsip1.1215515.xyz');
define('QUALID_CLOUD_PORT',   443);
define('QUALID_TRUNK_NAME',   'QualidRemote');
define('QUALID_CONTEXT',      'qualid-remote-agents');

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
        'api_key'        => qualid_get('api_key',        ''),
        'company_id'     => qualid_get('company_id',     ''),
        'sip_domain'     => qualid_get('sip_domain',     ''),
        'trunk_user'     => qualid_get('trunk_user',     ''),
        'trunk_pass'     => qualid_get('trunk_pass',     ''),
        'turn_server'    => qualid_get('turn_server',    ''),
        'connected'      => qualid_get('connected',      '0'),
        'connected_at'   => qualid_get('connected_at',   ''),
        'last_error'     => qualid_get('last_error',     ''),
    ];
}

// ---------------------------------------------------------------------------
// QUALI-D Cloud API calls
// ---------------------------------------------------------------------------

/**
 * Calls POST /api/pbx/provision on the QUALI-D cloud
 * Returns array with success + data, or success=false + error
 */
function qualid_provision($api_key) {
    $payload = json_encode([
        'api_key'   => $api_key,
        'pbx_label' => gethostname(),
    ]);

    $ch = curl_init(QUALID_API_BASE . '/api/pbx/provision');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'error' => 'Network error: ' . $err];
    }

    $data = json_decode($raw, true);
    if (!$data) {
        return ['success' => false, 'error' => 'Invalid response from QUALI-D API (HTTP ' . $code . ')'];
    }
    if (!(isset($data['success']) ? $data['success'] : false)) {
        return ['success' => false, 'error' => isset($data['error']) ? $data['error'] : 'Unknown API error'];
    }

    return ['success' => true, 'data' => $data];
}

/**
 * Fetch list of agents for this company from QUALI-D API
 */
function qualid_get_agents($api_key) {
    $ch = curl_init(QUALID_API_BASE . '/api/agents');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-PBX-API-Key: ' . $api_key,
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
    return isset($data['agents']) ? $data['agents'] : [];
}

/**
 * Provision a SIP account for a specific agent
 */
function qualid_provision_agent($api_key, $agent_id, $agent_name) {
    $payload = json_encode([
        'api_key'    => $api_key,
        'agent_id'   => $agent_id,
        'agent_name' => $agent_name,
    ]);

    $ch = curl_init(QUALID_API_BASE . '/api/agent/sip-credentials');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($raw, true);
    return $data ? $data : ['success' => false, 'error' => 'No response'];
}

// ---------------------------------------------------------------------------
// Asterisk config generation
// ---------------------------------------------------------------------------

/**
 * Write pjsip trunk config and dialplan for remote agents
 * Called after successful provision
 */
function qualid_write_asterisk_config($config) {
    qualid_write_pjsip($config);
    qualid_write_dialplan($config);

    // Tell FreePBX a reload is needed
    needreload();
}

function qualid_write_pjsip($cfg) {
    $sip_domain  = $cfg['sip_domain'];
    $trunk_user  = $cfg['trunk_user'];
    $trunk_pass  = $cfg['trunk_pass'];
    $cloud_srv   = QUALID_CLOUD_SERVER;
    $cloud_port  = QUALID_CLOUD_PORT;
    $trunk_name  = QUALID_TRUNK_NAME;

    $conf = <<<CONF
; =============================================================================
; QUALI-D Remote Agent — PJSIP Trunk Configuration
; Auto-generated by the qualid_remote FreePBX module. DO NOT EDIT manually.
; To reconfigure, use the QUALI-D Remote Agent admin page.
; =============================================================================

[qualid_wss_transport]
type=transport
protocol=wss
bind=0.0.0.0

[{$trunk_name}]
type=registration
transport=qualid_wss_transport
outbound_auth={$trunk_name}_auth
server_uri=sip:{$cloud_srv}:{$cloud_port};transport=wss
client_uri=sip:{$trunk_user}@{$sip_domain}
retry_interval=60
expiration=3600
contact_user={$trunk_user}

[{$trunk_name}_auth]
type=auth
auth_type=userpass
username={$trunk_user}
password={$trunk_pass}

[{$trunk_name}_aor]
type=aor
contact=sip:{$cloud_srv}:{$cloud_port};transport=wss

[{$trunk_name}_endpoint]
type=endpoint
transport=qualid_wss_transport
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
from_domain={$sip_domain}
CONF;

    $path = '/etc/asterisk/pjsip_qualid.conf';
    file_put_contents($path, $conf);

    // Include in pjsip.conf if not already there
    qualid_ensure_include('/etc/asterisk/pjsip.conf', 'pjsip_qualid.conf');
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

; Inbound context — calls arriving from the QUALI-D cloud to this PBX
[{$trunk_name}-inbound]
exten => _.,1,NoOp(QUALI-D Remote Agent inbound call from \${CALLERID(all)})
 same => n,Set(CALLERID(name)=\${CALLERID(name)})
 same => n,Goto(from-trunk,\${EXTEN},1)

; Remote agent routing context
; Add this context to your ring groups / queues when the target agent is remote:
;   DIAL(PJSIP/agent_42@{$trunk_name}_endpoint)
[{$context}]
exten => _agent_.,1,NoOp(Routing call to remote agent \${EXTEN})
 same => n,Dial(PJSIP/\${EXTEN}@{$sip_domain},{$trunk_name}_endpoint,60,rU)
 same => n,Hangup()
CONF;

    $path = '/etc/asterisk/extensions_qualid.conf';
    file_put_contents($path, $conf);

    // Include in extensions_custom.conf if not already there
    qualid_ensure_include('/etc/asterisk/extensions_custom.conf', 'extensions_qualid.conf');
}

/**
 * Ensure an #include line exists in a target Asterisk config file
 */
function qualid_ensure_include($target_file, $include_file) {
    $include_line = '#include ' . $include_file;
    if (!file_exists($target_file)) return;

    $contents = file_get_contents($target_file);
    if (strpos($contents, $include_file) === false) {
        file_put_contents($target_file, $contents . "\n" . $include_line . "\n");
    }
}

/**
 * Remove QUALI-D configs and #include lines on uninstall
 */
function qualid_remove_asterisk_config() {
    $files = [
        '/etc/asterisk/pjsip_qualid.conf',
        '/etc/asterisk/extensions_qualid.conf',
    ];
    foreach ($files as $f) {
        if (file_exists($f)) unlink($f);
    }

    // Remove include lines
    foreach (['/etc/asterisk/pjsip.conf', '/etc/asterisk/extensions_custom.conf'] as $f) {
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

function qualid_mask_key($key) {
    if (strlen($key) < 8) return '••••••••';
    return substr($key, 0, 4) . str_repeat('•', strlen($key) - 8) . substr($key, -4);
}

function qualid_connection_status() {
    $connected = qualid_get('connected', '0') === '1';
    if (!$connected) return ['status' => 'disconnected', 'label' => 'Not Connected', 'class' => 'danger'];

    // Verify connection is still valid by checking trunk file exists
    if (!file_exists('/etc/asterisk/pjsip_qualid.conf')) {
        return ['status' => 'warning', 'label' => 'Config Missing', 'class' => 'warning'];
    }

    return ['status' => 'connected', 'label' => 'Connected', 'class' => 'success'];
}
