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
	 * @param array $blocks      Parsed blocks from post content.
	 * @param array $options     Review options.
	 * @return string The constructed prompt.
	 */
	public function build_review_prompt( array $blocks, array $options = array() ): string {
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

		// Construct the full prompt.
		$prompt = <<<PROMPT
Please review the following document and provide actionable editorial feedback.

DOCUMENT TITLE: {$options['post_title']}

DOCUMENT STRUCTURE:
{$document_blocks}

FOCUS AREAS:
{$focus_instructions}

TARGET TONE:
{$tone_guidance}

INSTRUCTIONS:
- Provide specific, actionable feedback for each issue you identify
- Reference blocks by their index number
- Prioritize the most impactful suggestions
- Be encouraging but honest
- Each feedback item should explain WHY it matters and HOW to improve it

OUTPUT FORMAT:
Return your response as a JSON array of feedback items. Each item must follow this exact structure:

[
  {
    "block_index": 0,
    "category": "content|tone|flow|design",
    "severity": "suggestion|important|critical",
    "title": "Brief title (max 50 chars)",
    "feedback": "Detailed explanation of the issue and why it matters (max 200 chars)",
    "suggestion": "Specific action to take (max 200 chars, optional)"
  }
]

Return ONLY valid JSON, no additional text or explanation.
PROMPT;

		return $prompt;
	}

	/**
	 * Get system instruction for the AI.
	 *
	 * @return string System instruction.
	 */
	public function get_system_instruction(): string {
		return <<<INSTRUCTION
You are an expert editorial assistant reviewing content in WordPress. Your role is to provide concise, actionable feedback on content quality, tone, flow, and design.

Key principles:
1. Every piece of feedback should be specific and actionable
2. Explain WHY something matters, not just WHAT is wrong
3. Suggest concrete improvements
4. Be encouraging while maintaining high editorial standards
5. Focus on the most impactful changes first
6. Consider the target audience and tone requirements

You must respond ONLY with valid JSON in the specified format. Do not include any explanatory text outside the JSON structure.
INSTRUCTION;
	}

	/**
	 * Format blocks for inclusion in prompt.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return string Formatted block structure.
	 */
	private function format_blocks_for_prompt( array $blocks ): string {
		$formatted = array();

		foreach ( $blocks as $index => $block ) {
			$block_type = $block['blockName'] ?? 'unknown';
			$content    = $this->extract_block_content( $block );

			// Truncate very long content.
			if ( strlen( $content ) > 2000 ) {
				$content = substr( $content, 0, 2000 ) . '... [truncated]';
			}

			$formatted[] = sprintf(
				"Block %d [%s]:\n%s",
				$index,
				$block_type,
				$content
			);
		}

		return implode( "\n\n", $formatted );
	}

	/**
	 * Extract readable content from a block.
	 *
	 * @param array $block Block data.
	 * @return string Extracted content.
	 */
	private function extract_block_content( array $block ): string {
		// Get innerHTML (the actual content).
		$content = $block['innerHTML'] ?? '';

		// Strip HTML tags for AI analysis.
		$content = wp_strip_all_tags( $content );

		// Clean up whitespace.
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );

		return $content ?: '[Empty block]';
	}

	/**
	 * Build focus area instructions.
	 *
	 * @param array $focus_areas Selected focus areas.
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
	 * @param string $target_tone Target tone.
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
