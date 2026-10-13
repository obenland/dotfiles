<?php
/**
 * Tests for the TestValue.PHPUnit.ExistenceAssertion sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Tests\PHPUnit;

/**
 * Tests for Sniffs/PHPUnit/ExistenceAssertionSniff.php.
 */
class ExistenceAssertionUnitTest extends TestValueSniffTestCase {

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
					6  => 1,
					7  => 1,
					11 => 1,
					12 => 1,
					13 => 1,
					14 => 1,
					15 => 1,
					16 => 1,
					17 => 1,
					18 => 1,
				),
			),
			'good' => array( self::fixture( '2' ), array() ),
		);
	}
}
