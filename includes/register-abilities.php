<?php
/**
 * Register WordPress Abilities for the Studio agent.
 *
 * These are the tools the agent can call. Each ability is a self-contained
 * unit that the LLM sees as a callable function (via wp-ai-client's
 * ability→function-declaration bridge).
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_abilities_api_categories_init',
	static function () {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				'studio',
				array(
					'label'       => __( 'Studio', 'studio-agent' ),
					'description' => __( 'Tools the Studio agent can call to inspect and operate on this WordPress site.', 'studio-agent' ),
				)
			);
		}
	}
);

add_action(
	'wp_abilities_api_init',
	static function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// site-info — read-only, no input.
		wp_register_ability(
			'studio/site-info',
			array(
				'label'               => __( 'Get site info', 'studio-agent' ),
				'description'         => __( 'Returns the WordPress version, site URL, active theme, and a count of active plugins. Use this to learn the basic facts about the current site.', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => static function () {
					$active_plugins = (array) get_option( 'active_plugins', array() );
					$theme          = wp_get_theme();
					return array(
						'wp_version'           => get_bloginfo( 'version' ),
						'site_url'             => get_site_url(),
						'site_title'           => get_bloginfo( 'name' ),
						'active_theme'         => $theme->get( 'Name' ),
						'active_theme_version' => $theme->get( 'Version' ),
						'active_plugin_count'  => count( $active_plugins ),
						'active_plugins'       => $active_plugins,
					);
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		// list-posts — read-only, with input.
		wp_register_ability(
			'studio/list-posts',
			array(
				'label'               => __( 'List posts', 'studio-agent' ),
				'description'         => __( 'Returns a list of posts (title, ID, status, type, modified date) on the site. Useful for getting an overview of content.', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Post type slug (default "post"). Examples: post, page, attachment.',
						),
						'status'    => array(
							'type'        => 'string',
							'description' => 'Post status (default "any"). Examples: publish, draft, pending, any.',
						),
						'limit'     => array(
							'type'        => 'integer',
							'description' => 'Maximum number of posts to return (default 10, max 50).',
							'minimum'     => 1,
							'maximum'     => 50,
						),
					),
				),
				'execute_callback'    => static function ( $args ) {
					$args = is_array( $args ) ? $args : array();
					$query = new WP_Query(
						array(
							'post_type'      => $args['post_type'] ?? 'post',
							'post_status'    => $args['status'] ?? 'any',
							'posts_per_page' => min( (int) ( $args['limit'] ?? 10 ), 50 ),
							'orderby'        => 'modified',
							'order'          => 'DESC',
						)
					);
					$out = array();
					foreach ( $query->posts as $post ) {
						$out[] = array(
							'id'          => $post->ID,
							'title'       => get_the_title( $post ),
							'status'      => $post->post_status,
							'type'        => $post->post_type,
							'modified'    => $post->post_modified_gmt,
							'permalink'   => get_permalink( $post ),
						);
					}
					return array(
						'count' => count( $out ),
						'total' => (int) $query->found_posts,
						'posts' => $out,
					);
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		// ---- Sandbox control abilities ----

		wp_register_ability(
			'studio/sandbox-open',
			array(
				'label'               => __( 'Open a sandbox session', 'studio-agent' ),
				'description'         => __( 'Opens a new sandbox session. While a sandbox is active, destructive abilities (sandbox-create-post, sandbox-update-option, etc.) queue their changes in a log instead of touching the host site. The user reviews the queued changes in a Playground preview alongside the chat and accepts or rejects them. ALWAYS open a sandbox before queueing changes when the user is asking for non-trivial edits.', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'note' => array( 'type' => 'string', 'description' => 'Short human-readable summary of what this sandbox is meant to do.' ),
					),
				),
				'execute_callback'    => static function ( $args ) {
					$note       = is_array( $args ) ? (string) ( $args['note'] ?? '' ) : '';
					$session_id = Studio_Agent_Sandbox::open( $note );
					return array(
						'session_id'    => $session_id,
						'note'          => $note,
						'base_revision' => Studio_Agent_Sandbox::current_revision(),
					);
				},
				'meta'                => array( 'annotations' => array( 'destructive' => false, 'idempotent' => false ) ),
			)
		);

		wp_register_ability(
			'studio/sandbox-status',
			array(
				'label'               => __( 'Sandbox status', 'studio-agent' ),
				'description'         => __( 'Returns the active sandbox session (if any) including the queued log of ops. Use to review what will be applied before the user accepts.', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
				'execute_callback'    => static function () {
					$id = Studio_Agent_Sandbox::active_session_id();
					if ( ! $id ) {
						return array( 'active' => false );
					}
					$session = Studio_Agent_Sandbox::get( $id );
					return array(
						'active'   => true,
						'session'  => array(
							'id'            => $session['id'],
							'note'          => $session['note'],
							'created_at'    => $session['created_at'],
							'base_revision' => $session['base_revision'],
							'op_count'      => count( (array) $session['log'] ),
							'log'           => $session['log'],
						),
					);
				},
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'idempotent' => true ) ),
			)
		);

		// ---- Sandbox-aware data ops ----

		wp_register_ability(
			'studio/sandbox-create-post',
			array(
				'label'               => __( 'Create a post (sandbox-aware)', 'studio-agent' ),
				'description'         => __( 'Creates a post or page. If a sandbox session is open, the op is queued and a temp_id is returned (use it as post_id in subsequent sandbox ops to reference this not-yet-real entity). If no sandbox is open, the post is created immediately on the host site.', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () { return current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'title' ),
					'properties' => array(
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'post_type' => array( 'type' => 'string', 'description' => 'post|page (default post)' ),
						'status'    => array( 'type' => 'string', 'description' => 'draft|publish|pending (default draft)' ),
					),
				),
				'execute_callback'    => static function ( $args ) {
					$args = is_array( $args ) ? $args : array();
					// Sandbox first.
					$sandboxed = Studio_Agent_Sandbox::record_or_pass(
						'studio/sandbox-create-post',
						$args,
						array( 'kind' => 'post' )
					);
					if ( null !== $sandboxed ) {
						return $sandboxed;
					}
					// Direct execution.
					$post_id = wp_insert_post(
						array(
							'post_title'   => (string) ( $args['title'] ?? '' ),
							'post_content' => (string) ( $args['content'] ?? '' ),
							'post_type'    => (string) ( $args['post_type'] ?? 'post' ),
							'post_status'  => (string) ( $args['status'] ?? 'draft' ),
						),
						true
					);
					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}
					Studio_Agent_Sandbox::bump_revision();
					return array(
						'id'        => (int) $post_id,
						'permalink' => (string) get_permalink( (int) $post_id ),
						'edit_url'  => (string) get_edit_post_link( (int) $post_id, 'raw' ),
					);
				},
				'meta'                => array( 'annotations' => array( 'destructive' => true ) ),
			)
		);

		wp_register_ability(
			'studio/sandbox-update-option',
			array(
				'label'               => __( 'Update a wp_option (sandbox-aware)', 'studio-agent' ),
				'description'         => __( 'Update a wp_option. If a sandbox session is open, the op is queued. Restricted to a small allowlist of safe options (blogname, blogdescription, show_on_front, page_on_front).', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'name', 'value' ),
					'properties' => array(
						'name'  => array( 'type' => 'string', 'description' => 'Option name. Allowed: blogname, blogdescription, show_on_front, page_on_front.' ),
						'value' => array( 'description' => 'New value. Strings/ints/temp_ids accepted depending on the option.' ),
					),
				),
				'execute_callback'    => static function ( $args ) {
					$args  = is_array( $args ) ? $args : array();
					$name  = (string) ( $args['name'] ?? '' );
					$allow = array( 'blogname', 'blogdescription', 'show_on_front', 'page_on_front' );
					if ( ! in_array( $name, $allow, true ) ) {
						return new WP_Error( 'studio_option_not_allowed', sprintf( 'Option "%s" is not in the allowed list.', $name ) );
					}
					$sandboxed = Studio_Agent_Sandbox::record_or_pass(
						'studio/sandbox-update-option',
						$args,
						array() // No new entity created — no temp id needed.
					);
					if ( null !== $sandboxed ) {
						return $sandboxed;
					}
					update_option( $name, $args['value'] );
					Studio_Agent_Sandbox::bump_revision();
					return array( 'option_name' => $name, 'value' => get_option( $name ) );
				},
				'meta'                => array( 'annotations' => array( 'destructive' => true ) ),
			)
		);

		// preview-theme — stage a theme for the user to review in a Playground iframe.
		wp_register_ability(
			'studio/preview-theme',
			array(
				'label'               => __( 'Preview a theme in Playground', 'studio-agent' ),
				'description'         => __( 'Renders the theme files in memory and stages them for the user to review in a Playground iframe alongside the chat. The user accepts or rejects from the UI; on accept, the theme is written and activated on this site. PREFER this over studio/create-theme when the user asks for a new theme — they almost always want to see it before committing.', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () {
					return current_user_can( 'switch_themes' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'slug', 'name' ),
					'properties' => array(
						'slug'        => array( 'type' => 'string', 'description' => 'Theme directory slug. Lowercase, alphanumeric and dashes only.', 'pattern' => '^[a-z0-9][a-z0-9-]*$' ),
						'name'        => array( 'type' => 'string', 'description' => 'Human-readable theme name.' ),
						'description' => array( 'type' => 'string', 'description' => 'One-sentence theme description.' ),
						'author'      => array( 'type' => 'string', 'description' => 'Theme author. Defaults to "Studio Agent".' ),
						'background'  => array( 'type' => 'string', 'description' => 'Background hex color (default white).' ),
						'foreground'  => array( 'type' => 'string', 'description' => 'Foreground/text hex color (default black).' ),
						'accent'      => array( 'type' => 'string', 'description' => 'Accent/link hex color.' ),
					),
				),
				'execute_callback'    => static function ( $args ) {
					$rendered = Studio_Agent_Theme_Tools::render_theme_files( $args );
					if ( is_wp_error( $rendered ) ) {
						return $rendered;
					}
					$preview_id = Studio_Agent_Theme_Preview::stage(
						is_array( $args ) ? $args : array(),
						$rendered['files']
					);
					return array(
						'preview_id'   => $preview_id,
						'theme_slug'   => $rendered['slug'],
						'theme_name'   => $rendered['name'],
						'files_staged' => array_keys( $rendered['files'] ),
						'note'         => 'Theme staged. The user will see it in a Playground preview alongside the chat and choose to accept or reject. Do NOT also call studio/create-theme — wait for the user to accept.',
					);
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => false,
					),
				),
			)
		);

		// create-theme — generates a block theme skeleton on disk.
		wp_register_ability(
			'studio/create-theme',
			array(
				'label'               => __( 'Create a new theme', 'studio-agent' ),
				'description'         => __( 'Generates a minimal block theme on disk under wp-content/themes/<slug>/, including style.css, theme.json, templates/index.html, parts/header.html and parts/footer.html. Does not activate it — use studio/activate-theme afterwards.', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () {
					return current_user_can( 'switch_themes' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'slug', 'name' ),
					'properties' => array(
						'slug'        => array(
							'type'        => 'string',
							'description' => 'Theme directory slug. Lowercase, alphanumeric and dashes only (e.g. "notes-blog").',
							'pattern'     => '^[a-z0-9][a-z0-9-]*$',
						),
						'name'        => array(
							'type'        => 'string',
							'description' => 'Human-readable theme name (e.g. "Notes Blog").',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'One-sentence theme description.',
						),
						'author'      => array(
							'type'        => 'string',
							'description' => 'Theme author. Defaults to "Studio Agent".',
						),
						'background'  => array(
							'type'        => 'string',
							'description' => 'Background color as hex (e.g. "#ffffff"). Defaults to white.',
						),
						'foreground'  => array(
							'type'        => 'string',
							'description' => 'Foreground / text color as hex (e.g. "#000000"). Defaults to black.',
						),
						'accent'      => array(
							'type'        => 'string',
							'description' => 'Accent / link color as hex. Defaults to a neutral blue.',
						),
					),
				),
				'execute_callback'    => array( 'Studio_Agent_Theme_Tools', 'create_theme' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);

		// write-theme-file — append/overwrite a single file inside an existing theme dir.
		wp_register_ability(
			'studio/write-theme-file',
			array(
				'label'               => __( 'Write a file inside a theme', 'studio-agent' ),
				'description'         => __( 'Writes (or overwrites) a single file inside wp-content/themes/<theme_slug>/<file_path>. Use to add custom templates (templates/page.html), parts (parts/sidebar.html), block patterns (patterns/cta.php), or styles. Path is sanitized — must stay inside the theme directory. Theme must already exist (use studio/create-theme first).', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () {
					return current_user_can( 'switch_themes' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'theme_slug', 'file_path', 'content' ),
					'properties' => array(
						'theme_slug' => array(
							'type'        => 'string',
							'description' => 'Slug of the existing theme directory.',
							'pattern'     => '^[a-z0-9][a-z0-9-]*$',
						),
						'file_path'  => array(
							'type'        => 'string',
							'description' => 'Relative path inside the theme dir, e.g. "templates/page.html" or "patterns/hero.php".',
						),
						'content'    => array(
							'type'        => 'string',
							'description' => 'Full file contents to write.',
						),
					),
				),
				'execute_callback'    => array( 'Studio_Agent_Theme_Tools', 'write_file' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
					),
				),
			)
		);

		// activate-theme — switch active theme.
		wp_register_ability(
			'studio/activate-theme',
			array(
				'label'               => __( 'Activate a theme', 'studio-agent' ),
				'description'         => __( 'Switches the active theme to the given slug. The theme must already exist on disk (created via studio/create-theme or installed otherwise).', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () {
					return current_user_can( 'switch_themes' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'theme_slug' ),
					'properties' => array(
						'theme_slug' => array(
							'type'        => 'string',
							'description' => 'Slug of the theme to activate.',
							'pattern'     => '^[a-z0-9][a-z0-9-]*$',
						),
					),
				),
				'execute_callback'    => array( 'Studio_Agent_Theme_Tools', 'activate_theme' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
					),
				),
			)
		);

		// list-skills — names + descriptions of the Studio skill bundles available to load.
		wp_register_ability(
			'studio/list-skills',
			array(
				'label'               => __( 'List Studio skills', 'studio-agent' ),
				'description'         => __( 'Returns the list of Studio skill bundles shipped with the plugin (e.g. "studio", "studio-cli"). Each entry has a name and a short description. Use this to discover what guidance is available, then call studio/load-skill to pull the full content of one. PREFER calling list-skills before any task that involves running wp-cli, managing local WordPress, or building themes/plugins under Studio — the skills contain the conventions you need.', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => static function () {
					return array( 'skills' => Studio_Agent_Skills::list_skills() );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		// load-skill — fetch the full markdown of one named skill.
		wp_register_ability(
			'studio/load-skill',
			array(
				'label'               => __( 'Load a Studio skill', 'studio-agent' ),
				'description'         => __( 'Returns the full markdown content of a Studio skill by name (e.g. "studio", "studio-cli"). The content is authoritative guidance you should follow for the rest of the conversation — wp-cli wrapping, workflow, command syntax. Discover names with studio/list-skills first.', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array(
						'name' => array(
							'type'        => 'string',
							'description' => 'Skill name as returned by studio/list-skills.',
							'pattern'     => '^[a-z0-9][a-z0-9-]*$',
						),
					),
				),
				'execute_callback'    => static function ( $args ) {
					$name = is_array( $args ) ? (string) ( $args['name'] ?? '' ) : '';
					return Studio_Agent_Skills::load_skill( $name );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		// list-plugins — read-only.
		wp_register_ability(
			'studio/list-plugins',
			array(
				'label'               => __( 'List installed plugins', 'studio-agent' ),
				'description'         => __( 'Returns all installed plugins (active and inactive) with their name, version, and active state.', 'studio-agent' ),
				'category'            => 'studio',
				'permission_callback' => static function () {
					return current_user_can( 'activate_plugins' );
				},
				'execute_callback'    => static function () {
					if ( ! function_exists( 'get_plugins' ) ) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}
					$all    = get_plugins();
					$active = (array) get_option( 'active_plugins', array() );
					$out    = array();
					foreach ( $all as $file => $data ) {
						$out[] = array(
							'file'    => $file,
							'name'    => $data['Name'] ?? $file,
							'version' => $data['Version'] ?? '',
							'active'  => in_array( $file, $active, true ),
						);
					}
					return array(
						'count'   => count( $out ),
						'plugins' => $out,
					);
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}
);
