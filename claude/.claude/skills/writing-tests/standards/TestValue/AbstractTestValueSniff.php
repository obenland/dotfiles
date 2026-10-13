<?php
/**
 * Shared helpers for the TestValue sniffs.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Base class for the TestValue sniffs.
 *
 * TestValue is an agent-only standard that flags low-value PHPUnit tests. Invoke
 * it by path: phpcs --standard=<skill>/standards/TestValue --warning-severity=1.
 * This file lives outside Sniffs/ so PHPCS does not register it as a sniff;
 * the standard's ruleset.xml autoloads it.
 */
abstract class AbstractTestValueSniff implements Sniff {

	/**
	 * Method-name prefixes on $this/self/static that count as an assertion, on top
	 * of assert*() (see is_assert_call()). Custom helpers named verify*()/check*()
	 * are treated as asserting to avoid false positives.
	 */
	protected const HELPER_ASSERT_PREFIXES = array( 'verify', 'check' );

	/**
	 * PHPUnit / Mockery calls that set up an expectation PHPUnit will verify.
	 */
	protected const EXPECT_METHODS = array( 'fail', 'addtoassertioncount' );

	/**
	 * Mock expectation calls (on any receiver).
	 */
	protected const MOCK_EXPECTATION_METHODS = array( 'expects', 'shouldreceive', 'shouldhavereceived', 'shouldnotreceive', 'shouldnothavereceived' );

	/**
	 * Global Codeception Verify / Specify helpers that assert, e.g. expect( $x )->equals( 1 ).
	 */
	protected const VERIFY_FUNCTIONS = array( 'expect', 'verify', 'expect_that', 'expect_not', 'verify_that', 'verify_not' );

	/**
	 * Parent classes that add no assertion helpers of their own: a $this->foo() call
	 * to a method the test class does not define cannot hide an assertion there.
	 */
	protected const PLAIN_TEST_BASES = array( 'testcase', 'phpunit\\framework\\testcase', 'wp_unittestcase' );

	/**
	 * PHPUnit TestCase methods (lowercased) that never assert, so calling them from a
	 * test does not make the test's verification unknown.
	 */
	protected const NON_ASSERTING_TESTCASE_METHODS = array(
		'createmock',
		'createstub',
		'createpartialmock',
		'createconfiguredmock',
		'createtestproxy',
		'getmockbuilder',
		'getmockforabstractclass',
		'getmockfortrait',
		'getmockfromwsdl',
		'prophesize',
		'any',
		'once',
		'never',
		'exactly',
		'atleast',
		'atleastonce',
		'atmost',
		'returnvalue',
		'returnvaluemap',
		'returncallback',
		'returnself',
		'returnargument',
		'onconsecutivecalls',
		'throwexception',
		'setup',
		'teardown',
		'getname',
		'name',
		'datasetname',
		'iniset',
		'setlocale',
		'factory',
		'go_to',
		'set_permalink_structure',
	);

	/**
	 * Per-file cache of same-class helper methods that assert, keyed by file path then class token.
	 *
	 * @var array<string, array<int, array<string, true>>>
	 */
	private array $asserting_helpers = array();

	/**
	 * Per-file cache of a class's methods (lowercased name => calls in its body),
	 * keyed by file path then class token.
	 *
	 * @var array<string, array<int, array<string, array[]>>>
	 */
	private array $class_methods = array();

	/**
	 * Default: listen for function declarations. Subclasses that only care about
	 * test methods implement process_test_method().
	 *
	 * @return array
	 */
	public function register(): array {
		return array( \T_FUNCTION );
	}

	/**
	 * Dispatches to process_test_method() for test methods in test classes.
	 *
	 * The $stack_ptr parameter stays untyped in every process(): PHPCS's Sniff
	 * interface declares it without a type, and an implementation may not narrow it.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $stack_ptr  The T_FUNCTION token.
	 *
	 * @return int|null A token to skip to, or null to continue.
	 */
	public function process( File $phpcs_file, $stack_ptr ): ?int {
		if ( ! $this->is_test_method( $phpcs_file, $stack_ptr ) ) {
			return null;
		}

		$tokens = $phpcs_file->getTokens();
		$this->process_test_method( $phpcs_file, $stack_ptr, $tokens[ $stack_ptr ]['scope_opener'], $tokens[ $stack_ptr ]['scope_closer'] );
		return null;
	}

