<?php
namespace Saltus\WP\Framework\Features\WpCli\Commands;

use Saltus\WP\Framework\Features\Duplicate\SaltusDuplicate;
use Saltus\WP\Framework\Features\SingleExport\SaltusSingleExport;

final class PostCommand extends AbstractCommand {
	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$post_type  = sanitize_key( $this->argument( $args, 0, 'post-type' ) );
		$query_args = [
			'post_type'      => $post_type,
			'post_status'    => sanitize_key( (string) ( $assoc_args['status'] ?? 'any' ) ),
			'posts_per_page' => max( 1, min( 100, (int) ( $assoc_args['per-page'] ?? 20 ) ) ),
			'paged'          => max( 1, (int) ( $assoc_args['page'] ?? 1 ) ),
			's'              => sanitize_text_field( (string) ( $assoc_args['search'] ?? '' ) ),
			'orderby'        => sanitize_key( (string) ( $assoc_args['orderby'] ?? 'date' ) ),
			'order'          => strtoupper( (string) ( $assoc_args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
		];
		$query      = new \WP_Query( $query_args );
		$rows       = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$rows[] = $this->post_row( $post );
			}
		}
		$this->cli->format_items( $this->format( $assoc_args ), $rows, [ 'ID', 'post_type', 'post_status', 'post_title', 'post_name' ] );
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		$post = get_post( (int) $this->argument( $args, 0, 'id' ) );
		if ( ! $post instanceof \WP_Post ) {
			$this->fail( 'Post not found.' );
		}
		$row = $this->post_row( $post, true );
		$this->cli->format_items( $this->format( $assoc_args ), [ $row ], array_keys( $row ) );
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function create( array $args, array $assoc_args ): void {
		$post_type = sanitize_key( $this->argument( $args, 0, 'post-type' ) );
		$title     = sanitize_text_field( $this->argument( $args, 1, 'title' ) );
		$post_data = [
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => (string) ( $assoc_args['content'] ?? '' ),
			'post_excerpt' => (string) ( $assoc_args['excerpt'] ?? '' ),
			'post_name'    => sanitize_title( (string) ( $assoc_args['slug'] ?? '' ) ),
			'post_status'  => $this->post_status( (string) ( $assoc_args['status'] ?? 'draft' ) ),
		];
		$post_id   = $this->ensure_result( wp_insert_post( $post_data, true ) );
		$this->apply_meta_and_terms( (int) $post_id, $assoc_args );
		$this->cli->success( 'Created post ' . (int) $post_id . '.' );
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function update( array $args, array $assoc_args ): void {
		$post_id = (int) $this->argument( $args, 0, 'id' );
		if ( ! get_post( $post_id ) ) {
			$this->fail( 'Post not found.' );
		}
		$post_data = [ 'ID' => $post_id ];
		$map       = [
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'slug'    => 'post_name',
		];
		foreach ( $map as $input => $field ) {
			if ( array_key_exists( $input, $assoc_args ) ) {
				$post_data[ $field ] = $input === 'slug' ? sanitize_title( (string) $assoc_args[ $input ] ) : (string) $assoc_args[ $input ];
			}
		}
		if ( isset( $assoc_args['status'] ) ) {
			$post_data['post_status'] = $this->post_status( (string) $assoc_args['status'] );
		}
		$this->ensure_result( wp_update_post( $post_data, true ) );
		$this->apply_meta_and_terms( $post_id, $assoc_args );
		$this->cli->success( 'Updated post ' . $post_id . '.' );
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function delete( array $args, array $assoc_args ): void {
		$post_id = (int) $this->argument( $args, 0, 'id' );
		$result  = wp_delete_post( $post_id, ! empty( $assoc_args['force'] ) );
		if ( ! $result ) {
			$this->fail( 'Post could not be deleted.' );
		}
		$this->cli->success( 'Deleted post ' . $post_id . '.' );
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function duplicate( array $args, array $assoc_args ): void {
		$post_id = (int) $this->argument( $args, 0, 'id' );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			$this->fail( 'Post not found.' );
		}
		$new_id = $this->ensure_result( ( new SaltusDuplicate( $post->post_type, [] ) )->perform_duplication( $post_id ) );
		$this->cli->success( 'Duplicated post as ' . (int) $new_id . '.' );
	}

	/**
	 * @param list<string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function export( array $args, array $assoc_args ): void {
		$post_id = (int) $this->argument( $args, 0, 'id' );
		$result  = $this->ensure_result( ( new SaltusSingleExport( '', [] ) )->export_post( $post_id ) );
		$wxr     = is_array( $result ) ? (string) ( $result['wxr'] ?? '' ) : '';
		$file    = (string) ( $assoc_args['file'] ?? '' );
		if ( $file === '' || $file === '-' ) {
			$this->cli->line( $wxr );
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Explicit CLI export destination.
		if ( file_put_contents( $file, $wxr ) === false ) {
			$this->fail( 'Could not write export file.' );
		}
		$this->cli->success( 'Exported post to ' . $file . '.' );
	}

	/** @param array<string, mixed> $assoc_args */
	private function apply_meta_and_terms( int $post_id, array $assoc_args ): void {
		if ( isset( $assoc_args['meta'] ) ) {
			foreach ( $this->json_object( (string) $assoc_args['meta'] ) as $key => $value ) {
				update_post_meta( $post_id, (string) $key, $value );
			}
		}
		if ( isset( $assoc_args['terms'] ) ) {
			foreach ( $this->json_object( (string) $assoc_args['terms'] ) as $taxonomy => $terms ) {
				wp_set_object_terms( $post_id, is_array( $terms ) ? $terms : [ $terms ], (string) $taxonomy, false );
			}
		}
	}

	private function post_status( string $status ): string {
		$status = sanitize_key( $status );
		if ( ! in_array( $status, [ 'publish', 'draft', 'pending', 'private' ], true ) ) {
			$this->fail( 'Unsupported post status: ' . $status );
		}
		return $status;
	}

	/** @return array<string, mixed> */
	private function post_row( \WP_Post $post, bool $details = false ): array {
		$row = [
			'ID'          => $post->ID,
			'post_type'   => $post->post_type,
			'post_status' => $post->post_status,
			'post_title'  => $post->post_title,
			'post_name'   => $post->post_name,
		];
		if ( $details ) {
			$row['post_content'] = $post->post_content;
			$row['post_excerpt'] = $post->post_excerpt;
			$row['meta']         = wp_json_encode( get_post_meta( $post->ID ) );
		}
		return $row;
	}
}
