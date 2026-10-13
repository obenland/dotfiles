<?php

class Bad_MockOnlyVerification_Test extends Project_TestCase {

	public function test_calls_logger() { // WARN
		$logger = $this->createMock( Logger::class );
		$logger->expects( $this->once() )->method( 'log' )->with( 'saved' );
		( new Saver( $logger ) )->save( [] );
	}

	public function test_expects_with_trivial_assert() { // WARN
		$mailer = $this->createMock( Mailer::class );
		$mailer->expects( $this->never() )->method( 'send' );
		( new Notifier( $mailer ) )->notify( null );
		$this->assertTrue( true );
	}
}
