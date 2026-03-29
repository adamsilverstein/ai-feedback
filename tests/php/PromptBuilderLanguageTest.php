<?php
/**
 * Tests for Prompt_Builder language support.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Prompt_Builder;

/**
 * Test cases for Prompt_Builder language support.
 */
class PromptBuilderLanguageTest extends TestCase
{

    /**
     * Prompt builder instance.
     *
     * @var Prompt_Builder
     */
    private Prompt_Builder $builder;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new Prompt_Builder();
    }

    /**
     * Test default locale (English) is used when no locale is provided.
     */
    public function test_default_locale_english(): void
    {
        $blocks = array(
            array(
                'clientId' => 'abc123',
                'name'     => 'core/paragraph',
                'content'  => 'Test content',
            ),
        );

        $prompt = $this->builder->build_review_prompt( $blocks );

        $this->assertStringContainsString( '## LANGUAGE', $prompt );
        $this->assertStringContainsString( 'Provide feedback in English', $prompt );
    }

    /**
     * Test Spanish locale includes Spanish language instruction.
     */
    public function test_spanish_locale(): void
    {
        $blocks = array(
            array(
                'clientId' => 'abc123',
                'name'     => 'core/paragraph',
                'content'  => 'Contenido de prueba',
            ),
        );

        $options = array( 'locale' => 'es_ES' );
        $prompt  = $this->builder->build_review_prompt( $blocks, $options );

        $this->assertStringContainsString( '## LANGUAGE', $prompt );
        $this->assertStringContainsString( 'Spanish', $prompt );
        $this->assertStringContainsString( 'Provide all feedback in Spanish', $prompt );
        $this->assertStringContainsString( 'RAE (Real Academia Española)', $prompt );
    }

    /**
     * Test French locale includes French language instruction.
     */
    public function test_french_locale(): void
    {
        $blocks  = array(
            array(
                'clientId' => 'abc123',
                'name'     => 'core/paragraph',
                'content'  => 'Contenu de test',
            ),
        );
        $options = array( 'locale' => 'fr_FR' );
        $prompt  = $this->builder->build_review_prompt( $blocks, $options );

        $this->assertStringContainsString( '## LANGUAGE', $prompt );
        $this->assertStringContainsString( 'French', $prompt );
        $this->assertStringContainsString( 'Provide all feedback in French', $prompt );
        $this->assertStringContainsString( 'Académie française', $prompt );
    }

    /**
     * Test German locale includes German language instruction.
     */
    public function test_german_locale(): void
    {
        $blocks  = array(
            array(
                'clientId' => 'abc123',
                'name'     => 'core/paragraph',
                'content'  => 'Testinhalt',
            ),
        );
        $options = array( 'locale' => 'de_DE' );
        $prompt  = $this->builder->build_review_prompt( $blocks, $options );

        $this->assertStringContainsString( '## LANGUAGE', $prompt );
        $this->assertStringContainsString( 'German', $prompt );
        $this->assertStringContainsString( 'Provide all feedback in German', $prompt );
        $this->assertStringContainsString( 'Duden', $prompt );
    }

    /**
     * Test Italian locale includes Italian language instruction.
     */
    public function test_italian_locale(): void
    {
        $blocks  = array(
            array(
                'clientId' => 'abc123',
                'name'     => 'core/paragraph',
                'content'  => 'Contenuto di prova',
            ),
        );
        $options = array( 'locale' => 'it_IT' );
        $prompt  = $this->builder->build_review_prompt( $blocks, $options );

        $this->assertStringContainsString( '## LANGUAGE', $prompt );
        $this->assertStringContainsString( 'Italian', $prompt );
        $this->assertStringContainsString( 'Provide all feedback in Italian', $prompt );
    }

    /**
     * Test Japanese locale includes Japanese language instruction.
     */
    public function test_japanese_locale(): void
    {
        $blocks  = array(
            array(
                'clientId' => 'abc123',
                'name'     => 'core/paragraph',
                'content'  => 'テストコンテンツ',
            ),
        );
        $options = array( 'locale' => 'ja' );
        $prompt  = $this->builder->build_review_prompt( $blocks, $options );

        $this->assertStringContainsString( '## LANGUAGE', $prompt );
        $this->assertStringContainsString( 'Japanese', $prompt );
        $this->assertStringContainsString( 'Provide all feedback in Japanese', $prompt );
        $this->assertStringContainsString( 'keigo (敬語)', $prompt );
    }

    /**
     * Test Simplified Chinese locale includes Chinese language instruction.
     */
    public function test_simplified_chinese_locale(): void
    {
        $blocks  = array(
            array(
                'clientId' => 'abc123',
                'name'     => 'core/paragraph',
                'content'  => '测试内容',
            ),
        );
        $options = array( 'locale' => 'zh_CN' );
        $prompt  = $this->builder->build_review_prompt( $blocks, $options );

        $this->assertStringContainsString( '## LANGUAGE', $prompt );
        $this->assertStringContainsString( 'Simplified Chinese', $prompt );
        $this->assertStringContainsString( 'Provide all feedback in Simplified Chinese', $prompt );
    }

    /**
     * Test unsupported locale falls back to English.
     */
    public function test_unsupported_locale_fallback(): void
    {
        $blocks  = array(
            array(
                'clientId' => 'abc123',
                'name'     => 'core/paragraph',
                'content'  => 'Test content',
            ),
        );
        $options = array( 'locale' => 'xx_XX' );
        $prompt  = $this->builder->build_review_prompt( $blocks, $options );

        $this->assertStringContainsString( '## LANGUAGE', $prompt );
        $this->assertStringContainsString( 'Provide feedback in English', $prompt );
    }

    /**
     * Test language instruction is positioned correctly in prompt.
     */
    public function test_language_instruction_position(): void
    {
        $blocks  = array(
            array(
                'clientId' => 'abc123',
                'name'     => 'core/paragraph',
                'content'  => 'Test content',
            ),
        );
        $options = array( 'locale' => 'es_ES' );
        $prompt  = $this->builder->build_review_prompt( $blocks, $options );

        // Verify language instruction appears after tone guidance and before instructions.
        $this->assertMatchesRegularExpression(
            '/TARGET TONE:.*## LANGUAGE.*INSTRUCTIONS:/s',
            $prompt
        );
    }

    /**
     * Test continuation reviews maintain language instruction.
     */
    public function test_continuation_review_with_locale(): void
    {
        $blocks            = array(
            array(
                'clientId' => 'abc123',
                'name'     => 'core/paragraph',
                'content'  => 'Contenido actualizado',
            ),
        );
        $options           = array( 'locale' => 'es_ES' );
        $existing_feedback = array(
            array(
                'block_id' => 'abc123',
                'category' => 'content',
                'severity' => 'suggestion',
                'content'  => array( 'raw' => 'Previous feedback' ),
            ),
        );

        $prompt = $this->builder->build_review_prompt( $blocks, $options, $existing_feedback );

        $this->assertStringContainsString( '## LANGUAGE', $prompt );
        $this->assertStringContainsString( 'Spanish', $prompt );
        $this->assertStringContainsString( 'CONTINUATION REVIEW INSTRUCTIONS', $prompt );
    }
}
