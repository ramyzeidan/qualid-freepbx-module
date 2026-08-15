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

// Install the auto-sync cron via the current user's crontab (no root needed).
// Adds a */2 entry only if it isn't already present.
$cron_script = '/var/www/html/admin/modules/qualid_remote/cron_sync.php';
$cron_line   = "*/2 * * * * /usr/bin/php {$cron_script} > /dev/null 2>&1";
$existing    = shell_exec('crontab -l 2>/dev/null') ?: '';
if (strpos($existing, $cron_script) === false) {
    $new_crontab = rtrim($existing) . "\n" . $cron_line . "\n";
    $ph = popen('crontab -', 'w');
    if ($ph) {
        fwrite($ph, $new_crontab);
        pclose($ph);
        out('Quali-D Connect: auto-sync cron installed (every 2 minutes).');
    } else {
        out('WARNING: Could not install cron — auto-sync will not run.');
    }
} else {
    out('Quali-D Connect: auto-sync cron already present — skipped.');
}

out('Quali-D Connect installed successfully.');
out('Go to Admin → Quali-D Connect to configure your connection.');
