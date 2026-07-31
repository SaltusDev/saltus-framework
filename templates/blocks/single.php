<?php
/** @var WP_Post $post */
?>
<article class="saltus-block__single">
	<?php if ( $attributes['showTitle'] ) : ?>
		<h2 class="saltus-block__title"><?php echo esc_html( $post->post_title ); ?></h2>
	<?php endif; ?>
	<?php if ( $attributes['showContent'] ) : ?>
		<div class="saltus-block__content"><?php echo wp_kses_post( apply_filters( 'the_content', $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound ?></div>
	<?php endif; ?>
	<?php if ( $meta !== [] ) : ?>
		<dl class="saltus-block__meta">
			<?php foreach ( $meta as $saltus_path => $saltus_value ) : ?>
				<dt><?php echo esc_html( $saltus_path ); ?></dt>
				<dd><?php echo esc_html( is_scalar( $saltus_value ) ? (string) $saltus_value : wp_json_encode( $saltus_value ) ); ?></dd>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>
</article>
