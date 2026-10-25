<?php
/**
 * User lookup tools (read-only).
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

use WPMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class User_Tools
 */
class User_Tools extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public function register( Tool_Registry $r ) {

		$r->add(
			'list_users',
			'List users, optionally filtered by role or search term.',
			$this->schema(
				array(
					'role'     => $this->str( 'Filter by role slug, e.g. administrator, editor, customer.' ),
					'search'   => $this->str( 'Search login, email, name.' ),
					'per_page' => $this->int( 'Results per page (default 20).' ),
					'page'     => $this->int( 'Page number.' ),
				)
			),
			array( $this, 'list_users' ),
			'list_users'
		);

		$r->add(
			'get_user',
			'Get a single user by ID.',
			$this->schema( array( 'id' => $this->int( 'User ID.' ) ), array( 'id' ) ),
			array( $this, 'get_user' ),
			'list_users'
		);
	}

	/**
	 * List users.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function list_users( array $args ) {
		$per_page = $this->per_page( $args['per_page'] ?? null );
		$query    = new \WP_User_Query(
			array(
				'role'    => $args['role'] ?? '',
				'search'  => ! empty( $args['search'] ) ? '*' . $args['search'] . '*' : '',
				'number'  => $per_page,
				'offset'  => ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page,
			)
		);
		$out = array();
		foreach ( $query->get_results() as $u ) {
			$out[] = $this->format_user( $u );
		}
		return array(
			'total' => (int) $query->get_total(),
			'users' => $out,
		);
	}

	/**
	 * Get user.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception When not found.
	 */
	public function get_user( array $args ) {
		$u = get_user_by( 'id', (int) $this->required( $args, 'id' ) );
		if ( ! $u ) {
			throw new \Exception( esc_html( 'User not found.' ) );
		}
		return $this->format_user( $u );
	}

	/**
	 * Format a user (no sensitive fields).
	 *
	 * @param \WP_User $u User.
	 * @return array
	 */
	private function format_user( $u ) {
		return array(
			'id'           => $u->ID,
			'username'     => $u->user_login,
			'email'        => $u->user_email,
			'display_name' => $u->display_name,
			'first_name'   => $u->first_name,
			'last_name'    => $u->last_name,
			'roles'        => $u->roles,
			'registered'   => $u->user_registered,
			'url'          => $u->user_url,
		);
	}
}
