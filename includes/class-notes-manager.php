<?php
/**
 * Notes Manager
 *
 * Creates and manages WordPress Notes for AI feedback.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

use WP_Error;

/**
 * Notes Manager class.
 */
class Notes_Manager
{

	/**
	 * Create notes from feedback items.
	 *
	 * @param array $feedback_items Parsed feedback items.
	 * @param int   $post_id        Post ID.
	 * @param array $review_data    Review metadata.
	 * @return array|WP_Error Array with note_ids and block_mapping, or error.
	 */
	public function create_notes_from_feedback(array $feedback_items, int $post_id, array $review_data = array()): array|WP_Error
	{
		if (empty($feedback_items)) {
			return new WP_Error(
				'no_feedback',
				__('No feedback items to create notes from.', 'ai-feedback')
			);
		}

		$note_ids = array();
		$block_mapping = array();
		$errors = array();

		// Group feedback items by block_id to handle threading.
		$grouped_feedback = array();
		foreach ($feedback_items as $item) {
			$block_id = !empty($item['block_id']) ? $item['block_id'] : 'document';
			if (!isset($grouped_feedback[$block_id])) {
				$grouped_feedback[$block_id] = array();
			}
			$grouped_feedback[$block_id][] = $item;
		}

		foreach ($grouped_feedback as $block_id => $items) {
			$parent_id = 0;
			foreach ($items as $index => $item) {
				$note_id = $this->create_note($item, $post_id, $review_data, $parent_id);

				if (is_wp_error($note_id)) {
					$errors[] = $note_id->get_error_message();
					continue;
				}

				$note_ids[] = $note_id;

				// The first note for a block is the parent for subsequent notes.
				if (0 === $index) {
					$parent_id = $note_id;
					// Only the top-level note is mapped to the block in block_mapping.
					if ('document' !== $block_id) {
						$block_mapping[$block_id] = $note_id;
					}
				}
			}
		}

		// If all notes failed, return error.
		if (empty($note_ids) && !empty($errors)) {
			return new WP_Error(
				'note_creation_failed',
				sprintf(
					/* translators: %s: error messages */
					__('Failed to create notes: %s', 'ai-feedback'),
					implode(', ', $errors)
				)
			);
		}

		return array(
			'note_ids' => $note_ids,
			'block_mapping' => $block_mapping,
		);
	}

	/**
	 * Create a single note.
	 *
	 * @param array $feedback_item Feedback item data.
	 * @param int   $post_id       Post ID.
	 * @param array $review_data   Review metadata.
	 * @return int|WP_Error Note ID or error.
	 */
	private function create_note(array $feedback_item, int $post_id, array $review_data, int $parent_id = 0): int|WP_Error
	{
		// Build note content.
		$content = $this->build_note_content($feedback_item);

		// Build metadata first so we can log it.
		$note_meta = $this->build_note_meta($feedback_item, $review_data);

		// Debug logging.
		Logger::debug(sprintf(
			'Creating note for post %d, block_id: %s, parent_id: %d',
			$post_id,
			$feedback_item['block_id'] ?? 'none',
			$parent_id
		));

		// Prepare comment data.
		// WordPress 6.9+ uses 'note' for block-level notes/comments.
		$comment_data = array(
			'comment_post_ID' => $post_id,
			'comment_type' => 'note', // WordPress block comment type for notes.
			'comment_content' => $content,
			'comment_approved' => '0', // 'hold' status in Gutenberg (unresolved)
			'comment_parent' => $parent_id,
			'user_id' => 0, // System-generated (AI).
			'comment_author' => __('AI Feedback', 'ai-feedback'),
			'comment_meta' => $note_meta,
		);

		// Insert comment as note.
		$note_id = wp_insert_comment($comment_data);

		if (!$note_id) {
			Logger::debug('Note creation failed - wp_insert_comment returned false');
			return new WP_Error(
				'note_creation_failed',
				__('Failed to create note.', 'ai-feedback')
			);
		}

		Logger::debug(sprintf('Note created successfully with ID: %d', $note_id));

		return $note_id;
	}

