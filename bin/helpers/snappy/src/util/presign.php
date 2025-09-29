<?php
namespace Snappy\Util;

class presign {
    /** Generate a SigV4 presigned GET URL (path style) for an object. */
    public static function s3_get(string $externalEndpoint, string $bucket, string $objectKey, string $region, string $accessKey, string $secretKey, int $expirySeconds, bool $debug=false, bool $encodeScopeSlashes=true): string {
        $objectKey = ltrim($objectKey, '/');
        $externalEndpoint = rtrim($externalEndpoint, '/');
        $parts = parse_url($externalEndpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if (isset($parts['port'])) { $host .= ':' . $parts['port']; }
        if ($region === '') { $region = 'us-east-1'; }
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $credentialScope = $date . '/' . $region . '/s3/aws4_request';
        if ($encodeScopeSlashes) {
            $credential = rawurlencode($accessKey . '/' . $credentialScope); // legacy approach
        } else {
            // Encode only the access key, leave slashes literal (some S3-compatible gateways expect this)
            $credential = rawurlencode($accessKey) . '/' . $credentialScope;
        }
        $signedHeaders = 'host';
        $algorithm = 'AWS4-HMAC-SHA256';
        $expires = (string) min(max($expirySeconds,1), 604800); // AWS max 7d
        $query = [
            'X-Amz-Algorithm' => $algorithm,
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => $expires,
            'X-Amz-SignedHeaders' => $signedHeaders,
        ];
        ksort($query);
        $canonicalQuery = [];
        foreach ($query as $k => $v) { $canonicalQuery[] = rawurlencode($k) . '=' . rawurlencode($v); }
        $canonicalQueryStr = implode('&', $canonicalQuery);
        $canonicalUri = '/' . $bucket . '/' . str_replace('%2F','/', rawurlencode($objectKey));
        $canonicalHeaders = 'host:' . strtolower($host) . "\n";
        $canonicalRequest = 'GET' . "\n" . $canonicalUri . "\n" . $canonicalQueryStr . "\n" . $canonicalHeaders . "\n" . $signedHeaders . "\nUNSIGNED-PAYLOAD";
        // Signing key
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $stringToSign = $algorithm . "\n" . $amzDate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $finalQuery = $canonicalQueryStr . '&X-Amz-Signature=' . $signature;
        $url = $scheme . '://' . $host . $canonicalUri . '?' . $finalQuery;
        if ($debug) {
            fwrite(STDERR, "[presign-debug] encodedScope=".($encodeScopeSlashes?'yes':'no')."\ncanonicalRequest=\n$canonicalRequest\n[stringToSign]=$stringToSign\n[url]=$url\n");
        }
        return $url;
    }

    /** Try both encoding modes; return first working or last tried. */
    public static function s3_get_with_fallback(string $endpoint, string $bucket, string $objectKey, string $region, string $accessKey, string $secretKey, int $expirySeconds, bool $debug=false): string {
        $primary = self::s3_get($endpoint,$bucket,$objectKey,$region,$accessKey,$secretKey,$expirySeconds,$debug,true);
        if (self::probe($primary)) { return $primary; }
        $fallback = self::s3_get($endpoint,$bucket,$objectKey,$region,$accessKey,$secretKey,$expirySeconds,$debug,false);
        return $fallback; // second attempt, return regardless
    }

    private static function probe(string $url): bool {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 400) { return true; }
        // Treat 403 (signature OK but forbidden) as success (object may need GET not HEAD)
        if ($code === 403) { return true; }
        return false;
    }
}
