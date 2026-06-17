<?php
// TOTP.php - RFC 6238 Time-Based One-Time Password Implementation in pure PHP

class TOTP {
    // Alphabet for base32 decoding
    private static $base32Lookup = [
        'A' => 0,  'B' => 1,  'C' => 2,  'D' => 3,  'E' => 4,  'F' => 5,  'G' => 6,  'H' => 7,
        'I' => 8,  'J' => 9,  'K' => 10, 'L' => 11, 'M' => 12, 'N' => 13, 'O' => 14, 'P' => 15,
        'Q' => 16, 'R' => 17, 'S' => 18, 'T' => 19, 'U' => 20, 'V' => 21, 'W' => 22, 'X' => 23,
        'Y' => 24, 'Z' => 25, '2' => 26, '3' => 27, '4' => 28, '5' => 29, '6' => 30, '7' => 31
    ];

    private static function base32Decode($secret) {
        $secret = strtoupper(trim($secret));
        $secret = str_replace('=', '', $secret);
        
        $buf = '';
        $val = 0;
        $bits = 0;
        
        $len = strlen($secret);
        for ($i = 0; $i < $len; $i++) {
            $char = $secret[$i];
            if (!isset(self::$base32Lookup[$char])) {
                return false; // Invalid base32 character
            }
            
            $val = ($val << 5) | self::$base32Lookup[$char];
            $bits += 5;
            
            while ($bits >= 8) {
                $bits -= 8;
                $buf .= chr(($val >> $bits) & 0xFF);
            }
        }
        return $buf;
    }

    public static function generateSecret($length = 16) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    public static function getOTP($secret, $timeSlice = null) {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }
        
        $key = self::base32Decode($secret);
        if ($key === false) {
            return false;
        }
        
        // Pack time slice into 8 bytes big-endian
        $timeBytes = pack('N*', 0) . pack('N*', $timeSlice);
        
        // Compute HMAC-SHA1
        $hash = hash_hmac('sha1', $timeBytes, $key, true);
        
        // Dynamic truncation
        $offset = ord($hash[19]) & 0x0F;
        
        $otp = (
            (ord($hash[$offset]) & 0x7F) << 24 |
            (ord($hash[$offset + 1]) & 0xFF) << 16 |
            (ord($hash[$offset + 2]) & 0xFF) << 8 |
            (ord($hash[$offset + 3]) & 0xFF)
        );
        
        $otp = $otp % 1000000;
        return str_pad($otp, 6, '0', STR_PAD_LEFT);
    }

    public static function verify($secret, $code, $discrepancy = 1) {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $currentTimeSlice = floor(time() / 30);
        
        // Allow time discrepancy window (e.g. -1, 0, +1 time slice) to account for client-server time skew
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = self::getOTP($secret, $currentTimeSlice + $i);
            if ($calculatedCode !== false && hash_equals($calculatedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
}
?>
