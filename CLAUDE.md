# AI Feedback Plugin

AI-powered editorial feedback for WordPress Gutenberg using the Notes feature (WP 6.9+).

## Workflow

- Prefer WordPress core features/APIs and Gutenberg packages over third-party tools
- Commit related changes together with brief, single-line messages
- Run linting and tests before each commit
- Use feature/bugfix branches; rebase onto main regularly
- Open PRs for review before merging

## Quick Reference

```bash
# Setup
composer install && npm install
npm run env:start

# Development
npm run start       # Watch mode
npm run build       # Production

# Testing
npm run test:unit   # JS unit tests
npm run test:e2e    # Playwright e2e
npm run lint:js     # ESLint
npm run lint:php    # PHPCS + PHPStan
```

## Architecture

```
ai-feedback/
├── ai-feedback.php              # Plugin bootstrap
├── includes/
│   ├── class-plugin.php         # Initialization, hooks registration
│   ├── class-review-service.php # Core review logic, AI integration
│   ├── class-review-controller.php  # REST endpoint for reviews
│   ├── class-notes-manager.php      # Creates WP Notes from feedback
│   ├── class-notes-controller.php   # REST endpoint for notes
│   ├── class-prompt-builder.php     # Builds prompts for AI
│   ├── class-response-parser.php    # Parses AI responses
│   ├── class-settings-controller.php # Settings REST endpoint
│   └── class-logger.php             # Debug logging utility
├── src/
│   ├── index.js                 # Editor script entry
│   ├── index.scss               # Styles
│   ├── components/
│   │   ├── AIFeedbackPanel.js   # Main sidebar panel
│   │   ├── ReviewButton.js      # Trigger review action
│   │   ├── ReviewSummary.js     # Display review results
│   │   ├── SettingsPanel.js     # Model/focus area settings
│   │   ├── ModelSelector.js     # AI model dropdown
│   │   └── EmptyState.js        # No-content placeholder
│   ├── store/                   # Redux-style data store
│   │   ├── index.js
│   │   ├── actions.js
│   │   ├── reducer.js
│   │   └── selectors.js
│   └── utils/
│       └── block-utils.js       # Block content extraction
├── tests/
│   ├── php/                     # PHPUnit tests
│   └── e2e/                     # Playwright tests
└── docs/
    ├── API.md                   # REST API reference
    ├── TESTING.md               # Testing guide
    └── CI.md                    # CI/CD documentation
```

## Key Concepts

### Notes (WordPress 6.9+)

Notes are block-level comments stored in `wp_comments` with `comment_type = 'block_comment'`. The plugin creates notes when AI generates feedback:

```php
// Creating a note (simplified)
wp_insert_comment( [
    'comment_type'    => 'block_comment',
    'comment_post_ID' => $post_id,
    'comment_content' => $feedback,
    'comment_meta'    => [ 'block_id' => $client_id, 'ai_feedback' => true ],
] );
```

### PHP AI Client

Uses `wordpress/php-ai-client` for provider-agnostic AI communication:

```php
use WordPress\AiClient\AiClient;

$response = AiClient::prompt( $prompt )
    ->usingSystemInstruction( $system_prompt )
    ->usingTemperature( 0.3 )
    ->generateText();
```

### Data Flow

1. User clicks "Review Document" in sidebar
2. `ReviewButton` dispatches `startReview` action
3. REST API calls `Review_Controller` → `Review_Service`
4. `Prompt_Builder` constructs prompt, AI generates response
5. `Response_Parser` extracts feedback items
6. `Notes_Manager` creates WP Notes for each item
7. Response returns note IDs mapped to block clientIds
8. Store updates block metadata with noteIds

## Common Tasks

### Adding a Focus Area

1. Add constant to `class-review-service.php`
2. Update `get_focus_area_prompt()` with instructions
3. Add UI option in `SettingsPanel.js`

### Adding a REST Endpoint

1. Create controller in `includes/` following existing patterns
2. Register routes in `rest_api_init` hook
3. Add tests in `tests/php/`

### Modifying Prompts

Edit `class-prompt-builder.php`. Consider:
- Token limits for different models
- Consistent JSON response format
- Localization of user-facing content

## Dependencies

**Runtime:** WordPress 6.9+, PHP 8.1+, [AI Experiments plugin](https://wordpress.org/plugins/ai/)

**Development:** Node 18+, Composer 2+, Docker (for wp-env)

## Code Style

- **PHP:** WordPress Coding Standards, PSR-4 autoloading (`AI_Feedback\` namespace), full type hints
- **JS:** WordPress ESLint config, functional components with hooks

## Security

- Sanitize all input (`sanitize_text_field`, `wp_kses`)
- Escape all output (`esc_html`, `esc_attr`)
- Capability checks on endpoints (`current_user_can`)
- Validate AI responses against expected schema

## Debugging

```php
// PHP: Enable WP_DEBUG in wp-config.php
AI_Feedback\Logger::debug( 'Message', [ 'context' => $data ] );
```

```javascript
// JS: Check browser console
localStorage.setItem( 'AI_FEEDBACK_DEBUG', 'true' );
```

## Resources

- [DESIGN.md](./DESIGN.md) - Architecture decisions
- [docs/TESTING.md](./docs/TESTING.md) - Testing guide
- [docs/API.md](./docs/API.md) - REST API docs
