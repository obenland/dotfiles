<?php
/**
 * Find test methods that duplicate tests elsewhere in the same suite.
 *
 * Usage:
 *   php find-duplicate-tests.php [--target-lines=<json>] [--pretty] <test-file> [<test-file> ...]
 *   php find-duplicate-tests.php --self-test
 *
 * --target-lines points at a JSON file mapping a file path to a list of
 * [start, end] added-line ranges. Only test methods overlapping those ranges
 * are treated as "new". Without it, every test method in a target file is new.
 *
 * Output (stdout): {"findings": [...]} with one finding per duplicated new method.
 *
 * The functions here are also used as a library by check-tests.php; main()
 * only runs when this file is executed directly.
 *
 * @package WritingTestsSkill
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput -- Standalone CLI script, WordPress is not loaded; output is JSON or plain text.

const WTD_MIN_STATEMENTS = 3;
const WTD_MAX_RELATED    = 10;

/**
 * Minimum token-bag similarity (Dice coefficient, literals normalized) for two
 * same-named tests in different files to count as clones. Calibrated on a large
 * back-test: same-named tests below this test genuinely different code.
 */
const WTD_MIN_NAME_SIMILARITY = 0.85;

/**
 * Compute the line number of every token.
 *
 * @param array $tokens Tokens from token_get_all().
 * @return int[] Line number per token index.
 */
function wtd_token_lines( array $tokens ): array {
	$lines = array();
	$line  = 1;
	foreach ( $tokens as $i => $token ) {
		if ( is_array( $token ) ) {
			$line        = $token[2];
			$lines[ $i ] = $line;
			$line       += substr_count( $token[1], "\n" );
		} else {
			$lines[ $i ] = $line;
		}
	}
	return $lines;
}

/**
 * Index of the next token that is not whitespace or a comment.
 *
 * @param array $tokens Tokens.
 * @param int   $i      Start index (exclusive).
 * @return int|null
 */
function wtd_next_code_token( array $tokens, int $i ): ?int {
	$count = count( $tokens );
	for ( $j = $i + 1; $j < $count; $j++ ) {
		if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $j;
	}
	return null;
}

/**
 * Normalize a method body into a hash, ignoring formatting and literal values.
 *
 * @param array $tokens Tokens.
 * @param int   $open   Index of the opening brace.
 * @param int   $close  Index of the closing brace.
 * @return array{0: string, 1: int, 2: string[]} Hash, statement count and the normalized tokens.
 */
function wtd_body_signature( array $tokens, int $open, int $close ): array {
	$parts      = array();
	$statements = 0;
	for ( $i = $open + 1; $i < $close; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) ) {
			if ( ';' === $token ) {
				++$statements;
			}
			$parts[] = $token;
			continue;
		}
		switch ( $token[0] ) {
			case T_WHITESPACE:
			case T_COMMENT:
			case T_DOC_COMMENT:
				break;
			case T_CONSTANT_ENCAPSED_STRING:
			case T_ENCAPSED_AND_WHITESPACE:
				$parts[] = '<S>';
				break;
			case T_LNUMBER:
			case T_DNUMBER:
				$parts[] = '<N>';
				break;
			default:
				$parts[] = $token[1];
		}
	}
	return array( md5( implode( "\x1f", $parts ) ), $statements, $parts );
}

/**
 * Whether a token is "&" (a plain string token before PHP 8.1, an array token
 * after, e.g. in `function &name()`).
 *
 * @param array|string $token Token.
 * @return bool
 */
function wtd_is_ampersand( array|string $token ): bool {
	if ( '&' === $token ) {
		return true;
	}
	return is_array( $token ) && defined( 'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG' ) && T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG === $token[0];
}

/**
 * Parse a PHP file and return its named functions/methods.
 *
 * Each entry: name, start, end (lines), is_method, is_test, hash (null when the
 * body is too small to compare). Results are cached per path.
 *
 * @param string $path Absolute file path.
 * @return array[]
 */
