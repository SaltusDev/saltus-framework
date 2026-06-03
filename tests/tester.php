<?php

use Saltus\WP\Framework\Infrastructure\Container\ContainerAssembler;

use Saltus\WP\Framework\Infrastructure\Container\GenericContainer;
use Saltus\WP\Framework\Infrastructure\Container\ServiceContainer;

echo "start\n";

require_once '/srv/www/globes-dev.test/htdocs/wp-load.php';

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( class_exists( \Saltus\WP\Framework\Core::class ) ) {
	echo "\nyes core\n";
}

/*
* The path to the plugin root directory is mandatory,
* so it loads the models from a subdirectory.
*/
$framework = new \Saltus\WP\Framework\Core( __DIR__ );
$framework->register();

var_dump( $framework->get_container()->count() );

$assembler = new ContainerAssembler( 'a' );
$container = $assembler->create( GenericContainer::class );

$container->put( '1', 'a' );

var_dump( $container->count() );
$feature_list = [
	\Saltus\WP\Framework\Features\AdminCols\AdminCols::class => [],
	\Saltus\WP\Framework\Features\AdminFilters\AdminFilters::class => [],
	\Saltus\WP\Framework\Features\DragAndDrop\DragAndDrop::class => [],
	\Saltus\WP\Framework\Features\Duplicate\Duplicate::class => [],
	\Saltus\WP\Framework\Features\Meta\Meta::class         => [],
	\Saltus\WP\Framework\Features\QuickEdit\QuickEdit::class => [],
	\Saltus\WP\Framework\Features\RememberTabs\RememberTabs::class => [],
	\Saltus\WP\Framework\Features\Settings\Settings::class => [],
	\Saltus\WP\Framework\Features\SingleExport\SingleExport::class => [],
];

$features = $assembler->create( ServiceContainer::class );


foreach ( $feature_list as $class => $dependencies ) {
	echo "Registering feature: $class\n";
	$features->register( $class, $class, $dependencies );
}

echo "Registered features:\n";
var_dump( $features->count() );
echo "done\n";
