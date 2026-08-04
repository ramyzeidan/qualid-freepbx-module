<?php
/**
 * QUALI-D Remote Agent — Module Install / Upgrade
 * Runs automatically by FreePBX when the module is installed or upgraded.
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Set default config values on fresh install
$defaults = [
    'qualid_api_key'      => '',
    'qualid_company_id'   => '',
    'qualid_sip_domain'   => '',
    'qualid_trunk_user'   => '',
    'qualid_trunk_pass'   => '',
    'qualid_turn_server'  => '',
    'qualid_connected'    => '0',
    'qualid_connected_at' => '',
    'qualid_last_error'   => '',
];

foreach ($defaults as $key => $val) {
    if (getConfig($key) === false) {
        setConfig($key, $val);
    }
}

// Ensure the Asterisk config directory is writable
$dirs = ['/etc/asterisk'];
foreach ($dirs as $dir) {
    if (!is_writable($dir)) {
        out("WARNING: {$dir} is not writable by the web server. Asterisk config generation may fail.");
    }
}

out('QUALI-D Remote Agent installed successfully.');
out('Go to Admin → QUALI-D Remote Agent to configure your API key.');
