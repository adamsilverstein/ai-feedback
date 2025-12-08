# Testing Strategy

This document outlines the comprehensive testing approach for the AI Feedback plugin.

## Overview

The plugin maintains high test coverage across multiple testing levels:

| Test Type | Coverage Target | Purpose |
|-----------|-----------------|---------|
| PHP Unit Tests | 90% | Test PHP classes in isolation |
| JavaScript Unit Tests | 90% | Test React components and hooks |
| Integration Tests | 80% | Test WordPress integration |
| E2E Tests | Critical paths | Test complete user workflows |
| Visual Regression | UI components | Detect unintended visual changes |

## Test Directory Structure

```
tests/
├── php/
│   ├── unit/
│   │   ├── test-review-service.php
│   │   ├── test-reply-service.php
│   │   ├── test-prompt-builder.php
│   │   ├── test-notes-manager.php
│   │   └── test-response-parser.php
│   ├── integration/
│   │   ├── test-rest-api.php
│   │   ├── test-notes-integration.php
│   │   └── test-abilities-integration.php
│   └── bootstrap.php
├── js/
│   ├── components/
│   │   ├── AIFeedbackPanel.test.js
│   │   ├── ModelSelector.test.js
│   │   ├── ReviewButton.test.js
│   │   ├── SettingsPanel.test.js
│   │   └── ReviewHistory.test.js
│   ├── hooks/
│   │   ├── useReview.test.js
│   │   └── useSettings.test.js
│   ├── store/
│   │   ├── selectors.test.js
│   │   ├── actions.test.js
│   │   └── reducer.test.js
│   └── setup.js
├── e2e/
│   ├── review-flow.spec.js
│   ├── reply-flow.spec.js
│   ├── settings.spec.js
│   ├── notes-interaction.spec.js
│   └── playwright.config.js
└── visual/
    ├── sidebar.spec.js
    ├── notes.spec.js
    └── playwright.config.js
```

## PHP Unit Tests

### Setup

The plugin uses PHPUnit with WP_Mock for WordPress function mocking.

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run with coverage
composer test:coverage
```

### Configuration

`phpunit.xml`:

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/php/bootstrap.php"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
>
    <testsuites>
        <testsuite name="unit">
            <directory>tests/php/unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/php/integration</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">includes</directory>
        </include>
        <report>
            <html outputDirectory="coverage/php"/>
            <clover outputFile="coverage/php/clover.xml"/>
        </report>
    </coverage>
</phpunit>
```

### Example Tests

#### Review Service Tests

```php
<?php
namespace AI_Feedback\Tests\Unit;

use AI_Feedback\Review_Service;
use AI_Feedback\Prompt_Builder;
use AI_Feedback\Notes_Manager;
use WP_Mock;
use Mockery;

class Test_Review_Service extends \WP_Mock\Tools\TestCase {

    private Review_Service $service;
    private $prompt_builder;
    private $notes_manager;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        $this->prompt_builder = Mockery::mock( Prompt_Builder::class );
        $this->notes_manager  = Mockery::mock( Notes_Manager::class );
        $this->service        = new Review_Service(
            $this->prompt_builder,
            $this->notes_manager
        );
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_review_document_parses_blocks(): void {
        $post_content = '<!-- wp:paragraph --><p>Test content</p><!-- /wp:paragraph -->';

        WP_Mock::userFunction( 'get_post' )
            ->once()
            ->with( 123 )
            ->andReturn( (object) array(
                'ID'           => 123,
                'post_content' => $post_content,
            ) );

        WP_Mock::userFunction( 'parse_blocks' )
            ->once()
            ->with( $post_content )
            ->andReturn( array(
                array(
                    'blockName' => 'core/paragraph',
                    'innerHTML' => '<p>Test content</p>',
                ),
            ) );

        $this->prompt_builder
            ->shouldReceive( 'build_review_prompt' )
            ->once()
            ->andReturn( 'Built prompt' );

        // Mock AI client response
        $this->mock_ai_client_response( json_encode( array(
            array(
                'block_index' => 0,
                'category'    => 'content',
                'severity'    => 'suggestion',
                'title'       => 'Test feedback',
                'feedback'    => 'Consider improving this.',
            ),
        ) ) );

        $this->notes_manager
            ->shouldReceive( 'create_note' )
            ->once();

        $result = $this->service->review_document( 123 );

        $this->assertArrayHasKey( 'review_id', $result );
        $this->assertArrayHasKey( 'notes', $result );
        $this->assertCount( 1, $result['notes'] );
    }

    public function test_review_document_handles_empty_response(): void {
        $this->setup_mock_post( 123 );
        $this->mock_ai_client_response( '[]' );

        $result = $this->service->review_document( 123 );

        $this->assertEmpty( $result['notes'] );
        $this->assertEquals( 0, $result['summary']['total_notes'] );
    }

    public function test_review_document_handles_invalid_block_references(): void {
        $this->setup_mock_post( 123, 2 ); // 2 blocks
        $this->mock_ai_client_response( json_encode( array(
            array(
                'block_index' => 99, // Invalid index
                'category'    => 'content',
                'severity'    => 'suggestion',
                'title'       => 'Test',
                'feedback'    => 'Should be skipped',
            ),
        ) ) );

        $result = $this->service->review_document( 123 );

        $this->assertEmpty( $result['notes'] );
    }

    public function test_review_respects_focus_areas(): void {
        $this->setup_mock_post( 123 );

        $this->prompt_builder
            ->shouldReceive( 'build_review_prompt' )
            ->with(
                Mockery::any(),
                Mockery::on( function( $options ) {
                    return $options['focus_areas'] === array( 'content', 'tone' );
                } )
            )
            ->once()
            ->andReturn( 'Prompt with focus' );

        $this->mock_ai_client_response( '[]' );

        $this->service->review_document( 123, array(
            'focus_areas' => array( 'content', 'tone' ),
        ) );

        // Assertion is in the mock expectation
        $this->assertTrue( true );
    }

    private function setup_mock_post( int $post_id, int $block_count = 1 ): void {
        $blocks = array_fill( 0, $block_count, array(
            'blockName' => 'core/paragraph',
            'innerHTML' => '<p>Content</p>',
        ) );

        WP_Mock::userFunction( 'get_post' )
            ->andReturn( (object) array(
                'ID'           => $post_id,
                'post_content' => 'content',
            ) );

        WP_Mock::userFunction( 'parse_blocks' )
            ->andReturn( $blocks );

        $this->prompt_builder
            ->shouldReceive( 'build_review_prompt' )
            ->andReturn( 'prompt' );
    }

    private function mock_ai_client_response( string $response ): void {
        // Mock the AI client singleton/static method
        // Implementation depends on php-ai-client structure
    }
}
```

