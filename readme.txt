=== Fresh Eyes: AI Editorial Feedback ===
Contributors: adamsilverstein
Tags: ai, editorial, block-editor, notes, writing
Requires at least: 7.0
Tested up to: 7.2
Requires PHP: 8.1
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fresh pair of eyes on your draft: AI feedback, suggested edits and quick fixes, left as notes on your blocks in the editor.

== Description ==

Fresh Eyes is an AI agent that reviews your posts right in the block editor, like a fresh pair of eyes on a story before it runs. It leverages the WordPress 7.0 core AI Client and Connectors API for provider-agnostic AI access, and uses the native Notes API to provide contextual, block-level feedback on your content.

= Features =

* 🤖 **AI-Powered Reviews**: Intelligent feedback on content quality, tone, flow, design, and grammar and spelling
* 📝 **Native Notes Integration**: Feedback appears as WordPress block comments
* 🔌 **Core Connectors API**: Uses the credentials you've already configured in Settings → Connectors
* 🔄 **Multiple AI Providers**: Works with Anthropic, OpenAI, Google, and any other registered AI provider
* 🎯 **Focus Areas**: Content Quality, Tone & Voice, Flow & Structure, Design & Formatting
* 👀 **Reader Personas**: Read it as a Copy editor, Skeptical expert, First-time visitor or Busy skimmer, and say who the post is for
* 📏 **House Style**: Tell it how you write once, in a reply or in Review Settings, and later reviews follow it. Or let it suggest rules from your published posts and keep the ones you want

= Requirements =

* **WordPress**: 7.0 or higher
* **PHP**: 8.1 or higher
* **AI Provider**: At least one provider configured under Settings → Connectors

== Installation ==

= Prerequisites =

WordPress 7.0 introduced the core AI Client and Connectors API. Fresh Eyes uses both directly — there is no longer any custom AI plumbing or separate "AI Experiments" plugin to install.

Before using Fresh Eyes, configure an AI provider:

1. In WordPress admin, go to **Settings → Connectors**.
2. Pick a provider (Anthropic, OpenAI, Google, etc.) and add an API key, or define one as an environment variable / PHP constant.
3. Save.

= Installing Fresh Eyes =

1. Upload the plugin to `/wp-content/plugins/fresh-eyes/`, or install via the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. Open any post in the block editor — the Fresh Eyes panel appears in the sidebar.

== Frequently Asked Questions ==

= What AI providers are supported? =

Any text generation provider connected through the WordPress 7.0 core AI Client. Settings → Connectors lists Anthropic, OpenAI, and Google, and installs each provider's plugin when you set it up. Other providers can be added with their own AI provider plugins.

= Will a review ever run on a different model than the one I picked? =

No. Reviews, replies and answers to mentions run on the model you picked, from its provider. If that provider doesn't offer the model, Fresh Eyes shows an error that names the model and sends nothing, rather than using another model or provider. Pick another model in the Fresh Eyes settings, or check which models your provider account offers.

= Where do I configure my API key? =

Settings → Connectors. The Connectors API also supports environment variables (e.g. `ANTHROPIC_API_KEY`) and PHP constants for credentials, taking precedence over database-stored values.

= What version of WordPress do I need? =

WordPress 7.0 or higher. Earlier versions are not supported because the plugin relies on `wp_ai_client_prompt()` and the Connectors API.

= Can I customize the feedback focus areas? =

Yes. The plugin provides Content Quality, Tone & Voice, Flow & Structure, and Design & Formatting focus areas, and you can pick which ones to apply per review.

= Is my content sent to external services? =

Yes — your content is sent to whichever AI provider you've configured in Settings → Connectors. Please review your provider's privacy policy and terms of service.

