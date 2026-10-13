<?php
/**
 * Tests for the TestValue.PHPUnit.NoMeaningfulAssertion sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Tests\PHPUnit;

/**
 * Tests for Sniffs/PHPUnit/NoMeaningfulAssertionSniff.php.
 */
class NoMeaningfulAssertionUnitTest extends TestValueSniffTestCase {

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
					13 => 1,
					18 => 1,
					25 => 1,
					30 => 1,
					34 => 1,
					38 => 1,
					47 => 1,
					55 => 1,
				),
			),
			'good' => array( self::fixture( '2' ), array() ),
		);
	}

	/**
	 * Missing and trivially-true assertions report distinct codes.
	 *
	 * @return void
	 */
	public function test_codes_distinguish_missing_and_trivial_assertions(): void {
		$sources = $this->get_warning_sources( self::fixture( '1' ) );

		$this->assertSame( array( 'TestValue.PHPUnit.NoMeaningfulAssertion.NoAssertions' ), $sources[5] );
		$this->assertSame( array( 'TestValue.PHPUnit.NoMeaningfulAssertion.TrivialAssertion' ), $sources[13] );
	}
}
