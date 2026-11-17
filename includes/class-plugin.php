<?php
/**
 * Main plugin bootstrap.
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 *
 * Loads dependencies, registers the REST endpoint and the admin UI.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Tool registry.
	 *
	 * @var Tool_Registry
	 */
	public $tools;

	/**
	 * Auth handler.
	 *
	 * @var Auth
	 */
	public $auth;

	/**
	 * OAuth server.
	 *
	 * @var OAuth
	 */
	public $oauth;

	/**
	 * Update checker.
	 *
	 * @var Updater
	 */
	public $updater;

	/**
	 * Tool-call audit log.
	 *
	 * @var Audit
	 */
	public $audit;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->includes();
		$this->auth  = new Auth();
		$this->audit = new Audit();
		$this->tools = new Tool_Registry();
		$this->oauth = new OAuth();
		$this->oauth->register();

		// Registered on every request, not just admin: wp_update_plugins()
		// also runs from cron, which fires on the front end.
		$this->updater = new Updater();
		$this->updater->register();

		add_action( 'plugins_loaded', array( $this, 'register_tools' ), 20 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		if ( is_admin() ) {
			new Admin();
		}

		register_activation_hook( WPMCP_FILE, array( __CLASS__, 'activate' ) );
	}

	/**
	 * Load class files.
	 */
	private function includes() {
		require_once WPMCP_DIR . 'includes/class-auth.php';
		require_once WPMCP_DIR . 'includes/class-oauth.php';
		require_once WPMCP_DIR . 'includes/class-updater.php';
		require_once WPMCP_DIR . 'includes/class-audit.php';
		require_once WPMCP_DIR . 'includes/class-tool-registry.php';
		require_once WPMCP_DIR . 'includes/class-mcp-server.php';
		require_once WPMCP_DIR . 'includes/class-rest-controller.php';
		require_once WPMCP_DIR . 'includes/tools/class-abstract-tool.php';
		require_once WPMCP_DIR . 'includes/tools/class-posts-tools.php';
		require_once WPMCP_DIR . 'includes/tools/class-taxonomy-tools.php';
		require_once WPMCP_DIR . 'includes/tools/class-media-tools.php';
		require_once WPMCP_DIR . 'includes/tools/class-user-tools.php';
		require_once WPMCP_DIR . 'includes/tools/class-comment-tools.php';
		require_once WPMCP_DIR . 'includes/tools/class-site-tools.php';
		require_once WPMCP_DIR . 'includes/tools/class-plugin-theme-tools.php';
		require_once WPMCP_DIR . 'includes/tools/class-woocommerce-tools.php';
		require_once WPMCP_DIR . 'includes/tools/class-polylang-tools.php';
		require_once WPMCP_DIR . 'includes/tools/class-seo-tools.php';
		require_once WPMCP_DIR . 'admin/class-admin.php';
	}

	/**
	 * Instantiate and register every tool group.
	 */
	public function register_tools() {
		$groups = array(
			new Tools\Posts_Tools(),
			new Tools\Taxonomy_Tools(),
			new Tools\Media_Tools(),
			new Tools\User_Tools(),
			new Tools\Comment_Tools(),
			new Tools\Site_Tools(),
			new Tools\Plugin_Theme_Tools(),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$groups[] = new Tools\WooCommerce_Tools();
		}

		if ( function_exists( 'pll_languages_list' ) ) {
			$groups[] = new Tools\Polylang_Tools();
		}

		if ( Tools\Seo_Tools::active_plugins() ) {
			$groups[] = new Tools\Seo_Tools();
		}

		/**
		 * Allow third parties to register additional tool groups.
		 *
		 * @param array         $groups   Array of tool-group objects exposing register( $registry ).
		 * @param Tool_Registry $registry The registry.
		 */
		$groups = apply_filters( 'wpmcp_tool_groups', $groups, $this->tools );

		foreach ( $groups as $group ) {
			$group->register( $this->tools );
		}

		do_action( 'wpmcp_register_tools', $this->tools );
	}

	/**
	 * Register the REST route(s).
	 */
	public function register_routes() {
		( new REST_Controller( $this->auth, $this->tools ) )->register_routes();
	}

	/**
	 * Activation: make sure a token secret exists.
	 */
	public static function activate() {
		if ( ! get_option( 'wpmcp_settings' ) ) {
			add_option(
				'wpmcp_settings',
				array(
					'enabled'        => true,
					'token'          => '',
					'user_id'        => 0,
					'disabled_tools' => array(),
				)
			);
		}
	}
}