	/**
	 * Build note content from feedback item.
	 *
	 * @param array $feedback_item Feedback item.
	 * @return string Note content.
	 */
	private function build_note_content(array $feedback_item): string
	{
		$content = '';

		// Add title.
		if (!empty($feedback_item['title'])) {
			$content .= '<strong>' . esc_html($feedback_item['title']) . '</strong>' . "\n\n";
		}

		// Add feedback.
		if (!empty($feedback_item['feedback'])) {
			$content .= wp_kses_post($feedback_item['feedback']);
		}

		// Add suggestion if present.
		if (!empty($feedback_item['suggestion'])) {
			$content .= "\n\n" . '<em>' . __('Suggestion:', 'ai-feedback') . '</em> ';
			$content .= wp_kses_post($feedback_item['suggestion']);
		}

		// Add category and severity badges.
		$content .= "\n\n" . $this->build_badges($feedback_item);

		return $content;
	}

	/**
	 * Build badges for category and severity.
	 *
	 * @param array $feedback_item Feedback item.
	 * @return string HTML badges.
	 */
	private function build_badges(array $feedback_item): string
	{
		$badges = array();

		// Category badge.
		if (!empty($feedback_item['category'])) {
			$category_labels = array(
				'content' => __('Content', 'ai-feedback'),
				'tone' => __('Tone', 'ai-feedback'),
				'flow' => __('Flow', 'ai-feedback'),
				'design' => __('Design', 'ai-feedback'),
			);

			$category_label = $category_labels[$feedback_item['category']] ?? $feedback_item['category'];
			$badges[] = sprintf(
				'<span class="ai-feedback-badge ai-feedback-category-%s">%s</span>',
				esc_attr($feedback_item['category']),
				esc_html($category_label)
			);
		}

		// Severity badge.
		if (!empty($feedback_item['severity'])) {
			$severity_labels = array(
				'suggestion' => __('Suggestion', 'ai-feedback'),
				'important' => __('Important', 'ai-feedback'),
				'critical' => __('Critical', 'ai-feedback'),
			);

			$severity_label = $severity_labels[$feedback_item['severity']] ?? $feedback_item['severity'];
			$badges[] = sprintf(
				'<span class="ai-feedback-badge ai-feedback-severity-%s">%s</span>',
				esc_attr($feedback_item['severity']),
				esc_html($severity_label)
			);
		}

		return implode(' ', $badges);
	}

	/**
	 * Build note metadata.
	 *
	 * @param array $feedback_item Feedback item.
	 * @param array $review_data   Review metadata.
	 * @return array Note metadata.
	 */
	private function build_note_meta(array $feedback_item, array $review_data): array
	{
		$meta = array(
			'ai_feedback' => '1', // Store as string for meta_query compatibility.
			'feedback_category' => $feedback_item['category'] ?? '',
			'feedback_severity' => $feedback_item['severity'] ?? '',
			'block_index' => $feedback_item['block_index'] ?? null,
		);

		// Add block ID (clientId) if available.
		// Note: clientId is ephemeral and changes on each editor load.
		if (!empty($feedback_item['block_id'])) {
			$meta['block_id'] = $feedback_item['block_id'];
		}

		// Add block name for more stable identification.
		if (!empty($feedback_item['block_name'])) {
			$meta['block_name'] = $feedback_item['block_name'];
		}

		// Add review metadata.
		if (!empty($review_data['review_id'])) {
			$meta['review_id'] = $review_data['review_id'];
		}

		if (!empty($review_data['model'])) {
			$meta['ai_model'] = $review_data['model'];
		}

		if (!empty($review_data['timestamp'])) {
			$meta['created_at'] = $review_data['timestamp'];
		}

		Logger::debug(sprintf(
			'Built note meta: block_id=%s, block_name=%s, category=%s, severity=%s',
			$meta['block_id'] ?? 'none',
			$meta['block_name'] ?? 'none',
			$meta['feedback_category'],
			$meta['feedback_severity']
		));

		return $meta;
	}

