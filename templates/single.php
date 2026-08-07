<?php
/** @var WP_Post $post */
/** @var array<string, mixed> $meta */
?>
<article class="saltus-frontend__single">
	<h1 class="saltus-frontend__title"><?php echo esc_html( $post->post_title ); ?></h1>
	<div class="saltus-frontend__content"><?php echo wp_kses_post( apply_filters( 'the_content', $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound ?></div>
	<?php if ( $meta !== [] ) : ?>
		<dl class="saltus-frontend__meta">
			<?php foreach ( $meta as $saltus_path => $saltus_value ) : ?>
				<dt><?php echo esc_html( $saltus_path ); ?></dt>
				<dd><?php echo esc_html( is_scalar( $saltus_value ) ? (string) $saltus_value : wp_json_encode( $saltus_value ) ); ?></dd>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>
</article>
