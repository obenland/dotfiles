<?php

class Bad_SwallowedFailure_Test extends Project_TestCase {

	public function test_catch_skip() {
		try {
			$result = call_remote_api();
		} catch ( Exception $e ) {
			$this->markTestSkipped( 'API unavailable: ' . $e->getMessage() ); // WARN
		}
		$this->assertSame( 200, $result->code );
	}

	public function test_catch_incomplete() {
		try {
			$this->assertSame( 1, flaky() );
		} catch ( \Throwable $e ) {
			if ( true ) {
				self::markTestIncomplete( 'flaky' ); // WARN
			}
		}
	}

	public function test_always_skipped() {
		$this->markTestSkipped( 'Broken since the refactor.' ); // WARN
		$this->assertSame( 1, broken() );
	}

	public function test_always_incomplete() {
		// TODO: finish.
		static::markTestIncomplete(); // WARN
	}
}
