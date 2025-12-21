# AI Feedback Plugin for WordPress

## Design Document

**Version:** 1.0.0
**Status:** Draft
**Last Updated:** December 2025

---

## Table of Contents

1. [Overview](#overview)
2. [Goals & Non-Goals](#goals--non-goals)
3. [Architecture](#architecture)
4. [User Experience](#user-experience)
5. [Technical Design](#technical-design)
6. [Data Model](#data-model)
7. [API Design](#api-design)
8. [Security Considerations](#security-considerations)
9. [Performance Considerations](#performance-considerations)
10. [Testing Strategy](#testing-strategy)
11. [Dependencies](#dependencies)
12. [Rollout Plan](#rollout-plan)

---

## Overview

### Problem Statement

Content creators and editors working in WordPress need actionable feedback on their content's quality, design, tone, and flow. Currently, this requires manual review by editors or external tools, creating friction in the publishing workflow.

### Solution

The AI Feedback plugin integrates AI-powered content review directly into the Gutenberg editor using WordPress 6.9's Notes feature. Users can request an AI review of their entire document, receiving contextual feedback attached to specific blocks as Notes. The AI provides concise, actionable suggestions on:

- **Content quality** - clarity, accuracy, completeness
- **Design** - block usage, visual hierarchy, formatting
- **Tone** - consistency, audience appropriateness
- **Flow** - logical progression, transitions, structure

### Key Innovation

By leveraging the native Notes system, feedback becomes a first-class collaboration feature. Users can respond to AI suggestions, and the AI will reply based on the updated content and user's response, creating an interactive editorial dialogue.

---

## Goals & Non-Goals

### Goals

1. **Seamless Integration** - Feel like a native WordPress feature, not a third-party addon
2. **Actionable Feedback** - Every note should suggest a specific improvement
3. **Conversational** - Support back-and-forth dialogue on feedback items
4. **Provider Agnostic** - Work with any AI provider via the PHP AI Client
5. **Performant** - Review large documents without blocking the editor
6. **Accessible** - Full keyboard navigation and screen reader support
7. **Testable** - Comprehensive test coverage (unit, E2E, visual regression)

### Non-Goals

1. **Auto-editing** - Will not automatically modify content (suggestions only)
2. **Grammar/Spell Check** - Not a replacement for Grammarly or similar tools
3. **SEO Analysis** - Not an SEO plugin (may inform content quality, but not keyword optimization)
4. **Real-time Collaboration** - Does not provide live multi-user editing (uses async Notes)
5. **Content Generation** - Does not write content for users

---

## Architecture

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      Gutenberg Editor                            │
├─────────────────────────────────────────────────────────────────┤
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────────┐ │
│  │ Plugin       │  │ Notes        │  │ Block Editor           │ │
│  │ Sidebar      │  │ Integration  │  │ (Content)              │ │
│  │              │  │              │  │                        │ │
│  │ - Model      │  │ - Create     │  │ ┌────────┐ ┌────────┐  │ │
│  │   Selection  │  │ - Reply      │  │ │Block 1 │ │Block 2 │  │ │
│  │ - Settings   │  │ - Resolve    │  │ │  📝    │ │        │  │ │
│  │ - Review     │  │              │  │ └────────┘ └────────┘  │ │
│  │   Button     │  │              │  │ ┌────────┐ ┌────────┐  │ │
│  │ - History    │  │              │  │ │Block 3 │ │Block 4 │  │ │
│  │              │  │              │  │ │  📝    │ │  📝    │  │ │
│  └──────────────┘  └──────────────┘  │ └────────┘ └────────┘  │ │
│                                       └────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      REST API Layer                              │
│  /wp-json/ai-feedback/v1/                                       │
│  ├── POST /review          (initiate full document review)      │
│  ├── POST /reply           (reply to user's note response)      │
│  ├── GET  /settings        (get user/site settings)             │
│  └── POST /settings        (update settings)                    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      PHP Backend                                 │
│  ┌──────────────────┐  ┌──────────────────┐                     │
│  │ Review Service   │  │ Reply Service    │                     │
│  │                  │  │                  │                     │
│  │ - Parse blocks   │  │ - Get context    │                     │
│  │ - Build prompt   │  │ - Build prompt   │                     │
│  │ - Call AI        │  │ - Call AI        │                     │
│  │ - Create notes   │  │ - Create reply   │                     │
│  └──────────────────┘  └──────────────────┘                     │
│                              │                                   │
│                              ▼                                   │
│  ┌──────────────────────────────────────────────────────────────┐│
│  │                   PHP AI Client                              ││
│  │  wordpress/php-ai-client                                     ││
│  │                                                              ││
│  │  Supported Providers:                                        ││
│  │  - OpenAI (GPT-4, GPT-4o)                                    ││
│  │  - Anthropic (Claude)                                        ││
│  │  - Google (Gemini)                                           ││
│  │  - Custom/Self-hosted                                        ││
│  └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

### Component Breakdown

#### Frontend (JavaScript/React)

| Component | Description |
|-----------|-------------|
| `PluginSidebar` | Main settings panel in editor toolbar |
| `ReviewButton` | Triggers document review |
| `ModelSelector` | Dropdown for AI model selection |
| `SettingsPanel` | Configuration options |
| `ReviewHistory` | List of past reviews with timestamps |
| `NotesIntegration` | Bridge to WordPress Notes API |

#### Backend (PHP)

| Class | Description |
|-------|-------------|
| `AI_Feedback_Plugin` | Main plugin bootstrap |
| `Review_Service` | Orchestrates document review |
| `Reply_Service` | Handles reply conversations |
| `Prompt_Builder` | Constructs AI prompts |
| `Notes_Manager` | Creates/manages Notes via WP API |
| `Settings_Controller` | REST API for settings |
| `Review_Controller` | REST API for reviews |
| `Abilities_Provider` | Registers plugin abilities |

---

## User Experience

### Primary Flow: Document Review

```
1. User opens post in Gutenberg editor
2. User clicks AI Feedback icon in toolbar
   → Plugin sidebar opens
3. User selects AI model (optional, has default)
4. User clicks "Review Document"
   → Loading state with progress indicator
5. AI analyzes document
   → Notes appear on relevant blocks
6. User sees visual indicators on blocks with feedback
7. User clicks note icon on block
   → Note panel opens with AI feedback
8. User can:
   a. Resolve note (dismiss feedback)
   b. Reply to note (ask for clarification)
   c. Make changes based on feedback
```

### Secondary Flow: Reply to Feedback

```
1. User reads AI feedback note
2. User types reply: "I want to keep this informal because..."
3. User submits reply
   → AI receives: current content + note context + user reply
4. AI responds with updated suggestion or acknowledgment
5. Conversation continues until user resolves note
```

### Sidebar Design

```
┌─────────────────────────────┐
│ 🤖 AI Feedback              │
├─────────────────────────────┤
│                             │
│ Model                       │
│ ┌─────────────────────────┐ │
│ │ Claude Sonnet 4     ▼   │ │
│ └─────────────────────────┘ │
│                             │
│ Focus Areas                 │
│ ☑ Content Quality          │
│ ☑ Tone & Voice             │
│ ☑ Flow & Structure         │
│ ☐ Design & Formatting      │
│                             │
│ Tone Target                 │
│ ┌─────────────────────────┐ │
│ │ Professional        ▼   │ │
│ └─────────────────────────┘ │
│                             │
│ ┌─────────────────────────┐ │
│ │    📝 Review Document   │ │
│ └─────────────────────────┘ │
│                             │
├─────────────────────────────┤
│ Review History              │
│                             │
│ Today, 2:30 PM              │
│   5 notes · 3 resolved      │
│                             │
│ Yesterday, 10:15 AM         │
│   8 notes · 8 resolved      │
│                             │
└─────────────────────────────┘
```

---

## Technical Design

### WordPress Integration Points

#### Notes API (WordPress 6.9+)

The plugin leverages the native Notes feature introduced in WordPress 6.9:

```php
// Creating a note on a block
wp_insert_comment( array(
    'comment_type'    => 'note',
    'comment_post_ID' => $post_id,
    'comment_content' => $feedback_text,
    'comment_meta'    => array(
        'ai_feedback' => true,
        'review_id'   => $review_id,
    ),
) );
// Note: Link notes to blocks via block attribute metadata (noteId on the block)
```

```javascript
// JavaScript: Using useBlockComments hook
import { useBlockComments } from '@wordpress/editor';

const { comments, addComment, resolveComment } = useBlockComments( blockId );
```

#### Abilities API (WordPress 6.9+)

Register plugin capabilities for discoverability and AI agent integration:

```php
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability( 'ai-feedback/review-document', array(
        'label'       => __( 'Review Document with AI', 'ai-feedback' ),
        'description' => __( 'Analyze document content and provide editorial feedback', 'ai-feedback' ),
        'category'    => 'content',
        'input_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => 'The post ID to review',
                    'required'    => true,
                ),
                'focus_areas' => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'string' ),
                ),
            ),
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'review_id'   => array( 'type' => 'string' ),
                'notes_count' => array( 'type' => 'integer' ),
            ),
        ),
        'permission_callback' => function( $post_id ) {
            return current_user_can( 'edit_post', $post_id );
        },
        'execute_callback' => array( 'AI_Feedback\Review_Service', 'execute_review' ),
        'meta' => array(
            'show_in_rest' => true,
        ),
    ) );
} );
```

#### Plugin Sidebar Registration

```javascript
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { feedback } from '@wordpress/icons';

registerPlugin( 'ai-feedback', {
    render: () => (
        <>
            <PluginSidebarMoreMenuItem target="ai-feedback-sidebar">
                AI Feedback
            </PluginSidebarMoreMenuItem>
            <PluginSidebar
                name="ai-feedback-sidebar"
                title="AI Feedback"
                icon={ feedback }
            >
                <AIFeedbackPanel />
            </PluginSidebar>
        </>
    ),
} );
```

### AI Integration

#### Using PHP AI Client

```php
use WordPress\AiClient\AiClient;

class Review_Service {
    public function review_document( int $post_id, array $options = array() ): array {
        $post    = get_post( $post_id );
        $blocks  = parse_blocks( $post->post_content );
        $prompt  = $this->prompt_builder->build_review_prompt( $blocks, $options );

        $response = AiClient::prompt( $prompt )
            ->usingSystemInstruction( $this->get_system_instruction() )
            ->usingTemperature( 0.3 )  // Lower temperature for consistent feedback
            ->generateText();

        return $this->parse_feedback_response( $response, $blocks );
    }

    private function get_system_instruction(): string {
        return <<<PROMPT
You are an expert editorial assistant reviewing content in WordPress.
Your role is to provide concise, actionable feedback on content quality,
tone, flow, and design. Each piece of feedback should:

1. Identify a specific issue or opportunity
2. Explain why it matters
3. Suggest a concrete improvement

Format your response as JSON with feedback items keyed by block index.
Be encouraging but honest. Prioritize the most impactful suggestions.
PROMPT;
    }
}
```

#### Prompt Structure

```json
{
  "system": "You are an expert editorial assistant...",
  "user": {
    "task": "Review this document and provide feedback",
    "document": {
      "title": "How to Build a WordPress Plugin",
      "blocks": [
        {
          "index": 0,
          "type": "core/heading",
          "content": "Introduction"
        },
        {
          "index": 1,
          "type": "core/paragraph",
          "content": "WordPress plugins are really easy to build..."
        }
      ]
    },
    "focus_areas": ["content", "tone", "flow"],
    "target_tone": "professional",
    "output_format": {
      "type": "array",
      "items": {
        "block_index": "integer",
        "category": "content|tone|flow|design",
        "severity": "suggestion|important|critical",
        "title": "string (max 50 chars)",
        "feedback": "string (max 200 chars)",
        "suggestion": "string (optional, max 200 chars)"
      }
    }
  }
}
```

#### Response Parsing

```php
class Response_Parser {
    public function parse_feedback( string $response, array $blocks ): array {
        $feedback_items = json_decode( $response, true );
        $notes = array();

        foreach ( $feedback_items as $item ) {
            $block_index = $item['block_index'];

            if ( ! isset( $blocks[ $block_index ] ) ) {
                continue; // Skip invalid block references
            }

            $notes[] = array(
                'block_id'   => $blocks[ $block_index ]['attrs']['metadata']['id'] ?? null,
                'category'   => $item['category'],
                'severity'   => $item['severity'],
                'title'      => sanitize_text_field( $item['title'] ),
                'content'    => wp_kses_post( $item['feedback'] ),
                'suggestion' => isset( $item['suggestion'] )
                    ? sanitize_text_field( $item['suggestion'] )
                    : null,
            );
        }

        return $notes;
    }
}
```

---

## Data Model

### Database Schema

The plugin primarily uses WordPress's existing comment system for Notes, with custom meta fields.

#### Comment Meta (Note Extensions)

| Meta Key | Type | Description |
|----------|------|-------------|
| `ai_feedback` | bool | True if note was created by AI |
| `review_id` | string | UUID linking notes from same review |
| `feedback_category` | string | content, tone, flow, design |
| `feedback_severity` | string | suggestion, important, critical |
| `ai_model` | string | Model used for this feedback |

#### Options (Settings)

| Option Key | Type | Default | Description |
|------------|------|---------|-------------|
| `ai_feedback_default_model` | string | `''` | Default AI model to use |
| `ai_feedback_default_focus` | array | `['content', 'tone', 'flow']` | Default focus areas |
| `ai_feedback_default_tone` | string | `'professional'` | Default target tone |

#### User Meta (Per-User Settings)

| Meta Key | Type | Description |
|----------|------|-------------|
| `ai_feedback_model_preference` | string | User's preferred model |
| `ai_feedback_dismissed_tips` | array | Onboarding tips user has dismissed |

### Data Flow

```
┌─────────────┐      ┌──────────────┐      ┌─────────────────┐
│   Editor    │──────│  REST API    │──────│  Review Service │
│  (React)    │      │              │      │                 │
└─────────────┘      └──────────────┘      └─────────────────┘
                                                   │
                     ┌─────────────────────────────┼─────────────────────────────┐
                     │                             │                             │
                     ▼                             ▼                             ▼
              ┌─────────────┐              ┌─────────────┐              ┌─────────────┐
              │   Prompt    │              │   AI Client │              │   Notes     │
              │   Builder   │──────────────│             │──────────────│   Manager   │
              │             │              │             │              │             │
              └─────────────┘              └─────────────┘              └─────────────┘
                                                                               │
                                                                               ▼
                                                                        ┌─────────────┐
                                                                        │  WordPress  │
                                                                        │  Comments   │
                                                                        │    Table    │
                                                                        └─────────────┘
```

---

## API Design

### REST API Endpoints

#### POST /wp-json/ai-feedback/v1/review

Initiate a document review.

**Request:**
```json
{
  "post_id": 123,
  "model": "claude-sonnet-4",
  "focus_areas": ["content", "tone", "flow"],
  "target_tone": "professional"
}
```

**Response:**
```json
{
  "review_id": "550e8400-e29b-41d4-a716-446655440000",
  "notes": [
    {
      "id": 456,
      "block_id": "abc123",
      "category": "content",
      "severity": "suggestion",
      "title": "Consider adding context",
      "content": "The opening paragraph jumps into technical details. Consider adding a sentence explaining why this matters to readers.",
      "suggestion": "Start with the problem this solves before explaining the solution."
    }
  ],
  "summary": {
    "total_notes": 5,
    "by_category": {
      "content": 2,
      "tone": 1,
      "flow": 2
    },
    "by_severity": {
      "suggestion": 3,
      "important": 2,
      "critical": 0
    }
  }
}
```

#### POST /wp-json/ai-feedback/v1/reply

Reply to a note and get AI response.

**Request:**
```json
{
  "note_id": 456,
  "reply_content": "I want to keep this technical because my audience is developers."
}
```

**Response:**
```json
{
  "reply_id": 789,
  "content": "That makes sense for a developer audience. In that case, consider adding a brief code example in the introduction to immediately signal the technical depth. This hooks developers while maintaining your technical tone.",
  "updated_suggestion": "Add a 2-3 line code snippet showing the end result before diving into explanation."
}
```

#### GET /wp-json/ai-feedback/v1/settings

Get current settings.

**Response:**
```json
{
  "default_model": "claude-sonnet-4",
  "default_focus_areas": ["content", "tone", "flow"],
  "default_tone": "professional",
  "available_models": [
    {
      "id": "claude-sonnet-4",
      "name": "Claude Sonnet 4",
      "provider": "anthropic"
    },
    {
      "id": "gpt-4o",
      "name": "GPT-4o",
      "provider": "openai"
    }
  ]
}
```

#### POST /wp-json/ai-feedback/v1/settings

Update settings.

**Request:**
```json
{
  "default_model": "gpt-4o",
  "default_focus_areas": ["content", "design"],
  "default_tone": "casual"
}
```

### JavaScript API

```javascript
// Using the data store
import { useSelect, useDispatch } from '@wordpress/data';
import { store as aiFeedbackStore } from '@jelix/ai-feedback';

function ReviewPanel() {
    const { isReviewing, lastReview, settings } = useSelect( ( select ) => ( {
        isReviewing: select( aiFeedbackStore ).isReviewing(),
        lastReview: select( aiFeedbackStore ).getLastReview(),
        settings: select( aiFeedbackStore ).getSettings(),
    } ) );

    const { startReview, updateSettings } = useDispatch( aiFeedbackStore );

    const handleReview = async () => {
        await startReview( {
            postId: getCurrentPostId(),
            model: settings.defaultModel,
            focusAreas: settings.defaultFocusAreas,
        } );
    };

    return (
        <Button
            isPrimary
            isBusy={ isReviewing }
            onClick={ handleReview }
        >
            { isReviewing ? 'Reviewing...' : 'Review Document' }
        </Button>
    );
}
```

---

## Security Considerations

### Authentication & Authorization

1. **Capability Checks** - All endpoints require `edit_post` capability for the target post
2. **Nonce Verification** - REST API uses WordPress nonce system
3. **Rate Limiting** - Implement per-user rate limits to prevent API abuse

```php
class Review_Controller extends WP_REST_Controller {
    public function get_item_permissions_check( $request ) {
        $post_id = $request->get_param( 'post_id' );

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to review this post.', 'ai-feedback' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }
}
```

### Data Sanitization

1. **Input Sanitization** - All user input sanitized before use
2. **Output Escaping** - All output escaped appropriately
3. **AI Response Validation** - Validate AI responses match expected schema

```php
// Sanitize AI response before storing
$feedback_content = wp_kses(
    $ai_response['feedback'],
    array(
        'strong' => array(),
        'em'     => array(),
        'code'   => array(),
    )
);
```

### Privacy

1. **No External Data Storage** - All data stored in WordPress database
2. **AI Provider Privacy** - Clearly document that content is sent to AI providers
3. **Data Minimization** - Only send necessary content to AI APIs
4. **Opt-in Required** - Users must explicitly initiate reviews

---

## Performance Considerations

### Optimization Strategies

1. **Streaming Responses** - Use streaming for long documents to show progress
2. **Block Batching** - Process blocks in chunks for very long documents
3. **Caching** - Cache available models list and settings
4. **Lazy Loading** - Load review history on demand

```php
// Streaming implementation
class Streaming_Review_Service {
    public function stream_review( int $post_id, callable $on_chunk ): void {
        $blocks = $this->get_blocks( $post_id );

        AiClient::prompt( $this->build_prompt( $blocks ) )
            ->usingSystemInstruction( $this->get_system_instruction() )
            ->stream( function( $chunk ) use ( $on_chunk ) {
                $on_chunk( $chunk );
            } );
    }
}
```

### Resource Limits

| Resource | Limit | Handling |
|----------|-------|----------|
| Max blocks per review | 100 | Show warning, allow override |
| Max characters per block | 5000 | Truncate with notice |
| Review timeout | 60 seconds | Show timeout error |
| Concurrent reviews per user | 1 | Queue additional requests |

---

## Testing Strategy

See [TESTING.md](./docs/TESTING.md) for comprehensive testing documentation.

### Test Types

| Type | Coverage Target | Tools |
|------|-----------------|-------|
| Unit Tests (PHP) | 90% | PHPUnit, WP_Mock |
| Unit Tests (JS) | 90% | Jest, React Testing Library |
| Integration Tests | 80% | PHPUnit with WP test suite |
| E2E Tests | Critical paths | Playwright |
| Visual Regression | UI components | Playwright + Percy |

---

## Dependencies

### Required

| Dependency | Version | Purpose |
|------------|---------|---------|
| WordPress | 6.9+ | Notes API, Abilities API |
| PHP | 8.0+ | Language features |
| [WordPress AI Experiments plugin](https://wordpress.org/plugins/ai/) | Latest | AI settings management, API key configuration |
| wordpress/php-ai-client | 0.3+ | AI provider abstraction |

### Development

| Dependency | Purpose |
|------------|---------|
| @wordpress/scripts | Build tooling |
| @wordpress/env | Local development |
| PHPUnit | PHP testing |
| Playwright | E2E testing |
| Percy | Visual regression |

---

## Rollout Plan

### Phase 1: Foundation (v0.1.0)

- [ ] Plugin scaffold with build system
- [ ] Plugin sidebar UI
- [ ] Basic settings management
- [ ] PHP AI Client integration
- [ ] Simple review endpoint (no Notes yet)

### Phase 2: Notes Integration (v0.2.0)

- [ ] Notes creation from AI feedback
- [ ] Block highlighting for notes
- [ ] Note resolution tracking
- [ ] Review history

### Phase 3: Conversation (v0.3.0)

- [ ] Reply handling
- [ ] Context-aware AI responses
- [ ] Conversation threading

### Phase 4: Polish (v1.0.0)

- [ ] Abilities API integration
- [ ] Streaming responses
- [ ] Performance optimization
- [ ] Accessibility audit
- [ ] Documentation

---

## Appendix

### A. Glossary

| Term | Definition |
|------|------------|
| Note | A comment attached to a specific block in WordPress 6.9+ |
| Review | A complete AI analysis of a document |
| Feedback Item | A single piece of feedback within a review |
| Focus Area | Category of feedback (content, tone, flow, design) |

### B. References

- [Notes Feature in WordPress 6.9](https://make.wordpress.org/core/2025/11/15/notes-feature-in-wordpress-6-9/)
- [Abilities API in WordPress 6.9](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/)
- [WordPress PHP AI Client](https://github.com/WordPress/php-ai-client)
- [Block Editor Handbook](https://developer.wordpress.org/block-editor/)
- [PluginSidebar Documentation](https://developer.wordpress.org/block-editor/reference-guides/slotfills/plugin-sidebar/)
