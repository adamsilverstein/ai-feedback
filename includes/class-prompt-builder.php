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
		);

		$options = wp_parse_args( $options, $defaults );

		// Build document structure.
		$document_blocks = $this->format_blocks_for_prompt( $blocks );

		// Build focus area instructions.
		$focus_instructions = $this->build_focus_instructions( $options['focus_areas'] );

		// Build tone guidance.
		$tone_guidance = $this->build_tone_guidance( $options['target_tone'] );

		// Build existing feedback section for continuation reviews.
		$existing_feedback_section = '';
		$continuation_instructions = '';
		if ( ! empty( $existing_feedback ) ) {
			$existing_feedback_section = $this->format_existing_feedback( $existing_feedback );
			$continuation_instructions = <<<CONT

CONTINUATION REVIEW INSTRUCTIONS:
- This is a follow-up review. Previous feedback and user responses are provided below.
- Do NOT repeat feedback that has already been given unless the issue persists after user addressed it.
- Focus on NEW issues or issues that weren't fully addressed in previous feedback.
- Consider user responses when determining if issues have been resolved.
- If a user has responded to feedback, check if their changes adequately address the concern.

PREVIOUS FEEDBACK AND RESPONSES:
{$existing_feedback_section}
CONT;
		}

		// Construct the full prompt.
		$prompt = <<<PROMPT
Please review the following document and provide actionable editorial feedback.

DOCUMENT TITLE: {$options['post_title']}

DOCUMENT BLOCKS:
{$document_blocks}

FOCUS AREAS:
{$focus_instructions}

TARGET TONE:
{$tone_guidance}
{$continuation_instructions}

INSTRUCTIONS:
- Provide specific, actionable feedback for each issue you identify
- Reference blocks by their block_id (the unique identifier shown for each block)
- Prioritize the most impactful suggestions
- Be encouraging but honest
- Each feedback item should explain WHY it matters and HOW to improve it
- Include an overall summary of the document quality

OUTPUT FORMAT:
Return your response as a JSON object with two properties: "summary" and "feedback".

{
  "summary": "A one-paragraph overall assessment of the document (max 300 chars). Include the total number of notes, overall tone assessment, and key improvement areas.",
  "feedback": [
    {
      "block_id": "abc123-def456",
      "category": "content|tone|flow|design",
      "severity": "suggestion|important|critical",
      "title": "Brief title (max 50 chars)",
      "feedback": "Detailed explanation of the issue and why it matters (max 200 chars)",
      "suggestion": "Specific action to take (max 200 chars, optional)"
    }
  ]
}

IMPORTANT:
- The "block_id" must exactly match one of the block IDs provided in the document
- Return ONLY valid JSON, no additional text or explanation
- If no feedback is needed for a block, don't include it in the array
PROMPT;

		return $prompt;
	}

	/**
	 * Get system instruction for the AI.
	 *
	 * @param  bool $is_continuation Whether this is a continuation review.
	 * @return string System instruction.
	 */
	public function get_system_instruction( bool $is_continuation = false ): string {
		$base_instruction = <<<'INSTRUCTION'
You are a concise editorial assistant. Follow these rules strictly:

BREVITY:
- Title: Max 5 words, start with action verb (e.g., "Add supporting evidence")
- Feedback: Max 2 sentences explaining the issue
- Suggestion: One specific, actionable step with example text

ACTIONABILITY:
- Provide specific replacement text when possible
- Never use vague phrases like "improve clarity" or "consider revising"

SEVERITY:
- critical: Factual errors, confusing content
- important: Weak arguments, tone issues
- suggestion: Style polish, formatting

GOOD: {"title":"Add data source","feedback":"Claim lacks evidence.","suggestion":"Add: 'Users grew 40% (Source: Analytics)'"}
BAD: {"title":"Improve writing","feedback":"Could be better.","suggestion":"Consider revising."}

Output valid JSON only.
INSTRUCTION;

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
			$client_id  = $block['clientId'] ?? 'unknown';
			$block_type = $block['name'] ?? 'unknown';
			$content    = $block['content'] ?? '';

			// Truncate very long content.
			if ( strlen( $content ) > 2000 ) {
				$content = substr( $content, 0, 2000 ) . '... [truncated]';
			}

			// Skip empty content.
			if ( empty( trim( $content ) ) ) {
				continue;
			}

			$formatted[] = sprintf(
				"Block ID: %s [%s]\n%s",
				$client_id,
				$block_type,
				$content
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
}
