<?php
/**
 * Abstract base for tool groups.
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

use WPMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Abstract_Tool
 */
abstract class Abstract_Tool {

	/**
	 * Register this group's tools with the registry.
	 *
	 * @param Tool_Registry $registry Registry.
	 */
	abstract public function register( Tool_Registry $registry );

	/**
	 * Convenience: string property schema.
	 *
	 * @param string $desc     Description.
	 * @param bool   $required Unused here; kept for readability at call sites.
	 * @return array
	 */
	protected function str( $desc ) {
		return array(
			'type'        => 'string',
			'description' => $desc,
		);
	}

	/**
	 * Convenience: integer property schema.
	 *
	 * @param string $desc Description.
	 * @return array
	 */
	protected function int( $desc ) {
		return array(
			'type'        => 'integer',
			'description' => $desc,
		);
	}

	/**
	 * Convenience: boolean property schema.
	 *
	 * @param string $desc Description.
	 * @return array
	 */
	protected function bool( $desc ) {
		return array(
			'type'        => 'boolean',
			'description' => $desc,
		);
	}

	/**
	 * Build an object schema.
	 *
	 * @param array $properties Properties map.
	 * @param array $required   Required keys.
	 * @return array
	 */
	protected function schema( array $properties, array $required = array() ) {
		$s = array(
			'type'       => 'object',
			'properties' => $properties,
		);
		if ( $required ) {
			$s['required'] = $required;
		}
		return $s;
	}

	/**
	 * Require an argument or throw.
	 *
	 * @param array  $args Args.
	 * @param string $key  Key.
	 * @return mixed
	 * @throws \Exception When missing.
	 */
	protected function required( array $args, $key ) {
		if ( ! isset( $args[ $key ] ) || '' === $args[ $key ] ) {
			throw new \Exception( esc_html( sprintf( 'Missing required argument: %s', $key ) ) );
		}
		return $args[ $key ];
	}

	/**
	 * Throw if a value is a WP_Error.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 * @throws \Exception On WP_Error.
	 */
	protected function unwrap( $value ) {
		if ( is_wp_error( $value ) ) {
			throw new \Exception( esc_html( $value->get_error_message() ) );
		}
		return $value;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Object-level authorization.
	 *
	 * The registry only checks a PRIMITIVE capability ("can this user edit
	 * posts at all?"). The low-level functions the callbacks use
	 * (wp_update_post, wp_delete_post, wp_insert_term, ...) deliberately
	 * perform no checks of their own — they trust the caller. Everything
	 * WordPress actually protects (ownership, post status, post-type caps,
	 * the super-admin guard) lives in map_meta_cap() and is only reachable
	 * when the target object's ID is passed in.
	 *
	 * These helpers are that missing half. Every tool that touches a
	 * specific object must call one.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Assert a meta capability against a specific object.
	 *
	 * @param string $cap Meta capability, e.g. 'edit_post'.
	 * @param int    $id  Object ID.
	 * @throws \Exception When not permitted.
	 */
	protected function assert_can( $cap, $id ) {
		if ( ! current_user_can( $cap, $id ) ) {
			throw new \Exception( esc_html( sprintf( 'Permission denied: %s on object %d.', $cap, (int) $id ) ) );
		}
	}

	/**
	 * Assert the post exists and may be edited by the current user.
	 *
	 * @param int $id Post ID.
	 * @return \WP_Post
	 * @throws \Exception When missing or not permitted.
	 */
	protected function assert_editable_post( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			throw new \Exception( esc_html( 'Post not found: ' . (int) $id ) );
		}
		$this->assert_can( 'edit_post', $post->ID );
		return $post;
	}

	/**
	 * Assert the post exists and may be read by the current user.
	 *
	 * @param int $id Post ID.
	 * @return \WP_Post
	 * @throws \Exception When missing or not permitted.
	 */
	protected function assert_readable_post( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			throw new \Exception( esc_html( 'Post not found: ' . (int) $id ) );
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			throw new \Exception( esc_html( 'Permission denied: cannot read post ' . $post->ID . '.' ) );
		}
		return $post;
	}

	/**
	 * Assert the ID is an attachment the current user may edit.
	 *
	 * @param int    $id  Attachment ID.
	 * @param string $cap Meta capability to check.
	 * @throws \Exception When not an attachment or not permitted.
	 */
	protected function assert_attachment( $id, $cap = 'edit_post' ) {
		$id = (int) $id;
		if ( 'attachment' !== get_post_type( $id ) ) {
			throw new \Exception( esc_html( 'Not an attachment: ' . $id ) );
		}
		$this->assert_can( $cap, $id );
	}

