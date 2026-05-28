<?php
/**
 * Conversation storage.
 *
 * Minimal session store backed by wp_options. Each session is keyed by a
 * UUID-like slug; we keep the message array (role/content) so the provider
 * can be stateless. This is intentionally simple for the PoC — when we wire
 * the agents-api conversation loop we'll move to its transcript store.
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

class Studio_Agent_Conversation {

	private const OPTION_PREFIX = 'studio_agent_session_';
	private const MAX_MESSAGES  = 50;

	public static function load( string $session_id ): array {
		$messages = get_option( self::OPTION_PREFIX . $session_id, array() );
		return is_array( $messages ) ? $messages : array();
	}

	public static function save( string $session_id, array $messages ): void {
		if ( count( $messages ) > self::MAX_MESSAGES ) {
			$messages = array_slice( $messages, -self::MAX_MESSAGES );
		}
		update_option( self::OPTION_PREFIX . $session_id, $messages, false );
	}

	public static function append( string $session_id, string $role, string $content ): array {
		$messages   = self::load( $session_id );
		$messages[] = array(
			'role'    => $role,
			'content' => $content,
		);
		self::save( $session_id, $messages );
		return $messages;
	}

	public static function generate_session_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return bin2hex( random_bytes( 16 ) );
	}
}