function wtd_parse_file( string $path ): array {
	static $cache = array();
	if ( isset( $cache[ $path ] ) ) {
		return $cache[ $path ];
	}
	$code = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable files are skipped.
	if ( false === $code ) {
		$cache[ $path ] = array();
		return array();
	}
	try {
		$tokens = token_get_all( $code );
	} catch ( \Throwable $e ) {
		$cache[ $path ] = array();
		return array();
	}
	$lines  = wtd_token_lines( $tokens );
	$count  = count( $tokens );
	$result = array();

	$depth          = 0;
	$function_stack = array(); // Brace depth at which each open function body closes.
	$pending_meta   = '';      // Docblock + attribute text preceding the next declaration.
	$pending_hidden = false;   // A private/protected modifier precedes the next declaration.

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		$type  = is_array( $token ) ? $token[0] : $token;

		if ( T_DOC_COMMENT === $type ) {
			$pending_meta .= $token[1];
			continue;
		}
		if ( T_ATTRIBUTE === $type ) {
			// Capture the attribute up to its matching bracket.
			$bracket = 1;
			$text    = '#[';
			for ( $j = $i + 1; $j < $count && $bracket > 0; $j++ ) {
				$t = $tokens[ $j ];
				$s = is_array( $t ) ? $t[1] : $t;
				if ( '[' === $s ) {
					++$bracket;
				} elseif ( ']' === $s ) {
					--$bracket;
				}
				$text .= $s;
			}
			$pending_meta .= $text;
			$i             = $j - 1;
			continue;
		}
		if ( T_PRIVATE === $type || T_PROTECTED === $type ) {
			$pending_hidden = true;
			continue;
		}
		if ( '{' === $type || T_CURLY_OPEN === $type || T_DOLLAR_OPEN_CURLY_BRACES === $type ) {
			++$depth;
			$pending_meta   = '';
			$pending_hidden = false;
			continue;
		}
		if ( '}' === $type ) {
			--$depth;
			if ( $function_stack && end( $function_stack ) === $depth ) {
				array_pop( $function_stack );
			}
			$pending_meta   = '';
			$pending_hidden = false;
			continue;
		}
		if ( ';' === $type ) {
			$pending_meta   = '';
			$pending_hidden = false;
			continue;
		}
		if ( T_FUNCTION !== $type ) {
			continue;
		}

		$meta           = $pending_meta;
		$hidden         = $pending_hidden;
		$pending_meta   = '';
		$pending_hidden = false;
		$name_index     = wtd_next_code_token( $tokens, $i );
		if ( null !== $name_index && wtd_is_ampersand( $tokens[ $name_index ] ) ) {
			$name_index = wtd_next_code_token( $tokens, $name_index );
		}
		if ( null === $name_index || ! is_array( $tokens[ $name_index ] ) || T_STRING !== $tokens[ $name_index ][0] ) {
			continue; // Closure: its braces are handled by the depth tracking above.
		}
		$name = $tokens[ $name_index ][1];

		// Find the body opener (or ';' for abstract/interface methods).
		$open  = null;
		$paren = 0;
		for ( $j = $name_index + 1; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( '(' === $t ) {
				++$paren;
			} elseif ( ')' === $t ) {
				--$paren;
			} elseif ( 0 === $paren && ';' === $t ) {
				break;
			} elseif ( 0 === $paren && '{' === $t ) {
				$open = $j;
				break;
			}
		}
		if ( null === $open ) {
			$i = $j;
			continue;
		}

		// Find the matching closing brace.
		$close = null;
		$level = 0;
		for ( $j = $open; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			$s = is_array( $t ) ? $t[0] : $t;
			if ( '{' === $s || T_CURLY_OPEN === $s || T_DOLLAR_OPEN_CURLY_BRACES === $s ) {
				++$level;
			} elseif ( '}' === $s ) {
				--$level;
				if ( 0 === $level ) {
					$close = $j;
					break;
				}
			}
		}
		if ( null === $close ) {
			break; // Unbalanced file; stop parsing.
		}

		$is_method = $depth > 0 && ! $function_stack;
		// PHPUnit only runs public methods; a private test_*() is a helper.
		$is_test = $is_method && ! $hidden && (
			str_starts_with( $name, 'test' )
			|| preg_match( '/@test\b/', $meta )
			// A Test attribute: alone, grouped with other attributes, or fully qualified.
			|| preg_match( '/(?:#\[|,)\s*\\\\?(?:PHPUnit\\\\Framework\\\\Attributes\\\\)?Test\s*[\](,]/', $meta )
		);

		list( $hash, $statements, $parts ) = wtd_body_signature( $tokens, $open, $close );

		$result[] = array(
			'name'      => $name,
			'start'     => $lines[ $i ],
			'end'       => $lines[ $close ],
			'is_method' => $is_method,
			'is_test'   => (bool) $is_test,
			'hash'      => $statements >= WTD_MIN_STATEMENTS ? $hash : null,
			'bag'       => $is_test ? array_count_values( $parts ) : null,
		);

		// Continue scanning inside the body so nested named functions are recorded.
		$function_stack[] = $depth;
		++$depth;
		$i = $open;
	}

	$cache[ $path ] = $result;
	return $result;
}

/**
 * Test methods of a file.
 *
 * @param string $path Absolute file path.
 * @return array[]
 */