When the selected model can see images (the built-in Claude, GPT and Gemini models can, and the AI client's model information is checked first), a review also sends up to four Media Library images per request (a long post reviewed in parts can send four with each part) that have no alt text, so the model can propose some. Images from other sites are never fetched or sent. An image the provider can't take (a GIF, or one too large in pixels) is left out on its own, and the review summary says so. Developers can turn this off by returning 0 from the `ai_feedback_max_review_images` filter.

An administrator can turn this off for the whole site: open Review Settings in the Fresh Eyes sidebar and uncheck "Send images to the AI provider". It is on by default. With it off, no image leaves your site and reviews only point out missing alt text. The `ai_feedback_max_review_images` filter wins over the setting either way.

= Why is there a Fresh Eyes user? =

So you can pick Fresh Eyes from the `@` menu in a note, the plugin adds a hidden user named Fresh Eyes. It has no role or email address, can't log in, and stays out of the Users screen and other user lists. Uninstalling the plugin removes it. Developers can go without it by returning false from the `ai_feedback_create_mention_user` filter (network-wide on multisite); typing `@fresh-eyes` still works. Tools that switch the current user directly, such as single sign-on or "log in as" plugins, can still make it the current user, but it has no role or capabilities, so it can't edit anything. Another account named "Fresh Eyes" would show next to it in the `@` menu; WordPress emails that account about the mention like any other user.

= What happens to my data when I delete the plugin? =

Deactivating keeps everything. Deleting the plugin from the Plugins screen removes its settings and personal data: the review settings, house style rules (site and personal, on every site of a network), review history, usage analytics, rate limit records, its editor preferences, cached data, pending AI replies and the hidden Fresh Eyes user. On a site with a persistent object cache, cached review sessions and model lists can't be looked up by name, so they stay in the cache until they expire, within an hour. Notes are kept, with their feedback details, since they are your editorial record: reinstalling picks up where you left off.

== Screenshots ==

1. A reviewed draft with suggested edits in the text, notes beside the blocks and the review summary in the sidebar
2. The Preview fix dialog showing the old and new wording
3. A note thread with an author's reply and the AI's answer
4. The Fresh Eyes Analytics screen

== Changelog ==

= 1.0.3 =
* Renamed the plugin to Fresh Eyes: AI Editorial Feedback. The text domain is now `fresh-eyes`.
* The notes and settings REST routes now require per-post and administrator capabilities.
* Requirement notices only show on the Plugins and Updates screens.

= 1.0.2 =
* AI replies to notes are now answered from the editor, so they no longer depend on WP-Cron.
* The Try again button waits out the short retry cooldown after a failed AI reply, with a hint next to it.
* Replies that were left waiting are answered when the post is next opened in the editor.
* AI suggestion markers stay credited to the note's author.
* Note badges are kept when a note's status is saved.
* AI notes are re-linked to their blocks after reloading an unsaved post.
* The next and previous feedback shortcuts work after a reload.
* Review settings show before the first review and collapse after it.
* Review History shows times in the site timezone.

= 1.0.1 =
* Renamed the plugin to Copydesk AI for the WordPress.org plugin directory. The text domain is now `copydesk-ai`.
* Load translations for the editor script.

= 1.0.0 =
* First stable release.
* Quick fixes: apply mechanical text changes straight from a note, with a preview of the old and new wording.
* Quick fixes are added as Gutenberg suggestions when Suggestion Mode is on, so they can be accepted or rejected.
* Reply to an AI note and get an AI answer in the same thread; replies are answered right away in the editor, with WP-Cron as the fallback.
* Feedback items are scored by priority, with quick actions in the sidebar.
* Large documents are reviewed in chunks, and the sidebar updates after each part.
* Staged progress during a review, and retries when the AI service is unavailable.
* Review history panel, plus export and share options for reviews.
* Usage analytics screen.
* Keyboard shortcuts for running a review and moving between feedback items.
* Contextual tooltips in settings and progressive tips for new users.
* Accessibility fixes: review errors are announced once, with an "Error:" prefix.
* Bug fixes from plugin testing.

= 0.2.0 =
* Migrated to the WordPress 7.0 core AI Client (`wp_ai_client_prompt()`).
* Adopted the WordPress 7.0 Connectors API for credential management; the AI Experiments plugin is no longer required.
* Bumped minimum requirements to WordPress 7.0 and PHP 8.1.
* Removed the `wordpress/php-ai-client` Composer dependency.
* Updated onboarding and error messaging to point users at Settings → Connectors.
* Default model is now Claude Sonnet 5.5; a saved default that is no longer offered falls back to it.
* Rate-limited AI requests are now retried with backoff; auth and billing failures still fail fast.
* AI requests use a 60 second timeout (filterable via `ai_feedback_http_timeout`) instead of core's 30 second default.

= 0.1.0 =
* Initial release.
* Plugin scaffold with build system.
* Settings UI and REST API.
* Model selection with provider grouping.
* Focus areas and tone selection.
* Review button with loading states.

== Upgrade Notice ==

= 1.0.3 =
Copydesk AI is now Fresh Eyes. The plugin now installs to a `fresh-eyes` folder, so if you installed Copydesk AI from GitHub, deactivate and delete that copy before activating this one. Existing notes, settings and history carry over.

= 1.0.2 =
AI replies to notes no longer depend on WP-Cron, plus fixes for suggestions, note badges and review history.

= 1.0.1 =
AI Feedback is now Copydesk AI. The plugin now installs to a `copydesk-ai` folder, so if you installed AI Feedback from GitHub, deactivate and delete that copy before activating this one. Existing notes, settings and history carry over.

= 1.0.0 =
First stable release. Adds quick fixes, AI replies in note threads, review history, analytics and chunked reviews of long documents.

= 0.2.0 =
Now requires WordPress 7.0+ and PHP 8.1+. AI provider credentials are read from Settings → Connectors; the previous AI Experiments plugin dependency has been removed.

== Development ==

The plugin is actively developed on GitHub:
https://github.com/adamsilverstein/fresh-eyes

Contributions are welcome.

== Credits ==

* Built on the WordPress 7.0 core [AI Client](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/) and [Connectors API](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)
* Uses the WordPress Notes API for block-level comments
