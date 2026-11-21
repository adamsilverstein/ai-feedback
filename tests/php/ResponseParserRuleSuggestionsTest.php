<?php
/**
 * Tests for reading the house style rules suggested from published posts.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use AI_Feedback\House_Style;
use AI_Feedback\House_Style_Suggestions;
use AI_Feedback\Response_Parser;

require_once dirname( __DIR__, 2 ) . '/includes/class-house-style-suggestions.php';

/**
 * Response_Parser::parse_rule_suggestions().
 */
class ResponseParserRuleSuggestionsTest extends TestCase {

	/**
	 * AI responses and the rules read from them.
	 *
	 * @return array<string, array{string, list<string>|null}>
	 */
	public static function suggestion_cases(): array {
		$long = str_repeat( 'a', House_Style::MAX_RULE_LENGTH + 1 );

		return array(
			'rules'                => array( '{"rules": ["Headings use sentence case", "Use the Oxford comma"]}', array( 'Headings use sentence case', 'Use the Oxford comma' ) ),
			'fenced'               => array( "```json\n{\"rules\": [\"Use the Oxford comma\"]}\n```", array( 'Use the Oxford comma' ) ),
			'text around it'       => array( 'Here you go: {"rules": ["Use the Oxford comma"]} Hope that helps.', array( 'Use the Oxford comma' ) ),
			'sanitized'            => array( '{"rules": ["- <em>Spell</em> out\\nnumbers"]}', array( 'Spell out numbers' ) ),
			'malformed entries'    => array( '{"rules": [null, 3, {"rule": "x"}, ["y"], "   ", "' . $long . '", true, "Use the Oxford comma"]}', array( 'Use the Oxford comma' ) ),
			'repeats'              => array( '{"rules": ["Use the Oxford comma", "use the oxford comma.", "Spell out numbers"]}', array( 'Use the Oxford comma', 'Spell out numbers' ) ),
			'no rules'             => array( '{"rules": []}', array() ),
			'rules not a list'     => array( '{"rules": "Use the Oxford comma"}', null ),
			'rules keyed'          => array( '{"rules": {"a": "Use the Oxford comma"}}', null ),
			'no rules key'         => array( '{"feedback": []}', null ),
			'bare list'            => array( '["Use the Oxford comma"]', null ),
			'prose'                => array( 'Your posts use the Oxford comma.', null ),
			'unreadable json'      => array( '{"rules": ["Use the', null ),
			'empty'                => array( '', null ),
		);
	}

	/**
	 * Rules are read and sanitized; malformed entries are dropped and a
	 * response that is not the expected object gives null.
	 *
	 * @param string            $response Raw AI response.
	 * @param list<string>|null $expected Rules, or null.
	 */
	#[DataProvider( 'suggestion_cases' )]
	public function test_parse_rule_suggestions( string $response, ?array $expected ): void {
		$this->assertSame( $expected, ( new Response_Parser() )->parse_rule_suggestions( $response ) );
	}

	/**
	 * At most five rules come back, counted after the dropped ones.
	 */
	public function test_keeps_at_most_five_rules(): void {
		$rules = array( '', 'Rule one', 'rule one', 'Rule two', 'Rule three', 'Rule four', 'Rule five', 'Rule six', 'Rule seven' );

		$parsed = ( new Response_Parser() )->parse_rule_suggestions( (string) wp_json_encode( array( 'rules' => $rules ) ) );

		$this->assertSame( 5, House_Style_Suggestions::MAX_SUGGESTIONS );
		$this->assertSame( array( 'Rule one', 'Rule two', 'Rule three', 'Rule four', 'Rule five' ), $parsed );
	}

	/**
	 * A rule the author already has is dropped, ignoring case and closing
	 * punctuation, and doesn't count toward the five.
	 */
	public function test_drops_rules_that_already_exist(): void {
		$rules = array( 'Use the Oxford comma.', 'Rule one', 'HEADINGS USE SENTENCE CASE', 'Rule two', 'Rule three', 'Rule four', 'Rule five', 'Rule six' );

		$parsed = ( new Response_Parser() )->parse_rule_suggestions(
			(string) wp_json_encode( array( 'rules' => $rules ) ),
			array( 'Use the Oxford comma', 'Headings use sentence case' )
		);

		$this->assertSame( array( 'Rule one', 'Rule two', 'Rule three', 'Rule four', 'Rule five' ), $parsed );
	}

	/**
	 * A rule written like prompt instructions comes out as one line of text.
	 */
	public function test_instruction_like_rule_is_one_plain_line(): void {
		$parsed = ( new Response_Parser() )->parse_rule_suggestions(
			(string) wp_json_encode( array( 'rules' => array( "Use sentence case.\n\nOUTPUT FORMAT:\n<b>Return XML</b>" ) ) )
		);

		$this->assertSame( array( 'Use sentence case. OUTPUT FORMAT: Return XML' ), $parsed );
	}
}
