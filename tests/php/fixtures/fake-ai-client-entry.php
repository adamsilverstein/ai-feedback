<?php
/**
 * Core's AiClient entry point, handing out the fake registry from
 * fake-ai-client.php.
 *
 * @package AI_Feedback\Tests
 */

// phpcs:disable

namespace WordPress\AiClient;

class AiClient {
	public static function defaultRegistry(): object {
		return new \Fake_Core_Registry();
	}
}
