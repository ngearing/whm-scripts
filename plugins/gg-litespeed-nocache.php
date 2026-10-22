<?php

/**
 * Plugin Name: GG - LiteSpeed Cache Rules
 * Description: Never caches pages that render a Gravity Form, never caches password-protected or private WordPress pages, and forces the LiteSpeed public TTL to 8 hours.
 * Version: 1.1
 */

if (! defined('ABSPATH')) {
	exit;
}

/**
 * 1. Mark any page that renders a Gravity Form as non-cacheable.
 */
function gfls_nocache_form_page() {
	do_action('litespeed_control_set_nocache', 'Gravity Form on page');
}
add_action('gform_pre_render', 'gfls_nocache_form_page');
add_action('gform_enqueue_scripts', 'gfls_nocache_form_page');

/**
 * 2. Never cache password-protected or private (post status) pages/posts.
 */
function gfls_nocache_protected_content() {
	if (! is_singular()) {
		return;
	}

	$post = get_queried_object();
	if (! $post instanceof WP_Post) {
		return;
	}

	if (post_password_required($post) || ! empty($post->post_password) || 'private' === $post->post_status) {
		do_action('litespeed_control_set_nocache', 'Password protected or private page');
	}
}
add_action('template_redirect', 'gfls_nocache_protected_content', 1);

/**
 * 3. Force LiteSpeed Default Public Cache TTL to 8 hours (seconds).
 */
function gfls_force_litespeed_ttl() {
	do_action('litespeed_conf_force', 'ttl_pub', 8 * HOUR_IN_SECONDS);
}
add_action('litespeed_init', 'gfls_force_litespeed_ttl');
