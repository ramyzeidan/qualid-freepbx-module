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

out('QUALI-D Remote Agent installed successfully.');
out('Go to Admin → QUALI-D Remote Agent to configure your API key.');
