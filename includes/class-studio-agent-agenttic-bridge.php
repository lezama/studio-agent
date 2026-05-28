<?php
/**
 * JSON-RPC bridge for `@automattic/agenttic-client`.
 *
 * Translates the agenttic protocol (JSON-RPC 2.0 over HTTP, A2A-shaped Task
 * envelopes) into a Studio_Agent_REST::run_turn() call. The motivation is
 * code reuse: the agenttic React hooks (`useAgentChat`) are the right UI
 * layer, so we expose our chat dispatcher under that wire format rather
 * than reimplementing the React side against our custom REST shape.
 *
 * Mirrors the structure of OpenclaWP_Agenttic_Bridge — same wire format,
 * same SSE-as-single-frame strategy. The single delta is that this bridge
 * smuggles `pending_previews` into the Task message as a custom DataPart
 * so the React side can drive the Playground preview pane without a
 * second round-trip.
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

final class Studio_Agent_Agenttic_Bridge {

	private const NAMESPACE = 'studio-agent/v1';

	private const PARSE_ERROR      = -32700;
	private const INVALID_REQUEST  = -32600;
	private const METHOD_NOT_FOUND = -32601;
	private const INVALID_PARAMS   = -32602;
	private const INTERNAL_ERROR   = -32603;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agenttic/(?P<agent>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'agent' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);
	}

	public static function check_permission( WP_REST_Request $request ) {
		$allowed = (bool) apply_filters(
			'studio_agent_agenttic_bridge_permission',
			current_user_can( 'manage_options' ),
			$request
		);

		if ( ! $allowed ) {
			return new WP_Error(
				'studio_agent_forbidden',
				__( 'You do not have permission to use the Studio Agent.', 'studio-agent' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	public static function handle( WP_REST_Request $request ) {
		$agent_slug = (string) $request->get_param( 'agent' );

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return self::error_response( null, self::PARSE_ERROR, 'Invalid JSON-RPC envelope.' );
		}

		$rpc_id  = $body['id'] ?? null;
		$method  = isset( $body['method'] ) ? (string) $body['method'] : '';
		$params  = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();
		$jsonrpc = isset( $body['jsonrpc'] ) ? (string) $body['jsonrpc'] : '';

		if ( '2.0' !== $jsonrpc ) {
			return self::error_response( $rpc_id, self::INVALID_REQUEST, 'jsonrpc must be "2.0".' );
		}

		$is_streaming = ( 'message/stream' === $method );

		if ( 'message/send' !== $method && ! $is_streaming ) {
			return self::error_response(
				$rpc_id,
				self::METHOD_NOT_FOUND,
				sprintf( 'Method "%s" is not supported. v0 supports message/send and message/stream.', $method )
			);
		}

		// Validate agent — for v0 we accept any registered agent slug; the
		// run_turn() flow is agent-agnostic but we keep the URL parameter
		// so future routing per-agent stays cheap.
		if ( function_exists( 'wp_has_agent' ) && ! wp_has_agent( $agent_slug ) ) {
			return self::error_response(
				$rpc_id,
				self::INVALID_PARAMS,
				sprintf( 'Agent "%s" is not registered.', $agent_slug )
			);
		}

		$message = isset( $params['message'] ) && is_array( $params['message'] ) ? $params['message'] : array();
		$text    = self::extract_text( $message );
		if ( '' === $text ) {
			return self::error_response( $rpc_id, self::INVALID_PARAMS, 'message must contain at least one non-empty text part.' );
		}

		$session_id = isset( $params['sessionId'] ) && is_string( $params['sessionId'] ) ? $params['sessionId'] : '';
		$task_id    = isset( $params['id'] ) && is_string( $params['id'] ) ? $params['id'] : self::generate_task_id();

		$result = Studio_Agent_REST::run_turn( $text, $session_id );

		if ( is_wp_error( $result ) ) {
			$err_payload = array(
				'jsonrpc' => '2.0',
				'id'      => $rpc_id,
				'error'   => array(
					'code'    => self::INTERNAL_ERROR,
					'message' => $result->get_error_message(),
					'data'    => array( 'code' => $result->get_error_code() ),
				),
			);
			if ( $is_streaming ) {
				self::emit_sse_event( $err_payload );
				exit;
			}
			return new WP_REST_Response( $err_payload, 200 );
		}

		$reply       = (string) $result['reply'];
		$session_out = (string) $result['session_id'];

		// Compose the Task. The assistant's text part comes first; any
		// pending previews ride along as a DataPart so the React UI can
		// pick them up and drive the Playground iframe without a second
		// round-trip.
		$parts = array(
			array(
				'type' => 'text',
				'text' => $reply,
			),
		);
		if ( ! empty( $result['pending_previews'] ) ) {
			// useAgentChat collapses A2A `parts` to UI message `content` and
			// drops the part `name`, so we put the discriminator inside the
			// `data` object itself where it survives the mapping.
			$parts[] = array(
				'type'     => 'data',
				'name'     => 'studio.preview',
				'data'     => array(
					'kind'     => 'studio.preview',
					'previews' => array_values( (array) $result['pending_previews'] ),
				),
				'mimeType' => 'application/vnd.studio-agent.preview+json',
			);
		}

		if ( ! empty( $result['sandbox'] ) ) {
			$parts[] = array(
				'type'     => 'data',
				'name'     => 'studio.sandbox',
				'data'     => array(
					'kind'    => 'studio.sandbox',
					'sandbox' => $result['sandbox'],
				),
				'mimeType' => 'application/vnd.studio-agent.sandbox+json',
			);
		}

		// Surface tool calls so the UI can show the user what work the
		// agent did this turn (transparency, trust, debugging).
		if ( ! empty( $result['tool_calls'] ) ) {
			$parts[] = array(
				'type'     => 'data',
				'name'     => 'studio.tool_calls',
				'data'     => array(
					'kind'  => 'studio.tool_calls',
					'tools' => array_values( (array) $result['tool_calls'] ),
				),
				'mimeType' => 'application/vnd.studio-agent.tool-calls+json',
			);
		}

		$task = array(
			'id'        => $task_id,
			'sessionId' => $session_out,
			'status'    => array(
				'state'     => 'completed',
				'message'   => array(
					'role'      => 'agent',
					'parts'     => $parts,
					'messageId' => self::generate_message_id(),
					'kind'      => 'message',
				),
				'timestamp' => gmdate( 'c' ),
			),
		);

		if ( $is_streaming ) {
			self::emit_sse_event(
				array(
					'jsonrpc' => '2.0',
					'id'      => $rpc_id,
					'result'  => $task,
				)
			);
			exit;
		}

		return self::success_response( $rpc_id, $task );
	}

	private static function emit_sse_event( array $payload ): void {
		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store' );
		header( 'X-Accel-Buffering: no' );
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		echo 'data: ' . wp_json_encode( $payload ) . "\n\n";
		flush();
	}

	private static function extract_text( array $message ): string {
		$parts = isset( $message['parts'] ) && is_array( $message['parts'] ) ? $message['parts'] : array();
		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}
			if ( 'text' !== ( $part['type'] ?? '' ) ) {
				continue;
			}
			$text = isset( $part['text'] ) ? trim( (string) $part['text'] ) : '';
			if ( '' !== $text ) {
				return $text;
			}
		}
		return '';
	}

	private static function success_response( $rpc_id, array $result ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $rpc_id,
				'result'  => $result,
			),
			200
		);
	}

	private static function error_response( $rpc_id, int $code, string $message, array $data = array() ): WP_REST_Response {
		$err = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( ! empty( $data ) ) {
			$err['data'] = $data;
		}
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $rpc_id,
				'error'   => $err,
			),
			200
		);
	}

	private static function generate_task_id(): string {
		return 'task-' . wp_generate_password( 12, false, false );
	}

	private static function generate_message_id(): string {
		return wp_generate_password( 12, false, false );
	}
}
