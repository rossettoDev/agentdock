<?php

declare(strict_types=1);

namespace Drupal\ab_api;

use Drupal\Core\Site\Settings;

/**
 * Encrypts a claim replay with a key that stays out of the database.
 */
final class IdempotencySeal {

  /**
   * Refuses to seal when the external key is missing.
   */
  public function assertConfigured(): void {
    $this->key();
  }

  /**
   * Encrypts the response body.
   */
  public function encrypt(string $plaintext): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key());
    return base64_encode($nonce . $cipher);
  }

  /**
   * Decrypts a stored response body.
   */
  public function decrypt(string $stored): string {
    $raw = base64_decode($stored, TRUE);
    $nonceSize = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if (!is_string($raw) || strlen($raw) <= $nonceSize) {
      throw new ApiException(
        409,
        'idempotency_unreadable',
        'The stored response cannot be recovered.',
      );
    }
    $plain = sodium_crypto_secretbox_open(
      substr($raw, $nonceSize),
      substr($raw, 0, $nonceSize),
      $this->key(),
    );
    if (!is_string($plain)) {
      throw new ApiException(
        409,
        'idempotency_unreadable',
        'The stored response cannot be recovered.',
      );
    }
    return $plain;
  }

  /**
   * Loads the 32-byte key from settings.
   */
  private function key(): string {
    $encoded = Settings::get('ab_api_idempotency_key', '');
    $key = is_string($encoded) ? base64_decode($encoded, TRUE) : FALSE;
    $size = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    if (!is_string($key) || strlen($key) !== $size) {
      throw new ApiException(
        500,
        'idempotency_unconfigured',
        'Idempotency seal is not configured.',
      );
    }
    return $key;
  }

}
