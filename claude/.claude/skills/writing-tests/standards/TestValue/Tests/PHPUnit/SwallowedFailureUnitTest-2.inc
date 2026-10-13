<?php

class Good_SwallowedFailure_Test extends Project_TestCase {

	public function test_conditional_skip() {
		if ( ! extension_loaded( 'intl' ) ) {
			$this->markTestSkipped( 'intl extension required' );
		}
		$this->assertSame( 'a', normalize( 'a' ) );
	}

	public function test_short_circuit_skip() {
		extension_loaded( 'imagick' ) || $this->markTestSkipped( 'imagick required' );
		$this->assertTrue( resize_image() );
	}

	public function test_skip_after_setup() {
		$version = get_db_version();
		if ( $version < 8 ) {
			$this->markTestSkipped( 'MySQL 8 required' );
		}
		$this->assertTrue( uses_cte() );
	}

	public function test_catch_and_assert() {
		try {
			parse( '' );
			$this->fail( 'Expected exception' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'empty', $e->getMessage() );
		}
	}

	public function setUp(): void {
		parent::setUp();
		$this->markTestSkipped( 'Not a test method; out of scope for this sniff.' );
	}

	// Back-test FP (2026-10): opting a subclass out of a parent's data-driven test.
	public function test_endpoint( $method = null, $endpoint = null ) {
		$this->markTestSkipped( 'Generic data-driven endpoint tests live on the parent class.' );
	}
}
