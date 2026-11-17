<?php
/**
 * User management tools.
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

		$r->add(
			'create_user',
			'Create a new user.',
			$this->schema(
				array(
					'username'     => $this->str( 'Login username.' ),
					'email'        => $this->str( 'Email address.' ),
					'password'     => $this->str( 'Password (auto-generated if omitted).' ),
					'role'         => $this->str( 'Role slug (default subscriber).' ),
					'display_name' => $this->str( 'Display name.' ),
					'first_name'   => $this->str( 'First name.' ),
					'last_name'    => $this->str( 'Last name.' ),
				),
				array( 'username', 'email' )
			),
			array( $this, 'create_user' ),
			'create_users'
		);

		$r->add(
			'update_user',
			'Update an existing user (email, role, names, password).',
			$this->schema(
				array(
					'id'           => $this->int( 'User ID.' ),
					'email'        => $this->str( 'New email.' ),
					'password'     => $this->str( 'New password.' ),
					'role'         => $this->str( 'New role slug.' ),
					'display_name' => $this->str( 'New display name.' ),
					'first_name'   => $this->str( 'New first name.' ),
					'last_name'    => $this->str( 'New last name.' ),
				),
				array( 'id' )
			),
			array( $this, 'update_user' ),
			'edit_users'
		);

		$r->add(
			'delete_user',
			'Delete a user. Optionally reassign their content to another user.',
			$this->schema(
				array(
					'id'       => $this->int( 'User ID to delete.' ),
					'reassign' => $this->int( 'User ID to reassign content to (optional).' ),
				),
				array( 'id' )
			),
			array( $this, 'delete_user' ),
			'delete_users'
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
			throw new \Exception( 'User not found.' );
		}
		return $this->format_user( $u );
	}

	/**
	 * Create user.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function create_user( array $args ) {
		$role = (string) ( $args['role'] ?? get_option( 'default_role', 'subscriber' ) );
		$this->assert_assignable_role( $role );

		$userdata = array(
			'user_login'   => (string) $this->required( $args, 'username' ),
			'user_email'   => (string) $this->required( $args, 'email' ),
			'user_pass'    => ! empty( $args['password'] ) ? (string) $args['password'] : wp_generate_password( 20 ),
			'role'         => $role,
			'display_name' => $args['display_name'] ?? '',
			'first_name'   => $args['first_name'] ?? '',
			'last_name'    => $args['last_name'] ?? '',
		);
		$id = $this->unwrap( wp_insert_user( $userdata ) );
		return $this->format_user( get_user_by( 'id', $id ) );
	}

	/**
	 * Update user.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function update_user( array $args ) {
		$id = (int) $this->required( $args, 'id' );

		// wp_update_user() performs no authorization of its own: without this
		// the tool rewrites any account's password or email — including the
		// owner's — and the "confirm your new address" flow does not apply
		// because that lives in wp-admin, not here.
		$this->assert_touchable_user( $id, 'edit_user' );

		// assert_touchable_user() exempts the caller's own account from the
		// "not another administrator" guard, which is right for renaming
		// yourself and wrong for the two fields that constitute the account.
		// Letting a connection rewrite its own login address or password turns
		// a leaked token into permanent takeover, with the owner locked out —
		// and the "confirm your new address" flow does not apply here, because
		// that lives in wp-admin.
		if ( $id === get_current_user_id() && ( isset( $args['email'] ) || isset( $args['password'] ) ) ) {
			throw new \Exception( 'Refusing to change the email or password of the account this connection acts as. Do it from the WordPress profile screen.' );
		}

		if ( isset( $args['role'] ) ) {
			// wp-admin will not let you change your own role either: for the
			// only administrator that is a lockout nobody can undo.
			if ( $id === get_current_user_id() ) {
				throw new \Exception( 'Refusing to change the role of the account this connection acts as.' );
			}
			$this->assert_assignable_role( (string) $args['role'] );
		}

		$userdata = array( 'ID' => $id );
		$map      = array(
			'email'        => 'user_email',
			'password'     => 'user_pass',
			'display_name' => 'display_name',
			'first_name'   => 'first_name',
			'last_name'    => 'last_name',
			'role'         => 'role',
		);
		foreach ( $map as $arg => $field ) {
			if ( isset( $args[ $arg ] ) ) {
				$userdata[ $field ] = (string) $args[ $arg ];
			}
		}
		$this->unwrap( wp_update_user( $userdata ) );
		return $this->format_user( get_user_by( 'id', $id ) );
	}

	/**
	 * Delete user.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception On failure.
	 */
	public function delete_user( array $args ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$id = (int) $this->required( $args, 'id' );

		$this->assert_touchable_user( $id, 'delete_user' );
		if ( $id === get_current_user_id() ) {
			throw new \Exception( 'Refusing to delete the account this connection acts as.' );
		}

		$reassign = ! empty( $args['reassign'] ) ? (int) $args['reassign'] : null;

		// wp_delete_user() only detaches from the current site on multisite;
		// wpmu_delete_user() is the network-wide call. Reporting "deleted"
		// for the former would be a lie.
		if ( is_multisite() ) {
			// A site administrator may have delete_users, but that does not
			// authorize deleting an account from the entire network.
			if ( ! is_super_admin( get_current_user_id() ) ) {
				throw new \Exception( 'Network-wide user deletion requires a network administrator.' );
			}
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			if ( ! wpmu_delete_user( $id ) ) {
				throw new \Exception( 'Failed to delete user: ' . $id );
			}
			return array(
				'deleted' => true,
				'id'      => $id,
			);
		}

		if ( ! wp_delete_user( $id, $reassign ) ) {
			throw new \Exception( 'Failed to delete user: ' . $id );
		}
		return array(
			'deleted' => true,
			'id'      => $id,
		);
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
