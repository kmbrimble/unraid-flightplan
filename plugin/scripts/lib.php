<?php
declare(strict_types=1);

/**
 * Flightplan engine. Shared by the CLI entry (flightplan-sync.php), the web endpoint (flightplan-api.php) and
 * tests/run.php. Nothing here references STDOUT, STDERR or $argv (see CLAUDE.md, WebGUI rules): every function
 * returns a result array, and the CLI file does the printing.
 *
 * What it does: for each configured source (a git repo on Gitea or GitHub) it asks the forge API for the branch
 * head SHA and, only when that changed, downloads the archive at that SHA, picks out the template XMLs, builds
 * a folder next to Community Applications' Private Apps folder and swaps it into place. Folders it owns carry a
 * marker file; only marker-owned folders are ever replaced or pruned.
 *
 * Secrets: a source holds a SecretsMan reference ("!secret ns/key"), never a token. The token is resolved in
 * process, held in a PHP string and sent as an HTTP header by libcurl; it is never put on a command line, in the
 * environment, in a file, in the log or in the status JSON.
 */

const FP_MARKER = '.flightplan-owned';
const FP_UA = 'flightplan-unraid';
const FP_MAX_ARCHIVE_BYTES = 50 * 1024 * 1024;
const FP_MAX_ENTRY_BYTES = 1024 * 1024;
const FP_MAX_TOTAL_BYTES = 20 * 1024 * 1024;
const FP_MAX_ENTRIES = 20000;
const FP_MAX_FILES = 2000;
const FP_MAX_WARNINGS = 200;

// ---------------------------------------------------------------------------------------------------------------
// Paths (every one overridable through the environment, for tests)

function fp_env(string $name, string $default): string
{
    $v = getenv($name);
    return ($v !== false && $v !== '') ? $v : $default;
}

function fp_config_path(): string
{
    return fp_env('FLIGHTPLAN_CONFIG', '/boot/config/plugins/flightplan/flightplan.json');
}

function fp_private_dir(): string
{
    return rtrim(fp_env('FLIGHTPLAN_PRIVATE_DIR', '/boot/config/plugins/community.applications/private'), '/');
}

/** Staging lives on the same filesystem as the private folder (flash), so the final swap is a plain rename. */
function fp_stage_dir(): string
{
    return rtrim(fp_env('FLIGHTPLAN_STAGE_DIR', '/boot/config/plugins/flightplan/.stage'), '/');
}

/** Runtime state is RAM only (/tmp): no flash wear, and one resync per boot because it starts empty. */
function fp_state_dir(): string
{
    return rtrim(fp_env('FLIGHTPLAN_STATE_DIR', '/tmp/flightplan'), '/');
}

function fp_github_api(): string
{
    return rtrim(fp_env('FLIGHTPLAN_GITHUB_API', 'https://api.github.com'), '/');
}

function fp_secretsman_src(): string
{
    return fp_env('FLIGHTPLAN_SECRETSMAN_SRC', '/usr/local/emhttp/plugins/unraid-secretsman/src/secretsman.php');
}

function fp_cron_file(): string
{
    return fp_env('FLIGHTPLAN_CRON_FILE', '/boot/config/plugins/flightplan/flightplan.cron');
}

function fp_sync_script(): string
{
    return fp_env('FLIGHTPLAN_SYNC_SCRIPT', '/usr/local/emhttp/plugins/flightplan/scripts/flightplan-sync.php');
}

// ---------------------------------------------------------------------------------------------------------------
// Small filesystem helpers

function fp_mkdir(string $dir, int $mode = 0700): bool
{
    return is_dir($dir) || @mkdir($dir, $mode, true) || is_dir($dir);
}

/** Write via a temp file in the same directory, then rename, so a reader never sees half a file. */
function fp_write_atomic(string $path, string $data): bool
{
    $dir = dirname($path);
    if (!fp_mkdir($dir, 0755)) {
        return false;
    }
    $tmp = $dir . '/.' . basename($path) . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $data) !== strlen($data)) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Recursive delete that never follows a symlink. */
function fp_rmtree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $e) {
        if ($e !== '.' && $e !== '..') {
            fp_rmtree($path . '/' . $e);
        }
    }
    @rmdir($path);
}

/** Strip control characters so a hostile string cannot forge a log line. */
function fp_clean(string $s, int $max = 400): string
{
    $s = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $s) ?? '';
    $s = trim($s);
    return strlen($s) > $max ? substr($s, 0, $max) . '...' : $s;
}

function fp_redact(string $s, array $secrets): string
{
    foreach ($secrets as $x) {
        if (is_string($x) && strlen($x) >= 4) {
            $s = str_replace($x, '[redacted]', $s);
        }
    }
    return $s;
}

// ---------------------------------------------------------------------------------------------------------------
// Log (RAM only, bounded)

function fp_log(string $msg): void
{
    $dir = fp_state_dir();
    if (!fp_mkdir($dir)) {
        return;
    }
    $file = $dir . '/flightplan.log';
    if (is_file($file) && (int) @filesize($file) > 200000) {
        $keep = (string) @file_get_contents($file, false, null, 100000);
        @file_put_contents($file, $keep);
    }
    @file_put_contents($file, gmdate('Y-m-d\TH:i:s\Z') . ' ' . fp_clean($msg, 600) . "\n", FILE_APPEND);
}

function fp_log_tail(int $lines = 60): array
{
    $file = fp_state_dir() . '/flightplan.log';
    if (!is_file($file)) {
        return [];
    }
    $all = preg_split('/\R/', trim((string) @file_get_contents($file))) ?: [];
    return array_slice($all, -$lines);
}

