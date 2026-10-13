<?php
/**
 * Tests for the TestValue.PHPUnit.NearDuplicateMethods sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Tests\PHPUnit;

/**
 * Tests for Sniffs/PHPUnit/NearDuplicateMethodsSniff.php.
 */
class NearDuplicateMethodsUnitTest extends TestValueSniffTestCase {

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
					11 => 1,
					21 => 1,
				),
			),
			// Duplicates across different classes are not merged by a data provider, so not flagged.
			'good' => array( self::fixture( '2' ), array() ),
		);
	}

	/**
	 * The message names the earlier twin and its line.
	 *
	 * @return void
	 */
	public function test_message_points_at_first_method(): void {
		$line    = $this->process_fixture( self::fixture( '1' ) )->getWarnings()[21];
		$message = reset( $line )[0]['message'];
		$this->assertSame( 'Same shape as test_parses_small_number() at line 5; merge into one test with a data provider.', $message );
	}
}
