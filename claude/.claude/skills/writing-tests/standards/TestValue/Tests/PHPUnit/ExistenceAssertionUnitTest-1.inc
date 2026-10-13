<?php

class Bad_ExistenceAssertion_Test extends Project_TestCase {

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'Foo_Service' ) ); // WARN
		$this->assertTrue( \class_exists( Foo_Service::class ) ); // WARN
	}

	public function test_methods_and_functions() {
		$this->assertTrue( method_exists( Foo_Service::class, 'run' ) ); // WARN
		self::assertTrue( function_exists( 'foo_helper' ), 'helper is loaded' ); // WARN
		$this->assertFalse( ! interface_exists( 'Foo_Interface' ) ); // WARN
		$this->assertTrue( trait_exists( 'Foo_Trait' ) ); // WARN
		$this->assertTrue( enum_exists( 'Foo_Enum' ) ); // WARN
		$this->assertTrue( property_exists( 'Foo', 'bar' ) ); // WARN
		$this->assertTrue( is_callable( [ 'Foo', 'bar' ] ) ); // WARN
		$this->assertTrue( defined( 'FOO_VERSION' ) ); // WARN
		$this->assertSame( true, true );
	}
}
