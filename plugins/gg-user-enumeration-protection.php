<?php
/**
 * Plugin Name: GG User Enumeration Protection
 * Description: Blocks WordPress username discovery: ?author=N, author archives, REST users, users sitemap, oEmbed author data, and account-revealing login / lost-password messages.
 * Version:     1.1.0
 * Author:      Green Graphics
 *
 * Must-use plugin, deployed fleet-wide.
 *
 * KEEP THIS FILE PHP 5.6 COMPATIBLE (no arrow functions, no ??, no type hints
 * on closures). A parse error in a mu-plugin white-screens the whole site, and
 * some accounts still run old ea-php versions.
 *
 * Opt-out for a site that genuinely uses author archive pages (the ?author=N
 * probe stays blocked either way). In wp-config.php:
 *     define( 'GG_ALLOW_AUTHOR_ARCHIVES', true );
 */

defined( 'ABSPATH' ) || exit;

/**
 * 1. ?author=N, ?author_name=x and /author/x/  ->  404
 *
 * Priority 1 matters: core's redirect_canonical() runs at priority 10 and is
 * what turns ?author=1 into /author/<login>/, i.e. the actual leak. We have to
 * get in before it.
 */
function gg_enum_block_author_requests() {
	$probe          = isset( $_GET['author'] ) || isset( $_GET['author_name'] );
	$block_archives = ! ( defined( 'GG_ALLOW_AUTHOR_ARCHIVES' ) && GG_ALLOW_AUTHOR_ARCHIVES );

	if ( $probe || ( $block_archives && is_author() ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}
add_action( 'template_redirect', 'gg_enum_block_author_requests', 1 );

/**
 * 2. REST: hide every /wp/v2/users route from anonymous requests
 *    (list, single, /me, application-passwords). Also stops ?_embed leaking
 *    author slugs on posts.
 */
function gg_enum_hide_rest_users( $endpoints ) {
	if ( ! is_user_logged_in() ) {
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
	}
	return $endpoints;
}
add_filter( 'rest_endpoints', 'gg_enum_hide_rest_users' );

/**
 * 3. Core users sitemap (wp-sitemap-users-1.xml)
 */
function gg_enum_remove_users_sitemap( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'gg_enum_remove_users_sitemap', 10, 2 );

/**
 * 4. oEmbed responses include author_name and author_url (the author archive
 *    URL, i.e. the username slug).
 */
function gg_enum_strip_oembed_author( $data ) {
	unset( $data['author_name'], $data['author_url'] );
	return $data;
}
add_filter( 'oembed_response_data', 'gg_enum_strip_oembed_author' );

/**
 * 5. wp-login.php: one generic message for unknown user / unknown email /
 *    wrong password. Done on the error object (not the login_errors string
 *    filter) so lockout, 2FA and other plugin messages are left alone.
 */
function gg_enum_generic_login_error( $errors ) {
	$leaky = array( 'invalid_username', 'invalid_email', 'incorrect_password' );
	$hit   = false;

	foreach ( $leaky as $code ) {
		if ( $errors->get_error_message( $code ) ) {
			$errors->remove( $code );
			$hit = true;
		}
	}
	if ( $hit ) {
		$errors->add( 'gg_invalid_login', 'Invalid login details.' );
	}
	return $errors;
}
add_filter( 'wp_login_errors', 'gg_enum_generic_login_error' );

/**
 * 6. Lost-password form: unknown accounts get the same "check your email"
 *    screen as real ones. Core adds its "no account" error AFTER this hook
 *    fires, so removing errors here doesn't work; we redirect instead.
 *
 *    WooCommerce's own lost-password form posts wc_reset_password and has its
 *    own flow, so leave that one alone.
 *
 *    Runs late (priority 99) so CAPTCHA / anti-spam plugins hooked to this
 *    action have already added their errors.
 */
function gg_enum_uniform_lostpassword( $errors ) {
	if ( isset( $_POST['wc_reset_password'] ) ) {
		return;
	}
	if ( ! isset( $_POST['user_login'] ) || ! is_string( $_POST['user_login'] ) ) {
		return;
	}

	$login = trim( wp_unslash( $_POST['user_login'] ) );
	if ( '' === $login ) {
		return; // let core show its "enter a username or email" error
	}

	// If any error other than "no such user" is present, leave it to core so
	// we never bypass those checks.
	foreach ( $errors->get_error_codes() as $code ) {
		if ( ! in_array( $code, array( 'invalid_email', 'invalid_username' ), true ) ) {
			return;
		}
	}

	$user = ( false !== strpos( $login, '@' ) )
		? get_user_by( 'email', $login )
		: get_user_by( 'login', $login );

	if ( ! $user ) {
		wp_safe_redirect( add_query_arg( 'checkemail', 'confirm', wp_login_url() ) );
		exit;
	}
}
add_action( 'lostpassword_post', 'gg_enum_uniform_lostpassword', 99 );
