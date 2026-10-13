<?php

class Good_ConstantRestatement_Test extends Project_TestCase {

	public function test_constant_as_input() {
		$order = new Order( Order::STATUS_PENDING );
		$this->assertSame( Order::STATUS_PENDING, $order->status() );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_constant_as_expected_result() {
		$this->assertSame( Order::STATUS_PAID, pay( new Order() )->status() );
	}

	public function test_class_name() {
		$this->assertSame( 'Shop\Order', Order::class );
		$this->assertInstanceOf( Order::class, make_order() );
	}

	public function test_count_matches_constant() {
		$this->assertCount( Order::MAX_ITEMS, fill_order() );
		$this->assertSame( 50, count( fill_order() ) );
	}

	/**
	 * @dataProvider data_statuses
	 */
	public function test_status_labels( $status, $label ) {
		$this->assertSame( $label, Order::label_for( $status ) );
	}

	public function test_concatenated_expectation_is_not_a_literal() {
		$suffix = get_suffix();
		$this->assertSame( 'order_' . $suffix, Order::META_KEY );
	}

	public function data_statuses() {
		return [ [ Order::STATUS_PENDING, 'Pending' ] ];
	}
}
