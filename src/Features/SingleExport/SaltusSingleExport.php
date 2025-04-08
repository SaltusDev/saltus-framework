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
	 * @param string     $name The name of the custom post type (CPT) to export.
	 * @param array|null $args Optional. Additional arguments for the export.
	 *                         - 'label': The label for the export link.
	 */
	public function __construct( string $name, ?array $args = [] ) {
		$this->name  = $name;
		$this->label = ! empty( $args['label'] ) ? $args['label'] : 'Export This';
	}

	/**
	 * Process the export functionality by hooking into WordPress actions.
	 */
	public function process() {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Get hooked in: Part II
	 * Initialize the export functionality.
	 *
	 * Hooks into WordPress filters and actions to enable single entry export.
	 *
	 */
	public function init() {

		if ( ! current_user_can( 'export' ) ) {
			return;
		}

		add_filter( 'export_args', array( $this, 'export_args' ) );
		add_filter( 'query', array( $this, 'query' ) );
		add_action( 'post_submitbox_misc_actions', array( $this, 'post_submitbox_misc_actions' ) );
	}

	/**
	 * Insert our action link into the submit box
	 *
	 * @param \WP_Post $post The current post object.
	 *
	 */
	public function post_submitbox_misc_actions( $post ) {

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
	 * @param array $args Query arguments for determining what should be exported.
	 *
	 * @return array Modified query arguments.
	 */
	public function export_args( $args ) {

		// if no export_single var, it's a normal export - don't interfere
		if ( ! isset( $_GET['export_single'] ) ) {
			return $args;
		}

		// verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'single_export' ) ) {
			return $args;
		}

		// use our fake date so the query is easy to find (because we don't have a good hook to use)
		$args['content']    = 'post';
		$args['start_date'] = self::FAKE_DATE;
		$args['end_date']   = self::FAKE_DATE;

		return $args;
	}

	/**
	 * Filter the SQL query for single entry export.
	 *
	 * Replaces the query to match the single post ID for export.
	 *
	 * @param string $query The original SQL query.
	 *
	 * @return string Modified SQL query.
	 */
	public function query( $query ) {
		if ( ! isset( $_GET['export_single'] ) ) {
			return $query;
		}

		// verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'single_export' ) ) {
			return $query;
		}

		global $wpdb;

		// This is the query WP will build (given our arg filtering above)
		// Since the current_filter isn't narrow, we'll check each query
		// to see if it matches, then if it is we replace it
		$test = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}  WHERE {$wpdb->posts}.post_type = 'post' AND {$wpdb->posts}.post_status != 'auto-draft' AND {$wpdb->posts}.post_date >= %s AND {$wpdb->posts}.post_date < %s",
			// phpcs:ignore: WordPress.DateTime.RestrictedFunctions.date_date
			date( 'Y-m-d', strtotime( self::FAKE_DATE ) ),
			// phpcs:ignore: WordPress.DateTime.RestrictedFunctions.date_date
			date( 'Y-m-d', strtotime( '+1 month', strtotime( self::FAKE_DATE ) ) )
		);

		if ( $test !== $query ) {
			return $query;
		}

		// divide query
		$split = explode( 'WHERE', $query );
		// replace WHERE clause
		$split[1] = $wpdb->prepare( " {$wpdb->posts}.ID = %d", intval( $_GET['export_single'] ) );
		// put query back together
		$query = implode( 'WHERE', $split );

		return $query;
	}
}

