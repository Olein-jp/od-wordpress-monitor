<?php
/**
 * Shared normalized monitor error codes.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Support;

final class ErrorCode {
	public const AGENT_ERROR                   = 'AGENT_ERROR';
	public const AGENT_NOT_FOUND               = 'AGENT_NOT_FOUND';
	public const AUTHENTICATION_FAILED         = 'AUTHENTICATION_FAILED';
	public const CERTIFICATE_ERROR             = 'CERTIFICATE_ERROR';
	public const CERTIFICATE_EXPIRED           = 'CERTIFICATE_EXPIRED';
	public const CERTIFICATE_EXPIRING          = 'CERTIFICATE_EXPIRING';
	public const CERTIFICATE_NOT_YET_VALID     = 'CERTIFICATE_NOT_YET_VALID';
	public const CERTIFICATE_VALIDATION_FAILED = 'CERTIFICATE_VALIDATION_FAILED';
	public const CONNECTION_ERROR              = 'CONNECTION_ERROR';
	public const CREDENTIAL_DECRYPTION_FAILED  = 'CREDENTIAL_DECRYPTION_FAILED';
	public const CREDENTIAL_NOT_FOUND          = 'CREDENTIAL_NOT_FOUND';
	public const ENCRYPTION_FAILED             = 'ENCRYPTION_FAILED';
	public const HTTPS_REQUIRED                = 'HTTPS_REQUIRED';
	public const HTTP_STATUS                   = 'HTTP_STATUS';
	public const INVALID_CERTIFICATE           = 'INVALID_CERTIFICATE';
	public const INVALID_JSON                  = 'INVALID_JSON';
	public const INVALID_RESPONSE              = 'INVALID_RESPONSE';
	public const INVALID_URL                   = 'INVALID_URL';
	public const PERMISSION_DENIED             = 'PERMISSION_DENIED';
	public const REDIRECT_LIMIT                = 'REDIRECT_LIMIT';
	public const RUNNER_ERROR                  = 'RUNNER_ERROR';
	public const SSL_UNAVAILABLE               = 'SSL_UNAVAILABLE';
	public const TIMEOUT                       = 'TIMEOUT';
	public const UNSAFE_REDIRECT               = 'UNSAFE_REDIRECT';
	public const UNSUPPORTED_SCHEMA            = 'UNSUPPORTED_SCHEMA';
}
