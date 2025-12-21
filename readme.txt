=== AI Feedback ===
Contributors: adamsilverstein
Tags: ai, gutenberg, editor, feedback, notes
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
Requires Plugins: ai
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered editorial feedback for WordPress Gutenberg editor using WordPress 6.9's Notes feature.

== Description ==

The AI Feedback plugin integrates AI-powered content review directly into the WordPress block editor. It leverages the native Notes API (WordPress 6.9+) to provide contextual, block-level feedback on your content.

= Features =

* 🤖 **AI-Powered Reviews**: Get intelligent feedback on content quality, tone, flow, and design
* 📝 **Native Notes Integration**: Feedback appears as WordPress block comments
* ⚙️ **Configurable Settings**: Choose AI models, focus areas, and target tone
* 🔄 **Multiple AI Providers**: Support for Claude, GPT-4, Gemini, and more
* 💬 **Conversational Feedback**: Reply to AI suggestions and get clarifications
* 🎯 **Focus Areas**: Content Quality, Tone & Voice, Flow & Structure, Design & Formatting

= Dependencies =

**This plugin requires the [WordPress AI Experiments plugin](https://wordpress.org/plugins/ai/) to be installed and activated.**

The AI Experiments plugin provides:
* Centralized AI settings screen for configuring API keys
* Support for multiple AI providers (Anthropic, OpenAI, Google Gemini, etc.)
* Common interface for AI configuration across WordPress plugins

== Installation ==

1. Install and activate the [WordPress AI Experiments plugin](https://wordpress.org/plugins/ai/)
2. Configure your AI provider API keys in Settings → AI
3. Install and activate the AI Feedback plugin
4. In the block editor, click the AI Feedback icon in the toolbar
5. Configure your preferred settings and click "Review Document"

== Frequently Asked Questions ==

= What is the WordPress AI Experiments plugin? =

The WordPress AI Experiments plugin is a required dependency that provides a centralized settings screen for configuring AI provider API keys and managing AI-related settings across WordPress plugins.

= Which AI providers are supported? =

The plugin supports multiple AI providers through the WordPress PHP AI Client, including:
* Anthropic (Claude Sonnet 4, Claude Opus 4)
* OpenAI (GPT-4o)
* Google (Gemini 2.0 Flash)

= Do I need an API key? =

Yes, you need an API key from at least one supported AI provider. Configure your API key through the AI Experiments plugin settings screen (Settings → AI) or in your wp-config.php file.

= What is the Notes API? =

The Notes API is a new feature in WordPress 6.9 that allows block-level comments. AI Feedback uses this API to attach feedback to specific blocks in your content.

== Screenshots ==

1. AI Feedback sidebar with settings panel
2. Review button and loading state
3. Block-level notes with AI feedback
4. Settings panel with model selection

== Changelog ==

= 0.1.0 =
* Initial release
* Plugin sidebar UI with settings
* Model selector with provider grouping
* Focus areas and tone selection
* Settings REST API
* Review button with loading states
* WordPress data store integration

== Upgrade Notice ==

= 0.1.0 =
Initial release of AI Feedback plugin. Requires WordPress 6.9+ and the AI Experiments plugin.

== Requirements ==

* WordPress 6.9 or higher
* PHP 8.0 or higher
* [WordPress AI Experiments plugin](https://wordpress.org/plugins/ai/)

== Credits ==

* Built with [WordPress PHP AI Client](https://github.com/WordPress/php-ai-client)
* Uses WordPress 6.9+ Notes API
* Powered by multiple AI providers