// ---------------------------------------------------------------------------------------------------------------
// Configuration

function fp_default_config(): array
{
    return ['schedule_enabled' => true, 'schedule' => '0 */6 * * *', 'sources' => []];
}

function fp_default_base_url(string $forge): string
{
    return $forge === 'github' ? 'https://github.com' : 'https://git.kiztigs.com';
}

/** Trim and default a source as typed by a person or the UI. Does not validate. */
function fp_source_normalize(array $s): array
{
    $forge = strtolower(trim((string) ($s['forge'] ?? 'gitea')));
    $repo = trim((string) ($s['repo'] ?? ''));
    $repo = trim($repo, '/');
    if (str_ends_with($repo, '.git')) {
        $repo = substr($repo, 0, -4);
    }
    $base = trim((string) ($s['base_url'] ?? ''));
    $base = $base === '' ? fp_default_base_url($forge) : rtrim($base, '/');
    $name = trim((string) ($s['name'] ?? ''));
    if ($name === '' && str_contains($repo, '/')) {
        $name = substr($repo, strrpos($repo, '/') + 1);
    }
    return [
        'id' => trim((string) ($s['id'] ?? '')),
        'forge' => $forge,
        'base_url' => $base,
        'repo' => $repo,
        'branch' => trim((string) ($s['branch'] ?? '')),
        'subpath' => trim(trim((string) ($s['subpath'] ?? '')), '/'),
        'name' => $name,
        'token_ref' => trim((string) ($s['token_ref'] ?? '')),
        'resolve_ip' => trim((string) ($s['resolve_ip'] ?? '')),
    ];
}

/** Returns '' when valid, else a message. Never echoes token_ref back (a raw token may have been pasted there). */
function fp_source_error(array $s): string
{
    if (!in_array($s['forge'], ['gitea', 'github'], true)) {
        return 'forge must be gitea or github';
    }
    if (!preg_match('/^[a-z0-9]{4,16}$/', $s['id'])) {
        return 'bad source id';
    }
    if (!preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $s['base_url'])) {
        return 'base URL must be http(s)://host[:port][/path] with no credentials, query or fragment';
    }
    if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $s['repo']) || str_contains($s['repo'], '..')) {
        return 'repository must look like owner/repo';
    }
    if ($s['branch'] !== '' && (!preg_match('~^[A-Za-z0-9._/-]+$~', $s['branch']) || str_contains($s['branch'], '..')
            || str_starts_with($s['branch'], '/') || str_ends_with($s['branch'], '/'))) {
        return 'branch has characters that are not allowed';
    }
    if ($s['subpath'] !== '') {
        foreach (explode('/', $s['subpath']) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || !preg_match('/^[A-Za-z0-9._ -]+$/', $seg)) {
                return 'subpath has characters that are not allowed';
            }
        }
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $s['name'])) {
        return 'folder name must be letters, digits, dot, dash or underscore (max 64) and not start with a dot';
    }
    if ($s['token_ref'] !== '' && !preg_match('/^!secret [A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $s['token_ref'])) {
        return 'token must be a SecretsMan reference written exactly as: !secret namespace/key (do not paste a token)';
    }
    if ($s['resolve_ip'] !== '' && filter_var($s['resolve_ip'], FILTER_VALIDATE_IP) === false) {
        return 'resolve IP must be an IPv4 or IPv6 address';
    }
    return '';
}

/** Strict 5-field cron expression. It ends up in root's crontab, so nothing but digits and * , - / gets through. */
function fp_cron_valid(string $expr): bool
{
    if (strlen($expr) > 100 || !preg_match('/^[0-9*,\/-]+( [0-9*,\/-]+){4}$/', $expr)) {
        return false;
    }
    $bounds = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
    foreach (explode(' ', $expr) as $i => $field) {
        [$lo, $hi] = $bounds[$i];
        foreach (explode(',', $field) as $item) {
            if (!preg_match('~^(\*|(\d{1,2})(?:-(\d{1,2}))?)(?:/(\d{1,2}))?$~', $item, $m)) {
                return false;
            }
            if ($m[1] !== '*') {
                $a = (int) $m[2];
                $b = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $a;
                if ($a < $lo || $b > $hi || $a > $b) {
                    return false;
                }
                if (isset($m[4]) && $m[4] !== '' && ($m[3] ?? '') === '') {
                    return false; // "5/10" is not portable cron
                }
            }
            if (isset($m[4]) && $m[4] !== '' && ((int) $m[4] < 1 || (int) $m[4] > 59)) {
                return false;
            }
        }
    }
    return true;
}

/** Normalise and validate a whole config. Returns [config, error]; error '' means valid. */
function fp_config_check(array $c): array
{
    $out = fp_default_config();
    $out['schedule_enabled'] = (bool) ($c['schedule_enabled'] ?? true);
    $out['schedule'] = trim((string) ($c['schedule'] ?? $out['schedule']));
    if ($out['schedule_enabled'] && !fp_cron_valid($out['schedule'])) {
        return [$out, 'schedule is not a valid 5-field cron expression'];
    }
    $srcs = $c['sources'] ?? null;
    if (!is_array($srcs) || !array_is_list($srcs)) {
        return [$out, 'sources must be a list'];
    }
    if (count($srcs) > 50) {
        return [$out, 'too many sources (max 50)'];
    }
    $names = [];
    $ids = [];
    foreach ($srcs as $i => $raw) {
        if (!is_array($raw)) {
            return [$out, 'source ' . ($i + 1) . ' is not an object'];
        }
        $s = fp_source_normalize($raw);
        if ($s['id'] === '') {
            $s['id'] = substr(bin2hex(random_bytes(6)), 0, 8);
        }
        $err = fp_source_error($s);
        if ($err !== '') {
            return [$out, 'source ' . ($i + 1) . ': ' . $err];
        }
        $lk = strtolower($s['name']);
        if (isset($names[$lk])) {
            return [$out, 'source ' . ($i + 1) . ': folder name "' . $s['name'] . '" is used twice'];
        }
        if (isset($ids[$s['id']])) {
            return [$out, 'source ' . ($i + 1) . ': duplicate id'];
        }
        $names[$lk] = true;
        $ids[$s['id']] = true;
        $out['sources'][] = $s;
    }
    return [$out, ''];
}

