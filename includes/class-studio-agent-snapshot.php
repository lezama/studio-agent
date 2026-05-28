<?php
/**
 * Snapshot generator: turn the current site state plus a sandbox session's
 * queued log into a Playground Blueprint that can be consumed by an
 * embedded `@wp-playground/client` instance.
 *
 * Strategy:
 *   - Subset of options (blogname, blogdescription, show_on_front, page_on_front).
 *   - Up to 20 most-recent published posts/pages, recreated via runPHP step.
 *   - Active theme files (style.css + theme.json + templates/* + parts/*).
 *   - Replay of the sandbox log: each op is turned into a step that executes
 *     the same ability inside the Playground after the snapshot is loaded.
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

class Studio_Agent_Snapshot {

	private const POST_LIMIT = 20;

	/**
	 * Build a Playground blueprint for a given sandbox session.
	 *
	 * @return array Blueprint JSON.
	 */
	public static function build_blueprint( string $session_id ): array {
		$session = Studio_Agent_Sandbox::get( $session_id );
		if ( ! $session ) {
			return self::base_blueprint();
		}

		$blueprint = self::base_blueprint();

		// Site options (snapshot of the live site).
		$options = self::snapshot_options();
		$blueprint['steps'][] = array(
			'step'    => 'setSiteOptions',
			'options' => $options,
		);

		// Active theme: copy files into Playground.
		foreach ( self::snapshot_active_theme_files() as $relative => $contents ) {
			$blueprint['steps'][] = array(
				'step' => 'mkdir',
				'path' => '/wordpress/wp-content/themes/' . dirname( $relative ),
			);
			$blueprint['steps'][] = array(
				'step' => 'writeFile',
				'path' => '/wordpress/wp-content/themes/' . $relative,
				'data' => $contents,
			);
		}
		$active_theme_slug = (string) get_option( 'stylesheet' );
		if ( $active_theme_slug ) {
			$blueprint['steps'][] = array(
				'step'            => 'activateTheme',
				'themeFolderName' => $active_theme_slug,
			);
		}

		// Recent posts/pages — recreate via runPHP.
		$posts_php = self::snapshot_posts_as_php();
		if ( $posts_php ) {
			$blueprint['steps'][] = array(
				'step' => 'runPHP',
				'code' => "<?php\nrequire_once '/wordpress/wp-load.php';\n" . $posts_php,
			);
		}

		// Apply the sandbox log on top — these are the proposed changes.
		$log_php = self::log_as_php( (array) $session['log'] );
		if ( $log_php ) {
			$blueprint['steps'][] = array(
				'step' => 'runPHP',
				'code' => "<?php\nrequire_once '/wordpress/wp-load.php';\n" . $log_php,
			);
		}

		return $blueprint;
	}

	/* ---------- snapshots ---------- */

	private static function base_blueprint(): array {
		return array(
			'landingPage'       => '/',
			'preferredVersions' => array( 'php' => '8.4', 'wp' => 'latest' ),
			'login'             => true,
			'steps'             => array( array( 'step' => 'login' ) ),
		);
	}

	/**
	 * @return array<string,scalar>
	 */
	private static function snapshot_options(): array {
		$keys = array( 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'posts_per_page', 'date_format', 'time_format' );
		$out  = array();
		foreach ( $keys as $k ) {
			$v = get_option( $k );
			if ( null !== $v && false !== $v ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * @return array<string,string> Map of relative path → contents.
	 */
	private static function snapshot_active_theme_files(): array {
		$theme = wp_get_theme();
		if ( ! $theme->exists() ) {
			return array();
		}
		$slug = $theme->get_stylesheet();
		$root = $theme->get_stylesheet_directory();
		$out  = array();

		$pick = array(
			'style.css',
			'theme.json',
			'functions.php',
		);
		foreach ( $pick as $rel ) {
			$path = $root . '/' . $rel;
			if ( is_file( $path ) ) {
				$out[ $slug . '/' . $rel ] = (string) file_get_contents( $path );
			}
		}

		// templates/ and parts/ for block themes.
		foreach ( array( 'templates', 'parts', 'patterns' ) as $sub ) {
			$dir = $root . '/' . $sub;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iter as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$rel = $sub . '/' . substr( $file->getPathname(), strlen( $dir ) + 1 );
				$out[ $slug . '/' . $rel ] = (string) file_get_contents( $file->getPathname() );
			}
		}
		return $out;
	}

	/**
	 * @return string PHP code that, when run, recreates the snapshotted posts
	 *                via wp_insert_post(). Trashes default WP-install posts
	 *                first to avoid duplicate Hello world!.
	 */
	private static function snapshot_posts_as_php(): string {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => self::POST_LIMIT,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		if ( empty( $posts ) ) {
			return '';
		}
		$lines   = array();
		$lines[] = '// Clear any default posts that ship with the fresh WP install.';
		$lines[] = 'foreach ( get_posts( array( "post_type" => array( "post", "page" ), "post_status" => "any", "posts_per_page" => -1 ) ) as $p ) { wp_delete_post( $p->ID, true ); }';
		foreach ( $posts as $post ) {
			$arr = array(
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_status'  => $post->post_status,
				'post_type'    => $post->post_type,
				'post_excerpt' => $post->post_excerpt,
				'post_name'    => $post->post_name,
			);
			$lines[] = sprintf( 'wp_insert_post( %s );', var_export( $arr, true ) );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Render the sandbox log as PHP that runs the queued ops via the
	 * Abilities API inside the Playground. The plugin must be active in
	 * the Playground for this to work — that's a Sprint-C concern (we'll
	 * mount the plugin in Playground via the snapshot zip / mu-plugin).
	 *
	 * For the v0 snapshot we just produce a comment listing the queued
	 * ops; Sprint C will replace this with real execution once the
	 * Playground has the plugin loaded.
	 */
	private static function log_as_php( array $log ): string {
		if ( empty( $log ) ) {
			return '';
		}
		$lines = array( '// Queued sandbox ops (will execute against the Playground):' );
		$temp_to_real = array();
		foreach ( $log as $op ) {
			$ability = $op['ability'];
			$args    = $op['args'];

			if ( 'studio/sandbox-create-post' === $ability ) {
				$insert = array(
					'post_title'   => (string) ( $args['title'] ?? '' ),
					'post_content' => (string) ( $args['content'] ?? '' ),
					'post_type'    => (string) ( $args['post_type'] ?? 'post' ),
					'post_status'  => (string) ( $args['status'] ?? 'publish' ), // publish in preview so it's visible
				);
				$lines[] = sprintf(
					'$id = wp_insert_post( %s );',
					var_export( $insert, true )
				);
				if ( ! empty( $op['temp_id'] ) ) {
					$lines[] = sprintf( '$_studio_temps[%s] = $id;', var_export( $op['temp_id'], true ) );
				}
			} elseif ( 'studio/sandbox-update-option' === $ability ) {
				$lines[] = sprintf(
					'update_option( %s, %s );',
					var_export( (string) ( $args['name'] ?? '' ), true ),
					var_export( $args['value'] ?? null, true )
				);
			}
			// Other op types: ignored for v0; will be handled in Sprint E.
		}
		return "\$_studio_temps = array();\n" . implode( "\n", $lines );
	}
}
