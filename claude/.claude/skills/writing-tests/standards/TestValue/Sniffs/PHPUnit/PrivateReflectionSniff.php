<?php
/**
 * TestValue.PHPUnit.PrivateReflection sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Sniffs\PHPUnit;

use PHP_CodeSniffer\Files\File;
use TestValue\AbstractTestValueSniff;

/**
 * Flags reflection used to reach private/protected members, in any method of a test class.
 */
class PrivateReflectionSniff extends AbstractTestValueSniff {

	/**
	 * Processes every method of a test class (helpers often wrap reflection),
	 * reporting at most once per method.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $stack_ptr  The T_FUNCTION token.
	 *
	 * @return int|null A token to skip to, or null to continue.
	 */
	public function process( File $phpcs_file, $stack_ptr ): ?int {
		if ( ! $this->is_method_in_test_class( $phpcs_file, $stack_ptr ) ) {
			return null;
		}

		$tokens = $phpcs_file->getTokens();
		$calls  = $this->find_calls( $phpcs_file, $tokens[ $stack_ptr ]['scope_opener'], $tokens[ $stack_ptr ]['scope_closer'] );

		// Flag calling a private method (ReflectionMethod + invoke) and reading private
		// state to assert on it (ReflectionProperty + getValue() inside an assertion).
		// Writing a private property to inject a double or reset a cache is a test seam
		// and is not flagged.
		$found         = null;
		$method_ref    = null;
		$property_ref  = null;
		$invokes       = false;
		$asserted_read = false;
		$assert_ranges = array();
		foreach ( $calls as $call ) {
			if ( $this->is_assert_call( $call ) ) {
				$assert_ranges[] = array( $call['open'], $call['close'] );
			}
		}

		foreach ( $calls as $call ) {
			if ( 'static' === $call['kind'] && 'closure' === $call['receiver'] && 'bind' === $call['name'] ) {
				$found = $call['ptr'];
				break;
			}

			$is_new    = 'new' === $call['kind'];
			$is_method = 'method' === $call['kind'];
			if ( null === $method_ref && ( ( $is_new && 'reflectionmethod' === $call['name'] ) || ( $is_method && 'getmethod' === $call['name'] ) ) ) {
				$method_ref = $call['ptr'];
			}
			if ( null === $property_ref && ( ( $is_new && 'reflectionproperty' === $call['name'] ) || ( $is_method && 'getproperty' === $call['name'] ) ) ) {
				$property_ref = $call['ptr'];
			}
			if ( $is_method && in_array( $call['name'], array( 'invoke', 'invokeargs', 'getclosure' ), true ) ) {
				$invokes = true;
			}
			if ( $is_method && 'getvalue' === $call['name'] ) {
				foreach ( $assert_ranges as $range ) {
					if ( $call['ptr'] > $range[0] && $call['ptr'] < $range[1] ) {
						$asserted_read = true;
						break;
					}
				}
			}
		}

		if ( null === $found && null !== $method_ref && $invokes ) {
			$found = $method_ref;
		}
		if ( null === $found && null !== $property_ref && $asserted_read ) {
			$found = $property_ref;
		}

		if ( null === $found ) {
			return null;
		}

		$phpcs_file->addWarning(
			'Reflection is used to reach private/protected members, coupling the test to implementation details. Test through the public API instead.',
			$found,
			'Found'
		);
		return null;
	}
}
