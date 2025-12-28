# AI Feedback Plugin - Development Guide

This document provides context for AI assistants (like Claude) working on this codebase.

## Project Overview

AI Feedback is a WordPress plugin that provides AI-powered editorial feedback in the Gutenberg editor using WordPress 6.9's Notes feature. The plugin integrates with the PHP AI Client for provider-agnostic AI communication.

## Quick Reference

```bash
# Install dependencies
composer install
npm install

# Start development environment
npm run env:start

# Build assets
npm run build        # Production build
npm run start        # Development with watch

# Run tests
npm run test:unit    # JavaScript unit tests
npm run test:php     # PHP unit tests
npm run test:e2e     # End-to-end tests
npm run test:visual  # Visual regression tests

# Linting
npm run lint:js      # ESLint
npm run lint:php     # PHPCS
npm run lint:css     # Stylelint
```

## Architecture Overview

```
ai-feedback/
├── ai-feedback.php           # Main plugin file
├── includes/                  # PHP classes
│   ├── class-plugin.php      # Bootstrap
│   ├── class-review-service.php
│   ├── class-reply-service.php
│   ├── class-prompt-builder.php
│   ├── class-notes-manager.php
│   ├── class-settings-controller.php
│   ├── class-review-controller.php
│   └── class-abilities-provider.php
├── src/                       # JavaScript/React source
│   ├── index.js              # Entry point
│   ├── store/                # WordPress data store
│   ├── components/           # React components
│   └── hooks/                # Custom React hooks
├── build/                     # Compiled assets (gitignored)
├── tests/
│   ├── php/                  # PHPUnit tests
│   ├── js/                   # Jest tests
│   ├── e2e/                  # Playwright tests
│   └── visual/               # Visual regression tests
└── docs/                      # Documentation
```

## Key Concepts

### Notes API (WordPress 6.9+)

Notes are block-level comments stored in the `wp_comments` table with `comment_type = 'block_comment'`. The plugin creates notes programmatically when AI feedback is generated.

```php
// Creating a note
wp_insert_comment( array(
    'comment_type'    => 'block_comment',
    'comment_post_ID' => $post_id,
    'comment_content' => $feedback,
    'comment_meta'    => array(
        'block_id'    => $block_client_id,
        'ai_feedback' => true,
    ),
) );
```

```javascript
// JavaScript: Working with notes
import { useBlockComments } from '@wordpress/editor';
const { comments, addComment } = useBlockComments( blockId );
```

### Abilities API (WordPress 6.9+)

The plugin registers abilities for discoverability by other tools and AI agents:

```php
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability( 'ai-feedback/review-document', array(
        'label'             => __( 'Review Document with AI', 'ai-feedback' ),
        'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        'execute_callback'  => array( Review_Service::class, 'execute_review' ),
    ) );
} );
```

### PHP AI Client

Provider-agnostic AI communication:

```php
use WordPress\AiClient\AiClient;

$response = AiClient::prompt( $prompt )
    ->usingSystemInstruction( $system_prompt )
    ->usingTemperature( 0.3 )
    ->generateText();
```

## Common Tasks

### Adding a New Focus Area

1. Add constant to `includes/class-review-service.php`:
   ```php
   const FOCUS_ACCESSIBILITY = 'accessibility';
   ```

2. Update `get_focus_area_prompt()` method with instructions for this area

3. Add UI option in `src/components/SettingsPanel.js`

4. Add tests in `tests/php/test-review-service.php` and `tests/js/SettingsPanel.test.js`

### Adding a New REST Endpoint

1. Create controller class in `includes/` following `class-review-controller.php` pattern

2. Register routes in `rest_api_init` hook

3. Add integration tests in `tests/php/integration/`

4. Document in `docs/API.md`

### Modifying the Prompt

The prompt is built in `class-prompt-builder.php`. Key methods:

- `build_review_prompt()` - Main document review prompt
- `build_reply_prompt()` - Context-aware reply prompt
- `get_system_instruction()` - System prompt defining AI behavior

When modifying prompts, update the corresponding tests and consider:
- Token limits for different models
- Response format consistency (JSON schema)
- Localization of user-facing parts

## Testing Guidelines

### Unit Tests

- PHP: Use `WP_Mock` for WordPress function mocking
- JavaScript: Use `@testing-library/react` for component tests
- Mock AI responses, don't make real API calls

