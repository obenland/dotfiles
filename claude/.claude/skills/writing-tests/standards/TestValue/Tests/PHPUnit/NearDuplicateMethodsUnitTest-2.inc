<?php

class Good_NearDuplicateMethods_Test extends Project_TestCase {

	/**
	 * @dataProvider data_numbers
	 */
	public function test_parses_numbers( $input, $expected ) {
		$parser = new Parser();
		$result = $parser->parse( $input );
		$this->assertSame( $expected, $result );
	}

	public function data_numbers() {
		return [ [ '12', 12 ], [ '1.5', 1.5 ] ];
	}

	public function test_short_one() {
		$this->assertSame( 1, one() );
	}

	public function test_short_two() {
		$this->assertSame( 2, two() );
	}

	public function test_empty_input() {
		$parser = new Parser();
		$result = $parser->parse( '' );
		$this->assertNull( $result );
	}

	private function helper_a() {
		$parser = new Parser();
		$result = $parser->parse( 'x' );
		return $result;
	}

	private function helper_b() {
		$parser = new Parser();
		$result = $parser->parse( 'y' );
		return $result;
	}
}

class Other_NearDuplicateMethods_Test extends Project_TestCase {

	public function test_empty_input() {
		$parser = new Parser();
		$result = $parser->parse( '' );
		$this->assertNull( $result );
	}
}
