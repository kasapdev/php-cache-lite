<?php

declare(strict_types=1);

$__failures = 0;
function check(string $label, bool $condition): void {
    global $__failures;
    echo ($condition ? "[PASS] " : "[FAIL] ") . $label . "\n";
    if (!$condition) { $__failures++; }
}

require_once __DIR__ . '/../src/CacheInterface.php';
require_once __DIR__ . '/../src/TtlNormalizer.php';
require_once __DIR__ . '/../src/ArrayCache.php';
require_once __DIR__ . '/../src/FileCache.php';

use Kasapdev\CacheLite\ArrayCache;
use Kasapdev\CacheLite\FileCache;

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/**
 * Recursively removes a directory and its contents.
 */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

$tmpBaseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-cache-lite-tests-' . uniqid();

/**
 * @return array{0: string, 1: \Kasapdev\CacheLite\CacheInterface}[]
 */
function makeBackends(string $tmpBaseDir): array
{
    static $counter = 0;
    $counter++;
    $dir = $tmpBaseDir . DIRECTORY_SEPARATOR . 'file-cache-' . $counter;

    return [
        ['ArrayCache', new ArrayCache()],
        ['FileCache', new FileCache($dir)],
    ];
}

// ---------------------------------------------------------------------
// Basic set/get/has/delete
// ---------------------------------------------------------------------

foreach (makeBackends($tmpBaseDir) as [$name, $cache]) {
    check("$name: has() is false for a never-set key", $cache->has('missing') === false);
    check("$name: get() returns default for a never-set key", $cache->get('missing', 'DEFAULT') === 'DEFAULT');
    check("$name: get() returns null default for a never-set key when no default given", $cache->get('missing') === null);

    check("$name: set() returns true", $cache->set('greeting', 'hello world') === true);
    check("$name: has() is true after set()", $cache->has('greeting') === true);
    check("$name: get() returns the stored value", $cache->get('greeting') === 'hello world');

    check("$name: set() can overwrite an existing key", $cache->set('greeting', 'goodbye') === true);
    check("$name: get() reflects the overwritten value", $cache->get('greeting') === 'goodbye');

    check("$name: delete() on an existing key returns true", $cache->delete('greeting') === true);
    check("$name: has() is false after delete()", $cache->has('greeting') === false);
    check("$name: delete() on a missing key does not error and returns true", $cache->delete('never-existed') === true);

    // Non-string / complex values round-trip correctly.
    $complex = ['a' => 1, 'b' => [2, 3], 'c' => null, 'd' => true];
    $cache->set('complex', $complex);
    check("$name: complex array values round-trip", $cache->get('complex') === $complex);

    $cache->set('int-value', 42);
    check("$name: integer values round-trip", $cache->get('int-value') === 42);

    $cache->set('null-value', null);
    check("$name: has() is true for a key explicitly set to null", $cache->has('null-value') === true);
    check("$name: get() returns null (not default) for a key explicitly set to null", $cache->get('null-value', 'DEFAULT') === null);
}

// ---------------------------------------------------------------------
// TTL semantics
// ---------------------------------------------------------------------

foreach (makeBackends($tmpBaseDir) as [$name, $cache]) {
    // ttl = null means never expires.
    $cache->set('forever', 'value', null);
    check("$name: ttl=null persists indefinitely (has)", $cache->has('forever') === true);
    check("$name: ttl=null persists indefinitely (get)", $cache->get('forever') === 'value');

    // ttl = 0 means already expired per PSR-16 convention.
    $cache->set('expired-now', 'value', 0);
    check("$name: ttl=0 is immediately expired (has is false)", $cache->has('expired-now') === false);
    check("$name: ttl=0 get() returns provided default", $cache->get('expired-now', 'FALLBACK') === 'FALLBACK');
    check("$name: ttl=0 get() returns null when no default given", $cache->get('expired-now') === null);

    // Negative ttl is also already expired.
    $cache->set('expired-negative', 'value', -100);
    check("$name: negative ttl is immediately expired (has is false)", $cache->has('expired-negative') === false);
    check("$name: negative ttl get() returns provided default", $cache->get('expired-negative', 'FALLBACK') === 'FALLBACK');

    // Positive ttl persists the value before expiry.
    $cache->set('short-lived', 'still-here', 3600);
    check("$name: positive ttl persists the value (has)", $cache->has('short-lived') === true);
    check("$name: positive ttl persists the value (get)", $cache->get('short-lived') === 'still-here');

    // DateInterval ttl.
    $cache->set('via-interval', 'interval-value', new DateInterval('PT1H'));
    check("$name: DateInterval ttl in the future persists the value", $cache->get('via-interval') === 'interval-value');
}

