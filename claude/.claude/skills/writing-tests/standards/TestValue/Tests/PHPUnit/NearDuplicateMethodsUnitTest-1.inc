<?php

class Bad_NearDuplicateMethods_Test extends Project_TestCase {

	public function test_parses_small_number() {
		$parser = new Parser();
		$result = $parser->parse( '12' );
		$this->assertSame( 12, $result );
	}

	public function test_parses_large_number() { // WARN
		// Comments and whitespace do not matter.
		$parser = new Parser();
		$result = $parser->parse(   '123456' );
		$this->assertSame( 123456, $result );
	}

	/**
	 * @test
	 */
	public function parses_float() { // WARN
		$parser = new Parser();
		$result = $parser->parse( "1.5" );
		$this->assertSame( 1.5, $result );
	}

	public function test_different_shape() {
		$parser = new Parser();
		$result = $parser->parse( '' );
		$this->assertNull( $result );
	}
}
