<?php

class Bad_SourceTextAssertion_Test extends Project_TestCase {

	public function test_source_contains_hook() {
		$source = file_get_contents( ABSPATH . 'wp-content/lib/foo/class-foo.php' ); // WARN
		$this->assertStringContainsString( "add_action( 'init'", $source );
	}

	public function test_relative_source() {
		$source = file_get_contents( __DIR__ . '/../../../../wp-content/lib/foo/load.php' ); // WARN
		$this->assertStringNotContainsString( 'error_log(', $source );
	}

	public function test_content_dir() {
		$this->assertStringContainsString( 'function foo', file_get_contents( WP_CONTENT_DIR . '/lib/foo/load.php' ) ); // WARN
	}

	public function test_mu_plugin() {
		$code = \file_get_contents( WPMU_PLUGIN_DIR . '/foo.php' ); // WARN
		$this->assertMatchesRegularExpression( '/foo/', $code );
	}

	public function test_up_dir() {
		$code = file_get_contents( dirname( __DIR__ ) . '/../src/Foo.php' ); // WARN
		$this->assertStringContainsString( 'class Foo', $code );
	}
}
