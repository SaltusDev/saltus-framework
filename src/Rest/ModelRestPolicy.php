<?php

namespace Saltus\WP\Framework\Rest;

use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Models\Model;

class ModelRestPolicy {

	public const CAPABILITY_MODELS    = 'models';
	public const CAPABILITY_META      = 'meta';
	public const CAPABILITY_SETTINGS  = 'settings';
	public const CAPABILITY_DUPLICATE = 'duplicate';
	public const CAPABILITY_EXPORT    = 'export';
	public const CAPABILITY_REORDER   = 'reorder';
	public const CAPABILITY_HEALTH    = 'health';

	private Modeler $modeler;

	public function __construct( Modeler $modeler ) {
		$this->modeler = $modeler;
	}

	public function has_capability( string $capability, ?string $model_type = null ): bool {
		if ( $capability === self::CAPABILITY_HEALTH ) {
			return true;
		}

		foreach ( $this->modeler->get_models() as $model ) {
			if ( $model_type !== null && $model->get_type() !== $model_type ) {
				continue;
			}

			if ( $this->is_enabled( $model, $capability ) ) {
				return true;
			}
		}

		return false;
	}

	public function is_enabled( Model $model, string $capability ): bool {
		$options = $this->get_model_options( $model );

		if ( array_key_exists( 'show_in_rest', $options ) && $options['show_in_rest'] === false ) {
			return false;
		}

		$saltus_rest = $options['saltus_rest'] ?? false;
		if ( $saltus_rest === true ) {
			return true;
		}

		if ( ! is_array( $saltus_rest ) ) {
			return false;
		}

		return ! empty( $saltus_rest[ $capability ] );
	}

	public function is_post_type_enabled( string $post_type, string $capability ): bool {
		$model = $this->get_model( $post_type );

		return $model !== null
			&& $model->get_type() === 'post_type'
			&& $this->is_enabled( $model, $capability );
	}

	public function is_post_enabled( int $post_id, string $capability ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		return $this->is_post_type_enabled( (string) $post->post_type, $capability );
	}

	public function get_model( string $name ): ?Model {
		$models = $this->modeler->get_models();

		return $models[ $name ] ?? null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_model_args( Model $model ): array {
		if ( method_exists( $model, 'get_args' ) ) {
			$args = $model->get_args();
			return is_array( $args ) ? $args : [];
		}

		return property_exists( $model, 'args' ) && is_array( $model->args ) ? $model->args : [];
	}

	/**
	 * @return array<string, Model>
	 */
	public function get_enabled_models( string $capability, ?string $model_type = null ): array {
		$enabled = [];

		foreach ( $this->modeler->get_models() as $name => $model ) {
			if ( $model_type !== null && $model->get_type() !== $model_type ) {
				continue;
			}

			if ( $this->is_enabled( $model, $capability ) ) {
				$enabled[ $name ] = $model;
			}
		}

		return $enabled;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_model_options( Model $model ): array {
		if ( method_exists( $model, 'get_options' ) ) {
			$options = $model->get_options();
			return is_array( $options ) ? $options : [];
		}

		return property_exists( $model, 'options' ) && is_array( $model->options ) ? $model->options : [];
	}
}