function wtd_test_methods( string $path ): array {
	return array_values(
		array_filter(
			wtd_parse_file( $path ),
			static function ( array $f ): bool {
				return $f['is_test'];
			}
		)
	);
}

/**
 * Name of the innermost named function enclosing a line, or null.
 *
 * @param string $path Absolute file path.
 * @param int    $line Line number.
 * @return string|null
 */
function wtd_enclosing_function( string $path, int $line ): ?string {
	$best = null;
	foreach ( wtd_parse_file( $path ) as $f ) {
		if ( $line >= $f['start'] && $line <= $f['end'] ) {
			if ( null === $best || ( $f['end'] - $f['start'] ) < ( $best['end'] - $best['start'] ) ) {
				$best = $f;
			}
		}
	}
	return $best ? $best['name'] : null;
}

/**
 * Whether [start, end] overlaps any of the given ranges.
 *
 * @param int   $start  Start line.
 * @param int   $end    End line.
 * @param array $ranges List of [start, end].
 * @return bool
 */
function wtd_overlaps( int $start, int $end, array $ranges ): bool {
	foreach ( $ranges as $range ) {
		if ( $start <= (int) $range[1] && $end >= (int) $range[0] ) {
			return true;
		}
	}
	return false;
}

/**
 * PHPUnit config file names, in the order PHPUnit picks them.
 */
const WTD_PHPUNIT_CONFIGS = array( 'phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist' );

/**
 * Absolute <testsuite><directory> paths declared by the PHPUnit config in a directory.
 *
 * @param string $dir Directory that may hold a PHPUnit config.
 * @return string[]|null Null when the directory has no PHPUnit config.
 */
function wtd_config_suite_dirs( string $dir ): ?array {
	static $cache = array();
	if ( array_key_exists( $dir, $cache ) ) {
		return $cache[ $dir ];
	}
	$cache[ $dir ] = null;
	foreach ( WTD_PHPUNIT_CONFIGS as $name ) {
		if ( ! is_file( $dir . '/' . $name ) ) {
			continue;
		}
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_file( $dir . '/' . $name );
		libxml_use_internal_errors( $previous );
		$dirs = array();
		foreach ( false === $xml ? array() : $xml->xpath( '//testsuite/directory' ) as $node ) {
			$value = trim( (string) $node );
			$real  = '' === $value ? false : realpath( str_starts_with( $value, '/' ) ? $value : $dir . '/' . $value );
			if ( false !== $real && is_dir( $real ) ) {
				$dirs[] = $real;
			}
		}
		$cache[ $dir ] = $dirs;
		break;
	}
	return $cache[ $dir ];
}

/**
 * Directories from $path's parent up to $root (or the filesystem root).
 *
 * @param string      $path Absolute file path.
 * @param string|null $root Repo root; the walk stops there.
 * @return string[]
 */
function wtd_ancestors( string $path, ?string $root ): array {
	$root = null === $root ? null : rtrim( $root, '/' );
	$dirs = array();
	for ( $dir = dirname( $path ); ; $dir = dirname( $dir ) ) {
		$dirs[] = $dir;
		if ( $dir === $root || dirname( $dir ) === $dir ) {
			break;
		}
	}
	return $dirs;
}

/**
 * The deepest <testsuite> directory containing a file, from the nearest PHPUnit
 * config above it that lists one.
 *
 * @param string      $path Absolute file path.
 * @param string|null $root Repo root; the upward search stops there.
 * @return string|null
 */
function wtd_configured_suite_dir( string $path, ?string $root = null ): ?string {
	foreach ( wtd_ancestors( $path, $root ) as $dir ) {
		$best = null;
		foreach ( wtd_config_suite_dirs( $dir ) ?? array() as $suite ) {
			if ( str_starts_with( $path, $suite . '/' ) && ( null === $best || strlen( $suite ) > strlen( $best ) ) ) {
				$best = $suite;
			}
		}
		if ( null !== $best ) {
			return $best;
		}
	}
	return null;
}

/**
 * The suite directory for a test file: its PHPUnit <testsuite> directory, else
 * the outermost tests/ directory above it, else the file's own directory.
 *
 * @param string      $path Absolute file path.
 * @param string|null $root Repo root; the upward search stops there.
 * @return string
 */
function wtd_suite_dir( string $path, ?string $root = null ): string {
	$suite = wtd_configured_suite_dir( $path, $root );
	if ( null !== $suite ) {
		return $suite;
	}
	$top_tests = null;
	foreach ( wtd_ancestors( $path, $root ) as $dir ) {
		if ( 0 === strcasecmp( basename( $dir ), 'tests' ) ) {
			$top_tests = $dir;
		}
	}
	return $top_tests ?? dirname( $path );
}

