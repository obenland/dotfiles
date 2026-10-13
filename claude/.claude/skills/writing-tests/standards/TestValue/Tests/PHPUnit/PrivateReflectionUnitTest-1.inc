<?php

class Bad_PrivateReflection_Test extends Project_TestCase {

	public function test_private_method() {
		$method = new ReflectionMethod( Foo::class, 'secret' ); // WARN
		$method->setAccessible( true );
		$this->assertSame( 1, $method->invoke( new Foo() ) );
	}

	public function test_private_property() {
		$prop = new \ReflectionProperty( Foo::class, 'cache' ); // WARN
		$this->assertSame( [], $prop->getValue( new Foo() ) );
	}

	public function test_via_reflection_class() {
		$ref    = new ReflectionClass( Foo::class );
		$method = $ref->getMethod( 'secret' ); // WARN
		$this->assertSame( 1, $method->invoke( new Foo() ) );
	}

	public function test_closure_bind() {
		$peek = Closure::bind( function () { // WARN
			return $this->cache;
		}, new Foo(), Foo::class );
		$this->assertSame( [], $peek() );
	}

	private function call_private( $object, $name ) {
		$ref = new ReflectionClass( $object );
		$m   = $ref->getMethod( $name ); // WARN
		$m->setAccessible( true );
		return $m->invoke( $object );
	}
}
