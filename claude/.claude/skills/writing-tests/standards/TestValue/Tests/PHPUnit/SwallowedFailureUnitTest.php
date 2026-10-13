<?php
/**
 * Tests for the TestValue.PHPUnit.SwallowedFailure sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Tests\PHPUnit;

/**
 * Tests for Sniffs/PHPUnit/SwallowedFailureSniff.php.
 */
class SwallowedFailureUnitTest extends TestValueSniffTestCase {

	/**
	 * Fixture path => expected warnings ( line => count ).
	 *
	 * @return array<string, array{0: string, 1: array<int, int>}>
	 */
	public static function data_fixtures(): array {
		return array(
			'bad'  => array(
				self::fixture( '1' ),
				array(
					9  => 1,
					19 => 1,
					25 => 1,
					31 => 1,
				),
			),
			'good' => array( self::fixture( '2' ), array() ),
		);
	}

	/**
	 * Skips inside a catch and unconditional skips report distinct codes.
	 *
	 * @return void
	 */
	public function test_codes(): void {
		$sources = $this->get_warning_sources( self::fixture( '1' ) );

		$this->assertSame( array( 'TestValue.PHPUnit.SwallowedFailure.InCatch' ), $sources[9] );
		$this->assertSame( array( 'TestValue.PHPUnit.SwallowedFailure.Unconditional' ), $sources[25] );
	}
}