/**
 * The working-copy root and VCS for a directory: git first, then SVN.
 *
 * @param string $dir Directory inside the working copy.
 * @return array{root: string, vcs: string}|null
 */
function wtd_vcs_root( string $dir ): ?array {
	$commands = array(
		'git' => 'git -C ' . escapeshellarg( $dir ) . ' rev-parse --show-toplevel 2>/dev/null',
		'svn' => 'svn info --show-item wc-root ' . escapeshellarg( $dir ) . ' 2>/dev/null',
	);
	foreach ( $commands as $vcs => $command ) {
		$top = trim( (string) shell_exec( $command ) );
		if ( '' !== $top && is_dir( $top ) ) {
			return array(
				'root' => (string) realpath( $top ),
				'vcs'  => $vcs,
			);
		}
	}
	return null;
}

/**
 * All PHP files under a directory, recursively (cached).
 *
 * @param string $dir Directory.
 * @return string[] Absolute paths, sorted.
 */
function wtd_php_files( string $dir ): array {
	static $cache = array();
	if ( isset( $cache[ $dir ] ) ) {
		return $cache[ $dir ];
	}
	$files = array();
	if ( is_file( $dir ) ) {
		$files[] = $dir;
	} elseif ( is_dir( $dir ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			}
		}
	}
	sort( $files );
	$cache[ $dir ] = $files;
	return $files;
}

/**
 * Build (and cache) name and body-hash indexes of test methods in a suite.
 *
 * @param string $suite_dir Suite directory.
 * @return array{names: array, hashes: array}
 */
function wtd_suite_index( string $suite_dir ): array {
	static $cache = array();
	if ( isset( $cache[ $suite_dir ] ) ) {
		return $cache[ $suite_dir ];
	}
	$index = array(
		'names'  => array(),
		'hashes' => array(),
	);
	foreach ( wtd_php_files( $suite_dir ) as $file ) {
		foreach ( wtd_test_methods( $file ) as $m ) {
			$entry                          = array(
				'file'   => $file,
				'line'   => $m['start'],
				'method' => $m['name'],
			);
			$index['names'][ $m['name'] ][] = $entry;
			if ( null !== $m['hash'] ) {
				$index['hashes'][ $m['hash'] ][] = $entry;
			}
		}
	}
	$cache[ $suite_dir ] = $index;
	return $index;
}

/**
 * Dice similarity of two token bags (token => count).
 *
 * @param array $a Bag.
 * @param array $b Bag.
 * @return float 0..1.
 */
function wtd_bag_similarity( array $a, array $b ): float {
	$total = array_sum( $a ) + array_sum( $b );
	if ( 0 === $total ) {
		return 1.0;
	}
	$shared = 0;
	foreach ( $a as $token => $count ) {
		if ( isset( $b[ $token ] ) ) {
			$shared += min( $count, $b[ $token ] );
		}
	}
	return 2 * $shared / $total;
}

/**
 * The parsed test method of a file starting at a line.
 *
 * @param string $path Absolute file path.
 * @param int    $line Start line.
 * @return array|null
 */
function wtd_method_at( string $path, int $line ): ?array {
	foreach ( wtd_test_methods( $path ) as $m ) {
		if ( $m['start'] === $line ) {
			return $m;
		}
	}
	return null;
}

/**
 * Express a path relative to a root when it is inside it.
 *
 * @param string      $path Absolute path.
 * @param string|null $root Root directory or null.
 * @return string
 */
function wtd_relative( string $path, ?string $root ): string {
	if ( null !== $root ) {
		$prefix = rtrim( $root, '/' ) . '/';
		if ( str_starts_with( $path, $prefix ) ) {
			return substr( $path, strlen( $prefix ) );
		}
	}
	return $path;
}

/**
 * Find duplicated new test methods.
 *
 * @param string[]    $targets      Absolute target file paths.
 * @param array|null  $target_lines Map absolute path => list of [start, end]; null = all methods are new.
 *                                  A target missing from the map is treated as entirely new.
 * @param string|null $root        Root used to make reported paths relative.
 * @return array[] Findings.
 */
