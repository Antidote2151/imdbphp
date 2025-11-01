<?php
declare(strict_types=1);

namespace Imdb;

use DateInterval;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * File caching
 * Caches files to disk in config->cachedir optionally gzipping if config->usezip
 *
 * Config keys used: cachedir cache_expire usezip converttozip usecache storecache
 */
class Cache implements CacheInterface
{
    protected Config $config;
    protected LoggerInterface $logger;

    /**
     * Cache constructor.
     * @throws Exception
     */
    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;

        if (($this->config->usecache || $this->config->storecache) && !is_dir($this->config->cachedir)) {
            @mkdir($this->config->cachedir, 0700, true);
            if (!is_dir($this->config->cachedir)) {
                $this->logger->critical("[Cache] Configured cache directory [{$this->config->cachedir}] does not exist!");
                throw new Exception("[Cache] Configured cache directory [{$this->config->cachedir}] does not exist!");
            }
        }
        if ($this->config->storecache && !is_writable($this->config->cachedir)) {
            $this->logger->critical("[Cache] Configured cache directory [{$this->config->cachedir}] lacks write permission!");
            throw new Exception("[Cache] Configured cache directory [{$this->config->cachedir}] lacks write permission!");
        }

        $this->purge();
    }

    /**
     * @inheritdoc
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->config->usecache) {
            return $default;
        }

        $cleanKey = $this->sanitiseKey($key);
        $fname = $this->config->cachedir . '/' . $cleanKey;

        if (!is_file($fname)) {
            $this->logger->debug("[Cache] Cache miss for [$key]");
            return $default;
        }

        $this->logger->debug("[Cache] Cache hit for [$key]");

        if ($this->config->usezip) {
            $content = @file_get_contents('compress.zlib://' . $fname); // liest auch unkomprimierte Dateien
            if ($content === false) {
                return $default;
            }

            if ($this->config->converttozip) {
                $fp = @fopen($fname, "r");
                if ($fp) {
                    $zipchk = fread($fp, 2) ?: '';
                    fclose($fp);
                    // prüfen auf gzip header
                    if (!(isset($zipchk[0], $zipchk[1]) && $zipchk[0] === chr(31) && $zipchk[1] === chr(139))) {
                        // beim Zugriff konvertieren
                        @file_put_contents('compress.zlib://' . $fname, $content);
                    }
                }
            }
            return $content;
        }

        $data = @file_get_contents($fname);
        return ($data === false) ? $default : $data;
    }

    /**
     * @inheritdoc
     * Hinweis: $ttl wird aktuell nicht ausgewertet (Datei-Cache ohne per-Key-Expiry).
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        if (!$this->config->storecache) {
            return false;
        }

        $cleanKey = $this->sanitiseKey($key);
        $fname = $this->config->cachedir . '/' . $cleanKey;
        $this->logger->debug("[Cache] Writing key [$key] to [$fname]");

        if ($this->config->usezip) {
            $fp = @gzopen($fname, "w");
            if ($fp === false) {
                return false;
            }
            gzputs($fp, (string)$value);
            gzclose($fp);
            return true;
        }

        return @file_put_contents($fname, (string)$value) !== false;
    }

    /**
     * Entfernt abgelaufene Dateien anhand von config->cache_expire (Sekunden).
     */
    public function purge(): void
    {
        if (!$this->config->storecache || $this->config->cache_expire == 0) {
            return;
        }

        $cacheDir = $this->config->cachedir;
        $this->logger->debug("[Cache] Purging old cache entries");

        $dir = @opendir($cacheDir);
        if ($dir === false) {
            return;
        }

        $now = time();
        while (($file = readdir($dir)) !== false) {
            if ($file === "." || $file === ".." || $file === ".placeholder") {
                continue;
            }
            $fname = $cacheDir . '/' . $file;
            if (is_dir($fname)) {
                continue;
            }
            $mod = @filemtime($fname);
            if ($mod && ($now - $mod > $this->config->cache_expire)) {
                @unlink($fname);
            }
        }
        closedir($dir);
    }

    /**
     * Replace characters the OS won't like using with the filesystem
     */
    protected function sanitiseKey(string $key): string
    {
        return str_replace(['/', '\\', '?', '%', '*', ':', '|', '"', '<', '>'], '.', $key);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            // $key muss string sein laut PSR
            $results[$key] = $this->get((string)$key, $default);
        }
        return $results;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string)$key, $value, $ttl) && $ok;
        }
        return $ok;
    }

    public function delete(string $key): bool
    {
        $cleanKey = $this->sanitiseKey($key);
        $fname = $this->config->cachedir . '/' . $cleanKey;
        if (!is_file($fname)) {
            return true;
        }
        return @unlink($fname);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete((string)$key) && $ok;
        }
        return $ok;
    }

    public function clear(): bool
    {
        $ok = true;
        $dir = @opendir($this->config->cachedir);
        if ($dir === false) {
            return false;
        }
        while (($file = readdir($dir)) !== false) {
            if ($file === "." || $file === ".." || $file === ".placeholder") {
                continue;
            }
            $fname = $this->config->cachedir . '/' . $file;
            if (is_dir($fname)) {
                continue;
            }
            $ok = @unlink($fname) && $ok;
        }
        closedir($dir);
        return $ok;
    }

    public function has(string $key): bool
    {
        $cleanKey = $this->sanitiseKey($key);
        $fname = $this->config->cachedir . '/' . $cleanKey;
        return is_file($fname);
    }
}
