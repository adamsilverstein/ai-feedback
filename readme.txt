=== AI Feedback ===
Contributors: adamsilverstein
Tags: ai, gutenberg, feedback, editorial, block-editor, notes
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
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
* 🎯 **Focus Areas**: Content Quality, Tone & Voice, Flow & Structure, Design & Formatting

= Requirements =

* **WordPress**: 6.9 or higher
* **PHP**: 8.0 or higher
* **Plugins**: [WordPress AI Experiments plugin](https://wordpress.org/plugins/ai/)

== Installation ==

= Prerequisites =

The AI Feedback plugin requires the [WordPress AI Experiments plugin](https://wordpress.org/plugins/ai/) to be installed and activated. The AI Experiments plugin provides:

* Centralized AI settings screen for configuring API keys
* Support for multiple AI providers (Anthropic, OpenAI, Google Gemini, etc.)
* Common interface for AI configuration across WordPress plugins

**Install the AI Experiments plugin first:**

1. Go to WordPress Admin → Plugins → Add New
2. Search for "AI Experiments" or "AI"
3. Click "Install Now" and then "Activate"
4. Configure your AI provider API keys in Settings → AI

= Installing AI Feedback =

1. Download the plugin from the WordPress plugin directory
2. Upload the plugin files to `/wp-content/plugins/ai-feedback/`, or install via the WordPress plugins screen
3. Activate the plugin through the 'Plugins' screen in WordPress
4. Configure your AI provider in Settings → AI
5. Open a post in the block editor and look for the AI Feedback panel

== Frequently Asked Questions ==

= What AI providers are supported? =

The plugin supports any AI provider configured via the WordPress AI Experiments plugin, including:
* Anthropic Claude
* OpenAI GPT
* Google Gemini
* And more

= Do I need an API key? =

Yes, you'll need an API key from your chosen AI provider. Configure it in the WordPress AI Experiments plugin settings at Settings → AI.

= What version of WordPress do I need? =

WordPress 6.9 or higher is required for the Notes feature that powers the block-level feedback.

= Can I customize the feedback focus areas? =

Yes! The plugin provides several focus areas:
* Content Quality
* Tone & Voice
* Flow & Structure
* Design & Formatting

You can select which areas you want the AI to focus on for each review.

= Is my content sent to external services? =

Yes, your content is sent to your configured AI provider (Anthropic, OpenAI, Google, etc.) for analysis. Please review your AI provider's privacy policy and terms of service.

== Screenshots ==

1. AI Feedback panel in the block editor sidebar
2. Review settings with model selection and focus areas
3. AI-generated notes attached to blocks
4. Note detail showing feedback and suggestions

== Changelog ==

= 0.1.0 =
* Initial release
* Plugin scaffold with build system
* Settings UI and REST API
* Model selection with provider grouping
* Focus areas and tone selection
* Review button with loading states

== Upgrade Notice ==

= 0.1.0 =
Initial release of AI Feedback plugin. Requires WordPress 6.9+ and the AI Experiments plugin.

== Development ==

The plugin is actively developed on GitHub:
https://github.com/adamsilverstein/ai-feedback

Contributions are welcome!

== Credits ==

* Requires [WordPress AI Experiments plugin](https://wordpress.org/plugins/ai/)
* Built with [WordPress PHP AI Client](https://github.com/WordPress/php-ai-client)
* Uses WordPress 6.9+ Notes API
