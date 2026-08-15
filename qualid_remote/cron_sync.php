#!/usr/bin/php
<?php
/**
 * Quali-D Connect — Automatic Sync Cron
 * Runs every 2 minutes via /etc/cron.d/qualid_remote.
 *
 * Jobs:
 *   1. Push this server's IP to QUALI-D cloud (POST /company/pbx-host)
 *   2. Push new CDR records to QUALI-D cloud  (POST /cdr/sync)
 *   3. Push live extension registration state (POST /extensions/sync)
 *   4. Send heartbeat ping                    (POST /ivr/heartbeat)
 */

// Bootstrap FreePBX without requiring a web session
define('FREEPBX_IS_AUTH', true);
$bootstrap_settings = ['freepbx_auth' => false];

foreach (['/etc/freepbx.conf', '/etc/asterisk/freepbx.conf'] as $_conf) {
    if (file_exists($_conf)) { require_once($_conf); break; }
}

require_once(dirname(__FILE__) . '/functions.inc.php');

$token = qualid_get('token', '');
if (empty($token)) {
    // Not connected yet — nothing to do
    exit(0);
}

qualid_push_pbx_host($token);
qualid_sync_cdr_to_qualid($token);
qualid_sync_extensions($token);
qualid_send_heartbeat($token);
