<?php
/**
 * Plugin & theme management tools.
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

use WPMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin_Theme_Tools
 *
 * Install / activate / update / delete plugins and themes. These are the
 * highest-risk operations; every one requires the matching WP capability
 * and the connected token acts as a real user, so capabilities are enforced.
 */
class Plugin_Theme_Tools extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public function register( Tool_Registry $r ) {

		// ---- Plugins ----
		$r->add(
			'list_plugins',
			'List all installed plugins with status (active/inactive), version and update availability.',
			$this->schema( array() ),
			array( $this, 'list_plugins' ),
			'activate_plugins'
		);

		$r->add(
			'activate_plugin',
			'Activate an installed plugin by its file path (e.g. "woocommerce/woocommerce.php").',
			$this->schema( array( 'plugin' => $this->str( 'Plugin file path relative to plugins dir.' ) ), array( 'plugin' ) ),
			array( $this, 'activate_plugin' ),
			'activate_plugins'
		);

		$r->add(
			'deactivate_plugin',
			'Deactivate an active plugin by its file path.',
			$this->schema( array( 'plugin' => $this->str( 'Plugin file path.' ) ), array( 'plugin' ) ),
			array( $this, 'deactivate_plugin' ),
			'activate_plugins'
		);

		$r->add(
			'install_plugin',
			'Install a plugin from the WordPress.org directory by slug (e.g. "wordpress-seo"), then optionally activate it.',
			$this->schema(
				array(
					'slug'     => $this->str( 'WordPress.org plugin slug.' ),
					'activate' => $this->bool( 'Activate after install (default true).' ),
				),
				array( 'slug' )
			),
			array( $this, 'install_plugin' ),
			'install_plugins'
		);

		$r->add(
			'update_plugin',
			'Update an installed plugin to the latest version by its file path.',
			$this->schema( array( 'plugin' => $this->str( 'Plugin file path.' ) ), array( 'plugin' ) ),
			array( $this, 'update_plugin' ),
			'update_plugins'
		);

		$r->add(
			'delete_plugin',
			'Delete (uninstall) a plugin by its file path. Deactivates first if active.',
			$this->schema( array( 'plugin' => $this->str( 'Plugin file path.' ) ), array( 'plugin' ) ),
			array( $this, 'delete_plugin' ),
			'delete_plugins'
		);

		// ---- Themes ----
		$r->add(
			'list_themes',
			'List installed themes with active status, version and update availability.',
			$this->schema( array() ),
			array( $this, 'list_themes' ),
			'switch_themes'
		);

		$r->add(
			'activate_theme',
			'Switch the active theme by its stylesheet directory (e.g. "twentytwentyfour").',
			$this->schema( array( 'stylesheet' => $this->str( 'Theme stylesheet/folder name.' ) ), array( 'stylesheet' ) ),
			array( $this, 'activate_theme' ),
			'switch_themes'
		);

		$r->add(
			'install_theme',
			'Install a theme from the WordPress.org directory by slug, then optionally activate it.',
			$this->schema(
				array(
					'slug'     => $this->str( 'WordPress.org theme slug.' ),
					'activate' => $this->bool( 'Activate after install (default false).' ),
				),
				array( 'slug' )
			),
			array( $this, 'install_theme' ),
			'install_themes'
		);

		$r->add(
			'update_theme',
			'Update an installed theme by its stylesheet name.',
			$this->schema( array( 'stylesheet' => $this->str( 'Theme stylesheet name.' ) ), array( 'stylesheet' ) ),
			array( $this, 'update_theme' ),
			'update_themes'
		);

