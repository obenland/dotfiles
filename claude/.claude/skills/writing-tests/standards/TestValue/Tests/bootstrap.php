<?php
/**
 * PHPUnit bootstrap for the TestValue sniff tests.
 *
 * Loads PHP_CodeSniffer from PHPCS_DIR, or from the global Composer install.
 * Run: PHPCS_DIR=<path to squizlabs/php_codesniffer> phpunit -c standards/phpunit.xml.dist
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions -- Standalone test bootstrap, WordPress is not loaded.

if ( ! defined( 'PHP_CODESNIFFER_CBF' ) ) {
	define( 'PHP_CODESNIFFER_CBF', false );
}
if ( ! defined( 'PHP_CODESNIFFER_VERBOSITY' ) ) {
	define( 'PHP_CODESNIFFER_VERBOSITY', 0 );
}

/**
 * Candidate PHP_CodeSniffer install directories, most specific first.
 *
 * @return string[]
 */
function testvalue_phpcs_dirs(): array {
	// An explicit PHPCS_DIR must not silently fall back to another install.
	if ( getenv( 'PHPCS_DIR' ) ) {
		return array( rtrim( (string) getenv( 'PHPCS_DIR' ), '/' ) );
	}
	$dirs  = array();
	$homes = array_filter(
		array(
			getenv( 'COMPOSER_HOME' ),
			getenv( 'HOME' ) ? getenv( 'HOME' ) . '/.composer' : false,
			getenv( 'HOME' ) ? getenv( 'HOME' ) . '/.config/composer' : false,
		)
	);
	foreach ( $homes as $home ) {
		$dirs[] = $home . '/vendor/squizlabs/php_codesniffer';
	}
	return $dirs;
}

$testvalue_autoload = null;
foreach ( testvalue_phpcs_dirs() as $testvalue_dir ) {
	if ( is_file( $testvalue_dir . '/autoload.php' ) ) {
		$testvalue_autoload = $testvalue_dir . '/autoload.php';
		break;
	}
}
if ( null === $testvalue_autoload ) {
	fwrite( STDERR, "PHP_CodeSniffer not found. Set PHPCS_DIR to a squizlabs/php_codesniffer checkout.\n" );
	exit( 1 );
}

require_once $testvalue_autoload;

// Loading Tokens defines PHPCS's own T_* constants, which the sniffs reference.
class_exists( \PHP_CodeSniffer\Util\Tokens::class );

require_once __DIR__ . '/PHPUnit/TestValueSniffTestCase.php';