	/**
	 * Get notes for a post.
	 *
	 * @param int  $post_id     Post ID.
	 * @param bool $ai_only     Whether to only get AI feedback notes.
	 * @return array Array of notes.
	 */
	public function get_notes_for_post(int $post_id, bool $ai_only = false): array
	{
		$args = array(
			'post_id' => $post_id,
			'type' => 'note',
			'status' => 'all',
		);

		if ($ai_only) {
			$args['meta_query'] = array(
				array(
					'key' => 'ai_feedback',
					'value' => '1',
				),
			);
		}

		$comments = get_comments($args);

		return $this->format_notes($comments);
	}

	/**
	 * Get notes by review ID.
	 *
	 * @param string $review_id Review ID.
	 * @return array Array of notes.
	 */
	public function get_notes_by_review(string $review_id): array
	{
		$args = array(
			'type' => 'note',
			'status' => 'all',
			'meta_query' => array(
				array(
					'key' => 'review_id',
					'value' => $review_id,
				),
			),
		);

		$comments = get_comments($args);

		return $this->format_notes($comments);
	}

	/**
	 * Format notes for API response.
	 *
	 * @param array $comments Array of comment objects.
	 * @return array Formatted notes.
	 */
	private function format_notes(array $comments): array
	{
		$notes = array();

		foreach ($comments as $comment) {
			$notes[] = array(
				'id' => (int) $comment->comment_ID,
				'post' => (int) $comment->comment_post_ID,
				'parent' => (int) $comment->comment_parent,
				'author_name' => $comment->comment_author,
				'content' => array(
					'raw' => $comment->comment_content,
					'rendered' => apply_filters('comment_text', $comment->comment_content, $comment),
				),
				'date' => $comment->comment_date,
				'type' => $comment->comment_type,
				'status' => ('1' === $comment->comment_approved) ? 'approved' : 'hold',
				'block_id' => get_comment_meta($comment->comment_ID, 'block_id', true),
				'block_index' => get_comment_meta($comment->comment_ID, 'block_index', true),
				'category' => get_comment_meta($comment->comment_ID, 'feedback_category', true),
				'severity' => get_comment_meta($comment->comment_ID, 'feedback_severity', true),
				'review_id' => get_comment_meta($comment->comment_ID, 'review_id', true),
				'ai_model' => get_comment_meta($comment->comment_ID, 'ai_model', true),
				'created_at' => $comment->comment_date,
				'is_resolved' => $this->is_note_resolved($comment->comment_ID),
			);
		}

		return $notes;
	}

	/**
	 * Resolve a note.
	 *
	 * @param int $note_id Note ID.
	 * @return bool Success.
	 */
	public function resolve_note(int $note_id): bool
	{
		return (bool) update_comment_meta($note_id, 'ai_feedback_resolved', true);
	}

	/**
	 * Unresolve a note.
	 *
	 * @param int $note_id Note ID.
	 * @return bool Success.
	 */
	public function unresolve_note(int $note_id): bool
	{
		return delete_comment_meta($note_id, 'ai_feedback_resolved');
	}

	/**
	 * Check if note is resolved.
	 *
	 * @param int $note_id Note ID.
	 * @return bool Whether note is resolved.
	 */
	public function is_note_resolved(int $note_id): bool
	{
		return (bool) get_comment_meta($note_id, 'ai_feedback_resolved', true);
	}

	/**
	 * Delete notes by review ID.
	 *
	 * @param string $review_id Review ID.
	 * @return int Number of notes deleted.
	 */
	public function delete_notes_by_review(string $review_id): int
	{
		$notes = $this->get_notes_by_review($review_id);
		$count = 0;

		foreach ($notes as $note) {
			if (wp_delete_comment($note['id'], true)) {
				$count++;
			}
		}

		return $count;
	}
}
