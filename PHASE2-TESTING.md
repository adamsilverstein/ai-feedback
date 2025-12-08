# Phase 2 Testing Guide

## Overview

Phase 2 implements the AI integration layer, making document reviews functional. The review button now triggers actual AI analysis (or mock responses for testing).

## What's New

### Backend Classes Created

1. **Prompt Builder** (`includes/class-prompt-builder.php`)
   - Constructs AI prompts from document blocks
   - Includes focus area-specific instructions
   - Defines JSON output schema

2. **Response Parser** (`includes/class-response-parser.php`)
   - Validates and sanitizes AI responses
   - Maps feedback to block indices
   - Generates review summaries

3. **Review Service** (`includes/class-review-service.php`)
   - Orchestrates the review process
   - Integrates with PHP AI Client
   - Handles errors and rate limiting
   - Provides mock mode for testing

4. **Review Controller** (`includes/class-review-controller.php`)
   - Exposes `POST /ai-feedback/v1/review` endpoint
   - Validates permissions
   - Implements rate limiting (10 reviews/hour)

## Testing Without AI API Keys (Mock Mode)

The plugin includes a mock mode for testing without API keys:

### Enable Mock Mode

Add to `wp-config.php`:

```php
define( 'AI_FEEDBACK_MOCK_MODE', true );
```

### Mock Response Behavior

- Returns 3 sample feedback items for the first 3 blocks
- No API calls made
- Instant responses
- Perfect for testing UI and data flow

## Testing With Real AI

### Prerequisites

1. **Install Dependencies**
   ```bash
   composer install
   npm install
   ```

2. **Configure AI Provider**

   Add API key to `wp-config.php`:

   **For Anthropic (Claude):**
   ```php
   define( 'ANTHROPIC_API_KEY', 'your-api-key-here' );
   ```

   **For OpenAI (GPT-4):**
   ```php
   define( 'OPENAI_API_KEY', 'your-api-key-here' );
   ```

   **For Google (Gemini):**
   ```php
   define( 'GOOGLE_API_KEY', 'your-api-key-here' );
   ```

3. **Build Assets**
   ```bash
   npm run build
   ```

### Testing Steps

1. **Start WordPress Environment**
   ```bash
   npm run env:start
   ```

2. **Access Editor**
   - Log in to WordPress
   - Create or edit a post
   - Add some content blocks (paragraphs, headings, etc.)

3. **Open AI Feedback Sidebar**
   - Click the three dots menu in editor toolbar
   - Select "AI Feedback"
   - Sidebar opens on right

4. **Configure Settings**
   - Select AI model (default: Claude Sonnet 4)
   - Choose focus areas (Content, Tone, Flow, Design)
   - Select target tone (Professional, Casual, Academic, Friendly)

5. **Request Review**
   - Click "Review Document" button
   - Button shows "Reviewing..." loading state
   - Wait for AI response (10-30 seconds typical)

6. **View Results**
   - Review Summary component displays results
   - Shows total notes and breakdown by category/severity
   - Notes data logged to console for inspection

## REST API Testing

### Using cURL

```bash
# Get current settings
curl -X GET "http://localhost:8888/wp-json/ai-feedback/v1/settings" \
  -H "X-WP-Nonce: YOUR_NONCE"

# Start a review
curl -X POST "http://localhost:8888/wp-json/ai-feedback/v1/review" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "post_id": 1,
    "model": "claude-sonnet-4-20250514",
    "focus_areas": ["content", "tone", "flow"],
    "target_tone": "professional"
  }'
```

### Expected Response

```json
{
  "review_id": "550e8400-e29b-41d4-a716-446655440000",
  "post_id": 1,
  "model": "claude-sonnet-4-20250514",
  "notes": [
    {
      "block_index": 0,
      "block_id": "abc123",
      "category": "content",
      "severity": "suggestion",
      "title": "Add context for readers",
      "feedback": "Consider adding a sentence explaining why this matters...",
      "suggestion": "Start with the problem before the solution"
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
    },
    "has_critical": false
  },
  "timestamp": "2025-12-06 20:43:00"
}
```

## Debugging

### Enable WordPress Debug Mode

Add to `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

### Check Logs

```bash
# PHP errors
tail -f wp-content/debug.log

# Browser console
# Open DevTools > Console tab
# Look for "Review failed:" or other errors
```

### Common Issues

1. **"PHP AI Client library is not installed"**
   - Run: `composer install`
   - Verify `vendor/autoload.php` exists

2. **"AI request failed: Invalid API key"**
   - Check API key is set in `wp-config.php`
   - Verify correct constant name for provider

3. **"Rate limit exceeded"**
   - Wait 1 hour or clear user meta:
   ```php
   delete_user_meta( $user_id, 'ai_feedback_reviews' );
   ```

4. **Review button does nothing**
   - Check browser console for errors
   - Verify post is saved (post_id exists)
   - Check REST API is accessible

5. **Empty response or parse error**
   - AI may have returned invalid JSON
   - Check PHP error log for details
   - Try mock mode to verify data flow

## Rate Limiting

- **Limit:** 10 reviews per hour per user
- **Storage:** User meta (`ai_feedback_reviews`)
- **Reset:** Automatic after 1 hour

### Check Rate Limit Status

```php
$user_id = get_current_user_id();
$reviews = get_user_meta( $user_id, 'ai_feedback_reviews', true );
$count = is_array( $reviews ) ? count( $reviews ) : 0;
echo "Reviews used: $count / 10";
```

## Browser Testing Checklist

- [ ] Review button appears in sidebar
- [ ] Button disabled when no post_id
- [ ] Loading state shows during review
- [ ] Review Summary displays results
- [ ] Settings persist after reload
- [ ] Model selector works
- [ ] Focus area checkboxes work
- [ ] Tone selector works
- [ ] Error messages display for failed reviews

## Next Steps (Phase 3)

Once reviews are working:

1. Implement Notes creation from feedback
2. Add block indicators for feedback
3. Create note panel component
4. Add resolution tracking
5. Implement review history

## Support

If you encounter issues:

1. Check this testing guide
2. Review `DESIGN.md` for architecture details
3. Check `docs/API.md` for endpoint specs
4. Enable debug mode and check logs
5. Test with mock mode to isolate issues
