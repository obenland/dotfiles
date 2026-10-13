<?php
/**
 * Check new or changed PHPUnit tests for low-value patterns.
 *
 * Runs the TestValue phpcs standard and the suite-wide duplicate detector over
 * the test files changed in the working copy (or an explicit file list) and
 * prints one JSON report. Informational: exits 0 whenever the report was produced.
 *
 * In a git repo the diff is against the merge-base with the default branch,
 * plus uncommitted and untracked files. In an SVN working copy it is the local
 * modifications (svn diff) plus unversioned files.
 *
 * Usage:
 *   php check-tests.php [--base=<ref>]
 *   php check-tests.php --files=a.php,b.php
 *   php check-tests.php --files-from=<list>
 *   php check-tests.php --self-test
 *
 * Options:
 *   --base=<ref>        Git diff base (default: origin/HEAD, else origin/trunk,
 *                       origin/main, origin/master, trunk, main, master); the
 *                       merge-base with HEAD is used. Ignored in SVN working copies.
 *   --files=<a,b>       Check these files in full instead of the working-copy diff.
 *   --files-from=<path> Check the files listed (one per line) in full.
 *   --repo=<dir>        Working copy to check (default: the current directory).
 *   --all-lines         Report findings anywhere in the files, not only on added lines.
 *   --no-duplicates     Skip the suite-wide duplicate scan.
 *   --phpcs=<path>      phpcs binary (default: the nearest vendor/bin/phpcs, then
 *                       bin/phpcs at the repo root, then phpcs on PATH).
 *   --standard=<path>   Override the phpcs standard (default: the bundled TestValue).
 *   --pretty            Pretty-print the JSON.
 *   --self-test         Run the script's own regression checks.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput -- Standalone CLI script, WordPress is not loaded; output is JSON or plain text.

require_once __DIR__ . '/find-duplicate-tests.php';

const WTC_PHPCS_BATCH = 200;

/**
 * Sniffs that report on a test method's `function` line rather than the line
 * at fault. In diff mode they are kept when the method overlaps the added lines.
 */
const WTC_METHOD_LEVEL_CHECKS = array(
	'TestValue.PHPUnit.NoMeaningfulAssertion.NoAssertions',
	'TestValue.PHPUnit.MockOnlyVerification.',
	'TestValue.PHPUnit.ExcessiveMocks.',
	'TestValue.PHPUnit.NearDuplicateMethods.',
);

/**
 * Run a command and capture stdout, stderr and the exit code.
 *
 * @param string      $command Shell command.
 * @param string|null $cwd     Working directory.
 * @return array{0: int, 1: string, 2: string}
 */
function wtc_run( string $command, ?string $cwd = null ): array {
	$process = proc_open(
		$command,
		array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$cwd
	);
	if ( ! is_resource( $process ) ) {
		return array( 127, '', "Could not start: {$command}" );
	}
	// Read both pipes without deadlocking on large output.
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );
	$out = '';
	$err = '';
	while ( ! feof( $pipes[1] ) || ! feof( $pipes[2] ) ) {
		$read   = array_filter(
			array( $pipes[1], $pipes[2] ),
			static function ( mixed $p ): bool {
				return ! feof( $p );
			}
		);
		$write  = null;
		$except = null;
		if ( false === stream_select( $read, $write, $except, 5 ) ) {
			break;
		}
		foreach ( $read as $pipe ) {
			$chunk = fread( $pipe, 65536 );
			if ( $pipe === $pipes[1] ) {
				$out .= $chunk;
			} else {
				$err .= $chunk;
			}
		}
	}
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	return array( proc_close( $process ), $out, $err );
}

/**
 * Run git in the repo and return trimmed stdout, or throw.
 *
 * @param string $root Repo root.
 * @param string $args Git arguments (already escaped).
 * @return string
 * @throws RuntimeException When git fails.
 */
function wtc_git( string $root, string $args ): string {
	list( $code, $out, $err ) = wtc_run( 'git -C ' . escapeshellarg( $root ) . ' ' . $args );
	if ( 0 !== $code ) {
		throw new RuntimeException( "git {$args} failed: " . trim( $err ) );
	}
	return $out;
}

/**
 * Whether a repo-relative path is inside a tests/ (any case) directory, or (when
 * $root is given) inside a <testsuite> directory of a PHPUnit config above it.
 *
 * @param string      $path Repo-relative path.
 * @param string|null $root Repo root, to look up PHPUnit configs.
 * @return bool
 */
function wtc_in_tests_dir( string $path, ?string $root = null ): bool {
	return false !== stripos( '/' . dirname( $path ) . '/', '/tests/' )
		|| ( null !== $root && null !== wtd_configured_suite_dir( $root . '/' . $path, $root ) );
}

