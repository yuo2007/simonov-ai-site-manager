<?php
/**
 * Authentication for the MCP endpoint.
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Class Auth
 *
 * Bearer-token authentication. The token is stored hashed; on a successful
 * match the associated WordPress user is set as the current user so that all
 * capability checks apply naturally — under a ceiling that strips every
 * administrative capability for the rest of the request.
 */
class Auth {

	const OPTION = 'wpmcp_settings';

	/**
	 * Capabilities an MCP request never holds, whoever it authenticates as.
	 *
	 * The bridge is for content. Signing in as an administrator must not turn
	 * a remote client into one, so everything that administers the site —
	 * plugins, themes, users, settings, core, file editing, raw HTML and
	 * uploads, theme configuration, import/export, network and store
	 * settings — is denied for the whole request. Meta capabilities resolve
	 * to these (edit_user → edit_users, manage_privacy_options →
	 * manage_options), so they are covered too.
	 *
	 * @var string[]
	 */
	const DENIED_CAPS = array(
		'manage_options',
		'activate_plugins',
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'upload_plugins',
		'resume_plugins',
		'switch_themes',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'upload_themes',
		'resume_themes',
		'edit_theme_options',
		'customize',
		'update_core',
		'update_languages',
		'install_languages',
		'edit_files',
		'list_users',
		'create_users',
		'add_users',
		'edit_users',
		'delete_users',
		'promote_users',
		'remove_users',
		'unfiltered_html',
		'unfiltered_upload',
		'import',
		'export',
		'edit_dashboard',
		'view_site_health_checks',
		'export_others_personal_data',
		'erase_others_personal_data',
		'manage_network',
		'manage_network_options',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_users',
		'manage_sites',
		'create_sites',
		'delete_sites',
		'delete_site',
		'setup_network',
		'upgrade_network',
		'manage_woocommerce',
		'view_woocommerce_reports',
	);

