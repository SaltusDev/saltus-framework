<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Features\WebMcp\AdminScreen;
use Saltus\WP\Framework\Features\WebMcp\AdminToolSet;
use Saltus\WP\Framework\Features\WebMcp\WebMcp;
use Saltus\WP\Framework\Features\WebMcp\WebMcpPolicy;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\WebMcp\ManifestBuilder;
use Saltus\WP\Framework\WebMcp\ToolDescriptor;
use Saltus\WP\Framework\WebMcp\WebMcpTool;

/**
 * Inspects the WebMCP browser surface offline.
 *
 * The surface is otherwise only observable from inside a browser that implements
 * an API Chrome ships no earlier than 157 — which makes a misconfiguration
 * indistinguishable from an unsupported browser. These commands render the same
 * descriptors the bridge would receive, without one.
 *
 * Defines no __invoke() on purpose. WP_CLI reflects on that method to decide a
 * command's kind, and a class that has one becomes a Subcommand whose own public
 * methods are never registered — `wp saltus webmcp validate` would parse
 * "validate" as a positional argument and silently run the manifest instead,
 * so the check that exits non-zero on a broken surface could never fail.
 */
final class WebMcpCommand extends AbstractCommand {

	/**
	 * Print the tool descriptors for a surface.
	 *
	 * ## OPTIONS
	 *
	 * [--surface=<surface>]
	 * : Which surface to project. frontend (default) or admin.
	 *
	 * [--screen=<screen>]
	 * : Admin screen to scope to: post_editor, post_list, settings, review_queue.
	 *
	 * [--format=<format>]
	 * : table (default), json, or yaml.
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function manifest( array $args, array $assoc_args ): void {
		$modeler = $this->modeler();
		$surface = $this->surface( $assoc_args );
		$tools   = $this->tools( $modeler, $surface, $assoc_args );

		if ( $tools === [] ) {
			$this->cli->line( 'No WebMCP tools are exposed for the ' . $surface . ' surface.' );

			return;
		}

		$rows = [];
		foreach ( ( new ManifestBuilder() )->build( $tools ) as $descriptor ) {
			$rows[] = $this->row( $descriptor );
		}

		$this->cli->format_items( $this->format( $assoc_args ), $rows, [ 'tool', 'read_only', 'required', 'parameters', 'description' ] );
	}

	/**
	 * Report whether the surface is coherent, exiting non-zero when it is not.
	 *
	 * Four failures are worth catching before a browser ever sees them: a tool
	 * whose name exceeds the agent budget is silently truncated and becomes
	 * uncallable; a model enabling frontend WebMCP while not publicly queryable
	 * is configured for a surface it can never serve; an allowlist naming a tool
	 * that does not exist is a typo that fails open, exposing everything the
	 * author meant to narrow; and an admin-enabled site with no review queue
	 * would accept writes with nothing to govern them.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default), json, or yaml.
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function validate( array $args, array $assoc_args ): void {
		$modeler = $this->modeler();
		$policy  = new WebMcpPolicy( $modeler );
		$issues  = array_merge(
			$this->name_issues( $modeler ),
			$this->model_issues( $modeler, $policy ),
			$this->allowlist_issues( $modeler, $policy )
		);

		if ( $issues === [] ) {
			$this->cli->success( 'WebMCP surface is valid: ' . count( $policy->enabled_models() ) . ' model(s) enabled.' );

			return;
		}

		$this->cli->format_items( $this->format( $assoc_args ), $issues, [ 'severity', 'subject', 'issue' ] );
		$this->fail( count( $issues ) . ' WebMCP issue(s) found.' );
	}

	/**
	 * Tools whose names exceed the agent name budget.
	 *
	 * @param Modeler $modeler Model registry.
	 * @return list<array<string, string>>
	 */
	private function name_issues( Modeler $modeler ): array {
		$issues  = [];
		$feature = new WebMcp( [ 'modeler' => $modeler ] );

		foreach ( array_merge( $feature->build_tools( $modeler ), $feature->build_admin_tools( $modeler ) ) as $tool ) {
			$name = $tool->get_name();
			if ( strlen( $name ) > ManifestBuilder::MAX_NAME ) {
				$issues[] = [
					'severity' => 'error',
					'subject'  => $name,
					'issue'    => 'Name exceeds ' . ManifestBuilder::MAX_NAME . ' characters and would be dropped from the manifest.',
				];
			}
		}

		return $issues;
	}

	/**
	 * Models configured for a surface they cannot serve.
	 *
	 * @param Modeler      $modeler Model registry.
	 * @param WebMcpPolicy $policy  Gating policy.
	 * @return list<array<string, string>>
	 */
	private function model_issues( Modeler $modeler, WebMcpPolicy $policy ): array {
		$issues = [];

		foreach ( $modeler->get_models() as $name => $model ) {
			$model_name = (string) $name;
			$config     = $policy->config( $model_name );
			if ( $config === null || ! $config['enabled'] ) {
				continue;
			}

			if ( $model->get_type() !== 'post_type' ) {
				$issues[] = [
					'severity' => 'error',
					'subject'  => $model_name,
					'issue'    => 'Only post_type models can expose WebMCP tools; this model is a ' . $model->get_type() . '.',
				];
				continue;
			}

			if ( $config['frontend'] && ! in_array( $model_name, $policy->frontend_models(), true ) ) {
				$issues[] = [
					'severity' => 'warning',
					'subject'  => $model_name,
					'issue'    => 'frontend is enabled but the post type is not publicly queryable, so no frontend tools register.',
				];
			}

			if ( ! $config['frontend'] && ! $config['admin'] ) {
				$issues[] = [
					'severity' => 'warning',
					'subject'  => $model_name,
					'issue'    => 'webmcp.enabled is true but neither frontend nor admin is enabled, so nothing registers.',
				];
			}
		}

		return $issues;
	}

