<?php

class Bad_NoMeaningfulAssertion_Test extends Project_TestCase {

	public function test_runs_without_error() { // WARN
		$service = new Service();
		$service->run();
	}

	public function test_only_trivial() {
		$service = new Service();
		$service->run();
		$this->assertTrue( true ); // WARN
	}

	public function test_trivial_with_message() {
		( new Service() )->run();
		self::assertTrue( true, 'Did not throw' ); // WARN
		$this->assertFalse( false );
	}

	/**
	 * @test
	 */
	public function it_does_nothing() { // WARN
		new Service();
	}

	#[Test]
	public function attribute_test_does_nothing() { // WARN
		new Service();
	}

	public function testCamelCaseNothing() { // WARN
		$result = assert( true );
	}

	public function test_mock_without_expects_and_no_assert() { // WARN
		$mock = $this->createMock( Foo::class );
		$mock->method( 'bar' )->willReturn( 1 );
		( new Service( $mock ) )->run();
	}
}

class Bad_Plain_PHPUnit_Base_Test extends \PHPUnit\Framework\TestCase {

	public function test_calls_an_undefined_helper_on_a_plain_base() { // WARN
		$this->build_fixture();
		run_job();
	}
}

class WC_Tests_Thing extends \WC_Unit_Test_Case {

	public function test_wc_style_class_name() { // WARN
		$thing = new Thing();
		$thing->run();
	}
}
