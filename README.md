# AI Feedback Plugin

AI-powered editorial feedback for WordPress Gutenberg editor using WordPress 6.9's Notes feature.

## Overview

The AI Feedback plugin integrates AI-powered content review directly into the WordPress block editor. It leverages the native Notes API (WordPress 6.9+) to provide contextual, block-level feedback on your content.

## Features

- 🤖 **AI-Powered Reviews**: Get intelligent feedback on content quality, tone, flow, and design
- 📝 **Native Notes Integration**: Feedback appears as WordPress block comments
- ⚙️ **Configurable Settings**: Choose AI models, focus areas, and target tone
- 🔄 **Multiple AI Providers**: Support for Claude, GPT-4, Gemini, and more
- 💬 **Conversational Feedback**: Reply to AI suggestions and get clarifications (Phase 4)
- 🎯 **Focus Areas**: Content Quality, Tone & Voice, Flow & Structure, Design & Formatting

## Requirements

- **WordPress**: 6.9 or higher
- **PHP**: 8.0 or higher
- **Plugins**: [WordPress AI Experiments plugin](https://wordpress.org/plugins/ai/)
- **Node.js**: 18.0 or higher (for development)
- **npm**: 9.0 or higher (for development)

## Installation

### Prerequisites

The AI Feedback plugin requires the [WordPress AI Experiments plugin](https://wordpress.org/plugins/ai/) to be installed and activated. The AI Experiments plugin provides:
- Centralized AI settings screen for configuring API keys
- Support for multiple AI providers (Anthropic, OpenAI, Google Gemini, etc.)
- Common interface for AI configuration across WordPress plugins

**Install the AI Experiments plugin first:**
1. Go to WordPress Admin → Plugins → Add New
2. Search for "AI Experiments" or "AI"
3. Click "Install Now" and then "Activate"
4. Configure your AI provider API keys in Settings → AI

### 1. Clone the Repository

```bash
git clone https://github.com/yourusername/ai-feedback.git
cd ai-feedback
```

### 2. Install Dependencies

```bash
# Install PHP dependencies
composer install

# Install JavaScript dependencies
npm install
```

### 3. Build Assets

```bash
# Production build
npm run build

# Development build with watch
npm run start
```

### 4. Set Up Local Environment (Optional)

```bash
# Start WordPress environment
npm run env:start

# The site will be available at http://localhost:8889
# Login: admin / password
```

### 5. Activate the Plugin

If using wp-env, the plugin is automatically activated. Otherwise:

1. Copy the plugin folder to `wp-content/plugins/ai-feedback`
2. Go to WordPress Admin → Plugins
3. Activate "AI Feedback"

## Configuration

### AI Provider Setup

The plugin uses the [WordPress PHP AI Client](https://github.com/WordPress/php-ai-client) which works in conjunction with the [WordPress AI Experiments plugin](https://wordpress.org/plugins/ai/) for managing AI provider credentials.

**Option 1: Using the AI Experiments Plugin (Recommended)**

Configure your AI provider through the WordPress admin interface:
1. Go to Settings → AI in WordPress admin
2. Select your preferred AI provider (Anthropic, OpenAI, Google Gemini, etc.)
3. Enter your API key
4. Save settings

**Option 2: Using wp-config.php**

Alternatively, you can configure your AI provider directly in `wp-config.php`:

```php
// For Anthropic Claude (default)
define( 'ANTHROPIC_API_KEY', 'your-api-key-here' );

// For OpenAI
define( 'OPENAI_API_KEY', 'your-api-key-here' );

// For Google Gemini
define( 'GOOGLE_AI_API_KEY', 'your-api-key-here' );
```

### Default Settings

The plugin sets these defaults on activation:

- **Default Model**: Claude Sonnet 4
- **Focus Areas**: Content, Tone, Flow
- **Target Tone**: Professional

You can change these in the plugin sidebar within the block editor.

## Usage

### Basic Review

1. Open a post in the block editor
2. Click the **AI Feedback** icon in the toolbar (or open from "Options" menu)
3. Adjust settings if needed:
   - Select AI model
   - Choose focus areas
   - Set target tone
4. Click **Review Document**
5. AI will analyze your content and attach notes to relevant blocks

### Working with Notes

- 📝 Notes appear as indicators on blocks with feedback
- Click a note indicator to read the feedback
- Each note includes:
  - **Title**: Brief summary
  - **Feedback**: Detailed explanation
  - **Suggestion**: Actionable recommendation (if applicable)
  - **Severity**: 🟢 Suggestion, 🟡 Important, 🔴 Critical

### Reply to Feedback (Phase 4)

Once implemented, you'll be able to:
1. Click on a note to open it
2. Type a reply asking for clarification
3. AI will respond with context-aware suggestions

## Development

### Project Structure

```
ai-feedback/
├── ai-feedback.php           # Main plugin file
├── includes/                  # PHP classes
│   ├── class-plugin.php      # Bootstrap
│   ├── class-settings-controller.php
│   └── ...
├── src/                       # JavaScript/React source
│   ├── index.js              # Entry point
│   ├── store/                # WordPress data store
│   ├── components/           # React components
│   └── index.scss            # Styles
├── build/                     # Compiled assets (gitignored)
├── tests/                     # Test files
└── docs/                      # Documentation
```

### Available Scripts

```bash
# Development
npm run start              # Watch mode with hot reload
npm run build              # Production build
npm run format             # Format code with Prettier

# Linting
npm run lint:js            # ESLint
npm run lint:css           # Stylelint
npm run lint:php           # PHPCS (requires Composer)

# Testing
npm run test:unit          # JavaScript unit tests
composer test              # PHP unit tests
npm run test:e2e           # End-to-end tests
npm run test:all           # Run all tests

# WordPress Environment
npm run env:start          # Start wp-env
npm run env:stop           # Stop wp-env
npm run env:clean          # Clean wp-env

# Build
npm run plugin-zip         # Create release zip
```

### Running Tests

```bash
# JavaScript tests (Jest)
npm run test:unit

# PHP tests (PHPUnit)
composer test

# E2E tests (Playwright)
npm run test:e2e

# Run all tests
npm run test:all
```

### Code Standards

The plugin follows:
- **PHP**: WordPress Coding Standards
- **JavaScript**: WordPress ESLint configuration
- **CSS**: WordPress Stylelint configuration

## Architecture

### PHP Backend

- **Settings Controller**: REST API for settings management
- **Review Service**: Orchestrates document analysis (Phase 2)
- **Reply Service**: Handles conversational feedback (Phase 4)
- **Notes Manager**: Creates and manages Notes (Phase 3)
- **Prompt Builder**: Constructs AI prompts (Phase 2)

### JavaScript Frontend

- **WordPress Data Store**: State management with `@wordpress/data`
- **React Components**: UI built with WordPress components
- **REST API Integration**: Communicates with PHP backend

### Data Flow

```
Editor → Plugin Sidebar → Data Store → REST API → PHP Services → AI Client → AI Provider
                                                                              ↓
Editor ← Notes API ← Notes Manager ← Response Parser ← AI Client ← AI Response
```

## Phase 1 Status (Current)

✅ **Completed:**
- Plugin scaffold with build system
- Plugin sidebar UI with settings
- Model selector with provider grouping
- Focus areas and tone selection
- Settings REST API with caching
- WordPress data store (selectors, actions, reducer)
- Review button with loading states

🚧 **Next Steps (Phase 2):**
- Review Service implementation
- Prompt Builder with focus area logic
- PHP AI Client integration
- Response Parser with validation
- Review REST endpoint
- Mock AI responses for testing

## Roadmap

### Phase 2: AI Integration & Review (Next)
- [ ] Review Service with block parsing
- [ ] Prompt engineering for different focus areas
- [ ] AI response parsing and validation
- [ ] Review REST endpoint
- [ ] Error handling and rate limiting

### Phase 3: Notes Integration
- [ ] Notes Manager for WordPress Notes API
- [ ] Block-level note indicators
- [ ] Note resolution tracking
- [ ] Review history display

### Phase 4: Conversation Loop
- [ ] Reply detection hook
- [ ] Context-aware reply prompts
- [ ] Async reply processing
- [ ] Conversation threading UI

### Phase 5: Polish & Release
- [ ] Abilities API integration
- [ ] Streaming responses
- [ ] Performance optimization
- [ ] Accessibility audit
- [ ] Complete documentation
- [ ] Release v1.0.0

## API Documentation

See [docs/API.md](docs/API.md) for complete REST API documentation.

## Testing Documentation

See [docs/TESTING.md](docs/TESTING.md) for comprehensive testing guide.

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Development Guidelines

- Follow WordPress coding standards
- Write tests for new features
- Update documentation as needed
- Ensure all tests pass before submitting PR

## License

GPL v2 or later

## Credits

- Requires [WordPress AI Experiments plugin](https://wordpress.org/plugins/ai/)
- Built with [WordPress PHP AI Client](https://github.com/WordPress/php-ai-client)
- Uses WordPress 6.9+ Notes API
- Powered by AI providers: Anthropic, OpenAI, Google

## Support

- **Issues**: [GitHub Issues](https://github.com/yourusername/ai-feedback/issues)
- **Documentation**: [docs/](docs/)
- **WordPress**: Requires 6.9+

## Changelog

### 0.1.0 (Current - Phase 1)
- Initial plugin scaffold
- Settings UI and REST API
- Model selection
- Focus areas configuration
- Review button (UI only)

### Coming Soon (Phase 2)
- Full AI integration
- Document review functionality
- Note creation

---

Built with ❤️ for WordPress
