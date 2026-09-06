# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
