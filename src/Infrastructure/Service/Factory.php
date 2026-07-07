<?php
namespace Saltus\WP\Framework\Infrastructure\Service;

interface Factory {
	/**
	 * Create a new resource that can return its instance
	 *
	 * @param array<int, mixed> $args Constructor arguments.
	 * @return mixed The result of the creation process.
	 */
	public function create( string $class_name, array $args = [] );
}
