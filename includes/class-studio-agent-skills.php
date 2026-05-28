<?php
/**
 * Studio Skills loader.
 *
 * The plugin ships a `skills/` directory with Studio's own agent guidance
 * (STUDIO.md, studio-cli/SKILL.md) plus a curated set from the
 * WordPress/agent-skills repo (wp-plugin-development, wp-block-development,
 * wp-block-themes, wp-rest-api, wp-wpcli-and-ops). Two abilities expose them:
 *
 *   - studio/list-skills  — manifest, used to populate the slash menu.
 *   - studio/load-skill   — reads the full markdown of a named skill.
 *
 * Path traversal is blocked by:
 *   1. Schema-level allowed-name pattern.
 *   2. realpath() check that the resolved file lives under skills/.
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

class Studio_Agent_Skills {

	/**
	 * Skills `name` => description + filename. Cached because we parse
	 * frontmatter on disk on first call, but never deviate per-request.
	 *
	 * @var array<string, array{description:string,file:string}>|null
	 */
	private static ?array $manifest_cache = null;

	/**
	 * Discover skills by scanning the skills/ directory:
	 *
	 *   - SKILL.md inside a sub-folder → skill name = folder name, parse
	 *     the YAML-ish frontmatter for `description`.
	 *   - STUDIO.md at the root → skill `studio`, hard-coded description
	 *     (the file has no frontmatter).
	 *
	 * @return array<string, array{description:string,file:string}>
	 */
	public static function manifest(): array {
		if ( null !== self::$manifest_cache ) {
			return self::$manifest_cache;
		}

		$skills = array();

		// STUDIO.md → name "studio".
		if ( file_exists( STUDIO_AGENT_PATH . 'skills/STUDIO.md' ) ) {
			$skills['studio'] = array(
				'description' => 'Studio top-level rules: prefix wp-cli with `studio`, workflow, common patterns. Auto-loaded into the system prompt — listed for completeness.',
				'file'        => 'STUDIO.md',
			);
		}

		// Each <name>/SKILL.md sub-directory.
		$skills_root = STUDIO_AGENT_PATH . 'skills';
		if ( is_dir( $skills_root ) ) {
			foreach ( scandir( $skills_root ) as $entry ) {
				if ( '' === $entry || '.' === $entry[0] ) {
					continue;
				}
				$candidate = $skills_root . '/' . $entry . '/SKILL.md';
				if ( ! is_file( $candidate ) ) {
					continue;
				}
				if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $entry ) ) {
					continue;
				}
				$frontmatter = self::parse_frontmatter( (string) file_get_contents( $candidate ) );
				$skills[ $entry ] = array(
					'description' => isset( $frontmatter['description'] ) && is_string( $frontmatter['description'] )
						? trim( $frontmatter['description'] )
						: ucfirst( str_replace( '-', ' ', $entry ) ),
					'file'        => $entry . '/SKILL.md',
				);
			}
		}

		self::$manifest_cache = $skills;
		return $skills;
	}

	/**
	 * @return list<array{name:string,description:string}>
	 */
	public static function list_skills(): array {
		$out = array();
		foreach ( self::manifest() as $name => $entry ) {
			$out[] = array(
				'name'        => (string) $name,
				'description' => (string) $entry['description'],
			);
		}
		return $out;
	}

	/**
	 * Load a skill's full markdown content.
	 *
	 * @return array{name:string,content:string,bytes:int}|WP_Error
	 */
	public static function load_skill( string $name ) {
		$manifest = self::manifest();
		if ( ! isset( $manifest[ $name ] ) ) {
			return new WP_Error(
				'studio_skill_not_found',
				sprintf( 'Skill "%s" is not available. Use studio/list-skills to see what exists.', $name ),
				array( 'status' => 404 )
			);
		}

		$skills_dir = realpath( STUDIO_AGENT_PATH . 'skills' );
		$full       = realpath( STUDIO_AGENT_PATH . 'skills/' . $manifest[ $name ]['file'] );

		if ( false === $skills_dir || false === $full || ! str_starts_with( $full, $skills_dir ) ) {
			return new WP_Error( 'studio_skill_invalid_path', 'Resolved skill path falls outside the skills directory.' );
		}

		$content = file_get_contents( $full );
		if ( false === $content ) {
			return new WP_Error( 'studio_skill_read_failed', sprintf( 'Failed reading skill "%s".', $name ) );
		}

		return array(
			'name'    => $name,
			'content' => $content,
			'bytes'   => strlen( $content ),
		);
	}

	/**
	 * Lightweight YAML-frontmatter parser. Only handles the `key: "value"`
	 * and `key: value` forms used by SKILL.md files — sufficient to
	 * extract `description` without pulling in a full YAML dependency.
	 *
	 * @return array<string,string>
	 */
	private static function parse_frontmatter( string $content ): array {
		if ( ! preg_match( '/^---\s*\n(.*?)\n---/s', $content, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( explode( "\n", $m[1] ) as $line ) {
			if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9_-]*)\s*:\s*(.*)$/', $line, $kv ) ) {
				continue;
			}
			$value = trim( $kv[2] );
			// Strip wrapping quotes if present.
			if ( ( strlen( $value ) >= 2 ) && ( $value[0] === '"' || $value[0] === "'" ) && substr( $value, -1 ) === $value[0] ) {
				$value = substr( $value, 1, -1 );
			}
			$out[ $kv[1] ] = $value;
		}
		return $out;
	}

	/**
	 * Inline content of the always-on `studio` skill, ready to splice
	 * into the system prompt. We strip the header to avoid weird
	 * "## IMPORTANT:" rendering inside an already-multi-section prompt;
	 * the body is what the model needs.
	 */
	public static function studio_skill_for_system_prompt(): string {
		$path = STUDIO_AGENT_PATH . 'skills/STUDIO.md';
		if ( ! is_file( $path ) ) {
			return '';
		}
		return (string) file_get_contents( $path );
	}
}
