<?php
/**
 * TestValue.PHPUnit.MockOnlyVerification sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Sniffs\PHPUnit;

use PHP_CodeSniffer\Files\File;
use TestValue\AbstractTestValueSniff;

/**
 * Flags tests whose only verification is mock expectations (->expects()).
 */
class MockOnlyVerificationSniff extends AbstractTestValueSniff {

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
		$has_expects = false;
		foreach ( $this->find_calls( $phpcs_file, $opener, $closer ) as $call ) {
			if ( $this->is_expectation_call( $call ) || $this->is_indirect_assertion( $phpcs_file, $call ) ) {
				return;
			}

			if ( $this->is_assert_call( $call ) && ! $this->is_trivial_assertion( $phpcs_file, $call ) ) {
				return;
			}

			if ( 'method' === $call['kind'] && 'expects' === $call['name'] ) {
				$has_expects = true;
			}
		}

		if ( ! $has_expects ) {
			return;
		}

		$phpcs_file->addWarning(
			'Test only verifies mock expectations (->expects()), so it checks wiring, not results. Also assert on the return value or state the code under test produces.',
			$function_ptr,
			'Found',
			array(),
			3
		);
	}
}
