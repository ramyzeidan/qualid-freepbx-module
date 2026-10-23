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

    public function __construct($freepbx) {
        // CRITICAL: parent::__construct() registers this instance with
        // FreePBX's BMO registry. Without it, generate_config() is never
        // called during fwconsole reload / Apply Config.
        parent::__construct($freepbx);

        // Load module helper functions (guarded against double-include)
        if (!function_exists('qualid_get')) {
            require_once __DIR__ . '/functions.inc.php';
        }
    }

    public function install()   {}
    public function uninstall() {}

    /**
     * Called by FreePBX before the admin page is rendered.
     * No per-page initialisation is needed for this module.
     */
    public function doConfigPageInit($page) {}

    /**
     * Called automatically by FreePBX on every Apply Config / fwconsole reload.
     * Syncs extensions, CDR, PBX host, and heartbeat with the QUALI-D cloud.
     */
    public function genConfig() {
        // Defensive: ensure helpers are loaded even if constructor was skipped
        if (!function_exists('qualid_get')) {
            if (file_exists(__DIR__ . '/functions.inc.php')) {
                require_once __DIR__ . '/functions.inc.php';
            } else {
                return;
            }
        }

        // Diagnostic: record that this hook fired, regardless of token state.
        // Visible on the Quali-D Connect page as "Last Apply Config".
        try {
            qualid_set('last_apply_config_at', date('Y-m-d H:i:s'));
        } catch (\Throwable $e) { /* non-fatal */ }

        $token = qualid_get('token', '');
        if (empty($token)) {
            return;
        }

        // Each call is isolated — one failure must not prevent the others from running.
        $calls = [
            'push_pbx_host'    => fn() => qualid_push_pbx_host($token),
            'sync_extensions'  => fn() => qualid_sync_extensions($token),
            'sync_cdr'         => fn() => qualid_sync_cdr_to_qualid($token),
            'send_heartbeat'   => fn() => qualid_send_heartbeat($token),
            'sync_trunks'      => fn() => qualid_sync_trunks($token),
        ];
        foreach ($calls as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                // Use error_log so it always reaches the PHP/Apache error log
                // regardless of file-system permissions on the module directory.
                error_log('Quali-D genConfig [' . $name . ']: ' . $e->getMessage());
            }
        }
    }
}
