<?php

class Good_ExcessiveMocks_Test extends Project_TestCase {

	public function setUp(): void {
		parent::setUp();
		$this->a = $this->createMock( A::class );
		$this->b = $this->createMock( B::class );
		$this->c = $this->createMock( C::class );
		$this->d = $this->createMock( D::class );
		$this->e = $this->createMock( E::class );
	}

	public function test_four_mocks_is_fine() {
		$a = $this->createMock( A::class );
		$b = $this->createMock( B::class );
		$c = $this->createStub( C::class );
		$d = $this->createStub( D::class );
		$this->assertSame( 1, ( new Thing( $a, $b, $c, $d ) )->run() );
	}
}
