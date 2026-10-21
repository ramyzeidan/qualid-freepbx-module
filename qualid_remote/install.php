<?php
/**
 * Quali-D Connect — Module Install / Upgrade
 * Runs automatically by FreePBX when the module is installed or upgraded.
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Ensure required directories are writable
$dirs = ['/etc/asterisk', '/var/lib/asterisk/agi-bin'];
foreach ($dirs as $dir) {
    if (is_dir($dir) && !is_writable($dir)) {
        out("WARNING: {$dir} is not writable by the web server. Config generation may fail.");
    }
}

// Upgrade path: if credentials are already stored from a previous install,
// regenerate all config files immediately.
// This handles upgrades from old TCP/WSS versions automatically.
$cfg_path = dirname(__FILE__) . '/config.json';
if (file_exists($cfg_path)) {
    $cfg = json_decode(file_get_contents($cfg_path), true);
    if (is_array($cfg) && !empty($cfg['trunk_user']) && !empty($cfg['trunk_pass'])) {
        qualid_write_pjsip($cfg);
        qualid_write_dialplan($cfg);
        out('Quali-D Connect: SIP trunk config regenerated with TLS transport (port 443).');
    }
    // Upgrade path: re-deploy AGI script if already connected
    if (is_array($cfg) && !empty($cfg['agi_secret'])) {
        qualid_write_agi_files($cfg['agi_secret']);
        out('Quali-D Connect: IVR AGI script deployed to /var/lib/asterisk/agi-bin/.');
    }
    // Upgrade path: immediately sync extensions + CDR so new installs are
    // up-to-date without waiting for Apply Config or a page visit.
    if (is_array($cfg) && !empty($cfg['token'])) {
        qualid_push_pbx_host($cfg['token']);
        qualid_sync_extensions($cfg['token']);
        qualid_sync_cdr_to_qualid($cfg['token']);
        out('Quali-D Connect: extensions and CDR synced with QUALI-D cloud.');
    }
}

// ---------------------------------------------------------------------------
// WireGuard: install wireguard-tools, configure /etc/wireguard/ ownership,
// and create a sudoers entry so the asterisk web user can manage the tunnel.
// This block runs as root (FreePBX Module Admin calls fwconsole internally),
// so apt-get, chown, and sudoers writes all succeed here.
// ---------------------------------------------------------------------------
$wg_bin = null;
foreach (['/usr/bin/wg', '/sbin/wg', '/usr/sbin/wg'] as $p) {
    if (is_executable($p)) { $wg_bin = $p; break; }
}
if (!$wg_bin) {
    @exec('DEBIAN_FRONTEND=noninteractive apt-get install -y wireguard-tools 2>&1', $wg_out, $wg_ret);
    out($wg_ret === 0
        ? 'Quali-D Connect: wireguard-tools installed.'
        : 'WARNING: wireguard-tools install failed — ISP RTP bypass will not be active.');
} else {
    out('Quali-D Connect: wireguard-tools already present.');
}

// Create /etc/wireguard and give the asterisk user ownership so page.php
// (running as asterisk web user) can write wg0.conf at Connect time without root.
if (!is_dir('/etc/wireguard')) {
    @mkdir('/etc/wireguard', 0700, true);
}
@chown('/etc/wireguard', 'asterisk');
@chgrp('/etc/wireguard', 'asterisk');

// Grant asterisk passwordless sudo for WireGuard service management only.
// Key generation and API calls run fine as asterisk; only wg-quick and
// systemctl need elevated privileges.
$sudoers  = "# Quali-D Connect — WireGuard tunnel management (auto-generated)\n";
$sudoers .= "asterisk ALL=(ALL) NOPASSWD:"
          . " /usr/bin/wg-quick,"
          . " /bin/systemctl enable wg-quick@wg0,"
          . " /bin/systemctl start wg-quick@wg0,"
          . " /bin/systemctl restart wg-quick@wg0,"
          . " /usr/bin/systemctl enable wg-quick@wg0,"
          . " /usr/bin/systemctl start wg-quick@wg0,"
          . " /usr/bin/systemctl restart wg-quick@wg0\n";
if (@file_put_contents('/etc/sudoers.d/qualid-wireguard', $sudoers) !== false) {
    @chmod('/etc/sudoers.d/qualid-wireguard', 0440);
    out('Quali-D Connect: WireGuard sudoers configured.');
} else {
    out('WARNING: Could not write /etc/sudoers.d/qualid-wireguard — WireGuard service management may fail.');
}

out('Quali-D Connect installed successfully.');
out('Go to Admin → Quali-D Connect to configure your connection.');
