<?php

// Simple S3 (or S3 compatible) implementation of the storage interface without external deps.
// Environment variables used if config not passed explicitly:
//   SNAPPY_S3_ENDPOINT (e.g. https://s3.amazonaws.com or http://minio:9000)
//   SNAPPY_S3_REGION   (e.g. us-east-1)
//   SNAPPY_S3_BUCKET
//   SNAPPY_S3_KEY
//   SNAPPY_S3_SECRET
//   SNAPPY_S3_PATH_STYLE (optional; set to 1 to force path style addressing)

require_once __DIR__ . '/storage.php';

class s3_storage implements storage {
    /** @var string */ private $endpoint;
    /** @var string */ private $region;
    /** @var string */ private $bucket;
    /** @var string */ private $key;
    /** @var string */ private $secret;
    /** @var bool */ private $debug;

    public function __construct($config = array()) {
        if (!is_array($config)) { $config = array(); }
        $this->endpoint = rtrim(isset($config['endpoint']) ? $config['endpoint'] : (getenv('SNAPPY_S3_ENDPOINT') ?: ''), '/');
        $this->region   = isset($config['region']) ? $config['region'] : (getenv('SNAPPY_S3_REGION') ?: 'us-east-1');
        $this->bucket   = isset($config['bucket']) ? $config['bucket'] : (getenv('SNAPPY_S3_BUCKET') ?: '');
        $this->key      = isset($config['key']) ? $config['key'] : (getenv('SNAPPY_S3_KEY') ?: '');
        $this->secret   = $config['secret'] ?? (getenv('SNAPPY_S3_SECRET') ?: '');
        $this->debug = (isset($config['debug']) && $config['debug']) || getenv('TSNAP_DEBUG');
        $missing = array();
        if (!$this->endpoint) { $missing[] = 'SNAPPY_S3_ENDPOINT (e.g. https://s3.amazonaws.com or http://localhost:8000)'; }
        if (!$this->bucket)   { $missing[] = 'SNAPPY_S3_BUCKET'; }
        if (!$this->key)      { $missing[] = 'SNAPPY_S3_KEY'; }
        if (!$this->secret)   { $missing[] = 'SNAPPY_S3_SECRET'; }
        if (!empty($missing)) {
            $msg = "Missing required S3 configuration environment variables:\n  - " . implode("\n  - ", $missing) . "\n\nSet them, e.g.:\n  export SNAPPY_S3_ENDPOINT=http://localhost:8000\n  export SNAPPY_S3_BUCKET=yourbucket\n  export SNAPPY_S3_KEY=access_key\n  export SNAPPY_S3_SECRET=secret_key\n\nOr pass an array to s3_storage::__construct().";
            throw new RuntimeException($msg);
        }
    }

    public function put_object($hash, $filepath) {
        if (!is_readable($filepath)) {
            throw new InvalidArgumentException('File not readable: ' . $filepath);
        }
        $body = file_get_contents($filepath);
        $key = $hash; // Could add prefixing logic later.
        $this->request('PUT', $key, array(
            'headers' => array(
                'Content-Type' => $this->guessMimeType($filepath),
                'Content-Length' => strlen($body),
            ),
            'body' => $body,
        ));
        return $key;
    }

    public function list_objects($prefix = '', $max = 100) {
        $params = array(
            'list-type' => 2,
            'max-keys' => min($max, 1000),
        );
        if ($prefix !== '') {
            $params['prefix'] = $prefix;
        }
        $query = $this->build_query($params);
        $response = $this->request('GET', '', array('query' => $query));
        $xml = @simplexml_load_string($response['body']);
        if (!$xml) {
            throw new RuntimeException('Failed to parse ListObjectsV2 response');
        }
        $out = array();
        if (!empty($xml->Contents)) {
            foreach ($xml->Contents as $obj) {
                $out[] = array(
                    'key' => (string)$obj->Key,
                    'size' => (int)$obj->Size,
                    'last_modified' => (string)$obj->LastModified,
                );
                if (count($out) >= $max) { break; }
            }
        }
        return $out;
    }

    public function get_object($key, $destinationPath) {
        $res = $this->request('GET', $key);
        if (false === file_put_contents($destinationPath, $res['body'])) {
            throw new RuntimeException('Failed to write file: ' . $destinationPath);
        }
    }

    public function read_object($key) {
        $res = $this->request('GET', $key);
        return $res['body'];
    }

    public function upload($localPath, $prefix) {
        $uploaded = array();
        if (!file_exists($localPath)) {
            throw new InvalidArgumentException('Path does not exist: ' . $localPath);
        }
        $localPath = rtrim($localPath, '/');
        $prefix = trim($prefix, '/');
        if (is_file($localPath)) {
            $basename = basename($localPath);
            $key = ($prefix !== '' ? $prefix . '/' : '') . $basename;
            $this->request('PUT', $key, array(
                'headers' => array(
                    'Content-Type' => $this->guessMimeType($localPath),
                    'Content-Length' => filesize($localPath),
                ),
                'body' => file_get_contents($localPath),
            ));
            $uploaded[] = $key;
            return $uploaded;
        }
        // Directory traversal
        $baseLen = strlen($localPath) + 1; // include trailing slash for relative
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($localPath, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $fileInfo) {
            if ($fileInfo->isDir()) { continue; }
            $rel = substr($fileInfo->getPathname(), $baseLen);
            $key = ($prefix !== '' ? $prefix . '/' : '') . str_replace('\\', '/', $rel);
            $body = file_get_contents($fileInfo->getPathname());
            $this->request('PUT', $key, array(
                'headers' => array(
                    'Content-Type' => $this->guessMimeType($fileInfo->getPathname()),
                    'Content-Length' => strlen($body),
                ),
                'body' => $body,
            ));
            $uploaded[] = $key;
            if ($this->debug) { fwrite(STDERR, "[tsnap-debug] Uploaded $key\n"); }
        }
        return $uploaded;
    }

