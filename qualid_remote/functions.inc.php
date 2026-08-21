<?php
/**
 * Quali-D Connect — FreePBX Module Helper Functions
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
define('QUALID_GITHUB_REPO',   'ramyzeidan/qualid-freepbx-module');
define('QUALID_MODULE_DIR',    dirname(__FILE__));

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
        'agi_secret'         => qualid_get('agi_secret',         ''),
    ];
}

// ---------------------------------------------------------------------------
// HTTP helper
// ---------------------------------------------------------------------------

/**
 * Resolve the relay hostname to an IP address, bypassing the system DNS.
 * Strategy (automatic, no CLI required):
 *   1. PHP's built-in gethostbyname() — works on most systems.
 *   2. Cloudflare DNS-over-HTTPS (1.1.1.1) — always reachable over HTTPS;
 *      works even when the system DNS doesn't resolve .xyz TLD domains.
 *   3. Last resort: the known VPS IP constant (QUALID_CLOUD_IP).
 * The resolved IP is cached in config.json for 1 hour so this adds no
 * latency to normal requests.
 */
function qualid_resolve_relay_ip() {
    $host = 'qualidapi1.1215515.xyz';

    // Check 1-hour cache
    $cached = qualid_get('relay_ip_cache', null);
    if (is_array($cached)
        && !empty($cached['ip'])
        && !empty($cached['at'])
        && (time() - (int) $cached['at']) < 3600) {
        return $cached['ip'];
    }

    // Try system resolver first (fast)
    $ip = gethostbyname($host);
    if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
        qualid_set('relay_ip_cache', array('ip' => $ip, 'at' => time()));
        return $ip;
    }

    // Fall back to Cloudflare DNS-over-HTTPS — no system DNS needed,
    // connects to 1.1.1.1 by IP so no DNS required for this call itself.
    $ch = curl_init('https://1.1.1.1/dns-query?name=' . rawurlencode($host) . '&type=A');
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER     => array('Accept: application/dns-json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $raw = curl_exec($ch);
    curl_close($ch);

    $dns = json_decode($raw, true);
    if (!empty($dns['Answer']) && is_array($dns['Answer'])) {
        foreach ($dns['Answer'] as $rec) {
            if (isset($rec['type']) && (int) $rec['type'] === 1
                && isset($rec['data'])
                && filter_var($rec['data'], FILTER_VALIDATE_IP)) {
                $ip = $rec['data'];
                qualid_set('relay_ip_cache', array('ip' => $ip, 'at' => time()));
                return $ip;
            }
        }
    }

    // Last resort: hardcoded VPS IP
    return QUALID_CLOUD_IP;
}

/**
 * Build a CURLOPT_RESOLVE list that pins our .xyz hostnames to their real IP,
 * bypassing whatever system DNS is configured on this machine.
 */
function qualid_curl_resolve_list() {
    $ip = qualid_resolve_relay_ip();
    return array(
        'qualidapi1.1215515.xyz:443:' . $ip,
        'qualidsip1.1215515.xyz:443:'  . $ip,
    );
}

function qualid_curl_get($url, $extra_headers = array()) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => array_merge(array('Accept: application/json'), $extra_headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_RESOLVE        => qualid_curl_resolve_list(),
    ));
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return array('_error' => $err);
    $data = json_decode($raw, true);
    return ($data !== null) ? $data : array('_http_error' => $code);
}

function qualid_curl_post($url, $payload, $extra_headers = array()) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => array_merge(array('Content-Type: application/json'), $extra_headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_RESOLVE        => qualid_curl_resolve_list(),
    ));
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return array('_error' => $err);
    $data = json_decode($raw, true);
    return ($data !== null) ? $data : array('_http_error' => $code);
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
        'device_name'           => 'Quali-D Connect',
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
        'device_name'         => 'Quali-D Connect',
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
// QUALI-D Relay Server — Trunk Provisioning
// ---------------------------------------------------------------------------

/**
 * Provision the FreePBX SIP trunk via the relay server.
 * Uses qualid_curl_resolve_list() to bypass system DNS for the .xyz hostname.
 */
