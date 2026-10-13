<?php

class Bad_SnapshotHash_Test extends Project_TestCase {

	public function test_snapshot() {
		$this->assertSame( 'd41d8cd98f00b204e9800998ecf8427e', md5( serialize( build_config() ) ) ); // WARN
		$this->assertEquals( '0a1b', sha1( json_encode( build_config() ) ) ); // WARN
		self::assertSame( 123, crc32( var_export( build_config(), true ) ) ); // WARN
		$this->assertSame(
			'abc',
			hash( 'sha256', print_r( build_config(), true ) ) // WARN
		);
	}
}
