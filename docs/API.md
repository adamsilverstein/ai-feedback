# API Reference

This document provides complete API documentation for the AI Feedback plugin.

## REST API

Base URL: `/wp-json/ai-feedback/v1/`

All endpoints require authentication via WordPress cookies or application passwords.

---

### POST /review

Initiates an AI review of a document.

#### Request

```http
POST /wp-json/ai-feedback/v1/review
Content-Type: application/json
X-WP-Nonce: {nonce}
```

**Body Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `post_id` | integer | Yes | The post ID to review |
| `model` | string | No | AI model to use (default: site default) |
| `focus_areas` | array | No | Areas to focus on: `content`, `tone`, `flow`, `design` |
| `target_tone` | string | No | Target tone: `professional`, `casual`, `academic`, `friendly` |

**Example Request:**

```json
{
  "post_id": 123,
  "model": "claude-sonnet-4",
  "focus_areas": ["content", "tone", "flow"],
  "target_tone": "professional"
}
```

#### Response

**Success (200 OK):**

```json
{
  "review_id": "550e8400-e29b-41d4-a716-446655440000",
  "notes": [
    {
      "id": 456,
      "block_id": "abc123-def456",
      "block_index": 2,
      "category": "content",
      "severity": "suggestion",
      "title": "Consider adding context",
      "content": "The opening paragraph jumps directly into technical details. Adding a sentence about why this matters could help readers understand the significance.",
      "suggestion": "Start with the problem this solves before explaining the solution."
    },
    {
      "id": 457,
      "block_id": "ghi789-jkl012",
      "block_index": 5,
      "category": "tone",
      "severity": "important",
      "title": "Inconsistent tone",
      "content": "This section shifts to a very casual tone ('super easy', 'just do this') which contrasts with the professional tone used elsewhere.",
      "suggestion": null
    }
  ],
  "summary": {
    "total_notes": 5,
    "by_category": {
      "content": 2,
      "tone": 1,
      "flow": 2,
      "design": 0
    },
    "by_severity": {
      "suggestion": 3,
      "important": 2,
      "critical": 0
    }
  },
  "model_used": "claude-sonnet-4",
  "tokens_used": 1523,
  "created_at": "2025-12-06T14:30:00Z"
}
```

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 400 | `invalid_post_id` | Post ID is missing or invalid |
| 403 | `forbidden` | User cannot edit this post |
| 404 | `post_not_found` | Post does not exist |
| 429 | `rate_limit_exceeded` | Too many review requests |
| 500 | `ai_error` | AI provider returned an error |
| 503 | `ai_unavailable` | AI provider is unavailable |

```json
{
  "code": "forbidden",
  "message": "You do not have permission to review this post.",
  "data": {
    "status": 403
  }
}
```

---

### POST /reply

Submit a reply to an AI feedback note and receive an AI response.

#### Request

```http
POST /wp-json/ai-feedback/v1/reply
Content-Type: application/json
X-WP-Nonce: {nonce}
```

**Body Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `note_id` | integer | Yes | The note (comment) ID to reply to |
| `reply_content` | string | Yes | The user's reply text |

**Example Request:**

```json
{
  "note_id": 456,
  "reply_content": "I want to keep this informal because my target audience is hobbyist developers."
}
```

#### Response

**Success (200 OK):**

```json
{
  "reply_id": 789,
  "content": "That context is helpful! For hobbyist developers, the informal tone works well. You might consider adding a brief note at the beginning indicating this is a casual tutorial - that sets expectations and prevents confusion for readers who stumble upon this from a search engine.",
  "updated_suggestion": "Add a one-line intro like 'This is a quick, no-frills guide for hobby projects.'",
  "created_at": "2025-12-06T14:35:00Z"
}
```

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 400 | `invalid_note_id` | Note ID is missing or invalid |
| 400 | `empty_reply` | Reply content is empty |
| 403 | `forbidden` | User cannot reply to this note |
| 404 | `note_not_found` | Note does not exist |
| 410 | `note_resolved` | Note has already been resolved |

