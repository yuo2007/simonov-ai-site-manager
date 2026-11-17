<?php
/**
 * Post / page / CPT tools.
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

use WPMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Posts_Tools
 */
class Posts_Tools extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public function register( Tool_Registry $r ) {

		$r->add(
			'list_post_types',
			'List all registered public and custom post types with their labels and REST support.',
			$this->schema( array() ),
			array( $this, 'list_post_types' ),
			'read'
		);

		$r->add(
			'list_posts',
			'Query posts/pages/CPT entries. Supports post_type, status, search, author, per_page, page, order, orderby, and taxonomy filters.',
			$this->schema(
				array(
					'post_type' => $this->str( 'Post type slug (default "post"). Use "any" for all.' ),
					'status'    => $this->str( 'Status: publish, draft, pending, private, future, trash, or "any".' ),
					'search'    => $this->str( 'Free-text search.' ),
					'author'    => $this->int( 'Author user ID.' ),
					'per_page'  => $this->int( 'Results per page (default 20, max 100).' ),
					'page'      => $this->int( 'Page number (default 1).' ),
					'orderby'   => $this->str( 'date, title, ID, menu_order, modified, rand.' ),
					'order'     => $this->str( 'ASC or DESC.' ),
				)
			),
			array( $this, 'list_posts' ),
			'read'
		);

		$r->add(
			'get_post',
			'Get a single post/page/CPT by ID, including content, meta and taxonomy terms.',
			$this->schema( array( 'id' => $this->int( 'Post ID.' ) ), array( 'id' ) ),
			array( $this, 'get_post' ),
			'read'
		);

		$r->add(
			'create_post',
			'Create a new post/page/CPT. Content accepts HTML or Gutenberg block markup.',
			$this->schema(
				array(
					'title'     => $this->str( 'Post title.' ),
					'content'   => $this->str( 'Post content (HTML or block markup).' ),
					'excerpt'   => $this->str( 'Optional excerpt.' ),
					'status'    => $this->str( 'publish, draft (default), pending, private, future.' ),
					'post_type' => $this->str( 'Post type (default "post").' ),
					'author'    => $this->int( 'Author user ID.' ),
					'slug'      => $this->str( 'URL slug.' ),
					'date'      => $this->str( 'Publish date (Y-m-d H:i:s, site time).' ),
					'parent'    => $this->int( 'Parent post ID (for hierarchical types).' ),
					'meta'      => array(
						'type'                 => 'object',
						'description'          => 'Custom fields as key/value pairs.',
						'additionalProperties' => true,
					),
				),
				array( 'title' )
			),
			array( $this, 'create_post' ),
			'edit_posts'
		);

		$r->add(
			'update_post',
			'Update an existing post/page/CPT. Only provided fields change.',
			$this->schema(
				array(
					'id'      => $this->int( 'Post ID.' ),
					'title'   => $this->str( 'New title.' ),
					'content' => $this->str( 'New content.' ),
					'excerpt' => $this->str( 'New excerpt.' ),
					'status'  => $this->str( 'New status.' ),
					'slug'    => $this->str( 'New slug.' ),
					'author'  => $this->int( 'New author ID.' ),
					'date'    => $this->str( 'New publish date.' ),
					'parent'  => $this->int( 'New parent ID.' ),
					'meta'    => array(
						'type'                 => 'object',
						'description'          => 'Custom fields to set.',
						'additionalProperties' => true,
					),
				),
				array( 'id' )
			),
			array( $this, 'update_post' ),
			'edit_posts'
		);

		$r->add(
			'delete_post',
			'Delete a post/page/CPT. By default moves to trash; set force=true to delete permanently.',
			$this->schema(
				array(
					'id'    => $this->int( 'Post ID.' ),
					'force' => $this->bool( 'Permanently delete instead of trashing.' ),
				),
				array( 'id' )
			),
			array( $this, 'delete_post' ),
			'delete_posts'
		);

		$r->add(
			'get_post_meta',
			'Get all custom fields (post meta) for a post.',
			$this->schema( array( 'id' => $this->int( 'Post ID.' ) ), array( 'id' ) ),
			array( $this, 'get_post_meta' ),
			'read'
		);

