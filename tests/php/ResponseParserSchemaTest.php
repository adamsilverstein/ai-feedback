<?php
/**
 * Tests for Response_Parser schema validation.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Response_Parser;
use WP_Error;

/**
 * Test cases for Response_Parser schema validation.
 */
class ResponseParserSchemaTest extends TestCase
{

    /**
     * Parser instance.
     *
     * @var Response_Parser
     */
    private Response_Parser $parser;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new Response_Parser();
    }

    /**
     * Test VALID_CATEGORIES constant is correctly defined.
     */
    public function test_valid_categories_constant(): void
    {
        $this->assertSame(
            array( 'content', 'tone', 'flow', 'design' ),
            Response_Parser::VALID_CATEGORIES
        );
    }

    /**
     * Test VALID_SEVERITIES constant is correctly defined.
     */
    public function test_valid_severities_constant(): void
    {
        $this->assertSame(
            array( 'suggestion', 'important', 'critical' ),
            Response_Parser::VALID_SEVERITIES
        );
    }

    /**
     * Test REQUIRED_FEEDBACK_FIELDS constant is correctly defined.
     */
    public function test_required_feedback_fields_constant(): void
    {
        $this->assertSame(
            array( 'block_id', 'category', 'severity', 'title', 'feedback' ),
            Response_Parser::REQUIRED_FEEDBACK_FIELDS
        );
    }

    /**
     * Test FIELD_MAX_LENGTHS constant is correctly defined.
     */
    public function test_field_max_lengths_constant(): void
    {
        $expected = array(
        'summary'    => 500,
        'title'      => 50,
        'feedback'   => 300,
        'suggestion' => 200,
        );
        $this->assertSame($expected, Response_Parser::FIELD_MAX_LENGTHS);
    }

    /**
     * Test valid response passes schema validation.
     */
    public function test_valid_response_passes_schema(): void
    {
        $data = array(
        'summary'  => 'Good document overall.',
        'feedback' => array(
        array(
        'block_id' => 'abc-123',
        'category' => 'content',
        'severity' => 'suggestion',
        'title'    => 'Consider rewording',
        'feedback' => 'This paragraph could be clearer.',
        ),
        ),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertTrue($result);
    }

    /**
     * Test empty feedback array is valid.
     */
    public function test_empty_feedback_array_is_valid(): void
    {
        $data = array(
        'summary'  => 'No issues found.',
        'feedback' => array(),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertTrue($result);
    }

    /**
     * Test feedback with optional suggestion field is valid.
     */
    public function test_feedback_with_suggestion_is_valid(): void
    {
        $data = array(
        'feedback' => array(
        array(
        'block_id'   => 'abc-123',
        'category'   => 'content',
        'severity'   => 'suggestion',
        'title'      => 'Consider rewording',
        'feedback'   => 'This paragraph could be clearer.',
        'suggestion' => 'Try using simpler words.',
        ),
        ),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertTrue($result);
    }

    /**
     * Test missing feedback field returns error.
     */
    public function test_missing_feedback_field(): void
    {
        $data = array( 'summary' => 'Test' );

        $result = $this->parser->validate_schema($data);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_field', $result->get_error_code());
    }

    /**
     * Test feedback not being array returns error.
     */
    public function test_feedback_not_array(): void
    {
        $data = array( 'feedback' => 'not an array' );

        $result = $this->parser->validate_schema($data);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertContains('invalid_type', $result->get_error_codes());
    }

    /**
     * Test missing required field in item returns error.
     */
    public function test_missing_required_field_in_item(): void
    {
        $data = array(
        'feedback' => array(
        array(
        'block_id' => 'abc-123',
        'category' => 'content',
        // Missing: severity, title, feedback.
        ),
        ),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertContains('missing_field', $result->get_error_codes());
    }

    /**
     * Test invalid category enum returns error.
     */
    public function test_invalid_category_enum(): void
    {
        $data = array(
        'feedback' => array(
        array(
        'block_id' => 'abc-123',
        'category' => 'invalid_category',
        'severity' => 'suggestion',
        'title'    => 'Test',
        'feedback' => 'Test feedback',
        ),
        ),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertContains('invalid_enum', $result->get_error_codes());
    }

    /**
     * Test invalid severity enum returns error.
     */
    public function test_invalid_severity_enum(): void
    {
        $data = array(
        'feedback' => array(
        array(
        'block_id' => 'abc-123',
        'category' => 'content',
        'severity' => 'invalid_severity',
        'title'    => 'Test',
        'feedback' => 'Test feedback',
        ),
        ),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertContains('invalid_enum', $result->get_error_codes());
    }

    /**
     * Test wrong type for string field returns error.
     */
    public function test_wrong_type_for_string_field(): void
    {
        $data = array(
        'feedback' => array(
        array(
        'block_id' => 123, // Should be string.
        'category' => 'content',
        'severity' => 'suggestion',
        'title'    => 'Test',
        'feedback' => 'Test feedback',
        ),
        ),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertContains('invalid_type', $result->get_error_codes());
    }

    /**
     * Test non-array root returns error.
     */
    public function test_non_array_root(): void
    {
        $result = $this->parser->validate_schema('not an array');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_type', $result->get_error_code());
    }

    /**
     * Test non-array item in feedback returns error.
     */
    public function test_non_array_item_in_feedback(): void
    {
        $data = array(
        'feedback' => array(
        'not an array',
        ),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertContains('invalid_type', $result->get_error_codes());
    }

    /**
     * Test summary not string returns error.
     */
    public function test_summary_not_string(): void
    {
        $data = array(
        'summary'  => array( 'not a string' ),
        'feedback' => array(),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertContains('invalid_type', $result->get_error_codes());
    }

    /**
     * Test suggestion not string returns error.
     */
    public function test_suggestion_not_string(): void
    {
        $data = array(
        'feedback' => array(
        array(
        'block_id'   => 'abc-123',
        'category'   => 'content',
        'severity'   => 'suggestion',
        'title'      => 'Test',
        'feedback'   => 'Test feedback',
        'suggestion' => 123, // Should be string.
        ),
        ),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertContains('invalid_type', $result->get_error_codes());
    }

    /**
     * Test multiple errors are collected.
     */
    public function test_multiple_errors_collected(): void
    {
        $data = array(
        'feedback' => array(
        array(
        // Missing all required fields.
        ),
        ),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertInstanceOf(WP_Error::class, $result);
        // Should have multiple missing_field errors.
        $messages = $result->get_error_messages('missing_field');
        $this->assertGreaterThan(1, count($messages));
    }

    /**
     * Test error data contains path.
     */
    public function test_error_data_contains_path(): void
    {
        $data = array(
        'feedback' => array(
        array(
        'block_id' => 'abc-123',
        'category' => 'invalid',
        'severity' => 'suggestion',
        'title'    => 'Test',
        'feedback' => 'Test',
        ),
        ),
        );

        $result     = $this->parser->validate_schema($data);
        $error_data = $result->get_error_data('invalid_enum');
        $this->assertIsArray($error_data);
        $this->assertArrayHasKey('path', $error_data);
        $this->assertStringContainsString('category', $error_data['path']);
    }

    /**
     * Test parse_feedback with schema validation on valid response.
     */
    public function test_parse_feedback_with_schema_validation(): void
    {
        $response = '{"summary": "Good", "feedback": []}';
        $blocks   = array();

        $result = $this->parser->parse_feedback($response, $blocks, true);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('feedback', $result);
    }

    /**
     * Test parse_feedback returns error on invalid schema.
     */
    public function test_parse_feedback_returns_error_on_invalid_schema(): void
    {
        $response = '{"invalid": "response"}';
        $blocks   = array();

        $result = $this->parser->parse_feedback($response, $blocks, true);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    /**
     * Test parse_feedback returns error on invalid JSON.
     */
    public function test_parse_feedback_returns_error_on_invalid_json(): void
    {
        $response = 'not json at all';
        $blocks   = array();

        $result = $this->parser->parse_feedback($response, $blocks, true);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    /**
     * Test parse_feedback without validation returns empty array on error.
     */
    public function test_parse_feedback_without_validation_returns_empty(): void
    {
        $response = 'not json at all';
        $blocks   = array();

        $result = $this->parser->parse_feedback($response, $blocks, false);
        $this->assertIsArray($result);
        $this->assertSame(array(), $result['feedback']);
    }

    /**
     * Test format_validation_errors produces readable output.
     */
    public function test_format_validation_errors(): void
    {
        $data   = array( 'feedback' => 'not array' );
        $errors = $this->parser->validate_schema($data);

        $formatted = $this->parser->format_validation_errors($errors);
        $this->assertStringContainsString('invalid_type', $formatted);
        $this->assertStringContainsString('$.feedback', $formatted);
    }

    /**
     * Test all category values are accepted.
     */
    public function test_all_valid_categories_accepted(): void
    {
        foreach ( Response_Parser::VALID_CATEGORIES as $category ) {
            $data = array(
            'feedback' => array(
            array(
            'block_id' => 'abc-123',
            'category' => $category,
            'severity' => 'suggestion',
            'title'    => 'Test',
            'feedback' => 'Test feedback',
            ),
            ),
            );

            $result = $this->parser->validate_schema($data);
            $this->assertTrue($result, "Category '$category' should be valid");
        }
    }

    /**
     * Test all severity values are accepted.
     */
    public function test_all_valid_severities_accepted(): void
    {
        foreach ( Response_Parser::VALID_SEVERITIES as $severity ) {
            $data = array(
            'feedback' => array(
            array(
            'block_id' => 'abc-123',
            'category' => 'content',
            'severity' => $severity,
            'title'    => 'Test',
            'feedback' => 'Test feedback',
            ),
            ),
            );

            $result = $this->parser->validate_schema($data);
            $this->assertTrue($result, "Severity '$severity' should be valid");
        }
    }

    /**
     * Test multiple items in feedback array.
     */
    public function test_multiple_feedback_items(): void
    {
        $data = array(
        'feedback' => array(
        array(
        'block_id' => 'block-1',
        'category' => 'content',
        'severity' => 'suggestion',
        'title'    => 'First issue',
        'feedback' => 'First feedback',
        ),
        array(
                    'block_id' => 'block-2',
                    'category' => 'tone',
                    'severity' => 'important',
                    'title'    => 'Second issue',
                    'feedback' => 'Second feedback',
        ),
        ),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertTrue($result);
    }

    /**
     * Test errors from multiple items are collected.
     */
    public function test_errors_from_multiple_items_collected(): void
    {
        $data = array(
        'feedback' => array(
        array(
        'block_id' => 'block-1',
        'category' => 'invalid1',
        'severity' => 'suggestion',
        'title'    => 'Test',
        'feedback' => 'Test',
        ),
        array(
                    'block_id' => 'block-2',
                    'category' => 'invalid2',
                    'severity' => 'suggestion',
                    'title'    => 'Test',
                    'feedback' => 'Test',
        ),
        ),
        );

        $result = $this->parser->validate_schema($data);
        $this->assertInstanceOf(WP_Error::class, $result);
        $messages = $result->get_error_messages('invalid_enum');
        $this->assertGreaterThanOrEqual(2, count($messages));
    }
}
