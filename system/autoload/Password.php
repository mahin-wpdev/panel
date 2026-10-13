<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

class Password
{

    public static function _crypt($password)
    {
        return password_hash((string)$password, PASSWORD_BCRYPT);
    }

    public static function _verify($user_input, $hashed_password)
    {
        $stored = (string)$hashed_password;
        if (password_verify((string)$user_input, $stored)) {
            return true;
        }
        // Upgrade old SHA-1 credentials transparently at the next successful login.
        return preg_match('/^[a-f0-9]{40}$/i', $stored) === 1
            && hash_equals(strtolower($stored), sha1((string)$user_input));
    }

    public static function _needsRehash($hashed_password)
    {
        $stored = (string)$hashed_password;
        if (preg_match('/^[a-f0-9]{40}$/i', $stored) === 1) {
            return true;
        }
        return password_needs_rehash($stored, PASSWORD_BCRYPT);
    }

    public static function customerHash($password)
    {
        return password_hash((string)$password, PASSWORD_BCRYPT);
    }

    public static function customerVerify($password, $passwordHash, $legacyPassword = '')
    {
        $hash = (string)$passwordHash;
        if ($hash !== '') {
            return password_verify((string)$password, $hash);
        }
        return hash_equals((string)$legacyPassword, (string)$password);
    }
    public static function _uverify($user_input, $hashed_password)
    {
        if ($user_input == $hashed_password) {
            return true;
        }
        return false;
    }
    public static function _gen()
    {
        $pass = substr(str_shuffle(str_repeat('ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz@#!123456789', 8)), 0, 8);
        return $pass;
    }

    /**
     * verify CHAP password
     * @param string $realPassword
     * @param string $CHAPassword
     * @param string $CHAPChallenge
     * @return bool
     */
    public static function chap_verify($realPassword, $CHAPassword, $CHAPChallenge){
        $CHAPassword = substr($CHAPassword, 2);
        $chapid = substr($CHAPassword, 0, 2);
        $result = hex2bin($chapid) . $realPassword . hex2bin(substr($CHAPChallenge, 2));
        $response = $chapid . md5($result);
        return ($response != $CHAPassword);
    }
}
