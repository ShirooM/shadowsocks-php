<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
define('CHUNK_SIZE_LEN',   2);
define('AEAD_TAG_LEN',    16);

define('CRYPTO_ERROR',    -1);
define('CRYPTO_NEED_MORE', 0);
define('CRYPTO_OK',        1);

define('CHUNK_SIZE_MASK', 0x3FFF);

/**
 * 加密解密类
 * @author walkor<walkor@workerman.net>
 */
class Encryptor
{
    protected $_key;
    protected $_method;
    protected $_cipher;
    protected $_decipher;
    protected $_bytesToKeyResults = array();
    protected $_cipherIv;
    protected $_ivSent = false;
    protected static $_methodSupported = array(
        'aes-128-cfb'=> array(16, 16),
        'aes-192-cfb'=> array(24, 16),
        'aes-256-cfb'=> array(32, 16),
        'bf-cfb'=> array(16, 8),
        'camellia-128-cfb'=> array(16, 16),
        'camellia-192-cfb'=> array(24, 16),
        'camellia-256-cfb'=> array(32, 16),
        'cast5-cfb'=> array(16, 8),
        'des-cfb'=> array(8, 8),
        'idea-cfb'=>array(16, 8),
        'rc2-cfb'=> array(16, 8),
        //'rc4'=> array(16, 0),      //rc4的iv长度为0，会有问题，暂时去掉
        //'rc4-md5'=> array(16, 16), //php的openssl找不到rc4-md5这个算法，暂时去掉
        'seed-cfb'=> array(16, 16),
        'aes-256-gcm'=> array(32, 32),  //对于AEAD，第二个参数是salt长度
        'chacha20-poly1305'=> array(32, 32),
        'chacha20-ietf-poly1305'=> array(32, 32),
        'xchacha20-ietf-poly1305'=> array(32, 32),
    );
    
    public function __construct($key, $method)
    {
        $this->_key = $key;
        $this->_method = $method;
        if($this->checkAEADMethod($this->_method)) {
            //AEAD
            $salt_len = $this->getCipherLen($this->_method);
            $salt_len = $salt_len[1];
            $salt = openssl_random_pseudo_bytes($salt_len);
            $this->_cipher = $this->getcipher($this->_key, $this->_method, 1, $salt);
        } else {
            $iv_size = openssl_cipher_iv_length($this->_method); 
            $iv = openssl_random_pseudo_bytes($iv_size); 
            $this->_cipher = $this->getcipher($this->_key, $this->_method, 1, $iv);
        }
    }
    
    protected function getCipher($password, $method, $op, $iv)
    {
        $method = strtolower($method);
        $m = $this->getCipherLen($method);
        if($m) {
            $ref = $this->EVPBytesToKey($password, $m[0], $m[1]);
            $key = $ref[0];
            $iv_ = $ref[1];
            if ($iv == null) {
                $iv = $iv_;
            }
            $iv = substr($iv, 0, $m[1]);
            if ($op === 1) {
                $this->_cipherIv = $iv;
            }
            if($this->checkAEADMethod($method)) {
                $salt = $iv;
                if($op === 1) {
                    return new AEADEncipher($method, $key, $salt);
                } else {
                    return new AEADDecipher($method, $key, $salt);
                }
            } else if ($method === 'rc4-md5') {
                return $this->createRc4Md5Cipher($key, $iv, $op);
            } else {
                if($op === 1) {
                    return new Encipher($method, $key, $iv);
                } else {
                    return new Decipher($method, $key, $iv);
                }
            }
        }
    }

    public function encrypt($buffer)
    {
        if($this->_method) {
            $result = $this->_cipher->update($buffer);
            if ($this->_ivSent)
            {
                return $result;
            }
            else 
            {
              $this->_ivSent = true;
              return $this->_cipherIv . $result;
            }
        }
    }

    public function decrypt($buffer)
    {
        if($this->_method) {
            if(!$this->_decipher) {
                $decipher_iv_len = $this->getCipherLen($this->_method);
                $decipher_iv_len = $decipher_iv_len[1];
                $decipher_iv = substr($buffer, 0, $decipher_iv_len);
                $this->_decipher = $this->getCipher($this->_key, $this->_method, 0, $decipher_iv);
                $result = $this->_decipher->update(substr($buffer, $decipher_iv_len));
                return $result;
            } else {
                $result = $this->_decipher->update($buffer);
                return $result;
            }
        }
    }
    
    protected function createRc4Md5Cipher($key, $iv, $op)
    {
        $rc4_key = md5($key.$iv);
        if($op === 1) 
        {
            return new Encipher('rc4', $rc4_key, '');
        } 
        else 
        {
            return Decipher('rc4', $rc4_key, '');
        }
    }

