<?php
/**
 * TestValue.PHPUnit.SourceTextAssertion sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Sniffs\PHPUnit;

use PHP_CodeSniffer\Files\File;
use TestValue\AbstractTestValueSniff;

/**
 * Flags tests that read production source files as text (file_get_contents on source paths).
 */
class SourceTextAssertionSniff extends AbstractTestValueSniff {

	/**
	 * Path constants that point into WordPress source.
	 */
	private const SOURCE_CONSTANTS = array( 'ABSPATH', 'WP_CONTENT_DIR', 'WPMU_PLUGIN_DIR' );

	/**
	 * Path fragments that point into production source.
	 */
	private const SOURCE_PATH_FRAGMENTS = array( 'wp-content/', '/../' );

	/**
	 * Path fragments that mark a test fixture or data file.
	 */
	private const FIXTURE_FRAGMENTS = array( 'fixture', 'data/', '.json', '.txt', '.html', '.xml', '.csv', '.jsonl' );

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
			if ( 'function' !== $call['kind'] || 'file_get_contents' !== $call['name'] ) {
				continue;
			}

			$args = $this->get_call_arguments( $phpcs_file, $call );
			if ( empty( $args ) ) {
				continue;
			}

			$expression = $this->tokens_to_string( $phpcs_file, $args[0] );
			$lower      = strtolower( $expression );

			foreach ( self::FIXTURE_FRAGMENTS as $fragment ) {
				if ( false !== strpos( $lower, $fragment ) ) {
					continue 2;
				}
			}

			$is_source = false;
			foreach ( $args[0] as $ptr ) {
				if ( $this->is_name_token( $tokens[ $ptr ]['code'] ) && in_array( $this->short_name( $tokens[ $ptr ]['content'] ), self::SOURCE_CONSTANTS, true ) ) {
					$is_source = true;
					break;
				}
			}

			if ( ! $is_source ) {
				foreach ( self::SOURCE_PATH_FRAGMENTS as $fragment ) {
					if ( false !== strpos( $lower, $fragment ) ) {
						$is_source = true;
						break;
					}
				}
			}

			// Only PHP source counts; a served asset (.ps1, .css) or data file compared to output is behavior.
			if ( ! $is_source || false === strpos( $lower, '.php' ) ) {
				continue;
			}

			$phpcs_file->addWarning(
				'Test reads a source file as text; asserting on source text breaks on refactors and misses runtime bugs. Execute the code and assert on its behavior.',
				$call['ptr'],
				'Found'
			);
		}
	}
}