---

### GET /settings

Retrieve current AI Feedback settings.

#### Request

```http
GET /wp-json/ai-feedback/v1/settings
X-WP-Nonce: {nonce}
```

#### Response

**Success (200 OK):**

```json
{
  "default_model": "claude-sonnet-4",
  "default_focus_areas": ["content", "tone", "flow"],
  "default_tone": "professional",
  "available_models": [
    {
      "id": "claude-sonnet-4",
      "name": "Claude Sonnet 4",
      "provider": "anthropic",
      "capabilities": ["text"],
      "max_tokens": 200000
    },
    {
      "id": "claude-opus-4",
      "name": "Claude Opus 4",
      "provider": "anthropic",
      "capabilities": ["text"],
      "max_tokens": 200000
    },
    {
      "id": "gpt-4o",
      "name": "GPT-4o",
      "provider": "openai",
      "capabilities": ["text", "vision"],
      "max_tokens": 128000
    },
    {
      "id": "gemini-2.5-flash",
      "name": "Gemini 2.5 Flash",
      "provider": "google",
      "capabilities": ["text"],
      "max_tokens": 1000000
    }
  ],
  "available_focus_areas": [
    {
      "id": "content",
      "label": "Content Quality",
      "description": "Clarity, accuracy, and completeness"
    },
    {
      "id": "tone",
      "label": "Tone & Voice",
      "description": "Consistency and audience appropriateness"
    },
    {
      "id": "flow",
      "label": "Flow & Structure",
      "description": "Logical progression and transitions"
    },
    {
      "id": "design",
      "label": "Design & Formatting",
      "description": "Block usage and visual hierarchy"
    }
  ],
  "available_tones": [
    { "id": "professional", "label": "Professional" },
    { "id": "casual", "label": "Casual" },
    { "id": "academic", "label": "Academic" },
    { "id": "friendly", "label": "Friendly" }
  ]
}
```

---

### POST /settings

Update AI Feedback settings.

#### Request

```http
POST /wp-json/ai-feedback/v1/settings
Content-Type: application/json
X-WP-Nonce: {nonce}
```

**Body Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `default_model` | string | No | Default AI model ID |
| `default_focus_areas` | array | No | Default focus areas |
| `default_tone` | string | No | Default target tone |

**Example Request:**

```json
{
  "default_model": "gpt-4o",
  "default_focus_areas": ["content", "design"],
  "default_tone": "casual"
}
```

#### Response

**Success (200 OK):**

Returns the updated settings object (same format as GET /settings).

**Error Responses:**

| Status | Error Code | Description |
|--------|------------|-------------|
| 400 | `invalid_model` | Model ID is not valid |
| 400 | `invalid_focus_area` | Focus area is not valid |
| 400 | `invalid_tone` | Tone is not valid |
| 403 | `forbidden` | User cannot manage settings |

---

### GET /reviews

List review history for a post.

#### Request

```http
GET /wp-json/ai-feedback/v1/reviews?post_id=123
X-WP-Nonce: {nonce}
```

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `post_id` | integer | Yes | The post ID |
| `per_page` | integer | No | Results per page (default: 10, max: 100) |
| `page` | integer | No | Page number (default: 1) |

#### Response

**Success (200 OK):**

```json
{
  "reviews": [
    {
      "review_id": "550e8400-e29b-41d4-a716-446655440000",
      "created_at": "2025-12-06T14:30:00Z",
      "model_used": "claude-sonnet-4",
      "focus_areas": ["content", "tone", "flow"],
      "summary": {
        "total_notes": 5,
        "resolved_notes": 3
      }
    },
    {
      "review_id": "660f9500-f3ac-52e5-b827-557766551111",
      "created_at": "2025-12-05T10:15:00Z",
      "model_used": "gpt-4o",
      "focus_areas": ["content"],
      "summary": {
        "total_notes": 3,
        "resolved_notes": 3
      }
    }
  ],
  "total": 8,
  "pages": 1
}
```

---

### POST /notes/{id}/resolve

