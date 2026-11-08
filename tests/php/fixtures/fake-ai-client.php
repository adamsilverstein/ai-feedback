<?php
/**
 * Fake WordPress 7.0 AI Client and filter API for ReviewServiceCallAiTest.
 *
 * @package AI_Feedback\Tests
 */

// phpcs:disable

/**
 * Records builder calls and returns or throws $GLOBALS['fake_ai_result'].
 */
class Fake_AI_Client_Prompt_Builder {
	public array $calls = array();
	public ?int $timeout_during_call = null;

	public function __call( string $name, array $args ): self {
		$this->calls[ $name ] = 'using_model_preference' === $name ? $args : $args[0];
		return $this;
	}

	public function generate_text() {
		$result = $GLOBALS['fake_ai_result'];
		if ( $result instanceof Throwable ) {
			throw $result;
		}
		return $result;
	}
}

function wp_ai_client_prompt( $prompt = null ) {
	$builder                    = new Fake_AI_Client_Prompt_Builder();
	$builder->calls['prompt']   = $prompt;
	// Core reads the timeout filter when the builder is created.
	$callbacks                    = $GLOBALS['fake_filters']['wp_ai_client_default_request_timeout'] ?? array();
	$builder->timeout_during_call = $callbacks ? (int) end( $callbacks )( 30.0 ) : null;
	$GLOBALS['fake_ai_builder'] = $builder;
	return $builder;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fake_filters'][ $hook ][ spl_object_id( $callback ) ] = $callback;
	return true;
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	unset( $GLOBALS['fake_filters'][ $hook ][ spl_object_id( $callback ) ] );
	return true;
}