// Real expiry timing with a 1-second ttl and a sleep(2).
foreach (makeBackends($tmpBaseDir) as [$name, $cache]) {
    $cache->set('ticking', 'tock', 1);
    check("$name: short ttl value exists immediately after set()", $cache->has('ticking') === true);
    sleep(2);
    check("$name: short ttl value is expired after waiting past ttl", $cache->has('ticking') === false);
    check("$name: get() after real expiry returns default", $cache->get('ticking', 'GONE') === 'GONE');
}

// ---------------------------------------------------------------------
// FileCache-specific: expired entries are deleted from disk on access.
// ---------------------------------------------------------------------

$fileCacheExpiryDir = $tmpBaseDir . DIRECTORY_SEPARATOR . 'expiry-check';
$fileCache = new FileCache($fileCacheExpiryDir);
$fileCache->set('doomed', 'value', 0);

$expectedPath = $fileCacheExpiryDir . DIRECTORY_SEPARATOR . sha1('doomed') . '.json';
check('FileCache: backing file exists right after set() even though already expired', is_file($expectedPath));

check('FileCache: has() on an expired key returns false', $fileCache->has('doomed') === false);
check('FileCache: backing file is deleted from disk after has() discovers expiry', !is_file($expectedPath));

// Re-create it and verify get() also deletes the expired file.
$fileCache->set('doomed2', 'value', 0);
$expectedPath2 = $fileCacheExpiryDir . DIRECTORY_SEPARATOR . sha1('doomed2') . '.json';
check('FileCache: second backing file exists right after set()', is_file($expectedPath2));
check('FileCache: get() on an expired key returns default', $fileCache->get('doomed2', 'GONE') === 'GONE');
check('FileCache: backing file is deleted from disk after get() discovers expiry', !is_file($expectedPath2));

// Non-expiring FileCache entries are stored as real JSON files with the expected shape.
$fileCache->set('shape-check', ['x' => 1], null);
$shapePath = $fileCacheExpiryDir . DIRECTORY_SEPARATOR . sha1('shape-check') . '.json';
check('FileCache: backing file exists for a persistent key', is_file($shapePath));
$rawJson = file_get_contents($shapePath);
$decodedJson = $rawJson === false ? null : json_decode($rawJson, true);
check('FileCache: backing file JSON has "value" and "expiresAt" keys', is_array($decodedJson) && array_key_exists('value', $decodedJson) && array_key_exists('expiresAt', $decodedJson));
check('FileCache: backing file JSON "value" matches what was stored', $decodedJson['value'] === ['x' => 1]);
check('FileCache: backing file JSON "expiresAt" is null for a non-expiring entry', $decodedJson['expiresAt'] === null);

// ---------------------------------------------------------------------
// getMultiple / setMultiple / deleteMultiple
// ---------------------------------------------------------------------

