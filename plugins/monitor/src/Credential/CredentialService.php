<?php
/**
 * Credential encryption and retrieval service.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Credential;

use Throwable;
use WP_Error;

final class CredentialService {
	public function __construct(
		private readonly CredentialRepository $repository,
		private readonly CredentialEncryptor $encryptor
	) {
	}

	/**
	 * Encrypt and persist an Application Password.
	 *
	 * @return int|WP_Error
	 */
	public function store( int $site_id, Credential $credential ) {
		try {
			$encrypted = $this->encryptor->encrypt( $credential->password() );
		} catch ( Throwable $exception ) {
			return new WP_Error( 'ENCRYPTION_FAILED', __( 'The credential could not be encrypted.', 'od-wordpress-monitor' ) );
		}

		return $this->repository->create( $site_id, $credential->username(), $encrypted );
	}

	/**
	 * Decrypt a credential only when an outbound request needs it.
	 *
	 * @return Credential|WP_Error
	 */
	public function for_site( int $site_id ) {
		$stored = $this->repository->find_by_site( $site_id );

		if ( null === $stored ) {
			return new WP_Error( 'CREDENTIAL_NOT_FOUND', __( 'No credential is stored for this site.', 'od-wordpress-monitor' ) );
		}

		try {
			return new Credential(
				$stored['username'],
				$this->encryptor->decrypt( $stored['encrypted_password'] )
			);
		} catch ( Throwable $exception ) {
			return new WP_Error( 'CREDENTIAL_DECRYPTION_FAILED', __( 'The stored credential could not be decrypted.', 'od-wordpress-monitor' ) );
		}
	}
}
