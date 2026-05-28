<?php
/**
 * Theme preview store.
 *
 * The agent's `studio/preview-theme` ability stages a theme without touching
 * disk. Files live in a transient keyed by a random preview id; the JS UI
 * renders a Playground iframe loaded with a Blueprint that re-creates the
 * theme inside the iframe so the user can see exactly what will land. If the
 * user accepts, we replay the same files through the real `create-theme` +
 * `activate-theme` flow on this site.
 *
 * Transients expire after PREVIEW_TTL seconds — staged previews are short-lived.
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

class Studio_Agent_Theme_Preview {

	private const TRANSIENT_PREFIX = 'studio_agent_preview_';
	private const PREVIEW_TTL      = HOUR_IN_SECONDS;

	/**
	 * Store a preview. `$theme_args` is whatever was passed to create_theme;
	 * `$files` is the rendered file map (path => content) ready to drop on disk.
	 *
	 * @param array $theme_args
	 * @param array<string,string> $files
	 * @return string Preview ID.
	 */
	public static function stage( array $theme_args, array $files ): string {
		$preview_id = wp_generate_uuid4();
		set_transient(
			self::TRANSIENT_PREFIX . $preview_id,
			array(
				'theme_args' => $theme_args,
				'files'      => $files,
				'created_at' => time(),
			),
			self::PREVIEW_TTL
		);
		return $preview_id;
	}

	/**
	 * @return array{theme_args:array,files:array<string,string>,created_at:int}|null
	 */
	public static function get( string $preview_id ): ?array {
		$data = get_transient( self::TRANSIENT_PREFIX . $preview_id );
		return is_array( $data ) ? $data : null;
	}

	public static function discard( string $preview_id ): bool {
		return (bool) delete_transient( self::TRANSIENT_PREFIX . $preview_id );
	}

	/**
	 * Build a Playground Blueprint that, when run inside the Playground iframe,
	 * writes the staged files and activates the theme. We base it on the slug
	 * so links/preview URL inside Playground match what the user will see
	 * after accepting on the host site.
	 *
	 * @param array{theme_args:array,files:array<string,string>} $preview
	 * @return array
	 */
	public static function build_blueprint( array $preview ): array {
		$slug  = (string) ( $preview['theme_args']['slug'] ?? 'studio-preview' );
		$files = (array) ( $preview['files'] ?? array() );

		$steps = array(
			array( 'step' => 'login' ),
		);

		// writeFile doesn't create parent directories — collect every directory
		// implied by the file paths and mkdir them up front.
		$theme_root = '/wordpress/wp-content/themes/' . $slug;
		$dirs       = array( $theme_root => true );
		foreach ( $files as $relative => $_ ) {
			$parent = dirname( $relative );
			if ( '.' === $parent ) {
				continue;
			}
			$accum = $theme_root;
			foreach ( explode( '/', $parent ) as $segment ) {
				$accum         .= '/' . $segment;
				$dirs[ $accum ] = true;
			}
		}
		foreach ( array_keys( $dirs ) as $dir ) {
			$steps[] = array( 'step' => 'mkdir', 'path' => $dir );
		}

		foreach ( $files as $relative => $contents ) {
			$steps[] = array(
				'step' => 'writeFile',
				'path' => $theme_root . '/' . $relative,
				'data' => $contents,
			);
		}

		$steps[] = array(
			'step'      => 'activateTheme',
			'themeFolderName' => $slug,
		);

		// Land on the front page so the user sees the activated theme rendered.
		$steps[] = array(
			'step'   => 'setSiteOptions',
			'options' => array(
				'blogname' => (string) ( $preview['theme_args']['name'] ?? 'Studio Preview' ),
			),
		);

		return array(
			'landingPage'       => '/',
			'preferredVersions' => array(
				'php' => '8.4',
				'wp'  => 'latest',
			),
			'login'             => true,
			'steps'             => $steps,
		);
	}
}
