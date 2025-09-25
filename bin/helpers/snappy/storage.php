<?php

interface storage {
    /**
     * Store a file in object storage under the given hash (or key) and return the key used.
     * @param string $hash Logical key / hash to store object as.
     * @param string $filepath Path to local file to upload.
     * @return string Object key actually used.
     */
    public function put_object($hash, $filepath);

    /**
     * List objects under an optional prefix.
     * @param string $prefix Optional key prefix filter.
     * @param int $max Maximum number of objects to return.
     * @return array<int,array{key:string,size:int,last_modified:string}>
     */
    public function list_objects($prefix = '', $max = 100);

    /**
     * Download an object to a destination file path.
     * @param string $key
     * @param string $destinationPath
     * @return void
     */
    public function get_object($key, $destinationPath);

    /**
     * Read an object's full contents into memory.
     * @param string $key
     * @return string
     */
    public function read_object($key);
}