<?php
declare(strict_types=1);

/**
 * Flightplan CLI entry. Run as `php flightplan-sync.php <mode>`; never reachable from the web.
 *
 *   --cron            one pass (called by the cron entry)
 *   --manual          one pass (spawned by the "Sync now" button)
 *   --install         one pass (called in the background by the .plg install step)
 *   --boot            pass with spaced retries (called in the background by event/started: container autostart,
 *                     DNS and the SecretsMan store are not necessarily ready the instant the array starts)
 *   --register-cron   make the flash .cron file match the config and reload cron, then exit
 *   --unregister-cron remove the flash .cron file and reload cron
 *   --uninstall       used by the remove step: drop the cron entry, delete every marker-owned folder this plugin
 *                     put in the Private Apps folder (foreign folders are never touched), staging and RAM state
 *
 * Exit status: 0 all sources fine, 1 at least one source failed or the config was unusable, 3 another sync holds
 * the lock. A failing sync never aborts anything else: callers always run this with `|| true`.
 */

require_once __DIR__ . '/lib.php';

function fp_cli_say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

function fp_cli_main(array $argv): int
{
    $mode = $argv[1] ?? '';
    if ($mode === '--register-cron') {
        $l = fp_config_load();
        if ($l['status'] !== 'ok') {
            // Leave whatever schedule is registered alone rather than guess from a missing or broken config.
            fp_cli_say('flightplan: config ' . $l['status'] . '; schedule left unchanged');
            return 0;
        }
        [$ok, $msg] = fp_cron_register($l['config']);
        fp_cli_say('flightplan: ' . $msg);
        return $ok ? 0 : 1;
    }
    if ($mode === '--unregister-cron') {
        [$ok, $msg] = fp_cron_register(['schedule_enabled' => false, 'schedule' => '']);
        fp_cli_say('flightplan: ' . $msg);
        return 0;
    }
    if ($mode === '--uninstall') {
        fp_cron_register(['schedule_enabled' => false, 'schedule' => '']);
        $private = fp_private_dir();
        $gone = [];
        foreach (is_dir($private) ? (scandir($private) ?: []) : [] as $e) {
            if ($e !== '.' && $e !== '..' && fp_is_owned($private . '/' . $e)) {
                fp_rmtree($private . '/' . $e);
                $gone[] = $e;
            }
        }
        fp_rmtree(fp_stage_dir());
        fp_rmtree(fp_state_dir());
        fp_cli_say('flightplan: removed ' . count($gone) . ' synced folder(s)');
        return 0;
    }
    if (!in_array($mode, ['--cron', '--manual', '--install', '--boot'], true)) {
        fwrite(STDERR, "usage: flightplan-sync.php --cron|--manual|--install|--boot|--register-cron|--unregister-cron\n");
        return 2;
    }

    $trigger = ltrim($mode, '-');
    $delays = [0];
    if ($mode === '--boot') {
        $spec = fp_env('FLIGHTPLAN_BOOT_DELAYS', '0,30,90,180,300');
        $delays = array_values(array_filter(array_map('intval', explode(',', $spec)), fn($d) => $d >= 0 && $d <= 900));
        $delays = $delays ?: [0];
    }
    $res = ['ran' => false, 'ok' => false, 'busy' => false, 'failed' => 0, 'retryable' => false, 'error' => ''];
    foreach ($delays as $i => $delay) {
        if ($delay > 0) {
            sleep($delay);
        }
        $res = fp_sync_all($trigger);
        fp_cli_say('flightplan: ' . ($res['busy'] ? 'busy' : ($res['ok'] ? 'ok' : 'failed=' . $res['failed'] . ($res['error'] !== '' ? ' ' . $res['error'] : ''))));
        if ($res['ok'] || $res['busy'] || !$res['retryable']) {
            break; // done, someone else is syncing, or retrying cannot help (bad config, no templates, conflict)
        }
    }
    return $res['busy'] ? 3 : ($res['ok'] ? 0 : 1);
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(fp_cli_main($_SERVER['argv'] ?? []));
}
