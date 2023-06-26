<?php
namespace Saltus\WP\Framework\Infrastructure\Service;

use Saltus\WP\Framework\Infrastructure\Container\{
	CanRegister,
	Container,
	ServiceContainer,
};


/**
 * A simplified implementation of a service container.
 *
 * Extend ArrayObject to have default implementations for iterators and
 * array access.
 *
 * @deprecated 0.1.1 Use Infrastructure/Container
 */
final class App
	extends ServiceContainer
	implements Container, CanRegister {

}