	/**
	 * Processes the body of a test method.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $function_ptr   The T_FUNCTION token.
	 * @param int  $opener     The method body opening brace.
	 * @param int  $closer     The method body closing brace.
	 *
	 * @return void
	 */
	protected function process_test_method( File $phpcs_file, int $function_ptr, int $opener, int $closer ): void {
	}

	/**
	 * Whether the function token is a method with a body inside a test class.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $stack_ptr  The T_FUNCTION token.
	 *
	 * @return bool
	 */
	protected function is_method_in_test_class( File $phpcs_file, int $stack_ptr ): bool {
		$tokens = $phpcs_file->getTokens();
		if ( \T_FUNCTION !== $tokens[ $stack_ptr ]['code'] || ! isset( $tokens[ $stack_ptr ]['scope_opener'], $tokens[ $stack_ptr ]['scope_closer'] ) ) {
			return false;
		}

		$conditions = $tokens[ $stack_ptr ]['conditions'];
		if ( empty( $conditions ) ) {
			return false;
		}

		// The direct parent scope must be a class.
		$class_ptr = array_key_last( $conditions );
		if ( \T_CLASS !== $conditions[ $class_ptr ] ) {
			return false;
		}

		return $this->is_test_class( $phpcs_file, $class_ptr );
	}

	/**
	 * Lenient test-class detection: the name ends in Test/Tests/TestCase or starts
	 * with Test_/Tests_/WC_Tests_, or it extends something ending in
	 * Test/Tests/TestCase/Test_Case/TestBase/Test_Base.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $class_ptr  The T_CLASS token.
	 *
	 * @return bool
	 */
	protected function is_test_class( File $phpcs_file, int $class_ptr ): bool {
		$name = (string) $phpcs_file->getDeclarationName( $class_ptr );
		if ( preg_match( '/(Test|Tests|Test_?Case)$/i', $name ) || preg_match( '/^(WC_)?Tests?_/i', $name ) ) {
			return true;
		}

		$extends = $phpcs_file->findExtendedClassName( $class_ptr );
		return is_string( $extends ) && (bool) preg_match( '/(Test|Tests|Test_?Case|Test_?Base)$/i', $extends );
	}

	/**
	 * Whether the function token is a test method: named test*, or tagged
	 * with an @test docblock tag or a #[Test] attribute.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $stack_ptr  The T_FUNCTION token.
	 *
	 * @return bool
	 */
	protected function is_test_method( File $phpcs_file, int $stack_ptr ): bool {
		if ( ! $this->is_method_in_test_class( $phpcs_file, $stack_ptr ) ) {
			return false;
		}

		// PHPUnit only runs public methods; a private test_*() is a helper.
		$properties = $phpcs_file->getMethodProperties( $stack_ptr );
		if ( 'public' !== $properties['scope'] ) {
			return false;
		}

		$name = (string) $phpcs_file->getDeclarationName( $stack_ptr );
		if ( 0 === stripos( $name, 'test' ) ) {
			return true;
		}

		$preamble = $this->get_method_preamble( $phpcs_file, $stack_ptr );
		return in_array( '@test', $preamble['tags'], true ) || in_array( 'test', $preamble['attributes'], true );
	}

	/**
	 * Collects docblock tags (lowercased, e.g. "@test") and attribute class names
	 * (lowercased short names, e.g. "test") preceding a method declaration.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $stack_ptr  The T_FUNCTION token.
	 *
	 * @return array{tags: string[], attributes: string[]}
	 */
	protected function get_method_preamble( File $phpcs_file, int $stack_ptr ): array {
		$tokens = $phpcs_file->getTokens();
		$result = array(
			'tags'       => array(),
			'attributes' => array(),
		);

		// phpcs:ignore comments between the docblock/attributes and the method are skipped too.
		$skip = Tokens::$methodPrefixes + Tokens::$phpcsCommentTokens + array(
			\T_WHITESPACE => \T_WHITESPACE,
			\T_COMMENT    => \T_COMMENT,
		);

		for ( $i = $stack_ptr - 1; $i >= 0; $i-- ) {
			$code = $tokens[ $i ]['code'];
			if ( isset( $skip[ $code ] ) ) {
				continue;
			}

			if ( \T_ATTRIBUTE_END === $code && isset( $tokens[ $i ]['attribute_opener'] ) ) {
				$attribute_opener = $tokens[ $i ]['attribute_opener'];
				for ( $j = $attribute_opener; $j < $i; $j++ ) {
					// Attribute names are the last name token before "(" , "," or "]".
					if ( ! $this->is_name_token( $tokens[ $j ]['code'] ) ) {
						continue;
					}
					$next = $phpcs_file->findNext( Tokens::$emptyTokens, $j + 1, null, true );
					if ( false !== $next && in_array( $tokens[ $next ]['code'], array( \T_OPEN_PARENTHESIS, \T_COMMA, \T_ATTRIBUTE_END ), true ) ) {
						$result['attributes'][] = strtolower( $this->short_name( $tokens[ $j ]['content'] ) );
					}
				}
				$i = $attribute_opener;
				continue;
			}

			if ( \T_DOC_COMMENT_CLOSE_TAG === $code && isset( $tokens[ $i ]['comment_opener'] ) ) {
				for ( $j = $tokens[ $i ]['comment_opener']; $j < $i; $j++ ) {
					if ( \T_DOC_COMMENT_TAG === $tokens[ $j ]['code'] ) {
						$result['tags'][] = strtolower( $tokens[ $j ]['content'] );
					}
				}
				$i = $tokens[ $i ]['comment_opener'];
				continue;
			}

			break;
		}

		return $result;
	}