Resolve (dismiss) a feedback note.

#### Request

```http
POST /wp-json/ai-feedback/v1/notes/456/resolve
X-WP-Nonce: {nonce}
```

#### Response

**Success (200 OK):**

```json
{
  "success": true,
  "note_id": 456,
  "resolved_at": "2025-12-06T15:00:00Z"
}
```

---

### DELETE /notes/{id}

Delete a feedback note entirely.

#### Request

```http
DELETE /wp-json/ai-feedback/v1/notes/456
X-WP-Nonce: {nonce}
```

#### Response

**Success (200 OK):**

```json
{
  "success": true,
  "deleted": true
}
```

---

## WordPress Data Store

The plugin registers a data store at `ai-feedback/store`.

### Selectors

```javascript
import { useSelect } from '@wordpress/data';
import { store as aiFeedbackStore } from '@jelix/ai-feedback';

// In a component:
const data = useSelect( ( select ) => {
    const store = select( aiFeedbackStore );
    return {
        // Review state
        isReviewing: store.isReviewing(),
        lastReview: store.getLastReview(),
        reviewHistory: store.getReviewHistory(),
        reviewError: store.getReviewError(),

        // Settings
        settings: store.getSettings(),
        availableModels: store.getAvailableModels(),
        selectedModel: store.getSelectedModel(),
        focusAreas: store.getFocusAreas(),
        targetTone: store.getTargetTone(),

        // Notes
        notesByBlockId: store.getNotesByBlockId( blockId ),
        unresolvedNotesCount: store.getUnresolvedNotesCount(),
        allNotes: store.getAllNotes(),
    };
} );
```

#### Selector Reference

| Selector | Return Type | Description |
|----------|-------------|-------------|
| `isReviewing()` | boolean | True if review is in progress |
| `getLastReview()` | object\|null | Most recent review result |
| `getReviewHistory()` | array | All reviews for current post |
| `getReviewError()` | Error\|null | Last review error |
| `getSettings()` | object | Current settings |
| `getAvailableModels()` | array | List of available AI models |
| `getSelectedModel()` | string | Currently selected model ID |
| `getFocusAreas()` | array | Selected focus areas |
| `getTargetTone()` | string | Selected target tone |
| `getNotesByBlockId(blockId)` | array | Notes for a specific block |
| `getUnresolvedNotesCount()` | number | Count of unresolved notes |
| `getAllNotes()` | array | All notes for current post |
| `isLoadingSettings()` | boolean | True if settings are loading |

### Actions

```javascript
import { useDispatch } from '@wordpress/data';
import { store as aiFeedbackStore } from '@jelix/ai-feedback';

// In a component:
const {
    startReview,
    updateSettings,
    resolveNote,
    deleteNote,
    replyToNote,
    fetchSettings,
    clearError,
} = useDispatch( aiFeedbackStore );
```

#### Action Reference

| Action | Parameters | Description |
|--------|------------|-------------|
| `startReview(options)` | `{ postId, model?, focusAreas?, targetTone? }` | Start document review |
| `updateSettings(settings)` | `{ defaultModel?, defaultFocusAreas?, defaultTone? }` | Update settings |
| `resolveNote(noteId)` | `number` | Mark note as resolved |
| `deleteNote(noteId)` | `number` | Delete a note |
| `replyToNote(noteId, content)` | `number, string` | Reply to a note |
| `fetchSettings()` | none | Refresh settings from server |
| `clearError()` | none | Clear any error state |

### Usage Example

