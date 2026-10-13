<?php

class Good_ExistenceAssertion_Test extends Project_TestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Foo_Service' ) ) {
			require __DIR__ . '/fixtures/foo-service.php';
		}
	}

	public function test_behavior() {
		if ( ! function_exists( 'mb_strlen' ) ) {
			$this->markTestSkipped( 'mbstring required' );
		}
		$this->assertSame( 3, mb_strlen( 'abc' ) );
	}

	public function test_registers_callback() {
		register_thing();
		// Registration is the behavior: the callback must resolve at runtime.
		$this->assertTrue( is_callable( get_registered_callback() ) );
		$this->assertTrue( class_exists( 'Foo' ) && Foo::is_ready() );
	}

	public function test_loader_defines_constant_from_config() {
		load_config( [ 'version' => '2.0' ] );
		$this->assertSame( '2.0', FOO_VERSION );
	}

	// Back-test FPs (2026-10): property_exists() on a runtime object is behavior.
	public function test_output_object_omits_property() {
		$stream = translate_item( get_item() );
		$this->assertFalse( property_exists( $stream, 'object' ) );
	}

	public function test_response_omits_unrequested_field() {
		$response = call_api( [ 'fields' => 'ID' ] );
		$this->assertFalse( property_exists( $response->response, 'two_step_enabled' ) );
		$this->assertTrue( property_exists( $response?->data, 'latency_count' ) );
	}
}
