<?php

class Bad_ExcessiveMocks_Test extends Project_TestCase {

	public function test_many_mocks() { // WARN
		$a = $this->createMock( A::class );
		$b = $this->createStub( B::class );
		$c = $this->getMockBuilder( C::class )->getMock();
		$d = $this->createPartialMock( D::class, [ 'x' ] );
		$e = $this->createConfiguredMock( E::class, [ 'y' => 1 ] );
		$f = $this->getMockForAbstractClass( F::class );
		$this->assertSame( 1, ( new Thing( $a, $b, $c, $d, $e, $f ) )->run() );
	}

	public function test_exactly_five_mocks() { // WARN
		$a = $this->createMock( A::class );
		$b = $this->createMock( B::class );
		$c = $this->createMock( C::class );
		$d = $this->createMock( D::class );
		$e = $this->createMock( E::class );
		$this->assertSame( 1, ( new Thing( $a, $b, $c, $d, $e ) )->run() );
	}
}
