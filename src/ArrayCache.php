<?php

declare(strict_types=1);

namespace Kasapdev\CacheLite;

/**
 * An in-process, in-memory cache backend.
 *
 * Values live only for the lifetime of the PHP process/request that holds
 * this instance (backed by a private array property). Each entry stores an
 * absolute expiry Unix timestamp computed at set-time (time() + ttl), rather
 * than the raw ttl itself. Expired entries are treated as absent on every
 * access (get/has), regardless of whether any cleanup pass has run. Each
 * entry also stores the list of tags (if any) it was set with, so that
 * invalidateTag() can remove every entry carrying a given tag.
 */
final class ArrayCache implements CacheInterface
{
    use TtlNormalizer;

    /**
     * @var array<string, array{value: mixed, expiresAt: int|null, tags: string[]}>
     */
    private array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->items[$key]['value'];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null, array $tags = []): bool
    {
        $this->items[$key] = [
            'value' => $value,
            'expiresAt' => $this->ttlToExpiresAt($ttl),
            'tags' => $tags,
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->items)) {
            return false;
        }

        $expiresAt = $this->items[$key]['expiresAt'];

        if ($this->isExpired($expiresAt)) {
            unset($this->items[$key]);
            return false;
        }

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function invalidateTag(string $tag): int
    {
        $removed = 0;

        foreach ($this->items as $key => $item) {
            if (in_array($tag, $item['tags'], true)) {
                unset($this->items[$key]);
                $removed++;
            }
        }

        return $removed;
    }
}
