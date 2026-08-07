<?php

namespace Saltus\WP\Framework\Features\EditorialReview;

use Saltus\WP\Framework\Infrastructure\Plugin\Registerable;
use Saltus\WP\Framework\Infrastructure\Service\Service;
use Saltus\WP\Framework\Modeler;
use Saltus\WP\Framework\Rest\EditorialReviewController;
use Saltus\WP\Framework\Rest\ModelRestPolicy;
use Saltus\WP\Framework\Rest\RestRouteDefinition;
use Saltus\WP\Framework\Rest\RestRouteProvider;

/** Registers the AI editorial review queue and its admin screen. */
final class EditorialReview implements Service, Registerable, RestRouteProvider {
	private ProposalService $proposals;

	/** @param array<string, mixed> $_dependencies */
	public function __construct( array $_dependencies = [] ) {
		unset( $_dependencies );
		$this->proposals = new ProposalService();
	}

	/** @return list<RestRouteDefinition> */
	public function get_rest_routes( Modeler $modeler, ModelRestPolicy $policy ): array {
		return [ new RestRouteDefinition( ModelRestPolicy::CAPABILITY_HEALTH, new EditorialReviewController( $this->proposals ) ) ];
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_admin_page' ] ); }

	public function register_admin_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		add_management_page( __( 'AI Review Queue', 'saltus-framework' ), __( 'AI Review Queue', 'saltus-framework' ), 'edit_posts', 'saltus-ai-review', [ $this, 'render_admin_page' ] );
	}

	public function render_admin_page(): void {
		$items = $this->proposals->list( 'pending' );
		?>
		<div class="wrap"><h1><?php echo esc_html__( 'AI Review Queue', 'saltus-framework' ); ?></h1>
		<p><?php echo esc_html__( 'Review proposed AI changes before they are applied.', 'saltus-framework' ); ?></p>
		<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'Proposal', 'saltus-framework' ); ?></th><th><?php echo esc_html__( 'Model', 'saltus-framework' ); ?></th><th><?php echo esc_html__( 'Change', 'saltus-framework' ); ?></th><th><?php echo esc_html__( 'Actions', 'saltus-framework' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $items as $item ) :
			$diff = is_array( $item['changeset'] ?? null ) ? $item['changeset'] : [];
			?>
			<tr><td>#<?php echo (int) $item['id']; ?><br><small><?php echo esc_html( (string) $item['created_at'] ); ?></small></td><td><?php echo esc_html( (string) $item['model'] ); ?></td><td><pre><?php echo esc_html( (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $diff, JSON_PRETTY_PRINT ) : json_encode( $diff ) /* phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode */ ) ); ?></pre></td><td><button class="button button-primary" data-saltus-review="approve" data-proposal="<?php echo (int) $item['id']; ?>"><?php echo esc_html__( 'Approve', 'saltus-framework' ); ?></button> <button class="button" data-saltus-review="reject" data-proposal="<?php echo (int) $item['id']; ?>"><?php echo esc_html__( 'Reject', 'saltus-framework' ); ?></button></td></tr>
			<?php
		endforeach; if ( ! $items ) :
			?>
			<tr><td colspan="4"><?php echo esc_html__( 'No pending proposals.', 'saltus-framework' ); ?></td></tr><?php endif; ?></tbody></table>
		<script>document.querySelectorAll('[data-saltus-review]').forEach(function(button){button.addEventListener('click',function(){var action=button.dataset.saltusReview;fetch('<?php echo esc_url_raw( rest_url( 'saltus-framework/v1/proposals/' ) ); ?>'+button.dataset.proposal+'/'+action,{method:'PUT',headers:{'X-WP-Nonce':'<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>','Content-Type':'application/json'},body:JSON.stringify({})}).then(function(){window.location.reload();});});});</script></div>
		<?php
	}
}
