<?php
/**
 * Tool registry.
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tool_Registry
 *
 * Holds every tool definition keyed by name.
 */
class Tool_Registry {

	/**
	 * Registered tools.
	 *
	 * @var array<string, array>
	 */
	private $tools = array();

	/**
	 * Register a tool.
	 *
	 * @param string   $name        Unique tool name (snake_case).
	 * @param string   $description Human-readable description for the LLM.
	 * @param array    $schema      JSON-Schema for the input (properties/required).
	 * @param callable $callback    fn( array $args ): mixed  — may throw \Exception.
	 * @param string   $capability  Required WP capability (checked before calling).
	 */
	public function add( $name, $description, array $schema, callable $callback, $capability = 'read' ) {
		$this->tools[ $name ] = array(
			'name'        => $name,
			'description' => $description,
			'inputSchema' => $this->normalize_schema( $schema ),
			'callback'    => $callback,
			'capability'  => $capability,
		);
	}

	/**
	 * Ensure the schema is a valid JSON-Schema object.
	 *
	 * @param array $schema Raw schema.
	 * @return array
	 */
	private function normalize_schema( array $schema ) {
		if ( ! isset( $schema['type'] ) ) {
			$schema['type'] = 'object';
		}
		if ( ! isset( $schema['properties'] ) || array() === $schema['properties'] ) {
			// Empty PHP arrays JSON-encode to "[]"; JSON Schema requires an object.
			$schema['properties'] = new \stdClass();
		}
		return $schema;
	}

	/**
	 * Tools that only read.
	 *
	 * Kept as one explicit list rather than a flag per registration, because
	 * the property that matters is the complement: anything NOT named here is
	 * treated as a write. A tool added later — by a future version or by a
	 * third party through wpmcp_tool_groups — is therefore restricted by
	 * default instead of silently escaping read-only mode, which is the way
	 * round this has to fail. Name prefixes cannot stand in for the list:
	 * wc_get_order and pll_list_languages read, pll_set_post_language writes.
	 *
	 * @var string[]
	 */
	private static $read_only_tools = array(
		'list_comments',
		'list_media',
		'list_nav_menus',
		'list_post_types',
		'list_posts',
		'list_taxonomies',
		'list_terms',
		'get_post',
		'get_post_meta',
		'get_site_info',
		'pll_get_post_language',
		'pll_list_languages',
		'wc_get_order',
		'wc_get_product',
		'wc_list_orders',
		'wc_list_products',
	);

	/**
	 * Whether a tool changes anything.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public function is_write( $name ) {
		$is_write = ! in_array( (string) $name, self::$read_only_tools, true );

		/**
		 * Filters whether a tool counts as a write for read-only mode.
		 *
		 * @param bool   $is_write Whether the tool changes anything.
		 * @param string $name     Tool name.
		 */
		return (bool) apply_filters( 'wpmcp_tool_is_write', $is_write, $name );
	}

	/**
	 * Writes that only add something new and never overwrite or remove.
	 *
	 * MCP assumes a write is destructive unless told otherwise, and that is the
	 * right default here too: update_* replaces what was there, set_* and
	 * assign_* swap one value for another. Only the tools named below are
	 * reported as additive.
	 *
	 * @var string[]
	 */
	private static $additive_tools = array(
		'create_comment',
		'create_post',
		'create_term',
		'pll_create_translation',
		'upload_media_base64',
		'upload_media_from_url',
		'wc_create_product',
	);

	/**
	 * MCP tool annotations, derived from the same classification read-only
	 * mode uses, so the hint a client sees can never disagree with what the
	 * server enforces.
	 *
	 * Clients use these to decide what to run without asking the user first;
	 * ChatGPT, for one, asks before calling tools not marked read-only.
	 *
	 * @param string $name Tool name.
	 * @return array
	 */
	public function annotations( $name ) {
		if ( ! $this->is_write( $name ) ) {
			return array(
				'readOnlyHint'  => true,
				'openWorldHint' => false,
			);
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => ! in_array( (string) $name, self::$additive_tools, true ),
			// Fetching from a URL is the one tool that reaches beyond this site.
			'openWorldHint'   => 'upload_media_from_url' === $name,
		);
	}

	/**
	 * Is the server restricted to reads?
	 *
	 * @return bool
	 */
	public function is_read_only() {
		$s = (array) get_option( Auth::OPTION, array() );
		return ! empty( $s['read_only'] );
	}

	/**
	 * Whether a tool exists (and is not disabled).
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public function has( $name ) {
		return isset( $this->tools[ $name ] ) && ! $this->is_disabled( $name );
	}

	/**
	 * Get a single tool definition.
	 *
	 * @param string $name Tool name.
	 * @return array|null
	 */
	public function get( $name ) {
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * Is a tool disabled in settings?
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	private function is_disabled( $name ) {
		$s = (array) get_option( Auth::OPTION, array() );
		return in_array( $name, (array) ( $s['disabled_tools'] ?? array() ), true );
	}

	/**
	 * List all enabled tools in MCP tools/list shape.
	 *
	 * @return array
	 */
	public function list_for_mcp() {
		$read_only = $this->is_read_only();

		$out = array();
		foreach ( $this->tools as $name => $def ) {
			if ( $this->is_disabled( $name ) ) {
				continue;
			}
			// Withholding the tool beats refusing the call: a model that can
			// see delete_post will keep reaching for it and reporting failures
			// at the user, where one it cannot see simply never comes up.
			// call_tool() refuses anyway, for clients holding an older list.
			if ( $read_only && $this->is_write( $name ) ) {
				continue;
			}
			$out[] = array(
				'name'        => $def['name'],
				'description' => $def['description'],
				'inputSchema' => $def['inputSchema'],
				'annotations' => $this->annotations( $name ),
			);
		}
		return $out;
	}

	/**
	 * All tool names (including disabled), for the admin UI.
	 *
	 * @return array
	 */
	public function all_names() {
		return array_keys( $this->tools );
	}
}