	/**
	 * Read plugin settings.
	 *
	 * @return array
	 */
	public function settings() {
		return wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'enabled'        => true,
				'token_hash'     => '',
				'user_id'        => 0,
				'read_only'      => false,
				'disabled_tools' => array(),
			)
		);
	}

	/**
	 * Is the MCP server enabled?
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$s = $this->settings();
		return ! empty( $s['enabled'] ) && ! empty( $s['token_hash'] ) && ! empty( $s['user_id'] );
	}

	/**
	 * Generate a new token, store its hash, and return the plaintext once.
	 *
	 * @param int $user_id The WP user the token acts as.
	 * @return string Plaintext token (shown to the admin only once).
	 */
	public function rotate_token( $user_id ) {
		$token           = 'wpmcp_' . bin2hex( random_bytes( 24 ) );
		$s               = $this->settings();
		$s['token_hash'] = $this->hash_token( $token );
		$s['user_id']    = (int) $user_id;
		update_option( self::OPTION, $s );
		return $token;
	}

	/**
	 * Hash a token for storage.
	 *
	 * Deliberately a fast keyed hash, not wp_hash_password(). bcrypt exists to
	 * slow down guessing of low-entropy human passwords; this token carries 192
	 * bits from a CSPRNG, so stretching buys nothing and costs ~120 ms of CPU
	 * on every request — which an unauthenticated caller can trigger at will.
	 * It also kept handing the plaintext token to every callback hooked on the
	 * 'check_password' filter, where security plugins log failed credentials.
	 *
	 * @param string $token Plaintext token.
	 * @return string
	 */
	private function hash_token( $token ) {
		return 'hmac:' . hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Maximum accepted token length.
	 *
	 * Issued tokens are 54 characters. The cap matters most on WP < 6.8, where
	 * PasswordHash::crypt_private() MD5-loops the entire input 256 times with
	 * no length limit — a 5 MB token would cost ~1.3 GB of hashing per request.
	 */
	const MAX_TOKEN_LENGTH = 128;

	/**
	 * Extract the bearer token from the current request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function extract_token( $request ) {
		$header = $request->get_header( 'authorization' );
		if ( ! $header ) {
			// Some servers strip the Authorization header; fall back.
			$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
		}
		if ( $header && preg_match( '/Bearer\s+(\S+)/i', $header, $m ) ) {
			return trim( $m[1] );
		}
		// Query-string fallback for clients that cannot set headers.
		$q = $request->get_param( 'token' );
		return $q ? (string) $q : '';
	}

	/**
	 * Permission callback for the REST route.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function permission_callback( $request ) {
		// Only the master switch gates here. Requiring a configured static token
		// (is_enabled) would wrongly 503 an OAuth-only setup, where no static
		// token or "act as user" is ever configured.
		$s = $this->settings();
		if ( empty( $s['enabled'] ) ) {
			return new \WP_Error( 'wpmcp_disabled', 'MCP server is disabled.', array( 'status' => 503 ) );
		}

		$token = $this->extract_token( $request );

		if ( ! $token || strlen( $token ) > self::MAX_TOKEN_LENGTH ) {
			return $this->deny();
		}

		// OAuth-issued bearer token: acts as the WordPress user who signed in.
		$oauth_user = wpmcp()->oauth->verify_access_token( $token );
		if ( $oauth_user ) {
			if ( is_multisite() && ! is_user_member_of_blog( $oauth_user, get_current_blog_id() ) ) {
				return $this->deny();
			}
			$this->limit_capabilities();
			wp_set_current_user( $oauth_user );
			return true;
		}

		// Below here only the static-token path applies; it needs a configured
		// token and user.
		if ( empty( $s['token_hash'] ) || empty( $s['user_id'] ) ) {
			return $this->deny();
		}

		$s = $this->settings();
		if ( ! $this->verify_token( $token, $s ) ) {
			$this->note_failure();
			return $this->deny();
		}

		$user = get_user_by( 'id', (int) $s['user_id'] );
		if ( ! $user ) {
			$this->log( 'Configured user does not exist: ' . (int) $s['user_id'] );
			return $this->deny();
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
			$this->log( 'Configured user is not a member of this site: ' . $user->ID );
			return $this->deny();
		}

		$this->limit_capabilities();
		wp_set_current_user( $user->ID );

		return true;
	}

	/**
	 * Put the capability ceiling in place for the rest of this request.
	 *
	 * Called before wp_set_current_user() on purpose: setting the user fires
	 * kses_init(), which decides whether content gets filtered by asking for
	 * unfiltered_html — it must already be refused at that point.
	 *
	 * Hooked on map_meta_cap rather than user_has_cap because a multisite
	 * super admin is granted everything before user_has_cap ever runs;
	 * do_not_allow is the one answer WP_User::has_cap() honours for them too.
	 */
	private function limit_capabilities() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'deny_admin_caps' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Replace any administrative primitive with do_not_allow.
	 *
	 * edit_user is refused outright: for a user's own account core maps it to
	 * no primitive at all, so the list alone would still let a request edit
	 * the very account it signed in as — e-mail and password included.
	 *
	 * @param string[] $caps Primitive capabilities the check resolved to.
	 * @param string   $cap  Capability being checked.
	 * @return string[]
	 */
	public static function deny_admin_caps( $caps, $cap ) {
		if ( 'edit_user' === $cap || array_intersect( (array) $caps, self::DENIED_CAPS ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Build the 401 and attach the OAuth discovery challenge.
	 *
	 * The RFC 9728 challenge points clients at the "sign in with WordPress"
	 * flow. It is sent ONLY on denial — a WWW-Authenticate header on a 200
	 * makes strict clients believe authentication is still required and loop.
	 *
	 * @return \WP_Error
	 */
	private function deny() {
		if ( ! headers_sent() ) {
			$meta = wpmcp()->oauth->resource_metadata_url();
			header( sprintf( 'WWW-Authenticate: Bearer resource_metadata="%s"', $meta ), true );
		}
		return new \WP_Error( 'wpmcp_unauthorized', 'Unauthorized.', array( 'status' => 401 ) );
	}

	/**
	 * Verify a token against the stored hash, upgrading legacy hashes.
	 *
	 * Tokens issued before the switch to HMAC are stored as bcrypt/phpass. They
	 * are still accepted once, then transparently re-hashed, so an existing
	 * integration keeps working without the site owner regenerating anything.
	 *
	 * @param string $token Plaintext token.
	 * @param array  $s     Settings.
	 * @return bool
	 */
	private function verify_token( $token, array $s ) {
		$stored = (string) $s['token_hash'];
		if ( '' === $stored ) {
			return false;
		}

		if ( 0 === strpos( $stored, 'hmac:' ) ) {
			return hash_equals( $stored, $this->hash_token( $token ) );
		}

		// Legacy bcrypt/phpass hash — verify, then upgrade in place.
		if ( wp_check_password( $token, $stored ) ) {
			$s['token_hash'] = $this->hash_token( $token );
			update_option( self::OPTION, $s );
			return true;
		}
		return false;
	}

	/**
	 * Record a failed attempt.
	 *
	 * Not a guessing defence — 192 bits of entropy makes that irrelevant — but
	 * without a counter a sustained attack leaves no trace at all.
	 */
	private function note_failure() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'wpmcp_fail_' . md5( $ip );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		if ( $count + 1 >= 20 ) {
			$this->log( sprintf( 'Repeated MCP auth failures from %s (%d in the last hour).', $ip, $count + 1 ) );
		}
	}

	/**
	 * Log a reason server-side without disclosing it to the caller.
	 *
	 * @param string $message Message.
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[simonov-ai-site-manager] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