    protected function EVPBytesToKey($password, $key_len, $iv_len)
    {
        $cache_key = "$password:$key_len:$iv_len";
        if(isset($this->_bytesToKeyResults[$cache_key]))
        {
          return $this->_bytesToKeyResults[$cache_key];
        }
        $m = array();
        $i = 0;
        $count = 0;
        while ($count < $key_len + $iv_len) 
        {
          $data = $password;
          if ($i > 0) 
          {
            $data = $m[$i-1] . $password;
          }
          $d = md5($data, true);
          $m[] = $d;
          $count += strlen($d);
          $i += 1;
        }
        $ms = '';
        foreach($m as $buf)
        {
           $ms .= $buf;
        }
        $key = substr($ms, 0, $key_len);
        $iv =  substr($ms, $key_len, $key_len + $iv_len);
        $this->bytesToKeyResults[$password] = array($key, $iv);
        return array($key, $iv);
    }
    
    protected function getCipherLen($method)
    {
        $method = strtolower($method);
        return isset(self::$_methodSupported[$method]) ? self::$_methodSupported[$method] : null;
    }

    protected function checkAEADMethod($method)
    {
        if($method == 'aes-256-gcm') {
            return true;
        }
        if($method == 'chacha20-poly1305') {
            return true;
        }        
        if($method == 'chacha20-ietf-poly1305') {
            return true;
        }
        if($method == 'xchacha20-ietf-poly1305') {
            return true;
        }
        return false;
    }
}

class Encipher
{
    protected $_algorithm;
    protected $_key;
    protected $_iv;
    protected $_tail;
    protected $_ivLength;

    public function __construct($algorithm, $key, $iv)
    {
        $this->_algorithm = $algorithm;
        $this->_key = $key;
        $this->_iv = $iv;
        $this->_ivLength = openssl_cipher_iv_length($algorithm);
    }

    public function update($data)
    {
        if (strlen($data) == 0)
            return '';
        $tl = strlen($this->_tail);
        if ($tl)
            $data = $this->_tail . $data;
        $b = openssl_encrypt($data, $this->_algorithm, $this->_key, OPENSSL_RAW_DATA, $this->_iv);
        $result = substr($b, $tl);
        $dataLength = strlen($data);
        $mod = $dataLength%$this->_ivLength;
        if ($dataLength >= $this->_ivLength) {
          $iPos = -($mod + $this->_ivLength);
          $this->_iv = substr($b, $iPos, $this->_ivLength);
        }
        $this->_tail = $mod!=0 ? substr($data, -$mod):'';
        return $result;
    }
}

class Decipher extends Encipher
{
    public function update($data)
    {
        if (strlen($data) == 0)
            return '';
        $tl = strlen($this->_tail);
        if ($tl)
            $data = $this->_tail . $data;
        $b = openssl_decrypt($data, $this->_algorithm, $this->_key, OPENSSL_RAW_DATA, $this->_iv);
        $result = substr($b, $tl);
        $dataLength = strlen($data);
        $mod = $dataLength%$this->_ivLength;
        if ($dataLength >= $this->_ivLength) {
          $iPos = -($mod + $this->_ivLength);
          $this->_iv = substr($data, $iPos, $this->_ivLength); 
        }
        $this->_tail = $mod!=0 ? substr($data, -$mod):'';
        return $result;
    }
}

class AEADEncipher
{
    protected $_algorithm;
    protected $_aead_tail;
    protected $_aead_subkey;
    protected $_aead_iv;
    protected $_aead_chunk_id;
    protected static $_methodSupported = array(
        'aes-256-gcm'=> array(32, 12),
        'chacha20-poly1305'=> array(32, 8),
        'chacha20-ietf-poly1305'=> array(32, 12),
        'xchacha20-ietf-poly1305'=> array(32, 24),
    );

    public function __construct($algorithm, $key, $salt)
    {
        $this->_algorithm = $algorithm;
        $this->_aead_tail = '';
        $iv_len = self::$_methodSupported[$algorithm][1];
        $this->_aead_iv = str_repeat("\x00", $iv_len);
        /* subkey生成 */
        $this->_aead_subkey = hash_hkdf("sha1", $key, strlen($key), "ss-subkey", $salt);
        $this->_aead_chunk_id = 0;
    }

    public function update($data)
    {
        $result = '';
        while(strlen($data) > 0) {
            $temp = '';
            $err = $this->aead_chunk_encrypt($this->_aead_iv, $this->_aead_subkey, $data, $temp);
            if($err == CRYPTO_ERROR) {
                echo "[" .__FILE__ . " " . __LINE__ . "]" . "AEAD encrypt error\n";
                return '';
            }
            $result .= $temp;
        }
        
        return $result;
    }

    protected function aead_chunk_encrypt(&$iv, $subkey, &$buffer, &$result)
    {
        /*
         * Shadowsocks AEAD chunk:
         *
         *  +--------------------------+------------+-------------------+-------------+
         *  | encrypted payload length | length tag | encrypted payload | payload tag |
         *  +--------------------------+------------+-------------------+-------------+
         *  |             2            |     16     |        n          |     16      |
         *  +--------------------------+------------+-------------------+-------------+
         *
         */
        $plen = strlen($buffer);
        if($plen > CHUNK_SIZE_MASK) {
            $plen = CHUNK_SIZE_MASK;
        }
        $data = substr($buffer, 0, $plen);
        $plen_bin = pack('n', $plen);
        $result .= $this->aead_encrypt($plen_bin, '', $iv, $subkey);
        if(strlen($result) !=  AEAD_TAG_LEN + CHUNK_SIZE_LEN) {
            return CRYPTO_ERROR;
        }
        sodium_increment($iv);
        $result .= $this->aead_encrypt($data, '', $iv, $subkey);
        if(strlen($result) !=  2*AEAD_TAG_LEN + CHUNK_SIZE_LEN + $plen) {
            return CRYPTO_ERROR;
        }
        sodium_increment($iv);
        $this->_aead_chunk_id++;
        $buffer = substr($buffer, $plen);
        return CRYPTO_OK;
    }

