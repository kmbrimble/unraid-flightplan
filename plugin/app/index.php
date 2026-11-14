<?php
declare(strict_types=1);

/**
 * Flightplan web app shell. A small PHP page served by the webGUI's own nginx/php-fpm, so it inherits the webGUI
 * session. It emits the CSRF token the app's POSTs need (validated and consumed by local_prepend.php; nothing here
 * checks it again), sets data-theme from the Unraid theme, and loads the static JS and CSS. GET only; it reads no
 * Flightplan config and no secret. Nothing here uses STDOUT, STDERR or $argv.
 */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store'); // the page carries the session's CSRF token

function fp_page_env(string $name, string $default): string
{
    $v = getenv($name);
    return $v !== false && $v !== '' ? $v : $default;
}

function fp_page_csrf(): string
{
    $path = fp_page_env('FLIGHTPLAN_VAR_INI', '/var/local/emhttp/var.ini');
    $ini = is_file($path) ? @parse_ini_file($path) : false;
    $t = is_array($ini) ? ($ini['csrf_token'] ?? '') : '';
    return is_string($t) ? $t : '';
}

function fp_page_theme(): string
{
    $path = fp_page_env('FLIGHTPLAN_DYNAMIX_CFG', '/boot/config/plugins/dynamix/dynamix.cfg');
    $cfg = is_file($path) ? @parse_ini_file($path, true) : false;
    $name = is_array($cfg) ? (string) ($cfg['display']['theme'] ?? '') : '';
    return in_array($name, ['black', 'gray'], true) ? 'dark' : 'light';
}

function fp_page_asset(string $file): string
{
    $p = __DIR__ . '/assets/' . $file;
    return 'assets/' . $file . '?v=' . (is_file($p) ? substr((string) md5_file($p), 0, 12) : '0');
}

function fp_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

?><!doctype html>
<html lang="en" data-theme="<?= fp_h(fp_page_theme()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>Flightplan</title>
<link rel="stylesheet" href="<?= fp_h(fp_page_asset('tokens.css')) ?>">
<link rel="stylesheet" href="<?= fp_h(fp_page_asset('base.css')) ?>">
<link rel="stylesheet" href="<?= fp_h(fp_page_asset('components.css')) ?>">
<link rel="stylesheet" href="<?= fp_h(fp_page_asset('app.css')) ?>">
</head>
<body>
<div id="app" data-csrf="<?= fp_h(fp_page_csrf()) ?>"><p class="fp-muted">Loading Flightplan&hellip;</p></div>
<noscript><p class="fp-muted">Flightplan needs JavaScript.</p></noscript>
<script src="<?= fp_h(fp_page_asset('app.js')) ?>"></script>
</body>
</html>
