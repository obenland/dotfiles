<?php
/**
 * TestValue.PHPUnit.ConstantRestatement sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Sniffs\PHPUnit;

use PHP_CodeSniffer\Files\File;
use TestValue\AbstractTestValueSniff;

/**
 * Flags assertions that a class constant equals a literal.
 */
class ConstantRestatementSniff extends AbstractTestValueSniff {

	/**
	 * Assertions that compare two values for equality.
	 */
	private const EQUALITY_ASSERTIONS = array( 'assertsame', 'assertequals', 'assertidentical' );

	/**
	 * Tokens that make up a scalar literal.
	 */
	private const SCALAR_TOKENS = array(
		\T_CONSTANT_ENCAPSED_STRING,
		\T_LNUMBER,
		\T_DNUMBER,
		\T_TRUE,
		\T_FALSE,
		\T_NULL,
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
		foreach ( $this->find_calls( $phpcs_file, $opener, $closer ) as $call ) {
			if ( ! $this->is_assert_call( $call ) || ! in_array( $call['name'], self::EQUALITY_ASSERTIONS, true ) ) {
				continue;
			}

			$args = $this->get_call_arguments( $phpcs_file, $call );
			if ( count( $args ) < 2 ) {
				continue;
			}

			if ( ( $this->is_literal( $phpcs_file, $args[0] ) && $this->is_class_constant( $phpcs_file, $args[1] ) )
				|| ( $this->is_literal( $phpcs_file, $args[1] ) && $this->is_class_constant( $phpcs_file, $args[0] ) )
			) {
				$phpcs_file->addWarning(
					"Test asserts a constant equals a literal; this restates source and can't catch a regression. Delete it or test behavior that depends on the constant.",
					$call['ptr'],
					'Found'
				);
			}
		}
	}

	/**
	 * Whether the argument is a scalar literal or an array literal of only scalars.
	 *
	 * @param File  $phpcs_file The file being scanned.
	 * @param int[] $ptrs       Argument tokens.
	 *
	 * @return bool
	 */
	private function is_literal( File $phpcs_file, array $ptrs ): bool {
		$tokens = $phpcs_file->getTokens();
		if ( empty( $ptrs ) ) {
			return false;
		}

		$first = $tokens[ $ptrs[0] ]['code'];
		if ( \T_OPEN_SHORT_ARRAY === $first || \T_ARRAY === $first ) {
			$allowed = array_merge( self::SCALAR_TOKENS, array( \T_OPEN_SHORT_ARRAY, \T_CLOSE_SHORT_ARRAY, \T_ARRAY, \T_OPEN_PARENTHESIS, \T_CLOSE_PARENTHESIS, \T_COMMA, \T_DOUBLE_ARROW, \T_MINUS ) );
			foreach ( $ptrs as $ptr ) {
				if ( ! in_array( $tokens[ $ptr ]['code'], $allowed, true ) ) {
					return false;
				}
			}
			return true;
		}

		if ( \T_MINUS === $first ) {
			array_shift( $ptrs );
		}

		return 1 === count( $ptrs ) && in_array( $tokens[ $ptrs[0] ]['code'], self::SCALAR_TOKENS, true );
	}

	/**
	 * Whether the argument is exactly a class constant fetch (Foo::BAR, \Ns\Foo::BAR,
	 * self::BAR, static::BAR), not Foo::class.
	 *
	 * @param File  $phpcs_file The file being scanned.
	 * @param int[] $ptrs       Argument tokens.
	 *
	 * @return bool
	 */
	private function is_class_constant( File $phpcs_file, array $ptrs ): bool {
		$tokens = $phpcs_file->getTokens();
		$count  = count( $ptrs );
		if ( $count < 3 ) {
			return false;
		}

		$constant = $tokens[ $ptrs[ $count - 1 ] ];
		if ( \T_STRING !== $constant['code'] || 'class' === strtolower( $constant['content'] ) ) {
			return false;
		}

		if ( \T_DOUBLE_COLON !== $tokens[ $ptrs[ $count - 2 ] ]['code'] ) {
			return false;
		}

		for ( $i = 0; $i < $count - 2; $i++ ) {
			$code = $tokens[ $ptrs[ $i ] ]['code'];
			if ( ! $this->is_name_token( $code ) && ! in_array( $code, array( \T_NS_SEPARATOR, \T_SELF, \T_STATIC, \T_PARENT ), true ) ) {
				return false;
			}
		}

		return true;
	}
}