/**
 * Whether a repo-relative path is a PHP test file in a tests directory (see
 * wtc_in_tests_dir()): named FooTest.php / foo-test.php / foo_test.php /
 * test-foo.php / test_foo.php, or (when $root is given) declaring test methods,
 * which covers WordPress core-style files such as tests/phpunit/tests/post/query.php.
 *
 * @param string      $path Repo-relative path.
 * @param string|null $root Repo root, to look up PHPUnit configs and inspect the file.
 * @return bool
 */
function wtc_is_test_file( string $path, ?string $root = null ): bool {
	if ( ! str_ends_with( $path, '.php' ) || ! wtc_in_tests_dir( $path, $root ) ) {
		return false;
	}
	$base = basename( $path );
	if ( str_ends_with( $base, 'Test.php' ) || preg_match( '/(?:[-_]test\.php|^test[-_].*\.php)$/i', $base ) ) {
		return true;
	}
	return null !== $root && is_file( $root . '/' . $path ) && array() !== wtd_test_methods( $root . '/' . $path );
}

/**
 * Turn a path from a diff header into a repo-relative path: drop a trailing
 * tab-separated label ("\t(working copy)" in SVN, "\t" after names with spaces
 * in git), unquote a C-style quoted path ("b/foo bar.php") and, for git, drop
 * the a/ or b/ prefix.
 *
 * @param string $path Header path.
 * @param bool   $git  Whether the header comes from git (prefixed paths).
 * @return string|null Null for /dev/null.
 */
function wtc_header_path( string $path, bool $git = true ): ?string {
	$path = rtrim( $path, "\r" );
	$tab  = strpos( $path, "\t" );
	if ( false !== $tab ) {
		$path = substr( $path, 0, $tab );
	}
	if ( strlen( $path ) >= 2 && '"' === $path[0] && str_ends_with( $path, '"' ) ) {
		$path = stripcslashes( substr( $path, 1, -1 ) );
	}
	if ( '/dev/null' === $path ) {
		return null;
	}
	return $git ? (string) preg_replace( '#^[ab]/#', '', $path ) : $path;
}

/**
 * Parse `git diff -U0 --src-prefix=a/ --dst-prefix=b/` or `svn diff -x "-U 0"`
 * output into added line ranges and added line text. "+++ " is only a file
 * header between "diff --git" / "Index: " and that file's first hunk; inside a
 * hunk it is an added line whose content starts with "++ ".
 *
 * @param string $diff Diff output.
 * @param bool   $git  Whether the diff comes from git (else SVN).
 * @return array{ranges: array, added: array} ranges: path => list of [start, end]; added: path => list of added line text.
 */
