<?php
/**
 * Quali-D Connect — Module Uninstall
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

// Remove IVR AGI script and conf file
qualid_remove_agi_files();

// Remove auto-sync cron
$cron_file = '/etc/cron.d/qualid_remote';
if (file_exists($cron_file)) {
    unlink($cron_file);
}

out('Quali-D Connect uninstalled. Asterisk configs, AGI script, and cron removed.');
