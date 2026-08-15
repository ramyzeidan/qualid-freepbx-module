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

// Remove auto-sync cron entry from the user's crontab
$cron_script = '/var/www/html/admin/modules/qualid_remote/cron_sync.php';
$existing    = shell_exec('crontab -l 2>/dev/null') ?: '';
if (strpos($existing, $cron_script) !== false) {
    $lines       = explode("\n", $existing);
    $lines       = array_filter($lines, function ($l) use ($cron_script) {
        return strpos($l, $cron_script) === false;
    });
    $new_crontab = implode("\n", $lines) . "\n";
    $ph = popen('crontab -', 'w');
    if ($ph) { fwrite($ph, $new_crontab); pclose($ph); }
}

out('Quali-D Connect uninstalled. Asterisk configs, AGI script, and cron removed.');
