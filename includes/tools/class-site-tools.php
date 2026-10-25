<?php
/**
 * Site-level tools: options, settings, site info, menus.
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

use WPMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Site_Tools
 */
class Site_Tools extends Abstract_Tool {

	/**
	 * Options that must never be exposed or written through the MCP bridge.
	 *
	 * @var string[]
	 */
	private $blocked = array( 'wpmcp_settings', 'auth_key', 'auth_salt', 'secret' );

	/**
	 * Substrings that mark an option as unreadable.
	 *
	 * Reads get a deny-list rather than an allow-list because the useful
	 * surface is genuinely open-ended, but anything smelling of a credential
	 * is refused: third-party plugins keep live API keys and SMTP passwords
	 * in options, and this bridge hands whatever it reads to an external
	 * model. Over-blocking a read is a nuisance; leaking a Stripe key is not.
	 *
	 * @var string[]
	 */
	private $secret_fragments = array(
		'wpmcp',
		'auth_key',
		'auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
		'secret',
		'password',
		'passwd',
		'_pwd',
		'api_key',
		'apikey',
		'_token',
		'token_',
		'access_key',
		'private_key',
		'license',
		'credential',
		'smtp',
		'mailserver',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public function register( Tool_Registry $r ) {

		$r->add(
			'get_site_info',
			'Get general site information: name, URL, description, admin email, language, WordPress version, active theme, timezone.',
			$this->schema( array() ),
			array( $this, 'get_site_info' ),
			'read'
		);

		$r->add(
			'get_option',
			'Read a WordPress option / setting by name.',
			$this->schema( array( 'name' => $this->str( 'Option name.' ) ), array( 'name' ) ),
			array( $this, 'get_option' ),
			'manage_options'
		);

		$r->add(
			'list_nav_menus',
			'List navigation menus and their items.',
			$this->schema( array() ),
			array( $this, 'list_nav_menus' ),
			'edit_theme_options'
		);
	}

	/**
	 * Site info.
	 *
	 * @return array
	 */
	public function get_site_info() {
		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'admin_url'   => admin_url(),
			// This tool runs at the 'read' capability, which every logged-in
			// user holds; the admin address is only for those who could read
			// it from Settings anyway.
			'admin_email' => current_user_can( 'manage_options' ) ? get_option( 'admin_email' ) : null,
			'language'    => get_bloginfo( 'language' ),
			'wp_version'  => get_bloginfo( 'version' ),
			'active_theme'=> array(
				'name'    => wp_get_theme()->get( 'Name' ),
				'stylesheet' => get_stylesheet(),
				'version' => wp_get_theme()->get( 'Version' ),
			),
			'timezone'    => wp_timezone_string(),
			'multisite'   => is_multisite(),
			'woocommerce' => class_exists( 'WooCommerce' ),
			'polylang'    => function_exists( 'pll_languages_list' ),
		);
	}

	/**
	 * Get an option.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception When blocked.
	 */
	public function get_option( array $args ) {
		$name = (string) $this->required( $args, 'name' );
		$this->guard_read( $name );
		return array(
			'name'  => $name,
			'value' => $this->redact( get_option( $name ) ),
		);
	}

	/**
	 * Blank out credential-looking entries inside an option value.
	 *
	 * guard_read() can only judge the option NAME, and plugins almost never
	 * give a secret its own option: they store one settings array under a
	 * perfectly innocent name. woocommerce_stripe_settings holds secret_key,
	 * SMTP plugins keep the mailbox password inside their *_options array.
	 * None of those names match the deny-list, so without this the whole array
	 * — live API keys included — is handed to an external model.
	 *
	 * Keys are matched with the same fragment list used for names, so the two
	 * halves of the check cannot drift apart.
	 *
	 * @param mixed $value Option value.
	 * @param int   $depth Recursion guard for self-referential structures.
	 * @return mixed
	 */
	private function redact( $value, $depth = 0 ) {
		if ( $depth > 10 || ! is_array( $value ) ) {
			return $value;
		}

		$out = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && $this->looks_secret( $key ) ) {
				$out[ $key ] = '[redacted by simonov-ai-site-manager]';
				continue;
			}
			$out[ $key ] = $this->redact( $item, $depth + 1 );
		}
		return $out;
	}

	/**
	 * Whether a name reads like a credential.
	 *
	 * @param string $name Option or array key.
	 * @return bool
	 */
	private function looks_secret( $name ) {
		foreach ( $this->secret_fragments as $fragment ) {
			if ( false !== stripos( $name, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * List nav menus.
	 *
	 * @return array
	 */
	public function list_nav_menus() {
		$menus = wp_get_nav_menus();
		$out   = array();
		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			$out[] = array(
				'id'    => $menu->term_id,
				'name'  => $menu->name,
				'slug'  => $menu->slug,
				'count' => $menu->count,
				'items' => array_map(
					static function ( $i ) {
						return array(
							'id'     => $i->ID,
							'title'  => $i->title,
							'url'    => $i->url,
							'parent' => (int) $i->menu_item_parent,
							'order'  => (int) $i->menu_order,
						);
					},
					$items ? $items : array()
				),
			);
		}
		return $out;
	}

	/**
	 * Reject a READ of a blocked / credential-bearing option.
	 *
	 * Whatever is read leaves the site for a
	 * third-party model, so any credential-looking name is refused.
	 *
	 * @param string $name Option name.
	 * @throws \Exception When blocked.
	 */
	private function guard_read( $name ) {
		foreach ( $this->blocked as $b ) {
			if ( false !== stripos( $name, $b ) ) {
				throw new \Exception( esc_html( 'Access to option "' . $name . '" is blocked for security.' ) );
			}
		}
		if ( $this->looks_secret( $name ) ) {
			throw new \Exception( esc_html( 'Access to option "' . $name . '" is blocked: it may hold a credential.' ) );
		}
	}
}