```javascript
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { store as aiFeedbackStore } from '@jelix/ai-feedback';
import { store as editorStore } from '@wordpress/editor';

function AIFeedbackPanel() {
    const postId = useSelect(
        ( select ) => select( editorStore ).getCurrentPostId()
    );

    const { isReviewing, lastReview, settings, reviewError } = useSelect(
        ( select ) => ( {
            isReviewing: select( aiFeedbackStore ).isReviewing(),
            lastReview: select( aiFeedbackStore ).getLastReview(),
            settings: select( aiFeedbackStore ).getSettings(),
            reviewError: select( aiFeedbackStore ).getReviewError(),
        } )
    );

    const { startReview, fetchSettings } = useDispatch( aiFeedbackStore );

    useEffect( () => {
        fetchSettings();
    }, [] );

    const handleReview = async () => {
        try {
            await startReview( {
                postId,
                model: settings.defaultModel,
                focusAreas: settings.defaultFocusAreas,
                targetTone: settings.defaultTone,
            } );
        } catch ( error ) {
            console.error( 'Review failed:', error );
        }
    };

    return (
        <div className="ai-feedback-panel">
            { reviewError && (
                <Notice status="error">{ reviewError.message }</Notice>
            ) }

            <Button
                isPrimary
                isBusy={ isReviewing }
                disabled={ isReviewing || ! postId }
                onClick={ handleReview }
            >
                { isReviewing ? 'Reviewing...' : 'Review Document' }
            </Button>

            { lastReview && (
                <ReviewSummary review={ lastReview } />
            ) }
        </div>
    );
}
```

---

## PHP Hooks

### Filters

#### `ai_feedback_system_instruction`

Modify the system instruction sent to the AI.

```php
add_filter( 'ai_feedback_system_instruction', function( string $instruction ): string {
    return $instruction . "\n\nAlways be encouraging and positive.";
} );
```

#### `ai_feedback_review_prompt`

Modify the review prompt before sending to AI.

```php
add_filter( 'ai_feedback_review_prompt', function( string $prompt, array $blocks, array $options ): string {
    // Add custom context
    return $prompt . "\n\nAdditional context: This is a technical blog.";
}, 10, 3 );
```

#### `ai_feedback_parse_response`

Modify the parsed AI response.

```php
add_filter( 'ai_feedback_parse_response', function( array $notes, string $raw_response ): array {
    // Filter out low-priority suggestions
    return array_filter( $notes, function( $note ) {
        return $note['severity'] !== 'suggestion';
    } );
}, 10, 2 );
```

#### `ai_feedback_available_models`

Modify the list of available AI models.

```php
add_filter( 'ai_feedback_available_models', function( array $models ): array {
    // Add a custom model
    $models[] = array(
        'id'           => 'custom/model',
        'name'         => 'Custom Model',
        'provider'     => 'custom',
        'capabilities' => array( 'text' ),
    );
    return $models;
} );
```

#### `ai_feedback_note_content`

Modify note content before saving.

```php
add_filter( 'ai_feedback_note_content', function( string $content, array $note_data ): string {
    // Add severity badge
    $badge = match( $note_data['severity'] ) {
        'critical'  => '🔴',
        'important' => '🟡',
        default     => '🟢',
    };
    return $badge . ' ' . $content;
}, 10, 2 );
```

#### `ai_feedback_temperature`

Modify the AI temperature setting.

```php
add_filter( 'ai_feedback_temperature', function( float $temperature, string $context ): float {
    // Higher temperature for creative content
    if ( has_category( 'creative-writing' ) ) {
        return 0.7;
    }
    return $temperature;
}, 10, 2 );
```

### Actions

#### `ai_feedback_before_review`

Fires before a review starts.

```php
add_action( 'ai_feedback_before_review', function( int $post_id, array $options ): void {
    // Log review start
    error_log( "Starting review for post {$post_id}" );
}, 10, 2 );
```

#### `ai_feedback_after_review`

Fires after a review completes.

```php
add_action( 'ai_feedback_after_review', function( int $post_id, array $result ): void {
    // Send notification if critical issues found
    $critical = array_filter( $result['notes'], fn( $n ) => $n['severity'] === 'critical' );
    if ( count( $critical ) > 0 ) {
        wp_mail(
            get_option( 'admin_email' ),
            'Critical content issues found',
            "Post {$post_id} has " . count( $critical ) . " critical issues."
        );
    }
}, 10, 2 );
```

#### `ai_feedback_note_created`

Fires when a note is created.

