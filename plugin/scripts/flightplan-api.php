<?php
declare(strict_types=1);

/**
 * Flightplan web endpoint, POST only, urlencoded (the webGui's nginx auth_request breaks multipart). CSRF is
 * validated and consumed by the webGui's own local_prepend.php before this runs; do not add a second check.
 * Authentication is the webGui session (nginx auth_request). Actions:
 *
 *   status   the config, per-source state, schedule, last sync, log tail (no secrets exist in any of it)
 *   save     payload=<json config>; validates, writes flash if changed, re-registers the cron entry
 *   sync     starts a sync in the background and returns at once; the page polls status
 *
 * Rule: nothing here references STDOUT, STDERR or $argv. The CLI-only guard at the bottom reads the request from
 * the environment/stdin so tests can drive the real dispatcher as a subprocess.
 */

require_once __DIR__ . '/lib.php';

/** Returns [http status, payload array]. */
function fp_api_handle(string $method, array $post): array
{
    if ($method !== 'POST') {
        return [405, ['ok' => false, 'error' => 'POST only']];
    }
    $action = (string) ($post['action'] ?? '');
    try {
        switch ($action) {
            case 'status':
                return [200, ['ok' => true] + fp_status()];

            case 'save':
                $raw = (string) ($post['payload'] ?? '');
                if (strlen($raw) > 65536) {
                    return [413, ['ok' => false, 'error' => 'payload too large']];
                }
                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    return [400, ['ok' => false, 'error' => 'payload is not valid JSON']];
                }
                [$ok, $msg, $cfg] = fp_config_save($data);
                if (!$ok) {
                    return [200, ['ok' => false, 'error' => $msg]];
                }
                [$cok, $cmsg] = fp_cron_register($cfg);
                return [200, ['ok' => true, 'message' => $cok ? 'Saved. ' . ucfirst($cmsg) . '.' : 'Saved, but ' . $cmsg . '.']];

            case 'sync':
                if (fp_sync_running()) {
                    return [200, ['ok' => true, 'message' => 'A sync is already running.']];
                }
                $php = fp_env('FLIGHTPLAN_PHP', '/usr/bin/php');
                @exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(fp_sync_script()) . ' --manual > /dev/null 2>&1 &');
                return [200, ['ok' => true, 'message' => 'Sync started.']];

            default:
                return [400, ['ok' => false, 'error' => 'unknown action']];
        }
    } catch (\Throwable $e) {
        error_log('flightplan-api: ' . get_class($e) . ': ' . $e->getMessage());
        return [500, ['ok' => false, 'error' => get_class($e) . ': ' . fp_clean($e->getMessage(), 200)]];
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
    $post = $_POST;
    if (PHP_SAPI === 'cli') {
        // Test hook only: under php-fpm the webGui populates $_POST; the CLI has no web server, so read it here.
        parse_str((string) stream_get_contents(fopen('php://stdin', 'r')), $post);
    }
    [$code, $out] = fp_api_handle($method, $post);
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['http' => $code] + $out, JSON_UNESCAPED_SLASHES);
}
