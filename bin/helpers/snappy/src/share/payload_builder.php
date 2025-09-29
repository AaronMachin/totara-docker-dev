<?php
namespace Snappy\Share;

use Snappy\Util\canonical_json;

class payload_builder {
    /** Build encoded payload (base64 no padding of canonical json) */
    public static function encode(array $data): string {
        $json = canonical_json::encode($data);
        $b64 = base64_encode($json);
        return rtrim($b64,'=');
    }
    public static function decode(string $encoded): array {
        $pad = strlen($encoded) % 4; if($pad!==0){ $encoded.=str_repeat('=',4-$pad); }
        $json = base64_decode($encoded,true); if($json===false){ return []; }
        $arr = @json_decode($json,true); return is_array($arr)?$arr:[];
    }
}

