<?php

declare(strict_types=1);

namespace Kasapdev\CacheLite;

/**
 * A PSR-16-shaped caching interface.
 *
 * The method signatures intentionally mirror psr/simple-cache's
 * Psr\SimpleCache\CacheInterface so implementations feel drop-in
 * familiar, but this library does not require that package as a
 * dependency and does not implement its interface directly.
 */
interface CacheInterface
{
    /**
     * Fetches a value from the cache.
     *
     * @param string $key The unique key of this item in the cache.
     * @param mixed $default Default value to return if the key does not exist or is expired.
     * @return mixed The value of the item from the cache, or $default in case of cache miss.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string $key The key of the item to store.
     * @param mixed $value The value of the item to store, must be serializable.
     * @param null|int|\DateInterval $ttl Optional. The TTL value of this item. If no value is sent
     *                                    and the driver supports TTL, then the library may set a
     *                                    default value for it or let the driver take care of that.
     *                                    A ttl of 0 or a negative value means the item is already expired.
     * @param string[] $tags Optional. A list of tags to associate with this entry, so it can later
     *                       be removed in bulk via invalidateTag(). Defaults to no tags.
     * @return bool True on success and false on failure.
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null, array $tags = []): bool;

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the item to delete.
     * @return bool True if the item was successfully removed. False if there was an error.
     */
    public function delete(string $key): bool;

    /**
     * Determines whether an item is present in the cache.
     *
     * @param string $key The cache item key.
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool True on success and false on failure.
     */
    public function clear(): bool;

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable $keys A list of keys that can be obtained in a single operation.
     * @param mixed $default Default value to return for keys that do not exist.
     * @return iterable A list of key => value pairs. Cache keys that do not exist or are stale will have $default as value.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable;

    /**
     * Persists a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param iterable $values A list of key => value pairs for a multiple-set operation.
     * @param null|int|\DateInterval $ttl Optional. The TTL value of this item.
     * @return bool True on success and false on failure.
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool;

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable $keys A list of string-based keys to be deleted.
     * @return bool True if the items were successfully removed. False if there was an error.
     */
    public function deleteMultiple(iterable $keys): bool;

    /**
     * Removes every cached entry that was stored with the given tag.
     *
     * @param string $tag The tag to invalidate.
     * @return int The number of entries that were removed.
     */
    public function invalidateTag(string $tag): int;
}
