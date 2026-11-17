<?php
/**
 * SEO fields of a post, for Yoast SEO and Rank Math (registered only when
 * one of them is active).
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

use WPMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Seo_Tools
 *
 * One set of field names for whichever SEO plugin the site runs, so the
 * model does not have to know that Yoast calls the description "metadesc"
 * and Rank Math keeps robots as an array.
 *
 * Each plugin gates its fields in three groups and the same gates apply
 * here: anyone who may edit the post may change the title, description and
 * focus keyphrase; robots and canonical ("advanced") need the plugin's own
 * advanced capability; social fields need Rank Math's social capability.
 * Yoast's advanced fields are editors-and-up by default and Rank Math's are
 * administrators-only, so an author connected over MCP cannot quietly
 * noindex a page.
 */
class Seo_Tools extends Abstract_Tool {

	/**
	 * Field => group. The group decides which capability a write needs.
	 *
	 * @var array
	 */
	const GROUPS = array(
		'title'          => 'general',
		'description'    => 'general',
		'focus_keyword'  => 'general',
		'canonical'      => 'advanced',
		'indexing'       => 'advanced',
		'og_title'       => 'social',
		'og_description' => 'social',
		'og_image_id'    => 'social',
	);

	/**
	 * Field => meta key, per plugin. indexing and og_image_id are handled
	 * separately because they map to more than a single string.
	 *
	 * @var array
	 */
	const KEYS = array(
		'yoast'     => array(
			'title'          => '_yoast_wpseo_title',
			'description'    => '_yoast_wpseo_metadesc',
			'focus_keyword'  => '_yoast_wpseo_focuskw',
			'canonical'      => '_yoast_wpseo_canonical',
			'og_title'       => '_yoast_wpseo_opengraph-title',
			'og_description' => '_yoast_wpseo_opengraph-description',
		),
		'rank_math' => array(
			'title'          => 'rank_math_title',
			'description'    => 'rank_math_description',
			'focus_keyword'  => 'rank_math_focus_keyword',
			'canonical'      => 'rank_math_canonical_url',
			'og_title'       => 'rank_math_facebook_title',
			'og_description' => 'rank_math_facebook_description',
		),
	);

	/**
	 * Yoast meta keys (after the _yoast_wpseo_ prefix) that its own editor
	 * only shows to users allowed to edit advanced metadata.
	 *
	 * @var string[]
	 */
	const YOAST_ADVANCED = array( 'meta-robots-noindex', 'meta-robots-nofollow', 'meta-robots-adv', 'canonical', 'redirect', 'bctitle', 'schema_page_type', 'schema_article_type' );

