<?php
/** @var list<WP_Post> $posts */
/** @var array<int, array<string, mixed>> $meta_by_post */
if ( $posts === [] ) :
	?>
	<p class="saltus-block__empty"><?php echo esc_html__( 'No entries found.', 'saltus-framework' ); ?></p>
	<?php
	return;
endif;
?>
<ul class="saltus-block__list">
	<?php foreach ( $posts as $saltus_post ) : ?>
		<li class="saltus-block__item">
			<h3 class="saltus-block__title"><?php echo esc_html( $saltus_post->post_title ); ?></h3>
			<?php if ( $attributes['showExcerpt'] && $saltus_post->post_excerpt !== '' ) : ?>
				<div class="saltus-block__excerpt"><?php echo wp_kses_post( $saltus_post->post_excerpt ); ?></div>
			<?php endif; ?>
			<?php if ( $attributes['showDate'] && isset( $saltus_post->post_date ) ) : ?>
				<time class="saltus-block__date"><?php echo esc_html( mysql2date( (string) get_option( 'date_format', 'F j, Y' ), $saltus_post->post_date ) ); ?></time>
			<?php endif; ?>
			<?php if ( ! empty( $meta_by_post[ $saltus_post->ID ] ) ) : ?>
				<dl class="saltus-block__meta">
					<?php foreach ( $meta_by_post[ $saltus_post->ID ] as $saltus_path => $saltus_value ) : ?>
						<dt><?php echo esc_html( $saltus_path ); ?></dt>
						<dd><?php echo esc_html( is_scalar( $saltus_value ) ? (string) $saltus_value : wp_json_encode( $saltus_value ) ); ?></dd>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
