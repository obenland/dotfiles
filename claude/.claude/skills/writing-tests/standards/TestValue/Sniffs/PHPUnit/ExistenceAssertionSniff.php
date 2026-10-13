<?php
/**
 * TestValue.PHPUnit.ExistenceAssertion sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Sniffs\PHPUnit;

use PHP_CodeSniffer\Files\File;
use TestValue\AbstractTestValueSniff;

/**
 * Flags assertions on class_exists()/method_exists()/function_exists() and friends.
 */
class ExistenceAssertionSniff extends AbstractTestValueSniff {

	/**
	 * Functions that only check whether a symbol exists.
	 */
	private const EXISTENCE_FUNCTIONS = array(
		'class_exists',
		'method_exists',
		'function_exists',
		'interface_exists',
		'trait_exists',
		'enum_exists',
		'property_exists',
		'is_callable',
		'defined',
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
			if ( ! $this->is_assert_call( $call ) ) {
				continue;
			}

			$args = $this->get_call_arguments( $phpcs_file, $call );
			if ( empty( $args ) ) {
				continue;
			}

			// Allow a leading "!" and a leading "\" namespace separator.
			$first = $args[0];
			while ( ! empty( $first ) && in_array( $tokens[ $first[0] ]['code'], array( \T_BOOLEAN_NOT, \T_NS_SEPARATOR ), true ) ) {
				array_shift( $first );
			}

			if ( count( $first ) < 2
				|| ! $this->is_name_token( $tokens[ $first[0] ]['code'] )
				|| \T_OPEN_PARENTHESIS !== $tokens[ $first[1] ]['code']
				|| ! in_array( strtolower( $this->short_name( $tokens[ $first[0] ]['content'] ) ), self::EXISTENCE_FUNCTIONS, true )
			) {
				continue;
			}

			// The existence check must be the whole argument, not part of a larger expression.
			if ( end( $first ) !== $tokens[ $first[1] ]['parenthesis_closer'] ) {
				continue;
			}

			$function_name = strtolower( $this->short_name( $tokens[ $first[0] ]['content'] ) );
			// is_callable( $x ) and property_exists( $object, ... ) inspect a runtime value.
			if ( ! $this->has_static_arguments( $phpcs_file, $first[1], in_array( $function_name, array( 'is_callable', 'property_exists' ), true ) ) ) {
				continue;
			}

			$phpcs_file->addWarning(
				'Asserting that a symbol exists (%s) restates the source; a call would already fatal. Call the code and assert on its behavior, or delete this.',
				$call['ptr'],
				'Found',
				array( $function_name . '()' )
			);
		}
	}

	/**
	 * Whether the existence check only names symbols statically (string literals,
	 * Foo::class, constants, or variables holding an object). A check on a value
	 * computed at runtime, such as is_callable( get_registered_callback() ), tests
	 * behavior and is not flagged.
	 *
	 * @param File $phpcs_file        The file being scanned.
	 * @param int  $open              The existence function's opening parenthesis.
	 * @param bool $reject_variables  Also treat variables as runtime values.
	 *
	 * @return bool
	 */
	private function has_static_arguments( File $phpcs_file, int $open, bool $reject_variables ): bool {
		$tokens = $phpcs_file->getTokens();

		for ( $i = $open + 1; $i < $tokens[ $open ]['parenthesis_closer']; $i++ ) {
			$code = $tokens[ $i ]['code'];
			if ( \T_VARIABLE === $code && $reject_variables ) {
				return false;
			}

			// A property of an object, e.g. property_exists( $response->data, 'id' ), is a runtime value.
			if ( \T_OBJECT_OPERATOR === $code || \T_NULLSAFE_OBJECT_OPERATOR === $code ) {
				return false;
			}

			if ( $this->is_name_token( $code ) ) {
				$next = $phpcs_file->findNext( \PHP_CodeSniffer\Util\Tokens::$emptyTokens, $i + 1, null, true );
				if ( false !== $next && \T_OPEN_PARENTHESIS === $tokens[ $next ]['code'] ) {
					return false;
				}
			}
		}

		return true;
	}
}
