#!/usr/bin/env php
<?php
/**
 * QUALI-D IVR AGI Script
 * ─────────────────────
 * Place at: /var/lib/asterisk/agi-bin/qualid_ivr.php
 * Make executable: chmod +x /var/lib/asterisk/agi-bin/qualid_ivr.php
 *
 * Dialplan usage (extensions_qualid.conf or extensions_custom.conf):
 *
 *   [from-trunk]
 *   exten => _.,1,NoOp(QUALI-D IVR)
 *    same => n,AGI(qualid_ivr.php)
 *    same => n,Hangup()
 *
 * This script is intentionally a "dumb executor":
 *  - It calls POST /ivr/agi/start to open a session and get the first instruction.
 *  - It executes the instruction using Asterisk AGI commands.
 *  - It calls POST /ivr/agi/step with the result and gets the next instruction.
 *  - It loops until the instruction is { action: hangup } or { action: transfer }.
 *  - On any call end it calls POST /ivr/agi/end.
 *
 * Configuration (set these three lines at the top):
 */

// Configuration is written by the FreePBX qualid_remote module at:
//   /etc/asterisk/qualid_ivr.conf
// Do NOT edit these values manually — reconnect via Admin → Quali-D Connect.
$_qconf = file_exists('/etc/asterisk/qualid_ivr.conf')
    ? (parse_ini_file('/etc/asterisk/qualid_ivr.conf') ?: [])
    : [];
define('QUALID_API_BASE',   $_qconf['api_base']   ?? 'https://api.quali-d.com');
define('QUALID_AGI_SECRET', $_qconf['agi_secret'] ?? '');
define('QUALID_TIMEOUT',    8);

// ─────────────────────────────────────────────────────────────────────────────

// Read all AGI environment variables from stdin
$agi_vars = [];
while (true) {
    $line = fgets(STDIN);
    if ($line === "\n" || $line === false) break;
    $line = trim($line);
    if (preg_match('/^agi_(\w+):\s*(.*)$/', $line, $m)) {
        $agi_vars[$m[1]] = $m[2];
    }
}

$caller_id = $agi_vars['callerid']    ?? 'unknown';
$did       = $agi_vars['extension']   ?? '';
$channel   = $agi_vars['channel']     ?? '';

// ─ Helper: send AGI command and read response ─────────────────────────────────
function agi($cmd) {
    fwrite(STDOUT, $cmd . "\n");
    fflush(STDOUT);
    $response = fgets(STDIN);
    return trim($response);
}

// ─ Helper: HTTP POST to QUALI-D API ──────────────────────────────────────────
function api_post($path, $body) {
    $url = QUALID_API_BASE . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-AGI-Secret: ' . QUALID_AGI_SECRET,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => QUALID_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['success' => false, 'error' => $err];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['success' => false, 'http_code' => $code];
}