    private function guessMimeType($path) {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $type = finfo_file($finfo, $path);
                finfo_close($finfo);
                if ($type) { return $type; }
            }
        }
        return 'application/octet-stream';
    }

    /**
     * Perform a signed S3 request.
     * @param string $method
     * @param string $key
     * @param array $options headers (array), body (string), query (string)
     * @return array
     */
    private function request($method, $key, $options = array()) {
        $method = strtoupper($method);
        $headers = isset($options['headers']) ? $options['headers'] : array();
        $body = isset($options['body']) ? $options['body'] : '';
        $query = isset($options['query']) ? $options['query'] : '';
        $service = 's3';
        $endpointHost = parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME);
        $host = $this->bucket . '.' . $endpointHost; // virtual-hosted style only
        $canonicalUri = '/' . ltrim($key, '/');
        if ($key === '') { $canonicalUri = '/'; }
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $headers['Host'] = $host . ($port ? ':' . $port : '');
        $headers['x-amz-content-sha256'] = hash('sha256', $body);
        $headers['x-amz-date'] = $amzDate;

        ksort($headers, SORT_STRING | SORT_FLAG_CASE);
        $canonicalHeaders = '';
        $signedHeadersArr = array();
        foreach ($headers as $h => $v) {
            $hLower = strtolower($h);
            $canonicalHeaders .= $hLower . ':' . trim($v) . "\n";
            $signedHeadersArr[] = $hLower;
        }
        sort($signedHeadersArr);
        $signedHeaders = implode(';', $signedHeadersArr);

        $canonicalQuerystring = $query;
        if ($canonicalQuerystring !== '') {
            $parsed = array();
            parse_str($canonicalQuerystring, $parsed);
            ksort($parsed);
            $pairs = array();
            foreach ($parsed as $k2 => $v2) { $pairs[] = rawurlencode($k2) . '=' . rawurlencode($v2); }
            $canonicalQuerystring = implode('&', $pairs);
        }

        $canonicalRequest = $method . "\n" . $canonicalUri . "\n" . $canonicalQuerystring . "\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $headers['x-amz-content-sha256'];
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = $dateStamp . '/' . $this->region . '/' . $service . '/aws4_request';
        $stringToSign = $algorithm . "\n" . $amzDate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);

        $signingKey = $this->getSignatureKey($this->secret, $dateStamp, $this->region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = $algorithm . ' Credential=' . $this->key . '/' . $credentialScope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        $headers['Authorization'] = $authorization;

        $url = $scheme . '://' . $host . ($port ? ':' . $port : '') . $canonicalUri;
        if ($canonicalQuerystring !== '') { $url .= '?' . $canonicalQuerystring; }
        $headerLines = array();
        foreach ($headers as $k => $v) { $headerLines[] = $k . ': ' . $v; }
        if ($this->debug) {
            fwrite(STDERR, "[tsnap-debug] Request: $method $url\n");
            fwrite(STDERR, "[tsnap-debug] CanonicalRequest:\n$canonicalRequest\n");
            fwrite(STDERR, "[tsnap-debug] Headers:\n" . implode("\n", $headerLines) . "\n");
            if ($body !== '' && $method !== 'GET') {
                fwrite(STDERR, "[tsnap-debug] Body SHA256: " . hash('sha256', $body) . " size=" . strlen($body) . "\n");
            }
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        if ($body !== '') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($responseBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            if ($this->debug) { fwrite(STDERR, "[tsnap-debug] cURL error: $err\n"); }
            throw new RuntimeException('cURL error: ' . $err);
        }
        curl_close($ch);
        if ($this->debug) {
            fwrite(STDERR, "[tsnap-debug] Response status: $status length=" . strlen($responseBody) . "\n");
        }
        if ($status >= 400) {
            throw new RuntimeException('S3 request failed (' . $status . '): ' . $responseBody);
        }

        return array('status' => $status, 'headers' => $headers, 'body' => $responseBody);
    }

    private function getSignatureKey($key, $dateStamp, $regionName, $serviceName) {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $key, true);
        $kRegion = hash_hmac('sha256', $regionName, $kDate, true);
        $kService = hash_hmac('sha256', $serviceName, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        return $kSigning;
    }

    private function build_query($params) {
        // RFC3986 compliant query builder for older PHP
        ksort($params);
        $pairs = array();
        foreach ($params as $k => $v) {
            $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        return implode('&', $pairs);
    }
}
