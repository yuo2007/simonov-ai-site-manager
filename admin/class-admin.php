<?php
/**
 * Admin settings page.
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Transient key for the one-time plaintext token display.
	 */
	const FLASH_TOKEN = 'wpmcp_flash_token_';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Load the settings page script, on that page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( $hook_suffix ) {
		if ( 'settings_page_' . WPMCP_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script( 'wpmcp-admin', WPMCP_URL . 'admin/admin.js', array(), WPMCP_VERSION, true );
	}

	/**
	 * Register the menu page.
	 */
	public function menu() {
		add_options_page(
			'Simonov AI Site Manager (MCP)',
			'Simonov AI Site Manager',
			'manage_options',
			WPMCP_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle form submissions.
	 */
	public function handle_actions() {
		if ( empty( $_POST['wpmcp_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'wpmcp_settings' );

		$action = sanitize_text_field( wp_unslash( $_POST['wpmcp_action'] ) );
		$s      = wp_parse_args( (array) get_option( Auth::OPTION, array() ), array( 'disabled_tools' => array() ) );

		if ( 'save' === $action ) {
			$s['enabled']   = ! empty( $_POST['enabled'] );
			$s['read_only'] = ! empty( $_POST['read_only'] );
			$user_id      = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
			if ( $user_id && ! $this->validate_actor( $user_id ) ) {
				return;
			}
			$s['user_id'] = $user_id;
			// The form posts the tools that are CHECKED (= enabled); we store the
			// complement. Unchecked boxes are simply absent from $_POST, so the
			// list must be derived from the full registry, not from the request.
			$checked = isset( $_POST['enabled_tools'] )
				? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['enabled_tools'] ) )
				: array();
			$s['disabled_tools'] = array_values( array_diff( wpmcp()->tools->all_names(), $checked ) );
			update_option( Auth::OPTION, $s );
			add_settings_error( 'wpmcp', 'saved', 'Settings saved.', 'updated' );
		}

		if ( 'clear_log' === $action ) {
			wpmcp()->audit->clear();
			add_settings_error( 'wpmcp', 'cleared', 'Audit log cleared.', 'updated' );
		}

		if ( 'revoke' === $action ) {
			$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
			$for_user  = isset( $_POST['revoke_user'] ) ? (int) $_POST['revoke_user'] : 0;
			if ( '' === $client_id ) {
				add_settings_error( 'wpmcp', 'norevoke', 'Nothing to revoke.', 'error' );
			} else {
				$removed = wpmcp()->oauth->revoke( $client_id, $for_user );
				add_settings_error(
					'wpmcp',
					'revoked',
					sprintf( 'Connection revoked — %d token(s) removed. The client must sign in again to reconnect.', $removed ),
					'updated'
				);
			}
		}

		if ( 'revoke_all' === $action ) {
			$removed = wpmcp()->oauth->revoke_all();
			add_settings_error(
				'wpmcp',
				'revoked_all',
				sprintf( 'All connections revoked — %d token(s) removed.', $removed ),
				'updated'
			);
		}

		if ( 'rotate' === $action ) {
			$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
			if ( ! $user_id ) {
				add_settings_error( 'wpmcp', 'nouser', 'Select a user before generating a token.', 'error' );
			} elseif ( $this->validate_actor( $user_id ) ) {
				$token = wpmcp()->auth->rotate_token( $user_id );
				set_transient( self::FLASH_TOKEN . get_current_user_id(), $token, 120 );
				add_settings_error( 'wpmcp', 'rotated', 'New token generated. Copy it now — it will not be shown again.', 'updated' );
			}
		}
		// Notices are rendered from the global store on this same request (no redirect,
		// so add_settings_error() output survives to render()).
	}

	/**
	 * Validate that the submitted actor user is allowed.
	 *
	 * The dropdown restricts the choices visually (role__in), but the posted
	 * user_id is attacker-controllable. On multisite an unchecked value lets a
	 * single-site admin mint a token acting as the network super-admin.
	 *
	 * @param int $user_id Candidate user ID.
	 * @return bool True when acceptable; otherwise emits a notice and returns false.
	 */
	private function validate_actor( $user_id ) {
		$user    = get_user_by( 'id', $user_id );
		$allowed = array( 'administrator', 'editor', 'shop_manager' );

		if ( ! $user || ! array_intersect( $allowed, (array) $user->roles ) ) {
			add_settings_error( 'wpmcp', 'baduser', 'Selected user is not a permitted role.', 'error' );
			return false;
		}
		if ( is_multisite() ) {
			if ( is_super_admin( $user_id ) && ! is_super_admin( get_current_user_id() ) ) {
				add_settings_error( 'wpmcp', 'baduser', 'You cannot issue a token acting as a network administrator.', 'error' );
				return false;
			}
			if ( ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				add_settings_error( 'wpmcp', 'baduser', 'Selected user is not a member of this site.', 'error' );
				return false;
			}
		}
		return true;
	}

	/**
	 * Render the settings page.
	 */
	public function render() {
		$s        = wp_parse_args(
			(array) get_option( Auth::OPTION, array() ),
			array(
				'enabled'        => false,
				'user_id'        => 0,
				'token_hash'     => '',
				'read_only'      => false,
				'disabled_tools' => array(),
			)
		);
		$endpoint = rest_url( WPMCP_NAMESPACE . '/mcp' );
		// Scoped to the current admin so a second manage_options user loading
		// this page cannot consume a token minted for someone else.
		$flash_key = self::FLASH_TOKEN . get_current_user_id();
		$flash     = get_transient( $flash_key );
		delete_transient( $flash_key );
		$tools    = wpmcp()->tools->all_names();
		sort( $tools );

		settings_errors( 'wpmcp' );
		?>
		<div class="wrap">
			<h1>Simonov AI Site Manager (MCP)</h1>
			<p>Free Model Context Protocol server. Connect Claude, ChatGPT and other MCP clients to manage this site's content.</p>

			<h2>Connection</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">MCP Endpoint URL</th>
					<td><code><?php echo esc_html( $endpoint ); ?></code></td>
				</tr>
				<tr>
					<th scope="row">Status</th>
					<td>
						<?php if ( wpmcp()->auth->is_enabled() ) : ?>
							<span style="color:#008a20;">&#10004; Active &mdash; ready to accept connections.</span>
						<?php else : ?>
							<span style="color:#b32d2e;">Not configured &mdash; select a user and generate a token below.</span>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php
			if ( $flash ) :
				$ready_url = add_query_arg( 'token', rawurlencode( $flash ), $endpoint );
				$config    = wp_json_encode(
					array(
						'mcpServers' => array(
							'wordpress' => array(
								'command' => 'npx',
								'args'    => array( '-y', 'mcp-remote', $endpoint, '--header', 'Authorization: Bearer ' . $flash ),
							),
						),
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				);
				?>
				<div class="notice notice-warning" style="padding-bottom:8px;">
					<p><strong>New token generated — copy what you need now. It will not be shown again.</strong></p>

					<p style="margin-bottom:4px;"><strong>Token</strong></p>
					<p style="display:flex;gap:6px;align-items:center;">
						<input type="text" readonly class="wpmcp-copy-src" style="flex:1;font-family:monospace;" value="<?php echo esc_attr( $flash ); ?>">
						<button type="button" class="button wpmcp-copy">Copy</button>
					</p>

					<p style="margin-bottom:4px;"><strong>Ready-to-paste connection URL</strong> — for claude.ai custom connectors and any client that takes a single URL:</p>
					<p style="display:flex;gap:6px;align-items:center;">
						<input type="text" readonly class="wpmcp-copy-src" style="flex:1;font-family:monospace;" value="<?php echo esc_attr( $ready_url ); ?>">
						<button type="button" class="button wpmcp-copy">Copy</button>
					</p>
					<p class="description" style="margin-top:0;">The token is part of this URL. Treat the whole URL as a password — anyone who has it controls the site as the configured user.</p>

					<p style="margin-bottom:4px;"><strong>Claude Desktop / Claude Code config</strong> — paste into your MCP config file:</p>
					<p style="display:flex;gap:6px;align-items:flex-start;">
						<textarea readonly class="wpmcp-copy-src" style="flex:1;font-family:monospace;height:9em;"><?php echo esc_textarea( $config ); ?></textarea>
						<button type="button" class="button wpmcp-copy">Copy</button>
					</p>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'wpmcp_settings' ); ?>
				<h2>Settings</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Enable MCP server</th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> Accept MCP requests</label></td>
					</tr>
					<tr>
						<th scope="row">Read-only mode</th>
						<td>
							<label><input type="checkbox" name="read_only" value="1" <?php checked( ! empty( $s['read_only'] ) ); ?>> Refuse anything that changes the site</label>
							<p class="description" style="max-width:640px;">
								Only the listing and reading tools stay available; everything that creates, edits
								or deletes disappears from the client's tool list and is refused
								if called anyway. Worth switching on whenever a client is connected for research or
								reporting rather than for editing &mdash; the model decides which tool to call, and
								its context is full of text from this site that anyone could have written.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Act as user</th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'name'             => 'user_id',
									'selected'         => $s['user_id'],
									'show_option_none' => '— select —',
									'option_none_value'=> 0,
									'role__in'         => array( 'administrator', 'editor', 'shop_manager' ),
								)
							);
							?>
							<p class="description">All MCP actions run as this user; WordPress capabilities are enforced accordingly. Use the least-privileged role that covers what you need &mdash; an editor is enough for content.</p>
						</td>
					</tr>
				</table>

				<?php
				$disabled_count = count( array_intersect( $tools, (array) $s['disabled_tools'] ) );
				$active_count   = count( $tools ) - $disabled_count;
				?>
				<h2>Enabled tools</h2>
				<p class="description">
					Uncheck a tool to disable it entirely (it will not appear in tools/list).
					<strong><?php echo (int) $active_count; ?></strong> of <strong><?php echo count( $tools ); ?></strong> tools active.
				</p>
				<p>
					<button type="button" class="button" id="wpmcp-check-all">Select all</button>
					<button type="button" class="button" id="wpmcp-uncheck-all">Deselect all</button>
					<span class="description">Ticks the boxes below &mdash; press &ldquo;Save settings&rdquo; to apply.</span>
				</p>
				<div style="column-count:3;column-gap:2em;max-width:1100px;">
					<?php
					foreach ( $tools as $tool ) :
						$is_on = ! in_array( $tool, (array) $s['disabled_tools'], true );
						?>
						<label style="display:block;">
							<input type="checkbox" class="wpmcp-tool" name="enabled_tools[]" value="<?php echo esc_attr( $tool ); ?>"
								<?php checked( $is_on ); ?>>
							<span style="text-decoration:<?php echo $is_on ? 'none' : 'line-through'; ?>;"><?php echo esc_html( $tool ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="description">Checked = <strong>enabled</strong>. Unchecked tools do not appear in <code>tools/list</code>.</p>

				<p class="submit">
					<button type="submit" name="wpmcp_action" value="save" class="button button-primary">Save settings</button>
					<button type="submit" name="wpmcp_action" value="rotate" class="button">Generate / rotate token</button>
				</p>
			</form>

			<?php $connections = wpmcp()->oauth->connections(); ?>
			<h2>Active connections</h2>
			<?php if ( empty( $connections ) ) : ?>
				<p class="description">No client has signed in with WordPress yet. Connections made that way appear here and can be withdrawn at any time.</p>
			<?php else : ?>
				<p class="description" style="max-width:880px;">
					Each row is a client that signed in and was approved. Revoking drops its tokens immediately and
					removes its registration, so it has to sign in again from scratch. Revoke anything you do not
					recognise — and anything you have stopped using, since a refresh token stays valid for 180 days.
				</p>
				<table class="widefat striped" style="max-width:880px;">
					<thead>
						<tr>
							<th>Client</th>
							<th>Acts as</th>
							<th>Access expires</th>
							<th>Can renew until</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $connections as $c ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $c['client_name'] ); ?></strong>
								<?php if ( $c['destination'] ) : ?>
									<br><span class="description"><?php echo esc_html( $c['destination'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $c['user_login'] ); ?></td>
							<td><?php echo $c['access_until'] ? esc_html( date_i18n( 'Y-m-d', $c['access_until'] ) ) : '&mdash;'; ?></td>
							<td><?php echo $c['refresh_until'] ? esc_html( date_i18n( 'Y-m-d', $c['refresh_until'] ) ) : '&mdash;'; ?></td>
							<td style="text-align:right;">
								<form method="post" style="margin:0;">
									<?php wp_nonce_field( 'wpmcp_settings' ); ?>
									<input type="hidden" name="client_id" value="<?php echo esc_attr( $c['client_id'] ); ?>">
									<input type="hidden" name="revoke_user" value="<?php echo esc_attr( $c['user_id'] ); ?>">
									<button type="submit" name="wpmcp_action" value="revoke" class="button button-small">Revoke</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" style="margin-top:10px;">
					<?php wp_nonce_field( 'wpmcp_settings' ); ?>
					<button type="submit" name="wpmcp_action" value="revoke_all" class="button">Revoke all connections</button>
				</form>
			<?php endif; ?>

			<?php $log = wpmcp()->audit->entries(); ?>
			<h2>Recent tool calls</h2>
			<?php if ( empty( $log ) ) : ?>
				<p class="description">Nothing yet. Every tool a client runs is recorded here, including the ones that were refused.</p>
			<?php else : ?>
				<p class="description" style="max-width:880px;">
					The last <?php echo (int) count( $log ); ?> calls, newest first. Capability checks decide what a
					connection <em>may</em> do; this is what it actually did. Only identifiers are kept &mdash; never
					post content, option values or passwords.
				</p>
				<table class="widefat striped" style="max-width:880px;">
					<thead>
						<tr><th>When</th><th>Tool</th><th>Result</th><th>Arguments</th><th>Account</th></tr>
					</thead>
					<tbody>
					<?php
					$colours = array(
						'ok'      => '#008a20',
						'denied'  => '#b32d2e',
						'blocked' => '#b32d2e',
						'error'   => '#bd8600',
						'unknown' => '#b32d2e',
					);
					foreach ( $log as $row ) :
						$user   = get_userdata( (int) ( $row['u'] ?? 0 ) );
						$result = (string) ( $row['r'] ?? '' );
						?>
						<tr>
							<td style="white-space:nowrap;"><?php echo esc_html( date_i18n( 'Y-m-d H:i', (int) ( $row['t'] ?? 0 ) ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $row['tool'] ?? '' ) ); ?></code></td>
							<td style="color:<?php echo esc_attr( $colours[ $result ] ?? '#3c434a' ); ?>;">
								<?php echo esc_html( $result ); ?>
								<?php if ( ! empty( $row['d'] ) ) : ?>
									<br><span class="description"><?php echo esc_html( (string) $row['d'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><span class="description"><?php echo esc_html( (string) ( $row['a'] ?? '' ) ); ?></span></td>
							<td><?php echo esc_html( $user ? $user->user_login : '&mdash;' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" style="margin-top:10px;">
					<?php wp_nonce_field( 'wpmcp_settings' ); ?>
					<button type="submit" name="wpmcp_action" value="clear_log" class="button">Clear log</button>
				</form>
			<?php endif; ?>

			<h2>Connect a client</h2>

			<h3 style="margin-bottom:4px;">Option A — claude.ai (recommended): sign in with WordPress</h3>
			<p style="margin-top:0;">
				In Claude, add a custom connector and paste this <strong>full MCP endpoint URL</strong>
				(not just the domain — the site root is not an MCP server):
			</p>
			<p style="display:flex;gap:6px;align-items:center;max-width:760px;">
				<input type="text" readonly class="wpmcp-copy-src" style="flex:1;font-family:monospace;" value="<?php echo esc_attr( $endpoint ); ?>">
				<button type="button" class="button wpmcp-copy2" data-copy="<?php echo esc_attr( $endpoint ); ?>">Copy</button>
			</p>
			<p class="description" style="margin-top:0;">
				Claude reads the URL, opens your WordPress login, you approve access once, and it connects —
				no token to copy or paste. Access is granted as the WordPress user who signs in.
				<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
					<br><strong style="color:#b32d2e;">Your site uses plain permalinks.</strong> Set
					<a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">Settings → Permalinks</a>
					to any &ldquo;pretty&rdquo; option so the sign-in endpoints work.
				<?php endif; ?>
			</p>

			<h3 style="margin-bottom:4px;">Option B — token (Claude Desktop, Claude Code, scripts)</h3>
			<p style="margin-top:0;">
				Press <strong>Generate / rotate token</strong> above. The connection URL and a ready-made config
				appear at the top of this page, each with a Copy button.
			</p>

			<?php
			$ua_probe = sprintf(
				'curl -A "Claude-User/1.0 (+Claude-User@anthropic.com)" -o /dev/null -w "%%{http_code}\n" %s',
				home_url( '/' )
			);
			?>
			<h2>The client signs in, then says the server refused it</h2>
			<p style="max-width:880px;margin-top:0;">
				If Claude completes the WordPress login and then reports that the server
				<em>rejected its request</em>, the cause is almost never this plugin. It is a filter in
				front of WordPress that blocks AI user agents.
			</p>
			<p style="max-width:880px;">
				The sign-in runs in <strong>your browser</strong>, so it passes. The MCP request that follows
				is made by the <strong>AI provider's own servers</strong>, carrying their own user agent — and
				that is what gets blocked. Hence the confusing &ldquo;you signed in, but then it was refused&rdquo;.
			</p>
			<p style="max-width:880px;">
				Every provider uses <strong>separate</strong> agents for crawling and for requests a person
				actually asked for. Only the second kind has to be allowed:
			</p>
			<table class="widefat striped" style="max-width:880px;">
				<thead>
					<tr>
						<th>Client</th>
						<th>Needed here — allow</th>
						<th>Crawler — may stay blocked</th>
					</tr>
				</thead>
				<tbody>
					<tr><td>Claude</td><td><code>Claude-User</code></td><td><code>ClaudeBot</code>, <code>Claude-SearchBot</code></td></tr>
					<tr><td>ChatGPT</td><td><code>ChatGPT-User</code></td><td><code>GPTBot</code>, <code>OAI-SearchBot</code></td></tr>
					<tr><td>Perplexity</td><td><code>Perplexity-User</code></td><td><code>PerplexityBot</code></td></tr>
				</tbody>
			</table>
			<p style="max-width:880px;">
				Allowing the user-initiated agent does <strong>not</strong> open the site to AI training
				crawlers. Those arrive under the other names, and you can keep blocking every one of them.
			</p>

			<p style="max-width:880px;margin-bottom:4px;">To check this site from any machine with <code>curl</code>:</p>
			<p style="display:flex;gap:6px;align-items:center;max-width:880px;">
				<input type="text" readonly style="flex:1;font-family:monospace;" value="<?php echo esc_attr( $ua_probe ); ?>">
				<button type="button" class="button wpmcp-copy2" data-copy="<?php echo esc_attr( $ua_probe ); ?>">Copy</button>
			</p>
			<p class="description" style="max-width:880px;margin-top:0;">
				<strong>200</strong> means nothing is blocking. A <strong>403</strong>, <strong>405</strong>,
				<strong>406</strong> or <strong>418</strong> — especially as a plain HTML error page rather than
				WordPress output — means the request never reached WordPress at all. Compare against the same
				command without the <code>-A</code> option: if that one succeeds, the user agent is the only
				difference, and the block is confirmed.
			</p>

			<p style="max-width:880px;margin-bottom:4px;">Where such a filter normally lives, worth checking in this order:</p>
			<ul style="max-width:880px;list-style:disc;margin-left:22px;">
				<li>
					<strong>Your hosting control panel</strong> — an option along the lines of &ldquo;Block AI
					bots&rdquo;, &ldquo;AI crawler protection&rdquo; or &ldquo;Anti-scraping&rdquo;. If you cannot
					find one, ask support: on shared hosting the rule sits in the server's own nginx or Apache
					config, where no plugin and no <code>.htaccess</code> of yours can override it.
				</li>
				<li>
					<strong>Cloudflare</strong> or a similar CDN — Security &rarr; Bots &rarr; &ldquo;Block AI
					Scrapers and Crawlers&rdquo;, or a custom WAF rule matching the user agent.
				</li>
				<li>
					<strong>A security or AI-blocking plugin</strong> — Wordfence and friends can filter by user
					agent, and &ldquo;block AI&rdquo; plugins add these names by default.
				</li>
			</ul>
			<p class="description" style="max-width:880px;">
				Ask for the user-initiated agent to be removed from the block list by name. Blocking it is rarely
				deliberate: these lists are usually copied wholesale from a published crawler list, without
				noticing that one entry on it is how your own integrations reach the site.
			</p>

		</div>
		<?php
	}
}
