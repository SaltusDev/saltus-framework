<?php

namespace Saltus\WP\Framework\Features\Workflow;

use Saltus\WP\Framework\Models\Model;

/**
 * Parses `workflow` out of model configs and validates it.
 *
 * A transition that names a state nobody declared is rejected at load time —
 * the alternative is a button that silently does nothing in production.
 *
 * @api
 */
final class WorkflowRegistry {

	/**
	 * Registered workflows, keyed by model.
	 *
	 * @var array<string, WorkflowDefinition>
	 */
	private array $workflows = [];

	/**
	 * Register the workflow declared by one model, if any.
	 *
	 * @throws InvalidWorkflow If the config is malformed.
	 */
	public function register_from_model( Model $model ): ?WorkflowDefinition {
		$config = $model->get_config()['workflow'] ?? [];
		if ( ! is_array( $config ) || $config === [] ) {
			return null;
		}

		// `enabled: false` is how a model keeps a workflow in config without
		// applying it, so it is a skip rather than an error.
		if ( array_key_exists( 'enabled', $config ) && ! $config['enabled'] ) {
			return null;
		}

		return $this->register( $this->model_name( $model ), $config );
	}

	/**
	 * Validate and register a workflow for a named model.
	 *
	 * @param array<string, mixed> $config Raw `workflow` config.
	 *
	 * @throws InvalidWorkflow If the config is malformed.
	 */
	public function register( string $model_name, array $config ): WorkflowDefinition {
		$states      = $this->parse_states( $model_name, $config['states'] ?? [] );
		$transitions = $this->parse_transitions( $model_name, $config['transitions'] ?? [], $states );

		$definition = new WorkflowDefinition( $model_name, $states, $transitions );

		$this->workflows[ $model_name ] = $definition;

		return $definition;
	}