/**
 * Load the config. status: ok | missing | unreadable. Only 'ok' (including a valid empty list) may drive pruning.
 */
function fp_config_load(): array
{
    $path = fp_config_path();
    if (!is_file($path)) {
        return ['status' => 'missing', 'config' => fp_default_config(), 'error' => 'config file not found'];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return ['status' => 'unreadable', 'config' => fp_default_config(), 'error' => 'config file could not be read'];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['status' => 'unreadable', 'config' => fp_default_config(), 'error' => 'config file is not valid JSON'];
    }
    [$cfg, $err] = fp_config_check($data);
    if ($err !== '') {
        return ['status' => 'unreadable', 'config' => fp_default_config(), 'error' => $err];
    }
    return ['status' => 'ok', 'config' => $cfg, 'error' => ''];
}

/** Validate and persist. Writes flash only when the content changed. Returns [ok, message, config]. */
function fp_config_save(array $input): array
{
    [$cfg, $err] = fp_config_check($input);
    if ($err !== '') {
        return [false, $err, $cfg];
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $path = fp_config_path();
    if (!(is_file($path) && @file_get_contents($path) === $json)) {
        if (!fp_write_atomic($path, $json)) {
            return [false, 'could not write the config file', $cfg];
        }
    }
    return [true, 'saved', $cfg];
}

// ---------------------------------------------------------------------------------------------------------------
// Cron registration

function fp_cron_line(array $cfg): string
{
    return $cfg['schedule'] . ' php ' . escapeshellarg(fp_sync_script()) . ' --cron > /dev/null 2>&1';
}

/**
 * Make the flash .cron file match the config (rewritten only when different) and ALWAYS tell Unraid to rebuild the
 * crontab afterwards. The second part matters at boot: the flash file persists but the RAM crontab does not, so
 * "file already correct" must still reload cron (update_cron concatenates every installed plugin's *.cron).
 */
function fp_cron_register(array $cfg): array
{
    $file = fp_cron_file();
    $want = null;
    if ($cfg['schedule_enabled'] && fp_cron_valid($cfg['schedule'])) {
        $want = "# Flightplan template sync\n" . fp_cron_line($cfg) . "\n";
    }
    $have = is_file($file) ? (string) @file_get_contents($file) : null;
    $msg = 'schedule unchanged';
    if ($want === null) {
        $msg = $have === null ? 'schedule off' : 'schedule removed';
        if ($have !== null) {
            @unlink($file);
        }
    } elseif ($have !== $want) {
        if (!fp_write_atomic($file, $want)) {
            return [false, 'could not write the cron file'];
        }
        $msg = 'schedule registered';
    }
    $uc = fp_env('FLIGHTPLAN_UPDATE_CRON', '/usr/local/sbin/update_cron');
    if ($uc !== '-' && is_file($uc) && ($want !== null || $have !== null)) {
        @exec(escapeshellarg($uc) . ' > /dev/null 2>&1');
    }
    return [true, $msg];
}

// ---------------------------------------------------------------------------------------------------------------
// HTTP (libcurl through the PHP extension: headers never touch argv)

function fp_origin(string $url): string
{
    $p = parse_url($url);
    if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
        return '';
    }
    $scheme = strtolower($p['scheme']);
    $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
    return $scheme . '://' . strtolower($p['host']) . ':' . $port;
}

function fp_url_join(string $base, string $loc): string
{
    if (preg_match('~^https?://~i', $loc)) {
        return $loc;
    }
    $p = parse_url($base);
    $scheme = $p['scheme'] ?? 'https';
    $auth = ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($loc, '//')) {
        return $scheme . ':' . $loc;
    }
    if (str_starts_with($loc, '/')) {
        return $scheme . '://' . $auth . $loc;
    }
    $dir = rtrim(dirname($p['path'] ?? '/'), '/');
    return $scheme . '://' . $auth . $dir . '/' . $loc;
}

/**
 * GET with manual redirect handling. $o: headers (list), auth (one full header line, sent ONLY to the origin of
 * the first URL, never to a redirect target such as a CDN), resolve (curl RESOLVE entries), dest (stream the body
 * to this file), max_bytes, timeout. Returns ok, status, body, error, bytes. The error never contains a URL query.
 */
