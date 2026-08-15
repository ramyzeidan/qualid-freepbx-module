<?php
/**
 * Quali-D Connect — FreePBX BMO Class
 *
 * Adding this class makes FreePBX treat the module as a BMO module,
 * which means generate_config() is called automatically on every
 * "Apply Config" / fwconsole reload — no cron, no manual steps.
 *
 * All module logic stays in functions.inc.php; this class is only
 * a thin hook wrapper.
 */
namespace FreePBX\Modules;

class Qualid_remote extends \FreePBX_Helpers implements \BMO {

    public function __construct($freepbx = null) {
        if ($freepbx !== null) {
            $this->FreePBX = $freepbx;
        }
        // Load module helper functions (guarded against double-include)
        if (!function_exists('qualid_get')) {
            require_once __DIR__ . '/functions.inc.php';
        }
    }

    public function install()   {}
    public function uninstall() {}

    /**
     * Called automatically by FreePBX on every Apply Config / fwconsole reload.
     * Syncs extensions, CDR, PBX host, and heartbeat with the QUALI-D cloud.
     */
    public function generate_config() {
        $token = qualid_get('token', '');
        if (empty($token)) {
            return;
        }
        qualid_push_pbx_host($token);
        qualid_sync_extensions($token);
        qualid_sync_cdr_to_qualid($token);
        qualid_send_heartbeat($token);
    }
}