```php
add_action( 'ai_feedback_note_created', function( int $note_id, array $note_data, int $post_id ): void {
    // Track note creation
    do_action( 'my_analytics_event', 'ai_note_created', array(
        'post_id'  => $post_id,
        'category' => $note_data['category'],
        'severity' => $note_data['severity'],
    ) );
}, 10, 3 );
```

#### `ai_feedback_note_resolved`

Fires when a note is resolved.

```php
add_action( 'ai_feedback_note_resolved', function( int $note_id, int $post_id ): void {
    // Update resolved count in post meta
    $count = (int) get_post_meta( $post_id, '_ai_feedback_resolved_count', true );
    update_post_meta( $post_id, '_ai_feedback_resolved_count', $count + 1 );
}, 10, 2 );
```

#### `ai_feedback_reply_received`

Fires when a user replies to a note.

```php
add_action( 'ai_feedback_reply_received', function( int $note_id, string $reply_content, int $user_id ): void {
    // Log user engagement
    error_log( "User {$user_id} replied to note {$note_id}" );
}, 10, 3 );
```

---

## Abilities API

The plugin registers abilities for integration with WordPress's Abilities API.

### ai-feedback/review-document

Review a document and provide AI feedback.

```php
// Execute via Abilities API
$result = wp_execute_ability( 'ai-feedback/review-document', array(
    'post_id'     => 123,
    'focus_areas' => array( 'content', 'tone' ),
) );
```

**Input Schema:**

```json
{
  "type": "object",
  "properties": {
    "post_id": {
      "type": "integer",
      "description": "The post ID to review",
      "required": true
    },
    "focus_areas": {
      "type": "array",
      "items": { "type": "string" },
      "description": "Areas to focus feedback on"
    },
    "target_tone": {
      "type": "string",
      "description": "Target tone for the content"
    }
  }
}
```

**Output Schema:**

```json
{
  "type": "object",
  "properties": {
    "review_id": { "type": "string" },
    "notes_count": { "type": "integer" },
    "summary": {
      "type": "object",
      "properties": {
        "by_category": { "type": "object" },
        "by_severity": { "type": "object" }
      }
    }
  }
}
```

### ai-feedback/get-notes

Retrieve notes for a post.

```php
$notes = wp_execute_ability( 'ai-feedback/get-notes', array(
    'post_id' => 123,
    'status'  => 'unresolved',
) );
```

### ai-feedback/resolve-note

Resolve a specific note.

```php
wp_execute_ability( 'ai-feedback/resolve-note', array(
    'note_id' => 456,
) );
```

---

## Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `invalid_post_id` | 400 | Post ID is missing or not a valid integer |
| `invalid_note_id` | 400 | Note ID is missing or not a valid integer |
| `invalid_model` | 400 | Specified model is not available |
| `invalid_focus_area` | 400 | Focus area is not recognized |
| `invalid_tone` | 400 | Tone is not recognized |
| `empty_reply` | 400 | Reply content is empty |
| `forbidden` | 403 | User lacks required capability |
| `post_not_found` | 404 | Post does not exist |
| `note_not_found` | 404 | Note does not exist |
| `note_resolved` | 410 | Note has already been resolved |
| `rate_limit_exceeded` | 429 | Too many requests in time window |
| `ai_error` | 500 | AI provider returned an error |
| `ai_timeout` | 504 | AI provider request timed out |
| `ai_unavailable` | 503 | AI provider is unavailable |

---

## Rate Limits

| Endpoint | Limit | Window |
|----------|-------|--------|
| POST /review | 10 requests | 1 hour |
| POST /reply | 30 requests | 1 hour |
| GET /settings | 60 requests | 1 minute |
| POST /settings | 10 requests | 1 minute |

Rate limit headers are included in responses:

```http
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 7
X-RateLimit-Reset: 1701874200
```

---

## Changelog

### 1.0.0

- Initial release
- Document review with AI feedback
- Notes integration with WordPress 6.9
- Reply to feedback notes
- Abilities API integration
- Support for multiple AI providers