function fp_http_get(string $url, array $o = []): array
{
    $headers = $o['headers'] ?? [];
    $auth = (string) ($o['auth'] ?? '');
    $dest = $o['dest'] ?? null;
    $max = (int) ($o['max_bytes'] ?? 4 * 1024 * 1024);
    $timeout = (int) ($o['timeout'] ?? 60);
    $origin = fp_origin($url);
    $cur = $url;
    $res = ['ok' => false, 'status' => 0, 'body' => '', 'error' => '', 'bytes' => 0];

    for ($hop = 0; $hop <= 5; $hop++) {
        $ch = curl_init($cur);
        $h = $headers;
        if ($auth !== '' && fp_origin($cur) === $origin) {
            $h[] = $auth;
        }
        $status = 0;
        $loc = '';
        $got = 0;
        $body = '';
        $fh = null;
        $tooBig = false;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => FP_UA,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => $o['resolve'] ?? [],
            CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$status, &$loc): int {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                    $status = (int) $m[1];
                    $loc = '';
                } elseif (stripos($line, 'location:') === 0) {
                    $loc = trim(substr($line, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($c, string $data) use (&$status, &$got, &$body, &$fh, &$tooBig, $max, $dest): int {
                $n = strlen($data);
                if ($status >= 300 && $status < 400) {
                    return $n; // a redirect's body is of no interest
                }
                $got += $n;
                if ($got > $max) {
                    $tooBig = true;
                    return 0;
                }
                if ($dest !== null && $status >= 200 && $status < 300) {
                    if ($fh === null) {
                        $fh = @fopen($dest, 'wb');
                        if ($fh === false) {
                            return 0;
                        }
                    }
                    return fwrite($fh, $data) === $n ? $n : 0;
                }
                $body .= $data;
                return $n;
            },
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if (is_resource($fh)) {
            fclose($fh);
        }

        if ($tooBig) {
            $res['error'] = 'response larger than the ' . (int) ($max / 1048576) . ' MiB limit';
            return $res;
        }
        if ($errno !== 0) {
            $res['error'] = 'network error: ' . fp_clean($err, 200);
            return $res;
        }
        if (in_array($status, [301, 302, 303, 307, 308], true) && $loc !== '') {
            $next = fp_url_join($cur, $loc);
            if (!preg_match('~^https?://~i', $next)
                || (stripos($cur, 'https://') === 0 && stripos($next, 'https://') !== 0)) {
                $res['error'] = 'refused a redirect to a non-HTTPS or unsupported URL';
                return $res;
            }
            $cur = $next;
            continue;
        }
        $res['status'] = $status;
        $res['ok'] = $status >= 200 && $status < 300;
        $res['body'] = $body;
        $res['bytes'] = $got;
        if (!$res['ok']) {
            $res['error'] = 'HTTP ' . $status;
        }
        return $res;
    }
    $res['error'] = 'too many redirects';
    return $res;
}

// ---------------------------------------------------------------------------------------------------------------
// Secrets (SecretsMan)

/**
 * Resolve a "!secret ns/key" reference through the installed SecretsMan plugin's own library, the same entry
 * points the hl CLI uses. Returns [ok, token, error]. An empty reference means "anonymous" and is ok.
 */
function fp_token_resolve(string $ref): array
{
    if ($ref === '') {
        return [true, '', ''];
    }
    if (!preg_match('/^!secret ([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+)$/', $ref, $m)) {
        return [false, '', 'token reference is malformed'];
    }
    $src = fp_secretsman_src();
    if (!is_file($src)) {
        return [false, '', 'SecretsMan is not installed, so the token reference cannot be resolved'];
    }
    try {
        require_once $src;
        $store = secretsman_load_store(secretsman_default_store_path());
        $token = secretsman_lookup($store, $m[1], $m[2]);
    } catch (\Throwable $e) {
        return [false, '', 'SecretsMan: ' . fp_clean($e->getMessage(), 200)];
    }
    if ($token === '') {
        return [false, '', 'SecretsMan: the referenced secret is empty'];
    }
    return [true, $token, ''];
}

// ---------------------------------------------------------------------------------------------------------------
// Forges

function fp_source_http_opts(array $s, string $token, array $extra = []): array
{
    $accept = $s['forge'] === 'github' ? 'Accept: application/vnd.github+json' : 'Accept: application/json';
    $o = ['headers' => [$accept]] + $extra;
    if ($token !== '') {
        $o['auth'] = $s['forge'] === 'github' ? 'Authorization: Bearer ' . $token : 'Authorization: token ' . $token;
    }
    if ($s['forge'] === 'gitea' && $s['resolve_ip'] !== '') {
        $p = parse_url($s['base_url']);
        $port = $p['port'] ?? (($p['scheme'] ?? 'https') === 'https' ? 443 : 80);
        $ip = str_contains($s['resolve_ip'], ':') ? '[' . $s['resolve_ip'] . ']' : $s['resolve_ip'];
        $o['resolve'] = [($p['host'] ?? '') . ':' . $port . ':' . $ip];
    }
    return $o;
}

function fp_explain_status(array $r, array $s, bool $hadToken): string
{
    if ($r['status'] === 401 || $r['status'] === 403) {
        return 'HTTP ' . $r['status'] . ($hadToken ? ': the token was rejected or lacks read access' : ': this forge needs a token (none configured)');
    }
    if ($r['status'] === 404) {
        return 'HTTP 404: repository or branch not found, or not visible to this ' . ($hadToken ? 'token' : 'anonymous request');
    }
    return $r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['status'];
}

/** Ask the forge for the default branch (if none set) and the head SHA. Returns [ok, branch, sha, archiveUrl, error]. */
function fp_forge_head(array $s, string $token): array
{
    $enc = fn(string $b): string => implode('/', array_map('rawurlencode', explode('/', $b)));
    if ($s['forge'] === 'github') {
        $api = fp_github_api() . '/repos/' . $s['repo'];
    } else {
        $api = $s['base_url'] . '/api/v1/repos/' . $s['repo'];
    }
    $branch = $s['branch'];
    if ($branch === '') {
        $r = fp_http_get($api, fp_source_http_opts($s, $token));
        $d = $r['ok'] ? json_decode($r['body'], true) : null;
        if (!$r['ok'] || !is_array($d) || !is_string($d['default_branch'] ?? null) || $d['default_branch'] === '') {
            return [false, '', '', '', 'repository lookup failed: ' . ($r['ok'] ? 'unexpected response' : fp_explain_status($r, $s, $token !== ''))];
        }
        $branch = $d['default_branch'];
    }
    $r = fp_http_get($api . '/branches/' . $enc($branch), fp_source_http_opts($s, $token));
    $d = $r['ok'] ? json_decode($r['body'], true) : null;
    $sha = $s['forge'] === 'github' ? ($d['commit']['sha'] ?? null) : ($d['commit']['id'] ?? null);
    if (!$r['ok'] || !is_string($sha) || !preg_match('/^[0-9a-f]{40,64}$/', $sha)) {
        return [false, $branch, '', '', 'branch lookup failed: ' . ($r['ok'] ? 'unexpected response' : fp_explain_status($r, $s, $token !== ''))];
    }
    $archive = $s['forge'] === 'github' ? $api . '/zipball/' . $sha : $api . '/archive/' . $sha . '.zip';
    return [true, $branch, $sha, $archive, ''];
}

// ---------------------------------------------------------------------------------------------------------------
// Archive handling

/**
 * Read template candidates out of the archive without extracting anything to disk. Names are taken from the zip
 * entries by basename only. Returns [ok, files(basename => content), notes, error].
 */
function fp_zip_select(string $zipPath, string $subpath): array
{
    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
        return [false, [], [], 'the downloaded archive could not be opened'];
    }
    $notes = [];
    $cands = [];
    $n = min($zip->numFiles, FP_MAX_ENTRIES);
    for ($i = 0; $i < $n; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) {
            continue;
        }
        $name = (string) $st['name'];
        if ($name === '' || str_ends_with($name, '/') || str_contains($name, "\0") || str_contains($name, '\\')
            || str_starts_with($name, '/')) {
            continue;
        }
        $segs = explode('/', $name);
        if (in_array('..', $segs, true) || count($segs) < 2) {
            continue;
        }
        array_shift($segs); // the archive's single top-level directory (owner-repo-sha/, repo/)
        if ($subpath !== '') {
            $sp = explode('/', $subpath);
            if (count($segs) <= count($sp) || array_slice($segs, 0, count($sp)) !== $sp) {
                continue;
            }
            $segs = array_slice($segs, count($sp));
        }
        $skip = false;
        foreach ($segs as $seg) {
            if ($seg === '' || $seg[0] === '.') {
                $skip = true; // dot-dirs and hidden files
            }
        }
        $base = end($segs);
        if ($skip || !preg_match('/\.xml$/i', $base) || strtolower($base) === 'ca_profile.xml') {
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9._ ()+,@=-]+$/', $base)) {
            $notes[] = 'skipped ' . fp_clean(implode('/', $segs), 120) . ': file name has unsupported characters';
            continue;
        }
        if ((int) $st['size'] > FP_MAX_ENTRY_BYTES) {
            $notes[] = 'skipped ' . fp_clean(implode('/', $segs), 120) . ': larger than 1 MiB';
            continue;
        }
        $cands[] = ['rel' => implode('/', $segs), 'base' => $base, 'index' => $i, 'depth' => count($segs)];
    }
    // Deterministic duplicate handling: shallowest path wins, then plain byte order. FAT is case-insensitive, so
    // names that differ only in case count as the same file.
    usort($cands, fn($a, $b) => [$a['depth'], $a['rel']] <=> [$b['depth'], $b['rel']]);
    $files = [];
    $kept = [];
    $total = 0;
    foreach ($cands as $c) {
        $key = strtolower($c['base']);
        if (isset($kept[$key])) {
            $notes[] = 'duplicate file name ' . $c['base'] . ': kept ' . fp_clean($kept[$key], 120) . ', skipped ' . fp_clean($c['rel'], 120);
            continue;
        }
        $content = $zip->getFromIndex($c['index'], FP_MAX_ENTRY_BYTES + 1);
        if ($content === false || strlen($content) > FP_MAX_ENTRY_BYTES) {
            $notes[] = 'skipped ' . fp_clean($c['rel'], 120) . ': could not be read within the size limit';
            continue;
        }
        $total += strlen($content);
        if ($total > FP_MAX_TOTAL_BYTES || count($files) >= FP_MAX_FILES) {
            $notes[] = 'stopped at the template count/size limit';
            break;
        }
        $kept[$key] = $c['rel'];
        $files[$c['base']] = $content;
    }
    $zip->close();
    ksort($files, SORT_STRING);
    return [true, $files, $notes, ''];
}

