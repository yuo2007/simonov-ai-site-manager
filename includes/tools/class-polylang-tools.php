<?php
/**
 * Polylang multilingual tools (registered only when Polylang is active).
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

use WPMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Polylang_Tools
 *
 * Uses Polylang's public API functions (pll_*).
 *
 * @see https://polylang.pro/doc/developpers-guide/
 */
class Polylang_Tools extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public function register( Tool_Registry $r ) {

		$r->add(
			'pll_list_languages',
			'List all languages configured in Polylang (slug, name, locale, default).',
			$this->schema( array() ),
			array( $this, 'list_languages' ),
			'read'
		);

		$r->add(
			'pll_get_post_language',
			'Get the language of a post and the IDs of its translations in every language.',
			$this->schema( array( 'post_id' => $this->int( 'Post ID.' ) ), array( 'post_id' ) ),
			array( $this, 'get_post_language' ),
			'read'
		);

		$r->add(
			'pll_set_post_language',
			'Set the language of a post (slug like "en", "uk", "fr").',
			$this->schema(
				array(
					'post_id' => $this->int( 'Post ID.' ),
					'lang'    => $this->str( 'Language slug.' ),
				),
				array( 'post_id', 'lang' )
			),
			array( $this, 'set_post_language' ),
			'edit_posts'
		);

		$r->add(
			'pll_link_translations',
			'Link posts as translations of each other. Provide a map of language slug => post ID.',
			$this->schema(
				array(
					'translations' => array(
						'type'                 => 'object',
						'description'          => 'Map of language slug to post ID, e.g. {"en":12,"uk":34}.',
						'additionalProperties' => array( 'type' => 'integer' ),
					),
				),
				array( 'translations' )
			),
			array( $this, 'link_translations' ),
			'edit_posts'
		);

		$r->add(
			'pll_create_translation',
			'Create a translated copy of an existing post in a target language, set its language and link it to the source. Provide translated title/content.',
			$this->schema(
				array(
					'source_id' => $this->int( 'Source post ID to translate.' ),
					'lang'      => $this->str( 'Target language slug.' ),
					'title'     => $this->str( 'Translated title.' ),
					'content'   => $this->str( 'Translated content.' ),
					'status'    => $this->str( 'Status of the new translation (default draft).' ),
				),
				array( 'source_id', 'lang', 'title' )
			),
			array( $this, 'create_translation' ),
			'edit_posts'
		);

		$r->add(
			'pll_set_term_language',
			'Set the language of a taxonomy term.',
			$this->schema(
				array(
					'term_id' => $this->int( 'Term ID.' ),
					'lang'    => $this->str( 'Language slug.' ),
				),
				array( 'term_id', 'lang' )
			),
			array( $this, 'set_term_language' ),
			'manage_categories'
		);
	}

	/**
	 * List languages.
	 *
	 * @return array
	 */
	public function list_languages() {
		$slugs   = pll_languages_list( array( 'fields' => '' ) );
		$default = function_exists( 'pll_default_language' ) ? pll_default_language() : '';
		$out     = array();
		foreach ( $slugs as $lang ) {
			$out[] = array(
				'slug'      => $lang->slug,
				'name'      => $lang->name,
				'locale'    => $lang->locale,
				'is_default'=> $lang->slug === $default,
			);
		}
		return $out;
	}

	/**
	 * Get post language + translations.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function get_post_language( array $args ) {
		$post_id = (int) $this->required( $args, 'post_id' );
		$langs   = pll_languages_list();
		$trans   = array();
		foreach ( $langs as $slug ) {
			$tid = pll_get_post( $post_id, $slug );
			if ( $tid ) {
				$trans[ $slug ] = (int) $tid;
			}
		}
		return array(
			'post_id'      => $post_id,
			'language'     => pll_get_post_language( $post_id ),
			'translations' => $trans,
		);
	}

	/**
	 * Set post language.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function set_post_language( array $args ) {
		$post_id = (int) $this->required( $args, 'post_id' );
		$lang    = (string) $this->required( $args, 'lang' );
		$this->assert_editable_post( $post_id );
		pll_set_post_language( $post_id, $lang );
		return array(
			'post_id'  => $post_id,
			'language' => pll_get_post_language( $post_id ),
		);
	}

	/**
	 * Link translations.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function link_translations( array $args ) {
		$translations = $this->required( $args, 'translations' );
		if ( ! is_array( $translations ) ) {
			throw new \Exception( 'translations must be an object mapping language slug to post ID.' );
		}
		$map = array();
		foreach ( $translations as $lang => $id ) {
			$id = (int) $id;
			$this->assert_editable_post( $id );
			$map[ (string) $lang ] = $id;
		}
		pll_save_post_translations( $map );
		return array( 'linked' => $map );
	}

	/**
	 * Create a translation copy.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function create_translation( array $args ) {
		$source_id = (int) $this->required( $args, 'source_id' );
		$lang      = (string) $this->required( $args, 'lang' );

		// Omitting 'content' copies the source body verbatim; without a read
		// check that turns this into a way to exfiltrate a private post.
		$source = $this->assert_readable_post( $source_id );
		$this->assert_post_type( $source->post_type, 'edit' );

		$status = (string) ( $args['status'] ?? 'draft' );
		// Same rule as create_post: publishing needs publish rights, not just
		// edit rights, or a contributor could skip review via a translation.
		if ( in_array( $status, array( 'publish', 'future' ), true ) ) {
			$this->assert_post_type( $source->post_type, 'publish' );
		}

		$new_id = wp_insert_post(
			wp_slash(
				array(
					'post_title'   => (string) $this->required( $args, 'title' ),
					'post_content' => (string) ( $args['content'] ?? $source->post_content ),
					'post_status'  => $status,
					'post_type'    => $source->post_type,
				)
			),
			true
		);
		$this->unwrap( $new_id );

		pll_set_post_language( $new_id, $lang );

		// Preserve existing translations and add the new one.
		$existing = array();
		foreach ( pll_languages_list() as $slug ) {
			$tid = pll_get_post( $source_id, $slug );
			if ( $tid ) {
				$existing[ $slug ] = (int) $tid;
			}
		}
		$existing[ pll_get_post_language( $source_id ) ] = $source_id;
		$existing[ $lang ] = $new_id;
		pll_save_post_translations( $existing );

		return array(
			'source_id'    => $source_id,
			'new_id'       => $new_id,
			'language'     => $lang,
			'translations' => $existing,
		);
	}

	/**
	 * Set term language.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function set_term_language( array $args ) {
		$term_id = (int) $this->required( $args, 'term_id' );
		$lang    = (string) $this->required( $args, 'lang' );
		$term    = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			throw new \Exception( 'Term not found: ' . $term_id );
		}
		$this->assert_taxonomy( $term->taxonomy, 'edit' );
		pll_set_term_language( $term_id, $lang );
		return array(
			'term_id'  => $term_id,
			'language' => $lang,
		);
	}
}
