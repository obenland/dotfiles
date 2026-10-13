<?php
/**
 * Tests for the TestValue.PHPUnit.ExcessiveMocks sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Tests\PHPUnit;

/**
 * Tests for Sniffs/PHPUnit/ExcessiveMocksSniff.php.
 */
class ExcessiveMocksUnitTest extends TestValueSniffTestCase {

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
					15 => 1,
				),
			),
			'good' => array( self::fixture( '2' ), array() ),
		);
	}

	/**
	 * The finding is a hint (severity 3) and reports the mock count.
	 *
	 * @return void
	 */
	public function test_reports_count_at_hint_severity(): void {
		$line    = $this->process_fixture( self::fixture( '1' ) )->getWarnings()[5];
		$message = reset( $line )[0];
		$this->assertSame( 3, $message['severity'] );
		$this->assertStringContainsString( 'creates 6 mocks', $message['message'] );
	}
}
