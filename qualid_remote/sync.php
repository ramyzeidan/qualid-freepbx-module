<?php
/**
 * Quali-D Connect — Background Sync Script
 * Runs every 5 minutes via /etc/cron.d/qualid_remote (written by the Apply Config hook).
 * Syncs extensions, queues, and CDR with the QUALI-D cloud.
 *
 * This script runs as the asterisk user (set in the cron file), which has read/write
 * access to /etc/asterisk and the Asterisk AGI bin directory.
 */

define('FREEPBX_IS_AUTH', 1);

// Locate and load the FreePBX environment
$bootstrap = '/etc/freepbx.conf';
if (!file_exists($bootstrap)) {
    $bootstrap = '/etc/asterisk/freepbx.conf';
}
if (!file_exists($bootstrap)) {
    exit(1);
}

$bootstrap_settings = array('skip_astman' => true);
require_once($bootstrap);

// Now load our own functions
require_once(dirname(__FILE__) . '/functions.inc.php');

$token = qualid_get('token');
if (empty($token)) {
    exit(0); // Not connected — nothing to do
}

qualid_push_pbx_host($token);
qualid_sync_extensions($token);
qualid_sync_queues($token);
qualid_sync_cdr_to_qualid($token);
qualid_send_heartbeat($token);
