<?php
/**
 * Stub file for WordPress 7.0 functions and classes that are not yet
 * available in php-stubs/wordpress-stubs (pinned to 6.9.x).
 *
 * Loaded only by PHPStan; never executed at runtime.
 *
 * TODO: Remove this file once php-stubs/wordpress-stubs ships v7.0.x and
 * Composer is bumped to require it; the upstream stubs will provide the
 * real signatures for wp_ai_client_prompt(), WP_AI_Client_Prompt_Builder,
 * wp_get_connectors(), wp_get_connector(), and wp_is_connector_registered().
 *
 * @package AI_Feedback
 */

// phpcs:disable

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * @param string $prompt Optional prompt text.
	 * @return WP_AI_Client_Prompt_Builder
	 */
	function wp_ai_client_prompt( string $prompt = '' ): WP_AI_Client_Prompt_Builder {
		return new WP_AI_Client_Prompt_Builder();
	}
}

if ( ! function_exists( 'wp_get_connectors' ) ) {
	/**
	 * @return array<string, array{name?: string, description?: string, type?: string, plugin?: array<string,mixed>}>
	 */
	function wp_get_connectors(): array {
		return array();
	}
}

if ( ! function_exists( 'wp_get_connector' ) ) {
	/**
	 * @param string $id Connector ID.
	 * @return array<string,mixed>|null
	 */
	function wp_get_connector( string $id ): ?array {
		return null;
	}
}

if ( ! function_exists( 'wp_is_connector_registered' ) ) {
	function wp_is_connector_registered( string $id ): bool {
		return false;
	}
}

if ( ! class_exists( 'WP_AI_Client_Prompt_Builder' ) ) {
	/**
	 * Fluent prompt builder backed by the WordPress 7.0 core AI Client.
	 */
	class WP_AI_Client_Prompt_Builder {
		public function with_text( string $text ): self {
			return $this;
		}
		public function using_system_instruction( string $instruction ): self {
			return $this;
		}
		public function using_temperature( float $temperature ): self {
			return $this;
		}
		public function using_max_tokens( int $max_tokens ): self {
			return $this;
		}
		public function using_top_p( float $top_p ): self {
			return $this;
		}
		public function using_top_k( int $top_k ): self {
			return $this;
		}
		/**
		 * @param string ...$models
		 */
		public function using_model_preference( string ...$models ): self {
			return $this;
		}
		/**
		 * @param array<string,mixed> $schema
		 */
		public function as_json_response( array $schema ): self {
			return $this;
		}
		/**
		 * @return string|WP_Error
		 */
		public function generate_text() {
			return '';
		}
		public function is_supported_for_text_generation(): bool {
			return true;
		}
	}
}
