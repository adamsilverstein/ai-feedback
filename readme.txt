=== AI Feedback ===
Contributors: adamsilverstein
Tags: ai, gutenberg, feedback, block-editor, notes
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered editorial feedback for WordPress Gutenberg, built on WordPress 7.0's core AI Client, Connectors API, and Notes feature.

== Description ==

The AI Feedback plugin integrates AI-powered content review directly into the WordPress block editor. It leverages the WordPress 7.0 core AI Client and Connectors API for provider-agnostic AI access, and uses the native Notes API to provide contextual, block-level feedback on your content.

= Features =

* 🤖 **AI-Powered Reviews**: Intelligent feedback on content quality, tone, flow, and design
* 📝 **Native Notes Integration**: Feedback appears as WordPress block comments
* 🔌 **Core Connectors API**: Uses the credentials you've already configured in Settings → Connectors
* 🔄 **Multiple AI Providers**: Works with Anthropic, OpenAI, Google, and any other registered AI provider
* 🎯 **Focus Areas**: Content Quality, Tone & Voice, Flow & Structure, Design & Formatting

= Requirements =

* **WordPress**: 7.0 or higher
* **PHP**: 8.1 or higher
* **AI Provider**: At least one provider configured under Settings → Connectors

== Installation ==

= Prerequisites =

WordPress 7.0 introduced the core AI Client and Connectors API. AI Feedback uses both directly — there is no longer any custom AI plumbing or separate "AI Experiments" plugin to install.

Before using AI Feedback, configure an AI provider:

1. In WordPress admin, go to **Settings → Connectors**.
2. Pick a provider (Anthropic, OpenAI, Google, etc.) and add an API key, or define one as an environment variable / PHP constant.
3. Save.

= Installing AI Feedback =

1. Upload the plugin to `/wp-content/plugins/ai-feedback/`, or install via the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. Open any post in the block editor — the AI Feedback panel appears in the sidebar.

== Frequently Asked Questions ==

= What AI providers are supported? =

Any provider registered with the WordPress 7.0 core AI Client. Out of the box this includes Anthropic, OpenAI, and Google. Additional providers can be added by their respective AI provider plugins.

= Where do I configure my API key? =

Settings → Connectors. The Connectors API also supports environment variables (e.g. `ANTHROPIC_API_KEY`) and PHP constants for credentials, taking precedence over database-stored values.

= What version of WordPress do I need? =

WordPress 7.0 or higher. Earlier versions are not supported because the plugin relies on `wp_ai_client_prompt()` and the Connectors API.

= Can I customize the feedback focus areas? =

Yes. The plugin provides Content Quality, Tone & Voice, Flow & Structure, and Design & Formatting focus areas, and you can pick which ones to apply per review.

= Is my content sent to external services? =

Yes — your content is sent to whichever AI provider you've configured in Settings → Connectors. Please review your provider's privacy policy and terms of service.

== Screenshots ==

1. AI Feedback panel in the block editor sidebar
2. Review settings with model selection and focus areas
3. AI-generated notes attached to blocks
4. Note detail showing feedback and suggestions

== Changelog ==

= 0.2.0 =
* Migrated to the WordPress 7.0 core AI Client (`wp_ai_client_prompt()`).
* Adopted the WordPress 7.0 Connectors API for credential management; the AI Experiments plugin is no longer required.
* Bumped minimum requirements to WordPress 7.0 and PHP 8.1.
* Removed the `wordpress/php-ai-client` Composer dependency.
* Updated onboarding and error messaging to point users at Settings → Connectors.

= 0.1.0 =
* Initial release.
* Plugin scaffold with build system.
* Settings UI and REST API.
* Model selection with provider grouping.
* Focus areas and tone selection.
* Review button with loading states.

== Upgrade Notice ==

= 0.2.0 =
Now requires WordPress 7.0+ and PHP 8.1+. AI provider credentials are read from Settings → Connectors; the previous AI Experiments plugin dependency has been removed.

== Development ==

The plugin is actively developed on GitHub:
https://github.com/adamsilverstein/ai-feedback

Contributions are welcome.

== Credits ==

* Built on the WordPress 7.0 core [AI Client](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/) and [Connectors API](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)
* Uses the WordPress Notes API for block-level comments
