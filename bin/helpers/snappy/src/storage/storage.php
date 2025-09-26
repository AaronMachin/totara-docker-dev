<?php

namespace Snappy\Storage;

interface storage {
    public function put_object(string $key, string $filepath): string;

    public function list_objects(string $prefix = '', int $max = 100): array;

    public function get_object(string $key, string $destination_path): void;

    public function read_object(string $key): string;

    public function upload(string $local_path, string $prefix): array;
}

