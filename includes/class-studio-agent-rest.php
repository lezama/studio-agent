<?php
/**
 * REST endpoint: POST /studio-agent/v1/chat
 *
 * Accepts { message, session_id? }. Loads (or starts) the session, appends
 * the user message, calls the wpcom proxy, persists the assistant reply,
 * and returns it.
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

class Studio_Agent_REST {

	public const NAMESPACE = 'studio-agent/v1';

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_chat' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'message'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'session_id' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		// Public — no auth, since playground.wordpress.net iframe loads this URL.
		// The preview id is unguessable (UUID v4) and the transient expires.
		register_rest_route(
			self::NAMESPACE,
			'/preview/(?P<id>[A-Za-z0-9-]+)/blueprint',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_preview_blueprint' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/skills',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_skills_list' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sandbox',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_sandbox_status' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/sandbox/(?P<id>[A-Za-z0-9-]+)/blueprint',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_sandbox_blueprint' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/sandbox/(?P<id>[A-Za-z0-9-]+)/accept',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_sandbox_accept' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/sandbox/(?P<id>[A-Za-z0-9-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'handle_sandbox_reject' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/preview/(?P<id>[A-Za-z0-9-]+)/accept',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_preview_accept' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/preview/(?P<id>[A-Za-z0-9-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'handle_preview_reject' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	public static function handle_preview_blueprint( WP_REST_Request $request ) {
		$id      = (string) $request->get_param( 'id' );
		$preview = Studio_Agent_Theme_Preview::get( $id );
		if ( ! $preview ) {
			return new WP_Error( 'studio_preview_not_found', 'Preview expired or not found.', array( 'status' => 404 ) );
		}
		$blueprint = Studio_Agent_Theme_Preview::build_blueprint( $preview );
		// CORS: playground.wordpress.net loads this from a different origin.
		$response = rest_ensure_response( $blueprint );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function handle_preview_accept( WP_REST_Request $request ) {
		$id      = (string) $request->get_param( 'id' );
		$preview = Studio_Agent_Theme_Preview::get( $id );
		if ( ! $preview ) {
			return new WP_Error( 'studio_preview_not_found', 'Preview expired or not found.', array( 'status' => 404 ) );
		}

		$create = Studio_Agent_Theme_Tools::create_theme( (array) ( $preview['theme_args'] ?? array() ) );
		if ( is_wp_error( $create ) ) {
			return $create;
		}

		$activate = Studio_Agent_Theme_Tools::activate_theme(
			array( 'theme_slug' => (string) ( $preview['theme_args']['slug'] ?? '' ) )
		);
		if ( is_wp_error( $activate ) ) {
			return $activate;
		}

		Studio_Agent_Theme_Preview::discard( $id );

		return rest_ensure_response(
			array(
				'accepted'    => true,
				'theme_slug'  => $activate['active_theme'],
				'preview_url' => $activate['preview_url'],
				'admin_url'   => $activate['admin_url'],
				'files'       => $create['files_created'],
			)
		);
	}

	public static function handle_preview_reject( WP_REST_Request $request ) {
		$id        = (string) $request->get_param( 'id' );
		$discarded = Studio_Agent_Theme_Preview::discard( $id );
		return rest_ensure_response( array( 'discarded' => $discarded ) );
	}

	public static function handle_skills_list() {
		return rest_ensure_response( array( 'skills' => Studio_Agent_Skills::list_skills() ) );
	}

	/* ---------- sandbox endpoints ---------- */

	public static function handle_sandbox_status() {
		$id = Studio_Agent_Sandbox::active_session_id();
		if ( ! $id ) {
			return rest_ensure_response( array( 'active' => false ) );
		}
		$session = Studio_Agent_Sandbox::get( $id );
		return rest_ensure_response(
			array(
				'active'  => true,
				'session' => array(
					'id'            => $session['id'],
					'note'          => $session['note'],
					'created_at'    => $session['created_at'],
					'base_revision' => $session['base_revision'],
					'op_count'      => count( (array) $session['log'] ),
					'log'           => $session['log'],
				),
			)
		);
	}

	public static function handle_sandbox_blueprint( WP_REST_Request $request ) {
		$id      = (string) $request->get_param( 'id' );
		$session = Studio_Agent_Sandbox::get( $id );
		if ( ! $session ) {
			return new WP_Error( 'studio_sandbox_not_found', 'Sandbox session not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( Studio_Agent_Snapshot::build_blueprint( $id ) );
	}

	public static function handle_sandbox_accept( WP_REST_Request $request ) {
		$id     = (string) $request->get_param( 'id' );
		$result = Studio_Agent_Sandbox::accept( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function handle_sandbox_reject( WP_REST_Request $request ) {
		$id      = (string) $request->get_param( 'id' );
		Studio_Agent_Sandbox::discard( $id );
		return rest_ensure_response( array( 'rejected' => true, 'session_id' => $id ) );
	}

	public static function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function handle_chat( WP_REST_Request $request ) {
		$message    = (string) $request->get_param( 'message' );
		$session_id = (string) ( $request->get_param( 'session_id' ) ?: '' );

		$result = self::run_turn( $message, $session_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Run one full chat turn: append the user message, run the wp-ai-client
	 * tool-calling loop with the studio agent's abilities, persist the
	 * assistant reply, and return a flat result array.
	 *
	 * Shared between the legacy `/chat` REST endpoint and the agenttic
	 * JSON-RPC bridge so both surfaces get the same agent runtime,
	 * including preview staging.
	 *
	 * @param string $message    User text. Required, non-empty after trim.
	 * @param string $session_id Session id; empty string starts a new one.
	 * @return array{
	 *     session_id:string,
	 *     reply:string,
	 *     model:string,
	 *     provider:string,
	 *     usage:array{prompt_tokens:int,completion_tokens:int,total_tokens:int},
	 *     tool_calls:array<int,array<string,mixed>>,
	 *     pending_previews:array<int,array<string,string>>
	 * }|WP_Error
	 */
	public static function run_turn( string $message, string $session_id = '' ) {
		if ( '' === trim( $message ) ) {
			return new WP_Error( 'studio_agent_empty_message', 'message is required', array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'studio_agent_no_ai_client',
				'wp-ai-client is unavailable. Studio Agent requires WordPress 7.0+.',
				array( 'status' => 500 )
			);
		}

		if ( ! class_exists( '\\AgentsAPI\\AI\\WP_Agent_Conversation_Loop' ) ) {
			return new WP_Error(
				'studio_agent_no_agents_api',
				'agents-api is unavailable. Activate the Agents API plugin.',
				array( 'status' => 500 )
			);
		}

		if ( '' === $session_id ) {
			$session_id = Studio_Agent_Conversation::generate_session_id();
		}

		Studio_Agent_Conversation::append( $session_id, 'user', $message );

		$tools         = Studio_Agent_Tools_Resolver::for_abilities( self::available_abilities() );
		$tool_executor = new Studio_Agent_Tool_Executor( $tools['name_to_ability'] );

		// Loop transcript shape is [{role,content}], matching what we already store.
		$messages = Studio_Agent_Conversation::load( $session_id );

		$system_prompt = self::system_prompt();
		$turn_meta     = array(
			'provider' => '',
			'model'    => '',
			'error'    => null,
		);

		$turn_runner = static function ( array $turn_messages, array $context ) use ( $system_prompt, $tools, &$turn_meta ): array {
			unset( $context );

			$ai_messages = Studio_Agent_Message_Adapter::to_ai_client_messages( $turn_messages );

			$builder = wp_ai_client_prompt( $ai_messages )
				->using_system_instruction( $system_prompt );

			if ( ! empty( $tools['declarations_for_provider'] ) ) {
				$builder = $builder->using_function_declarations( ...$tools['declarations_for_provider'] );
			}

			try {
				$generated = $builder->generate_text_result();
			} catch ( \Throwable $e ) {
				$turn_meta['error'] = $e->getMessage();
				return array(
					'messages'              => $turn_messages,
					'tool_calls'            => array(),
					'content'               => '[provider error: ' . $e->getMessage() . ']',
					'conversation_complete' => true,
				);
			}

			if ( is_wp_error( $generated ) ) {
				$turn_meta['error'] = $generated->get_error_message();
				return array(
					'messages'              => $turn_messages,
					'tool_calls'            => array(),
					'content'               => '[provider error: ' . $generated->get_error_message() . ']',
					'conversation_complete' => true,
				);
			}

			$turn_meta['provider'] = (string) $generated->getProviderMetadata()->getId();
			$turn_meta['model']    = (string) $generated->getModelMetadata()->getId();

			$assistant_text = '';
			try {
				$assistant_text = (string) $generated->toText();
			} catch ( \Throwable $e ) {
				// `toText()` throws when the turn responded only with tool calls.
				// That's the normal mediation-mode first-turn shape; treat as empty.
				$assistant_text = '';
			}

			$tool_calls = array();
			$assistant_message = $generated->toMessage();
			foreach ( $assistant_message->getParts() as $part ) {
				if ( $part->getType()->isFunctionCall() ) {
					$call = $part->getFunctionCall();
					// Capture the provider-issued id so the agents-api loop can
					// reuse it when building tool-call/tool-result envelopes.
					// Claude rejects subsequent turns when the tool_use_id
					// passed back in the FunctionResponse does not match the
					// one the model issued.
					$tool_calls[] = array(
						'id'         => (string) $call->getId(),
						'name'       => (string) $call->getName(),
						'parameters' => $call->getArgs(),
					);
				}
			}

			$usage              = $generated->getTokenUsage();
			$prompt_tokens      = $usage->getPromptTokens();
			$completion_tokens  = $usage->getCompletionTokens();

			$out = array(
				'messages'   => $turn_messages,
				'tool_calls' => $tool_calls,
				'usage'      => array(
					'prompt_tokens'     => $prompt_tokens,
					'completion_tokens' => $completion_tokens,
					'total_tokens'      => $prompt_tokens + $completion_tokens,
				),
			);
			if ( '' !== $assistant_text ) {
				$out['content'] = $assistant_text;
			}
			return $out;
		};

		$loop_options = array(
			'max_turns' => 5,
		);
		if ( ! empty( $tools['declarations'] ) ) {
			$loop_options['tool_executor']     = $tool_executor;
			$loop_options['tool_declarations'] = $tools['declarations'];
		}

		$loop_result = \AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
			$messages,
			$turn_runner,
			$loop_options
		);

		$final_messages = isset( $loop_result['messages'] ) && is_array( $loop_result['messages'] ) ? $loop_result['messages'] : $messages;
		$reply          = Studio_Agent_Message_Adapter::last_assistant_text( $final_messages );

		Studio_Agent_Conversation::append( $session_id, 'assistant', $reply );

		$usage_total = isset( $loop_result['usage'] ) && is_array( $loop_result['usage'] ) ? $loop_result['usage'] : array();

		// If a sandbox session is open, surface its id so the UI can
		// fetch the blueprint and render a Playground preview.
		$active_sandbox = Studio_Agent_Sandbox::active_session_id();

		return array(
			'session_id'       => $session_id,
			'reply'            => $reply,
			'model'            => $turn_meta['model'],
			'provider'         => $turn_meta['provider'],
			'usage'            => array(
				'prompt_tokens'     => (int) ( $usage_total['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $usage_total['completion_tokens'] ?? 0 ),
				'total_tokens'      => (int) ( $usage_total['total_tokens'] ?? 0 ),
			),
			'tool_calls'       => $tool_executor->get_tool_calls_made(),
			'pending_previews' => $tool_executor->get_pending_previews(),
			'sandbox'          => $active_sandbox ? array( 'session_id' => $active_sandbox ) : null,
		);
	}

	/**
	 * The list of ability names the agent can call. Keep narrow on purpose —
	 * each new ability is a new attack surface for the model.
	 *
	 * @return list<string>
	 */
	private static function available_abilities(): array {
		$default = array(
			'studio/site-info',
			'studio/list-posts',
			'studio/list-plugins',
			'studio/list-skills',
			'studio/load-skill',
			'studio/sandbox-open',
			'studio/sandbox-status',
			'studio/sandbox-create-post',
			'studio/sandbox-update-option',
			'studio/preview-theme',
			'studio/create-theme',
			'studio/write-theme-file',
			'studio/activate-theme',
		);
		/**
		 * Filter the abilities exposed to the Studio Agent on each chat turn.
		 *
		 * Other plugins can append their own abilities (registered via
		 * wp_register_ability()) to make them callable from the agent.
		 *
		 * @since 0.3.0
		 *
		 * @param string[] $abilities Ability slugs.
		 */
		$filtered = (array) apply_filters( 'studio_agent_abilities', $default );
		// Defensive: every entry must be a registered ability.
		return array_values(
			array_filter(
				$filtered,
				static fn( $name ) => is_string( $name ) && function_exists( 'wp_has_ability' ) && wp_has_ability( $name )
			)
		);
	}

	private static function system_prompt(): string {
		$studio_rules = Studio_Agent_Skills::studio_skill_for_system_prompt();

		return 'You are the Studio Agent, embedded in a local WordPress site running inside Automattic Studio. '
			. 'You help developers explore, build, and run WordPress. Be concise and practical. '
			. "\n\n=== STUDIO RULES (auto-loaded) ===\n"
			. $studio_rules
			. "\n=== END STUDIO RULES ===\n"
			. "\nInspection tools: studio/site-info, studio/list-posts, studio/list-plugins. "
			. "Skill tools: studio/list-skills, studio/load-skill. "
			. "Sandbox tools: studio/sandbox-open, studio/sandbox-status, studio/sandbox-create-post, studio/sandbox-update-option. "
			. "Theme tools: studio/preview-theme (preferred), studio/create-theme, studio/write-theme-file, studio/activate-theme."
			. "\n\nGuidelines:\n"
			. "- Studio's top-level rules are above; they ALWAYS apply.\n"
			. "- For deeper topics (block development, REST API, plugin authoring, wp-cli ops, block themes), call studio/list-skills and pull the relevant ones with studio/load-skill — they're authoritative.\n"
			. "- When the user asks about the current site, USE the inspection tools — do not guess or refuse.\n"
			. "- For destructive content/option changes (creating posts, updating site name, etc.): call studio/sandbox-open FIRST, then use the sandbox-* abilities to queue ops. Each op returns a temp_id; reuse it in subsequent ops to reference not-yet-real entities. The UI will boot a Playground preview with all queued ops applied so the user can review and Accept All / Reject. STOP after queuing — don't call sandbox-status repeatedly; the UI handles it.\n"
			. "- For NEW themes, ALWAYS call studio/preview-theme first (separate flow from sandbox). After calling preview-theme, STOP — they will accept or reject in the UI.\n"
			. "- Only call studio/create-theme + studio/activate-theme directly if the user explicitly says they don't want a preview.\n"
			. "- Themes are block themes (theme.json + HTML templates). Use Gutenberg block markup like <!-- wp:paragraph --><p>...</p><!-- /wp:paragraph -->.";
	}
}
