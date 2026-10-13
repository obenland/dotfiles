<?php
/**
 * TestValue.PHPUnit.SwallowedFailure sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Sniffs\PHPUnit;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;
use TestValue\AbstractTestValueSniff;

/**
 * Flags markTestSkipped()/markTestIncomplete() that hide failures or always skip.
 */
class SwallowedFailureSniff extends AbstractTestValueSniff {

	/**
	 * TestCase methods that skip or abandon a test.
	 */
	private const SKIP_METHODS = array( 'marktestskipped', 'marktestincomplete' );

	/**
	 * Operators that make a statement-level skip conditional, e.g.
	 * extension_loaded( 'x' ) || $this->markTestSkipped().
	 */
	private const CONDITIONAL_OPERATORS = array(
		\T_BOOLEAN_OR,
		\T_BOOLEAN_AND,
		\T_LOGICAL_OR,
		\T_LOGICAL_AND,
		\T_INLINE_THEN,
		\T_INLINE_ELSE,
		\T_COALESCE,
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
		$tokens = $phpcs_file->getTokens();

		foreach ( $this->find_calls( $phpcs_file, $opener, $closer ) as $call ) {
			if ( ! $this->is_self_call( $call ) || ! in_array( $call['name'], self::SKIP_METHODS, true ) ) {
				continue;
			}

			// Scopes nested inside the test method.
			$inner      = array();
			$past_owner = false;
			foreach ( $tokens[ $call['ptr'] ]['conditions'] as $ptr => $code ) {
				if ( $past_owner ) {
					$inner[ $ptr ] = $code;
				}
				if ( $ptr === $function_ptr ) {
					$past_owner = true;
				}
			}

			if ( in_array( \T_CATCH, $inner, true ) ) {
				$phpcs_file->addWarning(
					'%s() inside a catch block reports real failures as skips. Let the exception fail the test, or catch only a specific, expected environment error.',
					$call['ptr'],
					'InCatch',
					array( $tokens[ $call['ptr'] ]['content'] )
				);
				continue;
			}

			if ( ! empty( $inner ) || ! empty( $tokens[ $call['ptr'] ]['nested_parenthesis'] ) ) {
				continue;
			}

			// A test method with parameters overrides a parent's data-driven test to opt out of it.
			if ( ! empty( $phpcs_file->getMethodParameters( $function_ptr ) ) ) {
				continue;
			}

			// Only flag the first statement of the method body.
			$first = $phpcs_file->findNext( Tokens::$emptyTokens, $opener + 1, $closer, true );
			$start = $phpcs_file->findStartOfStatement( $call['ptr'] );
			if ( $first !== $start ) {
				continue;
			}

			$end = $phpcs_file->findEndOfStatement( $call['ptr'] );
			if ( false !== $phpcs_file->findNext( self::CONDITIONAL_OPERATORS, $start, $end ) ) {
				continue;
			}

			$phpcs_file->addWarning(
				'Unconditional %s() means this test never runs. Fix or delete the test, or skip only behind a checked condition.',
				$call['ptr'],
				'Unconditional',
				array( $tokens[ $call['ptr'] ]['content'] )
			);
		}
	}
}
