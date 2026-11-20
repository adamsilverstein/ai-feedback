<?php
/**
 * Text with backslashes, for checking it survives core's unslashing writes.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

/**
 * Data provider of text an AI might return that holds backslashes.
 */
trait Backslash_Text_Provider {

	/**
	 * Text with backslashes, quotes and accented characters.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function backslash_text_provider(): array {
		return array(
			'windows path' => array( 'Save it to C:\\path\\new instead.' ),
			'literal \\n'  => array( 'End each line with \\n, not a real line break.' ),
			'regex'        => array( 'Match versions with \\d+\\.\\d+ - café "déjà vu".' ),
			'escaped JSON' => array( 'Send {"title":"Say \\"hi\\"","path":"a\\\\b"} as the body.' ),
		);
	}
}
