<?php

declare(strict_types=1);

namespace Kasapdev\CacheLite;

/**
 * Shared helper for converting a PSR-16-style TTL (null|int|DateInterval)
 * into an absolute Unix expiry timestamp (or null for "never expires").
 */
trait TtlNormalizer
{
    /**
     * @param null|int|\DateInterval $ttl
     * @return int|null Absolute Unix timestamp at which the item expires, or null if it never expires.
     */
    private function ttlToExpiresAt(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            return $now->add($ttl)->getTimestamp();
        }

        return time() + $ttl;
    }

    /**
     * @param int|null $expiresAt Absolute Unix timestamp, or null if it never expires.
     */
    private function isExpired(?int $expiresAt): bool
    {
        return $expiresAt !== null && $expiresAt <= time();
    }
}