#### Prompt Builder Tests

```php
<?php
namespace AI_Feedback\Tests\Unit;

use AI_Feedback\Prompt_Builder;
use WP_Mock;

class Test_Prompt_Builder extends \WP_Mock\Tools\TestCase {

    private Prompt_Builder $builder;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->builder = new Prompt_Builder();
    }

    public function test_build_review_prompt_includes_all_blocks(): void {
        $blocks = array(
            array(
                'blockName' => 'core/heading',
                'innerHTML' => '<h1>Title</h1>',
            ),
            array(
                'blockName' => 'core/paragraph',
                'innerHTML' => '<p>Content here</p>',
            ),
        );

        $prompt = $this->builder->build_review_prompt( $blocks, array() );

        $this->assertStringContainsString( 'Title', $prompt );
        $this->assertStringContainsString( 'Content here', $prompt );
        $this->assertStringContainsString( '"index": 0', $prompt );
        $this->assertStringContainsString( '"index": 1', $prompt );
    }

    public function test_build_review_prompt_respects_focus_areas(): void {
        $blocks = array(
            array( 'blockName' => 'core/paragraph', 'innerHTML' => '<p>Test</p>' ),
        );

        $prompt = $this->builder->build_review_prompt( $blocks, array(
            'focus_areas' => array( 'tone', 'flow' ),
        ) );

        $this->assertStringContainsString( 'tone', strtolower( $prompt ) );
        $this->assertStringContainsString( 'flow', strtolower( $prompt ) );
    }

    public function test_build_review_prompt_includes_target_tone(): void {
        $blocks = array(
            array( 'blockName' => 'core/paragraph', 'innerHTML' => '<p>Test</p>' ),
        );

        $prompt = $this->builder->build_review_prompt( $blocks, array(
            'target_tone' => 'casual',
        ) );

        $this->assertStringContainsString( 'casual', strtolower( $prompt ) );
    }

    public function test_build_reply_prompt_includes_context(): void {
        $original_note = 'Consider making this more concise.';
        $user_reply    = 'I need the detail for SEO purposes.';
        $current_content = '<p>This is the detailed paragraph content.</p>';

        $prompt = $this->builder->build_reply_prompt(
            $original_note,
            $user_reply,
            $current_content
        );

        $this->assertStringContainsString( $original_note, $prompt );
        $this->assertStringContainsString( $user_reply, $prompt );
        $this->assertStringContainsString( 'detailed paragraph', $prompt );
    }

    public function test_get_system_instruction_is_consistent(): void {
        $instruction1 = $this->builder->get_system_instruction();
        $instruction2 = $this->builder->get_system_instruction();

        $this->assertEquals( $instruction1, $instruction2 );
        $this->assertStringContainsString( 'editorial', strtolower( $instruction1 ) );
    }

    public function test_strips_html_tags_from_content(): void {
        $blocks = array(
            array(
                'blockName' => 'core/paragraph',
                'innerHTML' => '<p><strong>Bold</strong> and <em>italic</em></p>',
            ),
        );

        $prompt = $this->builder->build_review_prompt( $blocks, array() );

        // Should contain text content
        $this->assertStringContainsString( 'Bold', $prompt );
        $this->assertStringContainsString( 'italic', $prompt );
    }

    public function test_handles_empty_blocks_array(): void {
        $prompt = $this->builder->build_review_prompt( array(), array() );

        $this->assertNotEmpty( $prompt );
        // Should indicate no content to review
        $this->assertStringContainsString( 'blocks', strtolower( $prompt ) );
    }

    public function test_truncates_very_long_content(): void {
        $long_content = str_repeat( 'Lorem ipsum dolor sit amet. ', 1000 );
        $blocks = array(
            array(
                'blockName' => 'core/paragraph',
                'innerHTML' => '<p>' . $long_content . '</p>',
            ),
        );

        $prompt = $this->builder->build_review_prompt( $blocks, array() );

        // Should be truncated to reasonable length
        $this->assertLessThan( 50000, strlen( $prompt ) );
        $this->assertStringContainsString( '[truncated]', $prompt );
    }
}
```

