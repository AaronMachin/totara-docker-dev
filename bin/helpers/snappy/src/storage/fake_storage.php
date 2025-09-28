<?php

namespace Snappy\Storage;

use InvalidArgumentException;
use Snappy\Support\Exception\RemoteException;

class fake_storage implements storage {
    private array $objects = []; // key => ['content'=>string,'last_modified'=>iso,'size'=>int]

    public function put_object(string $key, string $filepath): string {
        if (!is_readable($filepath)) { throw new InvalidArgumentException('File not readable: '.$filepath); }
        $data = file_get_contents($filepath);
        $this->objects[$key] = [
            'content' => $data,
            'last_modified' => gmdate('Y-m-d\TH:i:s\Z'),
            'size' => strlen($data),
        ];
        return $key;
    }

    public function list_objects(string $prefix = '', int $max = 100): array {
        $out = [];
        foreach ($this->objects as $k => $meta) {
            if ($prefix !== '' && !str_starts_with($k, $prefix)) { continue; }
            $out[] = [ 'key' => $k, 'size' => $meta['size'], 'last_modified' => $meta['last_modified'] ];
            if (count($out) >= $max) { break; }
        }
        return $out;
    }

    public function get_object(string $key, string $destination_path): void {
        if (!isset($this->objects[$key])) { throw new RemoteException('missing object '.$key); }
        if (false === file_put_contents($destination_path, $this->objects[$key]['content'])) { throw new RemoteException('write failed'); }
    }

    public function read_object(string $key): string {
        if (!isset($this->objects[$key])) { throw new RemoteException('missing object '.$key); }
        return $this->objects[$key]['content'];
    }

    public function upload(string $local_path, string $prefix): array {
        $uploaded = [];
        if (is_file($local_path)) {
            $key = rtrim($prefix,'/').'/'.basename($local_path);
            $this->put_object($key, $local_path);
            $uploaded[] = $key;
            return $uploaded;
        }
        $base = rtrim($local_path,'/');
        $len = strlen($base)+1;
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $fi) { if ($fi->isDir()) continue; $rel = substr($fi->getPathname(), $len); $key = ($prefix? rtrim($prefix,'/').'/' : '').$rel; $this->put_object($key, $fi->getPathname()); $uploaded[]=$key; }
        return $uploaded;
    }

    // Test helper to delete object
    public function delete(string $key): void { unset($this->objects[$key]); }
}