	/**
	 * Active SEO plugins this group can drive.
	 *
	 * @return string[] Some of 'yoast', 'rank_math'.
	 */
	public static function active_plugins() {
		$active = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$active[] = 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$active[] = 'rank_math';
		}
		return $active;
	}

	/**
	 * Refuse a generic meta write to an SEO field the user could not change
	 * through update_seo or the plugin's own editor.
	 *
	 * Rank Math's keys are not protected meta and Yoast's are allowlisted for
	 * update_post_meta, so without this the generic meta tools would be a
	 * way around both plugins' role settings.
	 *
	 * @param string $key Meta key (already sanitized).
	 * @throws \Exception When the plugin would not let this user change it.
	 */
	public static function assert_meta_key_permitted( $key ) {
		$group  = '';
		$plugin = '';
		if ( defined( 'WPSEO_VERSION' ) && 0 === strpos( $key, '_yoast_wpseo_' ) ) {
			$plugin = 'yoast';
			$group  = in_array( substr( $key, strlen( '_yoast_wpseo_' ) ), self::YOAST_ADVANCED, true ) ? 'advanced' : 'general';
		} elseif ( defined( 'RANK_MATH_VERSION' ) && 0 === strpos( $key, 'rank_math_' ) ) {
			$plugin = 'rank_math';
			if ( in_array( $key, array( 'rank_math_robots', 'rank_math_advanced_robots', 'rank_math_canonical_url', 'rank_math_breadcrumb_title' ), true ) ) {
				$group = 'advanced';
			} elseif ( 0 === strpos( $key, 'rank_math_facebook_' ) || 0 === strpos( $key, 'rank_math_twitter_' ) ) {
				$group = 'social';
			} elseif ( 0 === strpos( $key, 'rank_math_schema_' ) ) {
				$group = 'snippet';
			} else {
				$group = 'general';
			}
		}
		if ( '' === $plugin || self::can_group( $plugin, $group ) ) {
			return;
		}
		throw new \Exception( esc_html( sprintf( 'Permission denied: %s needs the %s capability in your SEO plugin. Use update_seo for SEO fields.', $key, self::group_capability_name( $plugin, $group ) ) ) );
	}

	/**
	 * Whether the current user may change a group of fields in a plugin.
	 *
	 * @param string $plugin 'yoast' or 'rank_math'.
	 * @param string $group  general|advanced|social|snippet.
	 * @return bool
	 */
	private static function can_group( $plugin, $group ) {
		if ( 'yoast' === $plugin ) {
			if ( 'advanced' !== $group ) {
				return true;
			}
			// Mirrors WPSEO_Meta::get_meta_field_defs(): advanced fields are
			// open to everyone only when the site switched that restriction off.
			if ( class_exists( 'WPSEO_Options' ) && ! \WPSEO_Options::get( 'disableadvanced_meta' ) ) {
				return true;
			}
			return current_user_can( 'wpseo_manage_options' ) || current_user_can( 'wpseo_edit_advanced_metadata' );
		}
		return current_user_can( 'rank_math_onpage_' . $group );
	}

	/**
	 * Human-readable capability for an error message.
	 *
	 * @param string $plugin Plugin.
	 * @param string $group  Group.
	 * @return string
	 */
	private static function group_capability_name( $plugin, $group ) {
		return 'yoast' === $plugin ? 'wpseo_edit_advanced_metadata' : 'rank_math_onpage_' . $group;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public function register( Tool_Registry $r ) {
		$plugin_arg = array(
			'type'        => 'string',
			'enum'        => array( 'yoast', 'rank_math' ),
			'description' => 'Which SEO plugin to use. Needed only when both Yoast SEO and Rank Math are active.',
		);

		$r->add(
			'get_seo',
			'Get the SEO fields of a post from Yoast SEO or Rank Math, whichever is active: SEO title, meta description, focus keyphrase, canonical URL, indexing and Open Graph title, description and image. An empty title or description means the plugin\'s default template for that post type is used.',
			$this->schema(
				array(
					'post_id' => $this->int( 'Post ID (any post type).' ),
					'plugin'  => $plugin_arg,
				),
				array( 'post_id' )
			),
			array( $this, 'get_seo' ),
			'edit_posts'
		);

		$r->add(
			'update_seo',
			'Set SEO fields of a post in Yoast SEO or Rank Math, whichever is active. Only the fields you pass are changed; an empty string resets a field to the plugin default. Titles and descriptions may use the plugin\'s variables: Yoast %%title%% %%sep%% %%sitename%%, Rank Math %title% %sep% %sitename%. Changing canonical or indexing needs the SEO plugin\'s advanced-settings permission.',
			$this->schema(
				array(
					'post_id'        => $this->int( 'Post ID (any post type).' ),
					'plugin'         => $plugin_arg,
					'title'          => $this->str( 'SEO title shown in search results and the browser tab.' ),
					'description'    => $this->str( 'Meta description shown under the title in search results. Aim for 120-160 characters.' ),
					'focus_keyword'  => $this->str( 'Focus keyphrase the post should rank for. Rank Math accepts several, comma-separated; the first is the primary one.' ),
					'canonical'      => $this->str( 'Canonical URL, when this post duplicates another page. Absolute http(s) URL.' ),
					'indexing'       => array(
						'type'        => 'string',
						'enum'        => array( 'default', 'index', 'noindex' ),
						'description' => 'Whether search engines may index the post. "default" follows the setting for the post type.',
					),
					'og_title'       => $this->str( 'Title for social shares (Open Graph). Empty uses the SEO title.' ),
					'og_description' => $this->str( 'Description for social shares (Open Graph). Empty uses the meta description.' ),
					'og_image_id'    => $this->int( 'Attachment ID of the image for social shares. 0 removes it. Upload with upload_media_from_url first if needed.' ),
				),
				array( 'post_id' )
			),
			array( $this, 'update_seo' ),
			'edit_posts'
		);
	}

	/**
	 * Pick the plugin to act on.
	 *
	 * @param array $args Tool args.
	 * @return string
	 * @throws \Exception When none, or ambiguous.
	 */
	private function plugin( array $args ) {
		$active = self::active_plugins();
		if ( ! $active ) {
			throw new \Exception( esc_html( 'No supported SEO plugin is active (Yoast SEO or Rank Math).' ) );
		}
		$wanted = isset( $args['plugin'] ) ? (string) $args['plugin'] : '';
		if ( '' !== $wanted ) {
			if ( ! in_array( $wanted, $active, true ) ) {
				throw new \Exception( esc_html( sprintf( 'SEO plugin "%s" is not active. Active: %s.', $wanted, implode( ', ', $active ) ) ) );
			}
			return $wanted;
		}
		if ( count( $active ) > 1 ) {
			throw new \Exception( esc_html( 'Both Yoast SEO and Rank Math are active. Pass plugin: "yoast" or "rank_math" to say which one the site uses.' ) );
		}
		return $active[0];
	}

	/**
	 * Get SEO fields.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function get_seo( array $args ) {
		$post   = $this->assert_editable_post( $this->required( $args, 'post_id' ) );
		$plugin = $this->plugin( $args );
		return $this->read( $plugin, $post->ID );
	}

	/**
	 * Read every field for a post.
	 *
	 * @param string $plugin Plugin.
	 * @param int    $id     Post ID.
	 * @return array
	 */
	private function read( $plugin, $id ) {
		$out = array(
			'post_id' => $id,
			'plugin'  => $plugin,
		);
		foreach ( self::KEYS[ $plugin ] as $field => $key ) {
			$out[ $field ] = (string) get_post_meta( $id, $key, true );
		}

		if ( 'yoast' === $plugin ) {
			$noindex         = (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true );
			$out['indexing'] = '1' === $noindex ? 'noindex' : ( '2' === $noindex ? 'index' : 'default' );
			$image_id        = (int) get_post_meta( $id, '_yoast_wpseo_opengraph-image-id', true );
			$image_url       = (string) get_post_meta( $id, '_yoast_wpseo_opengraph-image', true );
		} else {
			$robots          = (array) get_post_meta( $id, 'rank_math_robots', true );
			$out['indexing'] = in_array( 'noindex', $robots, true ) ? 'noindex' : ( in_array( 'index', $robots, true ) ? 'index' : 'default' );
			$image_id        = (int) get_post_meta( $id, 'rank_math_facebook_image_id', true );
			$image_url       = (string) get_post_meta( $id, 'rank_math_facebook_image', true );
		}
		$out['og_image_id']  = $image_id;
		$out['og_image_url'] = $image_url;
		return $out;
	}

	/**
	 * Update SEO fields.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception On invalid input or missing permission.
	 */
	public function update_seo( array $args ) {
		$post   = $this->assert_editable_post( $this->required( $args, 'post_id' ) );
		$plugin = $this->plugin( $args );

		$changes = array();
		foreach ( array_keys( self::GROUPS ) as $field ) {
			if ( array_key_exists( $field, $args ) && null !== $args[ $field ] ) {
				$changes[ $field ] = $this->clean( $field, $args[ $field ] );
			}
		}
		if ( ! $changes ) {
			throw new \Exception( esc_html( 'Nothing to update. Pass at least one of: ' . implode( ', ', array_keys( self::GROUPS ) ) . '.' ) );
		}

		// Check every permission before writing anything, so a refused field
		// does not leave the post half-updated.
		foreach ( array_keys( $changes ) as $field ) {
			$group = self::GROUPS[ $field ];
			if ( ! self::can_group( $plugin, $group ) ) {
				throw new \Exception( esc_html( sprintf( 'Permission denied: changing %s needs the %s capability in your SEO plugin.', $field, self::group_capability_name( $plugin, $group ) ) ) );
			}
		}

		foreach ( $changes as $field => $value ) {
			if ( 'indexing' === $field ) {
				$this->write_indexing( $plugin, $post->ID, $value );
			} elseif ( 'og_image_id' === $field ) {
				$this->write_image( $plugin, $post->ID, $value );
			} else {
				$this->write( $post->ID, self::KEYS[ $plugin ][ $field ], $value );
			}
		}

		return $this->read( $plugin, $post->ID );
	}

	/**
	 * Validate and normalize one field.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Raw value.
	 * @return string|int
	 * @throws \Exception On invalid input.
	 */
	private function clean( $field, $value ) {
		if ( 'og_image_id' === $field ) {
			$id = (int) $value;
			if ( $id && ! wp_attachment_is_image( $id ) ) {
				throw new \Exception( esc_html( 'og_image_id is not an image attachment: ' . $id ) );
			}
			if ( $id && ! current_user_can( 'read_post', $id ) ) {
				throw new \Exception( esc_html( 'Permission denied: cannot read attachment ' . $id . '.' ) );
			}
			return $id;
		}
		if ( ! is_scalar( $value ) ) {
			throw new \Exception( esc_html( $field . ' must be a string.' ) );
		}
		$value = (string) $value;
		if ( 'indexing' === $field ) {
			if ( ! in_array( $value, array( 'default', 'index', 'noindex' ), true ) ) {
				throw new \Exception( esc_html( 'indexing must be "default", "index" or "noindex".' ) );
			}
			return $value;
		}
		if ( 'canonical' === $field ) {
			if ( '' === trim( $value ) ) {
				return '';
			}
			$url = esc_url_raw( trim( $value ), array( 'http', 'https' ) );
			if ( '' === $url || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
				throw new \Exception( esc_html( 'canonical must be an absolute http(s) URL.' ) );
			}
			return $url;
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Write or clear one string field.
	 *
	 * @param int    $id    Post ID.
	 * @param string $key   Meta key.
	 * @param string $value Value; '' clears.
	 */
	private function write( $id, $key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $id, $key );
			return;
		}
		// update_post_meta() unslashes its input.
		update_post_meta( $id, $key, wp_slash( $value ) );
	}

	/**
	 * Write the index / noindex choice.
	 *
	 * @param string $plugin Plugin.
	 * @param int    $id     Post ID.
	 * @param string $value  default|index|noindex.
	 */
	private function write_indexing( $plugin, $id, $value ) {
		if ( 'yoast' === $plugin ) {
			// Yoast: '0' (or absent) = post type default, '1' = noindex, '2' = index.
			$map = array(
				'default' => '',
				'noindex' => '1',
				'index'   => '2',
			);
			$this->write( $id, '_yoast_wpseo_meta-robots-noindex', $map[ $value ] );
			return;
		}

		// Rank Math keeps robots as one array (index/noindex, nofollow,
		// noarchive, ...); change only the index directive and keep the rest.
		$robots = array_values( array_diff( (array) get_post_meta( $id, 'rank_math_robots', true ), array( 'index', 'noindex', '' ) ) );
		if ( 'default' === $value ) {
			if ( $robots ) {
				update_post_meta( $id, 'rank_math_robots', $robots );
			} else {
				// No per-post robots at all: Rank Math falls back to the post type setting.
				delete_post_meta( $id, 'rank_math_robots' );
			}
			return;
		}
		array_unshift( $robots, $value );
		update_post_meta( $id, 'rank_math_robots', $robots );
	}

	/**
	 * Write or clear the social image (both its ID and URL are stored).
	 *
	 * @param string $plugin Plugin.
	 * @param int    $id     Post ID.
	 * @param int    $image  Attachment ID; 0 clears.
	 */
	private function write_image( $plugin, $id, $image ) {
		$keys = 'yoast' === $plugin
			? array( '_yoast_wpseo_opengraph-image-id', '_yoast_wpseo_opengraph-image' )
			: array( 'rank_math_facebook_image_id', 'rank_math_facebook_image' );

		if ( ! $image ) {
			delete_post_meta( $id, $keys[0] );
			delete_post_meta( $id, $keys[1] );
			return;
		}
		update_post_meta( $id, $keys[0], (string) $image );
		update_post_meta( $id, $keys[1], wp_slash( (string) wp_get_attachment_url( $image ) ) );
	}
}