#### Response Parser Tests

```php
<?php
namespace AI_Feedback\Tests\Unit;

use AI_Feedback\Response_Parser;

class Test_Response_Parser extends \WP_Mock\Tools\TestCase {

    private Response_Parser $parser;

    public function setUp(): void {
        parent::setUp();
        \WP_Mock::setUp();
        $this->parser = new Response_Parser();
    }

    public function test_parse_valid_json_response(): void {
        $response = json_encode( array(
            array(
                'block_index' => 0,
                'category'    => 'content',
                'severity'    => 'suggestion',
                'title'       => 'Add context',
                'feedback'    => 'Consider adding more context.',
                'suggestion'  => 'Start with why this matters.',
            ),
        ) );

        $blocks = array(
            array( 'attrs' => array( 'metadata' => array( 'id' => 'block-1' ) ) ),
        );

        \WP_Mock::userFunction( 'sanitize_text_field' )
            ->andReturnUsing( fn( $str ) => $str );

        \WP_Mock::userFunction( 'wp_kses_post' )
            ->andReturnUsing( fn( $str ) => $str );

        $result = $this->parser->parse_feedback( $response, $blocks );

        $this->assertCount( 1, $result );
        $this->assertEquals( 'block-1', $result[0]['block_id'] );
        $this->assertEquals( 'content', $result[0]['category'] );
        $this->assertEquals( 'Add context', $result[0]['title'] );
    }

    public function test_parse_handles_invalid_json(): void {
        $response = 'This is not JSON';
        $blocks   = array();

        $result = $this->parser->parse_feedback( $response, $blocks );

        $this->assertEmpty( $result );
    }

    public function test_parse_skips_invalid_block_indices(): void {
        $response = json_encode( array(
            array(
                'block_index' => 5, // Only have 2 blocks
                'category'    => 'content',
                'severity'    => 'suggestion',
                'title'       => 'Test',
                'feedback'    => 'Should be skipped',
            ),
        ) );

        $blocks = array(
            array( 'attrs' => array() ),
            array( 'attrs' => array() ),
        );

        $result = $this->parser->parse_feedback( $response, $blocks );

        $this->assertEmpty( $result );
    }

    public function test_parse_sanitizes_content(): void {
        $response = json_encode( array(
            array(
                'block_index' => 0,
                'category'    => 'content',
                'severity'    => 'suggestion',
                'title'       => '<script>alert("xss")</script>Title',
                'feedback'    => 'Normal feedback',
            ),
        ) );

        $blocks = array(
            array( 'attrs' => array() ),
        );

        \WP_Mock::userFunction( 'sanitize_text_field' )
            ->andReturnUsing( fn( $str ) => strip_tags( $str ) );

        \WP_Mock::userFunction( 'wp_kses_post' )
            ->andReturnUsing( fn( $str ) => strip_tags( $str, '<strong><em><code>' ) );

        $result = $this->parser->parse_feedback( $response, $blocks );

        $this->assertStringNotContainsString( '<script>', $result[0]['title'] );
    }

    public function test_parse_handles_missing_optional_fields(): void {
        $response = json_encode( array(
            array(
                'block_index' => 0,
                'category'    => 'tone',
                'severity'    => 'important',
                'title'       => 'Tone issue',
                'feedback'    => 'The tone could be improved.',
                // No 'suggestion' field
            ),
        ) );

        $blocks = array( array( 'attrs' => array() ) );

        \WP_Mock::userFunction( 'sanitize_text_field' )->andReturnArg( 0 );
        \WP_Mock::userFunction( 'wp_kses_post' )->andReturnArg( 0 );

        $result = $this->parser->parse_feedback( $response, $blocks );

        $this->assertNull( $result[0]['suggestion'] );
    }
}
```

## JavaScript Unit Tests

### Setup

Using Jest with React Testing Library.

```bash
# Run tests
npm run test:unit

# Run with coverage
npm run test:unit -- --coverage

# Run in watch mode
npm run test:unit -- --watch
```

### Configuration

`jest.config.js`:

```javascript
module.exports = {
    preset: '@wordpress/jest-preset-default',
    testEnvironment: 'jsdom',
    setupFilesAfterEnv: [ '<rootDir>/tests/js/setup.js' ],
    testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
    collectCoverageFrom: [
        'src/**/*.js',
        '!src/index.js',
        '!**/node_modules/**',
    ],
    coverageThreshold: {
        global: {
            branches: 90,
            functions: 90,
            lines: 90,
            statements: 90,
        },
    },
};
```

### Test Setup

`tests/js/setup.js`:

```javascript
import '@testing-library/jest-dom';
import { registerStore } from '@wordpress/data';

// Mock WordPress packages
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/data', () => ( {
    ...jest.requireActual( '@wordpress/data' ),
    useSelect: jest.fn(),
    useDispatch: jest.fn(),
} ) );
```

### Example Component Tests

#### ReviewButton Tests

