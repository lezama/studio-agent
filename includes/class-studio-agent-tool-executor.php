<?php
/**
 * Bridge between the agents-api conversation loop and the WP Abilities API.
 *
 * The loop's mediation path needs an implementation of `WP_Agent_Tool_Executor`
 * to actually run a tool call. We map every call to a registered ability via
 * `wp_get_ability()`, using the name map produced by Studio_Agent_Tools_Resolver.
 *
 * Studio-specific behavior: when a tool result contains a `preview_id`
 * (currently emitted by `studio/preview-theme`), we capture it on the executor
 * instance so the REST runner can surface pending previews to the UI without
 * walking the transcript or making a second round-trip.
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;

final class Studio_Agent_Tool_Executor implements WP_Agent_Tool_Executor {

	/** @var array<string, string> */
	private array $name_to_ability;

	/** @var list<array{name:string,args:array<string,mixed>}> */
	private array $tool_calls_made = array();

	/** @var list<array{preview_id:string,theme_slug:string,theme_name:string}> */
	private array $pending_previews = array();

	/**
	 * @param array<string, string> $name_to_ability Sanitized-name → fully namespaced ability slug.
	 */
	public function __construct( array $name_to_ability ) {
		$this->name_to_ability = $name_to_ability;
	}

	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition, $context );

		$declared_name = (string) ( $tool_call['tool_name'] ?? '' );
		$parameters    = isset( $tool_call['parameters'] ) && is_array( $tool_call['parameters'] ) ? $tool_call['parameters'] : array();

		$ability_name = $this->name_to_ability[ $declared_name ] ?? '';
		if ( '' === $ability_name || ! function_exists( 'wp_get_ability' ) ) {
			return array(
				'success'   => false,
				'tool_name' => $declared_name,
				'error'     => sprintf( 'Unknown tool "%s".', $declared_name ),
			);
		}

		$ability = wp_get_ability( $ability_name );
		if ( null === $ability ) {
			return array(
				'success'   => false,
				'tool_name' => $declared_name,
				'error'     => sprintf( 'Ability "%s" is not registered.', $ability_name ),
			);
		}

		$this->tool_calls_made[] = array(
			'name' => $ability_name,
			'args' => $parameters,
		);

		// Abilities without an input schema reject any non-null argument
		// (Abilities API requires a schema to validate input). When the model
		// passes no parameters AND the ability declares no schema, call
		// execute() with no args so the validator short-circuits.
		$has_schema = method_exists( $ability, 'get_input_schema' )
			&& is_array( $ability->get_input_schema() )
			&& ! empty( $ability->get_input_schema() );
		$result     = ( empty( $parameters ) && ! $has_schema )
			? $ability->execute()
			: $ability->execute( $parameters );
		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'tool_name' => $declared_name,
				'error'     => $result->get_error_message(),
			);
		}

		// Surface preview_id from preview-theme calls so the UI can render
		// the iframe without a follow-up REST request.
		if ( is_array( $result ) && ! empty( $result['preview_id'] ) ) {
			$this->pending_previews[] = array(
				'preview_id' => (string) $result['preview_id'],
				'theme_slug' => (string) ( $result['theme_slug'] ?? '' ),
				'theme_name' => (string) ( $result['theme_name'] ?? '' ),
			);
		}

		return array(
			'success'   => true,
			'tool_name' => $declared_name,
			'result'    => $result,
		);
	}

	/**
	 * @return list<array{name:string,args:array<string,mixed>}>
	 */
	public function get_tool_calls_made(): array {
		return $this->tool_calls_made;
	}

	/**
	 * @return list<array{preview_id:string,theme_slug:string,theme_name:string}>
	 */
	public function get_pending_previews(): array {
		return $this->pending_previews;
	}
}
