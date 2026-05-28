<?php
/**
 * Transcript ↔ wp-ai-client Message DTO adapter.
 *
 * The agents-api conversation loop owns the transcript shape — a flat list of
 * `[ 'role' => ..., 'content' => ..., 'type' => ..., 'payload' => ...,
 * 'metadata' => ... ]` envelopes. Each turn-runner call needs that transcript
 * translated into the `Message` DTOs that `wp_ai_client_prompt()` accepts, with
 * tool-call / tool-result envelopes mapped to structured FunctionCall and
 * FunctionResponse parts so Claude (and other strict-format providers) accept
 * the conversation between mediated turns.
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

final class Studio_Agent_Message_Adapter {

	/**
	 * Convert a transcript array into a list of WP AI Client `Message` DTOs.
	 *
	 * Type mapping:
	 *  - text                → Message(role, [MessagePart(text)])
	 *  - tool_call           → Message(model, [MessagePart(FunctionCall)])
	 *  - tool_result         → Message(user,  [MessagePart(FunctionResponse)])
	 *  - other typed envelopes (approval_required, etc.) are skipped.
	 *
	 * @param array<int,array<string,mixed>> $messages Transcript messages.
	 * @return list<\WordPress\AiClient\Messages\DTO\Message>
	 */
	public static function to_ai_client_messages( array $messages ): array {
		if ( ! class_exists( '\\WordPress\\AiClient\\Messages\\DTO\\Message' ) ) {
			return array();
		}

		$out = array();
		foreach ( $messages as $message ) {
			$role = (string) ( $message['role'] ?? '' );
			$type = (string) ( $message['type'] ?? 'text' );

			if ( 'tool_call' === $type ) {
				$dto = self::tool_call_message( $message );
				if ( null !== $dto ) {
					$out[] = $dto;
				}
				continue;
			}

			if ( 'tool_result' === $type ) {
				$dto = self::tool_result_message( $message );
				if ( null !== $dto ) {
					$out[] = $dto;
				}
				continue;
			}

			if ( '' !== $type && 'text' !== $type ) {
				continue;
			}

			$text = self::extract_text( $message['content'] ?? '' );
			if ( '' === $text ) {
				continue;
			}

			if ( 'user' === $role ) {
				$role_enum = \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user();
			} elseif ( 'assistant' === $role || 'model' === $role ) {
				$role_enum = \WordPress\AiClient\Messages\Enums\MessageRoleEnum::model();
			} else {
				continue;
			}

			$out[] = new \WordPress\AiClient\Messages\DTO\Message(
				$role_enum,
				array( new \WordPress\AiClient\Messages\DTO\MessagePart( $text ) )
			);
		}

		return $out;
	}

	/**
	 * Walk the transcript backwards and return the most recent text-typed
	 * assistant reply. Tool-call / tool-result envelopes are skipped so the
	 * UI never surfaces internal infrastructure messages as the bot's answer.
	 *
	 * @param array<int,array<string,mixed>> $messages Transcript messages.
	 * @return string Empty string when no text-typed assistant message is present.
	 */
	public static function last_assistant_text( array $messages ): string {
		for ( $i = count( $messages ) - 1; $i >= 0; --$i ) {
			$message = $messages[ $i ];
			if ( 'assistant' !== ( $message['role'] ?? '' ) ) {
				continue;
			}
			$type = (string) ( $message['type'] ?? 'text' );
			if ( '' !== $type && 'text' !== $type ) {
				continue;
			}
			$text = self::extract_text( $message['content'] ?? '' );
			if ( '' !== $text ) {
				return $text;
			}
		}
		return '';
	}

	private static function tool_call_message( array $envelope ): ?\WordPress\AiClient\Messages\DTO\Message {
		if ( ! class_exists( '\\WordPress\\AiClient\\Tools\\DTO\\FunctionCall' ) ) {
			return null;
		}
		$payload    = isset( $envelope['payload'] ) && is_array( $envelope['payload'] ) ? $envelope['payload'] : array();
		$metadata   = isset( $envelope['metadata'] ) && is_array( $envelope['metadata'] ) ? $envelope['metadata'] : array();
		$tool_name  = (string) ( $payload['tool_name'] ?? '' );
		$args       = isset( $payload['parameters'] ) && is_array( $payload['parameters'] ) ? $payload['parameters'] : array();
		$id         = (string) ( $metadata['tool_call_id'] ?? $payload['tool_call_id'] ?? '' );
		if ( '' === $tool_name ) {
			return null;
		}

		$function_call = new \WordPress\AiClient\Tools\DTO\FunctionCall( '' !== $id ? $id : null, $tool_name, $args );

		return new \WordPress\AiClient\Messages\DTO\Message(
			\WordPress\AiClient\Messages\Enums\MessageRoleEnum::model(),
			array( new \WordPress\AiClient\Messages\DTO\MessagePart( $function_call ) )
		);
	}

	private static function tool_result_message( array $envelope ): ?\WordPress\AiClient\Messages\DTO\Message {
		if ( ! class_exists( '\\WordPress\\AiClient\\Tools\\DTO\\FunctionResponse' ) ) {
			return null;
		}
		$payload   = isset( $envelope['payload'] ) && is_array( $envelope['payload'] ) ? $envelope['payload'] : array();
		$metadata  = isset( $envelope['metadata'] ) && is_array( $envelope['metadata'] ) ? $envelope['metadata'] : array();
		$tool_name = (string) ( $payload['tool_name'] ?? '' );
		$id        = (string) ( $metadata['tool_call_id'] ?? $payload['tool_call_id'] ?? '' );
		// Prefer the executor's structured result; fall back to the envelope's
		// human-readable content if that's all we have.
		$response = $payload['result'] ?? ( $payload['error'] ?? null );
		if ( null === $response ) {
			$response = self::extract_text( $envelope['content'] ?? '' );
		}
		if ( '' === $tool_name ) {
			return null;
		}

		$function_response = new \WordPress\AiClient\Tools\DTO\FunctionResponse( '' !== $id ? $id : null, $tool_name, $response );

		return new \WordPress\AiClient\Messages\DTO\Message(
			\WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
			array( new \WordPress\AiClient\Messages\DTO\MessagePart( $function_response ) )
		);
	}

	/**
	 * Pull a flat string out of a message's `content` field, which may be a
	 * scalar string or a list of parts.
	 *
	 * @param mixed $content Raw content.
	 */
	private static function extract_text( $content ): string {
		if ( is_string( $content ) ) {
			return $content;
		}
		if ( ! is_array( $content ) ) {
			return '';
		}

		$out = '';
		foreach ( $content as $part ) {
			if ( is_string( $part ) ) {
				$out .= $part;
			} elseif ( is_array( $part ) && isset( $part['text'] ) ) {
				$out .= (string) $part['text'];
			}
		}
		return $out;
	}
}
