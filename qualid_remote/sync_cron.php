#!/usr/bin/env php
<?php
/**
 * Quali-D Connect — Queue Sync Cron Script
 * -----------------------------------------
 * Installed at: /etc/cron.d/qualid_remote
 * Runs every 5 minutes as the asterisk user.
 *
 * Fetches the current queue list from the QUALI-D API and writes
 * /etc/asterisk/queues_qualid.conf, then reloads app_queue.so so
 * changes made in the Vue dashboard take effect in Asterisk
 * automatically — no Apply Config click required.
 */

// Allow functions.inc.php to load outside a web request
define('FREEPBX_IS_AUTH', true);

require_once __DIR__ . '/functions.inc.php';

$token = qualid_get('token', '');
if (empty($token)) {
    exit(0); // Not connected — nothing to sync
}

qualid_sync_queues($token);