function wtc_parse_diff( string $diff, bool $git = true ): array {
	$ranges    = array();
	$added     = array();
	$current   = null;
	$in_header = false;
	foreach ( explode( "\n", $diff ) as $line ) {
		if ( str_starts_with( $line, $git ? 'diff --git ' : 'Index: ' ) ) {
			$current   = null;
			$in_header = true;
		} elseif ( ! $git && str_starts_with( $line, 'Property changes on: ' ) ) {
			$current = null;
		} elseif ( $in_header && str_starts_with( $line, '+++ ' ) ) {
			$current = wtc_header_path( substr( $line, 4 ), $git );
			if ( null !== $current ) {
				$ranges[ $current ] = $ranges[ $current ] ?? array();
				$added[ $current ]  = $added[ $current ] ?? array();
			}
		} elseif ( $in_header && ! str_starts_with( $line, '@@' ) ) {
			continue; // Other header lines: index, mode, rename, ---.
		} elseif ( preg_match( '/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $m ) ) {
			$in_header = false;
			$start     = (int) $m[1];
			$count     = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 1;
			if ( null !== $current && $count > 0 ) {
				$ranges[ $current ][] = array( $start, $start + $count - 1 );
			}
		} elseif ( null !== $current && str_starts_with( $line, '+' ) ) {
			$added[ $current ][] = substr( $line, 1 );
		}
	}
	return array(
		'ranges' => $ranges,
		'added'  => $added,
	);
}

/**
 * Whether a line is inside the ranges (null ranges = whole file).
 *
 * @param int        $line   Line.
 * @param array|null $ranges Ranges or null.
 * @return bool
 */
function wtc_line_in( int $line, ?array $ranges ): bool {
	return null === $ranges || wtd_overlaps( $line, $line, $ranges );
}

/**
 * Whether a phpcs finding falls in the added ranges. Method-level checks report
 * on the `function` line, so they are kept when the method's line range overlaps
 * the added ranges; a near-duplicate is also kept when the earlier method it
 * names ("at line N") overlaps them, so a new test inserted above its twin counts.
 *
 * @param array      $finding Finding with file, line, check, message.
 * @param string     $abs     Absolute path of the file.
 * @param array|null $ranges  Added ranges, or null for the whole file.
 * @return bool
 */
function wtc_keep_finding( array $finding, string $abs, ?array $ranges ): bool {
	if ( wtc_line_in( $finding['line'], $ranges ) ) {
		return true;
	}
	$method_level = false;
	foreach ( WTC_METHOD_LEVEL_CHECKS as $prefix ) {
		$method_level = $method_level || str_starts_with( $finding['check'], $prefix );
	}
	if ( ! $method_level ) {
		return false;
	}
	$starts = array( $finding['line'] );
	if ( str_starts_with( $finding['check'], 'TestValue.PHPUnit.NearDuplicateMethods.' ) && preg_match( '/ at line (\d+)/', $finding['message'], $m ) ) {
		$starts[] = (int) $m[1];
	}
	foreach ( wtd_parse_file( $abs ) as $f ) {
		if ( in_array( $f['start'], $starts, true ) && wtd_overlaps( $f['start'], $f['end'], $ranges ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The default git diff base: origin/HEAD, else the first common default branch that exists.
 *
 * @param string $root Repo root.
 * @return string
 * @throws RuntimeException When no candidate ref exists.
 */
function wtc_default_base( string $root ): string {
	list( $code, $out ) = wtc_run( 'git -C ' . escapeshellarg( $root ) . ' symbolic-ref --quiet --short refs/remotes/origin/HEAD' );
	if ( 0 === $code && '' !== trim( $out ) ) {
		return trim( $out );
	}
	foreach ( array( 'origin/trunk', 'origin/main', 'origin/master', 'trunk', 'main', 'master' ) as $candidate ) {
		list( $code ) = wtc_run( 'git -C ' . escapeshellarg( $root ) . ' rev-parse --verify --quiet ' . escapeshellarg( $candidate . '^{commit}' ) );
		if ( 0 === $code ) {
			return $candidate;
		}
	}
	throw new RuntimeException( 'No default branch found (tried origin/HEAD, origin/trunk, origin/main, origin/master, trunk, main, master). Pass --base=<ref>.' );
}

/**
 * Git: added ranges and lines since the merge-base with $base, untracked files included.
 *
 * @param string $root Repo root.
 * @param string $base Base ref.
 * @return array{ranges: array, added: array, untracked: string[]}
 * @throws RuntimeException When the base ref is missing or git fails.
 */
function wtc_git_changes( string $root, string $base ): array {
	list( $code ) = wtc_run( 'git -C ' . escapeshellarg( $root ) . ' rev-parse --verify --quiet ' . escapeshellarg( $base . '^{commit}' ) );
	if ( 0 !== $code ) {
		throw new RuntimeException( "Base ref '{$base}' not found. Pass --base=<ref> with a ref that exists locally, or fetch it first." );
	}
	$merge_base = trim( wtc_git( $root, 'merge-base ' . escapeshellarg( $base ) . ' HEAD' ) );
	// Fixed prefixes so diff.noprefix / diff.mnemonicPrefix configs don't change the headers.
	$diff   = wtc_git( $root, 'diff -U0 --no-color --no-ext-diff --src-prefix=a/ --dst-prefix=b/ --find-renames --diff-filter=AMR ' . escapeshellarg( $merge_base ) . ' --' );
	$parsed = wtc_parse_diff( $diff );

	$parsed['untracked'] = explode( "\0", wtc_git( $root, 'ls-files -z --others --exclude-standard --full-name' ) );
	return $parsed;
}

/**
 * SVN: added ranges and lines of local modifications, unversioned files included.
 *
 * @param string $root Working-copy root.
 * @return array{ranges: array, added: array, untracked: string[]}
 * @throws RuntimeException When svn fails.
 */
function wtc_svn_changes( string $root ): array {
	list( $code, $diff, $err ) = wtc_run( 'svn diff --internal-diff -x ' . escapeshellarg( '-U 0' ) . ' --ignore-properties --no-diff-deleted', $root );
	if ( 0 !== $code ) {
		throw new RuntimeException( 'svn diff failed: ' . trim( $err ) );
	}
	$parsed = wtc_parse_diff( $diff, false );

	list( $code, $status, $err ) = wtc_run( 'svn status --ignore-externals', $root );
	if ( 0 !== $code ) {
		throw new RuntimeException( 'svn status failed: ' . trim( $err ) );
	}
	$untracked = array();
	foreach ( explode( "\n", $status ) as $line ) {
		if ( ! str_starts_with( $line, '?' ) ) {
			continue;
		}
		$path = trim( substr( $line, 8 ) );
		// An unversioned directory is listed once; its files are all new.
		foreach ( is_dir( $root . '/' . $path ) ? wtd_php_files( $root . '/' . $path ) : array( $root . '/' . $path ) as $abs ) {
			$untracked[] = wtd_relative( $abs, $root );
		}
	}
	$parsed['untracked'] = $untracked;
	return $parsed;
}

/**
 * Collect the files to check and their added ranges from the working-copy diff.
 *
 * @param string      $root Repo root.
 * @param string      $vcs  'git' or 'svn'.
 * @param string|null $base Git base ref (ignored for SVN).
 * @return array{files: string[], ranges: array, stats: array}
 */
function wtc_collect_diff( string $root, string $vcs, ?string $base ): array {
	$changes = 'svn' === $vcs ? wtc_svn_changes( $root ) : wtc_git_changes( $root, (string) $base );
	$ranges  = $changes['ranges'];
	$added   = $changes['added'];

	foreach ( $changes['untracked'] as $path ) {
		if ( ! str_ends_with( $path, '.php' ) && ! preg_match( '#(^|/)phpunit(\.dist)?\.xml(\.dist)?$#', $path ) ) {
			continue;
		}
		$content = @file_get_contents( $root . '/' . $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable files are skipped.
		if ( false === $content ) {
			continue;
		}
		$lines           = '' === $content ? array() : explode( "\n", rtrim( $content, "\n" ) );
		$ranges[ $path ] = $lines ? array( array( 1, count( $lines ) ) ) : array();
		$added[ $path ]  = $lines;
	}

	$files            = array();
	$production_lines = 0;
	$new_suites       = 0;
	foreach ( $ranges as $path => $file_ranges ) {
		if ( wtc_is_test_file( $path, $root ) ) {
			$files[] = $path;
		} elseif ( str_ends_with( $path, '.php' ) && ! wtc_in_tests_dir( $path, $root ) ) {
			// Test support code (base classes, fixtures under tests/ dirs) is not production code.
			$production_lines += count( $added[ $path ] ?? array() );
		}
		if ( preg_match( '#(^|/)phpunit(\.dist)?\.xml(\.dist)?$#', $path ) ) {
			foreach ( $added[ $path ] ?? array() as $text ) {
				if ( preg_match( '/<testsuite\b/', $text ) ) {
					++$new_suites;
				}
			}
		}
	}
	sort( $files );

	return array(
		'files'  => $files,
		'ranges' => array_intersect_key( $ranges, array_flip( $files ) ),
		'stats'  => array(
			'production_lines_changed' => $production_lines,
			'new_suites'               => $new_suites,
		),
	);
}

/**
 * Find a phpcs binary: the nearest vendor/bin/phpcs above the first file (up to
 * the root), then bin/phpcs at the root, then phpcs on PATH.
 *
 * @param string   $root  Repo root.
 * @param string[] $files Repo-relative files.
 * @return string|null
 */
function wtc_find_phpcs( string $root, array $files ): ?string {
	$dir = $files ? dirname( $root . '/' . $files[0] ) : $root;
	while ( str_starts_with( $dir . '/', $root . '/' ) ) {
		if ( is_file( $dir . '/vendor/bin/phpcs' ) ) {
			return $dir . '/vendor/bin/phpcs';
		}
		if ( $dir === $root ) {
			break;
		}
		$dir = dirname( $dir );
	}
	if ( is_file( $root . '/bin/phpcs' ) ) {
		return $root . '/bin/phpcs';
	}
	list( $code, $out ) = wtc_run( 'command -v phpcs' );
	return 0 === $code && '' !== trim( $out ) ? trim( $out ) : null;
}

/**
 * Run phpcs over the files and return findings.
 *
 * @param string      $root     Repo root.
 * @param string|null $phpcs    phpcs binary, or null to look one up.
 * @param string      $standard Standard path.
 * @param string[]    $files    Repo-relative files.
 * @param array       $errors   Error list (appended to).
 * @return array[]
 */
function wtc_run_phpcs( string $root, ?string $phpcs, string $standard, array $files, array &$errors ): array {
	$phpcs = $phpcs ?? wtc_find_phpcs( $root, $files );
	if ( null === $phpcs || ! is_file( $phpcs ) ) {
		$errors[] = 'phpcs not found (looked for vendor/bin/phpcs, bin/phpcs and phpcs on PATH); phpcs checks skipped. Pass --phpcs=<path>.';
		return array();
	}
	if ( str_contains( $standard, '/' ) && ! file_exists( $standard ) ) {
		$errors[] = "phpcs standard not found at {$standard}; phpcs checks skipped.";
		return array();
	}

	$findings = array();
	foreach ( array_chunk( $files, WTC_PHPCS_BATCH ) as $batch ) {
		$args = array_map(
			static function ( string $f ) use ( $root ): string {
				return escapeshellarg( $root . '/' . $f );
			},
			$batch
		);
		$cmd  = escapeshellarg( $phpcs ) . ' -q --standard=' . escapeshellarg( $standard ) .
			' --report=json --warning-severity=1 --error-severity=1 ' . implode( ' ', $args );

		list( $code, $out, $err ) = wtc_run( $cmd, $root );
		$report                   = json_decode( $out, true );
		if ( ! is_array( $report ) || ! isset( $report['files'] ) ) {
			$errors[] = sprintf( 'phpcs failed (exit %d): %s', $code, substr( trim( $out . ' ' . $err ), 0, 500 ) );
			continue;
		}
		foreach ( $report['files'] as $abs => $data ) {
			$rel = wtd_relative( $abs, $root );
			foreach ( $data['messages'] ?? array() as $msg ) {
				$source = (string) ( $msg['source'] ?? '' );
				if ( str_starts_with( $source, 'Internal.' ) ) {
					$errors[] = "phpcs {$source} in {$rel}: " . ( $msg['message'] ?? '' );
					continue;
				}
				$line       = (int) ( $msg['line'] ?? 0 );
				$findings[] = array(
					'file'          => $rel,
					'line'          => $line,
					'method'        => wtd_enclosing_function( $abs, $line ),
					'check'         => $source,
					'source'        => 'phpcs',
					'severity'      => (int) ( $msg['severity'] ?? 5 ) <= 3 ? 'hint' : 'warning',
					'message'       => (string) ( $msg['message'] ?? '' ),
					'related'       => array(),
					'related_count' => 0,
				);
			}
		}
	}
	return $findings;
}

/**
 * Normalize a user-supplied path to repo-relative, or null when missing.
 *
 * @param string $root Repo root.
 * @param string $path Path relative to cwd or root, or absolute.
 * @return string|null
 */
function wtc_normalize_path( string $root, string $path ): ?string {
	$path = trim( $path );
	if ( '' === $path ) {
		return null;
	}
	foreach ( array( $path, $root . '/' . $path ) as $candidate ) {
		$real = realpath( $candidate );
		if ( false !== $real && is_file( $real ) ) {
			return wtd_relative( $real, $root );
		}
	}
	return null;
}

/**
 * Run built-in regression checks for diff parsing, finding filtering and
 * test-file detection.
 *
 * @return int Exit code.
 */
function wtc_self_test(): int {
	$diff   = implode(
		"\n",
		array(
			'diff --git a/tests/FooTest.php b/tests/FooTest.php',
			'index 1111111..2222222 100644',
			'--- a/tests/FooTest.php',
			'+++ b/tests/FooTest.php',
			'@@ -10,0 +11,2 @@ class FooTest',
			'+++ not a header, an added line starting with ++',
			'+--- also content',
			'@@ -20,3 +21,0 @@ class FooTest',
			'-deleted one',
			'-deleted two',
			'-deleted three',
			'diff --git a/tests/NewTest.php b/tests/NewTest.php',
			'new file mode 100644',
			'index 0000000..3333333',
			'--- /dev/null',
			'+++ b/tests/NewTest.php',
			'@@ -0,0 +1,3 @@',
			'+<?php',
			'+class NewTest {',
			'+}',
			'diff --git "a/tests/Sp ace\303\251Test.php" "b/tests/Sp ace\303\251Test.php"',
			'--- "a/tests/Sp ace\303\251Test.php"',
			'+++ "b/tests/Sp ace\303\251Test.php"',
			'@@ -1 +1 @@',
			'-old',
			'+new',
			'',
		)
	);
	$parsed = wtc_parse_diff( $diff );
	$quoted = "tests/Sp ace\u{e9}Test.php";
	$svn    = wtc_parse_diff(
		implode(
			"\n",
			array(
				'Index: a/tests/FooTest.php',
				'===================================================================',
				"--- a/tests/FooTest.php\t(revision 100)",
				"+++ a/tests/FooTest.php\t(working copy)",
				'@@ -4,0 +5,2 @@',
				'+++ content',
				'+more',
				'Index: tests/NewTest.php',
				'===================================================================',
				"--- tests/NewTest.php\t(nonexistent)",
				"+++ tests/NewTest.php\t(working copy)",
				'@@ -0,0 +1 @@',
				'+<?php',
				'Property changes on: tests/NewTest.php',
				'___________________________________________________________________',
				'Added: svn:eol-style',
				'## -0,0 +1 ##',
				'+native',
				'',
			)
		),
		false
	);
	$spaced = wtc_parse_diff( "diff --git a/tests/Sp ace Test.php b/tests/Sp ace Test.php\n--- a/tests/Sp ace Test.php\t\n+++ b/tests/Sp ace Test.php\t\n@@ -1 +1 @@\n-old\n+new\n" );

	$tmp = sys_get_temp_dir() . '/wtc-self-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) ) . '.php';
	file_put_contents(
		$tmp,
		"<?php\nclass FixtureTest extends TestCase {\n" . // Lines 1-2.
		"\tpublic function test_new_above_twin(): void {\n\t\t\$a = 1;\n\t\t\$b = 2;\n\t\trun( \$a, \$b );\n\t}\n\n" . // Lines 3-7.
		"\tpublic function test_twin(): void {\n\t\t\$a = 3;\n\t\t\$b = 4;\n\t\trun( \$a, \$b );\n\t}\n" . // Lines 9-13.
		"}\n"
	);
	$tree       = (string) realpath( sys_get_temp_dir() ) . '/wtc-self-test-tree-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	$with_tests = "<?php\nclass Query_Test extends WP_UnitTestCase {\n\tpublic function test_query(): void {\n\t\t\$this->assertTrue( query() );\n\t}\n}\n";
	$tree_files = array(
		'phpunit.xml.dist'                     => "<?xml version=\"1.0\"?>\n<phpunit><testsuites><testsuite name=\"default\"><directory prefix=\"test-\">./phpunit</directory></testsuite></testsuites></phpunit>\n",
		'phpunit/thing.php'                    => $with_tests,
		'phpunit/helpers.php'                  => "<?php\nfunction make_thing() {}\n",
		'tests/phpunit/tests/post/query.php'   => $with_tests,
		'tests/phpunit/includes/bootstrap.php' => "<?php\nrequire 'functions.php';\n",
		'src/query.php'                        => $with_tests,
	);
	foreach ( $tree_files as $rel => $content ) {
		if ( ! is_dir( dirname( $tree . '/' . $rel ) ) ) {
			mkdir( dirname( $tree . '/' . $rel ), 0777, true );
		}
		file_put_contents( $tree . '/' . $rel, $content );
	}
	$finding    = static function ( int $line, string $check, string $message = '' ): array {
		return array(
			'line'    => $line,
			'check'   => $check,
			'message' => $message,
		);
	};
	$no_asserts = 'TestValue.PHPUnit.NoMeaningfulAssertion.NoAssertions';
	$near_dup   = 'TestValue.PHPUnit.NearDuplicateMethods.Found';
	$checks     = array(
		'"+++ " inside a hunk is an added line, not a file header' => array( 'tests/FooTest.php', 'tests/NewTest.php', $quoted ) === array_keys( $parsed['ranges'] )
			&& array( '++ not a header, an added line starting with ++', '--- also content' ) === $parsed['added']['tests/FooTest.php'],
		'deletion-only hunks add no range'                => array( array( 11, 12 ) ) === $parsed['ranges']['tests/FooTest.php'],
		'new files (--- /dev/null) get their added range' => array( array( 1, 3 ) ) === $parsed['ranges']['tests/NewTest.php'] && 3 === count( $parsed['added']['tests/NewTest.php'] ),
		'quoted paths are unquoted'                       => array( array( 1, 1 ) ) === ( $parsed['ranges'][ $quoted ] ?? null ),
		'a trailing tab after a git path is dropped'      => array( 'tests/Sp ace Test.php' ) === array_keys( $spaced['ranges'] ),
		'svn headers are parsed, without stripping a real a/ dir' => array( 'a/tests/FooTest.php', 'tests/NewTest.php' ) === array_keys( $svn['ranges'] )
			&& array( array( 5, 6 ) ) === $svn['ranges']['a/tests/FooTest.php']
			&& array( '++ content', 'more' ) === $svn['added']['a/tests/FooTest.php'],
		'svn property changes are not added lines'        => array( '<?php' ) === $svn['added']['tests/NewTest.php'],
		'method-level finding on an unchanged signature line is kept when the body changed' => wtc_keep_finding( $finding( 9, $no_asserts ), $tmp, array( array( 11, 11 ) ) ),
		'method-level finding is dropped when the method is unchanged' => ! wtc_keep_finding( $finding( 9, $no_asserts ), $tmp, array( array( 4, 4 ) ) ),
		'line-level finding outside the added lines is dropped' => ! wtc_keep_finding( $finding( 9, 'TestValue.PHPUnit.NoMeaningfulAssertion.TrivialAssertion' ), $tmp, array( array( 11, 11 ) ) ),
		'near-duplicate inserted above its twin is kept'  => wtc_keep_finding( $finding( 9, $near_dup, 'Same shape as test_new_above_twin() at line 3; merge into one test with a data provider.' ), $tmp, array( array( 3, 8 ) ) ),
		'near-duplicate of two unchanged methods is dropped' => ! wtc_keep_finding( $finding( 9, $near_dup, 'Same shape as test_new_above_twin() at line 3; merge.' ), $tmp, array( array( 15, 16 ) ) ),
		'whole-file mode keeps every finding'             => wtc_keep_finding( $finding( 1, 'Anything.Else' ), $tmp, null ),
		'test files are detected by name'                 => wtc_is_test_file( 'wp-content/plugins/foo/tests/FooTest.php' )
			&& wtc_is_test_file( 'wp-content/lib/foo/Tests/Unit/BarTest.php' )
			&& wtc_is_test_file( 'wp-content/plugins/foo/tests/class-foo-test.php' )
			&& wtc_is_test_file( 'wp-content/plugins/foo/tests/foo_test.php' )
			&& wtc_is_test_file( 'wp-content/plugins/foo/tests/test-foo.php' )
			&& wtc_is_test_file( 'wp-content/plugins/foo/tests/test_foo.php' ),
		'non-test files are not detected'                 => ! wtc_is_test_file( 'wp-content/plugins/foo/FooTest.php' )
			&& ! wtc_is_test_file( 'wp-content/plugins/foo/tests/latest.php' )
			&& ! wtc_is_test_file( 'wp-content/plugins/foo/tests/bootstrap.php' )
			&& ! wtc_is_test_file( 'wp-content/plugins/foo/tests/FooTest.inc' )
			&& ! wtc_is_test_file( 'wp-content/plugins/contests/FooTest.php' ),
		'test files without a test-style name are detected by content' => wtc_is_test_file( 'tests/phpunit/tests/post/query.php', $tree )
			&& ! wtc_is_test_file( 'tests/phpunit/includes/bootstrap.php', $tree )
			&& ! wtc_is_test_file( 'src/query.php', $tree ),
		'phpunit.xml testsuite dirs count as tests dirs'  => wtc_is_test_file( 'phpunit/thing.php', $tree )
			&& wtc_in_tests_dir( 'phpunit/helpers.php', $tree )
			&& ! wtc_is_test_file( 'phpunit/helpers.php', $tree )
			&& ! wtc_in_tests_dir( 'phpunit/helpers.php' ),
	);
	unlink( $tmp );
	$cleanup = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tree, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $cleanup as $entry ) {
		$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $tree );

	$failed = 0;
	foreach ( $checks as $name => $ok ) {
		echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
		if ( ! $ok ) {
			++$failed;
		}
	}
	echo $failed ? "{$failed} check(s) failed\n" : 'All ' . count( $checks ) . " checks passed\n";
	return $failed ? 1 : 0;
}

/**
 * Entry point.
 *
 * @param string[] $cli_args Arguments.
 * @return int Exit code.
 * @throws RuntimeException On invalid usage or git failures.
 */
function wtc_main( array $cli_args ): int {
	$opts = array(
		'base'          => null,
		'files'         => null,
		'files_from'    => null,
		'all_lines'     => false,
		'no_duplicates' => false,
		'pretty'        => false,
		'phpcs'         => null,
		'repo'          => null,
		'standard'      => null,
	);
	foreach ( array_slice( $cli_args, 1 ) as $arg ) {
		if ( str_starts_with( $arg, '--base=' ) ) {
			$opts['base'] = substr( $arg, 7 );
			if ( '' === $opts['base'] || str_starts_with( $opts['base'], '-' ) ) {
				throw new RuntimeException( "Invalid --base value '{$opts['base']}': pass a git ref such as --base=origin/main." );
			}
		} elseif ( str_starts_with( $arg, '--files=' ) ) {
			$opts['files'] = substr( $arg, 8 );
		} elseif ( str_starts_with( $arg, '--files-from=' ) ) {
			$opts['files_from'] = substr( $arg, 13 );
		} elseif ( str_starts_with( $arg, '--repo=' ) ) {
			$opts['repo'] = substr( $arg, 7 );
		} elseif ( str_starts_with( $arg, '--phpcs=' ) ) {
			$opts['phpcs'] = substr( $arg, 8 );
		} elseif ( str_starts_with( $arg, '--standard=' ) ) {
			$opts['standard'] = substr( $arg, 11 );
		} elseif ( '--all-lines' === $arg ) {
			$opts['all_lines'] = true;
		} elseif ( '--no-duplicates' === $arg ) {
			$opts['no_duplicates'] = true;
		} elseif ( '--pretty' === $arg ) {
			$opts['pretty'] = true;
		} elseif ( '--self-test' === $arg ) {
			return wtc_self_test();
		} elseif ( '--help' === $arg || '-h' === $arg ) {
			preg_match( '#/\*\*(.*?)\*/#s', (string) file_get_contents( __FILE__ ), $m );
			echo preg_replace( '/^\s*\* ?/m', '', $m[1] ?? '' ) . "\n";
			return 0;
		} else {
			throw new RuntimeException( "Unknown argument: {$arg}" );
		}
	}

	$repo = $opts['repo'] ?? (string) getcwd();
	$vcs  = is_dir( $repo ) ? wtd_vcs_root( $repo ) : null;
	if ( null === $vcs ) {
		throw new RuntimeException( "Not a git repository or SVN working copy: {$repo}" );
	}
	$root     = $vcs['root'];
	$standard = $opts['standard'] ?? dirname( __DIR__ ) . '/standards/TestValue';
	$errors   = array();
	$stats    = array(
		'production_lines_changed' => 0,
		'new_suites'               => 0,
	);

	if ( null !== $opts['files'] || null !== $opts['files_from'] ) {
		$mode = 'files';
		$base = null;
		$list = array();
		if ( null !== $opts['files'] ) {
			$list = array_merge( $list, explode( ',', $opts['files'] ) );
		}
		if ( null !== $opts['files_from'] ) {
			$content = @file_get_contents( $opts['files_from'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reported below.
			if ( false === $content ) {
				throw new RuntimeException( "Cannot read --files-from list: {$opts['files_from']}" );
			}
			$list = array_merge( $list, explode( "\n", $content ) );
		}
		$files = array();
		foreach ( $list as $item ) {
			if ( '' === trim( $item ) ) {
				continue;
			}
			$rel = wtc_normalize_path( $root, $item );
			if ( null === $rel ) {
				$errors[] = 'File not found: ' . trim( $item );
				continue;
			}
			if ( str_starts_with( $rel, '/' ) ) {
				$errors[] = 'File is outside the repository root, skipped: ' . trim( $item );
				continue;
			}
			$files[ $rel ] = true;
		}
		$files = array_keys( $files );
		sort( $files );
		$ranges = array(); // Empty map = whole files.
	} else {
		$mode      = 'diff';
		$base      = 'svn' === $vcs['vcs'] ? 'BASE' : ( $opts['base'] ?? wtc_default_base( $root ) );
		$collected = wtc_collect_diff( $root, $vcs['vcs'], $base );
		$files     = $collected['files'];
		$ranges    = $collected['ranges'];
		$stats     = $collected['stats'];
	}

	// Ranges used for filtering: null means the whole file.
	$filter_ranges = static function ( string $file ) use ( $ranges, $opts ): ?array {
		if ( $opts['all_lines'] || ! isset( $ranges[ $file ] ) ) {
			return null;
		}
		return $ranges[ $file ];
	};

	$findings = array();
	if ( $files ) {
		foreach ( wtc_run_phpcs( $root, $opts['phpcs'], $standard, $files, $errors ) as $finding ) {
			if ( wtc_keep_finding( $finding, $root . '/' . $finding['file'], $filter_ranges( $finding['file'] ) ) ) {
				$findings[] = $finding;
			}
		}

		if ( ! $opts['no_duplicates'] ) {
			$abs_targets = array();
			$abs_ranges  = array();
			foreach ( $files as $file ) {
				$abs           = $root . '/' . $file;
				$abs_targets[] = $abs;
				$file_ranges   = $filter_ranges( $file );
				if ( null !== $file_ranges ) {
					$abs_ranges[ $abs ] = $file_ranges;
				}
			}
			$findings = array_merge( $findings, wtd_find_duplicates( $abs_targets, $abs_ranges, $root ) );
		}
	}

	usort(
		$findings,
		static function ( array $a, array $b ): int {
			return array( $a['file'], $a['line'], $a['check'] ) <=> array( $b['file'], $b['line'], $b['check'] );
		}
	);

	$summary = array(
		'warning'  => 0,
		'hint'     => 0,
		'by_check' => array(),
	);
	foreach ( $findings as $f ) {
		++$summary[ $f['severity'] ];
		$summary['by_check'][ $f['check'] ] = ( $summary['by_check'][ $f['check'] ] ?? 0 ) + 1;
	}
	ksort( $summary['by_check'] );
	if ( ! $summary['by_check'] ) {
		$summary['by_check'] = new stdClass();
	}

	$new_methods = 0;
	foreach ( $files as $file ) {
		$file_ranges = $ranges[ $file ] ?? null;
		foreach ( wtd_test_methods( $root . '/' . $file ) as $m ) {
			if ( null === $file_ranges || wtd_overlaps( $m['start'], $m['end'], $file_ranges ) ) {
				++$new_methods;
			}
		}
	}

	$report = array(
		'base'     => $base,
		'mode'     => $mode,
		'files'    => $files,
		'findings' => $findings,
		'summary'  => $summary,
		'stats'    => array(
			'test_files_changed'       => count( $files ),
			'new_test_methods'         => $new_methods,
			'production_lines_changed' => $stats['production_lines_changed'],
			'new_suites'               => $stats['new_suites'],
		),
		'errors'   => $errors,
	);
	echo json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | ( $opts['pretty'] ? JSON_PRETTY_PRINT : 0 ) ) . "\n";
	return 0;
}

if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	try {
		$wtc_exit = wtc_main( $argv );
	} catch ( \Throwable $e ) {
		echo json_encode( array( 'errors' => array( 'Internal error: ' . $e->getMessage() ) ), JSON_UNESCAPED_SLASHES ) . "\n";
		$wtc_exit = 2;
	}
	exit( $wtc_exit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI exit code.
}
