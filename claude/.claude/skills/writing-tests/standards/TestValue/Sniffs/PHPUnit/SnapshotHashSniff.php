<?php
/**
 * TestValue.PHPUnit.SnapshotHash sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Sniffs\PHPUnit;

use PHP_CodeSniffer\Files\File;
use TestValue\AbstractTestValueSniff;

/**
 * Flags assertions on a hash of serialized data (opaque snapshot tests).
 */
class SnapshotHashSniff extends AbstractTestValueSniff {

	/**
	 * Hash functions used to fingerprint a value.
	 */
	private const HASH_FUNCTIONS = array( 'md5', 'sha1', 'crc32', 'hash' );

	/**
	 * Functions that serialize a value to a string.
	 */
	private const SERIALIZERS = array( 'serialize', 'json_encode', 'var_export', 'print_r' );

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
			if ( ! $this->is_assert_call( $call ) ) {
				continue;
			}

			foreach ( $this->find_calls( $phpcs_file, $call['open'], $call['close'] ) as $inner ) {
				if ( 'function' !== $inner['kind'] || ! in_array( $inner['name'], self::HASH_FUNCTIONS, true ) ) {
					continue;
				}

				for ( $i = $inner['open'] + 1; $i < $inner['close']; $i++ ) {
					if ( $this->is_name_token( $tokens[ $i ]['code'] ) && in_array( strtolower( $this->short_name( $tokens[ $i ]['content'] ) ), self::SERIALIZERS, true ) ) {
						$phpcs_file->addWarning(
							'Asserting on a hash of serialized data hides what changed and pins incidental details. Assert on the specific fields that matter.',
							$inner['ptr'],
							'Found'
						);
						break 2;
					}
				}
			}
		}
	}
}
