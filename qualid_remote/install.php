<?php
/**
 * QUALI-D Remote Agent — Module Install / Upgrade
 * Runs automatically by FreePBX when the module is installed or upgraded.
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Ensure the Asterisk config directory is writable
$dirs = ['/etc/asterisk'];
foreach ($dirs as $dir) {
    if (!is_writable($dir)) {
        out("WARNING: {$dir} is not writable by the web server. Asterisk config generation may fail.");
    }
}

// Upgrade path: if credentials are already stored from a previous install,
// regenerate pjsip_qualid.conf immediately so it picks up TLS transport.
// This handles upgrades from the old TCP/WSS versions automatically.
$cfg_path = dirname(__FILE__) . '/config.json';
if (file_exists($cfg_path)) {
    $cfg = json_decode(file_get_contents($cfg_path), true);
    if (is_array($cfg) && !empty($cfg['trunk_user']) && !empty($cfg['trunk_pass'])) {
        qualid_write_pjsip($cfg);
        qualid_write_dialplan($cfg);
        out('QUALI-D Remote Agent: SIP trunk config regenerated with TLS transport (port 443).');
    }
}

out('QUALI-D Remote Agent installed successfully.');
out('Go to Admin → QUALI-D Remote Agent to configure your connection.');