		$r->add(
			'delete_theme',
			'Delete an installed theme by its stylesheet name (cannot be the active theme).',
			$this->schema( array( 'stylesheet' => $this->str( 'Theme stylesheet name.' ) ), array( 'stylesheet' ) ),
			array( $this, 'delete_theme' ),
			'delete_themes'
		);
	}

	/**
	 * Load upgrade/plugin admin APIs.
	 *
	 * @throws \Exception When file modifications are disabled or the
	 *                    filesystem cannot be initialised.
	 */
	private function load_admin() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			throw new \Exception( 'File modifications are disabled on this site (DISALLOW_FILE_MODS).' );
		}

		if ( ! \WP_Filesystem() ) {
			// Only fall back to direct writes when the site has NOT chosen a
			// method itself. Overriding a deliberate FTP/SSH configuration
			// would defeat a hardening measure whose entire purpose is to stop
			// the web process from writing to the codebase.
			if ( defined( 'FS_METHOD' ) && FS_METHOD ) {
				throw new \Exception( 'Filesystem credentials are required for this operation (FS_METHOD is ' . FS_METHOD . ').' );
			}
			// A plain closure, not an arrow function: the plugin still has to
			// parse on PHP 7.0-7.3, where `fn()` is a syntax error.
			add_filter(
				'filesystem_method',
				static function () {
					return 'direct';
				}
			);
			if ( ! \WP_Filesystem() ) {
				throw new \Exception( 'Could not initialise the filesystem.' );
			}
		}
	}

	/**
	 * List plugins.
	 *
	 * @return array
	 */
	public function list_plugins() {
		$this->load_admin();
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		$all     = get_plugins();
		$updates = get_site_transient( 'update_plugins' );
		$out     = array();
		foreach ( $all as $file => $data ) {
			$out[] = array(
				'plugin'         => $file,
				'name'           => $data['Name'],
				'version'        => $data['Version'],
				'active'         => is_plugin_active( $file ),
				'update_available' => isset( $updates->response[ $file ] ),
				'new_version'    => $updates->response[ $file ]->new_version ?? null,
				'description'    => wp_strip_all_tags( $data['Description'] ),
			);
		}
		return $out;
	}

	/**
	 * Activate a plugin.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function activate_plugin( array $args ) {
		$this->load_admin();
		$plugin = (string) $this->required( $args, 'plugin' );
		$result = activate_plugin( $plugin );
		$this->unwrap( $result );
		return array(
			'plugin' => $plugin,
			'active' => is_plugin_active( $plugin ),
		);
	}

	/**
	 * Deactivate a plugin.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function deactivate_plugin( array $args ) {
		$this->load_admin();
		$plugin = (string) $this->required( $args, 'plugin' );

		if ( 0 !== validate_file( $plugin ) ) {
			throw new \Exception( 'Invalid plugin path: ' . $plugin );
		}
		// deactivate_plugins() trims and runs plugin_basename(), so " x/y.php",
		// "x//y.php" and the absolute path all reach the same plugin. Compare
		// the form it will actually act on, and only accept installed plugins.
		$plugin = plugin_basename( trim( $plugin ) );
		if ( ! array_key_exists( $plugin, get_plugins() ) ) {
			throw new \Exception( 'Plugin not installed: ' . $plugin );
		}
		// Deactivating itself leaves no way back in over MCP.
		if ( plugin_basename( WPMCP_FILE ) === $plugin ) {
			throw new \Exception( 'Refusing to deactivate the MCP plugin itself.' );
		}

		deactivate_plugins( $plugin );
		return array(
			'plugin' => $plugin,
			'active' => is_plugin_active( $plugin ),
		);
	}

	/**
	 * Install a plugin from wordpress.org.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception On failure.
	 */
	public function install_plugin( array $args ) {
		$this->load_admin();
		$slug = sanitize_key( $this->required( $args, 'slug' ) );

		$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		$this->unwrap( $api );

		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}
		if ( ! $result ) {
			throw new \Exception( 'Plugin installation failed. Check filesystem permissions.' );
		}

		$plugin_file = $upgrader->plugin_info();
		$activated   = false;
		if ( $plugin_file && ( ! isset( $args['activate'] ) || ! empty( $args['activate'] ) ) ) {
			$act = activate_plugin( $plugin_file );
			$activated = ! is_wp_error( $act );
		}

		return array(
			'slug'      => $slug,
			'plugin'    => $plugin_file,
			'installed' => true,
			'active'    => $activated,
		);
	}

	/**
	 * Update a plugin.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception On failure.
	 */
	public function update_plugin( array $args ) {
		$this->load_admin();
		$plugin = (string) $this->required( $args, 'plugin' );
		wp_update_plugins();

		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $plugin );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}
		return array(
			'plugin'  => $plugin,
			'updated' => (bool) $result,
			'version' => get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin )['Version'] ?? null,
		);
	}

	/**
	 * Delete a plugin.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception On failure.
	 */
	public function delete_plugin( array $args ) {
		$this->load_admin();
		$plugin = (string) $this->required( $args, 'plugin' );

		// delete_plugins() builds its path as dirname( $plugins_dir . $file )
		// and validates nothing, so "../../wp-includes/x.php" would recursively
		// delete wp-includes. validate_file() is the check core omits here.
		if ( 0 !== validate_file( $plugin ) ) {
			throw new \Exception( 'Invalid plugin path: ' . $plugin );
		}
		if ( ! array_key_exists( $plugin, get_plugins() ) ) {
			throw new \Exception( 'Unknown plugin: ' . $plugin );
		}
		if ( plugin_basename( WPMCP_FILE ) === $plugin ) {
			throw new \Exception( 'Refusing to delete the MCP plugin itself.' );
		}

		if ( is_plugin_active( $plugin ) ) {
			deactivate_plugins( $plugin );
		}
		$result = delete_plugins( array( $plugin ) );
		$this->unwrap( $result );
		return array(
			'plugin'  => $plugin,
			'deleted' => true === $result,
		);
	}

	/**
	 * List themes.
	 *
	 * @return array
	 */
	public function list_themes() {
		$this->load_admin();
		if ( function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}
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

	/**
	 * Activate a theme.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception When theme missing.
	 */
	public function activate_theme( array $args ) {
		$stylesheet = (string) $this->required( $args, 'stylesheet' );
		$theme      = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			throw new \Exception( 'Theme not found: ' . $stylesheet );
		}
		switch_theme( $stylesheet );
		return array(
			'stylesheet' => $stylesheet,
			'active'     => get_stylesheet() === $stylesheet,
		);
	}

	/**
	 * Install a theme.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception On failure.
	 */
	public function install_theme( array $args ) {
		$this->load_admin();
		$slug = sanitize_key( $this->required( $args, 'slug' ) );

		$api = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		$this->unwrap( $api );

		$upgrader = new \Theme_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}
		if ( ! $result ) {
			throw new \Exception( 'Theme installation failed. Check filesystem permissions.' );
		}

		$activated = false;
		if ( ! empty( $args['activate'] ) ) {
			switch_theme( $slug );
			$activated = get_stylesheet() === $slug;
		}
		return array(
			'slug'      => $slug,
			'installed' => true,
			'active'    => $activated,
		);
	}

	/**
	 * Update a theme.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception On failure.
	 */
	public function update_theme( array $args ) {
		$this->load_admin();
		$stylesheet = (string) $this->required( $args, 'stylesheet' );
		wp_update_themes();
		$upgrader = new \Theme_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $stylesheet );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}
		return array(
			'stylesheet' => $stylesheet,
			'updated'    => (bool) $result,
		);
	}

	/**
	 * Delete a theme.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception On failure.
	 */
	public function delete_theme( array $args ) {
		$this->load_admin();
		$stylesheet = (string) $this->required( $args, 'stylesheet' );

		// delete_theme() concatenates $themes_dir . $stylesheet unchecked, so
		// "../plugins" would wipe the plugin directory.
		if ( 0 !== validate_file( $stylesheet ) ) {
			throw new \Exception( 'Invalid stylesheet: ' . $stylesheet );
		}
		if ( ! wp_get_theme( $stylesheet )->exists() ) {
			throw new \Exception( 'Unknown theme: ' . $stylesheet );
		}
		if ( get_stylesheet() === $stylesheet || get_template() === $stylesheet ) {
			throw new \Exception( 'Cannot delete the active theme.' );
		}
		$result = delete_theme( $stylesheet );
		$this->unwrap( $result );
		return array(
			'stylesheet' => $stylesheet,
			'deleted'    => true === $result,
		);
	}
}
