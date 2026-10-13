<?php
/**
 * Shared harness for TestValue sniff unit tests: run one sniff over the
 * -1.inc (bad) and -2.inc (good) fixtures and compare warning counts per line.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

namespace TestValue\Tests\PHPUnit;

use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\LocalFile;
use PHP_CodeSniffer\Ruleset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the per-sniff tests. A FooUnitTest tests Sniffs/PHPUnit/FooSniff.php.
 */
abstract class TestValueSniffTestCase extends TestCase {

	/**
	 * PHPCS config restricted to the sniff under test.
	 *
	 * @var Config
	 */
	protected static Config $config;

	/**
	 * Ruleset built from the config.
	 *
	 * @var Ruleset
	 */
	protected static Ruleset $ruleset;

	/**
	 * Builds a ruleset that loads the TestValue standard but only runs the sniff under test.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		$sniff = preg_replace( '/UnitTest$/', '', ( new \ReflectionClass( static::class ) )->getShortName() );

		// Assigned, not passed as CLI args: PHPCS 3 ignores CLI args a previous Config instance already set.
		static::$config            = new Config( array( '-q' ) );
		static::$config->standards = array( dirname( __DIR__, 2 ) );
		static::$config->sniffs    = array( 'TestValue.PHPUnit.' . $sniff );
		// ExcessiveMocks and the other hints report at severity 3; agents run with --warning-severity=1.
		static::$config->warningSeverity = 1;
		static::$ruleset                 = new Ruleset( static::$config );
	}

	/**
	 * Path of this test's fixture with the given suffix: 1 = bad, 2 = good.
	 *
	 * @param string $suffix Fixture suffix.
	 * @return string
	 */
	public static function fixture( string $suffix ): string {
		$test_file = ( new \ReflectionClass( static::class ) )->getFileName();
		return dirname( $test_file ) . '/' . basename( $test_file, '.php' ) . '-' . $suffix . '.inc';
	}

	/**
	 * Fixture path => expected warnings ( line => count ). Errors are always expected to be empty.
	 *
	 * @return array<string, array{0: string, 1: array<int, int>}>
	 */
	abstract public static function data_fixtures(): array;

	/**
	 * Runs the sniff over a fixture and compares warning counts per line.
	 *
	 * @dataProvider data_fixtures
	 *
	 * @param string          $filepath Fixture path.
	 * @param array<int, int> $warnings Expected warning count per line.
	 * @return void
	 */
	#[DataProvider( 'data_fixtures' )]
	public function test_fixture_warnings( string $filepath, array $warnings ): void {
		$phpcs_file = $this->process_fixture( $filepath );

		$this->assertSame( array(), array_map( 'count', $phpcs_file->getErrors() ), 'TestValue sniffs must only emit warnings.' );
		$this->assertEquals( $warnings, array_map( array( static::class, 'count_messages' ), $phpcs_file->getWarnings() ) );
	}

	/**
	 * Runs the sniff under test over a file.
	 *
	 * @param string $filepath File path.
	 * @return LocalFile
	 */
	protected function process_fixture( string $filepath ): LocalFile {
		$phpcs_file = new LocalFile( $filepath, static::$ruleset, static::$config );
		$phpcs_file->process();
		return $phpcs_file;
	}

	/**
	 * Counts messages on one line (getWarnings() is keyed line => column => messages).
	 *
	 * @param array<int, array<int, array<string, mixed>>> $columns Messages per column.
	 * @return int
	 */
	public static function count_messages( array $columns ): int {
		return array_sum( array_map( 'count', $columns ) );
	}

	/**
	 * Returns the warning sources (sniff codes) raised for a fixture, keyed by line.
	 *
	 * @param string $filepath Fixture path.
	 * @return array<int, string[]>
	 */
	protected function get_warning_sources( string $filepath ): array {
		$sources = array();
		foreach ( $this->process_fixture( $filepath )->getWarnings() as $line => $columns ) {
			foreach ( $columns as $messages ) {
				foreach ( $messages as $message ) {
					$sources[ $line ][] = $message['source'];
				}
			}
		}
		return $sources;
	}
}
