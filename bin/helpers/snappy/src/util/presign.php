<?php
namespace Snappy\Util;

class presign {
    /** Generate a SigV4 presigned GET URL (path style) for an object. */
    public static function s3_get(string $externalEndpoint, string $bucket, string $objectKey, string $region, string $accessKey, string $secretKey, int $expirySeconds): string {
        $objectKey = ltrim($objectKey, '/');
        $externalEndpoint = rtrim($externalEndpoint, '/');
        $parts = parse_url($externalEndpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if (isset($parts['port'])) { $host .= ':' . $parts['port']; }
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $credentialScope = $date . '/' . $region . '/s3/aws4_request';
        $credential = rawurlencode($accessKey . '/' . $credentialScope);
        $signedHeaders = 'host';
        $algorithm = 'AWS4-HMAC-SHA256';
        $expires = (string) min(max($expirySeconds,1), 604800); // AWS max 7d
        $query = [
            'X-Amz-Algorithm' => $algorithm,
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => $expires,
            'X-Amz-SignedHeaders' => $signedHeaders,
            'X-Amz-Content-Sha256' => 'UNSIGNED-PAYLOAD'
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
        return $scheme . '://' . $host . $canonicalUri . '?' . $finalQuery;
    }
}

