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
}

out('Quali-D Connect installed successfully.');
out('Go to Admin → Quali-D Connect to configure your connection.');