function wtd_find_duplicates( array $targets, ?array $target_lines = null, ?string $root = null ): array {
	$findings = array();
	foreach ( array_unique( $targets ) as $target ) {
		$methods = array_filter(
			wtd_test_methods( $target ),
			static function ( array $m ) use ( $target_lines, $target ): bool {
				return null === $target_lines || ! isset( $target_lines[ $target ] ) || wtd_overlaps( $m['start'], $m['end'], $target_lines[ $target ] );
			}
		);
		if ( ! $methods ) {
			continue; // Helper file or no new tests: skip the (slow) suite scan.
		}
		$index = wtd_suite_index( wtd_suite_dir( $target, $root ) );
		$rel   = wtd_relative( $target, $root );

		foreach ( $methods as $m ) {

			$checks = array(
				'Duplicates.SameNameInSuite' => $index['names'][ $m['name'] ] ?? array(),
				'Duplicates.SameBodyInSuite' => null !== $m['hash'] ? ( $index['hashes'][ $m['hash'] ] ?? array() ) : array(),
			);
			foreach ( $checks as $check => $entries ) {
				$others = array_values(
					array_filter(
						$entries,
						static function ( array $e ) use ( $target ): bool {
							return $e['file'] !== $target;
						}
					)
				);
				if ( $others && 'Duplicates.SameNameInSuite' === $check ) {
					// A shared name is only a clone when the bodies are also alike.
					$others = array_values(
						array_filter(
							$others,
							static function ( array $e ) use ( $m ): bool {
								$other = wtd_method_at( $e['file'], $e['line'] );
								return null !== $other && wtd_bag_similarity( $m['bag'], $other['bag'] ) >= WTD_MIN_NAME_SIMILARITY;
							}
						)
					);
				}
				if ( ! $others ) {
					continue;
				}
				$file_count = count( array_unique( array_column( $others, 'file' ) ) );
				$related    = array();
				foreach ( array_slice( $others, 0, WTD_MAX_RELATED ) as $e ) {
					$related[] = array(
						'file'   => wtd_relative( $e['file'], $root ),
						'line'   => $e['line'],
						'method' => $e['method'],
					);
				}
				$files_word = 1 === $file_count ? 'other file' : 'other files';
				if ( 'Duplicates.SameNameInSuite' === $check ) {
					$message = sprintf(
						'%s() also exists in %d %s in this suite — this looks like a cloned template. Test the shared behavior once (one suite-wide test or a data provider over all implementations).',
						$m['name'],
						$file_count,
						$files_word
					);
				} else {
					$example = $related[0];
					$message = sprintf(
						'%s() has identical logic (ignoring literal values) as %d test %s in %d %s in this suite (e.g. %s() in %s) — this looks like a cloned test. Keep one test and vary the inputs with a data provider.',
						$m['name'],
						count( $others ),
						1 === count( $others ) ? 'method' : 'methods',
						$file_count,
						$files_word,
						$example['method'],
						basename( $example['file'] )
					);
				}
				$findings[] = array(
					'file'          => $rel,
					'line'          => $m['start'],
					'method'        => $m['name'],
					'check'         => $check,
					'source'        => 'duplicates',
					'severity'      => 'warning',
					'message'       => $message,
					'related'       => $related,
					'related_count' => count( $others ),
				);
			}
		}
	}
	return $findings;
}

/**
 * Run built-in regression checks against temporary fixtures.
 *
 * @return int Exit code.
 */
