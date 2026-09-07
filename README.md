# PHP Cache Lite

[![CI](https://github.com/kasapdev/php-cache-lite/actions/workflows/ci.yml/badge.svg)](https://github.com/kasapdev/php-cache-lite/actions/workflows/ci.yml) [![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE) ![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)

A lightweight, dependency-free PSR-16-shaped caching library for PHP with in-memory and file-based backends. `Kasapdev\CacheLite\CacheInterface` mirrors the method signatures of `psr/simple-cache`'s `CacheInterface` so it feels drop-in familiar, but this library has zero external dependencies — it does not require `psr/simple-cache` and does not implement its interface directly. Two backends ship out of the box: `ArrayCache` for fast in-process caching that lives only for the current request, and `FileCache` for a simple persistent cache backed by one JSON file per key.

## Installation

Once published to Packagist:

```bash
composer require kasapdev/php-cache-lite
```

Until then (or if you'd rather skip Composer entirely), you can use it directly — it's zero-dependency and PSR-4 autoloadable:

```php
<?php

require_once __DIR__ . '/src/CacheInterface.php';
require_once __DIR__ . '/src/TtlNormalizer.php';
require_once __DIR__ . '/src/ArrayCache.php';
require_once __DIR__ . '/src/FileCache.php';
```

## Usage

### ArrayCache (in-memory, per-process)

```php
<?php

require_once __DIR__ . '/src/CacheInterface.php';
require_once __DIR__ . '/src/TtlNormalizer.php';
require_once __DIR__ . '/src/ArrayCache.php';

use Kasapdev\CacheLite\ArrayCache;

$cache = new ArrayCache();

// Store a value forever (ttl = null means "never expires").
$cache->set('user:42:name', 'Ada Lovelace');

// Store a value that expires in 60 seconds.
$cache->set('session:token', 'abc123', 60);

// Store a value using a DateInterval.
$cache->set('report:daily', ['total' => 1000], new DateInterval('P1D'));

echo $cache->get('user:42:name'); // "Ada Lovelace"
echo $cache->get('missing-key', 'fallback'); // "fallback"

var_dump($cache->has('session:token')); // true

$cache->delete('session:token');
var_dump($cache->has('session:token')); // false

// Bulk operations.
$cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
print_r($cache->getMultiple(['a', 'b', 'missing'], 'n/a'));
$cache->deleteMultiple(['a', 'b']);

// Wipe everything.
$cache->clear();
```

### A more realistic end-to-end example

A typical read-through pattern: check the cache first, fall back to the
expensive source on a miss, then store the result for next time.

```php
<?php

require_once __DIR__ . '/src/CacheInterface.php';
require_once __DIR__ . '/src/TtlNormalizer.php';
require_once __DIR__ . '/src/FileCache.php';

use Kasapdev\CacheLite\FileCache;

function fetchUserProfile(FileCache $cache, int $userId): array
{
    $key = "user:$userId:profile";

    $profile = $cache->get($key);

    if ($profile !== null) {
        return $profile; // cache hit, skip the expensive lookup entirely
    }

    // Simulate an expensive lookup (a database query, an API call, ...).
    $profile = ['id' => $userId, 'name' => 'Ada Lovelace', 'plan' => 'pro'];

    // Cache it for 10 minutes, tagged so it can be bulk-invalidated later
    // (see "Tag-Based Invalidation" below) whenever this user's data changes.
    $cache->set($key, $profile, 600, tags: ["user:$userId"]);

    return $profile;
}

$cache = new FileCache(__DIR__ . '/var/cache');

$profile = fetchUserProfile($cache, 42); // populates the cache
$profileAgain = fetchUserProfile($cache, 42); // served straight from cache

var_dump($profile === $profileAgain); // true
```

### FileCache (persistent, one JSON file per key)

```php
<?php

require_once __DIR__ . '/src/CacheInterface.php';
require_once __DIR__ . '/src/TtlNormalizer.php';
require_once __DIR__ . '/src/FileCache.php';

use Kasapdev\CacheLite\FileCache;

// The directory is created automatically if it doesn't exist.
$cache = new FileCache(__DIR__ . '/var/cache');

$cache->set('weather:istanbul', ['tempC' => 21], 300); // expires in 5 minutes
$cache->set('config:site-name', 'My App'); // never expires

echo $cache->get('config:site-name'); // "My App"

if ($cache->has('weather:istanbul')) {
    print_r($cache->get('weather:istanbul'));
}

// A ttl of 0 (or negative) means the item is immediately considered expired.
$cache->set('one-shot', 'value', 0);
var_dump($cache->has('one-shot')); // false
echo $cache->get('one-shot', 'expired already'); // "expired already"

// Bulk operations work the same way as ArrayCache.
$cache->setMultiple(['x' => 1, 'y' => 2], 3600);
print_r($cache->getMultiple(['x', 'y', 'z']));
$cache->deleteMultiple(['x', 'y']);

$cache->clear();
```

Each entry is stored under `$directory` as `sha1($key) . '.json'`, with a body shaped like `{"value": ..., "expiresAt": <timestamp-or-null>, "tags": [...]}`. Hashing the key guarantees the filename is always filesystem-safe and collision-resistant, no matter what characters the original key contains. When an expired entry is discovered during `get()` or `has()`, its backing file is deleted immediately.

## Tag-Based Invalidation

Both backends support tagging entries at write time and invalidating every
entry that carries a given tag in one call, without having to track the
individual keys yourself. Pass an optional `tags` list to `set()`, then call
`invalidateTag()` to remove everything tagged with it:

```php
<?php

require_once __DIR__ . '/src/CacheInterface.php';
require_once __DIR__ . '/src/TtlNormalizer.php';
require_once __DIR__ . '/src/ArrayCache.php';

use Kasapdev\CacheLite\ArrayCache;

$cache = new ArrayCache();

// Tag related entries so they can be invalidated together later.
$cache->set('user:42:profile', ['name' => 'Ada Lovelace'], null, tags: ['user:42']);
$cache->set('user:42:settings', ['theme' => 'dark'], null, tags: ['user:42', 'settings']);
$cache->set('user:99:profile', ['name' => 'Grace Hopper'], null, tags: ['user:99']);

// Something changed about user 42 (e.g. they updated their profile) — drop
// every cache entry tagged with their id in a single call.
$removed = $cache->invalidateTag('user:42');

var_dump($removed); // int(2)
var_dump($cache->has('user:42:profile')); // false
var_dump($cache->has('user:42:settings')); // false

// Entries tagged differently (or not at all) are untouched.
var_dump($cache->has('user:99:profile')); // true
```

`tags` defaults to an empty array, so existing `set()` calls that don't pass
it behave exactly as before — tagging is entirely opt-in and does not affect
untagged entries or normal TTL-based expiry. `invalidateTag()` returns the
number of entries it removed, `0` if nothing matched. On `FileCache`, tags
are persisted as part of each entry's JSON body and `invalidateTag()` scans
the cache directory's files to find and delete the matching ones; on
`ArrayCache`, it's a simple scan over the in-memory entries.

## API

### `Kasapdev\CacheLite\CacheInterface`

| Method | Description |
| --- | --- |
| `get(string $key, mixed $default = null): mixed` | Fetch a value, or `$default` on miss/expiry. |
| `set(string $key, mixed $value, null\|int\|\DateInterval $ttl = null, array $tags = []): bool` | Store a value. `$ttl = null` means never expires; `0` or negative means immediately expired. `$tags` optionally names this entry for later bulk removal via `invalidateTag()`. |
| `delete(string $key): bool` | Remove a key. Returns `true` even if the key didn't exist. |
| `has(string $key): bool` | Whether a non-expired value exists for the key. |
| `clear(): bool` | Remove every entry. |
| `getMultiple(iterable $keys, mixed $default = null): iterable` | Fetch several values at once, keyed by the original keys. |
| `setMultiple(iterable $values, null\|int\|\DateInterval $ttl = null): bool` | Store several `key => value` pairs at once with a shared TTL. |
| `deleteMultiple(iterable $keys): bool` | Remove several keys at once. |
| `invalidateTag(string $tag): int` | Remove every entry stored with the given tag. Returns the number of entries removed. |

### `Kasapdev\CacheLite\ArrayCache`

In-process cache backed by a private array. Each entry stores an **absolute** expiry Unix timestamp computed at set-time (`time() + $ttl`), not the raw TTL, and expiry is checked against the current time on every `get()`/`has()` call — no background sweep is required for expired keys to disappear.

```php
new ArrayCache()
```

### `Kasapdev\CacheLite\FileCache`

```php
new FileCache(string $directory)
```

Persists each entry as a JSON file under `$directory` (created automatically, including nested paths, if it doesn't exist). Throws `\RuntimeException` if the directory cannot be created.

## Testing

This library ships with a single, dependency-free test script (no PHPUnit required):

```bash
php tests/run.php
```

It exercises both backends across basic get/set/delete/has, TTL semantics (including `ttl = 0`, negative TTLs, `DateInterval` TTLs, and real timing-based expiry), bulk operations (`getMultiple`/`setMultiple`/`deleteMultiple`), `clear()`, tag-based invalidation (`invalidateTag()` on both backends, including `FileCache`'s on-disk file removal), and `FileCache`'s on-disk file lifecycle (including that expired files are deleted the moment they're discovered). A successful run ends with `All tests passed.` and exit code `0`.

## License

MIT — see [LICENSE](LICENSE).
