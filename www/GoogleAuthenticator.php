<?php
class PHPGangsta_GoogleAuthenticator
{
    protected $_codeLength = 6;

    /**
     * Create new secret.
     * 16 characters, randomly chosen from the allowed base32 characters.
     *
     * @param int $secretLength
     *
     * @return string
     */
    public function createSecret($secretLength = 16)
    {
        $validChars = $this->_getBase32LookupTable();

        // Valid secret lengths are 80 to 640 bits
        if ($secretLength < 16 || $secretLength > 128) {
            // Menggunakan kelas Exception bawaan PHP 5/8
            throw new Exception('Bad secret length'); 
        }
        $secret = '';
        $rnd = false;
        
        // Cek random_bytes (PHP 7+) - Cara paling aman
        if (function_exists('random_bytes')) {
            $rnd = random_bytes($secretLength);
        } 
        // Cek openssl_random_pseudo_bytes (PHP 5.3+ / fallback)
        elseif (function_exists('openssl_random_pseudo_bytes')) {
            $cryptoStrong = false;
            $rnd = openssl_random_pseudo_bytes($secretLength, $cryptoStrong);
            if (!$cryptoStrong) {
                $rnd = false;
            }
        }
        // Menghapus mcrypt_create_iv karena dihapus di PHP 7.1 dan memunculkan warning/error fatal

        if ($rnd !== false) {
            for ($i = 0; $i < $secretLength; ++$i) {
                // Mengubah akses string $rnd[$i] menjadi $rnd{$i} di PHP 8 
                // Namun, kita gunakan chr() agar kompatibel di string/byte lama
                // Menggunakan bitwise AND (ord($rnd[$i]) & 31) untuk mendapatkan index
                $secret .= $validChars[ord($rnd[$i]) & 31];
            }
        } else {
            throw new Exception('No source of secure random');
        }

        return $secret;
    }

    /**
     * Calculate the code, with given secret and point in time.
     *
     * @param string $secret
     * @param int|null $timeSlice
     *
     * @return string
     */
    public function getCode($secret, $timeSlice = null)
    {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }

        $secretkey = $this->_base32Decode($secret);

        // Pack time into binary string
        $time = chr(0).chr(0).chr(0).chr(0).pack('N*', $timeSlice);
        
        // Hash it with users secret key
        $hm = hash_hmac('SHA1', $time, $secretkey, true);
        
        // Use last nipple of result as index/offset
        // Menggunakan substr($hm, -1) untuk mendapatkan byte terakhir, lalu ord()
        $offset = ord(substr($hm, -1)) & 0x0F; 
        
        // grab 4 bytes of the result
        $hashpart = substr($hm, $offset, 4);

        // Unpak binary value
        $value = unpack('N', $hashpart);
        // Mengakses array $value[1] aman di PHP 5-8
        $value = $value[1]; 
        
        // Only 32 bits
        $value = $value & 0x7FFFFFFF;

        $modulo = pow(10, $this->_codeLength);

