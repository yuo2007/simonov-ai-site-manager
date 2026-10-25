<?php
/**
 * Plugin Name:       Simonov AI Site Manager (MCP)
 * Plugin URI:        https://github.com/yuo2007/simonov-ai-site-manager
 * Description:        Free, open Model Context Protocol (MCP) server for WordPress. Lets Claude, ChatGPT and other MCP clients manage posts, media, taxonomies, comments, WooCommerce and Polylang translations.
 * Version:           0.9.0
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Author:            Oleksandr Simonov
 * Author URI:        https://www.linkedin.com/in/%D0%BE%D0%BB%D0%B5%D0%BA%D1%81%D0%B0%D0%BD%D0%B4%D1%80-%D1%81%D1%96%D0%BC%D0%BE%D0%BD%D0%BE%D0%B2-522aa2b0/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       simonov-ai-site-manager
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

define( 'WPMCP_VERSION', '0.9.0' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMCP_URL', plugin_dir_url( __FILE__ ) );
define( 'WPMCP_NAMESPACE', 'wp-mcp/v1' );
define( 'WPMCP_MIN_WP', '5.8' );
define( 'WPMCP_MIN_PHP', '7.0' );

define( 'WPMCP_SLUG', 'simonov-ai-site-manager' );
define( 'WPMCP_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check that the stack is new enough to run the plugin.
 *
 * The "Requires at least" header already stops activation on an older
 * WordPress, but a site can be downgraded (or the plugin dropped in by FTP)
 * while the plugin stays active, so the same check runs here too.
 *
 * @return string Empty string when supported, otherwise the reason.
 */
function unsupported_reason() {
	if ( version_compare( PHP_VERSION, WPMCP_MIN_PHP, '<' ) ) {
		return sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'Simonov AI Site Manager requires PHP %1$s or newer. This site runs PHP %2$s.', 'simonov-ai-site-manager' ),
			WPMCP_MIN_PHP,
			PHP_VERSION
		);
	}

	$wp_version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '0';
	if ( version_compare( $wp_version, WPMCP_MIN_WP, '<' ) ) {
		return sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			__( 'Simonov AI Site Manager requires WordPress %1$s or newer. This site runs WordPress %2$s.', 'simonov-ai-site-manager' ),
			WPMCP_MIN_WP,
			$wp_version
		);
	}

	return '';
}

/**
 * Boot the plugin once all plugins are loaded so integrations
 * (WooCommerce, Polylang) can be detected reliably.
 */
function wpmcp() {
	return Plugin::instance();
}

$wpmcp_unsupported = unsupported_reason();

if ( '' !== $wpmcp_unsupported ) {
	// Stay inert rather than fatal: an unsupported site keeps its admin.
	add_action(
		'admin_notices',
		function () {
			$reason = unsupported_reason();
			if ( '' === $reason || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . esc_html( $reason ) . '</p></div>';
		}
	);
} else {
	require_once WPMCP_DIR . 'includes/class-plugin.php';
	wpmcp();
}
