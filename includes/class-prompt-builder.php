<?php
/**
 * Prompt Builder
 *
 * Constructs AI prompts for document review.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Prompt Builder class.
 */
class Prompt_Builder {




	/**
	 * Build a review prompt for the AI.
	 *
	 * @param  array $blocks            Blocks with clientId, name, and content from the editor.
	 * @param  array $options           Review options.
	 * @param  array $existing_feedback Optional existing feedback for continuation reviews.
	 * @return string The constructed prompt.
	 */
	public function build_review_prompt( array $blocks, array $options = array(), array $existing_feedback = array() ): string {
		$defaults = array(
			'focus_areas' => array( 'content', 'tone', 'flow' ),
			'target_tone' => 'professional',
			'post_title'  => '',
			'locale'      => get_locale(),
		);

		$options = wp_parse_args( $options, $defaults );

		// Build document structure.
		$document_blocks = $this->format_blocks_for_prompt( $blocks );

		// Build focus area instructions.
		$focus_instructions = $this->build_focus_instructions( $options['focus_areas'] );

		// Build tone guidance.
		$tone_guidance = $this->build_tone_guidance( $options['target_tone'] );

		// Build language instruction.
		$language_instruction = $this->get_language_instruction( $options['locale'] );

		// Build existing feedback section for continuation reviews.
		$existing_feedback_section = '';
		$continuation_instructions = '';
		if ( ! empty( $existing_feedback ) ) {
			$existing_feedback_section  = $this->format_existing_feedback( $existing_feedback );
			$continuation_instructions  = "\n\nCONTINUATION REVIEW INSTRUCTIONS:\n";
			$continuation_instructions .= "- This is a follow-up review. Previous feedback and user responses are provided below.\n";
			$continuation_instructions .= "- Do NOT repeat feedback that has already been given unless the issue persists after user addressed it.\n";
			$continuation_instructions .= "- Focus on NEW issues or issues that weren't fully addressed in previous feedback.\n";
			$continuation_instructions .= "- Consider user responses when determining if issues have been resolved.\n";
			$continuation_instructions .= "- If a user has responded to feedback, check if their changes adequately address the concern.\n\n";
			$continuation_instructions .= "PREVIOUS FEEDBACK AND RESPONSES:\n";
			$continuation_instructions .= $existing_feedback_section;
		}

		// Construct the full prompt.
		$prompt  = 'Please review the following document and provide actionable editorial feedback.' . "\n\n";
		$prompt .= 'DOCUMENT TITLE: ' . $options['post_title'] . "\n\n";
		$prompt .= "DOCUMENT BLOCKS:\n" . $document_blocks . "\n\n";
		$prompt .= "FOCUS AREAS:\n" . $focus_instructions . "\n\n";
		$prompt .= "TARGET TONE:\n" . $tone_guidance;
		$prompt .= $language_instruction;
		$prompt .= $continuation_instructions . "\n\n";
		$prompt .= $this->get_block_type_instructions() . "\n\n";
		$prompt .= "INSTRUCTIONS:\n";
		$prompt .= "- Provide specific, actionable feedback for each issue you identify\n";
		$prompt .= "- Reference blocks by their block_id (the unique identifier shown for each block)\n";
		$prompt .= "- Prioritize the most impactful suggestions\n";
		$prompt .= "- Be encouraging but honest\n";
		$prompt .= "- Each feedback item should explain WHY it matters and HOW to improve it\n";
		$prompt .= "- Include an overall summary of the document quality\n\n";
		$prompt .= "OUTPUT FORMAT:\n";
		$prompt .= 'Return your response as a JSON object with two properties: "summary" and "feedback".' . "\n\n";
		$prompt .= "{\n";
		$prompt .= '  "summary": "A one-paragraph overall assessment of the document (max 300 chars). Include the total number of notes, overall tone assessment, and key improvement areas.",' . "\n";
		$prompt .= '  "feedback": [' . "\n";
		$prompt .= "    {\n";
		$prompt .= '      "block_id": "abc123-def456",' . "\n";
		$prompt .= '      "category": "content|tone|flow|design",' . "\n";
		$prompt .= '      "severity": "suggestion|important|critical",' . "\n";
		$prompt .= '      "title": "Brief title (max 50 chars)",' . "\n";
		$prompt .= '      "feedback": "Detailed explanation of the issue and why it matters (max 200 chars)",' . "\n";
		$prompt .= '      "suggestion": "Specific action to take (max 200 chars, optional)"' . "\n";
		$prompt .= "    }\n";
		$prompt .= "  ]\n";
		$prompt .= "}\n\n";
		$few_shot_examples = $this->get_few_shot_examples( $options['locale'] ?? 'en_US' );

		// Only append examples if the base prompt is already under the character
		// budget. 12000 chars ≈ 3000 tokens — conservative to avoid inflating cost
		// on very long documents while staying well within model context limits.
		if ( strlen( $prompt ) + strlen( $few_shot_examples ) < 12000 ) {
			$prompt .= $few_shot_examples . "\n\n";
		}

		$prompt .= "IMPORTANT:\n";
		$prompt .= '- The "block_id" must exactly match one of the block IDs provided in the document' . "\n";
		$prompt .= "- Return ONLY valid JSON, no additional text or explanation\n";
		$prompt .= "- If no feedback is needed for a block, don't include it in the array";

		return $prompt;
	}

