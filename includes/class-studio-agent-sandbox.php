<?php
/**
 * Sandbox session: queue destructive ability calls, replay on accept.
 *
 * The agent normally executes abilities directly against the host site.
 * When a sandbox session is open, destructive abilities consult this class
 * and — instead of executing — append themselves to a log keyed by a
 * temporary id. The caller (the agent) gets the temp id back and can use
 * it to reference newly-created entities in subsequent ops.
 *
 * On accept: replay the log against the host site, mapping temp ids to
 * real ids as we go. Reject: discard the session.
 *
 * One active session per site. Stored in wp_options.
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

class Studio_Agent_Sandbox {

	private const ACTIVE_KEY  = 'studio_agent_sandbox_active';
	private const SESSION_PREFIX = 'studio_agent_sandbox_session_';
	private const REVISION_KEY = 'studio_agent_revision';
	private const TTL = HOUR_IN_SECONDS * 6;

	/* ---------- session lifecycle ---------- */

	/** @return string Session id. */
	public static function open( string $note = '' ): string {
		// Close any pre-existing session — single session per site.
		$existing = self::active_session_id();
		if ( $existing ) {
			self::discard( $existing );
		}

		$session_id = wp_generate_uuid4();
		$session = array(
			'id'             => $session_id,
			'note'           => $note,
			'created_at'     => time(),
			'base_revision'  => self::current_revision(),
			'log'            => array(),
			'state'          => 'active',
			'next_temp_seq'  => 1,
		);
		update_option( self::SESSION_PREFIX . $session_id, $session, false );
		update_option( self::ACTIVE_KEY, $session_id, false );
		return $session_id;
	}

	public static function active_session_id(): ?string {
		$id = get_option( self::ACTIVE_KEY, '' );
		if ( ! is_string( $id ) || '' === $id ) {
			return null;
		}
		$session = self::get( $id );
		if ( ! $session || 'active' !== ( $session['state'] ?? '' ) ) {
			delete_option( self::ACTIVE_KEY );
			return null;
		}
		// TTL check.
		if ( time() - (int) $session['created_at'] > self::TTL ) {
			self::discard( $id );
			return null;
		}
		return $id;
	}

	/** @return array|null */
	public static function get( string $session_id ) {
		$session = get_option( self::SESSION_PREFIX . $session_id, null );
		return is_array( $session ) ? $session : null;
	}

	public static function discard( string $session_id ): void {
		delete_option( self::SESSION_PREFIX . $session_id );
		if ( get_option( self::ACTIVE_KEY ) === $session_id ) {
			delete_option( self::ACTIVE_KEY );
		}
	}

	/* ---------- log append (called from sandbox-aware abilities) ---------- */

	/**
	 * If a sandbox is active, record the op and return the placeholder
	 * result (with the temp id). Returns null when there's no active
	 * sandbox — the caller should then execute normally.
	 *
	 * @param string $ability      Ability name.
	 * @param array  $args         Args as the ability would have received.
	 * @param array  $tempid_role  Optional. ['kind' => 'post'|'option'|...] if this op
	 *                              creates a new entity that subsequent ops may
	 *                              reference by temp id.
	 * @return array|null
	 */
	public static function record_or_pass( string $ability, array $args, array $tempid_role = array() ) {
		$session_id = self::active_session_id();
		if ( ! $session_id ) {
			return null;
		}

		$session = self::get( $session_id );
		$temp_id = null;
		if ( ! empty( $tempid_role['kind'] ) ) {
			$seq            = (int) ( $session['next_temp_seq'] ?? 1 );
			$temp_id        = sprintf( 'tmp_%s_%03d', $tempid_role['kind'], $seq );
			$session['next_temp_seq'] = $seq + 1;
		}

		$op = array(
			'ability'  => $ability,
			'args'     => $args,
			'temp_id'  => $temp_id,
			'timestamp' => time(),
		);
		$session['log'][] = $op;
		update_option( self::SESSION_PREFIX . $session_id, $session, false );

		/**
		 * Fires after a destructive ability has been recorded in the
		 * active sandbox session instead of executing.
		 *
		 * Lets other plugins observe the queue (e.g. for telemetry,
		 * activity feeds, or custom diff UIs).
		 *
		 * @since 0.3.0
		 *
		 * @param array  $op         { ability, args, temp_id, timestamp }
		 * @param string $session_id Active session id.
		 */
		do_action( 'studio_agent_sandbox_op_recorded', $op, $session_id );

		return array(
			'sandboxed'  => true,
			'session_id' => $session_id,
			'temp_id'    => $temp_id,
			'note'       => 'Op queued in sandbox; will be applied on accept.',
		);
	}

	/* ---------- revision counter ---------- */

	public static function current_revision(): int {
		return (int) get_option( self::REVISION_KEY, 0 );
	}

	public static function bump_revision(): int {
		$next = self::current_revision() + 1;
		update_option( self::REVISION_KEY, $next, true );
		return $next;
	}

	/* ---------- accept (replay) ---------- */

	/**
	 * Replay the session's log against the host site, resolving temp ids
	 * to real ids as ops execute. Atomic-ish: aborts on first WP_Error
	 * but leaves earlier ops applied (no DB transaction across abilities).
	 *
	 * @return array{accepted:bool,results:list<array>}|WP_Error
	 */
	public static function accept( string $session_id ) {
		$session = self::get( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'studio_sandbox_not_found', 'Sandbox session not found.' );
		}
		if ( 'active' !== $session['state'] ) {
			return new WP_Error( 'studio_sandbox_already_finalized', 'Sandbox session is not active.' );
		}
		$base_rev = (int) $session['base_revision'];
		$current  = self::current_revision();
		if ( $current !== $base_rev ) {
			return new WP_Error(
				'studio_sandbox_stale',
				sprintf(
					'Site changed since the sandbox was opened (base revision %d, current %d). Re-open the sandbox to capture the new state.',
					$base_rev,
					$current
				),
				array( 'base_revision' => $base_rev, 'current_revision' => $current )
			);
		}

		// Detach from "active" before replay so sandbox-aware abilities
		// see no active session and execute normally instead of re-queueing.
		delete_option( self::ACTIVE_KEY );

		$temp_to_real = array();
		$results      = array();
		foreach ( (array) $session['log'] as $op ) {
			$ability = (string) $op['ability'];
			$args    = self::resolve_temp_ids( (array) $op['args'], $temp_to_real );

			if ( ! function_exists( 'wp_get_ability' ) ) {
				return new WP_Error( 'studio_sandbox_no_abilities', 'Abilities API not available.' );
			}
			$ability_obj = wp_get_ability( $ability );
			if ( ! $ability_obj ) {
				$results[] = array( 'op' => $op, 'error' => 'ability_not_registered' );
				continue;
			}

			$result = $ability_obj->execute( $args );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'op' => $op, 'error' => $result->get_error_message() );
				$session['state'] = 'partial';
				$session['accept_error'] = $result->get_error_message();
				update_option( self::SESSION_PREFIX . $session_id, $session, false );
				return new WP_Error(
					'studio_sandbox_op_failed',
					sprintf( 'Op "%s" failed during apply: %s', $ability, $result->get_error_message() ),
					array( 'results' => $results )
				);
			}

			// Map temp id → real id when this op created an entity.
			if ( ! empty( $op['temp_id'] ) ) {
				$real_id = self::extract_real_id( $result );
				if ( null !== $real_id ) {
					$temp_to_real[ $op['temp_id'] ] = $real_id;
				}
			}
			$results[] = array(
				'op'       => $op,
				'result'   => $result,
				'real_id'  => $temp_to_real[ $op['temp_id'] ?? '' ] ?? null,
			);
		}

		$session['state']        = 'accepted';
		$session['accepted_at']  = time();
		$session['temp_to_real'] = $temp_to_real;
		update_option( self::SESSION_PREFIX . $session_id, $session, false );
		self::bump_revision();

		/**
		 * Fires after a sandbox session has been successfully replayed
		 * against the host site. Useful for cache invalidation, audit
		 * logs, or follow-up cleanup.
		 *
		 * @since 0.3.0
		 *
		 * @param string $session_id   The accepted session id.
		 * @param array  $temp_to_real Map of temp ids to real ids assigned during replay.
		 * @param array  $results      Per-op results from the replay.
		 */
		do_action( 'studio_agent_sandbox_accepted', $session_id, $temp_to_real, $results );

		return array(
			'accepted' => true,
			'session_id' => $session_id,
			'mapping'  => $temp_to_real,
			'results'  => $results,
		);
	}

	/* ---------- helpers ---------- */

	/**
	 * Recursively scan args for tempId placeholders and substitute the
	 * real id once we know it. Convention: any string value matching
	 * `^tmp_<kind>_\d+$` is treated as a tempId reference.
	 */
	private static function resolve_temp_ids( array $args, array $map ): array {
		array_walk_recursive( $args, function ( &$v ) use ( $map ) {
			if ( is_string( $v ) && preg_match( '/^tmp_[a-z]+_\d+$/', $v ) ) {
				if ( isset( $map[ $v ] ) ) {
					$v = $map[ $v ];
				}
			}
		} );
		return $args;
	}

	/**
	 * Pull a real id out of an ability's result. Most of our abilities
	 * return arrays with `id` (post id) or `option_name`; widen as needed.
	 */
	private static function extract_real_id( $result ) {
		if ( ! is_array( $result ) ) {
			return null;
		}
		foreach ( array( 'id', 'post_id', 'theme_slug', 'option_name' ) as $k ) {
			if ( isset( $result[ $k ] ) ) {
				return $result[ $k ];
			}
		}
		return null;
	}
}