	/**
	 * Allowlist entries naming tools that do not exist.
	 *
	 * @param Modeler      $modeler Model registry.
	 * @param WebMcpPolicy $policy  Gating policy.
	 * @return list<array<string, string>>
	 */
	private function allowlist_issues( Modeler $modeler, WebMcpPolicy $policy ): array {
		$feature = new WebMcp( [ 'modeler' => $modeler ] );
		$known   = [];
		foreach ( array_merge( $feature->build_tools( $modeler ), $feature->build_admin_tools( $modeler ) ) as $tool ) {
			$known[ $tool->get_name() ] = true;
		}

		$issues = [];
		foreach ( $modeler->get_models() as $name => $_model ) {
			$config = $policy->config( (string) $name );
			if ( $config === null || $config['tools'] === [] ) {
				continue;
			}

			foreach ( $config['tools'] as $tool_name ) {
				if ( ! isset( $known[ $tool_name ] ) ) {
					$issues[] = [
						'severity' => 'error',
						'subject'  => (string) $name,
						'issue'    => 'Allowlist names unknown tool "' . $tool_name . '"; check for a typo.',
					];
				}
			}
		}

		return $issues;
	}

	/**
	 * Resolve the tools for a surface, optionally scoped to an admin screen.
	 *
	 * @param Modeler              $modeler    Model registry.
	 * @param string               $surface    Surface name.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return list<WebMcpTool>
	 */
	private function tools( Modeler $modeler, string $surface, array $assoc_args ): array {
		$feature = new WebMcp( [ 'modeler' => $modeler ] );
		$policy  = new WebMcpPolicy( $modeler );

		$candidates = $surface === WebMcpTool::SURFACE_ADMIN
			? $feature->build_admin_tools( $modeler )
			: $feature->build_tools( $modeler );

		$screen  = isset( $assoc_args['screen'] ) ? (string) $assoc_args['screen'] : '';
		$allowed = $surface === WebMcpTool::SURFACE_ADMIN && $screen !== ''
			? ( new AdminToolSet() )->for_screen( $this->screen( $screen ) )
			: null;

		$tools = [];
		foreach ( $candidates as $tool ) {
			$name = $tool->get_name();

			// Gated by the same policy the browser would see. Rendering the raw
			// tool set would report a surface on a site where no model opted in,
			// which is the opposite of what this command exists to tell you.
			if ( ! $policy->allowed_by_any_model( $name, $surface ) ) {
				continue;
			}

			if ( $allowed !== null && ! in_array( $name, $allowed, true ) ) {
				continue;
			}

			$tools[] = $tool;
		}

		return $tools;
	}

	/**
	 * Validate a screen name against the known screens.
	 *
	 * @param string $screen Requested screen.
	 */
	private function screen( string $screen ): string {
		$screens = [
			AdminScreen::POST_EDITOR,
			AdminScreen::POST_LIST,
			AdminScreen::SETTINGS,
			AdminScreen::REVIEW_QUEUE,
		];

		if ( ! in_array( $screen, $screens, true ) ) {
			$this->fail( 'Unknown screen "' . $screen . '". Expected one of: ' . implode( ', ', $screens ) . '.' );
		}

		return $screen;
	}

	/**
	 * Read the requested surface.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	private function surface( array $assoc_args ): string {
		$surface = strtolower( (string) ( $assoc_args['surface'] ?? WebMcpTool::SURFACE_FRONTEND ) );

		if ( ! in_array( $surface, [ WebMcpTool::SURFACE_FRONTEND, WebMcpTool::SURFACE_ADMIN ], true ) ) {
			$this->fail( 'Unknown surface "' . $surface . '". Expected frontend or admin.' );
		}

		return $surface;
	}

	/**
	 * Flatten one descriptor into a table row.
	 *
	 * @param ToolDescriptor $descriptor Descriptor to render.
	 * @return array<string, string>
	 */
	private function row( ToolDescriptor $descriptor ): array {
		$schema     = $descriptor->get_input_schema();
		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];
		$required   = is_array( $schema['required'] ?? null ) ? $schema['required'] : [];

		return [
			'tool'        => $descriptor->get_name(),
			'read_only'   => ! empty( $descriptor->get_annotations()['readOnlyHint'] ) ? 'yes' : 'no',
			'required'    => $required === [] ? '-' : implode( ', ', array_map( 'strval', $required ) ),
			'parameters'  => $properties === [] ? '-' : implode( ', ', array_map( 'strval', array_keys( $properties ) ) ),
			'description' => $descriptor->get_description(),
		];
	}
}
