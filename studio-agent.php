<?php
/**
 * Plugin Name: Studio Agent
 * Description: Registers a Studio agent on agents-api and exposes a chat REST endpoint backed by the WordPress.com AI proxy.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Automattic
 * License: GPL-2.0-or-later
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

define( 'STUDIO_AGENT_VERSION', '0.1.0' );
define( 'STUDIO_AGENT_PATH', __DIR__ . '/' );

require_once STUDIO_AGENT_PATH . 'includes/class-studio-wpcom-provider.php';
require_once STUDIO_AGENT_PATH . 'includes/class-studio-agent-conversation.php';
require_once STUDIO_AGENT_PATH . 'includes/class-studio-agent-message-adapter.php';
require_once STUDIO_AGENT_PATH . 'includes/class-studio-agent-tools-resolver.php';
require_once STUDIO_AGENT_PATH . 'includes/class-studio-agent-tool-executor.php';
require_once STUDIO_AGENT_PATH . 'includes/class-studio-agent-rest.php';
require_once STUDIO_AGENT_PATH . 'includes/class-studio-agent-admin.php';
require_once STUDIO_AGENT_PATH . 'includes/class-studio-agent-theme-tools.php';
require_once STUDIO_AGENT_PATH . 'includes/class-studio-agent-theme-preview.php';
require_once STUDIO_AGENT_PATH . 'includes/class-studio-agent-skills.php';
require_once STUDIO_AGENT_PATH . 'includes/class-studio-agent-sandbox.php';
require_once STUDIO_AGENT_PATH . 'includes/class-studio-agent-snapshot.php';
require_once STUDIO_AGENT_PATH . 'includes/class-studio-agent-agenttic-bridge.php';
require_once STUDIO_AGENT_PATH . 'includes/register-agent.php';
require_once STUDIO_AGENT_PATH . 'includes/register-abilities.php';

add_action( 'rest_api_init', array( Studio_Agent_REST::class, 'register_routes' ) );
Studio_Agent_Admin::register();
Studio_Agent_Agenttic_Bridge::register();
add_action( 'init', static function () {
	register_block_type( STUDIO_AGENT_PATH . 'blocks/chat' );
} );
