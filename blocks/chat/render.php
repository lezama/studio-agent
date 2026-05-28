<?php
/**
 * Server-side render for the studio-agent/chat block.
 *
 * Emits a single root div carrying the agent list + bridge config as
 * data attributes; `build/view.js` hydrates it into the React chat surface.
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_get_agents' ) ) {
	return;
}

$studio_agents        = wp_get_agents();
$studio_default_agent = isset( $attributes['defaultAgent'] ) ? (string) $attributes['defaultAgent'] : 'studio';
$studio_variant       = isset( $attributes['variant'] ) ? (string) $attributes['variant'] : 'full';
$studio_show_preview  = isset( $attributes['showPreview'] ) ? (bool) $attributes['showPreview'] : true;
$studio_show_slash    = isset( $attributes['showSlashMenu'] ) ? (bool) $attributes['showSlashMenu'] : true;
// Preset variants apply on top of the explicit toggles so author UI is simple.
if ( 'chat-only' === $studio_variant ) {
	$studio_show_preview = false;
} elseif ( 'preview-only' === $studio_variant ) {
	$studio_show_slash = false;
}
$studio_wrapper_attrs = function_exists( 'get_block_wrapper_attributes' )
	? get_block_wrapper_attributes( array( 'class' => 'studio-agent-chat studio-agent-chat--variant-' . $studio_variant ) )
	: 'class="studio-agent-chat"';

$studio_agent_payload = array();
foreach ( $studio_agents as $studio_agent_slug => $studio_agent_obj ) {
	$studio_agent_payload[] = array(
		'slug'  => (string) $studio_agent_slug,
		'label' => $studio_agent_obj instanceof WP_Agent
			? (string) $studio_agent_obj->get_label()
			: (string) $studio_agent_slug,
	);
}

$studio_config = array(
	'bridgeUrl'      => esc_url_raw( rest_url( 'studio-agent/v1/agenttic' ) ),
	'restUrl'        => esc_url_raw( rest_url( 'studio-agent/v1' ) ),
	'nonce'          => wp_create_nonce( 'wp_rest' ),
	'playgroundBase' => 'https://playground.wordpress.net/',
	'variant'        => $studio_variant,
	'showPreview'    => $studio_show_preview,
	'showSlashMenu'  => $studio_show_slash,
);
?>
<div <?php echo $studio_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes returns escaped string. ?>>
	<?php if ( empty( $studio_agent_payload ) ) : ?>
		<p class="studio-agent-empty-state">
			<?php esc_html_e( 'No agents are registered. A plugin needs to register one on the wp_agents_api_init hook.', 'studio-agent' ); ?>
		</p>
	<?php else : ?>
		<div
			id="studio-agent-chat-root"
			data-agents="<?php echo esc_attr( wp_json_encode( $studio_agent_payload ) ); ?>"
			data-default-agent="<?php echo esc_attr( $studio_default_agent ); ?>"
			data-config="<?php echo esc_attr( wp_json_encode( $studio_config ) ); ?>"
		>
			<noscript>
				<?php esc_html_e( 'JavaScript is required to chat with the Studio Agent.', 'studio-agent' ); ?>
			</noscript>
		</div>
	<?php endif; ?>
</div>
