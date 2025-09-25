<?php
namespace Snappy\Storage;

use RuntimeException;
use InvalidArgumentException;

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
        $this->endpoint = rtrim($config['endpoint'] ?? getenv('SNAPPY_S3_ENDPOINT') ?: '', '/');
        $this->region = $config['region'] ?? getenv('SNAPPY_S3_REGION') ?: 'us-east-1';
        $this->bucket = $config['bucket'] ?? getenv('SNAPPY_S3_BUCKET') ?: '';
        $this->key = $config['key'] ?? getenv('SNAPPY_S3_KEY') ?: '';
        $this->secret = $config['secret'] ?? getenv('SNAPPY_S3_SECRET') ?: '';
        $this->debug = (bool)($config['debug'] ?? getenv('SNAPPY_DEBUG'));
        $force_path = getenv('SNAPPY_S3_PATH_STYLE');
        $this->path_style = ($force_path === '1' || strtolower((string)$force_path) === 'true');
        $this->auto_path_style = false;
        if (!$this->path_style) {
            $host = parse_url($this->endpoint, PHP_URL_HOST);
            if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
                $this->auto_path_style = true;
            }
        }
        $missing = [];
        if ($this->endpoint === '') { $missing[] = 'SNAPPY_S3_ENDPOINT'; }
        if ($this->bucket === '') { $missing[] = 'SNAPPY_S3_BUCKET'; }
        if ($this->key === '') { $missing[] = 'SNAPPY_S3_KEY'; }
        if ($this->secret === '') { $missing[] = 'SNAPPY_S3_SECRET'; }
        if ($missing) {
            throw new RuntimeException('Missing config: ' . implode(', ', $missing));
        }
    }

    public function put_object(string $key, string $filepath): string {
        if (!is_readable($filepath)) {
            throw new InvalidArgumentException('File not readable: ' . $filepath);
        }
        return $this->stream_put($key, $filepath);
    }

    public function list_objects(string $prefix = '', int $max = 100): array {
        $params = [ 'list-type' => 2, 'max-keys' => min($max, 1000) ];
        if ($prefix !== '') {
            $params['prefix'] = $prefix;
        }
        $query = $this->build_query($params);
        $response = $this->request('GET', '', ['query' => $query]);
        $xml = @simplexml_load_string($response['body']);
        if (!$xml) {
            throw new RuntimeException('Bad ListObjectsV2 XML');
        }
        $out = [];
        if (!empty($xml->Contents)) {
            foreach ($xml->Contents as $obj) {
                $out[] = [
                    'key' => (string)$obj->Key,
                    'size' => (int)$obj->Size,
                    'last_modified' => (string)$obj->LastModified,
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
            throw new RuntimeException('Write failed: ' . $destination_path);
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
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($local_path, \FilesystemIterator::SKIP_DOTS));
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
            throw new RuntimeException('curl error: ' . $err);
        }
        curl_close($ch);
        if ($status >= 400) {
            throw new RuntimeException('s3 error ' . $status . ' ' . $resp_body);
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
            throw new RuntimeException('open failed: ' . $filepath);
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
            throw new RuntimeException('curl error: ' . $err);
        }
        fclose($fh);
        curl_close($ch);
        if ($status >= 400) {
            throw new RuntimeException('s3 put error ' . $status . ' ' . $resp_body);
        }
        return $key;
    }
}

