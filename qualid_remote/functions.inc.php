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
 *
 * Only qualidsip1 is pinned here (used by Asterisk trunk, connects directly to
 * the VPS on port 443 where Kamailio listens for TLS/SIP).
 *
 * qualidapi1 is intentionally NOT pinned — it routes through the Cloudflare
 * tunnel to Node.js:3000 and is NOT directly accessible on the VPS IP:443.
 * Pinning it to the VPS IP would cause API calls to fail when system DNS works
 * but falls back to the VPS IP as last resort.
 */
function qualid_curl_resolve_list() {
    $ip = qualid_resolve_relay_ip();
    return array(
        'qualidsip1.1215515.xyz:443:' . $ip,
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
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_TLSv1_2,
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
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_TLSv1_2,
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
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_TLSv1_2,
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
    qualid_write_rtp_conf();
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
method=tlsv1_2
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
qualify_frequency=60

[{$trunk_name}_endpoint]
type=endpoint
transport={$trunk_name}_tls_transport
outbound_auth={$trunk_name}_auth
aors={$trunk_name}_aor
context={$trunk_name}-inbound
disallow=all
allow=opus
allow=ulaw
allow=alaw
webrtc=yes
dtls_auto_generate_cert=yes
ice_support=yes
media_use_received_transport=yes
rtcp_mux=yes
force_rport=yes
rewrite_contact=yes
direct_media=no
from_user={$trunk_user}
from_domain={$cloud_srv}
CONF;

    $path = '/etc/asterisk/pjsip_qualid.conf';
    file_put_contents($path, $conf);
    qualid_ensure_include('/etc/asterisk/pjsip_custom_post.conf', 'pjsip_qualid.conf');

    // Pin the SIP hostname → VPS IP in /etc/hosts so Asterisk resolves it
    // correctly and sends the right TLS SNI even when Egyptian ISP DNS
    // blocks .xyz TLD resolution.
    qualid_pin_hosts_entry(QUALID_CLOUD_IP, QUALID_CLOUD_SERVER);
}

/**
 * Ensure a socat UDP-over-TCP tunnel is running so Asterisk's STUN/TURN
 * traffic can reach the coturn server on the VPS even when the ISP blocks
 * ALL outbound UDP.
 *
 * Tunnel chain:
 *   Asterisk → UDP:127.0.0.1:3479 → socat(FreePBX) → TCP:VPS:80 → socat(VPS) → UDP:coturn:3478
 *
 * Uses a simple background process checked via pgrep — no systemd required
 * (avoids permission issues when PHP runs as the asterisk user).
 * The cron job (sync.php, every 5 min) keeps it alive if it dies.
 * Also writes a @reboot cron entry so the tunnel starts on boot.
 */
function qualid_setup_stun_tunnel() {
    if (!file_exists('/usr/bin/socat')) {
        return false;
    }

    $cloud_ip = QUALID_CLOUD_IP;

    // Check if socat tunnel is already running on port 3479
    exec('pgrep -f "socat.*UDP4-LISTEN:3479" 2>/dev/null', $pids);
    if (!empty($pids)) {
        return true; // already running
    }

    // Start socat as a fully detached background process.
    // setsid creates a new session so the process survives when fwconsole
    // (Apply Config) exits and kills its process group.
    exec("setsid /usr/bin/socat UDP4-LISTEN:3479,reuseaddr,fork TCP4:{$cloud_ip}:80,nodelay </dev/null > /dev/null 2>&1 &");

    // Reboot persistence is handled by the @reboot entry in qualid_write_cron()
    return true;
}

/**
 * Append TURN/ICE settings to /etc/asterisk/rtp_additional.conf so Asterisk's
 * native WebRTC endpoint can traverse NAT via the coturn relay on the VPS.
 *
 * NOTE: rtp_custom.conf is broken in FreePBX 17 / Asterisk 22 (the #include
 * is present in rtp.conf but the file contents are silently ignored). Writing
 * to rtp_additional.conf works because it's the first #include in rtp.conf.
 *
 * STUN/TURN addresses point to 127.0.0.1:3479 — the local socat tunnel
 * endpoint. The tunnel converts UDP→TCP so it works even when the ISP blocks
 * all outbound UDP (Egyptian fixed-line).
 *
 * Credentials are ephemeral HMAC-SHA1 (same scheme as coturn use-auth-secret):
 *   username = "<expiry_timestamp>:qualid_asterisk"
 *   password = base64(HMAC-SHA1(turn_secret, username))
 * TTL = 24 hours -- the sync cron (every 5 min) refreshes well before expiry.
 */
function qualid_write_rtp_conf() {
    $turn_domain = qualid_get('turn_domain', '');
    $turn_secret = qualid_get('turn_secret', '');
    if (empty($turn_domain) || empty($turn_secret)) {
        return; // TURN not provisioned yet -- skip
    }

    // Ensure the UDP-over-TCP tunnel is running before configuring STUN/TURN
    qualid_setup_stun_tunnel();

    // Ephemeral TURN credentials (24h TTL)
    $expiry   = time() + 86400;
    $username = $expiry . ':qualid_asterisk';
    $password = base64_encode(hash_hmac('sha1', $username, $turn_secret, true));

    // Point to the local socat tunnel endpoint (UDP:3479 → TCP:VPS:80 → coturn)
    // instead of the VPS IP directly, because all outbound UDP is blocked.
    $stun_turn_addr = '127.0.0.1:3479';

    // No stunaddr — we don't need server-reflexive (srflx) candidates because
    // ALL outbound UDP from FreePBX is blocked by the ISP. srflx candidates
    // would never work.  Removing stunaddr eliminates the full STUN gathering
    // phase (up to 1.6s of retransmission timeouts per interface) and speeds
    // up call setup significantly. Only TURN relay candidates are useful here.
    $turn_block = <<<CONF
; -- qualid-turn-start --
icesupport=yes
turnaddr={$stun_turn_addr}
turnusername={$username}
turnpassword={$password}
; -- qualid-turn-end --
CONF;

    $path = '/etc/asterisk/rtp_additional.conf';
    $content = @file_get_contents($path);
    if ($content === false) {
        return; // rtp_additional.conf doesn't exist yet
    }

    // Remove any existing qualid TURN block AND any leftover stunaddr/turnaddr
    // lines from earlier module versions that wrote without markers
    $content = preg_replace('/; -- qualid-turn-start --.*?; -- qualid-turn-end --\n?/s', '', $content);
    $content = preg_replace('/^(stunaddr|turnaddr|turnusername|turnpassword)=.*\n?/m', '', $content);

    // Append the TURN block
    $content = rtrim($content) . "\n" . $turn_block . "\n";
    file_put_contents($path, $content);

    // Clean stale STUN/TURN settings from rtp.conf and rtp_custom.conf.
    // Earlier manual debugging left a [general](+) block in rtp.conf and
    // a stunaddr line in rtp_custom.conf.  Because these files are loaded
    // AFTER rtp_additional.conf, they override our settings.
    qualid_clean_rtp_overrides();

    // Pin TURN hostname in /etc/hosts as DNS safety net
    qualid_pin_hosts_entry(QUALID_CLOUD_IP, $turn_domain);

    // Reload the RTP module so Asterisk picks up the new TURN credentials
    exec('asterisk -rx "module reload res_rtp_asterisk.so" 2>/dev/null');
}

/**
 * Remove stale STUN/TURN settings from rtp.conf and rtp_custom.conf that
 * would override the correct settings we write to rtp_additional.conf.
 */
function qualid_clean_rtp_overrides() {
    // --- rtp_custom.conf: strip any stunaddr/turnaddr lines ---
    $custom = '/etc/asterisk/rtp_custom.conf';
    $c = @file_get_contents($custom);
    if ($c !== false) {
        $cleaned = preg_replace('/^(stunaddr|turnaddr|turnusername|turnpassword|icesupport)=.*\n?/m', '', $c);
        if ($cleaned !== $c) {
            file_put_contents($custom, $cleaned);
        }
    }

    // --- rtp.conf: remove any [general](+) block with stun/turn settings ---
    $rtp = '/etc/asterisk/rtp.conf';
    $r = @file_get_contents($rtp);
    if ($r !== false) {
        // Remove the entire appended [general](+) section that contains
        // stun/turn settings (added during manual debugging).
        $cleaned = preg_replace('/\[general\]\(\+\)\s*\n((?:(?:icesupport|stunaddr|turnaddr|turnusername|turnpassword)=.*\n?)*)/s', '', $r);
        // Also strip any standalone stun/turn lines outside a section header
        $cleaned = preg_replace('/^(stunaddr|turnaddr|turnusername|turnpassword)=.*\n?/m', '', $cleaned);
        if ($cleaned !== $r) {
            file_put_contents($rtp, $cleaned);
        }
    }
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
exten => h,1,Hangup()
exten => _.,1,NoOp(Routing call to remote agent \${EXTEN})
 same => n,Dial(PJSIP/\${EXTEN}@{$trunk_name}_endpoint,60,rU)
 same => n,Hangup()

; ── Quali-D IVR ──────────────────────────────────────────────────────────────
; Route inbound calls through the Quali-D IVR flow engine.
; In FreePBX: Admin → Custom Destinations → add "qualid-ivr,<DID>,1"
; then set your Inbound Route destination to that Custom Destination.
; The DID passed as the extension becomes the trigger_number matched by the API.
[qualid-ivr]
exten => _.,1,NoOp(Quali-D IVR - DID:\${EXTEN} Raw CID:\${CALLERID(all)})
 same => n,Set(QUALID_PAI=\${PJSIP_HEADER(read,P-Asserted-Identity)})
 same => n,Set(QUALID_CID=\${FILTER(0-9+,\${CUT(QUALID_PAI,@,1)})})
 same => n,GotoIf(\$["\${QUALID_CID}" != ""]?override)
 same => n,Set(QUALID_PAI=\${PJSIP_HEADER(read,Remote-Party-ID)})
 same => n,Set(QUALID_CID=\${FILTER(0-9+,\${CUT(QUALID_PAI,@,1)})})
 same => n,GotoIf(\$["\${QUALID_CID}" != ""]?override)
 same => n,Set(QUALID_PAI=\${PJSIP_HEADER(read,X-ATA-CallerID)})
 same => n,Set(QUALID_CID=\${FILTER(0-9+,\${CUT(QUALID_PAI,@,1)})})
 same => n,GotoIf(\$["\${QUALID_CID}" = ""]?run)
 same => n(override),Set(CALLERID(num)=\${QUALID_CID})
 same => n(run),NoOp(Final CID: \${CALLERID(num)})
 same => n,AGI(qualid_ivr.php)
 same => n,Hangup()
exten => h,1,Hangup()
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

/**
 * Upsert a single hostname → IP entry in /etc/hosts.
 * Removes any existing line for the same hostname first, then appends.
 * This pins the SIP server hostname to its VPS IP so Asterisk can resolve
 * it (and send the correct TLS SNI) even when system DNS blocks .xyz TLDs.
 */
function qualid_pin_hosts_entry($ip, $hostname) {
    $hosts = '/etc/hosts';
    if (!file_exists($hosts) || !is_writable($hosts)) return;
    $lines = file($hosts, FILE_IGNORE_NEW_LINES);
    // Remove any pre-existing entry for this hostname
    $lines = array_filter($lines, function($line) use ($hostname) {
        return !preg_match('/\s+' . preg_quote($hostname, '/') . '(\s|$)/', $line);
    });
    $lines[] = $ip . ' ' . $hostname . '  # qualid-remote';
    file_put_contents($hosts, implode("\n", $lines) . "\n");
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
                        $mem_ins->execute(array($name, 'Local/' . $clean . '@qualid-remote-agents/n'));
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
                $conf .= 'member => Local/' . $clean . "@qualid-remote-agents/n\n";
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

    // Always regenerate Asterisk config files on Apply Config so that
    // dialplan changes in module updates take effect without requiring
    // a manual reconnect from the admin page.
    $cfg = qualid_load_config();
    if (!empty($cfg['trunk_user']) && !empty($cfg['trunk_pass'])) {
        qualid_write_pjsip($cfg);
        qualid_write_dialplan($cfg);

        // Write RTP config now (best-effort — FreePBX may regenerate
        // rtp_additional.conf after this hook, wiping our TURN block).
        // The per-minute cron watchdog re-applies it within 60 seconds.
        qualid_write_rtp_conf();
    }

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
 * Write /etc/cron.d/qualid_remote with two jobs:
 *
 * 1. Every 5 min — full sync (extensions, CDR, TURN credential refresh)
 * 2. Every 1 min — lightweight RTP watchdog that ensures the TURN config
 *    and socat tunnel are present.  This covers the case where FreePBX's
 *    Apply Config regenerates rtp_additional.conf AFTER our hook writes
 *    to it, wiping the TURN block.  The watchdog re-applies within 60s.
 *
 * Safe to call multiple times — idempotent.
 */
function qualid_write_cron() {
    $module_dir  = dirname(__FILE__);
    $sync_script = $module_dir . '/sync.php';
    $rtp_script  = $module_dir . '/deferred_rtp.php';
    $php_bin     = PHP_BINARY ?: '/usr/bin/php';
    $cron_file   = '/etc/cron.d/qualid_remote';
    $cloud_ip    = QUALID_CLOUD_IP;
    $content     = "# Quali-D Connect — automatic sync & RTP watchdog\n"
                 . "*/5 * * * * asterisk {$php_bin} {$sync_script} > /dev/null 2>&1\n"
                 . "* * * * * asterisk {$php_bin} {$rtp_script} > /dev/null 2>&1\n"
                 . "@reboot root /usr/bin/socat UDP4-LISTEN:3479,reuseaddr,fork TCP4:{$cloud_ip}:80,nodelay > /dev/null 2>&1 &\n";

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
// Diagnostics
// ---------------------------------------------------------------------------

/**
 * Run all system self-checks and return a structured result for the admin UI.
 * No side-effects — read-only.  Safe to call on every page load.
 *
 * Each check item:
 *   id      — machine identifier
 *   label   — human label
 *   ok      — bool
 *   detail  — short status string (may contain safe HTML)
 *   fix     — fix key passed to run_fix AJAX handler (null if no auto-fix)
 *   action  — HTML recommendation string shown when ok=false
 */
function qualid_run_diagnostics() {
    $checks = [];

    // 1. socat binary present
    $socat_bin       = '/usr/bin/socat';
    $socat_installed = file_exists($socat_bin) && is_executable($socat_bin);
    $checks[] = [
        'id'     => 'socat_installed',
        'label'  => 'socat binary installed',
        'ok'     => $socat_installed,
        'detail' => $socat_installed ? $socat_bin : 'Not found at /usr/bin/socat',
        'fix'    => null,
        'action' => $socat_installed ? null
            : 'Install socat: <code>yum install socat -y</code> (RHEL/CentOS) or <code>apt-get install socat -y</code> (Debian/Ubuntu)',
    ];

    // 2. TURN UDP-over-TCP tunnel running
    exec('pgrep -f "socat.*UDP4-LISTEN:3479" 2>/dev/null', $pids);
    $tunnel_ok = !empty($pids);
    $checks[] = [
        'id'     => 'tunnel_running',
        'label'  => 'TURN UDP&#x2011;over&#x2011;TCP tunnel active',
        'ok'     => $tunnel_ok,
        'detail' => $tunnel_ok ? 'PID&nbsp;' . implode(', ', array_map('intval', $pids)) : 'Not running',
        'fix'    => $socat_installed ? 'tunnel' : null,
        'action' => $tunnel_ok ? null
            : ($socat_installed
                ? 'The tunnel relays Asterisk TURN traffic over TCP (required on networks that block UDP). Click <b>Fix</b> to start it immediately, or wait up to 60&nbsp;s for the automatic watchdog.'
                : 'Install socat first (see above).'),
    ];

    // 3. TURN credentials stored in config
    $turn_domain = qualid_get('turn_domain', '');
    $turn_secret = qualid_get('turn_secret', '');
    $creds_ok    = !empty($turn_domain) && !empty($turn_secret);
    $checks[] = [
        'id'     => 'turn_creds',
        'label'  => 'TURN credentials provisioned',
        'ok'     => $creds_ok,
        'detail' => $creds_ok ? htmlspecialchars($turn_domain) : 'turn_domain or turn_secret missing from config',
        'fix'    => null,
        'action' => $creds_ok ? null
            : 'TURN credentials are missing. Click <b>Disconnect</b> then log in again to re-provision.',
    ];

    // 4. rtp_additional.conf writable
    $rtp_path     = '/etc/asterisk/rtp_additional.conf';
    $rtp_exists   = file_exists($rtp_path);
    $rtp_writable = $rtp_exists && is_writable($rtp_path);
    $checks[] = [
        'id'     => 'rtp_writable',
        'label'  => 'rtp_additional.conf writable',
        'ok'     => $rtp_writable,
        'detail' => !$rtp_exists   ? 'File not found'
                  : (!$rtp_writable ? 'Exists but not writable by current user'
                  :                   $rtp_path),
        'fix'    => null,
        'action' => $rtp_writable ? null
            : 'Run: <code>chown asterisk:asterisk /etc/asterisk/rtp_additional.conf &amp;&amp; chmod 664 /etc/asterisk/rtp_additional.conf</code>',
    ];

    // 5. TURN block present in rtp_additional.conf
    $rtp_content   = $rtp_exists ? @file_get_contents($rtp_path) : false;
    $turn_block_ok = ($rtp_content !== false && strpos($rtp_content, 'qualid-turn-start') !== false);

    $turn_detail = 'Not found &mdash; TURN config is missing';
    if ($turn_block_ok) {
        if (preg_match('/turnusername=(\d+):/', $rtp_content, $m)) {
            $expires_in = (int)$m[1] - time();
            if ($expires_in > 3600) {
                $h = floor($expires_in / 3600);
                $turn_detail = "Present &mdash; credentials expire in {$h}h";
            } elseif ($expires_in > 0) {
                $min = ceil($expires_in / 60);
                $turn_detail = "Present &mdash; credentials expire in {$min}&nbsp;min (refreshing soon)";
            } else {
                $turn_detail = 'Present &mdash; credentials <b>expired</b>, will refresh on next cron run';
            }
        } else {
            $turn_detail = 'Present';
        }
    }

    $checks[] = [
        'id'     => 'turn_block',
        'label'  => 'TURN config block in rtp_additional.conf',
        'ok'     => $turn_block_ok,
        'detail' => $turn_detail,
        'fix'    => ($creds_ok && $rtp_writable) ? 'rtp_conf' : null,
        'action' => $turn_block_ok ? null
            : 'FreePBX may have regenerated rtp_additional.conf after Apply Config, wiping the TURN block. Click <b>Fix</b> to rewrite it now, or wait up to 60&nbsp;s for the automatic watchdog.',
    ];

    // 6. Stale STUN/TURN overrides in rtp.conf
    $rtp_main_content = @file_get_contents('/etc/asterisk/rtp.conf');
    $stale_main = false;
    if ($rtp_main_content !== false) {
        $stale_main = (bool) preg_match('/\[general\]\(\+\)[^\[]*?(stunaddr|turnaddr)=/s', $rtp_main_content);
        if (!$stale_main) {
            $stale_main = (bool) preg_match('/^stunaddr=/m', $rtp_main_content);
        }
    }
    $checks[] = [
        'id'     => 'stale_rtp_main',
        'label'  => 'No stale STUN/TURN overrides in rtp.conf',
        'ok'     => !$stale_main,
        'detail' => $stale_main ? 'Found stunaddr= / turnaddr= &mdash; overriding correct settings' : 'Clean',
        'fix'    => $stale_main ? 'overrides' : null,
        'action' => $stale_main
            ? 'rtp.conf contains hardcoded STUN/TURN settings that silently override the Quali-D config and cause 10&ndash;20&nbsp;s call delays. Click <b>Fix</b> to remove them.'
            : null,
    ];

    // 7. Stale STUN/TURN overrides in rtp_custom.conf
    $rtp_custom_content = @file_get_contents('/etc/asterisk/rtp_custom.conf');
    $stale_custom = ($rtp_custom_content !== false)
        && (bool) preg_match('/^(stunaddr|turnaddr|turnusername|turnpassword)=/m', $rtp_custom_content);
    $checks[] = [
        'id'     => 'stale_rtp_custom',
        'label'  => 'No stale STUN/TURN overrides in rtp_custom.conf',
        'ok'     => !$stale_custom,
        'detail' => $stale_custom
            ? 'Found STUN/TURN settings that override the correct config'
            : ($rtp_custom_content === false ? 'File not present (OK)' : 'Clean'),
        'fix'    => $stale_custom ? 'overrides' : null,
        'action' => $stale_custom
            ? 'rtp_custom.conf has hardcoded STUN/TURN settings that override the Quali-D config. Click <b>Fix</b> to remove them.'
            : null,
    ];

    // 8. Cron watchdog configured
    $cron_file         = '/etc/cron.d/qualid_remote';
    $cron_content      = @file_get_contents($cron_file);
    $cron_present      = ($cron_content !== false);
    $cron_has_watchdog = $cron_present && strpos($cron_content, 'deferred_rtp.php') !== false;
    $cron_has_reboot   = $cron_present && strpos($cron_content, '@reboot') !== false;
    $cron_ok           = $cron_has_watchdog;

    if (!$cron_present) {
        $cron_detail = 'File not found';
    } elseif (!$cron_has_watchdog) {
        $cron_detail = 'Old format &mdash; per-minute watchdog entry missing';
    } else {
        $parts = ['Per-minute watchdog active'];
        if ($cron_has_reboot) { $parts[] = '@reboot socat entry present'; }
        $cron_detail = implode(', ', $parts);
    }

    $checks[] = [
        'id'     => 'cron_watchdog',
        'label'  => 'Cron watchdog configured',
        'ok'     => $cron_ok,
        'detail' => $cron_detail,
        'fix'    => 'cron',
        'action' => $cron_ok ? null
            : (!$cron_present
                ? 'Cron file is missing. Click <b>Fix</b> to recreate it, or trigger an Apply Config.'
                : 'Cron file has an old format without the per-minute watchdog. Click <b>Fix</b> to update.'),
    ];

    // 9. PJSIP trunk configured
    $pjsip_files  = [
        '/etc/asterisk/pjsip_additional.conf',
        '/etc/asterisk/pjsip.conf',
        '/etc/asterisk/pjsip_custom.conf',
    ];
    $pjsip_ok     = false;
    $pjsip_source = '';
    foreach ($pjsip_files as $pf) {
        $pfc = @file_get_contents($pf);
        if ($pfc !== false && strpos($pfc, 'QualidRemote') !== false) {
            $pjsip_ok     = true;
            $pjsip_source = basename($pf);
            if (strpos($pfc, 'webrtc=yes') !== false) {
                $pjsip_source .= ' (WebRTC enabled)';
            }
            break;
        }
    }
    $checks[] = [
        'id'     => 'pjsip_trunk',
        'label'  => 'PJSIP trunk configured',
        'ok'     => $pjsip_ok,
        'detail' => $pjsip_ok ? htmlspecialchars($pjsip_source) : 'QualidRemote endpoint not found in PJSIP config',
        'fix'    => null,
        'action' => $pjsip_ok ? null
            : 'PJSIP trunk not written yet. Go to <b>Admin &rarr; Apply Config</b> to generate it.',
    ];

    $failures = array_filter($checks, function ($c) { return !$c['ok']; });

    return [
        'success'    => true,
        'checks'     => $checks,
        'all_ok'     => empty($failures),
        'fail_count' => count($failures),
    ];
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