	/**
	 * Whether a token is a name: T_STRING, or a PHPCS 4 namespaced-name token
	 * (\Foo\bar, Foo\bar, namespace\bar). PHPCS 3 splits those into T_STRING and
	 * T_NS_SEPARATOR, PHPCS 4 keeps them as one token.
	 *
	 * @param int|string $code Token code.
	 *
	 * @return bool
	 */
	protected function is_name_token( int|string $code ): bool {
		return \T_STRING === $code
			|| ( defined( 'T_NAME_FULLY_QUALIFIED' ) && \T_NAME_FULLY_QUALIFIED === $code )
			|| ( defined( 'T_NAME_QUALIFIED' ) && \T_NAME_QUALIFIED === $code )
			|| ( defined( 'T_NAME_RELATIVE' ) && \T_NAME_RELATIVE === $code );
	}

	/**
	 * The unqualified part of a name token's content: \Ns\Foo => Foo.
	 *
	 * @param string $content Token content.
	 *
	 * @return string
	 */
	protected function short_name( string $content ): string {
		$pos = strrpos( $content, '\\' );
		return false === $pos ? $content : substr( $content, $pos + 1 );
	}

	/**
	 * Finds all calls in a token range.
	 *
	 * Each call is an array with:
	 *  - name:     lowercased function/method name.
	 *  - ptr:      the name token.
	 *  - kind:     'method' (-> or ?->), 'static' (::), 'function' or 'new'.
	 *  - receiver: lowercased receiver token content for method/static calls
	 *              ("$this", "self", "static", "assert", ...), '' otherwise.
	 *  - open/close: the parenthesis tokens.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $start      First token (exclusive).
	 * @param int  $end        Last token (exclusive).
	 *
	 * @return array[]
	 */
	protected function find_calls( File $phpcs_file, int $start, int $end ): array {
		$tokens = $phpcs_file->getTokens();
		$calls  = array();

		for ( $i = $start + 1; $i < $end; $i++ ) {
			if ( ! $this->is_name_token( $tokens[ $i ]['code'] ) ) {
				continue;
			}

			$open = $phpcs_file->findNext( Tokens::$emptyTokens, $i + 1, null, true );
			if ( false === $open || \T_OPEN_PARENTHESIS !== $tokens[ $open ]['code'] || ! isset( $tokens[ $open ]['parenthesis_closer'] ) ) {
				continue;
			}

			$prev = $phpcs_file->findPrevious( Tokens::$emptyTokens, $i - 1, null, true );
			// PHPCS 3 splits qualified names into parts; step back over the namespace.
			while ( false !== $prev && \T_NS_SEPARATOR === $tokens[ $prev ]['code'] ) {
				$prev = $phpcs_file->findPrevious( Tokens::$emptyTokens, $prev - 1, null, true );
				if ( false !== $prev && \T_STRING === $tokens[ $prev ]['code'] ) {
					$prev = $phpcs_file->findPrevious( Tokens::$emptyTokens, $prev - 1, null, true );
				}
			}

			$kind     = 'function';
			$receiver = '';
			if ( false !== $prev ) {
				$prev_code = $tokens[ $prev ]['code'];
				if ( \T_FUNCTION === $prev_code || \T_CONST === $prev_code ) {
					continue;
				}
				if ( \T_NEW === $prev_code ) {
					$kind = 'new';
				} elseif ( \T_OBJECT_OPERATOR === $prev_code || \T_NULLSAFE_OBJECT_OPERATOR === $prev_code || \T_DOUBLE_COLON === $prev_code ) {
					$kind         = \T_DOUBLE_COLON === $prev_code ? 'static' : 'method';
					$receiver_ptr = $phpcs_file->findPrevious( Tokens::$emptyTokens, $prev - 1, null, true );
					if ( false !== $receiver_ptr ) {
						$receiver = strtolower( $this->short_name( $tokens[ $receiver_ptr ]['content'] ) );
					}
				}
			}

			$calls[] = array(
				'name'     => strtolower( $this->short_name( $tokens[ $i ]['content'] ) ),
				'ptr'      => $i,
				'kind'     => $kind,
				'receiver' => $receiver,
				'open'     => $open,
				'close'    => $tokens[ $open ]['parenthesis_closer'],
			);
		}

		return $calls;
	}

