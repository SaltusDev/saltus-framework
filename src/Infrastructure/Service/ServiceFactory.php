<?php
namespace Saltus\WP\Framework\Infrastructure\Service;

use Saltus\WP\Framework\Infrastructure\Service\Service;

class ServiceFactory implements Service, Factory {

	public function create( string $class_name, $args = [] ) {
		if ( ! class_exists( $class_name ) ) {
			throw new \InvalidArgumentException( esc_html( "Class $class_name does not exist." ) );
		}

		$reflection = new \ReflectionClass( $class_name );

		return $reflection->newInstanceArgs( $args );
	}
}
