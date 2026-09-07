# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.2.0] - 2026-09-07

### Added

- Tag-based invalidation, available on both `ArrayCache` and `FileCache`:
  - `set()` gains a new optional trailing `array $tags = []` parameter
    (e.g. `$cache->set('user:42:profile', $data, 600, tags: ['user:42'])`).
    Existing positional callers are unaffected since `tags` defaults to an
    empty array and is added after `$ttl`.
  - A new `invalidateTag(string $tag): int` method (added to
    `CacheInterface` and implemented by both backends) removes every entry
    stored with the given tag and returns how many entries were removed.
  - `ArrayCache` stores each entry's tags in-memory alongside its value and
    expiry, and `invalidateTag()` scans the in-memory entries for matches.
  - `FileCache` persists tags as a new `tags` field in each entry's JSON
    body (`{"value": ..., "expiresAt": ..., "tags": [...]}`) and
    `invalidateTag()` scans the cache directory's files, deleting the ones
    whose stored tags include the target tag.
  - Entries set without a `tags` argument behave exactly as before —
    tagging is entirely opt-in and does not affect normal get/set/delete
    or TTL-based expiry.

## [1.1.0] - 2026-09-06

### Added

- Test coverage for edge cases in `FileCache` and the shared `TtlNormalizer`:
  - `FileCache::get()`/`has()` treat a file with corrupted (non-JSON)
    content as a cache miss instead of throwing.
  - `FileCache::get()`/`has()` treat a JSON file that decodes fine but is
    missing the expected `value`/`expiresAt` shape as a cache miss.
  - A `DateInterval` with `invert = 1` (representing a point in the past)
    is treated as already expired, the same as a negative integer ttl.
  - `getMultiple()`/`setMultiple()` are confirmed to work with a real
    `Generator`, not just a plain array, matching their `iterable`
    parameter type on both `ArrayCache` and `FileCache`.

No behavioral changes were needed — all new edge-case tests passed against
the existing implementation.
