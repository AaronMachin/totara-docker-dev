<?php

namespace Snappy\Storage;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Local filesystem implementation of storage interface.
 * Keys are stored relative to a base path on disk.
 */
class local_storage implements storage {
    private string $base_path;

    public function __construct(string $base_path) {
        $this->base_path = rtrim($base_path, '/');
        if (!is_dir($this->base_path)) {
            if (!@mkdir($this->base_path, 0777, true)) {
                throw new RuntimeException('Cannot create local storage base path: ' . $this->base_path);
            }
        }
    }

    public function base_path(): string {
        return $this->base_path;
    }

    private function full_path(string $key): string {
        $key = ltrim($key, '/');
        return $this->base_path . '/' . $key;
    }

    public function put_object(string $key, string $filepath): string {
        if (!is_readable($filepath)) {
            throw new InvalidArgumentException('File not readable: ' . $filepath);
        }
        $dest = $this->full_path($key);
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new RuntimeException('Cannot create directory: ' . $dir);
        }
        if (!@copy($filepath, $dest)) {
            throw new RuntimeException('Copy failed to ' . $dest);
        }
        return $key;
    }

    public function list_objects(string $prefix = '', int $max = 100): array {
        $out = [];
        $base = $this->base_path;
        $prefix = ltrim($prefix, '/');
        $search_root = $base;
        if ($prefix !== '') {
            $search_root = $base . '/' . $prefix;
            if (!is_dir($search_root)) {
                return [];
            }
        }
        $len = strlen($base) + 1;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($search_root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fileinfo) {
            if ($fileinfo->isDir()) {
                continue;
            }
            $path = $fileinfo->getPathname();
            $rel = substr($path, $len);
            if ($prefix !== '' && strpos($rel, $prefix) !== 0) {
                continue; // when search_root == base (prefix empty) we include all
            }
            $out[] = [
                'key' => str_replace('\\', '/', $rel),
                'size' => (int) $fileinfo->getSize(),
                'last_modified' => date('c', $fileinfo->getMTime()),
            ];
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    public function get_object(string $key, string $destination_path): void {
        $src = $this->full_path($key);
        if (!is_file($src)) {
            throw new RuntimeException('Missing object: ' . $key);
        }
        $dir = dirname($destination_path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new RuntimeException('Cannot create dir: ' . $dir);
        }
        if (!@copy($src, $destination_path)) {
            throw new RuntimeException('Copy failed to ' . $destination_path);
        }
    }

    public function read_object(string $key): string {
        $src = $this->full_path($key);
        if (!is_file($src)) {
            throw new RuntimeException('Missing object: ' . $key);
        }
        $data = @file_get_contents($src);
        if ($data === false) {
            throw new RuntimeException('Read failed: ' . $key);
        }
        return $data;
    }

    public function upload(string $local_path, string $prefix): array {
        $uploaded = [];
        if (!file_exists($local_path)) {
            throw new InvalidArgumentException('Path does not exist: ' . $local_path);
        }
        $local_path = rtrim($local_path, '/');
        $prefix = trim($prefix, '/');
        if (is_file($local_path)) {
            $key = ($prefix ? $prefix . '/' : '') . basename($local_path);
            $this->put_object($key, $local_path);
            $uploaded[] = $key;
            return $uploaded;
        }
        $base_len = strlen($local_path) + 1;
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($local_path, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file_info) {
            if ($file_info->isDir()) {
                continue;
            }
            $rel = substr($file_info->getPathname(), $base_len);
            $key = ($prefix ? $prefix . '/' : '') . str_replace('\\', '/', $rel);
            $this->put_object($key, $file_info->getPathname());
            $uploaded[] = $key;
        }
        return $uploaded;
    }
}

