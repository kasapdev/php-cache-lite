<?php

declare(strict_types=1);

namespace Kasapdev\CacheLite;

/**
 * A file-based cache backend.
 *
 * Each cache entry is stored as one JSON file under the configured
 * directory. The cache key is not used as the filename directly (arbitrary
 * strings are not always safe filenames); instead the filename is
 * `sha1($key) . '.json'`, so two different keys never collide with the
 * filesystem's naming restrictions and directory separators in a key can
 * never cause a path traversal. Each file's JSON body is shaped like:
 * `{"value": ..., "expiresAt": <timestamp-or-null>}`.
 *
 * When a get()/has() call discovers that a stored entry is expired, the
 * backing file is deleted immediately as part of that call.
 */
final class FileCache implements CacheInterface
{
    use TtlNormalizer;

    private readonly string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, "/\\");

        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
                throw new \RuntimeException(sprintf('Unable to create cache directory "%s".', $this->directory));
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->readEntry($key);

        if ($entry === null) {
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $payload = [
            'value' => $value,
            'expiresAt' => $this->ttlToExpiresAt($ttl),
        ];

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        return file_put_contents($this->filePathForKey($key), $encoded, LOCK_EX) !== false;
    }

    public function delete(string $key): bool
    {
        $path = $this->filePathForKey($key);

        if (!is_file($path)) {
            return true;
        }

        return unlink($path);
    }

    public function has(string $key): bool
    {
        return $this->readEntry($key) !== null;
    }

    public function clear(): bool
    {
        $success = true;

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (!unlink($file)) {
                $success = false;
            }
        }

        return $success;
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

    /**
     * Reads and decodes the entry for $key, transparently deleting and
     * treating it as absent if it has expired or is corrupt/missing.
     *
     * @return array{value: mixed, expiresAt: int|null}|null
     */
    private function readEntry(string $key): ?array
    {
        $path = $this->filePathForKey($key);

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || !array_key_exists('value', $decoded) || !array_key_exists('expiresAt', $decoded)) {
            return null;
        }

        /** @var int|null $expiresAt */
        $expiresAt = $decoded['expiresAt'];

        if ($this->isExpired($expiresAt)) {
            unlink($path);
            return null;
        }

        return ['value' => $decoded['value'], 'expiresAt' => $expiresAt];
    }

    /**
     * Maps a cache key to its backing file path. The key is hashed with
     * sha1() to guarantee a filesystem-safe, collision-resistant filename
     * regardless of what characters the key contains.
     */
    private function filePathForKey(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    }
}
