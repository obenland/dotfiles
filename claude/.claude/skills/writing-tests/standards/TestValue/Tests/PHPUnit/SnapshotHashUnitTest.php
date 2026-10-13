<?php
/**
 * Tests for the TestValue.PHPUnit.SnapshotHash sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Tests\PHPUnit;

/**
 * Tests for Sniffs/PHPUnit/SnapshotHashSniff.php.
 */
class SnapshotHashUnitTest extends TestValueSniffTestCase {

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
					8  => 1,
					11 => 1,
				),
			),
			'good' => array( self::fixture( '2' ), array() ),
		);
	}
}
