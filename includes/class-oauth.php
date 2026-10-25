<?php
/**
 * Minimal OAuth 2.1 authorization server for the MCP endpoint.
 *
 * Implements exactly what an MCP client needs to "sign in with WordPress":
 *   - RFC 9728 protected-resource metadata + a WWW-Authenticate challenge
 *   - RFC 8414 authorization-server metadata
 *   - RFC 7591 dynamic client registration (public clients, no secret)
 *   - authorization_code grant with mandatory PKCE (S256)
 *   - refresh_token grant
 *
 * The access token maps to the WordPress user who signed in and approved, so
 * every per-object capability check in the tools applies to that real user.
 *
 * All endpoints are served from parse_request (plain front-end requests) rather
 * than the REST API, so browser cookie auth on /authorize behaves normally and
 * there is no REST nonce to fight.
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Class OAuth
 */
class OAuth {

	const CLIENTS      = 'wpmcp_oauth_clients';
	const TOKENS       = 'wpmcp_oauth_tokens';
	const REFRESH      = 'wpmcp_oauth_refresh';
	const BASE         = 'wp-mcp-oauth';
	const AT_PREFIX    = 'mcpoa_';
	const RT_PREFIX    = 'mcpor_';
	const CODE_TTL     = 300;            // seconds — room for a slow login/consent.
	const AT_TTL       = 2592000;        // 30 days.
	const RT_TTL       = 15552000;       // 180 days.

