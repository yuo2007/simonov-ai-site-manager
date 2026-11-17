<?php
/**
 * Self-hosted plugin updates served from GitHub releases.
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Class Updater
 *
 * WordPress 5.8 added a way for a plugin to host its own updates: the
 * "Update URI" header names a hostname, and core then asks the
 * update_plugins_{hostname} filter for the latest version instead of querying
 * WordPress.org. The site owner gets the ordinary one-click "Update now"
 * button, with no helper plugin on their site and no third-party library here.
 *
 * The newest GitHub release is the source of truth: its tag is the version and
 * the ZIP attached to it is the package.
 */
class Updater {

	/**
	 * Transient holding the parsed release — successes and failures alike.
	 */
	const CACHE_KEY = 'wpmcp_latest_release';

	/**
	 * How long a good answer is reused for ordinary background checks.
	 */
	const CACHE_TTL = 900;

	/**
	 * How long a good answer is reused on the Updates screen, where someone is
	 * actively looking. Matches the 60 s WordPress uses for its own data there.
	 */
	const FRESH_TTL = 60;

	/**
	 * How long a failure is remembered. Without this an unreachable or
	 * rate-limited API would mean a fresh 10-second HTTP request on every
	 * admin page load.
	 */
	const ERROR_TTL = 1800;

	/**
	 * Resolved release for this request, so one page load costs one lookup.
	 *
	 * @var array|false
	 */
	private $memo = false;

	/**
	 * Whether $memo holds a resolved answer (false is a valid one).
	 *
	 * @var bool
	 */
	private $memo_set = false;