// ─ Helper: download URL to temp file (for AGI playback) ──────────────────────
function download_audio($url) {
    if (!$url) return null;
    $tmp = tempnam('/tmp', 'qualid_ivr_') . '.mp3';
    $ch  = curl_init($url);
    $fh  = fopen($tmp, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        // Send AGI secret so the /api/ivr/agi/audio/{id} endpoint accepts the request
        CURLOPT_HTTPHEADER     => ['X-AGI-Secret: ' . QUALID_AGI_SECRET],
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($code !== 200 || !filesize($tmp)) {
        @unlink($tmp);
        return null;
    }
    return $tmp;
}

// Convert /tmp/file.mp3 to asterisk-compatible path (no extension)
function audio_path($tmp_file) {
    if (!$tmp_file) return 'silence/1';  // 1-second silence as fallback
    // Asterisk needs the path without extension and GSM/WAV is preferred;
    // we store MP3 so we use mp3player. Return full path without extension.
    return $tmp_file;
}

// ─ Answer the call ────────────────────────────────────────────────────────────
agi('ANSWER');
agi('SET VARIABLE CHANNEL_ANSWERED 1');

// ─ Start session ─────────────────────────────────────────────────────────────
$start_resp = api_post('/ivr/agi/start', [
    'caller_id' => $caller_id,
    'did'       => $did,
]);

if (empty($start_resp['success']) || empty($start_resp['instruction'])) {
    // API error or no flow → hang up
    agi('HANGUP');
    exit(0);
}

$session_id  = $start_resp['session_id'] ?? null;
$instruction = $start_resp['instruction'];
$hangup_cause = 'normal';

// ─ Main execution loop ────────────────────────────────────────────────────────
while (true) {
    $action = $instruction['action'] ?? 'hangup';

    // ── hangup ──────────────────────────────────────────────────────────────
    if ($action === 'hangup') {
        break;
    }

    // ── transfer ────────────────────────────────────────────────────────────
    if ($action === 'transfer') {
        $ext     = $instruction['extension'] ?? '';
        $context = $instruction['context']   ?? 'default';
        $timeout = (int) ($instruction['timeout'] ?? 30);
        if ($ext) {
            agi("EXEC Goto {$context},{$ext},1");
        }
        $hangup_cause = 'transferred';
        break;
    }

    // ── queue ────────────────────────────────────────────────────────────────
    if ($action === 'queue') {
        $queue_name = preg_replace('/[^a-z0-9_-]/', '', strtolower($instruction['queue_name'] ?? ''));
        $timeout    = (int) ($instruction['timeout'] ?? 60);

        if (!$queue_name) {
            $instruction = ['action' => 'hangup'];
            continue;
        }

        // EXEC Queue(queuename,options,url,announceoverride,timeout,agi,macro,gosub,rule,position)
        // We only use name + timeout. The queue config (members, strategy, etc.) is
        // pre-written to /etc/asterisk/queues_qualid.conf by the FreePBX module.
        $queue_resp = agi("EXEC Queue {$queue_name},,,,{$timeout}");

        // Queue() sets QUEUESTATUS: TIMEOUT, FULL, JOINEMPTY, LEAVEEMPTY, JOINUNAVAIL,
        // LEAVEUNAVAIL, CONTINUE, or empty (answered + hung up by agent).
        $status_resp = agi('GET VARIABLE QUEUESTATUS');
        $queue_status = '';
        if (preg_match('/result=1\s+\(([^)]+)\)/', $status_resp, $sm)) {
            $queue_status = strtolower($sm[1]);
        }

        // Map Asterisk QUEUESTATUS to our event_data values
        $result_map = [
            'timeout'     => 'timeout',
            'full'        => 'full',
            'joinempty'   => 'joinempty',
            'leaveempty'  => 'timeout',
            'joinunavail' => 'joinempty',
            'leaveunavail'=> 'timeout',
            'continue'    => 'timeout',
        ];
        $queue_result = isset($result_map[$queue_status]) ? $result_map[$queue_status] : 'answered';

        if ($queue_result === 'answered') {
            $hangup_cause = 'queue_answered';
        }

        $step_resp   = api_post('/ivr/agi/step', [
            'session_id' => $session_id,
            'event'      => 'queue_result',
            'event_data' => $queue_result,
        ]);
        $instruction = $step_resp['instruction'] ?? ['action' => 'hangup'];
        continue;
    }

    // ── wait ─────────────────────────────────────────────────────────────────
    if ($action === 'wait') {
        $secs = (int) ($instruction['duration'] ?? 2);
        agi("EXEC Wait {$secs}");
        $step_resp   = api_post('/ivr/agi/step', [
            'session_id' => $session_id,
            'event'      => 'done',
            'event_data' => null,
        ]);
        $instruction = $step_resp['instruction'] ?? ['action' => 'hangup'];
        continue;
    }

    // ── play ─────────────────────────────────────────────────────────────────
    if ($action === 'play') {
        $tmp = download_audio($instruction['url'] ?? null);
        if ($tmp) {
            agi("EXEC MP3Player {$tmp}");
            @unlink($tmp);
        } else {
            agi('EXEC Wait 1');
        }
        $step_resp   = api_post('/ivr/agi/step', [
            'session_id' => $session_id,
            'event'      => 'done',
            'event_data' => null,
        ]);
        $instruction = $step_resp['instruction'] ?? ['action' => 'hangup'];
        continue;
    }

    // ── get_digits (menu / get_digits node) ──────────────────────────────────
    if ($action === 'get_digits') {
        $tmp        = download_audio($instruction['url'] ?? null);
        $num_digits = (int) ($instruction['num_digits'] ?? 1);
        $timeout    = (int) ($instruction['timeout']    ?? 5) * 1000; // ms
        $retries    = (int) ($instruction['retries']    ?? 3);
        $valid_keys = $instruction['valid_keys'] ?? [];

        $key = '';
        for ($attempt = 0; $attempt < $retries; $attempt++) {
            // Play prompt
            if ($tmp) {
                // Use GET DATA to play + collect simultaneously
                $resp = agi("GET DATA {$tmp} {$timeout} {$num_digits}");
            } else {
                $resp = agi("WAIT FOR DIGIT {$timeout}");
            }
            // Parse response: "200 result=<digits> (timeout)"
            if (preg_match('/result=(\S+)/', $resp, $m)) {
                $key = trim($m[1], '<>');
                if ($key !== '' && $key !== '-1') break;
            }
            $key = '';
        }

        if ($tmp) @unlink($tmp);

        $event     = ($key === '') ? 'timeout' : 'dtmf';
        $step_resp = api_post('/ivr/agi/step', [
            'session_id' => $session_id,
            'event'      => $event,
            'event_data' => $key ?: null,
        ]);
        $instruction = $step_resp['instruction'] ?? ['action' => 'hangup'];
        continue;
    }

    // ── record_speech (speech-to-text via Whisper) ────────────────────────────
    if ($action === 'record_speech') {
        $tmp        = download_audio($instruction['url'] ?? null);
        $silence    = (int) ($instruction['silence_sec'] ?? 3);
        $max_sec    = (int) ($instruction['max_sec']     ?? 30);
        $rec_file   = tempnam('/tmp', 'qualid_speech_');

        // Play prompt, then record
        if ($tmp) {
            agi("EXEC MP3Player {$tmp}");
            @unlink($tmp);
        }
        agi("RECORD FILE {$rec_file} wav \"#\" {$max_sec}000 s={$silence}");

        // Upload for Whisper transcription via API step
        $speech_text = '';
        if (file_exists($rec_file . '.wav')) {
            // Encode as base64 and send in step (avoids separate upload endpoint)
            $b64 = base64_encode(file_get_contents($rec_file . '.wav'));
            @unlink($rec_file . '.wav');

            $step_resp   = api_post('/ivr/agi/step', [
                'session_id'     => $session_id,
                'event'          => 'speech',
                'event_data'     => $b64,
                'event_encoding' => 'base64_wav',
            ]);
        } else {
            $step_resp = api_post('/ivr/agi/step', [
                'session_id' => $session_id,
                'event'      => 'timeout',
                'event_data' => null,
            ]);
        }
        @unlink($rec_file);

        $instruction = $step_resp['instruction'] ?? ['action' => 'hangup'];
        continue;
    }

    // ── record_voicemail ──────────────────────────────────────────────────────
    if ($action === 'record_voicemail') {
        $tmp      = download_audio($instruction['url'] ?? null);
        $silence  = (int) ($instruction['silence_sec'] ?? 5);
        $max_sec  = (int) ($instruction['max_sec']     ?? 120);
        $vm_sid   = $instruction['session_id']         ?? $session_id;
        $up_url   = $instruction['upload_url']         ?? null;
        $rec_file = tempnam('/tmp', 'qualid_vm_');

        // Play beep prompt
        if ($tmp) {
            agi("EXEC MP3Player {$tmp}");
            @unlink($tmp);
        } else {
            agi("EXEC Playback beep");
        }

        // Record voicemail
        agi("RECORD FILE {$rec_file} wav \"#\" {$max_sec}000 s={$silence}");

        // Upload to QUALI-D API
        if ($up_url && file_exists($rec_file . '.wav')) {
            $ch = curl_init($up_url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => [
                    'session_id' => $vm_sid,
                    'recording'  => new CURLFile($rec_file . '.wav', 'audio/wav', 'voicemail.wav'),
                ],
                CURLOPT_HTTPHEADER     => ['X-AGI-Secret: ' . QUALID_AGI_SECRET],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_exec($ch);
            curl_close($ch);
            @unlink($rec_file . '.wav');
        }
        @unlink($rec_file);

        $step_resp   = api_post('/ivr/agi/step', [
            'session_id' => $session_id,
            'event'      => 'done',
            'event_data' => null,
        ]);
        $instruction = $step_resp['instruction'] ?? ['action' => 'hangup'];
        continue;
    }

    // Unknown action — hang up
    break;
}

// ─ End session ────────────────────────────────────────────────────────────────
if ($session_id) {
    api_post('/ivr/agi/end', [
        'session_id'   => $session_id,
        'hangup_cause' => $hangup_cause,
    ]);
}

agi('HANGUP');
exit(0);
