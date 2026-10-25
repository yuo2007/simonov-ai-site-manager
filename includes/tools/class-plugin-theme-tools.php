<?php
/**
 * Plugin & theme inventory tools.
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

use WPMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin_Theme_Tools
 *
 * Read-only: lists installed plugins and themes with their status and
 * update availability. Installing, activating, updating and deleting
 * plugins or themes is site administration and stays in the dashboard;
 * this build deliberately offers no tool for it.
 */
class Plugin_Theme_Tools extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public function register( Tool_Registry $r ) {

		$r->add(
			'list_plugins',
			'List all installed plugins with status (active/inactive), version and update availability.',
			$this->schema( array() ),
			array( $this, 'list_plugins' ),
			'activate_plugins'
		);

		$r->add(
			'list_themes',
			'List installed themes with active status, version and update availability.',
			$this->schema( array() ),
			array( $this, 'list_themes' ),
			'switch_themes'
		);
	}

	/**
	 * List plugins.
	 *
	 * @return array
	 */
	public function list_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all     = get_plugins();
		$updates = get_site_transient( 'update_plugins' );
		$out     = array();
		foreach ( $all as $file => $data ) {
			$out[] = array(
				'plugin'           => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'active'           => is_plugin_active( $file ),
				'update_available' => isset( $updates->response[ $file ] ),
				'new_version'      => $updates->response[ $file ]->new_version ?? null,
				'description'      => wp_strip_all_tags( $data['Description'] ),
			);
		}
		return $out;
	}

	/**
	 * List themes.
	 *
	 * @return array
	 */
	public function list_themes() {
		$updates = get_site_transient( 'update_themes' );
		$active  = get_stylesheet();
		$out     = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$out[] = array(
				'stylesheet'       => $stylesheet,
				'name'             => $theme->get( 'Name' ),
				'version'          => $theme->get( 'Version' ),
				'active'           => $stylesheet === $active,
				'update_available' => isset( $updates->response[ $stylesheet ] ),
				'new_version'      => $updates->response[ $stylesheet ]['new_version'] ?? null,
			);
		}
		return $out;
	}
}