	/**
	 * Register hooks.
	 *
	 * Not gated on is_admin(): wp_update_plugins() also runs from the
	 * wp_update_plugins cron event, which fires on front-end requests.
	 */
	public function register() {
		$host = wp_parse_url( WPMCP_UPDATE_URI, PHP_URL_HOST );
		if ( ! $host ) {
			return;
		}

		add_filter( 'update_plugins_' . $host, array( $this, 'check_for_update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ) );
	}

	/**
	 * Tell core whether a newer release exists.
	 *
	 * @param array|false $update      Update offered so far.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file, relative to the plugins dir.
	 * @return array|false
	 */
	public function check_for_update( $update, $plugin_data, $plugin_file ) {
		// github.com is a shared hostname — every other plugin that hosts its
		// updates there hooks this very filter, so anything that is not us has
		// to pass through untouched.
		if ( WPMCP_BASENAME !== $plugin_file ) {
			return $update;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			return $update;
		}

		// Core compares 'version' against the installed header itself and puts
		// the result in either response (newer) or no_update (same or older).
		return array(
			'slug'         => WPMCP_SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => WPMCP_MIN_WP,
			'requires_php' => WPMCP_MIN_PHP,
		);
	}

	/**
	 * Fill the "View details" modal, which would otherwise 404 against
	 * WordPress.org.
	 *
	 * @param false|object|array $result Result so far.
	 * @param string             $action API action.
	 * @param object             $args   Request args.
	 * @return false|object|array
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || WPMCP_SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			return $result;
		}

		$headers = get_file_data(
			WPMCP_FILE,
			array(
				'name'        => 'Plugin Name',
				'author'      => 'Author',
				'description' => 'Description',
			)
		);

		return (object) array(
			'name'          => $headers['name'],
			'slug'          => WPMCP_SLUG,
			'version'       => $release['version'],
			'author'        => $headers['author'],
			'homepage'      => WPMCP_UPDATE_URI,
			'download_link' => $release['package'],
			'requires'      => WPMCP_MIN_WP,
			'requires_php'  => WPMCP_MIN_PHP,
			'last_updated'  => $release['published'],
			'sections'      => array(
				'description' => wpautop( esc_html( $headers['description'] ) ),
				'changelog'   => $this->render_changelog( $release['changelog'] ),
			),
		);
	}

	/**
	 * Install the download into the folder this site already uses.
	 *
	 * WordPress names the destination after the folder inside the ZIP. Early
	 * releases were distributed as an archive with no wrapping folder, so the
	 * plugin sits in a differently named directory on different sites ("mcp",
	 * "wordpresmcp", ...). Renaming the unpacked source to whatever this
	 * install actually uses keeps an update an update, instead of a second copy
	 * in a new folder with the original left behind, active and stale.
	 *
	 * It also covers GitHub's own source archives, which unpack to
	 * "owner-repo-sha/".
	 *
	 * @param string|\WP_Error $source        Unpacked source directory.
	 * @param string           $remote_source Parent temp directory.
	 * @param \WP_Upgrader     $upgrader      Upgrader instance (unused).
	 * @param array            $hook_extra    Context.
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( empty( $hook_extra['plugin'] ) || WPMCP_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		global $wp_filesystem;

		// The same GitHub repository also hosts the WordPress.org edition,
		// whose main file has a different name. Installing that over this
		// plugin would leave WordPress pointing at a file that no longer
		// exists, and it would deactivate the plugin on every site at once.
		if ( $wp_filesystem && ! $wp_filesystem->exists( trailingslashit( $source ) . basename( WPMCP_FILE ) ) ) {
			return new \WP_Error(
				'wpmcp_wrong_package',
				__( 'The downloaded package is not WP MCP Control. The update was stopped and the installed version is untouched.', 'wp-mcp-control' )
			);
		}

		$expected = dirname( WPMCP_BASENAME );
		if ( '.' === $expected || '' === $expected ) {
			return $source;
		}

		if ( ! $wp_filesystem || basename( untrailingslashit( $source ) ) === $expected ) {
			return $source;
		}

		$target = trailingslashit( $remote_source ) . $expected;
		if ( $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}

		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $target ) ) {
			return new \WP_Error(
				'wpmcp_rename_failed',
				__( 'Could not prepare the downloaded package for installation.', 'wp-mcp-control' )
			);
		}

		return trailingslashit( $target );
	}

	/**
	 * Forget the cached release after an update so the row stops advertising it.
	 */
	public function flush_cache() {
		delete_transient( self::CACHE_KEY );
		$this->memo     = false;
		$this->memo_set = false;
	}

	/* --------------------------------------------------------------------- */
	/* Release lookup                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * The latest release, from cache when possible.
	 *
	 * @return array|false
	 */
	private function latest_release() {
		// check_for_update() and plugin_information() can both run in one
		// request; neither should cost a second round trip.
		if ( $this->memo_set ) {
			return $this->memo;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['fetched'] ) && ! $this->needs_refresh( $cached ) ) {
			// No 'version' is a remembered failure.
			return $this->remember( empty( $cached['version'] ) ? false : $cached );
		}

		$response = wp_remote_get(
			// Not /releases/latest: the repository also publishes the
			// WordPress.org edition, and whichever release is newest overall is
			// not necessarily one of ours. List them and pick our own instead.
			sprintf( 'https://api.github.com/repos/%s/releases?per_page=30', WPMCP_GH_REPO ),
			array(
				'timeout'    => 10,
				'user-agent' => 'wp-mcp-control/' . WPMCP_VERSION,
				'headers'    => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->store( array() );
			return $this->remember( false );
		}

		$parsed = $this->pick_release( json_decode( wp_remote_retrieve_body( $response ), true ) );

		$this->store( $parsed ? $parsed : array() );

		return $this->remember( $parsed );
	}

	/**
	 * Cache an answer, stamped so its age can be judged per context.
	 *
	 * The transient's own lifetime is only the outer bound; how long an entry
	 * is actually reused is decided by needs_refresh(), which cannot be
	 * expressed as a single expiry.
	 *
	 * @param array $payload Parsed release, or an empty array for a failure.
	 */
	private function store( array $payload ) {
		$payload['fetched'] = time();
		set_transient( self::CACHE_KEY, $payload, max( self::CACHE_TTL, self::ERROR_TTL ) );
	}

	/**
	 * Memoize the answer for the rest of the request.
	 *
	 * @param array|false $value Resolved release.
	 * @return array|false
	 */
	private function remember( $value ) {
		$this->memo     = $value;
		$this->memo_set = true;
		return $value;
	}

	/**
	 * Whether a cached answer is too old to reuse here.
	 *
	 * @param array $cached Cached payload.
	 * @return bool
	 */
	private function needs_refresh( array $cached ) {
		$age = time() - (int) $cached['fetched'];

		// Retry a failure sooner than a success is refreshed, so an outage
		// does not keep a site on an old version for the full cache window.
		if ( empty( $cached['version'] ) ) {
			return $age >= self::ERROR_TTL;
		}

		return $age >= $this->max_age();
	}

	/**
	 * How stale an answer may be, given why it is being asked for.
	 *
	 * A single long cache made the feature look broken: someone who opens the
	 * Updates screen is there *because* they want to know about updates, and
	 * being told "up to date" from a half-day-old answer reads as a fault, not
	 * as caching. WordPress refreshes its own plugin data every 60 seconds on
	 * that screen, so match it there rather than sitting on top of it, and keep
	 * a longer window for the background checks that fire on every admin page.
	 *
	 * The cache still exists for a reason: unauthenticated GitHub API calls are
	 * limited to 60 an hour per IP, and on shared hosting that IP is shared
	 * with every other site on the box.
	 *
	 * @return int Seconds.
	 */
	private function max_age() {
		// "Check again" is an explicit instruction to go and look.
		if ( ! empty( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache bypass.
			return 0;
		}

		if ( doing_action( 'load-update-core.php' ) ) {
			return self::FRESH_TTL;
		}

		return self::CACHE_TTL;
	}

	/**
	 * The highest-versioned release that ships this plugin's own ZIP.
	 *
	 * @param mixed $releases Decoded list of releases.
	 * @return array|false
	 */
	private function pick_release( $releases ) {
		if ( ! is_array( $releases ) ) {
			return false;
		}
		$best = false;
		foreach ( $releases as $release ) {
			$parsed = $this->parse_release( $release );
			if ( $parsed && ( ! $best || version_compare( $parsed['version'], $best['version'], '>' ) ) ) {
				$best = $parsed;
			}
		}
		return $best;
	}

	/**
	 * Reduce a GitHub release to the fields we need.
	 *
	 * @param mixed $release Decoded release.
	 * @return array|false
	 */
	private function parse_release( $release ) {
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return false;
		}
		if ( ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return false;
		}

		$version = ltrim( (string) $release['tag_name'], 'vV' );

		// version_compare() answers nonsense for a tag that is not a version
		// number, and nonsense here means offering a bogus update to every
		// site, so refuse anything that is not digits, dots and a suffix.
		if ( ! preg_match( '/^\d+(\.\d+)*(-[A-Za-z0-9.]+)?$/', $version ) ) {
			return false;
		}

		$package = $this->find_package( $release );
		if ( '' === $package ) {
			return false;
		}

		return array(
			'version'   => $version,
			'package'   => $package,
			'url'       => empty( $release['html_url'] ) ? WPMCP_UPDATE_URI : esc_url_raw( (string) $release['html_url'] ),
			'changelog' => isset( $release['body'] ) ? (string) $release['body'] : '',
			'published' => isset( $release['published_at'] ) ? (string) $release['published_at'] : '',
		);
	}

	/**
	 * Hosts a package may be downloaded from.
	 *
	 * Whatever comes back here is handed to WP_Upgrader, which unpacks it into
	 * wp-content/plugins — so the response from the API is not something to
	 * take on trust. Pinning the host means a tampered or unexpected response
	 * cannot turn an update check into an install from an arbitrary server.
	 *
	 * @var string[]
	 */
	private $package_hosts = array( 'github.com', 'objects.githubusercontent.com', 'codeload.github.com' );

	/**
	 * Whether a package URL may be handed to the upgrader.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_trusted_package( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}
		return in_array( strtolower( $parts['host'] ), $this->package_hosts, true );
	}

	/**
	 * Pick the download URL for a release.
	 *
	 * @param array $release Release data.
	 * @return string Empty string when nothing is downloadable.
	 */
	private function find_package( array $release ) {
		$assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array();

		// Only the exact build CI attaches. Any other ZIP, or GitHub's source
		// archive, may be a different plugin from the same repository, and
		// installing that would deactivate this one on every site.
		foreach ( $assets as $asset ) {
			if ( empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
				continue;
			}
			if ( WPMCP_SLUG . '.zip' !== strtolower( (string) $asset['name'] ) ) {
				continue;
			}
			$download = esc_url_raw( (string) $asset['browser_download_url'] );
			if ( $this->is_trusted_package( $download ) ) {
				return $download;
			}
		}

		return '';
	}

	/**
	 * Render release notes for the "View details" modal.
	 *
	 * Notes are Markdown. This handles the two things they actually use —
	 * bullet lists and paragraphs — and escapes everything else, rather than
	 * shipping a Markdown parser to render a changelog.
	 *
	 * @param string $markdown Release body.
	 * @return string
	 */
	private function render_changelog( $markdown ) {
		$markdown = trim( (string) $markdown );
		if ( '' === $markdown ) {
			return '<p>' . esc_html__( 'See the release notes on GitHub.', 'wp-mcp-control' ) . '</p>';
		}

		$out   = '';
		$in_ul = false;

		foreach ( preg_split( '/\R/', $markdown ) as $line ) {
			$line = trim( $line );

			if ( preg_match( '/^[-*]\s+(.*)$/', $line, $m ) ) {
				if ( ! $in_ul ) {
					$out  .= '<ul>';
					$in_ul = true;
				}
				$out .= '<li>' . esc_html( $m[1] ) . '</li>';
				continue;
			}

			if ( $in_ul ) {
				$out  .= '</ul>';
				$in_ul = false;
			}
			if ( '' !== $line ) {
				$out .= '<p>' . esc_html( $line ) . '</p>';
			}
		}

		if ( $in_ul ) {
			$out .= '</ul>';
		}

		return $out;
	}
}