    protected function aead_encrypt($msg, $ad, $nonce, $key)
    {
        if($this->_algorithm == 'aes-256-gcm')
            return sodium_crypto_aead_aes256gcm_encrypt($msg, $ad, $nonce, $key);
        else if($this->_algorithm == 'chacha20-poly1305')
            return sodium_crypto_aead_chacha20poly1305_encrypt($msg, $ad, $nonce, $key);
        else if($this->_algorithm == 'chacha20-ietf-poly1305')
            return sodium_crypto_aead_chacha20poly1305_ietf_encrypt($msg, $ad, $nonce, $key);
        else if($this->_algorithm == 'xchacha20-ietf-poly1305')
            return sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($msg, $ad, $nonce, $key);
    }
}

class AEADDecipher extends AEADEncipher
{
    public function update($data)
    {
        $tl = strlen($this->_aead_tail);
        if($tl) {
            $data = $this->_aead_tail . $data;
            $this->_aead_tail = '';
        }

        $result = '';
        while(strlen($data) > 0) {
            $err = $this->aead_chunk_decrypt($this->_aead_iv, $this->_aead_subkey, $data, $result);
            if($err == CRYPTO_ERROR) {
                echo "[ " . __LINE__ . "]" . "AEAD decrypt error\n";
                return '';
            } else if($err == CRYPTO_NEED_MORE) {
                if( strlen($data) == 0 ) {
                    echo "[ " . __LINE__ . "]" . "AEAD decrypt error\n";
                    return '';
                } else {
                    $this->_aead_tail .= $data;
                    //echo "[ " . __LINE__ . "]" . "AEAD decrypt tail\n";
                    break;
                }
            }
        }
        
        return $result;
    }

    protected function aead_chunk_decrypt(&$iv, $subkey, &$buffer, &$result)
    {
        /*
         * Shadowsocks AEAD chunk:
         *
         *  +--------------------------+------------+-------------------+-------------+
         *  | encrypted payload length | length tag | encrypted payload | payload tag |
         *  +--------------------------+------------+-------------------+-------------+
         *  |             2            |     16     |        n          |     16      |
         *  +--------------------------+------------+-------------------+-------------+
         *
         */
        //验证chunk长度
        if(strlen($buffer) <= 2 * AEAD_TAG_LEN + CHUNK_SIZE_LEN) {
            return CRYPTO_NEED_MORE;
        }

        $payload_length_enc_length = AEAD_TAG_LEN + CHUNK_SIZE_LEN;
        $payload_length_enc = substr($buffer, 0, $payload_length_enc_length);

        $mlen = $this->aead_decrypt($payload_length_enc, '', $iv, $subkey);
        if(strlen($mlen) != CHUNK_SIZE_LEN) {
            echo "[ " . __LINE__ . "]" . "mlen error! id: " . $this->_aead_chunk_id . "\n";
            return CRYPTO_ERROR;
        }
        $payload_length = unpack('n', $mlen);
        $payload_length = intval($payload_length[1]) & CHUNK_SIZE_MASK;
        $payload_enc_length = $payload_length + AEAD_TAG_LEN;
        //验证payload长度
        if(strlen($buffer) - $payload_length_enc_length < $payload_enc_length) {
            return CRYPTO_NEED_MORE;
        }
        $buffer = substr($buffer, $payload_length_enc_length);
        $payload_enc = substr($buffer, 0, $payload_enc_length);
        $buffer = substr($buffer, $payload_enc_length);
        sodium_increment($iv);
        $result .= $this->aead_decrypt($payload_enc, '', $iv, $subkey);
        sodium_increment($iv);
        $this->_aead_chunk_id++;
        return CRYPTO_OK;
    }

    protected function aead_decrypt($msg, $ad, $nonce, $key)
    {
        if($this->_algorithm == 'aes-256-gcm')
            return sodium_crypto_aead_aes256gcm_decrypt($msg, $ad, $nonce, $key);
        else if($this->_algorithm == 'chacha20-poly1305')
            return sodium_crypto_aead_chacha20poly1305_decrypt($msg, $ad, $nonce, $key);
        else if($this->_algorithm == 'chacha20-ietf-poly1305')
            return sodium_crypto_aead_chacha20poly1305_ietf_decrypt($msg, $ad, $nonce, $key);
        else if($this->_algorithm == 'xchacha20-ietf-poly1305')
            return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($msg, $ad, $nonce, $key);
    }
}
