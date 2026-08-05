<?php
/**
 * QUALI-D Remote Agent — Module Uninstall
 * Removes all config keys and Asterisk config files.
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Remove JSON config file (module uses file-based storage, not FreePBX DB)
$cfg_path = dirname(__FILE__) . '/config.json';
if (file_exists($cfg_path)) {
    unlink($cfg_path);
}

// Remove Asterisk config files and #include lines
qualid_remove_asterisk_config();

out('QUALI-D Remote Agent uninstalled. Asterisk configs removed.');
