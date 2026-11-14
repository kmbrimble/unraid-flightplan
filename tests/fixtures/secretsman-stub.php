<?php
// Stand-in for the installed SecretsMan library, exposing the three functions Flightplan calls with the same
// signatures and failure behaviour (throws, store must be 0600). Used when the real file is not on the machine.
if (!class_exists('SecretsmanError')) {
    final class SecretsmanError extends \RuntimeException
    {
    }
}
function secretsman_default_store_path(): string
{
    return getenv('SECRETSMAN_STORE_PATH') ?: '/mnt/user/appdata/.secrets/store.json';
}
function secretsman_load_store(string $path): array
{
    if (!is_file($path)) {
        throw new SecretsmanError("secretsman: store not found at {$path}");
    }
    if ((fileperms($path) & 0777) !== 0600) {
        throw new SecretsmanError('secretsman: store has insecure permissions');
    }
    $d = json_decode((string) file_get_contents($path), true);
    if (!is_array($d)) {
        throw new SecretsmanError('secretsman: store is not valid JSON');
    }
    return $d;
}
function secretsman_lookup(array $store, string $ns, string $key): string
{
    if (!isset($store[$ns]) || !array_key_exists($key, $store[$ns])) {
        throw new SecretsmanError("secretsman: no such key '{$key}' in namespace '{$ns}'");
    }
    return $store[$ns][$key];
}
