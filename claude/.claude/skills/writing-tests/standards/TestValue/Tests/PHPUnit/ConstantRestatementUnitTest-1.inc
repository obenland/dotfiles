<?php

class Bad_ConstantRestatement_Test extends Project_TestCase {

	public function test_constants() {
		$this->assertSame( 'pending', Order::STATUS_PENDING ); // WARN
		$this->assertEquals( Order::MAX_ITEMS, 50 ); // WARN
		self::assertSame( 1.5, \Shop\Order::RATE ); // WARN
		$this->assertSame( -1, Order::NONE ); // WARN
		$this->assertSame( true, Order::ENABLED ); // WARN
		$this->assertSame( null, Order::DEFAULT_NOTE ); // WARN
		$this->assertSame( [ 'a', 'b' ], Order::KEYS ); // WARN
		$this->assertSame( array( 'x' => 1 ), Order::MAP ); // WARN
	}

	public function test_self_constant() {
		$this->assertSame( 'v2', self::API_VERSION ); // WARN
		$this->assertSame( 'v2', static::API_VERSION ); // WARN
	}
}