// ---------------------------------------------------------------------------------------------------------------
// Templates and the GitHub-reference validator

/** Elements that carry a URL or an image reference. Free text (Overview, Changes) is deliberately not scanned. */
const FP_URL_TAGS = ['PluginURL', 'Repository', 'TemplateURL', 'Icon', 'Support', 'Project', 'ReadMe', 'Registry', 'DonateLink', 'WebUI'];

function fp_github_host(string $value): string
{
    $v = strtolower(trim($value));
    $v = preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $v) ?? $v;
    $v = preg_replace('~^[^/@\s]*@~', '', $v) ?? $v; // drop any userinfo before matching
    if (preg_match('~(?:^|[/@.\s])(github\.com|(?:[a-z0-9-]+\.)?githubusercontent\.com|ghcr\.io)(?=[/:\s]|$)~', $v, $m)) {
        return $m[1];
    }
    return '';
}

/** Where Kieren's own plugins and templates are served from; GitHub references into his account should move here. */
const FP_EXPECTED_ORIGIN = 'git.kiztigs.com';
const FP_OWN_ACCOUNT = 'kmbrimble';

/**
 * Classify a GitHub reference: 'moveme' (his own account, so it belongs on the expected origin: this includes his own
 * ghcr.io images, which now live in Gitea's container registry at git.kiztigs.com/kmbrimble/<image>) or 'third'
 * (someone else's upstream, informational). References to Gitea itself are not GitHub hosts and are never flagged.
 */