foreach (makeBackends($tmpBaseDir) as [$name, $cache]) {
    $setResult = $cache->setMultiple(['one' => 1, 'two' => 2, 'three' => 3]);
    check("$name: setMultiple() returns true", $setResult === true);

    $got = $cache->getMultiple(['one', 'two', 'missing']);
    $gotArray = is_array($got) ? $got : iterator_to_array($got);
    check("$name: getMultiple() returns correct values for existing keys", $gotArray['one'] === 1 && $gotArray['two'] === 2);
    check("$name: getMultiple() returns null default for a missing key", array_key_exists('missing', $gotArray) && $gotArray['missing'] === null);

    $gotWithDefault = $cache->getMultiple(['one', 'missing'], 'N/A');
    $gotWithDefaultArray = is_array($gotWithDefault) ? $gotWithDefault : iterator_to_array($gotWithDefault);
    check("$name: getMultiple() honors a custom default for missing keys", $gotWithDefaultArray['missing'] === 'N/A');
    check("$name: getMultiple() still returns real value for existing keys alongside a custom default", $gotWithDefaultArray['one'] === 1);

    $setWithTtl = $cache->setMultiple(['expiring-a' => 'a', 'expiring-b' => 'b'], 0);
    check("$name: setMultiple() with ttl=0 still returns true", $setWithTtl === true);
    check("$name: setMultiple() with ttl=0 results in immediately expired keys", $cache->has('expiring-a') === false && $cache->has('expiring-b') === false);

    $deleteResult = $cache->deleteMultiple(['one', 'two']);
    check("$name: deleteMultiple() returns true", $deleteResult === true);
    check("$name: deleteMultiple() actually removed the keys", $cache->has('one') === false && $cache->has('two') === false);
    check("$name: deleteMultiple() leaves untouched keys intact", $cache->has('three') === true);

    $deleteMissingResult = $cache->deleteMultiple(['never-existed-a', 'never-existed-b']);
    check("$name: deleteMultiple() on missing keys does not error and returns true", $deleteMissingResult === true);
}

// ---------------------------------------------------------------------
// clear()
// ---------------------------------------------------------------------

foreach (makeBackends($tmpBaseDir) as [$name, $cache]) {
    $cache->set('a', 1);
    $cache->set('b', 2);
    $cache->set('c', 3);

    check("$name: keys exist before clear()", $cache->has('a') && $cache->has('b') && $cache->has('c'));

    $clearResult = $cache->clear();
    check("$name: clear() returns true", $clearResult === true);
    check("$name: keys are gone after clear()", $cache->has('a') === false && $cache->has('b') === false && $cache->has('c') === false);

    // Cache should still be usable after clear().
    $cache->set('post-clear', 'works');
    check("$name: cache is usable after clear()", $cache->get('post-clear') === 'works');
}

// FileCache: clear() actually empties the directory of cache files on disk.
$fileCacheClearDir = $tmpBaseDir . DIRECTORY_SEPARATOR . 'clear-check';
$fileCacheForClear = new FileCache($fileCacheClearDir);
$fileCacheForClear->set('x', 1);
$fileCacheForClear->set('y', 2);
$filesBeforeClear = glob($fileCacheClearDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
check('FileCache: directory contains files before clear()', count($filesBeforeClear) === 2);
$fileCacheForClear->clear();
$filesAfterClear = glob($fileCacheClearDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
check('FileCache: directory contains no cache files after clear()', count($filesAfterClear) === 0);

// ---------------------------------------------------------------------
// FileCache constructor creates the directory if missing.
// ---------------------------------------------------------------------

$autoCreateDir = $tmpBaseDir . DIRECTORY_SEPARATOR . 'auto-created' . DIRECTORY_SEPARATOR . 'nested';
check('FileCache: target directory does not exist before construction', !is_dir($autoCreateDir));
$autoCreateCache = new FileCache($autoCreateDir);
check('FileCache: constructor creates a missing (nested) directory', is_dir($autoCreateDir));
$autoCreateCache->set('k', 'v');
check('FileCache: cache in an auto-created directory is functional', $autoCreateCache->get('k') === 'v');

// ---------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------

rrmdir($tmpBaseDir);

echo $__failures === 0 ? "\nAll tests passed.\n" : "\n$__failures test(s) FAILED.\n";
exit($__failures === 0 ? 0 : 1);
