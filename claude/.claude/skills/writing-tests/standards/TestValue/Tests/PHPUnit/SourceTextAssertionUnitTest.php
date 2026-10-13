<?php
/**
 * Tests for the TestValue.PHPUnit.SourceTextAssertion sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Tests\PHPUnit;

/**
 * Tests for Sniffs/PHPUnit/SourceTextAssertionSniff.php.
 */
class SourceTextAssertionUnitTest extends TestValueSniffTestCase {

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
					11 => 1,
					16 => 1,
					20 => 1,
					25 => 1,
				),
			),
			'good' => array( self::fixture( '2' ), array() ),
		);
	}
}
