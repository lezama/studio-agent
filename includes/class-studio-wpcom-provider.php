<?php
/**
 * Custom wp-ai-client provider for the Studio WordPress.com AI proxy.
 *
 * Registers a Provider with `AiClient::defaultRegistry()` so that
 * `wp_ai_client_prompt()` routes through `https://public-api.wordpress.com/wpcom/v2/ai-api-proxy`,
 * authenticated with the Studio user's wpcom access token. Mirrors the
 * configuration used by `studio code` (apps/cli/ai/providers.ts).
 *
 * @package StudioAgent
 */

namespace StudioAgent\WpAiClient;

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Bearer-token authentication. The wpcom AI proxy needs an extra
 * `X-WPCOM-AI-Feature` header alongside the bearer token; we attach it on
 * top of the standard ApiKey behaviour so the provider registry's strict
 * authentication-class check still passes.
 */
class Authentication extends ApiKeyRequestAuthentication {

	private string $feature_header;

	public function __construct( string $api_key, string $feature_header = 'studio-assistant-anthropic' ) {
		parent::__construct( $api_key );
		$this->feature_header = $feature_header;
	}

	public function authenticateRequest( Request $request ): Request {
		return parent::authenticateRequest( $request )
			->withHeader( 'X-WPCOM-AI-Feature', $this->feature_header );
	}
}

/**
 * Availability checker — provider is configured when the wpcom token option is set.
 */
class Availability implements ProviderAvailabilityInterface {

	public function isConfigured(): bool {
		return '' !== resolve_wpcom_token();
	}
}

/**
 * Single-model directory exposing the canonical Studio model.
 */
class Model_Directory implements ModelMetadataDirectoryInterface {

	private const MODEL_ID = 'claude-haiku-4-5-20251001';

	public function listModelMetadata(): array {
		return array( $this->build_metadata() );
	}

	public function hasModelMetadata( string $modelId ): bool {
		return $modelId === self::MODEL_ID;
	}

	public function getModelMetadata( string $modelId ): ModelMetadata {
		if ( $modelId !== self::MODEL_ID ) {
			throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException(
				sprintf( 'Unknown model: %s', $modelId )
			);
		}
		return $this->build_metadata();
	}

	private function build_metadata(): ModelMetadata {
		return new ModelMetadata(
			self::MODEL_ID,
			'Claude Haiku 4.5 (via WPCOM proxy)',
			array(
				CapabilityEnum::textGeneration(),
				CapabilityEnum::chatHistory(),
			),
			array(
				new SupportedOption( OptionEnum::inputModalities() ),
				new SupportedOption( OptionEnum::outputModalities() ),
				new SupportedOption( OptionEnum::systemInstruction() ),
				new SupportedOption( OptionEnum::maxTokens() ),
				new SupportedOption( OptionEnum::temperature() ),
				new SupportedOption( OptionEnum::topP() ),
				new SupportedOption( OptionEnum::stopSequences() ),
				new SupportedOption( OptionEnum::functionDeclarations() ),
			)
		);
	}
}

/**
 * Concrete text-generation model. Builds requests against the wpcom proxy's
 * OpenAI-compatible chat completions endpoint.
 */
class Text_Generation_Model extends AbstractOpenAiCompatibleTextGenerationModel {

	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request( $method, Provider::url( $path ), $headers, $data );
	}
}

/**
 * The provider class itself.
 */
class Provider extends AbstractApiProvider {

	public const ID = 'studio-wpcom';

	protected static function baseUrl(): string {
		return 'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1';
	}

	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			self::ID,
			'Studio (WordPress.com AI Proxy)',
			ProviderTypeEnum::cloud(),
			null,
			RequestAuthenticationMethod::apiKey(),
			'Routes Anthropic models through the Automattic-managed WordPress.com AI gateway, using the Studio user\'s wpcom auth token.',
			null
		);
	}

	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new Availability();
	}

	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new Model_Directory();
	}

	protected static function createModel( ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata ): \WordPress\AiClient\Providers\Models\Contracts\ModelInterface {
		return new Text_Generation_Model( $modelMetadata, $providerMetadata );
	}
}

/**
 * Register the provider on plugins_loaded so it's available before any
 * `wp_ai_client_prompt()` call. Also store the authentication instance on
 * the registry so wp-ai-client can authenticate requests.
 */
/**
 * Resolve the wpcom token from the most-secure source first.
 *
 *   1. PHP constant STUDIO_AGENT_WPCOM_TOKEN (defined in wp-config.php
 *      or env-loaded at boot — never hits the database).
 *   2. Environment variable STUDIO_AGENT_WPCOM_TOKEN (Studio app
 *      injects this when it boots the site).
 *   3. wp_option `studio_wpcom_token` (least secure; plaintext in DB).
 *
 * Production deploys should set the constant. The option fallback exists
 * so the bootstrap script can wire things up in one shell command.
 */
function resolve_wpcom_token(): string {
	if ( defined( 'STUDIO_AGENT_WPCOM_TOKEN' ) && is_string( STUDIO_AGENT_WPCOM_TOKEN ) && '' !== STUDIO_AGENT_WPCOM_TOKEN ) {
		return (string) STUDIO_AGENT_WPCOM_TOKEN;
	}
	$env = getenv( 'STUDIO_AGENT_WPCOM_TOKEN' );
	if ( is_string( $env ) && '' !== $env ) {
		return $env;
	}
	$opt = get_option( 'studio_wpcom_token', '' );
	return is_string( $opt ) ? trim( $opt ) : '';
}

function register_provider(): void {
	$token = resolve_wpcom_token();
	if ( '' === $token ) {
		return;
	}

	$registry = AiClient::defaultRegistry();
	$registry->registerProvider( Provider::class );
	$registry->setProviderRequestAuthentication(
		Provider::class,
		new Authentication( $token )
	);
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\register_provider', 20 );