	/**
	 * Get system instruction for the AI.
	 *
	 * @param  bool $is_continuation Whether this is a continuation review.
	 * @return string System instruction.
	 */
	public function get_system_instruction( bool $is_continuation = false ): string {
		$base_instruction  = "You are a concise editorial assistant. Follow these rules strictly:\n\n";
		$base_instruction .= "BREVITY:\n";
		$base_instruction .= '- Title: Max 5 words, start with action verb (e.g., "Add supporting evidence")' . "\n";
		$base_instruction .= "- Feedback: Max 2 sentences explaining the issue\n";
		$base_instruction .= "- Suggestion: One specific, actionable step with example text\n\n";
		$base_instruction .= "ACTIONABILITY:\n";
		$base_instruction .= "- Provide specific replacement text when possible\n";
		$base_instruction .= '- Never use vague phrases like "improve clarity" or "consider revising"' . "\n\n";
		$base_instruction .= "SEVERITY:\n";
		$base_instruction .= "- critical: Factual errors, confusing content\n";
		$base_instruction .= "- important: Weak arguments, tone issues\n";
		$base_instruction .= "- suggestion: Style polish, formatting\n\n";
		$base_instruction .= 'GOOD: {"title":"Add data source","feedback":"Claim lacks evidence.","suggestion":"Add: \'Users grew 40% (Source: Analytics)\'"}' . "\n";
		$base_instruction .= 'BAD: {"title":"Improve writing","feedback":"Could be better.","suggestion":"Consider revising."}' . "\n\n";
		$base_instruction .= 'Output valid JSON only.';

		if ( $is_continuation ) {
			$base_instruction .= "\n\nCONTINUATION REVIEW RULES:\n";
			$base_instruction .= "- You have access to previous feedback and user responses.\n";
			$base_instruction .= "- Skip issues that were already addressed based on user responses.\n";
			$base_instruction .= "- Only flag new issues or issues that persist despite user changes.\n";
			$base_instruction .= "- Be aware that content may have changed since the last review.\n";
			$base_instruction .= '- Reference the same block_ids when following up on existing issues.';
		}

		return $base_instruction;
	}

	/**
	 * Format existing feedback for inclusion in continuation prompts.
	 *
	 * @param  array $existing_feedback Array of feedback notes with replies.
	 * @return string Formatted feedback for prompt.
	 */
	private function format_existing_feedback( array $existing_feedback ): string {
		if ( empty( $existing_feedback ) ) {
			return '';
		}

		$formatted = array();

		foreach ( $existing_feedback as $note ) {
			$block_id = $note['block_id'] ?? 'unknown';
			$category = $note['category'] ?? 'general';
			$severity = $note['severity'] ?? 'suggestion';
			$content  = $note['content']['raw'] ?? '';

			// Truncate very long content.
			if ( strlen( $content ) > 500 ) {
				$content = substr( $content, 0, 500 ) . '... [truncated]';
			}

			$feedback_entry = sprintf(
				"[Block: %s] [%s/%s]\nAI Feedback: %s",
				$block_id,
				$category,
				$severity,
				wp_strip_all_tags( $content )
			);

			// Add user replies if present.
			if ( ! empty( $note['replies'] ) ) {
				$feedback_entry .= "\nUser Responses:";
				foreach ( $note['replies'] as $reply ) {
					$reply_content = $reply['content']['raw'] ?? '';
					$reply_author  = $reply['author_name'] ?? 'User';
					$is_ai_reply   = $reply['is_ai'] ?? false;

					// Truncate reply content.
					if ( strlen( $reply_content ) > 300 ) {
						$reply_content = substr( $reply_content, 0, 300 ) . '...';
					}

					$author_label    = $is_ai_reply ? 'AI' : $reply_author;
					$feedback_entry .= sprintf(
						"\n  - %s: %s",
						$author_label,
						wp_strip_all_tags( $reply_content )
					);
				}
			}

			$formatted[] = $feedback_entry;
		}

		return implode( "\n\n---\n\n", $formatted );
	}

