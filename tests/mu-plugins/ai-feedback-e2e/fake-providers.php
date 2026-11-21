<?php
/**
 * Fake providers for ai-feedback-e2e-providers.php. Each model answers
 * with a review that names the provider and model in its summary.
 *
 * @package AI_Feedback
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Files.FileName, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * A provider's model list.
 */
class AI_Feedback_E2E_Directory implements ModelMetadataDirectoryInterface {

	/**
	 * Constructor.
	 *
	 * @param string[] $ids Model IDs.
	 */
	public function __construct( private array $ids ) {}

	/**
	 * Metadata for a text model.
	 *
	 * @param string $id Model ID.
	 * @return ModelMetadata
	 */
	private static function metadata( string $id ): ModelMetadata {
		return new ModelMetadata(
			$id,
			$id,
			array( CapabilityEnum::textGeneration() ),
			array(
				new SupportedOption( OptionEnum::systemInstruction() ),
				new SupportedOption( OptionEnum::temperature() ),
				new SupportedOption( OptionEnum::maxTokens() ),
				new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
				new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function listModelMetadata(): array {
		return array_map( array( self::class, 'metadata' ), $this->ids );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $modelId Model ID.
	 */
	public function hasModelMetadata( string $modelId ): bool {
		return in_array( $modelId, $this->ids, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $modelId Model ID.
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException For a model it doesn't list, as core does.
	 */
	public function getModelMetadata( string $modelId ): ModelMetadata {
		if ( ! $this->hasModelMetadata( $modelId ) ) {
			throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException( 'No model with ID ' . esc_html( $modelId ) . ' was found in the provider' );
		}
		return self::metadata( $modelId );
	}
}

/**
 * A model that answers with a one-note review.
 */
class AI_Feedback_E2E_Model implements ModelInterface, TextGenerationModelInterface {

	/**
	 * Model config.
	 *
	 * @var ModelConfig
	 */
	private ModelConfig $config;

	/**
	 * Constructor.
	 *
	 * @param ModelMetadata    $model    Model metadata.
	 * @param ProviderMetadata $provider Provider metadata.
	 */
	public function __construct( private ModelMetadata $model, private ProviderMetadata $provider ) {
		$this->config = new ModelConfig();
	}

	/**
	 * {@inheritDoc}
	 */
	public function metadata(): ModelMetadata {
		return $this->model;
	}

	/**
	 * {@inheritDoc}
	 */
	public function providerMetadata(): ProviderMetadata {
		return $this->provider;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ModelConfig $config Config.
	 */
	public function setConfig( ModelConfig $config ): void {
		$this->config = $config;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getConfig(): ModelConfig {
		return $this->config;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<mixed> $prompt Prompt messages.
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$text = wp_json_encode(
			array(
				'summary'  => 'Answered by ' . $this->provider->getId() . '/' . $this->model->getId() . '.',
				'feedback' => array(),
			)
		);
		return new GenerativeAiResult(
			'e2e-' . wp_generate_uuid4(),
			array( new Candidate( new ModelMessage( array( new MessagePart( (string) $text ) ) ), FinishReasonEnum::stop() ) ),
			new TokenUsage( 1, 1, 2 ),
			$this->provider,
			$this->model
		);
	}
}

/**
 * Credentials are always there.
 */
class AI_Feedback_E2E_Availability implements ProviderAvailabilityInterface {

	/**
	 * {@inheritDoc}
	 */
	public function isConfigured(): bool {
		return true;
	}
}

/**
 * Shared provider parts.
 */
abstract class AI_Feedback_E2E_Provider extends AbstractProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @param ModelMetadata    $modelMetadata    Model metadata.
	 * @param ProviderMetadata $providerMetadata Provider metadata.
	 */
	protected static function createModel( ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata ): ModelInterface {
		return new AI_Feedback_E2E_Model( $modelMetadata, $providerMetadata );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new AI_Feedback_E2E_Availability();
	}
}

/**
 * The provider the default model belongs to.
 */
class AI_Feedback_E2E_Anthropic_Provider extends AI_Feedback_E2E_Provider {

	/**
	 * {@inheritDoc}
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( 'anthropic', 'E2E Anthropic', ProviderTypeEnum::cloud() );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new AI_Feedback_E2E_Directory( 'listed' === AI_FEEDBACK_E2E_PROVIDERS ? array( 'claude-haiku-e2e', AI_FEEDBACK_DEFAULT_MODEL ) : array( 'claude-haiku-e2e' ) );
	}
}

/**
 * Another provider, which lists the default model.
 */
class AI_Feedback_E2E_Other_Provider extends AI_Feedback_E2E_Provider {

	/**
	 * {@inheritDoc}
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( 'e2eother', 'E2E Other', ProviderTypeEnum::cloud() );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new AI_Feedback_E2E_Directory( array( AI_FEEDBACK_DEFAULT_MODEL, 'other-model-1' ) );
	}
}
