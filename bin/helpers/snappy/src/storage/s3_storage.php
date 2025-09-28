<?php

namespace Snappy\Storage;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use InvalidArgumentException;
use Snappy\Support\Exception\RemoteException;

class s3_storage implements storage {
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $key;
    private string $secret;
    private bool $debug;
    private bool $path_style;
    private bool $auto_path_style;

    public function __construct(array $config = []) {
        $this->endpoint = rtrim($config['endpoint'] ?? '', '/');
        $this->region = $config['region'] ?? 'us-east-1';
        $this->bucket = $config['bucket'] ?? '';
        $this->key = $config['key'] ?? '';
        $this->secret = $config['secret'] ?? '';
        $this->debug = (bool)($config['debug'] ?? false);
        $this->path_style = (bool)($config['path_style'] ?? false);
        $this->auto_path_style = false;
        if (!$this->path_style) {
            $host = parse_url($this->endpoint, PHP_URL_HOST);
            if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
                $this->auto_path_style = true;
            }
        }
        $missing = [];
        foreach (['endpoint','bucket','key','secret'] as $req) {
            if ($this->$req === '') { $missing[] = $req; }
        }
        if ($missing) {
            throw new RemoteException('Missing config: ' . implode(', ', $missing));
        }
    }

    public function put_object(string $key, string $filepath): string {
        if (!is_readable($filepath)) {
            throw new InvalidArgumentException('File not readable: ' . $filepath);
        }
        return $this->stream_put($key, $filepath);
    }

    public function list_objects(string $prefix = '', int $max = 100): array {
        $params = ['list-type' => 2, 'max-keys' => min($max, 1000)];
        if ($prefix !== '') {
            $params['prefix'] = $prefix;
        }
        $query = $this->build_query($params);
        $response = $this->request('GET', '', ['query' => $query]);
        $xml = @simplexml_load_string($response['body']);
        if (!$xml) {
            throw new RemoteException('Bad ListObjectsV2 XML');
        }
        $out = [];
        if (!empty($xml->Contents)) {
            foreach ($xml->Contents as $obj) {
                $out[] = [
                    'key' => (string) $obj->Key,
                    'size' => (int) $obj->Size,
                    'last_modified' => (string) $obj->LastModified,
                ];
                if (count($out) >= $max) {
                    break;
                }
            }
        }
        return $out;
    }

    public function get_object(string $key, string $destination_path): void {
        $res = $this->request('GET', $key);
        if (false === file_put_contents($destination_path, $res['body'])) {
            throw new RemoteException('Write failed: ' . $destination_path);
        }
    }

    public function read_object(string $key): string {
        $res = $this->request('GET', $key);
        return $res['body'];
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
            $this->stream_put($key, $local_path);
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
            $key = ($prefix ? $prefix . '/' : '') . str_replace('\\\\/', '/', $rel);
            $this->stream_put($key, $file_info->getPathname());
            $uploaded[] = $key;
            if ($this->debug) {
                fwrite(STDERR, "[snappy-debug] uploaded $key\n");
            }
        }
        return $uploaded;
    }

    public function ensure_bucket(): void {
        // Try a lightweight list to detect existence
        try {
            $this->list_objects('', 1);
            return; // exists
        } catch (RemoteException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'NoSuchBucket') === false && strpos($msg, '404') === false) {
                return; // other error
            }
        }
        // proceed to create
        // Build create bucket request
        $use_path = $this->path_style || $this->auto_path_style;
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME);
        $endpoint_host = parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        $host = $use_path ? $endpoint_host : ($this->bucket . '.' . $endpoint_host);
        if ($port) { $host .= ':' . $port; }
        $uri = $use_path ? '/' . $this->bucket : '/';
        $body = '';
        if ($this->region !== 'us-east-1') {
            $body = '<CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><LocationConstraint>' . htmlspecialchars($this->region, ENT_QUOTES) . '</LocationConstraint></CreateBucketConfiguration>';
        }
        $amz = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $payload_hash = hash('sha256', $body);
        $headers = [
            'Host' => $host,
            'x-amz-date' => $amz,
            'x-amz-content-sha256' => $payload_hash,
            'Content-Length' => strlen($body),
        ];
        if ($body !== '') {
            $headers['Content-Type'] = 'application/xml';
        }
        ksort($headers, SORT_STRING | SORT_FLAG_CASE);
        $canonical_headers = '';
        $signed = [];
        foreach ($headers as $h => $v) {
            $hl = strtolower($h);
            $canonical_headers .= $hl . ':' . trim($v) . "\n";
            $signed[] = $hl;
        }
        sort($signed);
        $signed_str = implode(';', $signed);
        $canonical_request = 'PUT' . "\n" . $uri . "\n\n" . $canonical_headers . "\n" . $signed_str . "\n" . $payload_hash;
        $scope = $date . '/' . $this->region . '/s3/aws4_request';
        $string_to_sign = 'AWS4-HMAC-SHA256' . "\n" . $amz . "\n" . $scope . "\n" . hash('sha256', $canonical_request);
        $signing_key = $this->signing_key($date, $this->region, 's3');
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->key . '/' . $scope . ', SignedHeaders=' . $signed_str . ', Signature=' . $signature;
        $url = $scheme . '://' . $host . $uri;
        $header_lines = [];
        foreach ($headers as $k => $v) { $header_lines[] = $k . ': ' . $v; }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header_lines);
        if ($body !== '') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // Accept 200 OK, 201 Created, 204 No Content. 409 BucketAlreadyOwnedByYou is fine.
        if (in_array($status, [200,201,202,204,409], true)) {
            return;
        }
        throw new RemoteException('bucket create failed status ' . $status . ' ' . $resp);
    }

    private function guess_mime(string $path): string {
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $type = finfo_file($f, $path);
                finfo_close($f);
                if ($type) {
                    return $type;
                }
            }
        }
        return 'application/octet-stream';
    }

    private function build_host_uri(string $key): array {
        $endpoint_host = parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME);
        $use_path = $this->path_style || $this->auto_path_style;
        if ($use_path) {
            $host = $endpoint_host . ($port ? ":$port" : '');
            $uri = '/' . $this->bucket . '/' . ltrim($key, '/');
            if ($key === '') {
                $uri = '/' . $this->bucket . '/';
            }
        } else {
            $host = $this->bucket . '.' . $endpoint_host . ($port ? ":$port" : '');
            $uri = '/' . ltrim($key, '/');
            if ($key === '') {
                $uri = '/';
            }
        }
        return [$scheme, $host, $uri];
    }

    private function request(string $method, string $key, array $options = []): array {
        $method = strtoupper($method);
        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? '';
        $query = $options['query'] ?? '';
        [$scheme, $host, $canonical_uri] = $this->build_host_uri($key);
        $amz = gmdate('Ymd\\THis\\Z');
        $date = gmdate('Ymd');
        $headers['Host'] = $host;
        $headers['x-amz-content-sha256'] = hash('sha256', $body);
        $headers['x-amz-date'] = $amz;
        ksort($headers, SORT_STRING | SORT_FLAG_CASE);
        $canonical_headers = '';
        $signed = [];
        foreach ($headers as $h => $v) {
            $hl = strtolower($h);
            $canonical_headers .= $hl . ':' . trim($v) . "\n";
            $signed[] = $hl;
        }
        sort($signed);
        $signed_str = implode(';', $signed);
        $canonical_query = $query;
        if ($canonical_query !== '') {
            parse_str($canonical_query, $parsed);
            ksort($parsed);
            $pairs = [];
            foreach ($parsed as $k => $v) {
                $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
            }
            $canonical_query = implode('&', $pairs);
        }
        $canonical_request = $method . "\n" . $canonical_uri . "\n" . $canonical_query . "\n" . $canonical_headers . "\n" . $signed_str . "\n" . $headers['x-amz-content-sha256'];
        $scope = $date . '/' . $this->region . '/s3/aws4_request';
        $string_to_sign = 'AWS4-HMAC-SHA256' . "\n" . $amz . "\n" . $scope . "\n" . hash('sha256', $canonical_request);
        $signing_key = $this->signing_key($date, $this->region, 's3');
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->key . '/' . $scope . ', SignedHeaders=' . $signed_str . ', Signature=' . $signature;
        $url = $scheme . '://' . $host . $canonical_uri;
        if ($canonical_query !== '') {
            $url .= '?' . $canonical_query;
        }
        $header_lines = [];
        foreach ($headers as $k => $v) {
            $header_lines[] = $k . ': ' . $v;
        }
        if ($this->debug) {
            fwrite(STDERR, "[snappy-debug] request $method $url\n");
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header_lines);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        $resp_body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp_body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RemoteException('curl error: ' . $err);
        }
        curl_close($ch);
        if ($status >= 400) {
            throw new RemoteException('s3 error ' . $status . ' ' . $resp_body);
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $resp_body];
    }

    private function signing_key(string $date, string $region, string $service): string {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function build_query(array $params): string {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        return implode('&', $pairs);
    }

    private function stream_put(string $key, string $filepath): string {
        $size = filesize($filepath);
        $payload_hash = hash_file('sha256', $filepath);
        $content_type = $this->guess_mime($filepath);
        [$scheme, $host, $uri] = $this->build_host_uri($key);
        $amz = gmdate('Ymd\\THis\\Z');
        $date = gmdate('Ymd');
        $headers = [
            'Host' => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date' => $amz,
            'Content-Type' => $content_type,
            'Content-Length' => $size,
        ];
        ksort($headers, SORT_STRING | SORT_FLAG_CASE);
        $canonical_headers = '';
        $signed = [];
        foreach ($headers as $h => $v) {
            $hl = strtolower($h);
            $canonical_headers .= $hl . ':' . trim($v) . "\n";
            $signed[] = $hl;
        }
        sort($signed);
        $signed_str = implode(';', $signed);
        $canonical_request = 'PUT' . "\n" . $uri . "\n\n" . $canonical_headers . "\n" . $signed_str . "\n" . $payload_hash;
        $scope = $date . '/' . $this->region . '/s3/aws4_request';
        $string_to_sign = 'AWS4-HMAC-SHA256' . "\n" . $amz . "\n" . $scope . "\n" . hash('sha256', $canonical_request);
        $signing_key = $this->signing_key($date, $this->region, 's3');
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->key . '/' . $scope . ', SignedHeaders=' . $signed_str . ', Signature=' . $signature;
        $url = $scheme . '://' . $host . $uri;
        $header_lines = [];
        foreach ($headers as $k => $v) {
            $header_lines[] = $k . ': ' . $v;
        }
        $fh = fopen($filepath, 'rb');
        if (!$fh) {
            throw new RemoteException('open failed: ' . $filepath);
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, $size);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header_lines);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        $resp_body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp_body === false) {
            $err = curl_error($ch);
            fclose($fh);
            curl_close($ch);
            throw new RemoteException('curl error: ' . $err);
        }
        fclose($fh);
        curl_close($ch);
        if ($status >= 400) {
            throw new RemoteException('s3 put error ' . $status . ' ' . $resp_body);
        }
        return $key;
    }

    public function delete_object(string $key): void {
        try {
            $this->request('DELETE', $key);
        } catch (RemoteException $e) {
            if (stripos($e->getMessage(), 'NoSuchKey') === false && stripos($e->getMessage(), '404') === false) {
                throw $e;
            }
        }
    }
}