	/**
	 * @param mixed $raw
	 *
	 * @return array<string, WorkflowState>
	 *
	 * @throws InvalidWorkflow If a state is malformed or duplicated.
	 */
	private function parse_states( string $model_name, $raw ): array {
		if ( ! is_array( $raw ) || $raw === [] ) {
			throw InvalidWorkflow::from_missing_states( $model_name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output.
		}

		$states = [];
		foreach ( $raw as $key => $settings ) {
			$state = $this->parse_state( $model_name, $key, $settings );
			if ( isset( $states[ $state->slug ] ) ) {
				throw InvalidWorkflow::from_duplicate_state( $model_name, $state->slug ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output.
			}
			$states[ $state->slug ] = $state;
		}

		return $states;
	}

	/**
	 * Build one state.
	 *
	 * Accepts both list form (`- slug: draft`) and map form (`draft: {...}`),
	 * because model configs in this framework are written both ways.
	 *
	 * @param int|string $key
	 * @param mixed      $settings
	 *
	 * @throws InvalidWorkflow If the state is malformed.
	 */
	private function parse_state( string $model_name, $key, $settings ): WorkflowState {
		if ( is_string( $settings ) ) {
			// Shorthand: a bare slug with no settings.
			return new WorkflowState( $settings, $this->humanize( $settings ) );
		}

		if ( ! is_array( $settings ) ) {
			throw InvalidWorkflow::from_malformed_state( $model_name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output.
		}

		$slug = isset( $settings['slug'] ) ? (string) $settings['slug'] : ( is_string( $key ) ? $key : '' );
		if ( $slug === '' ) {
			throw InvalidWorkflow::from_missing_state_slug( $model_name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output.
		}

		return new WorkflowState(
			$slug,
			isset( $settings['label'] ) ? (string) $settings['label'] : $this->humanize( $slug ),
			(bool) ( $settings['public'] ?? false ),
			isset( $settings['notification'] ) ? (string) $settings['notification'] : ''
		);
	}

	/**
	 * @param mixed                        $raw
	 * @param array<string, WorkflowState> $states
	 *
	 * @return array<string, WorkflowTransition>
	 *
	 * @throws InvalidWorkflow If a transition is malformed or references an unknown state.
	 */
	private function parse_transitions( string $model_name, $raw, array $states ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$transitions = [];
		foreach ( $raw as $key => $settings ) {
			$name = is_string( $key ) ? $key : (string) ( is_array( $settings ) ? ( $settings['name'] ?? '' ) : '' );
			if ( $name === '' ) {
				continue;
			}
			$transitions[ $name ] = $this->parse_transition( $model_name, $name, $settings, $states );
		}

		return $transitions;
	}

	/**
	 * @param mixed                        $settings
	 * @param array<string, WorkflowState> $states
	 *
	 * @throws InvalidWorkflow If the transition is malformed or references an unknown state.
	 */
	private function parse_transition( string $model_name, string $name, $settings, array $states ): WorkflowTransition {
		if ( ! is_array( $settings ) ) {
			throw InvalidWorkflow::from_malformed_transition( $model_name, $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output.
		}

		$to = isset( $settings['to'] ) ? (string) $settings['to'] : '';
		if ( $to === '' ) {
			throw InvalidWorkflow::from_missing_transition_target( $model_name, $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output.
		}
		if ( ! isset( $states[ $to ] ) ) {
			throw InvalidWorkflow::from_unknown_transition_state( $model_name, $name, $to ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output.
		}

		$from = $this->parse_from( $model_name, $name, $settings['from'] ?? [], $states );

		return new WorkflowTransition(
			$name,
			$from,
			$to,
			isset( $settings['capability'] ) ? (string) $settings['capability'] : 'edit_posts',
			isset( $settings['label'] ) ? (string) $settings['label'] : '',
			(bool) ( $settings['action_hook'] ?? false )
		);
	}

	/**
	 * @param mixed                        $raw
	 * @param array<string, WorkflowState> $states
	 *
	 * @return list<string>
	 *
	 * @throws InvalidWorkflow If a source state is unknown or absent.
	 */
	private function parse_from( string $model_name, string $name, $raw, array $states ): array {
		$from = is_array( $raw ) ? $raw : [ $raw ];
		$from = array_values( array_filter( array_map( 'strval', $from ), static fn( string $slug ): bool => $slug !== '' ) );

		if ( $from === [] ) {
			throw InvalidWorkflow::from_transition_without_source( $model_name, $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output.
		}

		foreach ( $from as $slug ) {
			if ( ! isset( $states[ $slug ] ) ) {
				throw InvalidWorkflow::from_unknown_transition_state( $model_name, $name, $slug ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output.
			}
		}

		return $from;
	}

	private function humanize( string $slug ): string {
		return ucfirst( str_replace( [ '_', '-' ], ' ', $slug ) );
	}

	private function model_name( Model $model ): string {
		if ( method_exists( $model, 'get_registration_name' ) ) {
			$name = (string) $model->get_registration_name();
			if ( $name !== '' ) {
				return $name;
			}
		}

		return $model->get_name();
	}

	public function get( string $model_name ): ?WorkflowDefinition {
		return $this->workflows[ $model_name ] ?? null;
	}

	/**
	 * @throws InvalidWorkflow If the model has no workflow.
	 */
	public function require( string $model_name ): WorkflowDefinition {
		$definition = $this->get( $model_name );
		if ( ! $definition instanceof WorkflowDefinition ) {
			throw InvalidWorkflow::from_unknown_model( $model_name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered as output.
		}

		return $definition;
	}

	public function has( string $model_name ): bool {
		return isset( $this->workflows[ $model_name ] );
	}

	/**
	 * Every model with a workflow.
	 *
	 * @return list<string>
	 */
	public function models(): array {
		return array_keys( $this->workflows );
	}

	/**
	 * @return array<string, WorkflowDefinition>
	 */
	public function all(): array {
		return $this->workflows;
	}
}
