<?php
/**
 * TestValue.PHPUnit.NearDuplicateMethods sniff.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Sniffs\PHPUnit;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;
use TestValue\AbstractTestValueSniff;

/**
 * Flags test methods in the same class whose bodies differ only in literals.
 */
class NearDuplicateMethodsSniff extends AbstractTestValueSniff {

	/**
	 * Minimum statements (semicolons) a body needs before it is compared.
	 *
	 * @var int
	 */
	public int $min_statements = 3;

	/**
	 * Literal tokens and the placeholder each is normalized to.
	 */
	private const LITERAL_TOKENS = array(
		\T_CONSTANT_ENCAPSED_STRING => 'STR',
		\T_DOUBLE_QUOTED_STRING     => 'STR',
		\T_ENCAPSED_AND_WHITESPACE  => 'STR',
		\T_HEREDOC                  => 'STR',
		\T_NOWDOC                   => 'STR',
		\T_START_HEREDOC            => 'STR',
		\T_END_HEREDOC              => 'STR',
		\T_START_NOWDOC             => 'STR',
		\T_END_NOWDOC               => 'STR',
		\T_LNUMBER                  => 'NUM',
		\T_DNUMBER                  => 'NUM',
	);

	/**
	 * Runs once per file.
	 *
	 * @return array
	 */
	public function register(): array {
		return array( \T_OPEN_TAG );
	}

	/**
	 * Compares all test methods in the file.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $stack_ptr  The T_OPEN_TAG token.
	 *
	 * @return int
	 */
	public function process( File $phpcs_file, $stack_ptr ): int {
		$tokens = $phpcs_file->getTokens();
		$seen   = array();

		for ( $function_ptr = $phpcs_file->findNext( \T_FUNCTION, 0 ); false !== $function_ptr; $function_ptr = $phpcs_file->findNext( \T_FUNCTION, $function_ptr + 1 ) ) {
			if ( ! $this->is_test_method( $phpcs_file, $function_ptr ) ) {
				continue;
			}

			$shape = $this->get_body_shape( $phpcs_file, $tokens[ $function_ptr ]['scope_opener'], $tokens[ $function_ptr ]['scope_closer'] );
			if ( null === $shape ) {
				continue;
			}

			$class_ptr = array_key_last( $tokens[ $function_ptr ]['conditions'] );
			$key       = $class_ptr . ':' . $shape;
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = $function_ptr;
				continue;
			}

			$original = $seen[ $key ];
			$phpcs_file->addWarning(
				'Same shape as %s() at line %d; merge into one test with a data provider.',
				$function_ptr,
				'Found',
				array( $phpcs_file->getDeclarationName( $original ), $tokens[ $original ]['line'] )
			);
		}

		return $phpcs_file->numTokens;
	}

	/**
	 * Normalized hash of a method body: no whitespace/comments, literals replaced
	 * by placeholders. Returns null for bodies too small to compare.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $opener     The method body opening brace.
	 * @param int  $closer     The method body closing brace.
	 *
	 * @return string|null
	 */
	private function get_body_shape( File $phpcs_file, int $opener, int $closer ): ?string {
		$tokens     = $phpcs_file->getTokens();
		$parts      = array();
		$statements = 0;

		for ( $i = $opener + 1; $i < $closer; $i++ ) {
			$code = $tokens[ $i ]['code'];
			if ( isset( Tokens::$emptyTokens[ $code ] ) || isset( Tokens::$phpcsCommentTokens[ $code ] ) ) {
				continue;
			}

			if ( \T_SEMICOLON === $code ) {
				++$statements;
			}

			$parts[] = self::LITERAL_TOKENS[ $code ] ?? $tokens[ $i ]['content'];
		}

		if ( $statements < (int) $this->min_statements ) {
			return null;
		}

		return md5( implode( ' ', $parts ) );
	}
}
