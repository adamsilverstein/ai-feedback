# Phase 3: Notes Integration

## Overview

Phase 3 integrates WordPress 6.9's Notes feature, converting AI feedback into actual WordPress Notes that appear on blocks in the editor. Users can now see feedback directly on their content with visual indicators.

## What Was Built

### 1. Notes Manager (`includes/class-notes-manager.php`)

Complete note management system for AI feedback:

**Core Features:**
- Creates WordPress Notes via `wp_insert_comment()` with `comment_type = 'block_comment'`
- Formats note content with titles, feedback, suggestions, and badges
- Associates notes with specific blocks using block IDs
- Stores comprehensive metadata (category, severity, review ID, AI model)
- Provides note retrieval, resolution tracking, and deletion

**Key Methods:**
- `create_notes_from_feedback()` - Batch create notes from AI feedback
- `get_notes_for_post()` - Retrieve all notes for a post
- `get_notes_by_review()` - Get notes from a specific review
- `resolve_note()` / `unresolve_note()` - Mark notes as resolved
- `delete_notes_by_review()` - Clean up notes by review ID

**Note Structure:**
```php
array(
    'comment_post_ID'  => $post_id,
    'comment_type'     => 'block_comment',
    'comment_content'  => $formatted_content,
    'comment_author'   => 'AI Feedback',
    'comment_meta'     => array(
        'ai_feedback'        => true,
        'feedback_category'  => 'content|tone|flow|design',
        'feedback_severity'  => 'suggestion|important|critical',
        'block_id'           => $block_client_id,
        'review_id'          => $review_uuid,
        'ai_model'           => 'claude-sonnet-4',
    ),
)
```

### 2. Notes Controller (`includes/class-notes-controller.php`)

REST API endpoints for note management:

**Endpoints:**
- `GET /ai-feedback/v1/notes/post/{post_id}` - Get notes for a post
  - Optional: `?ai_only=true` to filter AI-generated notes
- `GET /ai-feedback/v1/notes/review/{review_id}` - Get notes by review ID
- `POST /ai-feedback/v1/notes/{note_id}/resolve` - Mark note as resolved
- `POST /ai-feedback/v1/notes/{note_id}/unresolve` - Mark note as unresolved
- `DELETE /ai-feedback/v1/notes/review/{review_id}` - Delete all notes from a review

**Permissions:**
- View notes: `edit_posts` capability
- Update notes: `edit_posts` capability
- Delete notes: `delete_posts` capability

### 3. Review Service Integration

Updated Review Service to automatically create notes after AI analysis:

**Changes:**
- Instantiates Notes Manager
- Calls `create_notes_from_feedback()` after parsing AI response
- Returns `note_ids` array in review response
- Gracefully handles note creation failures (still returns feedback data)
- Includes `notes_error` in response if creation fails

**Response Structure:**
```json
{
  "review_id": "uuid",
  "post_id": 123,
  "model": "claude-sonnet-4",
  "notes": [...],
  "note_ids": [456, 457, 458],
  "summary": {...},
  "timestamp": "2025-12-06 20:50:00"
}
```

### 4. Frontend Updates

**Review Summary Component:**
- Shows count of created WordPress Notes
- Displays success message with note count
- Shows warning if note creation failed
- Provides user guidance about where to find notes

## How It Works

### Complete Flow

1. **User triggers review** → Frontend calls `POST /ai-feedback/v1/review`
2. **Backend analyzes content** → AI returns structured feedback
3. **Response parsed** → Feedback validated and sanitized
4. **Notes created** → Each feedback item becomes a WordPress Note
5. **Response returned** → Frontend receives review data + note IDs
6. **UI updates** → Summary shows note creation status
7. **Notes appear** → WordPress displays note indicators on blocks

### Note Format Example

When AI provides feedback like:
```json
{
  "block_index": 0,
  "category": "content",
  "severity": "important",
  "title": "Add context for readers",
  "feedback": "The opening jumps into details without explanation.",
  "suggestion": "Start with why this matters before how it works."
}
```

It becomes a Note with content:
```
**Add context for readers**

The opening jumps into details without explanation.

*Suggestion:* Start with why this matters before how it works.

<span class="ai-feedback-badge ai-feedback-category-content">Content</span>
<span class="ai-feedback-badge ai-feedback-severity-important">Important</span>
```

## Database Storage

### Comment Table
Notes are stored as WordPress comments with:
- `comment_type = 'block_comment'`
- `comment_author = 'AI Feedback'`
- `user_id = 0` (system-generated)

### Comment Meta
Metadata stored with each note:
- `ai_feedback` - Boolean flag (always true)
- `feedback_category` - content, tone, flow, or design
- `feedback_severity` - suggestion, important, or critical
- `block_id` - Block client ID for association
- `block_index` - Block position in document
- `review_id` - UUID linking notes from same review
- `ai_model` - Model used for feedback
- `ai_feedback_resolved` - Resolution status (optional)

## Testing Phase 3

