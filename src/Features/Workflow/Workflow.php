<?php

namespace Saltus\WP\Framework\Features\Workflow;

use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\MCP\Tools\ToolContributor;
use Saltus\WP\Framework\MCP\Tools\Workflow\GetWorkflowStates;
use Saltus\WP\Framework\MCP\Tools\Workflow\TransitionWorkflowState;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;
use Saltus\WP\Framework\Rest\WorkflowController;

/**
 * Registers custom editorial workflows declared in model configs.
 *
 * Opt-in per model: a model without a `workflow` block behaves exactly as before,
 * and draft/publish keep working for models that do declare one.
 */
final class Workflow implements Service, Registerable, RestRouteProvider, ToolContributor {

	private WorkflowRegistry $registry;
	private StateTransitioner $transitioner;
	private bool $models_loaded = false;

	/** @param array<string, mixed> $_dependencies */
	public function __construct( array $_dependencies = [] ) {
		unset( $_dependencies );

		$this->registry     = new WorkflowRegistry();
		$this->transitioner = new StateTransitioner( $this->registry );
	}

	/** @return list<RestRouteDefinition> */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		$this->load_models( $modeler );

		return [
			new RestRouteDefinition(
				ModelRestPolicy::CAPABILITY_HEALTH,
				new WorkflowController( $this->registry, $this->transitioner )
			),
		];
	}

	/**
	 * @param ModelRestPolicy|null $policy
	 *
	 * @return list<\Saltus\WP\Framework\MCP\Tools\ToolInterface>
	 */
	public function get_mcp_tools( Modeler $modeler, ?ModelRestPolicy $policy = null ): array {
		$this->load_models( $modeler );

		return [
			new GetWorkflowStates(),
			new TransitionWorkflowState(),
		];
	}

	public function register(): void {
		add_action( 'saltus/framework/workflow/notify', [ $this, 'send_notification' ], 10, 2 );
	}

	/**
	 * Notify the address a state declared.
	 *
	 * Uses wp_mail so a site's existing mail configuration applies. Filterable
	 * so a site can route to Slack or Teams instead without this class knowing
	 * about either.
	 *
	 * @param array<string, mixed> $payload Transition payload.
	 */
	public function send_notification( string $recipient, array $payload ): void {
		if ( $recipient === '' || ! function_exists( 'wp_mail' ) ) {
			return;
		}

		$notification = $this->filtered_notification(
			[
				'to'      => $recipient,
				'subject' => sprintf(
					'[%s] moved to %s',
					(string) ( $payload['model'] ?? 'content' ),
					(string) ( $payload['to'] ?? '' )
				),
				'body'    => sprintf(
					"Post %d moved from %s to %s.\n\n%s",
					(int) ( $payload['post_id'] ?? 0 ),
					(string) ( $payload['from'] ?? '' ),
					(string) ( $payload['to'] ?? '' ),
					(string) ( $payload['note'] ?? '' )
				),
			],
			$payload
		);

		if ( $notification['to'] === '' ) {
			return;
		}

		wp_mail( $notification['to'], $notification['subject'], $notification['body'] );
	}

	/**
	 * Let a site rewrite or cancel the notification.
	 *
	 * Filtering rather than hard-coding wp_mail is what lets a site route to
	 * Slack or Teams without this class knowing either exists.
	 *
	 * @param array{to: string, subject: string, body: string} $notification
	 * @param array<string, mixed>                             $payload
	 *
	 * @return array{to: string, subject: string, body: string}
	 */
	private function filtered_notification( array $notification, array $payload ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $notification;
		}

		/**
		 * Filter the workflow notification before it is sent.
		 *
		 * Return an empty `to` to cancel the mail.
		 *
		 * @param array<string, mixed> $notification Recipient, subject and body.
		 * @param array<string, mixed> $payload      The transition payload.
		 */
		$filtered = apply_filters( 'saltus/framework/workflow/notification', $notification, $payload );

		// Each key falls back to the unfiltered value, so a listener returning a
		// partial array cannot produce an empty subject or body.
		return [
			'to'      => (string) ( $filtered['to'] ?? $notification['to'] ),
			'subject' => (string) ( $filtered['subject'] ?? $notification['subject'] ),
			'body'    => (string) ( $filtered['body'] ?? $notification['body'] ),
		];
	}

	/**
	 * Read workflow declarations out of the loaded models, once.
	 *
	 * A malformed workflow costs that model its workflow rather than taking the
	 * site down at boot.
	 */
	public function load_models( Modeler $modeler ): void {
		if ( $this->models_loaded ) {
			return;
		}
		$this->models_loaded = true;

		foreach ( $modeler->get_models() as $model ) {
			try {
				$this->registry->register_from_model( $model );
			} catch ( InvalidWorkflow $exception ) {
				$this->report( $exception );
			}
		}
	}

	/**
	 * Surface a bad declaration where a developer will see it.
	 */
	private function report( InvalidWorkflow $exception ): void {
		$message = 'Saltus workflow: ' . $exception->getMessage();

		if ( function_exists( 'do_action' ) ) {
			do_action( 'saltus/framework/workflow/invalid', $exception );
		}

		if ( function_exists( 'add_action' ) && function_exists( 'is_admin' ) && is_admin() ) {
			add_action(
				'admin_notices',
				static function () use ( $message ): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html( $message )
					);
				}
			);
		}
	}

	public function registry(): WorkflowRegistry {
		return $this->registry;
	}

	public function transitioner(): StateTransitioner {
		return $this->transitioner;
	}
}
