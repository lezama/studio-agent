<?php
/**
 * Admin page: Studio Agent chat.
 *
 * Renders the studio-agent/chat block inside a wp-admin page so we get the
 * same React + AgentUI surface that the block exposes when dropped into a
 * post or page. The block itself owns the view assets via block.json's
 * viewScript / viewStyle declarations; this class just makes sure those
 * assets are enqueued on our admin screen (block-template enqueueing only
 * fires for front-end render contexts by default).
 *
 * @package StudioAgent
 */

defined( 'ABSPATH' ) || exit;

class Studio_Agent_Admin {

	private const PAGE_SLUG = 'studio-agent';
	private const PAGE_HOOK = 'toplevel_page_studio-agent';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_block_assets' ) );
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Studio Agent', 'studio-agent' ),
			__( 'Studio Agent', 'studio-agent' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-format-chat',
			30
		);
	}

	/**
	 * Enqueue the studio-agent/chat block's view assets on our admin page.
	 *
	 * register_block_type wires viewScript/viewStyle for front-end render
	 * contexts but not for arbitrary admin screens that emit the block via
	 * do_blocks(). We mirror what front-end enqueueing does manually.
	 */
	public static function enqueue_block_assets( string $hook ): void {
		if ( self::PAGE_HOOK !== $hook ) {
			return;
		}

		$block_dir  = STUDIO_AGENT_PATH . 'blocks/chat';
		$build_dir  = $block_dir . '/build';
		$build_url  = plugins_url( 'blocks/chat/build/', STUDIO_AGENT_PATH . 'studio-agent.php' );
		$asset_file = $build_dir . '/view.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			// The block hasn't been built yet. Surface a clear message — easier
			// to debug than a silent broken page.
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e(
						'Studio Agent: the chat block bundle is missing. Run "npm install && npm run build" inside the studio-agent plugin directory.',
						'studio-agent'
					);
					echo '</p></div>';
				}
			);
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'studio-agent-chat-view',
			$build_url . 'view.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? STUDIO_AGENT_VERSION,
			true
		);

		if ( file_exists( $build_dir . '/view.css' ) ) {
			wp_enqueue_style(
				'studio-agent-chat-view',
				$build_url . 'view.css',
				array(),
				$asset['version'] ?? STUDIO_AGENT_VERSION
			);
		}
	}

	public static function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Studio Agent', 'studio-agent' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Chat with the Studio agent. Uses the WordPress.com AI proxy via wp-ai-client; no API key required.', 'studio-agent' ); ?>
			</p>
			<?php
			// Render the block via do_blocks so the same render.php that
			// powers the front-end block also drives the admin surface.
			echo do_blocks( '<!-- wp:studio-agent/chat /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks output is safe.
			?>
		</div>
		<?php
	}
}
