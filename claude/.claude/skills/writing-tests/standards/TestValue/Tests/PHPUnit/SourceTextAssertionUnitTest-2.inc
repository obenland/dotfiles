<?php

class Good_SourceTextAssertion_Test extends Project_TestCase {

	public function test_parses_fixture() {
		$json = file_get_contents( __DIR__ . '/fixtures/response.json' );
		$this->assertSame( 'ok', parse_response( $json )['status'] );
	}

	public function test_parses_data_dir() {
		$raw = file_get_contents( __DIR__ . '/../data/sample.csv' );
		$this->assertCount( 3, parse_csv( $raw ) );
	}

	public function test_html_fixture_under_wp_content() {
		$html = file_get_contents( WP_CONTENT_DIR . '/lib/foo/tests/page.html' );
		$this->assertSame( 'Title', extract_title( $html ) );
	}

	public function test_reads_generated_output() {
		$path = generate_report( sys_get_temp_dir() );
		$this->assertStringContainsString( 'Total', file_get_contents( $path ) );
	}

	// Back-test FPs (2026-10): served assets compared to output are behavior, not PHP source.
	public function test_route_serves_committed_installer_verbatim() {
		$expected = file_get_contents( dirname( __DIR__, 5 ) . '/wp-content/plugins/foo/installers/install.ps1' );
		$this->assertSame( $expected, dispatch( '/install.ps1' )->get_data() );
	}

	public function test_canvas_is_not_sent_the_overrides() {
		$overrides = file_get_contents( WP_CONTENT_DIR . '/plugins/foo/css/editor-overrides.css' );
		$this->assertStringNotContainsString( $overrides, get_editor_css() );
	}
}
