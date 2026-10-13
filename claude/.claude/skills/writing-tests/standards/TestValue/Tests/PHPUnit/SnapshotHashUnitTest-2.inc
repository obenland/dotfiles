<?php

class Good_SnapshotHash_Test extends Project_TestCase {

	public function test_cache_key_is_md5_of_input() {
		$this->assertSame( md5( 'user-1' ), cache_key( 'user-1' ) );
	}

	public function test_fields() {
		$config = build_config();
		$this->assertSame( 'prod', $config['env'] );
		$this->assertSame( [ 'env' => 'prod' ], json_decode( json_encode( $config ), true ) );
	}

	public function test_signature() {
		$payload = json_encode( [ 'a' => 1 ] );
		$this->assertTrue( verify_signature( $payload, hash_hmac( 'sha256', $payload, 'k' ) ) );
	}
}