function qualid_provision_trunk($token) {
    $ch = curl_init(QUALID_RELAY_URL . '/api/pbx/provision');
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(array('bearer_token' => $token, 'pbx_label' => gethostname())),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_RESOLVE        => qualid_curl_resolve_list(),
    ));
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return array('success' => false, 'error' => 'Relay server unreachable: ' . $err);
    }

    $data = json_decode($raw, true);
    if (!$data) {
        return array('success' => false, 'error' => 'Relay server returned an unexpected response.');
    }
    if (!(isset($data['success']) && $data['success'])) {
        return array('success' => false, 'error' => isset($data['error']) ? $data['error'] : 'Trunk provisioning failed');
    }
    return array('success' => true, 'data' => $data);
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
; Quali-D Connect — PJSIP Trunk Configuration
; Auto-generated by the qualid_remote FreePBX module. DO NOT EDIT manually.
; To reconfigure, use the Quali-D Connect admin page.
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
; Quali-D Connect — Dialplan
; Auto-generated. DO NOT EDIT manually.
; =============================================================================

[{$trunk_name}-inbound]
exten => _.,1,NoOp(Quali-D Connect inbound call from \${CALLERID(all)})
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
          . "; Do NOT edit manually — reconnect via Admin → Quali-D Connect.\n"
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
 * Push this FreePBX server's own IP/hostname to the QUALI-D cloud so agents
 * know which host to register their SIP extensions against.
 * Uses SERVER_ADDR when available, falls back to gethostbyname(gethostname()).
 * Non-fatal — failure is logged but does not block login.
 */
