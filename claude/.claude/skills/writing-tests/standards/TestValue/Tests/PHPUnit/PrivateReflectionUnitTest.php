<?php
/**
 * Tests for the TestValue.PHPUnit.PrivateReflection sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Tests\PHPUnit;

/**
 * Tests for Sniffs/PHPUnit/PrivateReflectionSniff.php.
 */
class PrivateReflectionUnitTest extends TestValueSniffTestCase {

	/**
	 * Fixture path => expected warnings ( line => count ).
	 *
	 * @return array<string, array{0: string, 1: array<int, int>}>
	 */
	public static function data_fixtures(): array {
		return array(
			// One warning per method, including private helpers.
			'bad'  => array(
				self::fixture( '1' ),
				array(
					6  => 1,
					12 => 1,
					18 => 1,
					23 => 1,
					31 => 1,
				),
			),
			'good' => array( self::fixture( '2' ), array() ),
		);
	}
}
