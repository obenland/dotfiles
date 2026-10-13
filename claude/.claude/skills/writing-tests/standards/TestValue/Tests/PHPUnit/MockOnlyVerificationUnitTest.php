<?php
/**
 * Tests for the TestValue.PHPUnit.MockOnlyVerification sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Tests\PHPUnit;

/**
 * Tests for Sniffs/PHPUnit/MockOnlyVerificationSniff.php.
 */
class MockOnlyVerificationUnitTest extends TestValueSniffTestCase {

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
					5  => 1,
					11 => 1,
				),
			),
			'good' => array( self::fixture( '2' ), array() ),
		);
	}
}
