<?php

namespace Saltus\WP\Framework\Infrastructure\Container;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/** Instantiates classes by resolving constructor parameters from dependencies. */
final class ReflectionInstantiator implements Instantiator {

	/**
	 * Instantiate a class using named, positional, or compatible dependencies.
	 *
	 * @param class-string $target_class
	 * @param array<mixed> $dependencies
	 * @return object
	 */
	public function instantiate( string $target_class, array $dependencies = [] ): object {
		$reflection = new ReflectionClass( $target_class );

		$constructor = $reflection->getConstructor();
		if ( $constructor === null ) {
			return $reflection->newInstance();
		}

		$arguments = [];
		foreach ( $constructor->getParameters() as $position => $parameter ) {
			if ( $parameter->isVariadic() ) {
				$arguments = array_merge( $arguments, $this->resolve_variadic( $dependencies, $position ) );
				break;
			}
			$arguments[] = $this->resolve_parameter( $parameter, $position, $dependencies, $target_class );
		}

		return $reflection->newInstanceArgs( $arguments );
	}

	/**
	 * @param array<mixed> $dependencies
	 * @return list<mixed>
	 */
	private function resolve_variadic( array $dependencies, int $position ): array {
		$values = [];
		foreach ( $dependencies as $key => $value ) {
			if ( is_int( $key ) && $key >= $position ) {
				$values[] = $value;
			}
		}
		return $values;
	}

	/**
	 * @param array<mixed> $dependencies
	 * @return mixed
	 */
	private function resolve_parameter( ReflectionParameter $parameter, int $position, array $dependencies, string $target_class ) {
		$name = $parameter->getName();
		if ( array_key_exists( $name, $dependencies ) ) {
			return $dependencies[ $name ];
		}
		if ( array_key_exists( $position, $dependencies ) ) {
			return $dependencies[ $position ];
		}

		$type_match = $this->find_type_match( $parameter, $dependencies );
		if ( $type_match['found'] ) {
			return $type_match['value'];
		}
		if ( $name === 'dependencies' && $this->is_array_parameter( $parameter ) ) {
			return $dependencies;
		}
		if ( $parameter->isDefaultValueAvailable() ) {
			return $parameter->getDefaultValue();
		}
		if ( $parameter->allowsNull() ) {
			return null;
		}

		throw FailedToMakeInstance::for_unresolved_argument( $name, $target_class ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output.
	}

	/**
	 * @param array<mixed> $dependencies
	 * @return array{found: bool, value: mixed}
	 */
	private function find_type_match( ReflectionParameter $parameter, array $dependencies ): array {
		$type = $parameter->getType();
		if ( ! $type instanceof ReflectionNamedType || $type->isBuiltin() ) {
			return [
				'found' => false,
				'value' => null,
			];
		}
		$class_name = $type->getName();
		foreach ( $dependencies as $dependency ) {
			if ( is_object( $dependency ) && is_a( $dependency, $class_name ) ) {
				return [
					'found' => true,
					'value' => $dependency,
				];
			}
		}
		return [
			'found' => false,
			'value' => null,
		];
	}

	private function is_array_parameter( ReflectionParameter $parameter ): bool {
		$type = $parameter->getType();
		return $type instanceof ReflectionNamedType && $type->isBuiltin() && $type->getName() === 'array';
	}
}