```javascript
// tests/js/components/ReviewButton.test.js
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import ReviewButton from '../../../src/components/ReviewButton';

jest.mock( '@wordpress/data' );

describe( 'ReviewButton', () => {
    const mockStartReview = jest.fn();

    beforeEach( () => {
        jest.clearAllMocks();

        useDispatch.mockReturnValue( {
            startReview: mockStartReview,
        } );
    } );

    it( 'renders with default text', () => {
        useSelect.mockReturnValue( {
            isReviewing: false,
            postId: 123,
        } );

        render( <ReviewButton /> );

        expect(
            screen.getByRole( 'button', { name: /review document/i } )
        ).toBeInTheDocument();
    } );

    it( 'shows loading state during review', () => {
        useSelect.mockReturnValue( {
            isReviewing: true,
            postId: 123,
        } );

        render( <ReviewButton /> );

        expect(
            screen.getByRole( 'button', { name: /reviewing/i } )
        ).toBeInTheDocument();
        expect( screen.getByRole( 'button' ) ).toBeDisabled();
    } );

    it( 'calls startReview when clicked', async () => {
        useSelect.mockReturnValue( {
            isReviewing: false,
            postId: 123,
        } );

        render( <ReviewButton /> );

        fireEvent.click(
            screen.getByRole( 'button', { name: /review document/i } )
        );

        await waitFor( () => {
            expect( mockStartReview ).toHaveBeenCalledWith( {
                postId: 123,
            } );
        } );
    } );

    it( 'is disabled when no post ID', () => {
        useSelect.mockReturnValue( {
            isReviewing: false,
            postId: null,
        } );

        render( <ReviewButton /> );

        expect( screen.getByRole( 'button' ) ).toBeDisabled();
    } );
} );
```

#### ModelSelector Tests

```javascript
// tests/js/components/ModelSelector.test.js
import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import ModelSelector from '../../../src/components/ModelSelector';

jest.mock( '@wordpress/data' );

describe( 'ModelSelector', () => {
    const mockModels = [
        { id: 'claude-sonnet-4', name: 'Claude Sonnet 4', provider: 'anthropic' },
        { id: 'gpt-4o', name: 'GPT-4o', provider: 'openai' },
    ];

    const mockUpdateSettings = jest.fn();

    beforeEach( () => {
        jest.clearAllMocks();

        useDispatch.mockReturnValue( {
            updateSettings: mockUpdateSettings,
        } );
    } );

    it( 'renders available models', () => {
        useSelect.mockReturnValue( {
            availableModels: mockModels,
            selectedModel: 'claude-sonnet-4',
        } );

        render( <ModelSelector /> );

        expect( screen.getByRole( 'combobox' ) ).toBeInTheDocument();
        expect( screen.getByText( 'Claude Sonnet 4' ) ).toBeInTheDocument();
    } );

    it( 'calls updateSettings on model change', () => {
        useSelect.mockReturnValue( {
            availableModels: mockModels,
            selectedModel: 'claude-sonnet-4',
        } );

        render( <ModelSelector /> );

        fireEvent.change( screen.getByRole( 'combobox' ), {
            target: { value: 'gpt-4o' },
        } );

        expect( mockUpdateSettings ).toHaveBeenCalledWith( {
            defaultModel: 'gpt-4o',
        } );
    } );

    it( 'shows loading state when models are loading', () => {
        useSelect.mockReturnValue( {
            availableModels: [],
            selectedModel: null,
            isLoadingModels: true,
        } );

        render( <ModelSelector /> );

        expect( screen.getByText( /loading/i ) ).toBeInTheDocument();
    } );

    it( 'groups models by provider', () => {
        useSelect.mockReturnValue( {
            availableModels: mockModels,
            selectedModel: 'claude-sonnet-4',
        } );

        render( <ModelSelector /> );

        expect( screen.getByRole( 'group', { name: /anthropic/i } ) ).toBeInTheDocument();
        expect( screen.getByRole( 'group', { name: /openai/i } ) ).toBeInTheDocument();
    } );
} );
```

#### Store Tests

```javascript
// tests/js/store/reducer.test.js
import reducer, { initialState } from '../../../src/store/reducer';
import {
    START_REVIEW,
    REVIEW_SUCCESS,
    REVIEW_ERROR,
    UPDATE_SETTINGS,
} from '../../../src/store/action-types';

describe( 'AI Feedback Store Reducer', () => {
    it( 'returns initial state', () => {
        expect( reducer( undefined, {} ) ).toEqual( initialState );
    } );

    it( 'handles START_REVIEW', () => {
        const state = reducer( initialState, { type: START_REVIEW } );

        expect( state.isReviewing ).toBe( true );
        expect( state.error ).toBe( null );
    } );

    it( 'handles REVIEW_SUCCESS', () => {
        const startedState = { ...initialState, isReviewing: true };
        const reviewData = {
            review_id: 'abc123',
            notes: [ { id: 1, content: 'Test' } ],
            summary: { total_notes: 1 },
        };

        const state = reducer( startedState, {
            type: REVIEW_SUCCESS,
            payload: reviewData,
        } );

        expect( state.isReviewing ).toBe( false );
        expect( state.lastReview ).toEqual( reviewData );
        expect( state.reviewHistory ).toHaveLength( 1 );
    } );

    it( 'handles REVIEW_ERROR', () => {
        const startedState = { ...initialState, isReviewing: true };
        const error = new Error( 'API failed' );

        const state = reducer( startedState, {
            type: REVIEW_ERROR,
            payload: error,
        } );

        expect( state.isReviewing ).toBe( false );
        expect( state.error ).toBe( error );
    } );

    it( 'handles UPDATE_SETTINGS', () => {
        const state = reducer( initialState, {
            type: UPDATE_SETTINGS,
            payload: { defaultModel: 'gpt-4o' },
        } );

        expect( state.settings.defaultModel ).toBe( 'gpt-4o' );
    } );

    it( 'preserves existing settings on partial update', () => {
        const stateWithSettings = {
            ...initialState,
            settings: {
                defaultModel: 'claude-sonnet-4',
                focusAreas: [ 'content' ],
            },
        };

        const state = reducer( stateWithSettings, {
            type: UPDATE_SETTINGS,
            payload: { focusAreas: [ 'content', 'tone' ] },
        } );

        expect( state.settings.defaultModel ).toBe( 'claude-sonnet-4' );
        expect( state.settings.focusAreas ).toEqual( [ 'content', 'tone' ] );
    } );
} );
```

