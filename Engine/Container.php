<?php
/**
 * Dependency injection container.
 *
 * Resolves constructor dependencies through reflection and keeps one shared
 * instance per class. Same approach as the other PrimePlaced plugins.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Engine;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * Class Container
 */
final class Container {

	/**
	 * Shared instances keyed by class name.
	 *
	 * @var array<class-string, object>
	 */
	private array $instances = array();

	/**
	 * Factories for classes that need custom construction.
	 *
	 * @var array<class-string, callable(Container): object>
	 */
	private array $factories = array();

	/**
	 * Registers a factory for a class or interface.
	 *
	 * @param class-string                $class_name Class or interface name.
	 * @param callable(Container): object $factory    Factory returning the instance.
	 * @return void
	 */
	public function set_factory( string $class_name, callable $factory ): void {
		$this->factories[ $class_name ] = $factory;
	}

	/**
	 * Returns the shared instance of a class, building it on first use.
	 *
	 * @template T of object
	 * @param class-string<T> $class_name Class name.
	 * @throws RuntimeException If the class cannot be built.
	 * @return T
	 */
	public function get( string $class_name ): object {
		if ( ! isset( $this->instances[ $class_name ] ) ) {
			$this->instances[ $class_name ] = isset( $this->factories[ $class_name ] )
				? ( $this->factories[ $class_name ] )( $this )
				: $this->auto_wire( $class_name );
		}

		$instance = $this->instances[ $class_name ];
		if ( ! $instance instanceof $class_name ) {
			throw new RuntimeException( esc_html( sprintf( 'Container entry for %s has the wrong type.', $class_name ) ) );
		}

		return $instance;
	}

	/**
	 * Builds a class by resolving its constructor parameters.
	 *
	 * @param class-string $class_name Class name.
	 * @throws RuntimeException If the class does not exist, is not instantiable or has an unresolvable parameter.
	 * @return object
	 */
	private function auto_wire( string $class_name ): object {
		if ( ! class_exists( $class_name ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Class %s does not exist.', $class_name ) ) );
		}

		$reflection = new ReflectionClass( $class_name );
		if ( ! $reflection->isInstantiable() ) {
			throw new RuntimeException( esc_html( sprintf( 'Class %s is not instantiable.', $class_name ) ) );
		}

		$constructor = $reflection->getConstructor();
		if ( null === $constructor ) {
			return $reflection->newInstance();
		}

		$arguments = array_map(
			fn ( ReflectionParameter $parameter ) => $this->resolve_parameter( $class_name, $parameter ),
			$constructor->getParameters()
		);

		return $reflection->newInstanceArgs( $arguments );
	}

	/**
	 * Resolves one constructor parameter.
	 *
	 * @param string              $class_name Class being built, for error messages.
	 * @param ReflectionParameter $parameter  Parameter to resolve.
	 * @throws RuntimeException If the parameter has no class type and no default value.
	 * @return mixed
	 */
	private function resolve_parameter( string $class_name, ReflectionParameter $parameter ): mixed {
		$type = $parameter->getType();
		if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
			/**
			 * Non-builtin named types are class or interface names.
			 *
			 * @var class-string $dependency
			 */
			$dependency = $type->getName();
			return $this->get( $dependency );
		}

		if ( $parameter->isDefaultValueAvailable() ) {
			return $parameter->getDefaultValue();
		}

		throw new RuntimeException( esc_html( sprintf( 'Cannot resolve parameter $%s of %s.', $parameter->getName(), $class_name ) ) );
	}
}
