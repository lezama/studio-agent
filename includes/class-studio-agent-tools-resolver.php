<?php
/**
 * Resolve a list of registered Abilities into the shapes the agents-api
 * conversation loop and wp-ai-client expect.
 *
 * Produces:
 *
 *   - declarations              : array keyed by sanitized declared name; each
 *                                 entry matches WP_Agent_Tool_Declaration::validate()
 *                                 (executor=client, scope=run).
 *   - declarations_for_provider : list of wp-ai-client FunctionDeclaration DTOs to
 *                                 pass into using_function_declarations().
 *   - name_to_ability           : map sanitized declared name → fully namespaced
 *                                 ability slug, so the executor can dispatch.
 *
 * Provider APIs reject `/` in function names, so `studio/site-info` is declared
 * to the model as `studio__site-info` and resolved back here.
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

final class Studio_Agent_Tools_Resolver {

	/**
	 * @param list<string> $ability_names Fully namespaced ability slugs.
	 * @return array{
	 *     declarations: array<string, array{name:string,source:string,description:string,parameters:array,executor:string,scope:string}>,
	 *     declarations_for_provider: list<\WordPress\AiClient\Tools\DTO\FunctionDeclaration>,
	 *     name_to_ability: array<string, string>,
	 * }
	 */
	public static function for_abilities( array $ability_names ): array {
		$declarations    = array();
		$provider_decls  = array();
		$name_to_ability = array();

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array(
				'declarations'              => array(),
				'declarations_for_provider' => array(),
				'name_to_ability'           => array(),
			);
		}

		foreach ( $ability_names as $ability_name ) {
			$ability_name = (string) $ability_name;
			$ability      = wp_get_ability( $ability_name );
			if ( null === $ability ) {
				continue;
			}

			$declared_name = self::sanitize_name( $ability_name );
			$description   = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
			$input_schema  = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
			// Abilities with no input return [] (empty list); function-declaration `parameters`
			// must be a JSON object. Substitute the canonical empty-object schema so the
			// provider proxy doesn't reject the call with HTTP 400.
			$parameters    = is_array( $input_schema ) && ! empty( $input_schema )
				? $input_schema
				: array( 'type' => 'object', 'properties' => (object) array() );

			$declarations[ $declared_name ] = array(
				'name'        => $declared_name,
				'source'      => 'studio-agent',
				'description' => $description,
				'parameters'  => $parameters,
				'executor'    => 'client',
				'scope'       => 'run',
			);

			$provider_decls[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
				$declared_name,
				$description,
				$parameters
			);

			$name_to_ability[ $declared_name ] = $ability_name;
		}

		return array(
			'declarations'              => $declarations,
			'declarations_for_provider' => $provider_decls,
			'name_to_ability'           => $name_to_ability,
		);
	}

	/**
	 * Convert a `namespace/slug` ability name into a provider-safe function name.
	 */
	public static function sanitize_name( string $ability_name ): string {
		$sanitized = str_replace( '/', '__', $ability_name );
		$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', $sanitized );
		return is_string( $sanitized ) ? $sanitized : '';
	}
}