## Integration Tests

### PHP Integration Tests

Tests that require a real WordPress environment:

```php
<?php
namespace AI_Feedback\Tests\Integration;

use AI_Feedback\Review_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

class Test_REST_API extends WP_UnitTestCase {

    private int $admin_id;
    private int $editor_id;
    private int $subscriber_id;
    private int $post_id;

    public function set_up(): void {
        parent::set_up();

        $this->admin_id = $this->factory->user->create( array(
            'role' => 'administrator',
        ) );

        $this->editor_id = $this->factory->user->create( array(
            'role' => 'editor',
        ) );

        $this->subscriber_id = $this->factory->user->create( array(
            'role' => 'subscriber',
        ) );

        $this->post_id = $this->factory->post->create( array(
            'post_content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
            'post_author'  => $this->editor_id,
        ) );
    }

    public function test_review_endpoint_requires_authentication(): void {
        $request = new WP_REST_Request( 'POST', '/ai-feedback/v1/review' );
        $request->set_param( 'post_id', $this->post_id );

        $response = rest_do_request( $request );

        $this->assertEquals( 401, $response->get_status() );
    }

    public function test_review_endpoint_requires_edit_capability(): void {
        wp_set_current_user( $this->subscriber_id );

        $request = new WP_REST_Request( 'POST', '/ai-feedback/v1/review' );
        $request->set_param( 'post_id', $this->post_id );

        $response = rest_do_request( $request );

        $this->assertEquals( 403, $response->get_status() );
    }

    public function test_editor_can_review_own_post(): void {
        wp_set_current_user( $this->editor_id );

        $request = new WP_REST_Request( 'POST', '/ai-feedback/v1/review' );
        $request->set_param( 'post_id', $this->post_id );

        // Mock AI client for integration test
        add_filter( 'ai_feedback_mock_response', function() {
            return '[]';
        } );

        $response = rest_do_request( $request );

        $this->assertEquals( 200, $response->get_status() );
        $this->assertArrayHasKey( 'review_id', $response->get_data() );
    }

    public function test_admin_can_review_any_post(): void {
        wp_set_current_user( $this->admin_id );

        $request = new WP_REST_Request( 'POST', '/ai-feedback/v1/review' );
        $request->set_param( 'post_id', $this->post_id );

        add_filter( 'ai_feedback_mock_response', fn() => '[]' );

        $response = rest_do_request( $request );

        $this->assertEquals( 200, $response->get_status() );
    }

    public function test_review_creates_notes(): void {
        wp_set_current_user( $this->editor_id );

        add_filter( 'ai_feedback_mock_response', function() {
            return json_encode( array(
                array(
                    'block_index' => 0,
                    'category'    => 'content',
                    'severity'    => 'suggestion',
                    'title'       => 'Test note',
                    'feedback'    => 'This is test feedback.',
                ),
            ) );
        } );

        $request = new WP_REST_Request( 'POST', '/ai-feedback/v1/review' );
        $request->set_param( 'post_id', $this->post_id );

        $response = rest_do_request( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertCount( 1, $data['notes'] );

        // Verify note was created in database
        $comments = get_comments( array(
            'post_id' => $this->post_id,
            'type'    => 'block_comment',
        ) );

        $this->assertCount( 1, $comments );
    }

    public function test_settings_endpoint(): void {
        wp_set_current_user( $this->admin_id );

        // GET settings
        $request = new WP_REST_Request( 'GET', '/ai-feedback/v1/settings' );
        $response = rest_do_request( $request );

        $this->assertEquals( 200, $response->get_status() );
        $this->assertArrayHasKey( 'default_model', $response->get_data() );

        // POST settings
        $request = new WP_REST_Request( 'POST', '/ai-feedback/v1/settings' );
        $request->set_param( 'default_model', 'gpt-4o' );

        $response = rest_do_request( $request );

        $this->assertEquals( 200, $response->get_status() );
        $this->assertEquals( 'gpt-4o', get_option( 'ai_feedback_default_model' ) );
    }
}
```

## End-to-End Tests

### Setup

Using Playwright with WordPress environment:

```bash
# Install Playwright
npm run test:e2e:install

# Run E2E tests
npm run test:e2e

# Run in headed mode (see browser)
npm run test:e2e -- --headed

# Run specific test file
npm run test:e2e -- review-flow.spec.js
```

### Configuration

`tests/e2e/playwright.config.js`:

```javascript
import { defineConfig } from '@playwright/test';

export default defineConfig( {
    testDir: '.',
    timeout: 60000,
    retries: 2,
    workers: 1, // WordPress tests run sequentially
    use: {
        baseURL: 'http://localhost:8889',
        screenshot: 'only-on-failure',
        video: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium',
            use: { browserName: 'chromium' },
        },
    ],
    webServer: {
        command: 'npm run env:start',
        url: 'http://localhost:8889',
        reuseExistingServer: true,
    },
} );
```