function fp_ref_kind(string $tag, string $host, string $value): string
{
    $v = strtolower(trim($value));
    $v = preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $v) ?? $v;
    $own = (bool) preg_match('~(?:^|[/@.])' . preg_quote($host, '~') . '[/:]' . preg_quote(FP_OWN_ACCOUNT, '~') . '(?:[/:@]|$)~', $v);
    if (!$own) {
        return 'third';
    }
    return 'moveme';
}

function fp_value_for_display(string $v): string
{
    $v = preg_replace('~(://)[^/@\s]*@~', '$1', $v) ?? $v;
    return fp_clean($v, 160);
}

/** Parse one template. Returns [ok, error, warnings(list of string), refs(list of [tag,host,value,kind])]. */
function fp_template_inspect(string $xml): array
{
    if (stripos($xml, '<!ENTITY') !== false || stripos($xml, '<!DOCTYPE') !== false) {
        return [false, 'DOCTYPE/ENTITY declarations are not allowed', [], []];
    }
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($x === false) {
        return [false, 'not well-formed XML', [], []];
    }
    if ($x->getName() !== 'Container') {
        return [false, 'root element is not Container', [], []];
    }
    $warn = [];
    $repo = trim((string) $x->Repository);
    $isPlugin = strtolower(trim((string) $x->Plugin)) === 'true';
    if ($isPlugin && trim((string) $x->PluginURL) === '') {
        $warn[] = 'Plugin template has no PluginURL, Community Applications will skip it';
    } elseif (!$isPlugin && $repo === '') {
        $warn[] = 'no Repository, Community Applications will skip it';
    }
    $refs = [];
    foreach (FP_URL_TAGS as $tag) {
        foreach ($x->{$tag} as $node) {
            $val = trim((string) $node);
            $host = $val === '' ? '' : fp_github_host($val);
            if ($host !== '') {
                $refs[] = [$tag, $host, fp_value_for_display($val), fp_ref_kind($tag, $host, $val)];
            }
        }
    }
    return [true, '', $warn, $refs];
}

// ---------------------------------------------------------------------------------------------------------------
// Owned folders, staging, swap, prune

function fp_is_owned(string $dir): bool
{
    if (is_link($dir) || !is_dir($dir)) {
        return false;
    }
    $m = $dir . '/' . FP_MARKER;
    if (is_link($m) || !is_file($m)) {
        return false;
    }
    $d = json_decode((string) @file_get_contents($m), true);
    return is_array($d) && ($d['tool'] ?? '') === 'flightplan';
}

/** Find an existing entry in the private folder whose name matches case-insensitively (FAT semantics). */
function fp_find_dir(string $private, string $name): ?string
{
    if (!is_dir($private)) {
        return null;
    }
    foreach (scandir($private) ?: [] as $e) {
        if ($e !== '.' && $e !== '..' && strcasecmp($e, $name) === 0) {
            return $e;
        }
    }
    return null;
}

function fp_marker_json(array $s, string $sha): string
{
    return json_encode(['tool' => 'flightplan', 'source' => $s['id'], 'repo' => $s['repo'], 'sha' => $sha, 'synced' => time()]) . "\n";
}

/** Swap $stage into $dest. The old copy is moved aside first and put back if the second rename fails. */
function fp_swap(string $stage, string $dest, string $oldSlot): array
{
    fp_rmtree($oldSlot);
    $hadOld = file_exists($dest) || is_link($dest);
    if ($hadOld && !@rename($dest, $oldSlot)) {
        return [false, 'could not move the existing copy aside'];
    }
    if (!@rename($stage, $dest)) {
        if ($hadOld) {
            @rename($oldSlot, $dest);
        }
        return [false, 'could not move the new copy into place'];
    }
    fp_rmtree($oldSlot);
    return [true, ''];
}

// ---------------------------------------------------------------------------------------------------------------
// Sync

function fp_prev_ok(array $prev, string $sha): bool
{
    return ($prev['status'] ?? '') !== 'error' && ($prev['sha'] ?? '') === $sha && ($prev['count'] ?? 0) > 0;
}

/**
 * Sync one source. $prev is that source's entry from the last run's state. A failure of any kind leaves the
 * existing folder exactly as it was. Returns the new state entry.
 */
