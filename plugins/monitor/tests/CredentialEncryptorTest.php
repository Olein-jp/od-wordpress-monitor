<?php
/**
 * Credential encryption tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Credential\CredentialEncryptor;
use UnexpectedValueException;

final class CredentialEncryptorTest extends \WP_UnitTestCase {
	private CredentialEncryptor $encryptor;

	public function set_up(): void {
		parent::set_up();
		$this->encryptor = new CredentialEncryptor( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
	}

	public function test_round_trip_returns_plaintext(): void {
		$ciphertext = $this->encryptor->encrypt( 'secret application password' );

		$this->assertSame( 'secret application password', $this->encryptor->decrypt( $ciphertext ) );
	}

	public function test_random_nonce_changes_ciphertext(): void {
		$this->assertNotSame( $this->encryptor->encrypt( 'same' ), $this->encryptor->encrypt( 'same' ) );
	}

	public function test_tampering_is_detected(): void {
		$payload                                       = base64_decode( $this->encryptor->encrypt( 'secret' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$payload[ SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ] = chr( ord( $payload[ SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ] ) ^ 1 );

		$this->expectException( UnexpectedValueException::class );
		$this->encryptor->decrypt( base64_encode( $payload ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
