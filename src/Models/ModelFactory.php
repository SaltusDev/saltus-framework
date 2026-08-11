<?php

namespace Saltus\WP\Framework\Models;

use Noodlehaus\AbstractConfig;
use Saltus\WP\Framework\Infrastructure\Container\Container;
use Saltus\WP\Framework\Infrastructure\Service\Processable;
use Saltus\WP\Framework\Features\Frontend\SaltusFrontend;

/**
 * @api
 */
class ModelFactory {

	/** @var Container<string, mixed> */
	protected Container $app;

	/** @var array<string, mixed> */
	protected array $project;

	private const POST     = 'post';
	private const TAXONOMY = 'taxonomy';

	/**
	 * Constructor.
	 *
	 * @param Container<string, mixed> $app     The application instance.
	 * @param array<string, mixed>     $project The project data.
	 */
	public function __construct( Container $app, array $project ) {
		$this->app     = $app;
		$this->project = $project;
	}

	/**
	 * Create a new model instance based on the provided configuration.
	 *
	 * @param AbstractConfig $config The configuration for the model.
	 *
	 * @return Model|null The created model instance or null if the type is not recognized.
	 */
	public function create( AbstractConfig $config ): ?Model {

		// soft fail
		if ( ! $config->has( 'type' ) ) {
			return null;
		}

		$type = $config->get( 'type' );
		// Map type strings to class handlers
		$type_map = [
			'post-type' => self::POST,
			'cpt'       => self::POST,
			'posttype'  => self::POST,
			'post_type' => self::POST,
			'taxonomy'  => self::TAXONOMY,
			'tax'       => self::TAXONOMY,
			'category'  => self::TAXONOMY,
			'cat'       => self::TAXONOMY,
			'tag'       => self::TAXONOMY,
		];

		$canonical = $type_map[ $type ] ?? null;
		if ( ! $canonical ) {
			// invalid type
			return null;
		}

		if ( $canonical === self::POST ) {
			$cpt = new PostType( $config );
			$cpt->setup();

			$this->process_services( $cpt, $config );

			// disable block editor only if 'block_editor' is false
			if ( $config->has( 'block_editor' ) && ! $config->get( 'block_editor' ) ) {
				add_filter( 'use_block_editor_for_post_type', [ $cpt, 'disable_block_editor' ], 10, 2 );
			}
			return $cpt;

		}
		$taxonomy = new Taxonomy( $config );
		$taxonomy->setup();
		return $taxonomy;
	}

	private function process_services( PostType $cpt, AbstractConfig $config ): void {
		$services = [ 'frontend', 'meta', 'settings' ];
		foreach ( $services as $service_name ) {
			if ( ! $config->has( $service_name ) || ! $this->app->has( $service_name ) ) {
				continue;
			}

			$config_value = $config->get( $service_name );
			if ( $service_name === 'frontend' && ! $config_value ) {
				continue;
			}
			$config_value = is_array( $config_value ) ? $config_value : [];
			$service      = $this->app->get( $service_name );
			$service_imp  = $service::make( $cpt->get_registration_name(), $this->project, $config_value );
			if ( $service_imp instanceof SaltusFrontend ) {
				$service_imp->set_model( $cpt );
			}

			if ( $service_imp instanceof Processable ) {
				$service_imp->process();
			}
		}

		$this->process_features( $cpt, $config );
	}

	private function process_features( PostType $cpt, AbstractConfig $config ): void {
		$service_name = 'features';
		if ( ! $config->has( $service_name ) ) {
			return;
		}

		$features = $config->get( $service_name );

		if ( ! is_iterable( $features ) ) {
			return;
		}

		foreach ( $features as $feature_name => $args ) {
			if ( ! $args ) {
				continue;
			}

			$normalized_feature_name = strtolower( $feature_name );

			// Feature is not available
			if ( ! $this->app->has( $normalized_feature_name ) ) {
				continue;
			}

			$service = $this->app->get( $normalized_feature_name );

			// Not every registered service is a per-model feature. `blocks`, for one,
			// is a container id but has no `make()` — it is a *top-level* config key,
			// and writing it under `features` used to raise a fatal
			// `Call to undefined method`. A misplaced key is an authoring mistake, so
			// it is skipped like any unavailable feature rather than crashing the site.
			if ( ! method_exists( $service, 'make' ) ) {
				continue;
			}

			// make sure $args is an array
			$args        = is_array( $args ) ? $args : [];
			$service_imp = $service::make( $cpt->get_registration_name(), $this->project, $args );

			if ( $service_imp instanceof Processable ) {
				$service_imp->process();
			}
		}
	}
}