	/**
	 * Ceilings on anonymous registration. A legitimate client registers once.
	 */
	const MAX_CLIENTS               = 200;
	const MAX_REGISTRATIONS_PER_HOUR = 10;
	const MAX_REGISTRATION_BYTES     = 8192;

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'parse_request', array( $this, 'handle_request' ) );
	}

	/* --------------------------------------------------------------------- */
	/* URLs                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * The token this server issues acts as this identity (the resource).
	 *
	 * @return string
	 */
	private function resource_url() {
		return rest_url( WPMCP_NAMESPACE . '/mcp' );
	}

	/**
	 * Issuer identifier — the site origin.
	 *
	 * @return string
	 */
	private function issuer() {
		return untrailingslashit( home_url() );
	}

	/**
	 * URL of an OAuth endpoint.
	 *
	 * @param string $path Sub-path (authorize|token|register).
	 * @return string
	 */
	private function endpoint( $path ) {
		return home_url( '/' . self::BASE . '/' . $path );
	}

	/**
	 * URL of the protected-resource metadata document.
	 *
	 * @return string
	 */
	public function resource_metadata_url() {
		return home_url( '/.well-known/oauth-protected-resource' );
	}

	/* --------------------------------------------------------------------- */
	/* Routing                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Intercept OAuth and discovery paths before WordPress renders a page.
	 *
	 * @param \WP $wp Environment (unused).
	 */
	public function handle_request( $wp ) {
		// esc_url_raw, not sanitize_text_field: the latter strips %XX octets,
		// which would mangle an encoded path before it is even matched.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = '/' . ltrim( $path, '/' );

		// Discovery documents. Match the bare path and any resource-scoped
		// suffix a client may append (…/oauth-protected-resource/wp-json/…).
		if ( $this->path_is( $path, '/.well-known/oauth-protected-resource' ) ) {
			$this->send_json( $this->protected_resource_metadata() );
		}
		if ( $this->path_is( $path, '/.well-known/oauth-authorization-server' ) ) {
			$this->send_json( $this->authorization_server_metadata() );
		}

		$base = '/' . self::BASE . '/';
		if ( 0 !== strpos( $path, $base ) ) {
			return;
		}
		$action = substr( $path, strlen( $base ) );

		switch ( $action ) {
			case 'authorize':
				$this->authorize();
				break;
			case 'token':
				$this->token();
				break;
			case 'register':
				$this->register_client();
				break;
			case 'status':
				$this->status();
				break;
			default:
				$this->send_json( array( 'error' => 'not_found' ), 404 );
		}
	}

	/**
	 * Whether a request path equals a base or is a sub-path of it.
	 *
	 * @param string $path Request path.
	 * @param string $base Base path.
	 * @return bool
	 */
	private function path_is( $path, $base ) {
		return $path === $base || 0 === strpos( $path, $base . '/' );
	}

	/**
	 * Unauthenticated health document. Reveals no secrets — just enough to
	 * confirm what is deployed and whether the sign-in flow can work.
	 */
	private function status() {
		$this->cors();
		$s = wpmcp()->auth->settings();
		$this->send_json(
			array(
				'plugin'        => 'simonov-ai-site-manager',
				'version'       => WPMCP_VERSION,
				'enabled'       => ! empty( $s['enabled'] ),
				'oauth'         => true,
				'mcp_endpoint'  => $this->resource_url(),
				'pretty_permalinks' => (bool) get_option( 'permalink_structure' ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Metadata                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * RFC 9728 protected-resource metadata.
	 *
	 * @return array
	 */
	private function protected_resource_metadata() {
		return array(
			'resource'                 => $this->resource_url(),
			'authorization_servers'    => array( $this->issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( 'mcp' ),
		);
	}

	/**
	 * RFC 8414 authorization-server metadata.
	 *
	 * @return array
	 */
	private function authorization_server_metadata() {
		return array(
			'issuer'                                => $this->issuer(),
			'authorization_endpoint'                => $this->endpoint( 'authorize' ),
			'token_endpoint'                        => $this->endpoint( 'token' ),
			'registration_endpoint'                 => $this->endpoint( 'register' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array( 'mcp' ),
		);
	}

	/* --------------------------------------------------------------------- */
	/* Dynamic client registration (RFC 7591)                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Register a public client and return its credentials.
	 */
	private function register_client() {
		$this->cors();
		if ( $this->is_preflight() ) {
			$this->no_content();
		}

		// Registration is necessarily open — a client has to be able to
		// introduce itself before anyone can sign in — but open means anonymous
		// writes to an option, so it needs a ceiling.
		$this->throttle_registration();

		$raw = $this->raw_body();
		if ( strlen( $raw ) > self::MAX_REGISTRATION_BYTES ) {
			$this->send_json( array( 'error' => 'invalid_client_metadata' ), 413 );
		}

		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) ) {
			$this->send_json( array( 'error' => 'invalid_client_metadata' ), 400 );
		}

		$redirect_uris = isset( $body['redirect_uris'] ) ? (array) $body['redirect_uris'] : array();
		$redirect_uris = array_slice( $redirect_uris, 0, 10 );
		$redirect_uris = array_values( array_filter( array_map( array( $this, 'clean_redirect_uri' ), $redirect_uris ) ) );
		if ( empty( $redirect_uris ) ) {
			$this->send_json( array( 'error' => 'invalid_redirect_uri' ), 400 );
		}

		$client_id = 'wpmcp-' . bin2hex( random_bytes( 16 ) );
		$clients   = $this->make_room( (array) get_option( self::CLIENTS, array() ) );

		$clients[ $client_id ] = array(
			'redirect_uris' => $redirect_uris,
			'client_name'   => isset( $body['client_name'] ) ? sanitize_text_field( (string) $body['client_name'] ) : '',
			'created'       => time(),
		);
		update_option( self::CLIENTS, $clients, false );

		$this->send_json(
			array(
				'client_id'                => $client_id,
				'redirect_uris'            => $redirect_uris,
				'token_endpoint_auth_method' => 'none',
				'grant_types'              => array( 'authorization_code', 'refresh_token' ),
				'response_types'           => array( 'code' ),
				'client_name'              => $clients[ $client_id ]['client_name'],
			),
			201
		);
	}

	/**
	 * Refuse a flood of registrations from one address.
	 *
	 * A real client registers once. Anything registering repeatedly is either
	 * broken or filling the store on purpose, and the store is an option every
	 * registration rewrites.
	 */
	private function throttle_registration() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'wpmcp_reg_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= self::MAX_REGISTRATIONS_PER_HOUR ) {
			if ( ! headers_sent() ) {
				header( 'Retry-After: 3600' );
			}
			$this->send_json( array( 'error' => 'too_many_requests' ), 429 );
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Make room for one more client without evicting a working connection.
	 *
	 * Capping the store by dropping whatever sat at the front of the array
	 * meant a burst of junk registrations could push out the entry a real
	 * connector depends on. Its client_id then resolves to nothing, /authorize
	 * answers "Unknown OAuth client", and the owner has to set the connection
	 * up again with no clue why it broke.
	 *
	 * Clients that still hold a token are never dropped. Everything else is
	 * discarded oldest-first, and only once it has had long enough to complete
	 * the sign-in it registered for.
	 *
	 * @param array $clients Current store.
	 * @return array
	 */
	private function make_room( array $clients ) {
		if ( count( $clients ) < self::MAX_CLIENTS ) {
			return $clients;
		}

		$in_use = $this->clients_with_tokens();
		$cutoff = time() - DAY_IN_SECONDS;

		$disposable = array();
		foreach ( $clients as $id => $client ) {
			if ( isset( $in_use[ $id ] ) ) {
				continue;
			}
			if ( isset( $client['created'] ) && (int) $client['created'] > $cutoff ) {
				continue;
			}
			$disposable[ $id ] = isset( $client['created'] ) ? (int) $client['created'] : 0;
		}

		asort( $disposable );

		foreach ( array_keys( $disposable ) as $id ) {
			if ( count( $clients ) < self::MAX_CLIENTS ) {
				break;
			}
			unset( $clients[ $id ] );
		}

		// Everything left is either in use or too new to judge. Refusing beats
		// evicting a live connection.
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			$this->send_json( array( 'error' => 'too_many_requests' ), 429 );
		}

		return $clients;
	}

	/**
	 * Client IDs that currently hold an access or refresh token.
	 *
	 * @return array<string, bool>
	 */
	private function clients_with_tokens() {
		$in_use = array();
		foreach ( array( self::TOKENS, self::REFRESH ) as $store ) {
			foreach ( (array) get_option( $store, array() ) as $rec ) {
				if ( ! empty( $rec['client_id'] ) && isset( $rec['expires'] ) && (int) $rec['expires'] >= time() ) {
					$in_use[ (string) $rec['client_id'] ] = true;
				}
			}
		}
		return $in_use;
	}

	/* --------------------------------------------------------------------- */
	/* Authorization endpoint                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Handle GET (show consent) and POST (record decision) of /authorize.
	 */
	private function authorize() {
		$params = 'POST' === $this->method() ? $this->post_params() : $this->get_params();

		$client_id     = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
		$redirect_uri  = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
		$state         = isset( $params['state'] ) ? (string) $params['state'] : '';
		$challenge     = isset( $params['code_challenge'] ) ? (string) $params['code_challenge'] : '';
		$challenge_m   = isset( $params['code_challenge_method'] ) ? (string) $params['code_challenge_method'] : '';
		$response_type = isset( $params['response_type'] ) ? (string) $params['response_type'] : '';

		$client = $this->get_client( $client_id );
		if ( ! $client ) {
			wp_die( esc_html__( 'Unknown OAuth client.', 'simonov-ai-site-manager' ), '', array( 'response' => 400 ) );
		}
		// redirect_uri must exactly match one the client registered. Until it is
		// validated we must NOT redirect errors to it.
		if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			wp_die( esc_html__( 'Invalid redirect_uri for this client.', 'simonov-ai-site-manager' ), '', array( 'response' => 400 ) );
		}
		if ( 'code' !== $response_type ) {
			$this->redirect_error( $redirect_uri, 'unsupported_response_type', $state );
		}
		if ( 'S256' !== $challenge_m || '' === $challenge ) {
			$this->redirect_error( $redirect_uri, 'invalid_request', $state, 'PKCE with S256 is required.' );
		}

		// Require a logged-in WordPress session; auth_redirect() sends the user
		// to wp-login and returns here afterwards.
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		$user = wp_get_current_user();
		if ( ! $this->user_may_connect( $user ) ) {
			wp_die(
				esc_html__( 'Your account is not permitted to connect an MCP client to this site.', 'simonov-ai-site-manager' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( 'POST' === $this->method() ) {
			check_admin_referer( 'wpmcp_oauth_consent' );
			if ( empty( $params['approve'] ) ) {
				$this->redirect_error( $redirect_uri, 'access_denied', $state );
			}
			$code = $this->issue_code( $client_id, (int) $user->ID, $redirect_uri, $challenge );
			$this->redirect_success( $redirect_uri, $code, $state );
		}

		$this->render_consent( $client, $params, $user );
	}

	/**
	 * Whether a user may authorize a connection.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	private function user_may_connect( $user ) {
		/**
		 * Minimum capability required to sign in an MCP client.
		 *
		 * @param string $cap Capability.
		 */
		$cap = (string) apply_filters( 'wpmcp_oauth_min_capability', 'edit_posts' );
		return $user && user_can( $user, $cap );
	}

	/**
	 * Render the consent screen.
	 *
	 * @param array    $client Client record.
	 * @param array    $params Request params.
	 * @param \WP_User $user   Current user.
	 */
	private function render_consent( $client, $params, $user ) {
		$name  = $client['client_name'] ? $client['client_name'] : __( 'An MCP client', 'simonov-ai-site-manager' );
		$roles = implode( ', ', (array) $user->roles );

		// Registration is open by design (RFC 7591), so client_name is chosen
		// by whoever registered and proves nothing — anyone can register a
		// client called "Claude" pointing at their own server, mail the
		// /authorize link to a logged-in administrator, and collect a token
		// for that account. The host the code will be sent to is the one fact
		// that distinguishes the real client from an impostor, so show it.
		$destination = (string) wp_parse_url( isset( $params['redirect_uri'] ) ? $params['redirect_uri'] : '', PHP_URL_HOST );
		if ( '' === $destination ) {
			$destination = __( 'an unknown destination', 'simonov-ai-site-manager' );
		}

		$hidden = array( 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'response_type', 'scope' );

		// A self-contained page: login_header()/login_footer() live in
		// wp-login.php, which is not loaded on a normal front-end request.
		wp_enqueue_style( 'wpmcp-consent', WPMCP_URL . 'assets/consent.css', array(), WPMCP_VERSION );

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php esc_html_e( 'Authorize access', 'simonov-ai-site-manager' ); ?></title>
	<?php wp_print_styles( 'wpmcp-consent' ); ?>
</head>
<body>
	<div class="card">
		<h1><?php echo esc_html( $name ); ?> <?php esc_html_e( 'wants to connect', 'simonov-ai-site-manager' ); ?></h1>
		<p class="site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?> — <?php echo esc_html( home_url() ); ?></p>
		<p><?php esc_html_e( 'This will let the client read and manage your site through the Model Context Protocol.', 'simonov-ai-site-manager' ); ?></p>
		<p class="who">
			<?php
			printf(
				/* translators: 1: user login, 2: roles */
				esc_html__( 'It will act as %1$s (%2$s), limited to that account\'s permissions.', 'simonov-ai-site-manager' ),
				'<strong>' . esc_html( $user->user_login ) . '</strong>',
				esc_html( $roles )
			);
			?>
		</p>
		<p class="dest">
			<?php
			printf(
				/* translators: %s: hostname the authorization code will be sent to */
				esc_html__( 'Access will be sent to %s. Approve only if you recognise that address — the name above is chosen by whoever registered the client and is not verified.', 'simonov-ai-site-manager' ),
				'<strong>' . esc_html( $destination ) . '</strong>'
			);
			?>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'wpmcp_oauth_consent' ); ?>
			<?php foreach ( $hidden as $k ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( isset( $params[ $k ] ) ? $params[ $k ] : '' ); ?>">
			<?php endforeach; ?>
			<div class="actions">
				<button type="submit" name="approve" value="1" class="approve"><?php esc_html_e( 'Approve', 'simonov-ai-site-manager' ); ?></button>
				<button type="submit" name="approve" value="" class="deny"><?php esc_html_e( 'Deny', 'simonov-ai-site-manager' ); ?></button>
			</div>
		</form>
		<p class="foot">
			<?php esc_html_e( 'Signed in as', 'simonov-ai-site-manager' ); ?> <?php echo esc_html( $user->user_login ); ?> —
			<a href="<?php echo esc_url( wp_logout_url( $this->current_url() ) ); ?>"><?php esc_html_e( 'switch account', 'simonov-ai-site-manager' ); ?></a>
		</p>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Token endpoint                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Handle POST /token.
	 */
	private function token() {
		$this->cors();
		if ( $this->is_preflight() ) {
			$this->no_content();
		}

		$params = $this->post_params();
		$grant  = isset( $params['grant_type'] ) ? (string) $params['grant_type'] : '';

		if ( 'authorization_code' === $grant ) {
			$this->grant_authorization_code( $params );
		} elseif ( 'refresh_token' === $grant ) {
			$this->grant_refresh_token( $params );
		} else {
			$this->send_json( array( 'error' => 'unsupported_grant_type' ), 400 );
		}
	}

	/**
	 * Exchange an authorization code (+ PKCE verifier) for tokens.
	 *
	 * @param array $params Request params.
	 */
	private function grant_authorization_code( $params ) {
		$code         = isset( $params['code'] ) ? (string) $params['code'] : '';
		$client_id    = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
		$redirect_uri = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
		$verifier     = isset( $params['code_verifier'] ) ? (string) $params['code_verifier'] : '';

		$key    = 'wpmcp_ac_' . hash( 'sha256', $code );
		$record = get_transient( $key );
		delete_transient( $key ); // Single use, whatever the outcome.

		if ( ! is_array( $record ) ) {
			$this->send_json( array( 'error' => 'invalid_grant' ), 400 );
		}
		// A public PKCE client may omit client_id / redirect_uri at the token
		// endpoint (the code + verifier already bind the grant). Only reject
		// when a value is present AND disagrees with what was authorized.
		if ( '' !== $client_id && ! hash_equals( (string) $record['client_id'], $client_id ) ) {
			$this->send_json( array( 'error' => 'invalid_grant' ), 400 );
		}
		if ( '' !== $redirect_uri && ! hash_equals( (string) $record['redirect_uri'], $redirect_uri ) ) {
			$this->send_json( array( 'error' => 'invalid_grant' ), 400 );
		}

		// PKCE: base64url( sha256( verifier ) ) must equal the stored challenge.
		$computed = $this->b64url( hash( 'sha256', $verifier, true ) );
		if ( '' === $verifier || ! hash_equals( (string) $record['code_challenge'], $computed ) ) {
			$this->send_json( array( 'error' => 'invalid_grant' ), 400 );
		}

		$this->issue_tokens( (int) $record['user_id'], $client_id );
	}

	/**
	 * Issue a fresh access token from a refresh token.
	 *
	 * @param array $params Request params.
	 */
	private function grant_refresh_token( $params ) {
		$refresh   = isset( $params['refresh_token'] ) ? (string) $params['refresh_token'] : '';
		$client_id = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';

		$store = (array) get_option( self::REFRESH, array() );
		$hash  = hash( 'sha256', $refresh );
		$rec   = isset( $store[ $hash ] ) ? $store[ $hash ] : null;

		if ( ! is_array( $rec ) || (int) $rec['expires'] < time() || ! hash_equals( (string) $rec['client_id'], $client_id ) ) {
			$this->send_json( array( 'error' => 'invalid_grant' ), 400 );
		}

		// A refresh token outlives most changes to an account: it is good for
		// 180 days, so without this an account demoted or stripped of its
		// permissions keeps minting working access tokens until it expires.
		// Per-tool capability checks still apply, but the connection should
		// not survive the authority it was granted under.
		$user = get_userdata( (int) $rec['user_id'] );
		if ( ! $user || ! $this->user_may_connect( $user ) ) {
			unset( $store[ $hash ] );
			update_option( self::REFRESH, $store, false );
			$this->send_json( array( 'error' => 'invalid_grant' ), 400 );
		}

		// Rotate: the presented refresh token is consumed.
		unset( $store[ $hash ] );
		update_option( self::REFRESH, $store, false );

		$this->issue_tokens( (int) $rec['user_id'], $client_id );
	}

	/* --------------------------------------------------------------------- */
	/* Token storage                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Create and persist an authorization code, returning the plaintext.
	 *
	 * @param string $client_id    Client.
	 * @param int    $user_id      User.
	 * @param string $redirect_uri Redirect URI.
	 * @param string $challenge    PKCE challenge.
	 * @return string
	 */
	private function issue_code( $client_id, $user_id, $redirect_uri, $challenge ) {
		$code = bin2hex( random_bytes( 32 ) );
		set_transient(
			'wpmcp_ac_' . hash( 'sha256', $code ),
			array(
				'client_id'      => $client_id,
				'user_id'        => (int) $user_id,
				'redirect_uri'   => $redirect_uri,
				'code_challenge' => $challenge,
			),
			self::CODE_TTL
		);
		return $code;
	}

	/**
	 * Mint an access + refresh token pair and emit the token response.
	 *
	 * @param int    $user_id   User the tokens act as.
	 * @param string $client_id Client.
	 */
	private function issue_tokens( $user_id, $client_id ) {
		if ( ! get_user_by( 'id', $user_id ) ) {
			$this->send_json( array( 'error' => 'invalid_grant' ), 400 );
		}

		$access  = self::AT_PREFIX . bin2hex( random_bytes( 32 ) );
		$refresh = self::RT_PREFIX . bin2hex( random_bytes( 32 ) );
		$now     = time();

		$tokens = $this->prune( (array) get_option( self::TOKENS, array() ) );
		$tokens[ hash( 'sha256', $access ) ] = array(
			'user_id'   => (int) $user_id,
			'client_id' => $client_id,
			'expires'   => $now + self::AT_TTL,
		);
		update_option( self::TOKENS, $tokens, false );

		$refresh_store = $this->prune( (array) get_option( self::REFRESH, array() ) );
		$refresh_store[ hash( 'sha256', $refresh ) ] = array(
			'user_id'   => (int) $user_id,
			'client_id' => $client_id,
			'expires'   => $now + self::RT_TTL,
		);
		update_option( self::REFRESH, $refresh_store, false );

		$this->send_json(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => self::AT_TTL,
				'refresh_token' => $refresh,
				'scope'         => 'mcp',
			)
		);
	}

	/**
	 * Verify an access token and return the WordPress user it acts as.
	 *
	 * Called from Auth::permission_callback for bearer tokens that are not the
	 * static token.
	 *
	 * @param string $token Presented bearer token.
	 * @return int User ID, or 0 when invalid/expired.
	 */
	public function verify_access_token( $token ) {
		if ( 0 !== strpos( $token, self::AT_PREFIX ) ) {
			return 0;
		}
		$tokens = (array) get_option( self::TOKENS, array() );
		$hash   = hash( 'sha256', $token );
		if ( ! isset( $tokens[ $hash ] ) ) {
			return 0;
		}
		$rec = $tokens[ $hash ];
		if ( (int) $rec['expires'] < time() ) {
			unset( $tokens[ $hash ] );
			update_option( self::TOKENS, $tokens, false );
			return 0;
		}
		return (int) $rec['user_id'];
	}

	/**
	 * Drop expired entries from a token store.
	 *
	 * @param array $store Store.
	 * @return array
	 */
	private function prune( array $store ) {
		$now = time();
		foreach ( $store as $k => $rec ) {
			if ( ! isset( $rec['expires'] ) || (int) $rec['expires'] < $now ) {
				unset( $store[ $k ] );
			}
		}
		return $store;
	}

	/* --------------------------------------------------------------------- */
	/* Connection management                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Live connections, one row per client and account.
	 *
	 * Until now a connection could be granted but never withdrawn: there was
	 * nothing listing what had been authorized and no way to cut it off short
	 * of changing the WordPress password. An access token is good for 30 days
	 * and a refresh token for 180.
	 *
	 * @return array
	 */
	public function connections() {
		$clients = (array) get_option( self::CLIENTS, array() );
		$rows    = array();
		$now     = time();

		foreach ( array( self::TOKENS => 'access', self::REFRESH => 'refresh' ) as $store => $kind ) {
			foreach ( (array) get_option( $store, array() ) as $rec ) {
				if ( empty( $rec['client_id'] ) || empty( $rec['user_id'] ) || (int) $rec['expires'] < $now ) {
					continue;
				}
				$key = $rec['client_id'] . '|' . (int) $rec['user_id'];

				if ( ! isset( $rows[ $key ] ) ) {
					$user   = get_userdata( (int) $rec['user_id'] );
					$client = isset( $clients[ $rec['client_id'] ] ) ? $clients[ $rec['client_id'] ] : array();
					$rows[ $key ] = array(
						'client_id'   => (string) $rec['client_id'],
						'client_name' => empty( $client['client_name'] ) ? __( 'Unnamed client', 'simonov-ai-site-manager' ) : $client['client_name'],
						'destination' => empty( $client['redirect_uris'][0] ) ? '' : (string) wp_parse_url( $client['redirect_uris'][0], PHP_URL_HOST ),
						'user_id'     => (int) $rec['user_id'],
						'user_login'  => $user ? $user->user_login : __( 'deleted account', 'simonov-ai-site-manager' ),
						'access_until'  => 0,
						'refresh_until' => 0,
					);
				}

				$field = 'access' === $kind ? 'access_until' : 'refresh_until';
				$rows[ $key ][ $field ] = max( $rows[ $key ][ $field ], (int) $rec['expires'] );
			}
		}

		return array_values( $rows );
	}

	/**
	 * Withdraw a connection: its tokens now, and its registration with them.
	 *
	 * @param string $client_id Client to revoke.
	 * @param int    $user_id   Account to revoke it for, or 0 for every account.
	 * @return int Number of tokens removed.
	 */
	public function revoke( $client_id, $user_id = 0 ) {
		$client_id = (string) $client_id;
		$user_id   = (int) $user_id;
		$removed   = 0;

		foreach ( array( self::TOKENS, self::REFRESH ) as $store ) {
			$records = (array) get_option( $store, array() );
			foreach ( $records as $hash => $rec ) {
				if ( empty( $rec['client_id'] ) || (string) $rec['client_id'] !== $client_id ) {
					continue;
				}
				if ( $user_id && (int) $rec['user_id'] !== $user_id ) {
					continue;
				}
				unset( $records[ $hash ] );
				$removed++;
			}
			update_option( $store, $records, false );
		}

		// With no tokens left the registration is only a way back in under the
		// same name, so drop it too and make the client register afresh.
		if ( ! $this->client_has_tokens( $client_id ) ) {
			$clients = (array) get_option( self::CLIENTS, array() );
			unset( $clients[ $client_id ] );
			update_option( self::CLIENTS, $clients, false );
		}

		return $removed;
	}

	/**
	 * Withdraw every connection.
	 *
	 * @return int Number of tokens removed.
	 */
	public function revoke_all() {
		$removed = count( (array) get_option( self::TOKENS, array() ) ) + count( (array) get_option( self::REFRESH, array() ) );
		update_option( self::TOKENS, array(), false );
		update_option( self::REFRESH, array(), false );
		update_option( self::CLIENTS, array(), false );
		return $removed;
	}

	/**
	 * Whether a client still holds any unexpired token.
	 *
	 * @param string $client_id Client ID.
	 * @return bool
	 */
	private function client_has_tokens( $client_id ) {
		$in_use = $this->clients_with_tokens();
		return isset( $in_use[ (string) $client_id ] );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Fetch a registered client.
	 *
	 * @param string $client_id Client ID.
	 * @return array|null
	 */
	private function get_client( $client_id ) {
		$clients = (array) get_option( self::CLIENTS, array() );
		return isset( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
	}

	/**
	 * Validate and normalize a redirect URI.
	 *
	 * @param mixed $uri Candidate.
	 * @return string Empty string when unacceptable.
	 */
	private function clean_redirect_uri( $uri ) {
		$uri = esc_url_raw( trim( (string) $uri ), array( 'https', 'http' ) );
		if ( '' === $uri ) {
			return '';
		}
		$host   = strtolower( (string) wp_parse_url( $uri, PHP_URL_HOST ) );
		$scheme = strtolower( (string) wp_parse_url( $uri, PHP_URL_SCHEME ) );

		// Loopback may use http; everything else must be https.
		$is_loopback = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		if ( 'https' !== $scheme && ! $is_loopback ) {
			return '';
		}
		return $uri;
	}

	/**
	 * Redirect back to the client with an authorization code.
	 *
	 * @param string $redirect_uri Redirect URI.
	 * @param string $code         Code.
	 * @param string $state        State.
	 */
	private function redirect_success( $redirect_uri, $code, $state ) {
		$args = array( 'code' => $code );
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- client-registered URI.
		exit;
	}

	/**
	 * Redirect back to the client with an error.
	 *
	 * @param string $redirect_uri Redirect URI.
	 * @param string $error        Error code.
	 * @param string $state        State.
	 * @param string $description  Optional description.
	 */
	private function redirect_error( $redirect_uri, $error, $state, $description = '' ) {
		$args = array( 'error' => $error );
		if ( '' !== $description ) {
			$args['error_description'] = $description;
		}
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- client-registered URI.
		exit;
	}

	/**
	 * base64url without padding.
	 *
	 * @param string $bin Binary string.
	 * @return string
	 */
	private function b64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/**
	 * Request method.
	 *
	 * @return string
	 */
	private function method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/**
	 * The current full URL.
	 *
	 * @return string
	 */
	private function current_url() {
		// The OAuth parameters ride in this URL's query string, so it must keep
		// its percent-encoding intact — sanitize_text_field() would strip it.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $uri );
	}

	/**
	 * Sanitized GET params.
	 *
	 * @return array
	 */
	private function get_params() {
		return $this->clean_params( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth params, validated below.
	}

	/**
	 * Sanitized POST params (form-encoded or JSON).
	 *
	 * @return array
	 */
	private function post_params() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the consent
		// POST is verified with check_admin_referer() by its caller; the token and
		// refresh grants are unauthenticated by the OAuth specification and are
		// bound by PKCE instead.
		if ( ! empty( $_POST ) ) {
			return $this->clean_params( $_POST );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$json = json_decode( $this->raw_body(), true );
		return is_array( $json ) ? $this->clean_params( $json ) : array();
	}

	/**
	 * Shallow-sanitize a params array to strings.
	 *
	 * @param array $raw Raw input.
	 * @return array
	 */
	private function clean_params( $raw ) {
		$out = array();
		foreach ( (array) $raw as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$out[ sanitize_key( $k ) ] = sanitize_text_field( wp_unslash( (string) $v ) );
			}
		}
		return $out;
	}

	/**
	 * Raw request body.
	 *
	 * @return string
	 */
	private function raw_body() {
		return (string) file_get_contents( 'php://input' );
	}

	/**
	 * Emit permissive CORS headers for token/register.
	 */
	private function cors() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );
	}

	/**
	 * Whether this is a CORS preflight.
	 *
	 * @return bool
	 */
	private function is_preflight() {
		return 'OPTIONS' === $this->method();
	}

	/**
	 * End a preflight request.
	 */
	private function no_content() {
		status_header( 204 );
		exit;
	}

	/**
	 * Send a JSON document and stop.
	 *
	 * @param array $data   Payload.
	 * @param int   $status HTTP status.
	 */
	private function send_json( $data, $status = 200 ) {
		if ( ! headers_sent() ) {
			status_header( $status );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
		}
		echo wp_json_encode( $data );
		exit;
	}
}