function qualid_push_pbx_host($token) {
    $host = !empty($_SERVER['SERVER_ADDR'])
        ? $_SERVER['SERVER_ADDR']
        : gethostbyname(gethostname());

    return qualid_curl_post(
        QUALID_MAIN_API . '/company/pbx-host',
        ['pbx_host' => $host, 'pbx_port' => 5060],
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

// ---------------------------------------------------------------------------
// CDR Sync
// ---------------------------------------------------------------------------

/**
 * Fetch CDR records from the local asteriskcdrdb.cdr table.
 * Uses a cross-database PDO query — the FreePBX MySQL user has SELECT on asteriskcdrdb.
 * If $since is provided (Y-m-d H:i:s string), only newer records are returned.
 * Returns up to 500 rows per call (incremental, so this is usually far fewer).
 */
function qualid_get_cdr_since($since = null) {
    try {
        $pdo = FreePBX::create()->Database;
        if ($since) {
            $stmt = $pdo->prepare(
                "SELECT uniqueid, calldate, src, dst, duration, billsec, disposition
                 FROM asteriskcdrdb.cdr
                 WHERE calldate > ?
                 ORDER BY calldate ASC
                 LIMIT 500"
            );
            $stmt->execute([$since]);
        } else {
            // First sync: grab last 500 records (most recent)
            $stmt = $pdo->prepare(
                "SELECT uniqueid, calldate, src, dst, duration, billsec, disposition
                 FROM asteriskcdrdb.cdr
                 ORDER BY calldate DESC
                 LIMIT 500"
            );
            $stmt->execute();
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }
    return is_array($rows) ? $rows : [];
}

/**
 * Push new CDR records to the QUALI-D cloud API (incremental — since last sync).
 * Stores last_cdr_sync_at in config.json on success.
 */
function qualid_sync_cdr_to_qualid($token) {
    $lastSync = qualid_get('last_cdr_sync_at', null);
    $rows     = qualid_get_cdr_since($lastSync);

    if (empty($rows)) {
        return ['success' => true, 'synced' => 0, 'message' => 'No new records'];
    }

    $result = qualid_curl_post(
        QUALID_MAIN_API . '/cdr/sync',
        ['records' => $rows],
        ['Authorization: Bearer ' . $token]
    );

    if (isset($result['success']) && $result['success']) {
        qualid_set('last_cdr_sync_at', date('Y-m-d H:i:s'));
    }

    return array_merge(
        is_array($result) ? $result : ['success' => false],
        ['record_count' => count($rows)]
    );
}

/**
 * Read all local extensions from FreePBX's own devices + users tables,
 * get live PJSIP registration status via the Asterisk CLI, then push
 * the list to POST /extensions/sync.
 *
 * Works on any FreePBX installation — no realtime DB tables required.
 * Registration status is fetched internally via `asterisk -rx` (the web
 * user on FreePBX always has permission to run this command).
 */
function qualid_sync_extensions($token) {
    try {
        $pdo = FreePBX::create()->Database;

        // FreePBX stores extensions in the `devices` table (always present).
        // JOIN `users` to get the friendly display name.
        $stmt = $pdo->query(
            "SELECT d.id AS extension,
                    d.tech AS type,
                    COALESCE(u.name, d.id) AS display_name
             FROM devices d
             LEFT JOIN users u ON u.extension = d.id
             WHERE d.id REGEXP '^[0-9]+$'
             ORDER BY CAST(d.id AS UNSIGNED)"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'DB error: ' . $e->getMessage()];
    }

    if (empty($rows)) {
        return ['success' => true, 'synced' => 0, 'message' => 'No extensions found'];
    }

    // Get live PJSIP registration status via Asterisk CLI.
    // Output format: "  101/sip:101@x.x.x.x   Avail   0.500 ..."
    $contacts_raw = @shell_exec('asterisk -rx "pjsip show contacts" 2>/dev/null') ?: '';
    $online = [];
    if (!empty($contacts_raw) &&
        preg_match_all('/^\s*(\d+)\/[^\s]+\s+Avail/m', $contacts_raw, $m)) {
        $online = array_flip($m[1]);
    }

    $extensions = [];
    foreach ($rows as $row) {
        $ext  = $row['extension'];
        $tech = strtolower($row['type']);
        if ($tech === 'pjsip') {
            $status = isset($online[$ext]) ? 'online' : 'offline';
        } else {
            $status = 'unknown';   // legacy SIP / IAX2 — status not checked
        }
        $extensions[] = [
            'extension'    => $ext,
            'display_name' => $row['display_name'],
            'type'         => $tech,
            'status'       => $status,
        ];
    }

    $result = qualid_curl_post(
        QUALID_MAIN_API . '/extensions/sync',
        ['extensions' => $extensions],
        ['Authorization: Bearer ' . $token]
    );

    return is_array($result) ? $result : ['success' => false, 'error' => 'Invalid API response'];
}

// ---------------------------------------------------------------------------
// Queue sync — fetch queue config from QUALI-D API, write to FreePBX DB
// (so queues appear in the native FreePBX UI) and to queues_qualid.conf
// (so Asterisk picks them up immediately without waiting for Apply Config).
// ---------------------------------------------------------------------------

/**
 * Fetch all queues from the QUALI-D API, push them into FreePBX's own queue
 * database tables (queues + queue_details), and write queues_qualid.conf for
 * immediate Asterisk activation.
 */
function qualid_sync_queues($token) {
    $result = qualid_curl_get(
        QUALID_MAIN_API . '/ivr/queues/asterisk-config',
        array('Authorization: Bearer ' . $token)
    );

    if (isset($result['_error'])) {
        return array('success' => false, 'error' => 'Network error: ' . $result['_error']);
    }
    if (empty($result['success'])) {
        return array('success' => false, 'error' => 'API error fetching queue config');
    }

    $queues = isset($result['queues']) ? $result['queues'] : array();

    // Write Asterisk conf for immediate effect (before any Apply Config click)
    qualid_write_queues($queues);

    // Write into FreePBX's own DB so queues appear in display=queues
    qualid_sync_queues_to_freepbx_db($queues);

    return array('success' => true, 'queues' => count($queues));
}

/**
 * Upsert queues into FreePBX's native queue tables so they appear in
 * Admin → Queues (display=queues) alongside any FreePBX-created queues.
 * Cleans up queues that were previously synced but have since been deleted.
 * Non-fatal — if the DB write fails the Asterisk conf still works.
 */
function qualid_sync_queues_to_freepbx_db($queues) {
    try {
        $pdo = FreePBX::create()->Database;
    } catch (Exception $e) {
        return; // FreePBX PDO unavailable
    }

    try {
        $prev_names = qualid_get('synced_queue_names', array());
        if (!is_array($prev_names)) { $prev_names = array(); }
        $current_names = array();

        foreach ($queues as $q) {
            $name = preg_replace('/[^0-9]/', '', $q['name']);
            if (!$name) continue;
            $current_names[] = $name;

            $display = isset($q['display_name']) ? $q['display_name'] : $name;

            // Upsert the queue row.
            // dest = 'app-blackhole,hangup,1' silences FreePBX's "no failover
            // destination" UI warning. Actual failover routing is handled by
            // the IVR AGI flow, so this value is never reached in practice.
            $stmt = $pdo->prepare(
                "INSERT INTO queues_config (extension, descr, dest)
                 VALUES (?, ?, 'app-blackhole,hangup,1')
                 ON DUPLICATE KEY UPDATE descr = VALUES(descr)"
            );
            $stmt->execute(array($name, $display));

            // Replace queue setting rows only — leave any existing 'member' rows
            // intact so that agents added via the FreePBX UI are not wiped.
            $pdo->prepare("DELETE FROM queues_details WHERE id = ? AND keyword != 'member'")->execute(array($name));

            $details = array(
                'strategy'          => isset($q['strategy'])      ? $q['strategy']      : 'rrmemory',
                'timeout'           => isset($q['timeout'])       ? (int) $q['timeout'] : 30,
                'maxlen'            => isset($q['maxlen'])        ? (int) $q['maxlen']  : 0,
                'wrapuptime'        => isset($q['wrapuptime'])    ? (int) $q['wrapuptime'] : 0,
                'musicclass'        => isset($q['music_on_hold']) ? $q['music_on_hold'] : 'default',
                'announce-holdtime' => !empty($q['announce_holdtime']) ? 'yes' : 'no',
                'announce-position' => !empty($q['announce_position']) ? 'yes' : 'no',
            );

            $ins = $pdo->prepare(
                "INSERT INTO queues_details (id, keyword, data, flags) VALUES (?, ?, ?, 0)"
            );
            foreach ($details as $key => $val) {
                $ins->execute(array($name, $key, (string) $val));
            }

            // Upgrade any pre-existing member rows written with flags=0 to
            // flags=1 so they appear in FreePBX's Static Agents tab.
            // This runs on every sync, so no CLI command is needed.
            $pdo->prepare(
                "UPDATE queues_details SET flags=1 WHERE id=? AND keyword='member' AND flags=0"
            )->execute(array($name));

            // Members — only replace when the API provides at least one member.
            // If the API omits 'members' or sends an empty array (no agents
            // assigned yet), the existing member rows are preserved so that
            // agents added manually via the FreePBX UI are not wiped.
            // flags=1 matches what FreePBX_Helpers::setConfig() writes for
            // user-defined rows — the Static Agents tab filters on this value.
            if (!empty($q['members'])) {
                $pdo->prepare("DELETE FROM queues_details WHERE id = ? AND keyword = 'member'")->execute(array($name));
                $mem_ins = $pdo->prepare(
                    "INSERT INTO queues_details (id, keyword, data, flags) VALUES (?, 'member', ?, 1)"
                );
                foreach ($q['members'] as $ext) {
                    $clean = preg_replace('/[^0-9]/', '', $ext);
                    if ($clean) {
                        $mem_ins->execute(array($name, 'Local/' . $clean . '@from-internal/n'));
                    }
                }
            }
        }

        // Remove from FreePBX DB any queues we previously synced that are now gone
        $removed = array_diff($prev_names, $current_names);
        foreach ($removed as $old) {
            $pdo->prepare("DELETE FROM queues_config  WHERE extension = ?")->execute(array($old));
            $pdo->prepare("DELETE FROM queues_details WHERE id        = ?")->execute(array($old));
        }

        qualid_set('synced_queue_names', $current_names);

        // Mark FreePBX as needing Apply Config (updates the banner in the UI)
        if (function_exists('needreload')) { needreload(); }

    } catch (Exception $e) {
        // Non-fatal
    }
}

/**
 * Write /etc/asterisk/queues_qualid.conf from the array returned by
 * GET /ivr/queues/asterisk-config and ensure it is #included from
 * queues_custom.conf (which Asterisk loads automatically).
 */
function qualid_write_queues($queues) {
    $conf = "; =============================================================================\n"
          . "; Quali-D Connect — Queue Configuration\n"
          . "; Auto-generated by the qualid_remote FreePBX module. DO NOT EDIT manually.\n"
          . "; To manage queues, use the QUALI-D dashboard.\n"
          . "; =============================================================================\n\n";

    foreach ($queues as $q) {
        $name = preg_replace('/[^0-9]/', '', $q['name']);
        if (!$name) continue;

        $conf .= "[{$name}]\n";
        $conf .= 'strategy='          . (isset($q['strategy'])      ? $q['strategy']      : 'rrmemory') . "\n";
        $conf .= 'timeout='           . ((int) (isset($q['timeout'])    ? $q['timeout']    : 30))        . "\n";
        $conf .= 'maxlen='            . ((int) (isset($q['maxlen'])     ? $q['maxlen']     : 0))         . "\n";
        $conf .= 'wrapuptime='        . ((int) (isset($q['wrapuptime']) ? $q['wrapuptime'] : 0))         . "\n";
        $conf .= 'musicclass='        . (isset($q['music_on_hold'])  ? $q['music_on_hold'] : 'default')  . "\n";
        $conf .= 'announce-holdtime=' . (!empty($q['announce_holdtime']) ? 'yes' : 'no') . "\n";
        $conf .= 'announce-position=' . (!empty($q['announce_position']) ? 'yes' : 'no') . "\n";
        $members = isset($q['members']) ? $q['members'] : array();
        foreach ($members as $ext) {
            $clean = preg_replace('/[^0-9]/', '', $ext);
            if ($clean) {
                $conf .= 'member => Local/' . $clean . "@from-internal/n\n";
            }
        }
        $conf .= "\n";
    }

    file_put_contents('/etc/asterisk/queues_qualid.conf', $conf);
    qualid_ensure_include('/etc/asterisk/queues_custom.conf', 'queues_qualid.conf');

    // Reload just the queue module — no full Asterisk restart needed.
    // This takes effect immediately without any manual Apply Config click.
    @shell_exec('asterisk -rx "module reload app_queue.so" 2>/dev/null');
}

/**
 * Remove the queue conf file, its #include line, and all queue records from
 * FreePBX's own database.  Called on uninstall and disconnect.
 */
function qualid_remove_queues() {
    // Remove Asterisk custom conf
    $f = '/etc/asterisk/queues_qualid.conf';
    if (file_exists($f)) unlink($f);

    $custom = '/etc/asterisk/queues_custom.conf';
    if (file_exists($custom)) {
        $lines = file($custom, FILE_IGNORE_NEW_LINES);
        $lines = array_filter($lines, function($line) {
            return strpos($line, 'queues_qualid') === false;
        });
        file_put_contents($custom, implode("\n", $lines) . "\n");
    }

    // Remove from FreePBX DB so they disappear from display=queues
    $prev_names = qualid_get('synced_queue_names', array());
    if (is_array($prev_names) && !empty($prev_names)) {
        try {
            $pdo = FreePBX::create()->Database;
            foreach ($prev_names as $name) {
                $pdo->prepare("DELETE FROM queues_config  WHERE extension = ?")->execute(array($name));
                $pdo->prepare("DELETE FROM queues_details WHERE id       = ?")->execute(array($name));
            }
        } catch (Exception $e) {
            // Non-fatal
        }
        qualid_set('synced_queue_names', array());
    }
}

// ---------------------------------------------------------------------------
// FreePBX reload hook
// ---------------------------------------------------------------------------

/**
 * Called automatically by FreePBX on every Apply Config / fwconsole reload.
 *
 * FreePBX's DialplanHooks processor scans all active modules for a function
 * named {rawname}_get_config() and calls it at priority 100. This is the
 * flat-PHP hook that reliably fires during config generation — no BMO class,
 * no cron, no manual steps required.
 *
 * We return null (no dialplan contribution) and use this as a side-channel
 * to push the latest extension list, CDR, PBX host, and heartbeat to the
 * QUALI-D cloud.
 */
function qualid_remote_get_config() {
    $token = qualid_get('token', '');
    if (empty($token)) return null;
    qualid_set('last_apply_config_at', date('Y-m-d H:i:s'));
    qualid_push_pbx_host($token);
    qualid_sync_extensions($token);
    qualid_sync_queues($token);
    qualid_sync_cdr_to_qualid($token);
    qualid_send_heartbeat($token);

    // Write a cron job so syncing continues automatically every 5 minutes
    // without requiring a page visit.  This hook runs as root so it has
    // permission to write to /etc/cron.d/.
    qualid_write_cron();

    return null;   // no dialplan entries to contribute
}

/**
 * Write /etc/cron.d/qualid_remote so the background sync script runs every
 * 5 minutes as the asterisk user.  Safe to call multiple times — idempotent.
 */
function qualid_write_cron() {
    $sync_script = dirname(__FILE__) . '/sync.php';
    $php_bin     = PHP_BINARY ?: '/usr/bin/php';
    $cron_file   = '/etc/cron.d/qualid_remote';
    $content     = "# Quali-D Connect — automatic sync (written by qualid_remote_get_config)\n"
                 . "*/5 * * * * asterisk {$php_bin} {$sync_script} > /dev/null 2>&1\n";

    @file_put_contents($cron_file, $content);
    @chmod($cron_file, 0644);
}

/**
 * Remove the cron job written by qualid_write_cron().
 * Called on disconnect and uninstall.
 */
function qualid_remove_cron() {
    $cron_file = '/etc/cron.d/qualid_remote';
    if (file_exists($cron_file)) {
        @unlink($cron_file);
    }
}

// ---------------------------------------------------------------------------
// Self-update from GitHub releases
// ---------------------------------------------------------------------------

/**
 * Check GitHub for a newer release than the currently installed version.
 */
function qualid_check_github_update() {
    $xml = @simplexml_load_file(QUALID_MODULE_DIR . '/module.xml');
    $currentVersion = $xml ? trim((string) $xml->version) : '0.0.0';

    $ch = curl_init('https://api.github.com/repos/' . QUALID_GITHUB_REPO . '/releases/latest');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: qualid-freepbx-module'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || ($httpCode !== 200 && $httpCode !== 404)) {
        return ['success' => false, 'error' => 'Could not reach GitHub (HTTP ' . $httpCode . ')'];
    }
    if ($httpCode === 404) {
        return ['success' => true, 'update_available' => false, 'current_version' => $currentVersion,
                'latest_version' => $currentVersion, 'message' => 'No releases published yet'];
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['tag_name'])) {
        return ['success' => false, 'error' => 'Invalid response from GitHub'];
    }

    $latestVersion  = ltrim($data['tag_name'], 'v');
    $updateAvailable = version_compare($latestVersion, $currentVersion, '>');

    return [
        'success'          => true,
        'current_version'  => $currentVersion,
        'latest_version'   => $latestVersion,
        'update_available' => $updateAvailable,
    ];
}

/**
 * Download the latest release tar.gz from GitHub and extract it in place.
 */
function qualid_do_github_update() {
    $downloadUrl = 'https://github.com/' . QUALID_GITHUB_REPO . '/releases/latest/download/qualid_remote.tar.gz';
    $modulesDir  = dirname(QUALID_MODULE_DIR); // /var/www/html/admin/modules
    $tmp         = sys_get_temp_dir() . '/qualid_update_' . time() . '.tar.gz';

    // Download
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: qualid-freepbx-module'],
    ]);
    $data     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$data || $httpCode !== 200) {
        return ['success' => false, 'error' => 'Download failed (HTTP ' . $httpCode . ')'];
    }
    if (file_put_contents($tmp, $data) === false) {
        return ['success' => false, 'error' => 'Cannot write temp file'];
    }

    // Extract — try shell tar first, fall back to PharData
    $extracted = false;
    $out = @shell_exec('tar -xzf ' . escapeshellarg($tmp) . ' -C ' . escapeshellarg($modulesDir) . ' 2>&1');
    if (file_exists(QUALID_MODULE_DIR . '/module.xml')) {
        $extracted = true;
    }

    if (!$extracted) {
        try {
            $phar = new PharData($tmp);
            $phar->extractTo($modulesDir, null, true);
            $extracted = file_exists(QUALID_MODULE_DIR . '/module.xml');
        } catch (Exception $e) {
            @unlink($tmp);
            return ['success' => false, 'error' => 'Extraction failed: ' . $e->getMessage()];
        }
    }

    @unlink($tmp);

    if (!$extracted) {
        return ['success' => false, 'error' => 'Extraction produced no files'];
    }

    needreload();
    return ['success' => true];
}
