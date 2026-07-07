<?php
namespace Saltus\WP\Framework\Features\SingleExport;

use Saltus\WP\Framework\Infrastructure\Service\{
	Processable
};

/**
 * Class SaltusSingleExport
 *
 * Enable an option to export single entry
 *
 * Adapted from trepmal's "Export One Post" at https://github.com/trepmal/export-one-post
 */
final class SaltusSingleExport implements Processable {

	/**
	 * @var string $name The name of the custom post type (CPT) to export.
	 */
	private $name;

	/**
	 * @var string $label The label for the export link.
	 */
	private $label;

	/**
	 * A constant representing a fake date used for filtering queries.
	 * Unlikely date match for filters
	 */
	const FAKE_DATE = '1970-01-05'; // Y-m-d

	/**
	 * Constructor.
	 *
	 * @param string                $name The name of the custom post type (CPT) to export.
	 * @param array<string, string> $args Optional. Additional arguments for the export.
	 *                         - 'label': The label for the export link.
	 */
	public function __construct( string $name, array $args = [] ) {
		$this->name  = $name;
		$this->label = ! empty( $args['label'] ) ? $args['label'] : 'Export This';
	}

	/**
	 * Process the export functionality by hooking into WordPress actions.
	 */
	public function process(): void {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Get hooked in: Part II
	 * Initialize the export functionality.
	 *
	 * Hooks into WordPress filters and actions to enable single entry export.
	 *
	 */
	public function init(): void {

		if ( ! current_user_can( 'export' ) ) {
			return;
		}

		add_filter( 'export_args', array( $this, 'export_args' ) );
		add_filter( 'posts_request', array( $this, 'query' ), 10, 2 );
		add_action( 'post_submitbox_misc_actions', array( $this, 'post_submitbox_misc_actions' ) );
	}

	/**
	 * Insert our action link into the submit box
	 *
	 * @param \WP_Post $post The current post object.
	 *
	 */
	public function post_submitbox_misc_actions( \WP_Post $post ): void {

		// if it's not out cpt, do nothing
		if ( $post->post_type !== $this->name ) {
			return;
		}

		?>
		<style>
		.export-one-post:before {
			content: "\f316";
			color: #82878c;
			font: normal 20px/1 dashicons;
			display: inline-block;
			padding: 0 3px 0 0;
			vertical-align: top;
			-webkit-font-smoothing: antialiased;
			-moz-osx-font-smoothing: grayscale;
		}
		</style>
		<div class="misc-pub-section export-one-post">
			<?php
			$export_url = add_query_arg(
				array(
					'download'      => '',
					'export_single' => $post->ID,
					'_wpnonce'      => wp_create_nonce( 'single_export' ),
				),
				admin_url( 'export.php' )
			);
			?>
			<a href="<?php echo esc_url( $export_url ); ?>"><?php echo esc_html( $this->label ); ?></a>
		</div>
		<?php
	}

	/**
	 * Modify export arguments.
	 *
	 * Adjusts the export query arguments to handle single entry export.
	 *
	 * @param array<string, mixed> $args Query arguments for determining what should be exported.
	 *
	 * @return array<string, mixed> Modified query arguments.
	 */
	public function export_args( array $args ): array {

		// if no export_single var, it's a normal export - don't interfere
		if ( ! isset( $_GET['export_single'] ) ) {
			return $args;
		}

		// verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'single_export' ) ) {
			return $args;
		}

		return $this->single_export_args( $args );
	}

	/**
	 * Filter the SQL query for single entry export.
	 *
	 * Replaces the query to match the single post ID for export.
	 *
	 * @param string   $query    The original SQL query.
	 * @param \WP_Query $wp_query The WP_Query instance.
	 *
	 * @return string Modified SQL query.
	 */
	public function query( string $query, \WP_Query $wp_query ): string {
		if ( ! isset( $_GET['export_single'] ) ) {
			return $query;
		}

		// verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'single_export' ) ) {
			return $query;
		}