		$r->add(
			'update_post_meta',
			'Set a single custom field (post meta) value on a post.',
			$this->schema(
				array(
					'id'    => $this->int( 'Post ID.' ),
					'key'   => $this->str( 'Meta key.' ),
					'value' => $this->str( 'Meta value (string, or JSON for arrays).' ),
				),
				array( 'id', 'key', 'value' )
			),
			array( $this, 'update_post_meta' ),
			'edit_posts'
		);

		$r->add(
			'delete_post_meta',
			'Delete a custom field (post meta) from a post.',
			$this->schema(
				array(
					'id'  => $this->int( 'Post ID.' ),
					'key' => $this->str( 'Meta key.' ),
				),
				array( 'id', 'key' )
			),
			array( $this, 'delete_post_meta' ),
			'edit_posts'
		);
	}

	/**
	 * List post types.
	 *
	 * @return array
	 */
	public function list_post_types() {
		$types = get_post_types( array(), 'objects' );
		$out   = array();
		foreach ( $types as $t ) {
			$out[] = array(
				'slug'         => $t->name,
				'label'        => $t->label,
				'public'       => (bool) $t->public,
				'hierarchical' => (bool) $t->hierarchical,
				'rest_base'    => $t->rest_base ? $t->rest_base : $t->name,
				'taxonomies'   => get_object_taxonomies( $t->name ),
			);
		}
		return $out;
	}

	/**
	 * List posts.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function list_posts( array $args ) {
		$per_page  = $this->per_page( $args['per_page'] ?? null );
		$post_type = (string) ( $args['post_type'] ?? 'post' );

		// A type nobody can view on the front end (GDPR requests, internal
		// bookkeeping) is only listable by someone who may edit it.
		$type_obj = 'any' === $post_type ? null : get_post_type_object( $post_type );
		if ( 'any' !== $post_type && ( ! $type_obj || ! is_post_type_viewable( $type_obj ) ) ) {
			$type_obj = $this->assert_post_type( $post_type, 'edit' );
		}

		// perm=readable only guards the private status. Drafts, pending and
		// scheduled posts would still be listed for everyone, so mirror the
		// Posts screen: without edit_others_posts you see only your own.
		$others_cap = $type_obj ? $type_obj->cap->edit_others_posts : 'edit_others_posts';
		$own_only   = null;
		if ( ! current_user_can( $others_cap ) ) {
			$protected = array_values( get_post_stati( array( 'protected' => true ) ) );
			$user_id   = get_current_user_id();
			$own_only  = static function ( $where ) use ( $protected, $user_id ) {
				global $wpdb;
				if ( ! $protected ) {
					return $where;
				}
				$placeholders = implode( ', ', array_fill( 0, count( $protected ), '%s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders are built above and every value is passed to prepare().
				return $where . $wpdb->prepare( " AND ( {$wpdb->posts}.post_status NOT IN ( {$placeholders} ) OR {$wpdb->posts}.post_author = %d )", array_merge( $protected, array( $user_id ) ) );
			};
			add_filter( 'posts_where', $own_only );
		}

		try {
			$query = $this->run_list_query( $post_type, $args, $per_page );
		} finally {
			if ( $own_only ) {
				remove_filter( 'posts_where', $own_only );
			}
		}

		$posts = array();
		foreach ( $query->posts as $p ) {
			$posts[] = $this->format_post( $p, false );
		}

		return array(
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'posts'       => $posts,
		);
	}

	/**
	 * The WP_Query behind list_posts.
	 *
	 * @param string $post_type Post type.
	 * @param array  $args      Tool args.
	 * @param int    $per_page  Page size.
	 * @return \WP_Query
	 */
	private function run_list_query( $post_type, array $args, $per_page ) {
		return new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $args['status'] ?? 'any',
				// Without perm=readable, WP_Query folds private and draft
				// statuses into the result set unconditionally, so a caller
				// could enumerate every unpublished post on the site.
				'perm'           => 'readable',
				's'              => $args['search'] ?? '',
				'author'         => ! empty( $args['author'] ) ? (int) $args['author'] : '',
				'posts_per_page' => $per_page,
				'paged'          => max( 1, (int) ( $args['page'] ?? 1 ) ),
				'orderby'        => $args['orderby'] ?? 'date',
				'order'          => $args['order'] ?? 'DESC',
			)
		);
	}

	/**
	 * Get one post.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception When not found.
	 */
	public function get_post( array $args ) {
		$id   = (int) $this->required( $args, 'id' );
		$post = $this->assert_readable_post( $id );

		// A password-protected post is only readable in the clear by someone
		// who could edit it; everyone else gets the metadata without the body.
		if ( '' !== (string) $post->post_password && ! current_user_can( 'edit_post', $post->ID ) ) {
			$formatted                 = $this->format_post( $post, false );
			$formatted['content']      = null;
			$formatted['is_protected'] = true;
			return $formatted;
		}

		return $this->format_post( $post, true );
	}

	/**
	 * Create a post.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function create_post( array $args ) {
		$post_type = (string) ( $args['post_type'] ?? 'post' );
		$status    = (string) ( $args['status'] ?? 'draft' );

		// Capabilities are per-post-type: without this the generic edit_posts
		// gate would reach 'product', 'wp_template' and every other type,
		// bypassing the capability each of those tools checks for itself.
		$this->assert_post_type( $post_type, 'edit' );
		if ( in_array( $status, array( 'publish', 'future' ), true ) ) {
			$this->assert_post_type( $post_type, 'publish' );
		}

		$postarr = array(
			'post_title'   => (string) $this->required( $args, 'title' ),
			'post_content' => (string) ( $args['content'] ?? '' ),
			'post_excerpt' => (string) ( $args['excerpt'] ?? '' ),
			'post_status'  => $status,
			'post_type'    => $post_type,
		);
		if ( ! empty( $args['author'] ) ) {
			if ( (int) $args['author'] !== get_current_user_id() ) {
				$this->assert_post_type( $post_type, 'edit_others' );
			}
			$postarr['post_author'] = (int) $args['author'];
		}
		if ( ! empty( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $args['slug'] );
		}
		if ( ! empty( $args['date'] ) ) {
			$postarr['post_date'] = (string) $args['date'];
		}
		if ( ! empty( $args['parent'] ) ) {
			$postarr['post_parent'] = (int) $args['parent'];
		}

		// Core unslashes post fields and meta on the way in, as if they came
		// from $_POST. Without slashing them first every backslash is lost,
		// which breaks block markup (< ...), JSON and code samples.
		$id = $this->unwrap( wp_insert_post( wp_slash( $postarr ), true ) );

		if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
			foreach ( $args['meta'] as $k => $v ) {
				$key = sanitize_key( $k );
				$this->assert_meta_writable( $key );
				update_post_meta( $id, $key, wp_slash( $v ) );
			}
		}

		return $this->format_post( get_post( $id ), true );
	}

	/**
	 * Update a post.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception When not found.
	 */
	public function update_post( array $args ) {
		$id   = (int) $this->required( $args, 'id' );
		$post = $this->assert_editable_post( $id );

		if ( isset( $args['status'] ) && in_array( (string) $args['status'], array( 'publish', 'future' ), true ) ) {
			$this->assert_post_type( $post->post_type, 'publish' );
		}
		if ( isset( $args['author'] ) && (int) $args['author'] !== (int) $post->post_author ) {
			$this->assert_post_type( $post->post_type, 'edit_others' );
		}

		$postarr = array( 'ID' => $id );
		$map     = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
			'date'    => 'post_date',
		);
		foreach ( $map as $arg => $field ) {
			if ( isset( $args[ $arg ] ) ) {
				$postarr[ $field ] = (string) $args[ $arg ];
			}
		}
		if ( isset( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $args['slug'] );
		}
		if ( isset( $args['author'] ) ) {
			$postarr['post_author'] = (int) $args['author'];
		}
		if ( isset( $args['parent'] ) ) {
			$postarr['post_parent'] = (int) $args['parent'];
		}

		$this->unwrap( wp_update_post( wp_slash( $postarr ), true ) );

		if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
			foreach ( $args['meta'] as $k => $v ) {
				$key = sanitize_key( $k );
				$this->assert_meta_writable( $key );
				update_post_meta( $id, $key, wp_slash( $v ) );
			}
		}

		return $this->format_post( get_post( $id ), true );
	}

	/**
	 * Delete a post.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception On failure.
	 */
	public function delete_post( array $args ) {
		$id = (int) $this->required( $args, 'id' );

		// wp_delete_post() checks nothing; with force=true this is an
		// irreversible site-wide destruction primitive.
		if ( ! get_post( $id ) ) {
			throw new \Exception( esc_html( 'Post not found: ' . $id ) );
		}
		$this->assert_can( 'delete_post', $id );

		$force = ! empty( $args['force'] );
		$res   = wp_delete_post( $id, $force );
		if ( ! $res ) {
			throw new \Exception( esc_html( 'Failed to delete post: ' . $id ) );
		}
		return array(
			'deleted' => true,
			'id'      => $id,
			'forced'  => $force,
		);
	}

	/**
	 * Get post meta.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function get_post_meta( array $args ) {
		$id = (int) $this->required( $args, 'id' );
		$this->assert_readable_post( $id );

		return $this->visible_meta( $id );
	}

	/**
	 * A post's meta, minus protected keys unless the user may edit the post.
	 *
	 * Protected meta is where plugins keep private state — legacy
	 * WooCommerce orders hold _billing_email, _billing_phone and the rest
	 * here, SEO plugins their focus keyphrases. Only someone who could edit
	 * the post sees it, through get_post and get_post_meta alike.
	 *
	 * @param int $id Post ID.
	 * @return array
	 */
	private function visible_meta( $id ) {
		$all = get_post_meta( $id );
		if ( current_user_can( 'edit_post', $id ) ) {
			return $all;
		}
		$public = array();
		foreach ( $all as $key => $value ) {
			if ( ! is_protected_meta( $key, 'post' ) ) {
				$public[ $key ] = $value;
			}
		}
		return $public;
	}

	/**
	 * Update post meta.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function update_post_meta( array $args ) {
		$id  = (int) $this->required( $args, 'id' );
		$key = sanitize_key( $this->required( $args, 'key' ) );

		// sanitize_key() preserves a leading underscore, so without this the
		// tool writes _price, _stock and _wp_page_template directly — which
		// bypasses the capability every dedicated tool checks.
		$this->assert_editable_post( $id );
		$this->assert_meta_writable( $key );

		$value = $this->required( $args, 'value' );
		$decoded = json_decode( (string) $value, true );
		if ( JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) ) ) {
			$value = $decoded;
		}
		update_post_meta( $id, $key, wp_slash( $value ) );
		return array(
			'id'    => $id,
			'key'   => $key,
			'value' => get_post_meta( $id, $key, true ),
		);
	}

	/**
	 * Delete post meta.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function delete_post_meta( array $args ) {
		$id  = (int) $this->required( $args, 'id' );
		$key = sanitize_key( $this->required( $args, 'key' ) );

		$this->assert_editable_post( $id );
		$this->assert_meta_writable( $key );

		delete_post_meta( $id, $key );
		return array(
			'deleted' => true,
			'id'      => $id,
			'key'     => $key,
		);
	}

	/**
	 * Format a post for output.
	 *
	 * @param \WP_Post $p         Post.
	 * @param bool     $full      Include content and meta.
	 * @return array
	 */
	private function format_post( $p, $full ) {
		$data = array(
			'id'        => $p->ID,
			'title'     => get_the_title( $p ),
			'status'    => $p->post_status,
			'type'      => $p->post_type,
			'slug'      => $p->post_name,
			'author'    => (int) $p->post_author,
			'date'      => $p->post_date,
			'modified'  => $p->post_modified,
			'link'      => get_permalink( $p ),
			'parent'    => (int) $p->post_parent,
		);
		if ( $full ) {
			$data['content'] = $p->post_content;
			$data['excerpt'] = $p->post_excerpt;
			$data['meta']    = $this->visible_meta( $p->ID );
			$data['terms']   = $this->post_terms( $p );
		}
		return $data;
	}

	/**
	 * Collect taxonomy terms for a post.
	 *
	 * @param \WP_Post $p Post.
	 * @return array
	 */
	private function post_terms( $p ) {
		$out = array();
		foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
			$terms = get_the_terms( $p->ID, $tax );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$out[ $tax ] = wp_list_pluck( $terms, 'name', 'term_id' );
			}
		}
		return $out;
	}
}
