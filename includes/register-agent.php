<?php
/**
 * Register the "studio" agent with agents-api.
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_agents_api_init',
	static function () {
		if ( ! function_exists( 'wp_register_agent' ) ) {
			return;
		}

		wp_register_agent(
			'studio',
			array(
				'label'       => __( 'Studio Agent', 'studio-agent' ),
				'description' => __( 'Helps developers run, build, and explore WordPress sites inside Studio.', 'studio-agent' ),
				'meta'        => array(
					'source_plugin'  => 'studio-agent/studio-agent.php',
					'source_type'    => 'bundled-agent',
					'source_package' => 'studio-agent',
					'source_version' => STUDIO_AGENT_VERSION,
				),
			)
		);
	}
);