		return $this->single_export_query( $query, $wp_query, intval( $_GET['export_single'] ) );
	}

	/**
	 * Export a single post as WXR.
	 *
	 * @param int $post_id The post ID to export.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function export_post( int $post_id ) {
		$post = \get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'saltus-framework' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! \defined( 'WXR_VERSION' ) ) {
			require_once ABSPATH . 'wp-admin/includes/export.php';
		}

		$export_args_filter = function ( array $args ): array {
			return $this->single_export_args( $args );
		};

		$query_filter = function ( string $query, \WP_Query $wp_query ) use ( $post_id ): string {
			return $this->single_export_query( $query, $wp_query, $post_id );
		};

		add_filter( 'export_args', $export_args_filter );
		add_filter( 'posts_request', $query_filter, 10, 2 );

		$buffer_level = ob_get_level();
		ob_start();
		try {
			\export_wp();
			$wxr = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'export_failed', $e->getMessage(), [ 'status' => 500 ] );
		} finally {
			$this->remove_export_headers();
			if ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			remove_filter( 'export_args', $export_args_filter );
			remove_filter( 'posts_request', $query_filter );
		}

		return [
			'post_id'    => $post_id,
			'post_type'  => $post->post_type,
			'post_title' => $post->post_title,
			'wxr'        => $wxr,
		];
	}

	/**
	 * Remove download headers emitted by WordPress core export_wp().
	 */
	private function remove_export_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		header_remove( 'Content-Description' );
		header_remove( 'Content-Disposition' );
		header_remove( 'Content-Type' );
	}

	/**
	 * Build export args that force WordPress through the identifiable single-export query.
	 *
	 * @param array<string, mixed> $args Query arguments for determining what should be exported.
	 * @return array<string, mixed>
	 */
	public function single_export_args( array $args ): array {
		$args['content']    = 'post';
		$args['start_date'] = self::FAKE_DATE;
		$args['end_date']   = self::FAKE_DATE;

		return $args;
	}

	/**
	 * Rewrite WordPress' generated export query to target a single post.
	 *
	 * Only hooked during a single-post export request (see the request-filtering
	 * step that registers this filter with $post_id bound). Core's exporter has
	 * no native "single post" scope, so that step forces self::FAKE_DATE into
	 * the year/month args, and this method recognizes the resulting query shape
	 * and swaps it for a single-post lookup.
	 *
	 * @param string   $query    The original SQL query.
	 * @param \WP_Query $wp_query The WP_Query instance.
	 * @param int      $post_id  The post ID to export.
	 * @return string
	 */
	public function single_export_query( string $query, \WP_Query $wp_query, int $post_id ): string {
		global $wpdb;

		if ( ! $this->is_fake_date_export_query( $wp_query ) ) {
			if ( strpos( $query, self::FAKE_DATE ) !== false ) {
				throw new \RuntimeException(
					\esc_html__( 'Single export failed: the export query could not be scoped.', 'saltus-framework' )
				);
			}
			return $query;
		}

		return $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE {$wpdb->posts}.ID = %d",
			$post_id
		);
	}

	/**
	 * Detect whether $query is the specific export query our request-filtering
	 * step produces by inspecting structured query vars instead of the
	 * compiled SQL.
	 *
	 * @param \WP_Query $query The WP_Query instance to check.
	 * @return bool
	 */
	private function is_fake_date_export_query( \WP_Query $query ): bool {
		if ( $query->get( 'post_type' ) !== 'post' ) {
			return false;
		}

		if ( $query->get( 'post_status' ) === 'auto-draft' ) {
			return false;
		}

		$date_query = $query->get( 'date_query' );
		if ( ! is_array( $date_query ) ) {
			return false;
		}

		$after  = null;
		$before = null;

		foreach ( $date_query as $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}
			if ( isset( $clause['after'] ) ) {
				$after = $clause['after'];
			}
			if ( isset( $clause['before'] ) ) {
				$before = $clause['before'];
			}
		}

		if ( empty( $after ) || empty( $before ) ) {
			return false;
		}

		$start = gmdate( 'Y-m-d', strtotime( self::FAKE_DATE ) );
		$end   = gmdate( 'Y-m-d', strtotime( '+1 month', strtotime( self::FAKE_DATE ) ) );

		return gmdate( 'Y-m-d', strtotime( $after ) ) === $start
			&& gmdate( 'Y-m-d', strtotime( $before ) ) === $end;
	}
}