### Example E2E Tests

```javascript
// tests/e2e/review-flow.spec.js
import { test, expect } from '@playwright/test';

test.describe( 'Review Flow', () => {
    test.beforeEach( async ( { page } ) => {
        // Login as admin
        await page.goto( '/wp-login.php' );
        await page.fill( '#user_login', 'admin' );
        await page.fill( '#user_pass', 'password' );
        await page.click( '#wp-submit' );
        await page.waitForURL( '**/wp-admin/**' );
    } );

    test( 'can open AI Feedback sidebar', async ( { page } ) => {
        await page.goto( '/wp-admin/post-new.php' );
        await page.waitForSelector( '.editor-post-title__input' );

        // Click AI Feedback toolbar button
        await page.click( 'button[aria-label="AI Feedback"]' );

        // Verify sidebar opened
        await expect(
            page.locator( '.ai-feedback-sidebar' )
        ).toBeVisible();

        await expect(
            page.getByRole( 'button', { name: /review document/i } )
        ).toBeVisible();
    } );

    test( 'can initiate document review', async ( { page } ) => {
        await page.goto( '/wp-admin/post-new.php' );

        // Add some content
        await page.click( '.editor-post-title__input' );
        await page.keyboard.type( 'Test Post Title' );

        await page.click( '.block-editor-default-block-appender__content' );
        await page.keyboard.type( 'This is a test paragraph for AI review.' );

        // Open sidebar and start review
        await page.click( 'button[aria-label="AI Feedback"]' );
        await page.click( 'button:has-text("Review Document")' );

        // Wait for review to complete
        await expect(
            page.locator( '.ai-feedback-sidebar' )
        ).toContainText( /reviewing/i );

        await expect(
            page.locator( '.ai-feedback-summary' )
        ).toBeVisible( { timeout: 30000 } );
    } );

    test( 'shows notes on blocks after review', async ( { page } ) => {
        // Create post with content
        await page.goto( '/wp-admin/post-new.php' );
        await page.click( '.editor-post-title__input' );
        await page.keyboard.type( 'Post with AI Notes' );

        await page.click( '.block-editor-default-block-appender__content' );
        await page.keyboard.type( 'Content that needs improvement.' );

        // Trigger review (mocked to return notes)
        await page.click( 'button[aria-label="AI Feedback"]' );
        await page.click( 'button:has-text("Review Document")' );

        // Wait for notes to appear
        await page.waitForSelector( '.block-editor-block-list__block[data-has-note="true"]', {
            timeout: 30000,
        } );

        // Verify note indicator is visible
        await expect(
            page.locator( '.ai-feedback-note-indicator' )
        ).toBeVisible();
    } );

    test( 'can click note to see feedback', async ( { page } ) => {
        // Assume post with notes exists
        await page.goto( '/wp-admin/post.php?post=123&action=edit' );

        // Click note indicator
        await page.click( '.ai-feedback-note-indicator' );

        // Verify note panel opens
        await expect(
            page.locator( '.ai-feedback-note-panel' )
        ).toBeVisible();

        await expect(
            page.locator( '.ai-feedback-note-content' )
        ).toContainText( /.+/ );
    } );
} );
```

```javascript
// tests/e2e/reply-flow.spec.js
import { test, expect } from '@playwright/test';

test.describe( 'Reply Flow', () => {
    test( 'can reply to AI feedback note', async ( { page } ) => {
        // Navigate to post with existing note
        await page.goto( '/wp-admin/post.php?post=123&action=edit' );

        // Open note
        await page.click( '.ai-feedback-note-indicator' );

        // Type reply
        await page.fill(
            '.ai-feedback-reply-input',
            'I want to keep this approach because...'
        );

        await page.click( 'button:has-text("Send Reply")' );

        // Wait for AI response
        await expect(
            page.locator( '.ai-feedback-reply' )
        ).toHaveCount( 2, { timeout: 30000 } );

        // Verify new reply appears
        await expect(
            page.locator( '.ai-feedback-reply' ).last()
        ).toContainText( /.+/ );
    } );

    test( 'can resolve note', async ( { page } ) => {
        await page.goto( '/wp-admin/post.php?post=123&action=edit' );

        await page.click( '.ai-feedback-note-indicator' );
        await page.click( 'button:has-text("Resolve")' );

        // Note should be marked as resolved
        await expect(
            page.locator( '.ai-feedback-note-indicator' )
        ).not.toBeVisible();

        // Check sidebar shows resolved count
        await page.click( 'button[aria-label="AI Feedback"]' );
        await expect(
            page.locator( '.ai-feedback-sidebar' )
        ).toContainText( /1 resolved/i );
    } );
} );
```