function fp_sync_source(array $s, array $prev): array
{
    $now = time();
    $private = fp_private_dir();
    $r = [
        'id' => $s['id'], 'folder' => $s['name'], 'forge' => $s['forge'], 'repo' => $s['repo'],
        'branch' => $s['branch'], 'sha' => $prev['sha'] ?? '', 'count' => $prev['count'] ?? 0,
        'status' => 'error', 'error' => '', 'checked_at' => $now, 'synced_at' => $prev['synced_at'] ?? 0,
        'notes' => $prev['notes'] ?? [], 'warnings' => $prev['warnings'] ?? [], 'warning_total' => $prev['warning_total'] ?? 0,
        'template_warnings' => $prev['template_warnings'] ?? [],
    ];
    $token = '';
    $fail = function (string $msg) use (&$r, &$token): array {
        $r['status'] = 'error';
        $r['error'] = fp_clean(fp_redact($msg, [$token]), 300);
        fp_log('source ' . $r['folder'] . ': ' . $r['error'] . ' (existing copy kept)');
        return $r;
    };

    [$ok, $token, $err] = fp_token_resolve($s['token_ref']);
    if (!$ok) {
        return $fail($err);
    }
    [$ok, $branch, $sha, $archive, $err] = fp_forge_head($s, $token);
    if ($branch !== '') {
        $r['branch'] = $branch;
    }
    if (!$ok) {
        return $fail($err);
    }

    $existing = fp_find_dir($private, $s['name']);
    $dest = $private . '/' . ($existing ?? $s['name']);
    if ($existing !== null && !fp_is_owned($dest)) {
        return $fail('a folder named "' . $existing . '" already exists in the Private Apps folder and is not owned by Flightplan; it was not touched');
    }
    if ($existing !== null && fp_prev_ok($prev, $sha)) {
        $r['sha'] = $sha;
        $r['status'] = 'unchanged';
        $r['error'] = '';
        fp_log('source ' . $r['folder'] . ': unchanged at ' . substr($sha, 0, 8));
        return $r;
    }

    // Download the archive at exactly this SHA into RAM-backed /tmp.
    $tmpDir = fp_state_dir() . '/dl';
    if (!fp_mkdir($tmpDir)) {
        return $fail('could not create the temporary download folder');
    }
    $zipPath = $tmpDir . '/' . $s['id'] . '.' . getmypid() . '.zip';
    $d = fp_http_get($archive, fp_source_http_opts($s, $token, ['dest' => $zipPath, 'max_bytes' => FP_MAX_ARCHIVE_BYTES, 'timeout' => 180]));
    if (!$d['ok']) {
        @unlink($zipPath);
        return $fail('archive download failed: ' . ($d['status'] > 0 ? fp_explain_status($d, $s, $token !== '') : $d['error']));
    }
    [$ok, $files, $notes, $err] = fp_zip_select($zipPath, $s['subpath']);
    @unlink($zipPath);
    if (!$ok) {
        return $fail($err);
    }

    // Keep only well-formed Container templates; remember the GitHub references and CA-skip warnings.
    $good = [];
    $refsAll = [];
    $tplWarn = [];
    foreach ($files as $name => $content) {
        [$valid, $e, $warn, $refs] = fp_template_inspect($content);
        if (!$valid) {
            $notes[] = 'skipped ' . $name . ': ' . $e;
            continue;
        }
        $good[$name] = $content;
        foreach ($warn as $w) {
            $tplWarn[] = $name . ': ' . $w;
        }
        foreach ($refs as [$tag, $host, $val, $kind]) {
            $refsAll[] = ['file' => $name, 'tag' => $tag, 'host' => $host, 'value' => $val, 'kind' => $kind];
        }
    }
    $r['notes'] = array_slice($notes, 0, 100);
    foreach ($notes as $n) {
        fp_log('source ' . $r['folder'] . ': ' . $n);
    }
    if (count($good) === 0) {
        return $fail('no usable template XML files found in the archive' . ($s['subpath'] !== '' ? ' under "' . $s['subpath'] . '"' : ''));
    }

    // Build next to (not inside) the Private Apps folder, on the same filesystem, then swap.
    $stageRoot = fp_stage_dir();
    $stage = $stageRoot . '/' . $s['name'] . '.new';
    fp_rmtree($stage);
    if (!fp_mkdir($stage, 0755)) {
        return $fail('could not create the staging folder');
    }
    foreach ($good as $name => $content) {
        if (@file_put_contents($stage . '/' . $name, $content) !== strlen($content)) {
            fp_rmtree($stage);
            return $fail('could not write a staged template');
        }
    }
    if (@file_put_contents($stage . '/' . FP_MARKER, fp_marker_json($s, $sha)) === false) {
        fp_rmtree($stage);
        return $fail('could not write the ownership marker');
    }
    if (!fp_mkdir($private, 0755)) {
        fp_rmtree($stage);
        return $fail('could not create the Private Apps folder');
    }
    // Re-check just before the swap: another writer may have created a foreign folder meanwhile.
    $existing = fp_find_dir($private, $s['name']);
    $dest = $private . '/' . ($existing ?? $s['name']);
    if ($existing !== null && !fp_is_owned($dest)) {
        fp_rmtree($stage);
        return $fail('a folder named "' . $existing . '" appeared and is not owned by Flightplan; it was not touched');
    }
    [$ok, $err] = fp_swap($stage, $dest, $stageRoot . '/' . $s['name'] . '.old');
    if (!$ok) {
        fp_rmtree($stage);
        return $fail($err);
    }

    $r['sha'] = $sha;
    $r['count'] = count($good);
    $r['status'] = 'updated';
    $r['error'] = '';
    $r['synced_at'] = time();
    $r['warning_total'] = count($refsAll);
    $r['move_total'] = count(array_filter($refsAll, fn($x) => $x['kind'] === 'moveme'));
    $r['warnings'] = array_slice($refsAll, 0, FP_MAX_WARNINGS);
    $r['template_warnings'] = array_slice($tplWarn, 0, 100);
    fp_log('source ' . $r['folder'] . ': updated to ' . substr($sha, 0, 8) . ' (' . count($good) . ' templates, ' . count($refsAll) . ' GitHub references, ' . $r['move_total'] . ' of them yours to move to ' . FP_EXPECTED_ORIGIN . ')');
    return $r;
}