	/**
	 * Format blocks for inclusion in prompt.
	 *
	 * @param  array $blocks Blocks with clientId, name, and content.
	 * @return string Formatted block structure.
	 */
	private function format_blocks_for_prompt( array $blocks ): string {
		$formatted = array();

		foreach ( $blocks as $block ) {
			$client_id  = sanitize_text_field( $block['clientId'] ?? 'unknown' );
			$block_type = sanitize_text_field( $block['name'] ?? 'unknown' );
			$content    = $block['content'] ?? '';

			// Truncate very long content.
			if ( strlen( $content ) > 2000 ) {
				$content = substr( $content, 0, 2000 ) . '... [truncated]';
			}

			// Skip empty content.
			if ( empty( trim( $content ) ) ) {
				continue;
			}

			$hint       = sanitize_text_field( $this->get_block_type_hint( $block_type ) );
			$type_label = $hint ? $block_type . ' - ' . $hint : $block_type;

			$formatted[] = sprintf(
				"Block ID: %s [%s]\n%s",
				$client_id,
				$type_label,
				wp_strip_all_tags( $content )
			);
		}

		return implode( "\n\n---\n\n", $formatted );
	}

	/**
	 * Build focus area instructions.
	 *
	 * @param  array $focus_areas Selected focus areas.
	 * @return string Focus instructions.
	 */
	private function build_focus_instructions( array $focus_areas ): string {
		$instructions = array();

		$area_definitions = array(
			'content' => 'Content Quality - Evaluate clarity, accuracy, completeness, and value. Look for vague statements, missing context, unsupported claims, or areas that need more detail.',
			'tone'    => 'Tone & Voice - Assess consistency of voice, appropriateness for audience, and alignment with target tone. Flag jarring shifts in formality or inconsistent terminology.',
			'flow'    => 'Flow & Structure - Analyze logical progression, transitions between ideas, paragraph structure, and overall organization. Identify awkward jumps or missing connections.',
			'design'  => 'Design & Formatting - Review block usage, visual hierarchy, formatting choices, and readability. Suggest better block types or formatting improvements.',
		);

		foreach ( $focus_areas as $area ) {
			if ( isset( $area_definitions[ $area ] ) ) {
				$instructions[] = '- ' . $area_definitions[ $area ];
			}
		}

		return implode( "\n", $instructions );
	}

	/**
	 * Build tone guidance.
	 *
	 * @param  string $target_tone Target tone.
	 * @return string Tone guidance.
	 */
	private function build_tone_guidance( string $target_tone ): string {
		$tone_definitions = array(
			'professional' => 'Professional - Clear, authoritative, and polished. Suitable for business content, technical documentation, and formal communications. Use industry-standard terminology and maintain objectivity.',
			'casual'       => 'Casual - Conversational, friendly, and approachable. Suitable for blogs, social media, and informal communications. Use contractions and everyday language, but remain clear and coherent.',
			'academic'     => 'Academic - Scholarly, precise, and evidence-based. Suitable for research, analysis, and educational content. Support claims with evidence and maintain formal structure.',
			'friendly'     => 'Friendly - Warm, personable, and engaging. Suitable for community content, customer communications, and welcoming materials. Be encouraging and supportive while staying helpful.',
		);

		return $tone_definitions[ $target_tone ] ?? $tone_definitions['professional'];
	}

