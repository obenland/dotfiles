<?php

class Good_MockOnlyVerification_Test extends Project_TestCase {

	public function test_mock_and_result() {
		$repo = $this->createMock( Repo::class );
		$repo->expects( $this->once() )->method( 'save' )->willReturn( 7 );
		$id = ( new Service( $repo ) )->create( [ 'name' => 'x' ] );
		$this->assertSame( 7, $id );
	}

	public function test_mock_and_exception() {
		$repo = $this->createMock( Repo::class );
		$repo->expects( $this->once() )->method( 'save' )->willThrowException( new RuntimeException() );
		$this->expectException( RuntimeException::class );
		( new Service( $repo ) )->create( [] );
	}

	public function test_stub_only() {
		$repo = $this->createStub( Repo::class );
		$repo->method( 'find' )->willReturn( null );
		$this->assertNull( ( new Service( $repo ) )->find( 1 ) );
	}

	public function test_mock_and_same_class_assertion_helper() {
		$repo = $this->createMock( Repo::class );
		$repo->expects( $this->once() )->method( 'save' );
		$this->run_and_check_saved( new Service( $repo ) );
	}

	private function run_and_check_saved( $service ) {
		$this->assertTrue( $service->create( [] ) );
	}
}
