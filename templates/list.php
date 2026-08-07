<?php
/** @var list<WP_Post> $posts */
/** @var array<int, array<string, mixed>> $meta_by_post */
/** @var array<string, mixed> $attributes */
if ( $posts === [] ) :
	?>
	<p class="saltus-frontend__empty"><?php echo esc_html__( 'No entries found.', 'saltus-framework' ); ?></p>
	<?php
	return;
endif;
?>
<ul class="saltus-frontend__list">
	<?php foreach ( $posts as $saltus_post ) : ?>
		<li class="saltus-frontend__item">
			<h2 class="saltus-frontend__title">
				<a href="<?php echo esc_url( get_permalink( $saltus_post ) ); ?>"><?php echo esc_html( $saltus_post->post_title ); ?></a>
			</h2>
			<?php if ( $saltus_post->post_excerpt !== '' ) : ?>
				<div class="saltus-frontend__excerpt"><?php echo wp_kses_post( $saltus_post->post_excerpt ); ?></div>
			<?php endif; ?>
			<time class="saltus-frontend__date" datetime="<?php echo esc_attr( $saltus_post->post_date ); ?>"><?php echo esc_html( mysql2date( (string) get_option( 'date_format', 'F j, Y' ), $saltus_post->post_date ) ); ?></time>
			<?php if ( ! empty( $meta_by_post[ $saltus_post->ID ] ) ) : ?>
				<dl class="saltus-frontend__meta">
					<?php foreach ( $meta_by_post[ $saltus_post->ID ] as $saltus_path => $saltus_value ) : ?>
						<dt><?php echo esc_html( $saltus_path ); ?></dt>
						<dd><?php echo esc_html( is_scalar( $saltus_value ) ? (string) $saltus_value : wp_json_encode( $saltus_value ) ); ?></dd>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