### E2E Tests

Located in `tests/e2e/`. Use Playwright with WordPress test utils:

```javascript
test( 'can initiate review from sidebar', async ( { page, admin } ) => {
    await admin.createNewPost();
    await page.getByRole( 'button', { name: 'AI Feedback' } ).click();
    await page.getByRole( 'button', { name: 'Review Document' } ).click();
    await expect( page.getByText( 'Reviewing...' ) ).toBeVisible();
} );
```

### Visual Regression

Tests in `tests/visual/` capture and compare UI states:

```javascript
test( 'sidebar default state', async ( { page } ) => {
    await page.goto( '/wp-admin/post-new.php' );
    await openAIFeedbackSidebar( page );
    await expect( page ).toHaveScreenshot( 'sidebar-default.png' );
} );
```

## Code Style

### PHP

- WordPress Coding Standards
- PSR-4 autoloading with `AI_Feedback\` namespace
- Type hints on all function parameters and returns

### JavaScript

- WordPress ESLint configuration
- Functional components with hooks
- TypeScript JSDoc annotations for type safety

## Dependencies

### Required at Runtime

- WordPress 6.9+
- PHP 8.1+
- [WordPress AI Experiments plugin](https://wordpress.org/plugins/ai/)
- `wordpress/php-ai-client` (Composer)

### Development

- Node.js 18+
- npm 9+
- Composer 2+
- Docker (for wp-env)

## Environment Variables

For local development, create `.env`:

```env
# AI Provider credentials (for testing with real APIs)
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...

# Test configuration
WP_TESTS_DOMAIN=localhost
WP_TESTS_EMAIL=admin@example.com
```

## Debugging

### PHP

```php
// Enable WordPress debug mode in wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );

// Plugin-specific logging
AI_Feedback\Logger::debug( 'Review started', array( 'post_id' => $post_id ) );
```

### JavaScript

```javascript
// Enable debug mode
localStorage.setItem( 'AI_FEEDBACK_DEBUG', 'true' );

// Console logging in development
import { debug } from './utils/logger';
debug( 'Review response', response );
```

## Security Checklist

When reviewing code changes, verify:

- [ ] All user input is sanitized (`sanitize_text_field`, `wp_kses`, etc.)
- [ ] All output is escaped (`esc_html`, `esc_attr`, `wp_json_encode`)
- [ ] Capability checks on all endpoints (`current_user_can`)
- [ ] Nonce verification for form submissions
- [ ] AI responses validated against expected schema
- [ ] No sensitive data logged or exposed

## Performance Considerations

- Lazy load review history (don't fetch on sidebar open)
- Use streaming for reviews of documents > 20 blocks
- Cache available models list (1 hour TTL)
- Debounce settings updates (500ms)

## Localization

All user-facing strings must be translatable:

```php
__( 'Review Document', 'ai-feedback' )
_n( '%d note', '%d notes', $count, 'ai-feedback' )
```

```javascript
import { __ } from '@wordpress/i18n';
__( 'Review Document', 'ai-feedback' );
```

## Release Process

1. Update version in `ai-feedback.php` and `package.json`
2. Run full test suite: `npm run test:all`
3. Build production assets: `npm run build`
4. Generate POT file: `npm run i18n:pot`
5. Create git tag: `git tag v1.0.0`
6. Build release ZIP: `npm run plugin-zip`

## Getting Help

- [Design Document](./DESIGN.md) - Architecture and technical decisions
- [Testing Guide](./docs/TESTING.md) - Comprehensive testing documentation
- [API Reference](./docs/API.md) - REST API documentation
- [WordPress Block Editor Handbook](https://developer.wordpress.org/block-editor/)
- [PHP AI Client Documentation](https://github.com/WordPress/php-ai-client)

## Git process
- As you work, commit each group of related changes together with a clear commit message.
- Keep commits concise and focused on a single change.
- Commit messages should be brief, high level overview of the changes made.
- A detailed commit description is not desired - if it seems required, consider breaking the commit down into several smaller commits
- Before each commit, ensure that current linting and tests pass.
- Use feature branches for new features and bugfix branches for fixes.
- Regularly rebase your branch onto the main branch to keep history clean.
- Open a pull request for code review before merging into the main branch.