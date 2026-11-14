<?php
// Router for `php -S`: a tiny fake Gitea (/api/v1/...) and GitHub (/repos/...) plus a CDN path for redirects.
// Behaviour is read from $FAKE_DIR/mode.json on every request; every request is appended to requests.log with the
// Authorization header it carried, so tests can prove what the server really received.
$dir = (string) getenv('FAKE_DIR');
$mode = json_decode((string) @file_get_contents("$dir/mode.json"), true) ?: ['repos' => []];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
file_put_contents("$dir/requests.log", json_encode(['path' => $path, 'auth' => $auth, 'port' => (int) $_SERVER['SERVER_PORT']]) . "\n", FILE_APPEND | LOCK_EX);

function fake_out(int $code, string $body, string $type = 'application/json'): void
{
    http_response_code($code);
    header('Content-Type: ' . $type);
    echo $body;
}

$forge = null;
$repoKey = '';
$rest = '';
if (preg_match('~^/api/v1/repos/([^/]+/[^/]+)(/.*)?$~', $path, $m)) {
    [$forge, $repoKey, $rest] = ['gitea', $m[1], $m[2] ?? ''];
} elseif (preg_match('~^/repos/([^/]+/[^/]+)(/.*)?$~', $path, $m)) {
    [$forge, $repoKey, $rest] = ['github', $m[1], $m[2] ?? ''];
} elseif (preg_match('~^/cdn/([^/]+)\.zip$~', $path, $m)) {
    $r = $mode['repos'][str_replace('__', '/', $m[1])] ?? null;
    if ($r === null) {
        fake_out(404, '{}');
    } else {
        header('Content-Type: application/zip');
        readfile($r['zip']);
    }
    return;
}
if ($forge === null || !isset($mode['repos'][$repoKey])) {
    fake_out(404, '{"message":"not found"}');
    return;
}
$r = $mode['repos'][$repoKey];
if (!empty($r['token'])) {
    $want = ($forge === 'github' ? 'Bearer ' : 'token ') . $r['token'];
    if ($auth !== $want) {
        fake_out(403, '{"message":"Only signed in user is allowed to call APIs."}');
        return;
    }
}
if ($rest === '') {
    fake_out(200, json_encode(['default_branch' => $r['default_branch'] ?? 'main']));
} elseif (preg_match('~^/branches/(.+)$~', $rest, $b)) {
    $branch = rawurldecode($b[1]);
    $sha = $r['branches'][$branch] ?? null;
    if ($sha === null) {
        fake_out(404, '{"message":"branch not found"}');
    } elseif ($forge === 'github') {
        fake_out(200, json_encode(['name' => $branch, 'commit' => ['sha' => $sha]]));
    } else {
        fake_out(200, json_encode(['name' => $branch, 'commit' => ['id' => $sha]]));
    }
} elseif (preg_match('~^/(?:archive|zipball)/([0-9a-f]+?)(?:\.zip)?$~', $rest, $a)) {
    if (!empty($r['archive_delay_ms'])) {
        usleep((int) $r['archive_delay_ms'] * 1000);
    }
    if (($r['archive_status'] ?? 200) !== 200) {
        fake_out((int) $r['archive_status'], '{"message":"boom"}');
    } elseif (!in_array($a[1], $r['branches'], true)) {
        fake_out(404, '{"message":"no such commit"}');
    } elseif (!empty($r['archive_redirect'])) {
        header('Location: ' . $r['archive_redirect'] . '/cdn/' . str_replace('/', '__', $repoKey) . '.zip', true, 302);
    } else {
        header('Content-Type: application/zip');
        readfile($r['zip']);
    }
} else {
    fake_out(404, '{}');
}