	/**
	 * Whether a call is a PHPUnit assertion: assert*() on any receiver ($this,
	 * self/static/a class, or a helper object such as $this->helper or $response),
	 * or a global assert*() function from PHPUnit's functions.php.
	 *
	 * @param array $call A call from find_calls().
	 *
	 * @return bool
	 */
	protected function is_assert_call( array $call ): bool {
		if ( 0 !== strpos( $call['name'], 'assert' ) ) {
			return false;
		}

		if ( 'static' === $call['kind'] || 'method' === $call['kind'] ) {
			return true;
		}

		// Global assert*() helpers, but not PHP's own assert().
		return 'function' === $call['kind'] && 'assert' !== $call['name'];
	}

	/**
	 * Whether a call is on the test case itself ($this->, self::, static::).
	 *
	 * @param array $call A call from find_calls().
	 *
	 * @return bool
	 */
	protected function is_self_call( array $call ): bool {
		return ( 'method' === $call['kind'] && '$this' === $call['receiver'] )
			|| ( 'static' === $call['kind'] && in_array( $call['receiver'], array( 'self', 'static', 'parent' ), true ) );
	}

	/**
	 * Whether a call is an expectation PHPUnit verifies on its own: expect*()
	 * (expectException, expectOutputString, ...), fail(), or a custom
	 * verify*()/check*() helper on the test case. Custom assert*() helpers are
	 * covered by is_assert_call().
	 *
	 * @param array $call A call from find_calls().
	 *
	 * @return bool
	 */
	protected function is_expectation_call( array $call ): bool {
		if ( ! $this->is_self_call( $call ) ) {
			return false;
		}

		if ( 0 === strpos( $call['name'], 'expect' ) || in_array( $call['name'], self::EXPECT_METHODS, true ) ) {
			return true;
		}

		foreach ( self::HELPER_ASSERT_PREFIXES as $prefix ) {
			if ( 0 === strpos( $call['name'], $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a call sets a mock expectation (->expects(), Mockery shouldReceive()).
	 *
	 * @param array $call A call from find_calls().
	 *
	 * @return bool
	 */
	protected function is_mock_expectation_call( array $call ): bool {
		if ( 'method' !== $call['kind'] ) {
			return false;
		}

		if ( in_array( $call['name'], self::MOCK_EXPECTATION_METHODS, true ) ) {
			return true;
		}

		// In-house mock helpers such as $this->mock_functions->expect() or $this->mock_http->expect_post().
		return '$this' !== $call['receiver'] && ( 'expect' === $call['name'] || 0 === strpos( $call['name'], 'expect_' ) );
	}

	/**
	 * Whether a call asserts indirectly: a Codeception expect()/verify() helper, a
	 * $this->*assert*() helper inherited from elsewhere, or a call to a method of the
	 * same class whose body asserts (followed transitively).
	 *
	 * @param File  $phpcs_file The file being scanned.
	 * @param array $call       A call from find_calls().
	 *
	 * @return bool
	 */
	protected function is_indirect_assertion( File $phpcs_file, array $call ): bool {
		if ( 'function' === $call['kind'] && in_array( $call['name'], self::VERIFY_FUNCTIONS, true ) ) {
			return true;
		}

		if ( ! $this->is_self_call( $call ) ) {
			return false;
		}

		if ( $this->is_assert_helper_name( $call['name'] ) ) {
			return true;
		}

		$class_ptr = $this->get_call_class( $phpcs_file, $call );
		if ( null === $class_ptr ) {
			return false;
		}

		$helpers = $this->get_asserting_helpers( $phpcs_file, $class_ptr );
		return isset( $helpers[ $call['name'] ] );
	}

	/**
	 * Whether a call goes to code this file cannot see that may assert: parent::*(),
	 * or $this->foo() / self::foo() for a method the class does not define while the
	 * class extends a project base class (not a plain PHPUnit/WP test case).
	 *
	 * @param File  $phpcs_file The file being scanned.
	 * @param array $call       A call from find_calls().
	 *
	 * @return bool
	 */
	protected function is_unresolved_inherited_call( File $phpcs_file, array $call ): bool {
		if ( 'static' === $call['kind'] && 'parent' === $call['receiver'] ) {
			return true;
		}

		if ( ! $this->is_self_call( $call ) || in_array( $call['name'], self::NON_ASSERTING_TESTCASE_METHODS, true ) ) {
			return false;
		}

		$class_ptr = $this->get_call_class( $phpcs_file, $call );
		if ( null === $class_ptr ) {
			return false;
		}

		$extends = $phpcs_file->findExtendedClassName( $class_ptr );
		if ( ! is_string( $extends ) || in_array( strtolower( ltrim( $extends, '\\' ) ), self::PLAIN_TEST_BASES, true ) ) {
			return false;
		}

		return ! isset( $this->get_class_methods( $phpcs_file, $class_ptr )[ $call['name'] ] );
	}

	/**
	 * The outermost class token enclosing a call, or null.
	 *
	 * @param File  $phpcs_file The file being scanned.
	 * @param array $call       A call from find_calls().
	 *
	 * @return int|null
	 */
	private function get_call_class( File $phpcs_file, array $call ): ?int {
		$tokens    = $phpcs_file->getTokens();
		$class_ptr = null;
		foreach ( $tokens[ $call['ptr'] ]['conditions'] as $ptr => $code ) {
			if ( \T_CLASS === $code ) {
				$class_ptr = $ptr;
			}
		}
		return $class_ptr;
	}

	/**
	 * The class's own methods: lowercased name => calls in the method body.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $class_ptr  The T_CLASS token.
	 *
	 * @return array<string, array[]>
	 */
	private function get_class_methods( File $phpcs_file, int $class_ptr ): array {
		$file = $phpcs_file->getFilename();
		if ( isset( $this->class_methods[ $file ][ $class_ptr ] ) ) {
			return $this->class_methods[ $file ][ $class_ptr ];
		}

		// Keep only the current file's entries.
		$this->class_methods = array( $file => $this->class_methods[ $file ] ?? array() );

		$tokens  = $phpcs_file->getTokens();
		$methods = array();
		for ( $i = $tokens[ $class_ptr ]['scope_opener'] + 1; $i < $tokens[ $class_ptr ]['scope_closer']; $i++ ) {
			if ( \T_FUNCTION !== $tokens[ $i ]['code'] || ! isset( $tokens[ $i ]['scope_opener'] ) ) {
				continue;
			}
			if ( array_key_last( $tokens[ $i ]['conditions'] ) === $class_ptr ) {
				$methods[ strtolower( (string) $phpcs_file->getDeclarationName( $i ) ) ] = $this->find_calls( $phpcs_file, $tokens[ $i ]['scope_opener'], $tokens[ $i ]['scope_closer'] );
			}
			$i = $tokens[ $i ]['scope_closer'];
		}

		$this->class_methods[ $file ][ $class_ptr ] = $methods;
		return $methods;
	}

	/**
	 * Whether a method name looks like an assertion helper that does not itself start
	 * with assert (assert*() calls are handled by is_assert_call()), e.g.
	 * run_dispatch_assertions().
	 *
	 * @param string $name Lowercased method name.
	 *
	 * @return bool
	 */
	private function is_assert_helper_name( string $name ): bool {
		return 0 !== strpos( $name, 'assert' ) && false !== strpos( $name, 'assert' );
	}

	/**
	 * Names (lowercased) of the class's methods whose bodies assert, directly or by
	 * calling another such method of the class.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $class_ptr  The T_CLASS token.
	 *
	 * @return array<string, true>
	 */
	private function get_asserting_helpers( File $phpcs_file, int $class_ptr ): array {
		$file = $phpcs_file->getFilename();
		if ( isset( $this->asserting_helpers[ $file ][ $class_ptr ] ) ) {
			return $this->asserting_helpers[ $file ][ $class_ptr ];
		}

		// Keep only the current file's entries.
		$this->asserting_helpers = array( $file => $this->asserting_helpers[ $file ] ?? array() );

		$methods = $this->get_class_methods( $phpcs_file, $class_ptr );

		$asserting = array();
		do {
			$changed = false;
			foreach ( $methods as $name => $calls ) {
				if ( isset( $asserting[ $name ] ) ) {
					continue;
				}
				foreach ( $calls as $call ) {
					$direct = ( $this->is_assert_call( $call ) && ! $this->is_trivial_assertion( $phpcs_file, $call ) )
						|| $this->is_expectation_call( $call )
						|| $this->is_mock_expectation_call( $call )
						|| ( 'function' === $call['kind'] && in_array( $call['name'], self::VERIFY_FUNCTIONS, true ) );
					$via    = $this->is_self_call( $call ) && ( isset( $asserting[ $call['name'] ] ) || $this->is_assert_helper_name( $call['name'] ) );
					if ( $direct || $via ) {
						$asserting[ $name ] = true;
						$changed            = true;
						break;
					}
				}
			}
		} while ( $changed );

		$this->asserting_helpers[ $file ][ $class_ptr ] = $asserting;
		return $asserting;
	}

	/**
	 * Whether an assertion only checks a literal against itself, e.g. assertTrue( true ).
	 *
	 * @param File  $phpcs_file The file being scanned.
	 * @param array $call       A call from find_calls().
	 *
	 * @return bool
	 */
	protected function is_trivial_assertion( File $phpcs_file, array $call ): bool {
		$trivial = array(
			'asserttrue'  => \T_TRUE,
			'assertfalse' => \T_FALSE,
			'assertnull'  => \T_NULL,
		);
		if ( ! isset( $trivial[ $call['name'] ] ) ) {
			return false;
		}

		$args = $this->get_call_arguments( $phpcs_file, $call );
		if ( empty( $args ) || 1 !== count( $args[0] ) ) {
			return false;
		}

		$tokens = $phpcs_file->getTokens();
		return $trivial[ $call['name'] ] === $tokens[ $args[0][0] ]['code'];
	}

	/**
	 * Splits a call's arguments at top-level commas.
	 *
	 * @param File  $phpcs_file The file being scanned.
	 * @param array $call       A call from find_calls().
	 *
	 * @return int[][] One list of non-empty token pointers per argument.
	 */
	protected function get_call_arguments( File $phpcs_file, array $call ): array {
		$tokens  = $phpcs_file->getTokens();
		$args    = array();
		$current = array();

		for ( $i = $call['open'] + 1; $i < $call['close']; $i++ ) {
			$code = $tokens[ $i ]['code'];
			if ( isset( Tokens::$emptyTokens[ $code ] ) ) {
				continue;
			}

			// Jump over nested brackets, keeping their tokens in the current argument.
			$nested_closer = null;
			if ( isset( $tokens[ $i ]['parenthesis_closer'] ) && \T_OPEN_PARENTHESIS === $code ) {
				$nested_closer = $tokens[ $i ]['parenthesis_closer'];
			} elseif ( \T_OPEN_SHORT_ARRAY === $code && isset( $tokens[ $i ]['bracket_closer'] ) ) {
				$nested_closer = $tokens[ $i ]['bracket_closer'];
			} elseif ( \T_OPEN_SQUARE_BRACKET === $code && isset( $tokens[ $i ]['bracket_closer'] ) ) {
				$nested_closer = $tokens[ $i ]['bracket_closer'];
			} elseif ( \T_OPEN_CURLY_BRACKET === $code && isset( $tokens[ $i ]['bracket_closer'] ) ) {
				$nested_closer = $tokens[ $i ]['bracket_closer'];
			}

			if ( null !== $nested_closer ) {
				for ( $j = $i; $j <= $nested_closer; $j++ ) {
					if ( ! isset( Tokens::$emptyTokens[ $tokens[ $j ]['code'] ] ) ) {
						$current[] = $j;
					}
				}
				$i = $nested_closer;
				continue;
			}

			if ( \T_COMMA === $code ) {
				$args[]  = $current;
				$current = array();
				continue;
			}

			$current[] = $i;
		}

		if ( ! empty( $current ) ) {
			$args[] = $current;
		}

		return $args;
	}

	/**
	 * Concatenates token contents for a list of token pointers.
	 *
	 * @param File  $phpcs_file The file being scanned.
	 * @param int[] $ptrs       Token pointers.
	 *
	 * @return string
	 */
	protected function tokens_to_string( File $phpcs_file, array $ptrs ): string {
		$tokens = $phpcs_file->getTokens();
		$out    = '';
		foreach ( $ptrs as $ptr ) {
			$out .= $tokens[ $ptr ]['content'];
		}
		return $out;
	}
}
