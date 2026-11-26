<?php
/**
 * Public API and template tags.
 *
 * @package ExpiringPosts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Set a post to expire. Without a date the post expires immediately.
 *
 * @param int|WP_Post     $post            Post.
 * @param int|string|bool $expiration_date Unix timestamp, or a date string strtotime() understands. False for now.
 * @return true|WP_Error
 */
function exp_expire_post( $post, $expiration_date = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- 1.x public API prefix.
	if ( ! $expiration_date ) {
		$expiration_date = time();
	}

	if ( ! is_int( $expiration_date ) ) {
		$expiration_date = is_numeric( $expiration_date ) ? (int) $expiration_date : strtotime( (string) $expiration_date );
	}

	$post = get_post( $post );
	if ( ! $post || false === $expiration_date ) {
		return new WP_Error( 'exp_expiring_posts_error', __( 'Either the post or expiration date provided are not valid.', 'expiring-posts' ) );
	}

	$plugin = EXP_Expiring_Posts::instance();
	$result = $plugin->set_expiration( $post->ID, true, EXP_Expiring_Posts::timestamp_to_local( $expiration_date ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	/**
	 * Fires after a post is set to expire through exp_expire_post().
	 *
	 * @param int $post_id         Post ID.
	 * @param int $expiration_date Unix timestamp.
	 */
	do_action( 'exp_expire_post', $post->ID, $expiration_date ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 1.x hook name.

	if ( $expiration_date <= time() ) {
		$plugin->expire_post( $post );
	}

	return true;
}

/**
 * Stop a post from expiring.
 *
 * @param int|WP_Post $post Post.
 * @return true|WP_Error
 */
function exp_unschedule_expiring_post( $post ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- 1.x public API prefix.
	$post = get_post( $post );
	if ( ! $post ) {
		return new WP_Error( 'exp_expiring_posts_error', __( 'The post provided is not valid.', 'expiring-posts' ) );
	}

	return EXP_Expiring_Posts::instance()->unschedule_expired_post( $post );
}

/**
 * Get the Unix timestamp a post expires (or expired) at.
 *
 * @param int|WP_Post|null $post Post. Defaults to the current post.
 * @return int|false False when the post is not set to expire.
 */
function exp_get_expiration_timestamp( $post = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- 1.x public API prefix.
	$post = get_post( $post );
	if ( ! $post ) {
		return false;
	}

	$expiration = EXP_Expiring_Posts::instance()->get_expiration( $post );
	if ( ! $expiration['enabled'] && EXP_Expiring_Posts::STATUS !== $post->post_status ) {
		return false;
	}

	return $expiration['timestamp'];
}

/**
 * Get a post's formatted expiry date.
 *
 * @param int|WP_Post|null $post   Post. Defaults to the current post.
 * @param string           $format PHP date format. Defaults to the site's date and time formats.
 * @return string Empty string when the post is not set to expire.
 */
function exp_get_expiration_date( $post = null, $format = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- 1.x public API prefix.
	$timestamp = exp_get_expiration_timestamp( $post );
	if ( ! $timestamp ) {
		return '';
	}

	if ( '' === $format ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	return (string) wp_date( $format, $timestamp );
}

/**
 * Print the current post's expiry date.
 *
 * @param string $format PHP date format. Defaults to the site's date and time formats.
 * @param string $before Text before the date. Only printed when there is a date.
 * @param string $after  Text after the date. Only printed when there is a date.
 */
function exp_the_expiration_date( $format = '', $before = '', $after = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- 1.x public API prefix.
	$date = exp_get_expiration_date( null, $format );
	if ( '' === $date ) {
		return;
	}

	echo wp_kses_post( $before ) . esc_html( $date ) . wp_kses_post( $after );
}

/**
 * `[expiring_posts_date]` shortcode. Shows the current post's expiry date.
 *
 * Attributes:
 * - `format` PHP date format. Defaults to the site's date and time formats.
 * - `none`   Text shown when the post does not expire. Defaults to nothing.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function exp_expiration_date_shortcode( $atts ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- 1.x public API prefix.
	$atts = shortcode_atts(
		array(
			'format' => '',
			'none'   => '',
		),
		$atts,
		'expiring_posts_date'
	);

	$date = exp_get_expiration_date( null, sanitize_text_field( $atts['format'] ) );
	if ( '' === $date ) {
		return esc_html( $atts['none'] );
	}

	return '<time class="exp-expiration-date" datetime="' . esc_attr( gmdate( 'c', (int) exp_get_expiration_timestamp() ) ) . '">' . esc_html( $date ) . '</time>';
}
add_shortcode( 'expiring_posts_date', 'exp_expiration_date_shortcode' );
