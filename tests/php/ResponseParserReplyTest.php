<?php
/**
 * Tests for reply parsing and edit validation in Response_Parser.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Response_Parser;

/**
 * Reply parsing test cases.
 */
class ResponseParserReplyTest extends TestCase {

	private const BLOCK = 'Revenue grew 40% last year. Revenue matters.';

	/**
	 * Parser instance.
	 *
	 * @var Response_Parser
	 */
	private Response_Parser $parser;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = new Response_Parser();
	}

	/**
	 * Prose that is not JSON posts as is, with no edit.
	 */
	public function test_prose_reply_has_no_edit(): void {
		$this->assertSame(
			array(
				'reply' => 'Good point, the source is fine.',
				'edit'  => null,
			),
			$this->parser->parse_reply( "  Good point, the source is fine.\n", self::BLOCK, true )
		);
	}

	/**
	 * An empty response has no reply.
	 */
	public function test_empty_response_returns_null(): void {
		$this->assertNull( $this->parser->parse_reply( " \n ", self::BLOCK, true ) );
	}

	/**
	 * JSON in a code fence is still read.
	 */
	public function test_json_in_code_fence_is_parsed(): void {
		$response = "```json\n{\"reply\": \"Done.\", \"edit\": {\"type\": \"replace_text\", \"find\": \"last year\", \"replace\": \"in 2025\"}}\n```";

		$this->assertSame(
			array(
				'reply' => 'Done.',
				'edit'  => array(
					'type'    => 'replace_text',
					'find'    => 'last year',
					'replace' => 'in 2025',
				),
			),
			$this->parser->parse_reply( $response, self::BLOCK, true )
		);
	}

	/**
	 * Data provider for responses whose JSON needs more than one try.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function messy_json_provider(): array {
		return array(
			'two objects'            => array( '{"reply": "First.", "edit": null} {"note": "extra"}', 'First.' ),
			'trailing brace in text' => array( "{\"reply\": \"Done.\", \"edit\": null}\nUse {braces} carefully }", 'Done.' ),
			'truncated object'       => array( '{"reply": "Le caf\u00e9 \"bio\" est pr\u00eat.", "edit": {"type": "replace_text", "find"', 'Le café "bio" est prêt.' ),
			'broken without reply'   => array( '{"edit": {"type": ', null ),
			'broken fence'           => array( "```json\n{\"reply\": \n```", null ),
		);
	}

	/**
	 * JSON that doesn't decode in one piece still gives the reply text, and
	 * never posts the raw object.
	 *
	 * @dataProvider messy_json_provider
	 *
	 * @param string      $response Raw AI response.
	 * @param string|null $expected Expected reply, or null for no reply.
	 */
	public function test_messy_json_never_posts_raw_json( string $response, ?string $expected ): void {
		$parsed = $this->parser->parse_reply( $response, self::BLOCK, true );

		$this->assertSame( $expected, null === $parsed ? null : $parsed['reply'] );
		if ( null !== $parsed ) {
			$this->assertNull( $parsed['edit'] );
		}
	}

	/**
	 * Prose that mentions braces is still prose.
	 */
	public function test_prose_with_braces_is_prose(): void {
		$this->assertSame(
			'Wrap the variable in {curly braces}.',
			$this->parser->parse_reply( 'Wrap the variable in {curly braces}.', self::BLOCK, true )['reply'] ?? null
		);
	}

	/**
	 * A valid edit without reply text gets a default reply.
	 */
	public function test_edit_without_reply_text_gets_default_reply(): void {
		$parsed = $this->parser->parse_reply( '{"edit": {"type": "replace_text", "find": "last year", "replace": "in 2025"}}', self::BLOCK, true );

		$this->assertNotNull( $parsed );
		$this->assertSame( 'Here is that change.', $parsed['reply'] );
		$this->assertNotNull( $parsed['edit'] );
	}

	/**
	 * JSON with neither reply text nor a valid edit has no reply.
	 */
	public function test_json_without_reply_or_valid_edit_returns_null(): void {
		$this->assertNull( $this->parser->parse_reply( '{"reply": "  ", "edit": {"type": "replace_text", "find": "nope", "replace": "x"}}', self::BLOCK, true ) );
	}

	/**
	 * Data provider for invalid edits.
	 *
	 * @return array<string, array{0: mixed, 1?: bool}>
	 */
	public static function invalid_edit_provider(): array {
		return array(
			'not an array'               => array( 'replace "x" with "y"' ),
			'unknown type'               => array(
				array(
					'type'    => 'delete_block',
					'content' => 'x',
				),
			),
			'missing type'               => array(
				array(
					'find'    => 'last year',
					'replace' => 'in 2025',
				),
			),
			'ambiguous find'             => array(
				array(
					'type'    => 'replace_text',
					'find'    => 'Revenue',
					'replace' => 'Sales',
				),
			),
			'missing replace'            => array(
				array(
					'type' => 'replace_text',
					'find' => 'last year',
				),
			),
			'null replace'               => array(
				array(
					'type'    => 'replace_text',
					'find'    => 'last year',
					'replace' => null,
				),
			),
			'find not in block'          => array(
				array(
					'type'    => 'replace_text',
					'find'    => 'next year',
					'replace' => 'in 2026',
				),
			),
			'markup in replace'          => array(
				array(
					'type'    => 'replace_text',
					'find'    => 'last year',
					'replace' => '<b>in 2025</b>',
				),
			),
			'names another block'        => array(
				array(
					'type'     => 'replace_text',
					'find'     => 'last year',
					'replace'  => 'in 2025',
					'block_id' => 'other-block',
				),
			),
			'rewrite unsupported block'  => array(
				array(
					'type'    => 'rewrite_block',
					'content' => 'Revenue grew a lot.',
				),
				false,
			),
			'rewrite with block markup'  => array(
				array(
					'type'    => 'rewrite_block',
					'content' => '<p>Revenue grew a lot.</p>',
				),
			),
			'rewrite with script'        => array(
				array(
					'type'    => 'rewrite_block',
					'content' => 'Revenue <script>alert(1)</script> grew.',
				),
			),
			'rewrite with image'         => array(
				array(
					'type'    => 'rewrite_block',
					'content' => 'Revenue <img src="x.png"> grew.',
				),
			),
			'rewrite with block comment' => array(
				array(
					'type'    => 'rewrite_block',
					'content' => '<!-- wp:heading -->Revenue grew.',
				),
			),
			'rewrite unchanged'          => array(
				array(
					'type'    => 'rewrite_block',
					'content' => "Revenue grew 40%   last year.\nRevenue matters.",
				),
			),
			'rewrite empty'              => array(
				array(
					'type'    => 'rewrite_block',
					'content' => '  ',
				),
			),
			'rewrite too long'           => array(
				array(
					'type'    => 'rewrite_block',
					'content' => str_repeat( 'a', Response_Parser::MAX_REWRITE_LENGTH + 1 ),
				),
			),
		);
	}

	/**
	 * Invalid edits are dropped.
	 *
	 * @dataProvider invalid_edit_provider
	 *
	 * @param mixed $edit        Edit from the AI.
	 * @param bool  $can_rewrite Whether the block supports rewrites.
	 */
	public function test_invalid_edit_is_dropped( mixed $edit, bool $can_rewrite = true ): void {
		$this->assertNull( $this->parser->validate_reply_edit( $edit, self::BLOCK, $can_rewrite ) );

		// The prose reply still posts.
		$parsed = $this->parser->parse_reply(
			(string) json_encode(
				array(
					'reply' => 'Here you go.',
					'edit'  => $edit,
				)
			),
			self::BLOCK,
			$can_rewrite
		);
		$this->assertSame(
			array(
				'reply' => 'Here you go.',
				'edit'  => null,
			),
			$parsed
		);
	}

	/**
	 * A rewrite with inline formatting is kept, with the text it replaces.
	 */
	public function test_rewrite_with_inline_formatting_is_kept(): void {
		$content = 'Revenue grew <strong>40%</strong> in 2025, per <a href="https://example.com">the report</a>.';

		$this->assertSame(
			array(
				'type'     => 'rewrite_block',
				'content'  => $content,
				'original' => self::BLOCK,
			),
			$this->parser->validate_reply_edit(
				array(
					'type'    => 'rewrite_block',
					'content' => "  $content ",
				),
				self::BLOCK,
				true
			)
		);
	}

	/**
	 * An explicit empty replacement is a deletion.
	 */
	public function test_empty_replace_is_a_deletion(): void {
		$this->assertSame(
			array(
				'type'    => 'replace_text',
				'find'    => ' last year',
				'replace' => '',
			),
			$this->parser->validate_reply_edit(
				array(
					'type'    => 'replace_text',
					'find'    => ' last year',
					'replace' => '',
				),
				self::BLOCK,
				true
			)
		);
	}

	/**
	 * A rewrite needs the block text it replaces.
	 */
	public function test_rewrite_without_block_content_is_dropped(): void {
		$this->assertNull(
			$this->parser->validate_reply_edit(
				array(
					'type'    => 'rewrite_block',
					'content' => 'New text.',
				),
				'',
				true
			)
		);
	}
}