/** Remove marker-owned folders whose source no longer exists. Only called with a successfully loaded config. */
function fp_prune(array $cfg): array
{
    $private = fp_private_dir();
    $keep = [];
    foreach ($cfg['sources'] as $s) {
        $keep[strtolower($s['name'])] = true;
    }
    $gone = [];
    if (!is_dir($private)) {
        return $gone;
    }
    foreach (scandir($private) ?: [] as $e) {
        if ($e === '.' || $e === '..' || isset($keep[strtolower($e)])) {
            continue;
        }
        if (fp_is_owned($private . '/' . $e)) {
            fp_rmtree($private . '/' . $e);
            $gone[] = $e;
            fp_log('pruned ' . $e . ' (its source was removed)');
        }
    }
    return $gone;
}

// ---------------------------------------------------------------------------------------------------------------
// State and the sync driver

function fp_state_load(): array
{
    $f = fp_state_dir() . '/state.json';
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return is_array($d) ? $d : ['sources' => []];
}

function fp_state_save(array $st): void
{
    fp_write_atomic(fp_state_dir() . '/state.json', json_encode($st, JSON_UNESCAPED_SLASHES) . "\n");
}

/** True while a sync holds the lock. */
function fp_sync_running(): bool
{
    $dir = fp_state_dir();
    if (!fp_mkdir($dir)) {
        return false;
    }
    $fh = @fopen($dir . '/sync.lock', 'c');
    if ($fh === false) {
        return false;
    }
    $free = flock($fh, LOCK_SH | LOCK_NB);
    if ($free) {
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return !$free;
}

/**
 * One full pass over every source, then the prune. One lock for cron, boot, install and the button. Returns
 * ['ran'=>bool, 'busy'=>bool, 'ok'=>bool, 'failed'=>int, 'retryable'=>bool, 'error'=>string].
 */
function fp_sync_all(string $trigger): array
{
    $dir = fp_state_dir();
    if (!fp_mkdir($dir)) {
        return ['ran' => false, 'busy' => false, 'ok' => false, 'failed' => 0, 'retryable' => false, 'error' => 'no state directory'];
    }
    $lock = @fopen($dir . '/sync.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['ran' => false, 'busy' => true, 'ok' => false, 'failed' => 0, 'retryable' => false, 'error' => 'a sync is already running'];
    }
    try {
        $st = fp_state_load();
        $loaded = fp_config_load();
        $st['last_trigger'] = $trigger;
        $st['config_status'] = $loaded['status'];
        $st['config_error'] = $loaded['error'];
        $st['started_at'] = time();
        fp_log('sync started (' . $trigger . ')');

        if ($loaded['status'] !== 'ok') {
            // Missing or unreadable source list: do nothing at all, in particular prune nothing.
            fp_log('sync skipped: ' . $loaded['error'] . '; nothing pruned');
            $st['last_sync'] = time();
            $st['last_ok'] = false;
            $st['pruned'] = [];
            fp_state_save($st);
            return ['ran' => true, 'busy' => false, 'ok' => false, 'failed' => 0, 'retryable' => false, 'error' => $loaded['error']];
        }

        $cfg = $loaded['config'];
        $prevAll = is_array($st['sources'] ?? null) ? $st['sources'] : [];
        $new = [];
        $failed = 0;
        $retry = false;
        foreach ($cfg['sources'] as $s) {
            try {
                $res = fp_sync_source($s, is_array($prevAll[$s['id']] ?? null) ? $prevAll[$s['id']] : []);
            } catch (\Throwable $e) {
                $res = ['id' => $s['id'], 'folder' => $s['name'], 'forge' => $s['forge'], 'repo' => $s['repo'], 'branch' => $s['branch'],
                    'sha' => '', 'count' => 0, 'status' => 'error', 'error' => fp_clean(get_class($e) . ': ' . $e->getMessage(), 300),
                    'checked_at' => time(), 'synced_at' => 0, 'notes' => [], 'warnings' => [], 'warning_total' => 0, 'template_warnings' => []];
                fp_log('source ' . $s['name'] . ': ' . $res['error']);
            }
            if ($res['status'] === 'error') {
                $failed++;
                $retry = $retry || preg_match('/network error|HTTP 5\d\d|download failed|lookup failed|SecretsMan/', $res['error']) === 1;
            }
            $new[$s['id']] = $res;
        }
        $st['sources'] = $new;
        $st['pruned'] = fp_prune($cfg);
        $st['last_sync'] = time();
        $st['last_ok'] = $failed === 0;
        fp_state_save($st);
        fp_log('sync finished: ' . count($cfg['sources']) . ' sources, ' . $failed . ' failed');
        return ['ran' => true, 'busy' => false, 'ok' => $failed === 0, 'failed' => $failed, 'retryable' => $retry, 'error' => ''];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Status for the UI

function fp_status(): array
{
    $loaded = fp_config_load();
    $st = fp_state_load();
    $srcs = [];
    foreach ($loaded['config']['sources'] as $s) {
        $srcs[] = $s + ['state' => is_array($st['sources'][$s['id']] ?? null) ? $st['sources'][$s['id']] : null];
    }
    $cfg = $loaded['config'];
    $cfg['sources'] = $srcs;
    return [
        'config_status' => $loaded['status'],
        'config_error' => $loaded['error'],
        'config' => $cfg,
        'running' => fp_sync_running(),
        'last_sync' => $st['last_sync'] ?? 0,
        'last_ok' => $st['last_ok'] ?? null,
        'last_trigger' => $st['last_trigger'] ?? '',
        'pruned' => $st['pruned'] ?? [],
        'secretsman_installed' => is_file(fp_secretsman_src()),
        'log' => fp_log_tail(),
        'now' => time(),
    ];
}