### Quick Test

```bash
# Enable mock mode
# Add to wp-config.php: define( 'AI_FEEDBACK_MOCK_MODE', true );

# Build and start
npm run build
npm run env:start

# In WordPress editor:
# 1. Create/edit a post with content
# 2. Open AI Feedback sidebar
# 3. Click "Review Document"
# 4. Check Review Summary for "Created X WordPress Notes"
# 5. Look for note indicators on blocks in editor
```

### Verify Notes Created

```php
// Check notes in database
$notes = get_comments( array(
    'post_id' => $post_id,
    'type'    => 'block_comment',
    'meta_query' => array(
        array(
            'key'   => 'ai_feedback',
            'value' => '1',
        ),
    ),
) );

foreach ( $notes as $note ) {
    echo "Note ID: {$note->comment_ID}\n";
    echo "Block ID: " . get_comment_meta( $note->comment_ID, 'block_id', true ) . "\n";
    echo "Category: " . get_comment_meta( $note->comment_ID, 'feedback_category', true ) . "\n";
    echo "Content: {$note->comment_content}\n\n";
}
```

### REST API Testing

```bash
# Get notes for a post
curl "http://localhost:8888/wp-json/ai-feedback/v1/notes/post/123" \
  -H "X-WP-Nonce: YOUR_NONCE"

# Get notes by review ID
curl "http://localhost:8888/wp-json/ai-feedback/v1/notes/review/uuid-here" \
  -H "X-WP-Nonce: YOUR_NONCE"

# Resolve a note
curl -X POST "http://localhost:8888/wp-json/ai-feedback/v1/notes/456/resolve" \
  -H "X-WP-Nonce: YOUR_NONCE"

# Delete notes from a review
curl -X DELETE "http://localhost:8888/wp-json/ai-feedback/v1/notes/review/uuid-here" \
  -H "X-WP-Nonce: YOUR_NONCE"
```

## Known Limitations

1. **Visual Indicators Not Implemented**
   - Notes are created but block indicators need JavaScript integration
   - WordPress 6.9 should display notes natively (verify in WP 6.9+)

2. **No Note Panel UI**
   - Can view notes via WordPress Comments UI
   - Custom note panel component not yet built (Phase 3 future enhancement)

3. **No Review History**
   - Past reviews not displayed in sidebar yet
   - Can query via REST API: `GET /notes/post/{id}`

## Next Steps (Future Enhancements)

### Block Indicators (JavaScript)
```javascript
// Hook into block editor to show indicators
import { useBlockComments } from '@wordpress/editor';

function BlockWithFeedback( { blockId } ) {
    const { comments } = useBlockComments( blockId );
    const aiFeedback = comments.filter( c => c.meta.ai_feedback );

    return aiFeedback.length > 0 ? (
        <Badge count={aiFeedback.length} />
    ) : null;
}
```

### Note Panel Component
- Display full note details
- Show category and severity with styling
- Resolution actions (resolve/unresolve)
- Link to related review
- Reply to notes (Phase 4)

### Review History
- List past reviews with timestamps
- Filter by date, model, focus areas
- View notes from previous reviews
- Re-run reviews with same settings

## Files Created/Modified

### New Files (2)
```
includes/class-notes-manager.php        - Note management system
includes/class-notes-controller.php     - REST API endpoints
```

### Modified Files (3)
```
includes/class-review-service.php       - Integrated note creation
includes/class-plugin.php               - Registered Notes Controller
src/components/ReviewSummary.js         - Show note creation status
```

## API Reference

See REST API endpoints documentation:
- Phase 2: Review endpoints
- Phase 3: Note management endpoints

Full API documentation: `docs/API.md`

## Troubleshooting

### Notes Not Appearing

1. **Check WordPress version** - Requires WP 6.9+ for Notes feature
2. **Verify note creation** - Check Review Summary for "Created X Notes"
3. **Check database** - Query `wp_comments` table for `comment_type='block_comment'`
4. **Review logs** - Enable `WP_DEBUG_LOG` and check for errors

### Note Creation Fails

If Review Summary shows `notes_error`:
- Verify post ID is valid
- Check user has `edit_post` capability
- Ensure blocks have metadata with IDs
- Review PHP error logs

### Can't See Note Indicators

- Requires WordPress 6.9+ for native support
- Check browser console for JavaScript errors
- Verify blocks have proper client IDs
- May need JavaScript integration (not yet implemented)

## Performance Considerations

- Notes created in batch (single transaction per review)
- Metadata indexed by WordPress for fast queries
- Rate limiting prevents excessive note creation
- Old notes can be bulk deleted by review ID

## Security

- All note content sanitized with `wp_kses()`
- Permissions checked for all operations
- Block IDs validated before association
- Review IDs use UUID v4 for uniqueness

---

**Phase 3 Status: Backend Complete ✅**

Note creation and management fully functional. Visual indicators and note panel UI are future enhancements that can leverage WordPress 6.9's built-in Notes display.
