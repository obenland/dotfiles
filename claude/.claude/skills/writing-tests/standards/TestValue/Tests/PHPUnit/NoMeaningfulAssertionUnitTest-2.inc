<?php

class Good_NoMeaningfulAssertion_Test extends Project_TestCase {

	public function test_real_assertion() {
		$this->assertSame( 3, add( 1, 2 ) );
	}

	public function test_static_assertion() {
		self::assertCount( 2, get_items() );
		static::assertNotEmpty( get_items() );
		\PHPUnit\Framework\Assert::assertIsArray( get_items() );
	}

	public function test_expect_exception() {
		$this->expectException( InvalidArgumentException::class );
		parse_thing( '' );
	}

	public function test_expect_output() {
		$this->expectOutputString( 'hi' );
		echo 'hi';
	}

	public function test_mock_expects() {
		$mock = $this->createMock( Foo::class );
		$mock->expects( $this->once() )->method( 'bar' );
		( new Service( $mock ) )->run();
	}

	public function test_fail_branch() {
		try {
			do_thing();
		} catch ( Exception $e ) {
			$this->fail( 'Should not throw' );
		}
	}

	public function test_custom_helper() {
		$this->assertResponseIsValid( get_response() );
	}

	public function test_verify_helper() {
		$this->verifyHeaders( get_response() );
	}

	public function test_check_helper() {
		self::checkInvariants( get_state() );
	}

	public function test_regression_for_fatal() {
		// Regression: this used to fatal on PHP 8.4 with a null blog.
		run_with_null_blog();
		// phpcs:ignore TestValue.PHPUnit.NoMeaningfulAssertion -- the regression was a fatal; reaching this line is the assertion.
		$this->assertTrue( true );
	}

	/**
	 * @doesNotPerformAssertions
	 */
	public function test_explicit_smoke() {
		run_smoke();
	}

	/**
	 * @dataProvider data_add
	 */
	public function test_add( $a, $b, $expected ) {
		$this->assertSame( $expected, add( $a, $b ) );
	}

	public function data_add() {
		return [ [ 1, 2, 3 ], [ 2, 2, 4 ] ];
	}

	public function setUp(): void {
		parent::setUp();
		$this->service = new Service();
	}

	private function helper_without_assertions() {
		return new Service();
	}

	// Back-test FPs (2026-10): private test_*() helpers are not run by PHPUnit.
	private static function test_domains() {
		return [ 'a.com', 'b.com' ];
	}

	private function testable_runner() {
		return new Runner();
	}

	public function test_codeception_specify() {
		$this->specify(
			'returns the locale',
			function () {
				expect( get_locale_for( 'fr' ) )->equals( 'fr' );
			}
		);
	}

	public function test_inherited_assertion_helper() {
		$this->run_dispatch_assertions( rest_get_server() );
	}

	public function test_expectations_set_in_a_same_class_helper() {
		$cart = $this->make_cart_expecting_removals( 1 );
		( new Validator() )->validate( $cart );
	}

	private function make_cart_expecting_removals( $count ) {
		$cart = $this->createMock( Cart::class );
		$cart->expects( $this->exactly( $count ) )->method( 'remove' );
		return $cart;
	}

	public function test_in_house_mock_expectations() {
		$this->mock_functions->expect( 'bump_stat', null, [ 'group', 'name', 1 ] );
		$this->mock_http->expect_post( 'https://example.com' )->responds( 200 );
		run_job();
	}

	public function test_endpoint( $method = null, $endpoint = null ) {
		$this->markTestSkipped( 'Generic data-driven tests live on the parent class.' );
	}

	public function test_assertion_on_a_helper_object() {
		$this->helper->assertValidOrder( make_order() );
	}

	public function test_assertion_on_a_response_object() {
		$response = dispatch( '/orders' );
		$response->assertStatus( 200 );
	}

	public function test_delegates_to_a_parent_test() {
		parent::test_endpoint( 'GET', '/orders' );
	}

	public function test_inherited_helper_from_a_project_base_class() {
		$this->run_shared_checks( make_order() );
	}

	/**
	 * @doesNotPerformAssertions
	 */
	// phpcs:ignore TestValue.PHPUnit.ExcessiveMocks -- a phpcs:ignore line must not hide the docblock above it.
	public function test_smoke_with_an_ignore_comment_before_it() {
		run_smoke();
	}

	public function test_comparison_inside_assert_true_is_not_trivial() {
		$this->assertTrue( true === is_paid( make_order() ) );
	}
}

class NotATestClassHelper {
	public function test_looks_like_a_test() {
		run_smoke();
	}
}

class Good_Plain_PHPUnit_Base_Test extends \PHPUnit\Framework\TestCase {

	public function test_transitive_helper_assertion() {
		$this->run_order_through_a_helper( make_order() );
	}

	private function run_order_through_a_helper( $order ) {
		$this->inspect( $order );
	}

	private function inspect( $order ) {
		$this->assertSame( 'paid', $order->status() );
	}
}
