<?php
/**
 * TestValue.PHPUnit.ExcessiveMocks sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Sniffs\PHPUnit;

use PHP_CodeSniffer\Files\File;
use TestValue\AbstractTestValueSniff;

/**
 * Flags test methods that build many mocks (hint-level severity).
 */
class ExcessiveMocksSniff extends AbstractTestValueSniff {

	/**
	 * Mock count above which a warning is raised.
	 *
	 * @var int
	 */
	public int $max_mocks = 4;

	/**
	 * TestCase methods that create a mock or stub.
	 */
	private const MOCK_FACTORIES = array(
		'createmock',
		'createstub',
		'getmockbuilder',
		'createpartialmock',
		'createconfiguredmock',
		'getmockforabstractclass',
	);

	/**
	 * Processes a test method body.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $function_ptr   The T_FUNCTION token.
	 * @param int  $opener     The method body opening brace.
	 * @param int  $closer     The method body closing brace.
	 *
	 * @return void
	 */
	protected function process_test_method( File $phpcs_file, int $function_ptr, int $opener, int $closer ): void {
		$count = 0;
		foreach ( $this->find_calls( $phpcs_file, $opener, $closer ) as $call ) {
			if ( in_array( $call['kind'], array( 'method', 'static' ), true ) && in_array( $call['name'], self::MOCK_FACTORIES, true ) ) {
				++$count;
			}
		}

		if ( $count <= (int) $this->max_mocks ) {
			return;
		}

		$phpcs_file->addWarning(
			'Test creates %d mocks; heavy mocking tests wiring, not behavior. Use real collaborators or simple fakes, or split the unit under test.',
			$function_ptr,
			'Found',
			array( $count ),
			3
		);
	}
}