function wtd_self_test(): int {
	$base  = (string) realpath( sys_get_temp_dir() ) . '/wtd-self-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	$suite = $base . '/tests/fixture';
	$other = $base . '/tests/other';
	mkdir( $suite . '/sub', 0777, true );
	mkdir( $other, 0777, true );
	file_put_contents(
		$base . '/phpunit.xml.dist',
		"<?xml version=\"1.0\"?>\n<phpunit>\n\t<testsuites>\n" .
		"\t\t<testsuite name=\"fixture\"><directory>./tests/fixture</directory></testsuite>\n" .
		"\t\t<testsuite name=\"other\"><directory>./tests/other</directory></testsuite>\n" .
		"\t</testsuites>\n</phpunit>\n"
	);

	$wrap = static function ( string $class_name, string $methods ): string {
		return "<?php\nclass {$class_name} extends TestCase {\n{$methods}\n}\n";
	};

	$body_a = "\t\t\$x = make_thing( 'alpha', 1 );\n\t\t\$y = \$x->run( 2 );\n\t\t\$this->assertSame( 'one', \$y );\n";
	// Same logic, different literals, extra comments and formatting.
	$body_b = "\t\t// Comment.\n\t\t\$x = make_thing( \"beta\", 99 ); /* inline */\n\n\t\t\$y   = \$x->run( 3.5 );\n\t\t\$this->assertSame( 'two', \$y );\n";
	// Different method call.
	$body_c = "\t\t\$x = make_thing( 'alpha', 1 );\n\t\t\$y = \$x->stop( 2 );\n\t\t\$this->assertSame( 'one', \$y );\n";
	// Too small to compare.
	$body_small = "\t\t\$this->assertTrue( thing( 'a' ) );\n";

	$files = array(
		'target'  => $suite . '/TargetTest.php',
		'literal' => $suite . '/sub/LiteralTest.php',
		'call'    => $suite . '/CallTest.php',
		'other'   => $other . '/OtherSuiteTest.php',
	);

	file_put_contents(
		$files['target'],
		$wrap(
			'TargetTest',
			"\tpublic function test_literal_clone(): void {\n{$body_a}\t}\n\n" . // Lines 3-7.
			"\tpublic function test_call_differs(): void {\n{$body_c}\t\t\$cb = function () { return 1; };\n\t}\n\n" . // Lines 9-14.
			"\tpublic function test_same_file_a(): void {\n\t\tfoo( 1 );\n\t\tbar( 2 );\n\t\tbaz( 3 );\n\t}\n\n" .
			"\tpublic function test_same_file_b(): void {\n\t\tfoo( 4 );\n\t\tbar( 5 );\n\t\tbaz( 6 );\n\t}\n\n" .
			"\t/**\n\t * @test\n\t */\n\tpublic function docblock_named(): void {\n{$body_small}\t}\n\n" .
			"\t#[Test]\n\tpublic function attribute_named(): void {\n{$body_small}\t}\n\n" .
			"\tpublic function test_shared_name(): void {\n{$body_small}\t}\n\n" .
			"\tpublic function test_cross_suite_name(): void {\n{$body_small}\t}\n\n" .
			"\tprivate function test_private_helper(): void {\n{$body_small}\t}\n\n" .
			"\tprivate function helper(): void {\n{$body_a}\t}\n"
		)
	);
	file_put_contents(
		$files['literal'],
		$wrap(
			'LiteralTest',
			"\tpublic function test_renamed_literal(): void {\n{$body_b}\t}\n\n" .
			"\tpublic function test_shared_name(): void {\n\t\t\$this->assertTrue( thing( 'z' ) );\n\t}\n\n" .
			"\t/** @test */\n\tpublic function docblock_named(): void {\n{$body_small}\t}\n\n" .
			"\t#[\\PHPUnit\\Framework\\Attributes\\Test]\n\tpublic function attribute_named(): void {\n{$body_small}\t}\n\n" .
			"\tprivate function test_private_helper(): void {\n{$body_small}\t}\n"
		)
	);
	file_put_contents(
		$files['call'],
		$wrap(
			'CallTest',
			"\tpublic function test_unrelated(): void {\n{$body_c}\t}\n\n" .
			"\tpublic function test_shared_name(): void {\n\t\tnope();\n\t}\n"
		)
	);
	file_put_contents(
		$files['other'],
		$wrap(
			'OtherSuiteTest',
			"\tpublic function test_cross_suite_name(): void {\n{$body_small}\t}\n"
		)
	);

	// Same-named tests whose token bags sit just above / just below WTD_MIN_NAME_SIMILARITY:
	// 20 statements of 6 tokens; renaming 17 calls gives 103/120 = 0.858, 19 gives 101/120 = 0.842.
	$sim_body              = static function ( int $renamed ): string {
		$body = '';
		for ( $k = 1; $k <= 20; $k++ ) {
			$body .= "\t\t\$x = " . ( $k <= $renamed ? 'g' : 'f' ) . "{$k}();\n";
		}
		return $body;
	};
	$files['similar']      = $suite . '/SimilarTest.php';
	$files['similar_twin'] = $suite . '/sub/SimilarTwinTest.php';
	$files['misc']         = $suite . '/MiscTest.php';
	$files['helper_only']  = $suite . '/HelperOnly.php';
	file_put_contents(
		$files['similar'],
		$wrap(
			'SimilarTest',
			"\tpublic function test_just_above_threshold(): void {\n{$sim_body( 0 )}\t}\n\n" .
			"\tpublic function test_just_below_threshold(): void {\n{$sim_body( 0 )}\t}\n"
		)
	);
	file_put_contents(
		$files['similar_twin'],
		$wrap(
			'SimilarTwinTest',
			"\tpublic function test_just_above_threshold(): void {\n{$sim_body( 17 )}\t}\n\n" .
			"\tpublic function test_just_below_threshold(): void {\n{$sim_body( 19 )}\t}\n"
		)
	);
	file_put_contents(
		$files['misc'],
		$wrap(
			'MiscTest',
			"\tpublic function &test_by_reference(): array {\n{$body_small}\t\treturn \$this->items;\n\t}\n\n" .
			"\t#[DataProvider('data_x'), Test]\n\tpublic function grouped_attribute(): void {\n{$body_small}\t}\n\n" .
			"\t#[DataProvider('data_x')]\n\tpublic function provider_only_attribute(): void {\n{$body_small}\t}\n"
		)
	);
	file_put_contents( $files['helper_only'], "<?php\nclass HelperOnly {\n\tpublic function make(): int {\n\t\treturn 1;\n\t}\n}\n" );
	$similar      = wtd_find_duplicates( array( $files['similar'] ), null, $base );
	$methods_sim  = array_column( wtd_test_methods( $files['similar'] ), null, 'name' );
	$methods_twin = array_column( wtd_test_methods( $files['similar_twin'] ), null, 'name' );
	$sim_above    = wtd_bag_similarity( $methods_sim['test_just_above_threshold']['bag'], $methods_twin['test_just_above_threshold']['bag'] );
	$sim_below    = wtd_bag_similarity( $methods_sim['test_just_below_threshold']['bag'], $methods_twin['test_just_below_threshold']['bag'] );
	$misc_methods = array_column( wtd_test_methods( $files['misc'] ), null, 'name' );

	$all       = wtd_find_duplicates( array( $files['target'] ), null, $base );
	$find      = static function ( array $findings, string $method, string $check ): ?array {
		foreach ( $findings as $f ) {
			if ( $f['method'] === $method && $f['check'] === $check ) {
				return $f;
			}
		}
		return null;
	};
	$methods   = array_column( wtd_test_methods( $files['target'] ), null, 'name' );
	$in_range  = wtd_find_duplicates( array( $files['target'] ), array( $files['target'] => array( array( 9, 9 ) ) ), $base );
	$in_range2 = wtd_find_duplicates( array( $files['target'] ), array( $files['target'] => array( array( 5, 5 ) ) ), $base );

	$checks = array(
		'literal-only differences still match'             => (bool) $find( $all, 'test_literal_clone', 'Duplicates.SameBodyInSuite' ),
		'files in nested suite subdirectories are scanned' => ( $find( $all, 'test_literal_clone', 'Duplicates.SameBodyInSuite' )['related'][0]['file'] ?? '' ) === 'tests/fixture/sub/LiteralTest.php',
		'different method calls don\'t match'              => null === $find( $all, 'test_call_differs', 'Duplicates.SameBodyInSuite' ),
		'same-file duplicates are not reported cross-file' => null === $find( $all, 'test_same_file_a', 'Duplicates.SameBodyInSuite' ) && null === $find( $all, 'test_same_file_b', 'Duplicates.SameBodyInSuite' ),
		'bodies under 3 statements are not body-compared'  => null === $find( $all, 'test_shared_name', 'Duplicates.SameBodyInSuite' ),
		'same name with a similar body is reported, a different body is not' => 1 === ( $find( $all, 'test_shared_name', 'Duplicates.SameNameInSuite' )['related_count'] ?? 0 )
			&& str_contains( $find( $all, 'test_shared_name', 'Duplicates.SameNameInSuite' )['message'] ?? '', 'in 1 other file' )
			&& 'tests/fixture/sub/LiteralTest.php' === ( $find( $all, 'test_shared_name', 'Duplicates.SameNameInSuite' )['related'][0]['file'] ?? '' ),
		'private test_*() helpers are not test methods'    => ! isset( $methods['test_private_helper'] ) && null === $find( $all, 'test_private_helper', 'Duplicates.SameNameInSuite' ),
		'@test docblock methods are test methods'          => (bool) $find( $all, 'docblock_named', 'Duplicates.SameNameInSuite' ),
		'#[Test] attribute methods are test methods'       => (bool) $find( $all, 'attribute_named', 'Duplicates.SameNameInSuite' ),
		'non-test helper methods are ignored'              => ! isset( $methods['helper'] ),
		'other suites are not scanned'                     => null === $find( $all, 'test_cross_suite_name', 'Duplicates.SameNameInSuite' ),
		'closures inside a method keep its end line'       => isset( $methods['test_call_differs'] ) && 9 === $methods['test_call_differs']['start'] && 14 === $methods['test_call_differs']['end'] && isset( $methods['test_same_file_a'] ),
		'methods outside target line ranges are ignored'   => array() === array_filter(
			$in_range,
			static function ( array $f ): bool {
				return 'test_call_differs' !== $f['method'];
			}
		) && null === $find( $in_range, 'test_literal_clone', 'Duplicates.SameBodyInSuite' ),
		'methods overlapping target line ranges are checked' => (bool) $find( $in_range2, 'test_literal_clone', 'Duplicates.SameBodyInSuite' ),
		'enclosing function resolves innermost named function' => 'test_call_differs' === wtd_enclosing_function( $files['target'], 13 ) && null === wtd_enclosing_function( $files['target'], 1 ),
		'suite dir is the phpunit.xml testsuite directory' => wtd_suite_dir( $files['literal'], $base ) === $suite && wtd_suite_dir( $files['other'], $base ) === $other,
		'without a config, the outermost tests/ dir is the suite' => wtd_suite_dir( $base . '/none/tests/unit/Tests/FooTest.php', $base . '/none' ) === $base . '/none/tests',
		'without a config or tests/ dir, the file\'s dir is the suite' => wtd_suite_dir( $base . '/none/src/FooTest.php', $base . '/none' ) === $base . '/none/src',
		'same name just above the similarity threshold is reported' => $sim_above > WTD_MIN_NAME_SIMILARITY && $sim_above < WTD_MIN_NAME_SIMILARITY + 0.02
			&& (bool) $find( $similar, 'test_just_above_threshold', 'Duplicates.SameNameInSuite' ),
		'same name just below the similarity threshold is not reported' => $sim_below < WTD_MIN_NAME_SIMILARITY && $sim_below > WTD_MIN_NAME_SIMILARITY - 0.02
			&& null === $find( $similar, 'test_just_below_threshold', 'Duplicates.SameNameInSuite' ),
		'by-reference methods (function &name) are parsed' => isset( $misc_methods['test_by_reference'] ),
		'#[DataProvider(...), Test] grouped attribute marks a test' => isset( $misc_methods['grouped_attribute'] ) && ! isset( $misc_methods['provider_only_attribute'] ),
		'a target without test methods yields no findings' => array() === wtd_find_duplicates( array( $files['helper_only'] ), null, $base ),
	);

	$cleanup = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $cleanup as $entry ) {
		$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $base );

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
 * Resolve a path given on the command line to an absolute path.
 *
 * @param string $path Path.
 * @return string|null
 */
