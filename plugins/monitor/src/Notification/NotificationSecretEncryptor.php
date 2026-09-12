<?php
/**
 * Authenticated encryption for notification secrets.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use RuntimeException;
use UnexpectedValueException;

final class NotificationSecretEncryptor {
	private readonly string $key;

	public function __construct( ?string $key = null ) {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			throw new RuntimeException( 'The sodium PHP extension is required.' );
		}

		$this->key = null === $key ? $this->derive_key() : $key;

		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $this->key ) ) {
			throw new RuntimeException( 'The notification encryption key has an invalid length.' );
		}
	}

	public function encrypt( string $plaintext ): string {
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );

		return base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function decrypt( string $encoded ): string {
		$payload = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $payload || strlen( $payload ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new UnexpectedValueException( 'The encrypted notification secret is invalid.' );
		}

		$nonce      = substr( $payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->key );

		if ( false === $plaintext ) {
			throw new UnexpectedValueException( 'Notification secret authentication failed.' );
		}

		return $plaintext;
	}

	private function derive_key(): string {
		return hash_hkdf(
			'sha256',
			wp_salt( 'auth' ),
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
			'od-wordpress-monitor-notifications'
		);
	}
}