```javascript
// tests/e2e/settings.spec.js
import { test, expect } from '@playwright/test';

test.describe( 'Settings', () => {
    test( 'can change default model', async ( { page } ) => {
        await page.goto( '/wp-admin/post-new.php' );

        await page.click( 'button[aria-label="AI Feedback"]' );

        // Change model
        await page.selectOption( '.ai-feedback-model-selector', 'gpt-4o' );

        // Verify selection persists after page reload
        await page.reload();
        await page.click( 'button[aria-label="AI Feedback"]' );

        await expect(
            page.locator( '.ai-feedback-model-selector' )
        ).toHaveValue( 'gpt-4o' );
    } );

    test( 'can toggle focus areas', async ( { page } ) => {
        await page.goto( '/wp-admin/post-new.php' );

        await page.click( 'button[aria-label="AI Feedback"]' );

        // Uncheck 'Design & Formatting'
        await page.click( 'input[name="focus-design"]' );

        // Verify checkbox state
        await expect(
            page.locator( 'input[name="focus-design"]' )
        ).not.toBeChecked();

        // Start review and verify design feedback not included
        await page.click( '.editor-post-title__input' );
        await page.keyboard.type( 'Test' );

        await page.click( 'button:has-text("Review Document")' );

        await page.waitForSelector( '.ai-feedback-summary' );

        // No design category in results
        await expect(
            page.locator( '.ai-feedback-summary' )
        ).not.toContainText( /design/i );
    } );
} );
```

## Visual Regression Tests

### Setup

Using Playwright with Percy for visual comparison:

```bash
# Run visual tests locally (generates screenshots)
npm run test:visual

# Run with Percy (CI)
PERCY_TOKEN=xxx npm run test:visual:percy
```

### Configuration

```javascript
// tests/visual/playwright.config.js
import { defineConfig } from '@playwright/test';

export default defineConfig( {
    testDir: '.',
    snapshotDir: './snapshots',
    updateSnapshots: process.env.UPDATE_SNAPSHOTS ? 'all' : 'missing',
    use: {
        baseURL: 'http://localhost:8889',
    },
} );
```

### Example Visual Tests

```javascript
// tests/visual/sidebar.spec.js
import { test, expect } from '@playwright/test';

test.describe( 'Sidebar Visual Tests', () => {
    test.beforeEach( async ( { page } ) => {
        await page.goto( '/wp-login.php' );
        await page.fill( '#user_login', 'admin' );
        await page.fill( '#user_pass', 'password' );
        await page.click( '#wp-submit' );
    } );

    test( 'sidebar default state', async ( { page } ) => {
        await page.goto( '/wp-admin/post-new.php' );
        await page.click( 'button[aria-label="AI Feedback"]' );

        await page.waitForSelector( '.ai-feedback-sidebar' );

        await expect( page ).toHaveScreenshot( 'sidebar-default.png', {
            clip: { x: 800, y: 0, width: 400, height: 600 },
        } );
    } );

    test( 'sidebar during review', async ( { page } ) => {
        await page.goto( '/wp-admin/post-new.php' );
        await page.click( '.editor-post-title__input' );
        await page.keyboard.type( 'Test' );

        await page.click( 'button[aria-label="AI Feedback"]' );
        await page.click( 'button:has-text("Review Document")' );

        // Capture loading state
        await expect( page ).toHaveScreenshot( 'sidebar-reviewing.png', {
            clip: { x: 800, y: 0, width: 400, height: 600 },
        } );
    } );

    test( 'sidebar with review results', async ( { page } ) => {
        // Navigate to post with completed review
        await page.goto( '/wp-admin/post.php?post=123&action=edit' );
        await page.click( 'button[aria-label="AI Feedback"]' );

        await page.waitForSelector( '.ai-feedback-summary' );

        await expect( page ).toHaveScreenshot( 'sidebar-with-results.png', {
            clip: { x: 800, y: 0, width: 400, height: 600 },
        } );
    } );
} );
```

```javascript
// tests/visual/notes.spec.js
import { test, expect } from '@playwright/test';

test.describe( 'Notes Visual Tests', () => {
    test( 'block with note indicator', async ( { page } ) => {
        await page.goto( '/wp-admin/post.php?post=123&action=edit' );

        const block = page.locator( '.block-editor-block-list__block' ).first();
        await expect( block ).toHaveScreenshot( 'block-with-note.png' );
    } );

    test( 'open note panel', async ( { page } ) => {
        await page.goto( '/wp-admin/post.php?post=123&action=edit' );

        await page.click( '.ai-feedback-note-indicator' );
        await page.waitForSelector( '.ai-feedback-note-panel' );

        await expect(
            page.locator( '.ai-feedback-note-panel' )
        ).toHaveScreenshot( 'note-panel-open.png' );
    } );

    test( 'note with replies', async ( { page } ) => {
        await page.goto( '/wp-admin/post.php?post=456&action=edit' );

        await page.click( '.ai-feedback-note-indicator' );
        await page.waitForSelector( '.ai-feedback-reply' );

        await expect(
            page.locator( '.ai-feedback-note-panel' )
        ).toHaveScreenshot( 'note-with-replies.png' );
    } );

    test( 'resolved note appearance', async ( { page } ) => {
        await page.goto( '/wp-admin/post.php?post=789&action=edit' );

        // Open sidebar to see resolved notes
        await page.click( 'button[aria-label="AI Feedback"]' );

        await expect(
            page.locator( '.ai-feedback-resolved-notes' )
        ).toHaveScreenshot( 'resolved-notes-list.png' );
    } );
} );
```

## CI/CD Integration

### GitHub Actions Workflow

