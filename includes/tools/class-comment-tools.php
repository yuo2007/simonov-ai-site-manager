<?php
/**
 * Comment tools.
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

use WPMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Comment_Tools
 */
class Comment_Tools extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public function register( Tool_Registry $r ) {

		$r->add(
			'list_comments',
			'List comments, optionally by post or status.',
			$this->schema(
				array(
					'post_id'  => $this->int( 'Filter by post ID.' ),
					'status'   => $this->str( 'approve, hold, spam, trash, or all.' ),
					'search'   => $this->str( 'Search text.' ),
					'per_page' => $this->int( 'Results per page (default 25).' ),
					'page'     => $this->int( 'Page number.' ),
				)
			),
			array( $this, 'list_comments' ),
			'moderate_comments'
		);

		$r->add(
			'create_comment',
			'Create a comment on a post.',
			$this->schema(
				array(
					'post_id'      => $this->int( 'Post ID.' ),
					'content'      => $this->str( 'Comment text.' ),
					'author_name'  => $this->str( 'Author name.' ),
					'author_email' => $this->str( 'Author email.' ),
					'parent'       => $this->int( 'Parent comment ID for replies.' ),
					'status'       => $this->str( 'hold (default) or approve. Publishing directly requires moderate_comments.' ),
				),
				array( 'post_id', 'content' )
			),
			array( $this, 'create_comment' ),
			'moderate_comments'
		);

		$r->add(
			'update_comment',
			'Update a comment (content or moderation status).',
			$this->schema(
				array(
					'id'      => $this->int( 'Comment ID.' ),
					'content' => $this->str( 'New content.' ),
					'status'  => $this->str( 'approve, hold, spam, trash.' ),
				),
				array( 'id' )
			),
			array( $this, 'update_comment' ),
			'moderate_comments'
		);

		$r->add(
			'delete_comment',
			'Delete a comment (trash or permanent).',
			$this->schema(
				array(
					'id'    => $this->int( 'Comment ID.' ),
					'force' => $this->bool( 'Permanently delete.' ),
				),
				array( 'id' )
			),
			array( $this, 'delete_comment' ),
			'moderate_comments'
		);
	}

	/**
	 * List comments.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function list_comments( array $args ) {
		$comments = get_comments(
			array(
				'post_id' => (int) ( $args['post_id'] ?? 0 ),
				'status'  => $args['status'] ?? 'all',
				'search'  => $args['search'] ?? '',
				'number'  => $this->per_page( $args['per_page'] ?? null, 25 ),
				'paged'   => max( 1, (int) ( $args['page'] ?? 1 ) ),
			)
		);
		return array_map( array( $this, 'format_comment' ), $comments );
	}

	/**
	 * Create comment.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function create_comment( array $args ) {
		$post_id = (int) $this->required( $args, 'post_id' );
		$this->assert_readable_post( $post_id );

		// wp_insert_comment() is the raw DB primitive: it skips
		// wp_filter_comment(), so kses, flood control, duplicate detection and
		// the disallowed-keys list never run. wp_new_comment() applies all of
		// them.
		//
		// kses alone is not enough here, though: it only engages for users
		// without unfiltered_html, and this bridge typically acts as an
		// administrator, who has it. Since the caller is an automated agent
		// that may be relaying attacker-authored text, sanitize
		// unconditionally rather than trusting the role.
		$content = wp_kses_post( (string) $this->required( $args, 'content' ) );

		$status = (string) ( $args['status'] ?? 'hold' );
		if ( ! in_array( $status, array( 'approve', 'hold' ), true ) ) {
			throw new \Exception( esc_html( 'Invalid status: use "approve" or "hold".' ) );
		}
		if ( 'approve' === $status && ! current_user_can( 'moderate_comments' ) ) {
			throw new \Exception( esc_html( 'Permission denied: moderate_comments is required to publish a comment directly.' ) );
		}

		$data = array(
			'comment_post_ID'      => $post_id,
			'comment_content'      => $content,
			'comment_author'       => sanitize_text_field( (string) ( $args['author_name'] ?? '' ) ),
			'comment_author_email' => sanitize_email( (string) ( $args['author_email'] ?? '' ) ),
			// wp_new_comment() reads this key unconditionally and warns when absent.
			'comment_author_url'   => '',
			'comment_parent'      => (int) ( $args['parent'] ?? 0 ),
			'comment_approved'     => ( 'approve' === $status ) ? 1 : 0,
		);

		$id = wp_new_comment( wp_slash( $data ), true );
		$this->unwrap( $id );
		if ( ! $id ) {
			throw new \Exception( esc_html( 'Failed to create comment.' ) );
		}
		return $this->format_comment( get_comment( $id ) );
	}

	/**
	 * Update comment.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function update_comment( array $args ) {
		$id = (int) $this->required( $args, 'id' );
		if ( ! get_comment( $id ) ) {
			throw new \Exception( esc_html( 'Comment not found: ' . $id ) );
		}

		if ( isset( $args['content'] ) ) {
			$this->unwrap(
				wp_update_comment(
					// wp_update_comment() unslashes internally, so unslashed
					// input silently loses its backslashes.
					wp_slash(
						array(
							'comment_ID'      => $id,
							'comment_content' => wp_kses_post( (string) $args['content'] ),
						)
					),
					true
				)
			);
		}
		if ( isset( $args['status'] ) ) {
			$status = (string) $args['status'];
			if ( ! in_array( $status, array( 'approve', 'hold', 'spam', 'trash' ), true ) ) {
				throw new \Exception( esc_html( 'Invalid status: use approve, hold, spam or trash.' ) );
			}
			// Returns false on failure; discarding it reported success either way.
			if ( ! wp_set_comment_status( $id, $status ) ) {
				throw new \Exception( esc_html( 'Failed to set comment status.' ) );
			}
		}
		return $this->format_comment( get_comment( $id ) );
	}

	/**
	 * Delete comment.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function delete_comment( array $args ) {
		$id = (int) $this->required( $args, 'id' );
		if ( ! wp_delete_comment( $id, ! empty( $args['force'] ) ) ) {
			throw new \Exception( esc_html( 'Failed to delete comment: ' . $id ) );
		}
		return array(
			'deleted' => true,
			'id'      => $id,
		);
	}

	/**
	 * Format a comment.
	 *
	 * @param \WP_Comment $c Comment.
	 * @return array
	 */
	private function format_comment( $c ) {
		return array(
			'id'           => (int) $c->comment_ID,
			'post_id'      => (int) $c->comment_post_ID,
			'author_name'  => $c->comment_author,
			'author_email' => $c->comment_author_email,
			'content'      => $c->comment_content,
			'status'       => wp_get_comment_status( $c->comment_ID ),
			'parent'       => (int) $c->comment_parent,
			'date'         => $c->comment_date,
		);
	}
}
