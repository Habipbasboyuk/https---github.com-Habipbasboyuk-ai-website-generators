<?php

namespace UiXpress\Utility;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class Encryption
 *
 * Provides encryption and decryption functionality using AES-256-CBC.
 */
class Encryption
{
  /**
   * Decrypts an encrypted string.
   *
   * @param string $string The encrypted string to decrypt.
   * @return string|false The decrypted string or false on failure.
   */
  public static function decrypt($raw_value)
  {
    if (!extension_loaded("openssl")) {
      return $raw_value;
    }

    $raw_value = base64_decode($raw_value, true);
    $method = "aes-256-ctr";
    $ivlen = openssl_cipher_iv_length($method);
    $iv = substr($raw_value, 0, $ivlen);
    $raw_value = substr($raw_value, $ivlen);

    $value = openssl_decrypt($raw_value, $method, LOGGED_IN_KEY, 0, $iv);

    if (!$value || substr($value, -strlen(LOGGED_IN_SALT)) !== LOGGED_IN_SALT) {
      return false;
    }

    return substr($value, 0, -strlen(LOGGED_IN_SALT));
  }

  /**
   * Encrypts a string using AES-256-CBC.
   *
   * @param string $string The string to encrypt.
   * @return string The encrypted string.
   */
  public static function encrypt($value)
  {
    if (!extension_loaded("openssl")) {
      return $value;
    }

    // Check if the value is already encrypted
    if (self::isEncrypted($value)) {
      return $value;
    }

    $method = "aes-256-ctr";
    $ivlen = openssl_cipher_iv_length($method);
    $iv = openssl_random_pseudo_bytes($ivlen);

    $raw_value = openssl_encrypt($value . LOGGED_IN_SALT, $method, LOGGED_IN_KEY, 0, $iv);

    if (!$raw_value) {
      return false;
    }

    return base64_encode($iv . $raw_value);
  }

  /**
   * Checks if a value is already encrypted.
   *
   * @param string $value The value to check.
   * @return bool True if the value is encrypted, false otherwise.
   */
  public static function isEncrypted($value)
  {
    $method = "aes-256-ctr";
    $ivlen = openssl_cipher_iv_length($method);

    $decoded_value = base64_decode($value, true);

    if (strlen($decoded_value) < $ivlen) {
      return false;
    }

    $iv = substr($decoded_value, 0, $ivlen);
    $raw_value = substr($decoded_value, $ivlen);

    return !empty($raw_value);
  }
}
