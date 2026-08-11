<?php

namespace Saltus\WP\Framework\Features\Workflow;

use InvalidArgumentException;
use Saltus\WP\Framework\Exception\SaltusFrameworkThrowable;

/**
 * A workflow config that cannot be registered.
 *
 * Messages name the model and the offending state or transition, because these
 * surface at model load where there is no other context.
 */
final class InvalidWorkflow extends InvalidArgumentException implements SaltusFrameworkThrowable {

	public static function from_missing_states( string $model ): self {
		return new self(
			sprintf( 'Model "%s" enables a workflow but declares no states.', $model )
		);
	}

	public static function from_malformed_state( string $model ): self {
		return new self(
			sprintf( 'Model "%s" declares a workflow state that is not an array of settings.', $model )
		);
	}

	public static function from_missing_state_slug( string $model ): self {
		return new self(
			sprintf( 'Model "%s" declares a workflow state with no slug.', $model )
		);
	}

	public static function from_duplicate_state( string $model, string $slug ): self {
		return new self(
			sprintf( 'Model "%s" declares the workflow state "%s" more than once.', $model, $slug )
		);
	}

	public static function from_malformed_transition( string $model, string $name ): self {
		return new self(
			sprintf( 'Transition "%s" on model "%s" must be an array of settings.', $name, $model )
		);
	}

	public static function from_missing_transition_target( string $model, string $name ): self {
		return new self(
			sprintf( 'Transition "%s" on model "%s" is missing the required "to" state.', $name, $model )
		);
	}

	public static function from_unknown_transition_state( string $model, string $name, string $state ): self {
		return new self(
			sprintf(
				'Transition "%s" on model "%s" references undeclared state "%s".',
				$name,
				$model,
				$state
			)
		);
	}

	public static function from_transition_without_source( string $model, string $name ): self {
		return new self(
			sprintf( 'Transition "%s" on model "%s" declares no "from" states.', $name, $model )
		);
	}

	public static function from_unknown_model( string $model ): self {
		return new self(
			sprintf( 'Model "%s" has no workflow.', $model )
		);
	}

	public static function from_unknown_transition( string $model, string $name ): self {
		return new self(
			sprintf( 'Model "%s" has no transition named "%s".', $model, $name )
		);
	}
}
