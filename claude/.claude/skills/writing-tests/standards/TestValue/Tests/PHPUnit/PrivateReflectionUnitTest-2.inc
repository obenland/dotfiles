<?php

class Good_PrivateReflection_Test extends Project_TestCase {

	public function test_public_api() {
		$foo = new Foo();
		$this->assertSame( 1, $foo->public_result() );
	}

	public function test_reflection_class_for_attributes() {
		$ref = new ReflectionClass( Foo::class );
		$this->assertTrue( $ref->isFinal() );
	}

	public function test_mock_method_named_get_method() {
		$request = $this->createMock( Request::class );
		$request->method( 'getMethod' )->willReturn( 'POST' );
		$this->assertSame( 'POST', handle( $request ) );
	}

	// Back-test FPs (2026-10): writing private state to inject a double or reset a cache is a seam.
	public function test_injects_a_stub_into_a_private_property() {
		$handler = new Handler();
		$prop    = new ReflectionProperty( Handler::class, 'glossary' );
		$prop->setAccessible( true );
		$prop->setValue( $handler, $this->createStub( Glossary::class ) );
		$this->assertSame( 'x', $handler->handle( 'x' ) );
	}

	public function test_swaps_a_singleton_and_restores_it() {
		$instance = ( new ReflectionClass( Catalog::class ) )->getProperty( 'instance' );
		$instance->setAccessible( true );
		$original = $instance->getValue();
		$instance->setValue( null, $this->createStub( Catalog::class ) );
		try {
			$this->assertSame( 1, lookup_count() );
		} finally {
			$instance->setValue( null, $original );
		}
	}

	public function test_reads_a_default_parameter_value() {
		$method = new ReflectionMethod( Reader::class, 'app' );
		$this->assertSame( [ 'a' ], $method->getParameters()[0]->getDefaultValue() );
	}
}

class Not_A_Test_Helper {
	public function peek( $object ) {
		$m = new ReflectionMethod( $object, 'secret' );
		$m->setAccessible( true );
		return $m->invoke( $object );
	}
}
