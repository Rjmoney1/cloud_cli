<?php
// JWT.php - Simple self-contained JWT encoder/decoder using HMAC-SHA256

class JWT {
    private static $secretKey = 'CloudLabSecureSecretKeyReplaceMeInProductionEnvironmentVariables!';

    private static function getSecret() {
        return getenv('JWT_SECRET') ?: self::$secretKey;
    }

    private static function base64UrlEncode($data) {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64UrlDecode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    public static function encode(array $payload, $expirySeconds = 3600) {
        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        
        $payload['iat'] = time();
        $payload['exp'] = time() + $expirySeconds;
        
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::getSecret(), true);
        $base64UrlSignature = self::base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function decode($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;
        
        $header = json_decode(self::base64UrlDecode($base64UrlHeader), true);
        $payload = json_decode(self::base64UrlDecode($base64UrlPayload), true);
        
        if (!$header || !$payload) {
            return null;
        }
        
        // Verify signature
        $expectedSignature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::getSecret(), true);
        $expectedBase64UrlSignature = self::base64UrlEncode($expectedSignature);
        
        if (!hash_equals($expectedBase64UrlSignature, $base64UrlSignature)) {
            return null; // Signature verification failed
        }
        
        // Check expiration claim
        if (isset($payload['exp']) && time() >= $payload['exp']) {
            return null; // Token expired
        }
        
        return $payload;
    }
}
?>