function wtd_abs_path( string $path ): ?string {
	$real = realpath( $path );
	return false === $real ? null : $real;
}

/**
 * CLI entry point.
 *
 * @param string[] $cli_args Arguments.
 * @return int Exit code.
 */
function wtd_main( array $cli_args ): int {
	$targets      = array();
	$target_lines = null;
	$pretty       = false;
	$errors       = array();
	foreach ( array_slice( $cli_args, 1 ) as $arg ) {
		if ( '--self-test' === $arg ) {
			return wtd_self_test();
		} elseif ( '--pretty' === $arg ) {
			$pretty = true;
		} elseif ( str_starts_with( $arg, '--target-lines=' ) ) {
			$json = @file_get_contents( substr( $arg, 15 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reported below.
			$map  = false === $json ? null : json_decode( $json, true );
			if ( ! is_array( $map ) ) {
				fwrite( STDERR, "Could not read --target-lines JSON\n" );
				return 2;
			}
			$target_lines = array();
			foreach ( $map as $file => $ranges ) {
				$abs = wtd_abs_path( $file );
				if ( null !== $abs && is_array( $ranges ) ) {
					$target_lines[ $abs ] = $ranges;
				}
			}
		} elseif ( str_starts_with( $arg, '--' ) ) {
			fwrite( STDERR, "Unknown option: {$arg}\n" );
			return 2;
		} else {
			$abs = wtd_abs_path( $arg );
			if ( null === $abs ) {
				$errors[] = "File not found: {$arg}";
				continue;
			}
			$targets[] = $abs;
		}
	}
	if ( ! $targets && ! $errors ) {
		fwrite( STDERR, "Usage: php find-duplicate-tests.php [--target-lines=<json>] [--pretty] <test-file>...\n       php find-duplicate-tests.php --self-test\n" );
		return 2;
	}

	$vcs  = wtd_vcs_root( dirname( $targets[0] ?? (string) getcwd() ) );
	$root = null === $vcs ? null : $vcs['root'];

	$findings = wtd_find_duplicates( $targets, $target_lines, $root );
	usort(
		$findings,
		static function ( array $a, array $b ): int {
			return array( $a['file'], $a['line'], $a['check'] ) <=> array( $b['file'], $b['line'], $b['check'] );
		}
	);
	$output = array( 'findings' => $findings );
	if ( $errors ) {
		$output['errors'] = $errors;
	}
	echo json_encode( $output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ( $pretty ? JSON_PRETTY_PRINT : 0 ) ) . "\n";
	return 0;
}

if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	exit( wtd_main( $argv ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI exit code.
}
