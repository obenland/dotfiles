<?php
/**
 * TestValue.PHPUnit.NoMeaningfulAssertion sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Sniffs\PHPUnit;

use PHP_CodeSniffer\Files\File;
use TestValue\AbstractTestValueSniff;

/**
 * Flags test methods that assert nothing, or only assert a literal (assertTrue( true )).
 */
class NoMeaningfulAssertionSniff extends AbstractTestValueSniff {

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
		$preamble = $this->get_method_preamble( $phpcs_file, $function_ptr );
		if ( array_intersect( array( '@doesnotperformassertions', '@expectedexception' ), $preamble['tags'] )
			|| in_array( 'doesnotperformassertions', $preamble['attributes'], true )
		) {
			return;
		}

		$first_trivial = null;
		foreach ( $this->find_calls( $phpcs_file, $opener, $closer ) as $call ) {
			if ( $this->is_expectation_call( $call ) || $this->is_mock_expectation_call( $call ) || $this->is_indirect_assertion( $phpcs_file, $call ) ) {
				return;
			}

			// A skipped or incomplete test is reported as such, not as a silent pass.
			if ( $this->is_self_call( $call ) && in_array( $call['name'], array( 'marktestskipped', 'marktestincomplete' ), true ) ) {
				return;
			}

			if ( ! $this->is_assert_call( $call ) ) {
				// parent::*() or an inherited helper may assert; the verification is unknown.
				if ( $this->is_unresolved_inherited_call( $phpcs_file, $call ) ) {
					return;
				}
				continue;
			}

			if ( ! $this->is_trivial_assertion( $phpcs_file, $call ) ) {
				return;
			}

			if ( null === $first_trivial ) {
				$first_trivial = $call['ptr'];
			}
		}

		if ( null !== $first_trivial ) {
			$phpcs_file->addWarning(
				'Test only asserts a literal (e.g. assertTrue( true )), so it only fails if the code throws. Assert on the result or side effect, or use expectNotToPerformAssertions() if not throwing is the contract.',
				$first_trivial,
				'TrivialAssertion',
				array(),
				3
			);
			return;
		}

		$phpcs_file->addWarning(
			'Test has no assertions or expectations, so it only fails on a fatal error. Assert on the observable result, or delete it.',
			$function_ptr,
			'NoAssertions'
		);
	}
}