	/**
	 * Get block-type specific review instructions.
	 *
	 * Returns a prompt section with detailed guidance for reviewing
	 * different block types with type-appropriate criteria.
	 *
	 * @return string Block-type instructions for the prompt.
	 */
	private function get_block_type_instructions(): string {
		$instructions  = "BLOCK-TYPE SPECIFIC GUIDANCE:\n";
		$instructions .= "When reviewing blocks, apply these type-specific criteria in addition to the general review focus areas:\n\n";

		$instructions .= "Headings (core/heading):\n";
		$instructions .= "- Verify proper heading hierarchy (H1 → H2 → H3, no skipped levels)\n";
		$instructions .= "- Check that headings are descriptive and support scannability\n";
		$instructions .= "- Evaluate SEO value of heading text\n\n";

		$instructions .= "Paragraphs (core/paragraph):\n";
		$instructions .= "- Assess readability and sentence length variation\n";
		$instructions .= "- Check for clarity, conciseness, and unnecessary jargon\n";
		$instructions .= "- Look for passive voice overuse\n\n";

		$instructions .= "Lists (core/list):\n";
		$instructions .= "- Verify parallel grammatical structure across items\n";
		$instructions .= "- Check consistent punctuation and capitalization\n";
		$instructions .= "- Ensure logical ordering of items\n\n";

		$instructions .= "Quotes (core/quote):\n";
		$instructions .= "- Verify attribution is present and accurate\n";
		$instructions .= "- Check that the quote supports the surrounding context\n\n";

		$instructions .= "Images (core/image):\n";
		$instructions .= "- Evaluate alt text quality (descriptive, concise, not redundant)\n";
		$instructions .= "- Check that the image supports the surrounding content\n\n";

		$instructions .= "Tables (core/table):\n";
		$instructions .= "- Review data organization and header clarity\n";
		$instructions .= "- Check for consistent formatting across cells\n\n";

		$instructions .= "Code (core/code):\n";
		$instructions .= "- Check that code blocks have sufficient context or explanation\n";
		$instructions .= '- Verify language is specified when relevant';

		return $instructions;
	}

	/**
	 * Get a short review hint for a specific block type.
	 *
	 * Returns a brief phrase describing what to focus on when reviewing
	 * a block of the given type. Returns empty string for unrecognized types.
	 *
	 * @param  string $block_type The block type name (e.g. 'core/heading').
	 * @return string Short hint for the block type, or empty string.
	 */
	private function get_block_type_hint( string $block_type ): string {
		$hints = array(
			'core/heading'   => 'review for hierarchy and SEO',
			'core/paragraph' => 'review for readability and clarity',
			'core/list'      => 'review for parallel structure',
			'core/quote'     => 'verify attribution',
			'core/image'     => 'evaluate alt text quality',
			'core/table'     => 'review organization and headers',
			'core/code'      => 'check for context and explanation',
		);

		return $hints[ $block_type ] ?? '';
	}

	/**
	 * Get default language configurations.
	 *
	 * @return array Language configurations.
	 */
	private function get_default_language_configs(): array {
		return array(
			'es_ES' => array(
				'name'        => 'Spanish',
				'instruction' => 'Provide all feedback in Spanish. Apply Spanish grammar and style conventions. Consider RAE (Real Academia Española) guidelines.',
			),
			'fr_FR' => array(
				'name'        => 'French',
				'instruction' => 'Provide all feedback in French. Apply French grammar rules including accent marks and agreement. Consider Académie française guidelines.',
			),
			'de_DE' => array(
				'name'        => 'German',
				'instruction' => 'Provide all feedback in German. Apply German grammar rules including case and compound words. Consider Duden guidelines.',
			),
			'it_IT' => array(
				'name'        => 'Italian',
				'instruction' => 'Provide all feedback in Italian. Apply Italian grammar and style conventions.',
			),
			'pt_BR' => array(
				'name'        => 'Brazilian Portuguese',
				'instruction' => 'Provide all feedback in Brazilian Portuguese. Apply Brazilian Portuguese conventions.',
			),
			'nl_NL' => array(
				'name'        => 'Dutch',
				'instruction' => 'Provide all feedback in Dutch. Apply Dutch grammar and spelling conventions.',
			),
			'ja'    => array(
				'name'        => 'Japanese',
				'instruction' => 'Provide all feedback in Japanese. Consider keigo (敬語) levels appropriate for the content type.',
			),
			'zh_CN' => array(
				'name'        => 'Simplified Chinese',
				'instruction' => 'Provide all feedback in Simplified Chinese.',
			),
		);
	}

