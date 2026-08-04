<?php
/**
 * QUALI-D Remote Agent — Module Uninstall
 * Removes all config keys and Asterisk config files.
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Remove all stored config
$keys = [
    'qualid_api_key', 'qualid_company_id', 'qualid_sip_domain',
    'qualid_trunk_user', 'qualid_trunk_pass', 'qualid_turn_server',
    'qualid_connected', 'qualid_connected_at', 'qualid_last_error',
];
foreach ($keys as $key) {
    delConfig($key);
}

// Remove Asterisk config files and #include lines
qualid_remove_asterisk_config();

out('QUALI-D Remote Agent uninstalled. Asterisk configs removed.');