```yaml
# .github/workflows/test.yml
name: Tests

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  php-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: xdebug

      - name: Install Composer dependencies
        run: composer install

      - name: Run PHP unit tests
        run: composer test:coverage

      - name: Upload coverage
        uses: codecov/codecov-action@v3
        with:
          files: coverage/php/clover.xml

  js-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '18'
          cache: 'npm'

      - run: npm ci
      - run: npm run test:unit -- --coverage

      - name: Upload coverage
        uses: codecov/codecov-action@v3
        with:
          files: coverage/js/lcov.info

  e2e-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '18'
          cache: 'npm'

      - run: npm ci
      - run: npx playwright install chromium

      - name: Start WordPress
        run: npm run env:start

      - name: Run E2E tests
        run: npm run test:e2e

      - uses: actions/upload-artifact@v3
        if: failure()
        with:
          name: e2e-results
          path: tests/e2e/test-results/

  visual-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '18'
          cache: 'npm'

      - run: npm ci
      - run: npx playwright install chromium

      - name: Start WordPress
        run: npm run env:start

      - name: Run visual tests
        run: npm run test:visual
        env:
          PERCY_TOKEN: ${{ secrets.PERCY_TOKEN }}
```

## Test Data Management

### Fixtures

```php
// tests/php/fixtures/sample-content.php
return array(
    'simple_post' => '<!-- wp:paragraph --><p>Simple test content.</p><!-- /wp:paragraph -->',

    'complex_post' => '
        <!-- wp:heading {"level":1} --><h1>Main Title</h1><!-- /wp:heading -->
        <!-- wp:paragraph --><p>Introduction paragraph.</p><!-- /wp:paragraph -->
        <!-- wp:heading {"level":2} --><h2>Section One</h2><!-- /wp:heading -->
        <!-- wp:paragraph --><p>Section content here.</p><!-- /wp:paragraph -->
        <!-- wp:list --><ul><li>Item 1</li><li>Item 2</li></ul><!-- /wp:list -->
    ',

    'ai_response_single' => json_encode( array(
        array(
            'block_index' => 0,
            'category'    => 'content',
            'severity'    => 'suggestion',
            'title'       => 'Add more context',
            'feedback'    => 'The introduction could benefit from more context.',
            'suggestion'  => 'Start by explaining the problem being solved.',
        ),
    ) ),

    'ai_response_multiple' => json_encode( array(
        array(
            'block_index' => 0,
            'category'    => 'tone',
            'severity'    => 'suggestion',
            'title'       => 'Consider formal tone',
            'feedback'    => 'The heading is quite casual.',
        ),
        array(
            'block_index' => 1,
            'category'    => 'content',
            'severity'    => 'important',
            'title'       => 'Expand introduction',
            'feedback'    => 'The introduction is too brief.',
        ),
        array(
            'block_index' => 3,
            'category'    => 'flow',
            'severity'    => 'suggestion',
            'title'       => 'Add transition',
            'feedback'    => 'Consider adding a transition sentence.',
        ),
    ) ),
);
```

### E2E Test Data Seeding

```javascript
// tests/e2e/utils/seed-data.js
import { request } from '@playwright/test';

export async function seedTestData( baseURL ) {
    const context = await request.newContext( {
        baseURL,
        extraHTTPHeaders: {
            Authorization: 'Basic ' + btoa( 'admin:password' ),
        },
    } );

    // Create test posts
    const posts = await Promise.all( [
        context.post( '/wp-json/wp/v2/posts', {
            data: {
                title: 'Post with Notes',
                content: '<!-- wp:paragraph --><p>Test content</p><!-- /wp:paragraph -->',
                status: 'draft',
            },
        } ),
        context.post( '/wp-json/wp/v2/posts', {
            data: {
                title: 'Post with Replies',
                content: '<!-- wp:paragraph --><p>Another test</p><!-- /wp:paragraph -->',
                status: 'draft',
            },
        } ),
    ] );

    return posts.map( ( r ) => r.json() );
}
```

## Mocking Strategies

### Mocking AI Responses in PHP

```php
// Add filter for testing
add_filter( 'ai_feedback_pre_ai_request', function( $response, $prompt ) {
    if ( defined( 'AI_FEEDBACK_TESTING' ) && AI_FEEDBACK_TESTING ) {
        return json_encode( get_test_ai_response() );
    }
    return $response;
}, 10, 2 );
```

### Mocking in E2E Tests

```javascript
// tests/e2e/utils/mock-ai.js
export async function mockAIResponses( page ) {
    await page.route( '**/wp-json/ai-feedback/v1/review', async ( route ) => {
        await route.fulfill( {
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify( {
                review_id: 'test-123',
                notes: [
                    {
                        id: 1,
                        block_id: 'block-abc',
                        category: 'content',
                        severity: 'suggestion',
                        title: 'Test Note',
                        content: 'This is test feedback.',
                    },
                ],
                summary: {
                    total_notes: 1,
                    by_category: { content: 1 },
                    by_severity: { suggestion: 1 },
                },
            } ),
        } );
    } );
}
```

## Coverage Requirements

### Minimum Thresholds

| Metric | PHP | JavaScript |
|--------|-----|------------|
| Line Coverage | 90% | 90% |
| Branch Coverage | 85% | 85% |
| Function Coverage | 90% | 90% |

### Critical Paths (100% Coverage Required)

- Authentication/authorization checks
- Data sanitization and validation
- Note creation and management
- Settings persistence
- AI response parsing

## Running All Tests

```bash
# Run complete test suite
npm run test:all

# Equivalent to:
npm run lint:js && \
npm run lint:php && \
npm run lint:css && \
npm run test:unit && \
composer test && \
npm run test:e2e && \
npm run test:visual
```