	/**
	 * Get language instruction for the AI based on locale.
	 *
	 * @param  string $locale WordPress locale code.
	 * @return string Language instruction for the prompt.
	 */
	private function get_language_instruction( string $locale ): string {
		$language_configs = $this->get_default_language_configs();

		// Allow filtering of language configurations.
		$language_configs = apply_filters( 'ai_feedback_language_configs', $language_configs );

		if ( isset( $language_configs[ $locale ] ) ) {
			return sprintf(
				"\n\n## LANGUAGE\n\nThe content is in %s. %s\n",
				$language_configs[ $locale ]['name'],
				$language_configs[ $locale ]['instruction']
			);
		}

		// Default to English.
		return "\n\n## LANGUAGE\n\nProvide feedback in English.\n";
	}

	/**
	 * Get few-shot examples to guide consistent AI output.
	 *
	 * Provides reference examples for each feedback category and severity
	 * level so the AI produces consistently formatted, high-quality feedback.
	 *
	 * @param  string $locale WordPress locale code.
	 * @return string Few-shot examples for the prompt.
	 */
	private function get_few_shot_examples( string $locale = 'en_US' ): string {
		$language_note = '';
		if ( 'en_US' !== $locale && 'en' !== substr( $locale, 0, 2 ) ) {
			$language_note = 'Note: These examples are in English for format reference only. '
				. 'Your actual feedback MUST be written in the language specified in the LANGUAGE section above.'
				. "\n\n";
		}

		$examples = <<<'EOT'
REFERENCE EXAMPLES:
Use these as a guide for the quality, format, and tone of your feedback.
Each item in the "feedback" array should follow this format:

Content quality example (severity: important):
{
  "summary": "The document has strong structure but several claims lack supporting evidence.",
  "feedback": [
    {
      "block_id": "example-1",
      "category": "content",
      "severity": "important",
      "title": "Cite your source",
      "feedback": "The statistic '80% of users prefer...' needs attribution. Unsourced data weakens credibility.",
      "suggestion": "Add source: 'According to [Study Name, Year], 80% of users prefer...'"
    }
  ]
}

Tone example (severity: suggestion):
{
  "summary": "Good content overall, but a few phrases break the formal tone.",
  "feedback": [
    {
      "block_id": "example-2",
      "category": "tone",
      "severity": "suggestion",
      "title": "Match formal tone",
      "feedback": "The phrase 'pretty cool feature' is too casual for this technical document.",
      "suggestion": "Replace with: 'This feature significantly improves...'"
    }
  ]
}

Flow example (severity: important):
{
  "summary": "Content is well-written but transitions between sections need work.",
  "feedback": [
    {
      "block_id": "example-3",
      "category": "flow",
      "severity": "important",
      "title": "Add transition",
      "feedback": "Abrupt topic shift between pricing and features. Readers need context.",
      "suggestion": "Add transition: 'Beyond pricing benefits, the feature also offers...'"
    }
  ]
}

Design example (severity: suggestion):
{
  "summary": "Content reads well but formatting could improve scannability.",
  "feedback": [
    {
      "block_id": "example-4",
      "category": "design",
      "severity": "suggestion",
      "title": "Use a list for scannability",
      "feedback": "Five items listed in one paragraph are hard to scan.",
      "suggestion": "Convert to a bulleted list with one item per benefit."
    }
  ]
}

When content is well-written, return minimal or no feedback:
{
  "summary": "Well-structured content with clear arguments and consistent tone.",
  "feedback": []
}
EOT;

		$examples = $language_note . $examples;

		/**
		 * Filters the few-shot examples appended to the AI review prompt.
		 *
		 * Return an empty string to disable few-shot examples entirely.
		 *
		 * @param string $examples The formatted few-shot examples.
		 * @param string $locale   The current WordPress locale.
		 */
		return apply_filters( 'ai_feedback_few_shot_examples', $examples, $locale );
	}
}
