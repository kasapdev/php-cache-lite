<?php

declare(strict_types=1);

namespace Kasapdev\CacheLite;

/**
 * Shared implementation of CacheInterface::remember() on top of get()/set().
 *
 * A private sentinel object is passed as get()'s default so a stored
 * `null` or `false` counts as a hit instead of being mistaken for a miss,
 * and the lookup is a single get() call rather than a has()/get() pair that
 * could straddle an entry's expiry.
 */
trait Remember
{
    public function remember(string $key, null|int|\DateInterval $ttl, callable $callback, array $tags = []): mixed
    {
        $miss = new \stdClass();
        $cached = $this->get($key, $miss);

        if ($cached !== $miss) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttl, $tags);

        return $value;
    }
}