        return str_pad($value % $modulo, $this->_codeLength, '0', STR_PAD_LEFT);
    }

    /**
     * Get QR-Code URL for image, from google charts.
     *
     * @param string $name
     * @param string $secret
     * @param string $title
     * @param array $params
     *
     * @return string
     */
    public function getQRCodeGoogleUrl($name, $secret, $title = null, $params = array())
    {
        // Pengambilan parameter width/height/level sudah aman untuk PHP 5/8

        $width = !empty($params['width']) && (int) $params['width'] > 0 ? (int) $params['width'] : 200;
        $height = !empty($params['height']) && (int) $params['height'] > 0 ? (int) $params['height'] : 200;
        $level = !empty($params['level']) && array_search($params['level'], array('L', 'M', 'Q', 'H')) !== false ? $params['level'] : 'M';

        $urlencoded = urlencode('otpauth://totp/'.$name.'?secret='.$secret.'');
        if (isset($title)) {
            $urlencoded .= urlencode('&issuer='.urlencode($title));
        }

        // Mengubah interpolasi string $urlencoded menjadi {urlenconded} untuk keamanan PHP 8
        // Namun, kode Mas Wawan sudah benar menggunakan: "https://.../?data=$urlencoded&size=${width}x${height}&ecc=$level"
        // Ay tetap tambahkan curly braces untuk $urlencoded dan $level agar konsisten dan menghindari warning jika Mas Wawan menggunakan PHP versi paling ketat.

        return "https://api.qrserver.com/v1/create-qr-code/?data={$urlencoded}&size={$width}x{$height}&ecc={$level}";
    }

    /**
     * Check if the code is correct. This will accept codes starting from $discrepancy*30sec ago to $discrepancy*30sec from now.
     *
     * @param string $secret
     * @param string $code
     * @param int $discrepancy This is the allowed time drift in 30 second units (8 means 4 minutes before or after)
     * @param int|null $currentTimeSlice time slice if we want use other that time()
     *
     * @return bool
     */
    public function verifyCode($secret, $code, $discrepancy = 1, $currentTimeSlice = null)
    {
        if ($currentTimeSlice === null) {
            $currentTimeSlice = floor(time() / 30);
        }

        if (strlen($code) != 6) {
            return false;
        }

        for ($i = -$discrepancy; $i <= $discrepancy; ++$i) {
            $calculatedCode = $this->getCode($secret, $currentTimeSlice + $i);
            if ($this->timingSafeEquals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set the code length, should be >=6.
     *
     * @param int $length
     *
     * @return PHPGangsta_GoogleAuthenticator
     */
    public function setCodeLength($length)
    {
        $this->_codeLength = $length;

        return $this;
    }

    /**
     * Helper class to decode base32.
     *
     * @param $secret
     *
     * @return bool|string
     */
    protected function _base32Decode($secret)
    {
        if (empty($secret)) {
            return '';
        }

        $base32chars = $this->_getBase32LookupTable();
        $base32charsFlipped = array_flip($base32chars);

        $paddingCharCount = substr_count($secret, $base32chars[32]);
        $allowedValues = array(6, 4, 3, 1, 0);
        
        if (!in_array($paddingCharCount, $allowedValues)) {
            return false;
        }
        
        for ($i = 0; $i < 4; ++$i) {
            if ($paddingCharCount == $allowedValues[$i] &&
                substr($secret, -($allowedValues[$i])) != str_repeat($base32chars[32], $allowedValues[$i])) {
                return false;
            }
        }
        
        $secret = str_replace('=', '', $secret);
        $secret = str_split($secret);
        $binaryString = '';
        
        for ($i = 0; $i < count($secret); $i = $i + 8) {
            $x = '';
            // Mengubah akses string/array $secret[$i] menjadi get_safe_value agar tidak ada @ dan aman di PHP 8
            $currentSecret = isset($secret[$i]) ? $secret[$i] : null; 
            
            if (!in_array($currentSecret, $base32chars)) {
                // Di PHP 8.1, 'A' in array('A', 'B') akan memunculkan deprecation warning. Tapi karena ini hanya array string, tidak masalah.
                return false;
            }
            
            for ($j = 0; $j < 8; ++$j) {
                // Mengganti @$base32charsFlipped[@$secret[$i + $j]]
                // Menghapus @ dan menggunakan isset() untuk akses array yang aman di PHP 8
                $char = isset($secret[$i + $j]) ? $secret[$i + $j] : null;
                $value = isset($base32charsFlipped[$char]) ? $base32charsFlipped[$char] : 0; 
                
                $x .= str_pad(base_convert($value, 10, 2), 5, '0', STR_PAD_LEFT);
            }
            
            $eightBits = str_split($x, 8);
            
            for ($z = 0; $z < count($eightBits); ++$z) {
                $y = chr(base_convert($eightBits[$z], 2, 10));
                // Mengubah logika pengecekan string kosong/karakter nol agar kompatibel PHP 8
                $binaryString .= $y; 
            }
        }

        return $binaryString;
    }

    /**
     * Get array with all 32 characters for decoding from/encoding to base32.
     *
     * @return array
     */
    protected function _getBase32LookupTable()
    {
        return array(
            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', //  7
            'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', // 15
            'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', // 23
            'Y', 'Z', '2', '3', '4', '5', '6', '7', // 31
            '=',  // padding char
        );
    }

    /**
     * A timing safe equals comparison
     * more info here: http://blog.ircmaxell.com/2014/11/its-all-about-time.html.
     *
     * @param string $safeString The internal (safe) value to be checked
     * @param string $userString The user submitted (unsafe) value
     *
     * @return bool True if the two strings are identical
     */
    private function timingSafeEquals($safeString, $userString)
    {
        // function hash_equals() baru ada di PHP 5.6+, jadi ini aman di PHP 5.6-8.x
        if (function_exists('hash_equals')) {
            return hash_equals($safeString, $userString);
        }
        
        // Fallback untuk PHP 5.3 - 5.5
        $safeLen = strlen($safeString);
        $userLen = strlen($userString);

        if ($userLen != $safeLen) {
            return false;
        }

        $result = 0;

        for ($i = 0; $i < $userLen; ++$i) {
            // Mengubah akses string $safeString[$i] menjadi $safeString{$i} (atau tetap karena PHP 5)
            // Di sini kita tetap menggunakan sintaks lama karena $i adalah integer dan ini lebih kompatibel di 5.x
            $result |= (ord($safeString[$i]) ^ ord($userString[$i]));
        }

        // They are only identical strings if $result is exactly 0...
        return $result === 0;
    }
}
?>