	/**
	 * Assert the current user may act on a post type at all.
	 *
	 * Capabilities in WordPress are per-post-type: 'product' needs
	 * edit_products, 'wp_template' needs edit_theme_options. wp_insert_post()
	 * checks none of them, so a tool gated on the generic 'edit_posts' would
	 * otherwise reach every type on the site.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $action    One of edit|publish|delete|edit_others.
	 * @return \WP_Post_Type
	 * @throws \Exception When unknown, internal, or not permitted.
	 */
	protected function assert_post_type( $post_type, $action = 'edit' ) {
		$post_type = (string) $post_type;

		// Purely internal bookkeeping types are never editable content. The
		// Site Editor / FSE types (wp_template, wp_global_styles, custom_css,
		// …) are NOT listed here: they are legitimate theme configuration and
		// stay gated by their own edit_theme_options capability below.
		$internal = array( 'revision', 'nav_menu_item', 'oembed_cache', 'user_request', 'customize_changeset' );
		if ( in_array( $post_type, $internal, true ) ) {
			throw new \Exception( esc_html( 'Post type not manageable via MCP: ' . $post_type ) );
		}

		$obj = get_post_type_object( $post_type );
		if ( ! $obj ) {
			throw new \Exception( esc_html( 'Unknown post type: ' . $post_type ) );
		}

		$map = array(
			'edit'        => $obj->cap->edit_posts,
			'publish'     => $obj->cap->publish_posts,
			'delete'      => $obj->cap->delete_posts,
			'edit_others' => $obj->cap->edit_others_posts,
		);
		$cap = $map[ $action ] ?? $obj->cap->edit_posts;

		if ( ! current_user_can( $cap ) ) {
			throw new \Exception( esc_html( sprintf( 'Permission denied: %s (%s) requires "%s".', $post_type, $action, $cap ) ) );
		}
		return $obj;
	}

	/**
	 * Assert the current user may manage a taxonomy.
	 *
	 * Taxonomies carry their own caps — product_cat maps to
	 * manage_product_terms (manage_woocommerce), not manage_categories.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $action   One of manage|edit|delete|assign.
	 * @return \WP_Taxonomy
	 * @throws \Exception When unknown or not permitted.
	 */
	protected function assert_taxonomy( $taxonomy, $action = 'manage' ) {
		$obj = get_taxonomy( (string) $taxonomy );
		if ( ! $obj ) {
			throw new \Exception( esc_html( 'Unknown taxonomy: ' . $taxonomy ) );
		}
		$map = array(
			'manage' => $obj->cap->manage_terms,
			'edit'   => $obj->cap->edit_terms,
			'delete' => $obj->cap->delete_terms,
			'assign' => $obj->cap->assign_terms,
		);
		$cap = $map[ $action ] ?? $obj->cap->manage_terms;

		if ( ! current_user_can( $cap ) ) {
			throw new \Exception( esc_html( sprintf( 'Permission denied: taxonomy "%s" (%s) requires "%s".', $taxonomy, $action, $cap ) ) );
		}
		return $obj;
	}

	/**
	 * Assert a post meta key may be written.
	 *
	 * sanitize_key() keeps a leading underscore, so without this the bridge
	 * writes protected meta — _price, _stock, _wp_page_template and every
	 * plugin's private state.
	 *
	 * @param string $key Meta key (already sanitized).
	 * @throws \Exception When protected.
	 */
	protected function assert_meta_writable( $key ) {
		if ( ! is_protected_meta( $key, 'post' ) ) {
			return;
		}
		if ( $this->is_allowed_protected_meta( $key ) ) {
			return;
		}
		throw new \Exception( esc_html( sprintf( 'Protected meta key is not writable via MCP: %s', $key ) ) );
	}

	/**
	 * Whether a protected post meta key is on the MCP write allowlist.
	 *
	 * Yoast SEO keeps every field it owns under the _yoast_wpseo_ prefix, so
	 * the whole namespace is opened when Yoast is active. Those keys hold SEO
	 * copy — titles, meta descriptions, focus keyphrases, canonical URLs,
	 * robots directives, Open Graph / Twitter cards, schema and the primary
	 * category — never core WordPress or commerce state, so writing them
	 * cannot escalate privileges. The namespace stays closed on sites without
	 * Yoast so the surface is not opened where nothing consumes it.
	 *
	 * Extend or restrict the allowlist with the wpmcp_meta_writable filter.
	 *
	 * @param string $key Meta key (already sanitized).
	 * @return bool
	 */
	protected function is_allowed_protected_meta( $key ) {
		$allowed = defined( 'WPSEO_VERSION' ) && 0 === strpos( $key, '_yoast_wpseo_' );

		/**
		 * Filters whether a protected post meta key may be written over MCP.
		 *
		 * @param bool   $allowed Whether the key is writable.
		 * @param string $key     Meta key (already sanitized).
		 */
		return (bool) apply_filters( 'wpmcp_meta_writable', $allowed, $key );
	}

	/**
	 * Clamp a per-page argument into a sane range.
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $default Default when absent.
	 * @param int   $max     Upper bound.
	 * @return int
	 */
	protected function per_page( $value, $default = 20, $max = 100 ) {
		$n = null === $value || '' === $value ? $default : (int) $value;
		return max( 1, min( $max, $n ) );
	}
}
