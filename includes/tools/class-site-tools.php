<?php
/**
 * Site-level tools: site info, menus.
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
	 * {@inheritDoc}
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public function register( Tool_Registry $r ) {

		$r->add(
			'get_site_info',
			'Get general site information: name, URL, description, language, WordPress version, active theme, timezone.',
			$this->schema( array() ),
			array( $this, 'get_site_info' ),
			'read'
		);

		$r->add(
			'list_nav_menus',
			'List navigation menus and their items.',
			$this->schema( array() ),
			array( $this, 'list_nav_menus' ),
			// Menus are public on the front end, and edit_theme_options is
			// never granted over MCP (see Auth::DENIED_CAPS).
			'edit_posts'
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
}
