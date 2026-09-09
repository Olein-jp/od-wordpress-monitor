<?php
/**
 * JSON metadata codec tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Support\MetadataCodec;

final class MetadataCodecTest extends \WP_UnitTestCase {
	public function test_round_trips_safe_metadata(): void {
		$codec    = new MetadataCodec();
		$metadata = array(
			'http_status' => 200,
			'redirected'  => false,
		);
		$encoded  = $codec->encode( $metadata );

		$this->assertIsString( $encoded );
		$this->assertSame( $metadata, $codec->decode( $encoded ) );
	}

	public function test_encode_failure_returns_an_error(): void {
		$codec             = new MetadataCodec();
		$recursive         = array();
		$recursive['self'] = &$recursive;

		$this->assertWPError( $codec->encode( $recursive ) );
	}

	public function test_decode_failure_and_sensitive_json_return_empty_metadata(): void {
		$codec = new MetadataCodec();

		$this->assertSame( array(), $codec->decode( '{bad json' ) );
		$this->assertSame( array(), $codec->decode( '{"password":"secret"}' ) );
	}
}
