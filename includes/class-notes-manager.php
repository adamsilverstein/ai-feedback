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
class Notes_Manager {



	/**
	 * Create notes from feedback items.
	 *
	 * @param  array $feedback_items Parsed feedback items.
	 * @param  int   $post_id        Post ID.
	 * @param  array $review_data    Review metadata.
	 * @return array|WP_Error Array with note_ids and block_mapping, or error.
	 */
	public function create_notes_from_feedback( array $feedback_items, int $post_id, array $review_data = array() ): array|WP_Error {
		if ( empty( $feedback_items ) ) {
			return new WP_Error(
				'no_feedback',
				__( 'No feedback items to create notes from.', 'ai-feedback' )
			);
		}

		$note_ids      = array();
		$block_mapping = array();
		$errors        = array();

		// Group feedback items by block_id to handle threading.
		$grouped_feedback = array();
		foreach ( $feedback_items as $item ) {
			$block_id = ! empty( $item['block_id'] ) ? $item['block_id'] : 'document';
			if ( ! isset( $grouped_feedback[ $block_id ] ) ) {
				$grouped_feedback[ $block_id ] = array();
			}
			$grouped_feedback[ $block_id ][] = $item;
		}

		foreach ( $grouped_feedback as $block_id => $items ) {
			$parent_id = 0;
			foreach ( $items as $index => $item ) {
				$note_id = $this->create_note( $item, $post_id, $review_data, $parent_id );

				if ( is_wp_error( $note_id ) ) {
					$errors[] = $note_id->get_error_message();
					continue;
				}

				$note_ids[] = $note_id;

				// The first note for a block is the parent for subsequent notes.
				if ( 0 === $index ) {
					$parent_id = $note_id;
					// Only the top-level note is mapped to the block in block_mapping.
					if ( 'document' !== $block_id ) {
						$block_mapping[ $block_id ] = $note_id;
					}
				}
			}
		}

		// If all notes failed, return error.
		if ( empty( $note_ids ) && ! empty( $errors ) ) {
			return new WP_Error(
				'note_creation_failed',
				sprintf(
					/* translators: %s: error messages */
					__( 'Failed to create notes: %s', 'ai-feedback' ),
					implode( ', ', $errors )
				)
			);
		}

		return array(
			'note_ids'      => $note_ids,
			'block_mapping' => $block_mapping,
		);
	}

	/**
	 * Create a single note.
	 *
	 * @param  array $feedback_item Feedback item data.
	 * @param  int   $post_id       Post ID.
	 * @param  array $review_data   Review metadata.
	 * @param  int   $parent_id     Parent note ID.
	 * @return int|WP_Error Note ID or error.
	 */
	private function create_note( array $feedback_item, int $post_id, array $review_data, int $parent_id = 0 ): int|WP_Error {
		// Build note content.
		$content = $this->build_note_content( $feedback_item );

		// Build metadata first so we can log it.
		$note_meta = $this->build_note_meta( $feedback_item, $review_data );

		// Debug logging.
		Logger::debug(
			sprintf(
				'Creating note for post %d, block_id: %s, parent_id: %d',
				$post_id,
				$feedback_item['block_id'] ?? 'none',
				$parent_id
			)
		);

		// Prepare comment data.
		// WordPress 6.9+ uses 'note' for block-level notes/comments.
		$comment_data = array(
			'comment_post_ID'  => $post_id,
			'comment_type'     => 'note', // WordPress block comment type for notes.
			'comment_content'  => $content,
			'comment_approved' => '0', // 'hold' status in Gutenberg (unresolved)
			'comment_parent'   => $parent_id,
			'user_id'          => 0, // System-generated (AI).
			'comment_author'   => Plugin::get_ai_feedback_author(),
			'comment_meta'     => $note_meta,
		);

		// Insert comment as note.
		$note_id = wp_insert_comment( $comment_data );

		if ( ! $note_id ) {
			Logger::debug( 'Note creation failed - wp_insert_comment returned false' );
			return new WP_Error(
				'note_creation_failed',
				__( 'Failed to create note.', 'ai-feedback' )
			);
		}

		Logger::debug( sprintf( 'Note created successfully with ID: %d', $note_id ) );

		return $note_id;
	}

	/**
	 * Build note content from feedback item.
	 *
	 * @param  array $feedback_item Feedback item.
	 * @return string Note content.
	 */
	private function build_note_content( array $feedback_item ): string {
		$content = '';

		// Add title.
		if ( ! empty( $feedback_item['title'] ) ) {
			$content .= '<strong>' . esc_html( $feedback_item['title'] ) . '</strong>' . "\n\n";
		}

		// Add feedback.
		if ( ! empty( $feedback_item['feedback'] ) ) {
			$content .= wp_kses_post( $feedback_item['feedback'] );
		}

		// Add suggestion if present.
		if ( ! empty( $feedback_item['suggestion'] ) ) {
			$content .= "\n\n" . '<em>' . __( 'Suggestion:', 'ai-feedback' ) . '</em> ';
			$content .= wp_kses_post( $feedback_item['suggestion'] );
		}

		// Add category and severity badges.
		$content .= "\n\n" . $this->build_badges( $feedback_item );

		return $content;
	}

	/**
	 * Build badges for category and severity.
	 *
	 * @param  array $feedback_item Feedback item.
	 * @return string HTML badges.
	 */
	private function build_badges( array $feedback_item ): string {
		$badges = array();

		// Category badge.
		if ( ! empty( $feedback_item['category'] ) ) {
			$category_labels = array(
				'content' => __( 'Content', 'ai-feedback' ),
				'tone'    => __( 'Tone', 'ai-feedback' ),
				'flow'    => __( 'Flow', 'ai-feedback' ),
				'design'  => __( 'Design', 'ai-feedback' ),
			);

			$category_label = $category_labels[ $feedback_item['category'] ] ?? $feedback_item['category'];
			$badges[]       = sprintf(
				'<span class="ai-feedback-badge ai-feedback-category-%s">%s</span>',
				esc_attr( $feedback_item['category'] ),
				esc_html( $category_label )
			);
		}

		// Severity badge.
		if ( ! empty( $feedback_item['severity'] ) ) {
			$severity_labels = array(
				'suggestion' => __( 'Suggestion', 'ai-feedback' ),
				'important'  => __( 'Important', 'ai-feedback' ),
				'critical'   => __( 'Critical', 'ai-feedback' ),
			);

			$severity_label = $severity_labels[ $feedback_item['severity'] ] ?? $feedback_item['severity'];
			$badges[]       = sprintf(
				'<span class="ai-feedback-badge ai-feedback-severity-%s">%s</span>',
				esc_attr( $feedback_item['severity'] ),
				esc_html( $severity_label )
			);
		}

		return implode( ' ', $badges );
	}

	/**
	 * Build note metadata.
	 *
	 * @param  array $feedback_item Feedback item.
	 * @param  array $review_data   Review metadata.
	 * @return array Note metadata.
	 */
	private function build_note_meta( array $feedback_item, array $review_data ): array {
		$meta = array(
			'ai_feedback'       => '1', // Store as string for meta_query compatibility.
			'feedback_category' => $feedback_item['category'] ?? '',
			'feedback_severity' => $feedback_item['severity'] ?? '',
			'block_index'       => $feedback_item['block_index'] ?? null,
		);

		if ( isset( $feedback_item['priority'] ) && is_int( $feedback_item['priority'] ) ) {
			$meta['feedback_priority'] = $feedback_item['priority'];
		}

		// Store the feedback text separately so the sidebar can show it without
		// parsing the note's HTML content.
		if ( ! empty( $feedback_item['title'] ) ) {
			$meta['feedback_title'] = $feedback_item['title'];
		}

		if ( ! empty( $feedback_item['feedback'] ) ) {
			$meta['feedback_text'] = $feedback_item['feedback'];
		}

		if ( ! empty( $feedback_item['suggestion'] ) ) {
			$meta['feedback_suggestion'] = $feedback_item['suggestion'];
		}

		// Add block ID (clientId) if available.
		// Note: clientId is ephemeral and changes on each editor load.
		if ( ! empty( $feedback_item['block_id'] ) ) {
			$meta['block_id'] = $feedback_item['block_id'];
		}

		// Add block name for more stable identification.
		if ( ! empty( $feedback_item['block_name'] ) ) {
			$meta['block_name'] = $feedback_item['block_name'];
		}

		// Add review metadata.
		if ( ! empty( $review_data['review_id'] ) ) {
			$meta['review_id'] = $review_data['review_id'];
		}

		if ( ! empty( $review_data['model'] ) ) {
			$meta['ai_model'] = $review_data['model'];
		}

		if ( ! empty( $review_data['timestamp'] ) ) {
			$meta['created_at'] = $review_data['timestamp'];
		}

		// Add grouping metadata if this is a grouped item.
		if ( ! empty( $feedback_item['is_group'] ) ) {
			$meta['is_group']    = '1';
			$meta['group_count'] = $feedback_item['count'] ?? 1;
			if ( ! empty( $feedback_item['block_ids'] ) ) {
				$meta['group_block_ids'] = wp_json_encode( $feedback_item['block_ids'] );
			}
			if ( ! empty( $feedback_item['original_title'] ) ) {
				$meta['original_title'] = $feedback_item['original_title'];
			}
		}

		Logger::debug(
			sprintf(
				'Built note meta: block_id=%s, block_name=%s, category=%s, severity=%s, is_group=%s',
				$meta['block_id'] ?? 'none',
				$meta['block_name'] ?? 'none',
				$meta['feedback_category'],
				$meta['feedback_severity'],
				! empty( $meta['is_group'] ) ? 'yes' : 'no'
			)
		);

		return $meta;
	}

	/**
	 * Get notes for a post.
	 *
	 * @param  int  $post_id Post ID.
	 * @param  bool $ai_only Whether to only get AI feedback notes.
	 * @return array Array of notes.
	 */
	public function get_notes_for_post( int $post_id, bool $ai_only = false ): array {
		$args = array(
			'post_id' => $post_id,
			'type'    => 'note',
			'status'  => 'all',
		);

		if ( $ai_only ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = array(
				array(
					'key'   => 'ai_feedback',
					'value' => '1',
				),
			);
		}

		$comments = get_comments( $args );

		return $this->format_notes( $comments );
	}

	/**
	 * Get notes by review ID.
	 *
	 * @param  string $review_id Review ID.
	 * @return array Array of notes.
	 */
	public function get_notes_by_review( string $review_id ): array {
		$args = array(
			'type'       => 'note',
			'status'     => 'all',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => array(
				array(
					'key'   => 'review_id',
					'value' => $review_id,
				),
			),
		);

		$comments = get_comments( $args );

		return $this->format_notes( $comments );
	}

	/**
	 * Read a numeric comment meta value as an integer.
	 *
	 * @param  int    $comment_id Comment ID.
	 * @param  string $key        Meta key.
	 * @return int|null Integer value, or null when the meta is missing or not numeric.
	 */
	private function get_int_meta( int $comment_id, string $key ): ?int {
		$value = get_comment_meta( $comment_id, $key, true );
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Format notes for API response.
	 *
	 * @param  array $comments Array of comment objects.
	 * @return array Formatted notes.
	 */
	private function format_notes( array $comments ): array {
		$notes = array();

		foreach ( $comments as $comment ) {
			$is_group       = get_comment_meta( $comment->comment_ID, 'is_group', true );
			$block_ids      = array();
			$group_count    = 1;
			$original_title = '';

			// If this is a grouped note, decode the block_ids.
			if ( $is_group ) {
				$group_block_ids = get_comment_meta( $comment->comment_ID, 'group_block_ids', true );
				if ( ! empty( $group_block_ids ) ) {
					$decoded = json_decode( $group_block_ids, true );
					if ( is_array( $decoded ) ) {
						$block_ids = $decoded;
					} else {
						// Log JSON decode errors for debugging.
						Logger::debug(
							sprintf(
								'Failed to decode group_block_ids for note %d: %s',
								$comment->comment_ID,
								json_last_error_msg()
							)
						);
					}
				}
				$group_count    = (int) get_comment_meta( $comment->comment_ID, 'group_count', true );
				$original_title = get_comment_meta( $comment->comment_ID, 'original_title', true );
			}

			$note = array(
				'id'          => (int) $comment->comment_ID,
				'post'        => (int) $comment->comment_post_ID,
				'parent'      => (int) $comment->comment_parent,
				'author_name' => $comment->comment_author,
				'content'     => array(
					'raw'      => $comment->comment_content,
					'rendered' => wp_kses_post( $comment->comment_content ),
				),
				'date'        => $comment->comment_date,
				'type'        => $comment->comment_type,
				'status'      => ( '1' === $comment->comment_approved ) ? 'approved' : 'hold',
				'block_id'    => get_comment_meta( $comment->comment_ID, 'block_id', true ),
				'block_index' => $this->get_int_meta( (int) $comment->comment_ID, 'block_index' ),
				'category'    => get_comment_meta( $comment->comment_ID, 'feedback_category', true ),
				'severity'    => get_comment_meta( $comment->comment_ID, 'feedback_severity', true ),
				'priority'    => $this->get_int_meta( (int) $comment->comment_ID, 'feedback_priority' ),
				'review_id'   => get_comment_meta( $comment->comment_ID, 'review_id', true ),
				'ai_model'    => get_comment_meta( $comment->comment_ID, 'ai_model', true ),
				'created_at'  => $comment->comment_date,
				'is_resolved' => $this->is_note_resolved( $comment->comment_ID ),
			);

			$note += $this->get_feedback_fields( $note['id'] );

			// Add grouping information if available.
			if ( $is_group ) {
				$note['is_group']       = true;
				$note['count']          = $group_count;
				$note['block_ids']      = $block_ids;
				$note['original_title'] = $original_title;
			}

			$notes[] = $note;
		}

		return $notes;
	}

	/**
	 * Resolve a note.
	 *
	 * @param  int $note_id Note ID.
	 * @return bool Success.
	 */
	public function resolve_note( int $note_id ): bool {
		// update_comment_meta() returns false when the value is unchanged.
		if ( $this->is_note_resolved( $note_id ) ) {
			return true;
		}

		return (bool) update_comment_meta( $note_id, 'ai_feedback_resolved', true );
	}

	/**
	 * Unresolve a note.
	 *
	 * @param  int $note_id Note ID.
	 * @return bool Success.
	 */
	public function unresolve_note( int $note_id ): bool {
		// delete_comment_meta() returns false when there is nothing to delete.
		if ( ! $this->is_note_resolved( $note_id ) ) {
			return true;
		}

		return delete_comment_meta( $note_id, 'ai_feedback_resolved' );
	}

	/**
	 * Get the top-level note of the thread a note belongs to.
	 *
	 * Feedback for one block shares a thread: the first item is the
	 * top-level note and the rest are replies to it.
	 *
	 * The parent ID comes from stored data, so it is only trusted when it is a
	 * note on the same post that the current user can edit.
	 *
	 * @param  int $note_id Note ID.
	 * @return int Thread (top-level note) ID, or 0 if there is no valid thread.
	 */
	public function get_thread_id( int $note_id ): int {
		$note = get_comment( $note_id );

		if ( ! $note instanceof \WP_Comment || 'note' !== $note->comment_type ) {
			return 0;
		}

		$parent = (int) $note->comment_parent;
		$thread = $parent > 0 ? get_comment( $parent ) : $note;

		if (
			! $thread instanceof \WP_Comment
			|| 'note' !== $thread->comment_type
			|| (int) $thread->comment_post_ID !== (int) $note->comment_post_ID
			|| ! current_user_can( 'edit_post', (int) $thread->comment_post_ID )
		) {
			return 0;
		}

		return (int) $thread->comment_ID;
	}

	/**
	 * Check whether every AI feedback note in a thread is dismissed.
	 *
	 * Replies written by people don't count.
	 *
	 * @param  int $thread_id Thread (top-level note) ID.
	 * @return bool True if all AI notes in the thread are resolved.
	 */
	public function is_ai_feedback_in_thread_resolved( int $thread_id ): bool {
		$replies = get_comments(
			array(
				'type'   => 'note',
				'status' => 'all',
				'parent' => $thread_id,
			)
		);

		$note_ids = array( $thread_id );
		if ( is_array( $replies ) ) {
			foreach ( $replies as $reply ) {
				if ( $reply instanceof \WP_Comment ) {
					$note_ids[] = (int) $reply->comment_ID;
				}
			}
		}

		foreach ( $note_ids as $note_id ) {
			if ( get_comment_meta( $note_id, 'ai_feedback', true ) && ! $this->is_note_resolved( $note_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether a thread is resolved in the editor's Notes panel.
	 *
	 * Core marks a resolved thread by approving its top-level note.
	 *
	 * @param  int $thread_id Thread (top-level note) ID.
	 * @return bool True if resolved.
	 */
	public function is_thread_resolved( int $thread_id ): bool {
		$thread = get_comment( $thread_id );

		return $thread instanceof \WP_Comment && '1' === (string) $thread->comment_approved;
	}

	/**
	 * Resolve or reopen a thread the way the editor's Notes panel does.
	 *
	 * Sets the top-level note's status and adds an empty reply carrying
	 * `_wp_note_status`, which the Notes panel shows as "resolved"/"reopened".
	 * Does nothing unless the ID is a top-level note on a post the current
	 * user can edit.
	 *
	 * @param  int  $thread_id Thread (top-level note) ID.
	 * @param  bool $resolved  True to resolve, false to reopen.
	 * @return bool True if the thread status changed.
	 */
	public function set_thread_resolved( int $thread_id, bool $resolved ): bool {
		$thread = get_comment( $thread_id );

		if (
			! $thread instanceof \WP_Comment
			|| 'note' !== $thread->comment_type
			|| (int) $thread->comment_parent > 0
			|| ! current_user_can( 'edit_post', (int) $thread->comment_post_ID )
			|| $this->is_thread_resolved( $thread_id ) === $resolved
		) {
			return false;
		}

		if ( ! wp_set_comment_status( $thread_id, $resolved ? 'approve' : 'hold' ) ) {
			return false;
		}

		$user = wp_get_current_user();

		wp_insert_comment(
			array(
				'comment_post_ID'  => (int) $thread->comment_post_ID,
				'comment_type'     => 'note',
				'comment_content'  => '',
				'comment_approved' => $resolved ? '1' : '0',
				'comment_parent'   => $thread_id,
				'user_id'          => $user->ID,
				'comment_author'   => $user->display_name,
				'comment_meta'     => array(
					'_wp_note_status' => $resolved ? 'resolved' : 'reopen',
				),
			)
		);

		return true;
	}

	/**
	 * Keep a note's thread in step with its dismissed AI feedback.
	 *
	 * Resolves the thread once the last AI feedback item in it is dismissed,
	 * and reopens it when an item is restored. Dismissing never reopens a
	 * thread someone resolved by hand.
	 *
	 * @param  int  $note_id   Note that was just dismissed or restored.
	 * @param  bool $dismissed True if the note was dismissed, false if restored.
	 * @return array{thread_id: int, thread_resolved: bool, thread_changed: bool} Thread state.
	 */
	public function sync_thread_status( int $note_id, bool $dismissed ): array {
		$thread_id = $this->get_thread_id( $note_id );

		if ( 0 === $thread_id ) {
			return array(
				'thread_id'       => 0,
				'thread_resolved' => false,
				'thread_changed'  => false,
			);
		}

		$changed = false;
		if ( ! $dismissed ) {
			$changed = $this->set_thread_resolved( $thread_id, false );
		} elseif ( $this->is_ai_feedback_in_thread_resolved( $thread_id ) ) {
			$changed = $this->set_thread_resolved( $thread_id, true );
		}

		return array(
			'thread_id'       => $thread_id,
			'thread_resolved' => $this->is_thread_resolved( $thread_id ),
			'thread_changed'  => $changed,
		);
	}

	/**
	 * Check if note is resolved.
	 *
	 * @param  int $note_id Note ID.
	 * @return bool Whether note is resolved.
	 */
	public function is_note_resolved( int $note_id ): bool {
		return (bool) get_comment_meta( $note_id, 'ai_feedback_resolved', true );
	}

	/**
	 * Delete notes by review ID.
	 *
	 * @param  string $review_id Review ID.
	 * @return int Number of notes deleted.
	 */
	public function delete_notes_by_review( string $review_id ): int {
		$notes = $this->get_notes_by_review( $review_id );
		$count = 0;

		foreach ( $notes as $note ) {
			if ( wp_delete_comment( $note['id'], true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Get notes with their reply threads.
	 *
	 * @param  int  $post_id          Post ID.
	 * @param  bool $exclude_resolved Whether to exclude resolved notes.
	 * @return array Array of notes with replies.
	 */
	public function get_notes_with_replies( int $post_id, bool $exclude_resolved = true ): array {
		// Get top-level AI feedback notes (parent = 0).
		$args = array(
			'post_id'    => $post_id,
			'type'       => 'note',
			'status'     => 'all',
			'parent'     => 0,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => array(
				array(
					'key'   => 'ai_feedback',
					'value' => '1',
				),
			),
		);

		$top_level_notes    = get_comments( $args );
		$notes_with_replies = array();

		foreach ( $top_level_notes as $note ) {
			// Skip resolved notes if requested.
			if ( $exclude_resolved && $this->is_note_resolved( (int) $note->comment_ID ) ) {
				continue;
			}

			// Get all replies for this note.
			$replies = get_comments(
				array(
					'post_id' => $post_id,
					'type'    => 'note',
					'status'  => 'all',
					'parent'  => (int) $note->comment_ID,
					'orderby' => 'comment_date',
					'order'   => 'ASC',
				)
			);

			$formatted_note            = $this->format_single_note( $note );
			$formatted_note['replies'] = array();

			foreach ( $replies as $reply ) {
				$formatted_note['replies'][] = $this->format_single_note( $reply );
			}

			$notes_with_replies[] = $formatted_note;
		}

		return $notes_with_replies;
	}

	/**
	 * Format a single note/comment for API response.
	 *
	 * @param  object $comment Comment object.
	 * @return array Formatted note.
	 */
	private function format_single_note( object $comment ): array {
		$is_ai = (bool) get_comment_meta( $comment->comment_ID, 'ai_feedback', true );

		$note = array(
			'id'          => (int) $comment->comment_ID,
			'post'        => (int) $comment->comment_post_ID,
			'parent'      => (int) $comment->comment_parent,
			'author_name' => $comment->comment_author,
			'content'     => array(
				'raw'      => $comment->comment_content,
				'rendered' => wp_kses_post( $comment->comment_content ),
			),
			'date'        => $comment->comment_date,
			'type'        => $comment->comment_type,
			'status'      => ( '1' === $comment->comment_approved ) ? 'approved' : 'hold',
			'block_id'    => get_comment_meta( $comment->comment_ID, 'block_id', true ),
			'block_name'  => get_comment_meta( $comment->comment_ID, 'block_name', true ),
			'block_index' => get_comment_meta( $comment->comment_ID, 'block_index', true ),
			'category'    => get_comment_meta( $comment->comment_ID, 'feedback_category', true ),
			'severity'    => get_comment_meta( $comment->comment_ID, 'feedback_severity', true ),
			'review_id'   => get_comment_meta( $comment->comment_ID, 'review_id', true ),
			'ai_model'    => get_comment_meta( $comment->comment_ID, 'ai_model', true ),
			'created_at'  => $comment->comment_date,
			'is_resolved' => $this->is_note_resolved( $comment->comment_ID ),
			'is_ai'       => $is_ai,
		);

		return $note + $this->get_feedback_fields( $note['id'] );
	}

	/**
	 * Get the stored feedback text fields for a note.
	 *
	 * Notes created before these fields were stored return empty strings; the
	 * sidebar falls back to the note content for those.
	 *
	 * @param  int $note_id Note ID.
	 * @return array{title: string, feedback: string, suggestion: string} Feedback fields.
	 */
	private function get_feedback_fields( int $note_id ): array {
		$fields = array(
			'title'      => 'feedback_title',
			'feedback'   => 'feedback_text',
			'suggestion' => 'feedback_suggestion',
		);

		$values = array();
		foreach ( $fields as $field => $meta_key ) {
			$value            = get_comment_meta( $note_id, $meta_key, true );
			$values[ $field ] = is_string( $value ) ? $value : '';
		}

		return $values;
	}

	/**
	 * Find existing note thread for a block.
	 *
	 * Attempts to match by block_id first, then falls back to block_name + block_index.
	 *
	 * @param  int         $post_id     Post ID.
	 * @param  string      $block_id    Block client ID.
	 * @param  string|null $block_name  Block name (e.g., 'core/paragraph').
	 * @param  int|null    $block_index Block index in the document.
	 * @return int|null Note ID if found, null otherwise.
	 */
	public function get_existing_note_for_block( int $post_id, string $block_id, ?string $block_name = null, ?int $block_index = null ): ?int {
		// First, try to find by exact block_id match.
		$args = array(
			'post_id'    => $post_id,
			'type'       => 'note',
			'status'     => 'all',
			'parent'     => 0,
			'number'     => 1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key'   => 'ai_feedback',
					'value' => '1',
				),
				array(
					'key'   => 'block_id',
					'value' => $block_id,
				),
			),
		);

		$notes = get_comments( $args );
		if ( ! empty( $notes ) ) {
			return (int) $notes[0]->comment_ID;
		}

		// Fall back to block_name + block_index if provided.
		if ( null !== $block_name && null !== $block_index ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = array(
				'relation' => 'AND',
				array(
					'key'   => 'ai_feedback',
					'value' => '1',
				),
				array(
					'key'   => 'block_name',
					'value' => $block_name,
				),
				array(
					'key'   => 'block_index',
					'value' => $block_index,
				),
			);

			$notes = get_comments( $args );
			if ( ! empty( $notes ) ) {
				return (int) $notes[0]->comment_ID;
			}
		}

		return null;
	}

	/**
	 * Get the latest review data for a post.
	 *
	 * Reconstructs a review-like structure from persisted notes.
	 *
	 * @param  int $post_id Post ID.
	 * @return array|null Review data or null if no reviews exist.
	 */
	public function get_latest_review_for_post( int $post_id ): ?array {
		// Get all AI feedback notes for this post.
		$notes = $this->get_notes_with_replies( $post_id, false );

		if ( empty( $notes ) ) {
			return null;
		}

		// Find the most recent review_id.
		$review_ids       = array();
		$latest_date      = null;
		$latest_review_id = null;

		foreach ( $notes as $note ) {
			$review_id = $note['review_id'] ?? '';
			if ( empty( $review_id ) ) {
				continue;
			}

			if ( ! isset( $review_ids[ $review_id ] ) ) {
				$review_ids[ $review_id ] = array(
					'date'  => $note['date'],
					'notes' => array(),
				);
			}
			$review_ids[ $review_id ]['notes'][] = $note;

			// Track the latest review.
			if ( null === $latest_date || $note['date'] > $latest_date ) {
				$latest_date      = $note['date'];
				$latest_review_id = $review_id;
			}
		}

		if ( null === $latest_review_id ) {
			return null;
		}

		$review_notes = $review_ids[ $latest_review_id ]['notes'];

		// Build block mapping from notes.
		$block_mapping = array();
		foreach ( $review_notes as $note ) {
			$block_id = $note['block_id'] ?? '';
			if ( ! empty( $block_id ) && 0 === $note['parent'] ) {
				$block_mapping[ $block_id ] = $note['id'];
			}
		}

		// Calculate summary statistics.
		$summary = $this->calculate_summary( $review_notes );

		// Get model from first note.
		$model = '';
		foreach ( $review_notes as $note ) {
			if ( ! empty( $note['ai_model'] ) ) {
				$model = $note['ai_model'];
				break;
			}
		}

		return array(
			'review_id'     => $latest_review_id,
			'post_id'       => $post_id,
			'model'         => $model,
			'notes'         => $review_notes,
			'note_ids'      => array_column( $review_notes, 'id' ),
			'block_mapping' => $block_mapping,
			'summary'       => $summary,
			'note_count'    => count( $review_notes ),
			'timestamp'     => $latest_date,
			'is_persisted'  => true,
		);
	}

	/**
	 * Calculate summary statistics from notes.
	 *
	 * @param  array $notes Array of notes.
	 * @return array Summary statistics.
	 */
	private function calculate_summary( array $notes ): array {
		$by_severity = array(
			'critical'   => 0,
			'important'  => 0,
			'suggestion' => 0,
		);

		$by_category = array(
			'content' => 0,
			'tone'    => 0,
			'flow'    => 0,
			'design'  => 0,
		);

		foreach ( $notes as $note ) {
			// Only count top-level notes (not replies).
			if ( $note['parent'] > 0 ) {
				continue;
			}

			$severity = $note['severity'] ?? '';
			$category = $note['category'] ?? '';

			if ( isset( $by_severity[ $severity ] ) ) {
				++$by_severity[ $severity ];
			}

			if ( isset( $by_category[ $category ] ) ) {
				++$by_category[ $category ];
			}
		}

		return array(
			'by_severity' => $by_severity,
			'by_category' => $by_category,
		);
	}

	/**
	 * Add feedback as a reply to an existing note thread.
	 *
	 * @param  array $feedback_item Feedback item data.
	 * @param  int   $post_id       Post ID.
	 * @param  int   $parent_id     Parent note ID.
	 * @param  array $review_data   Review metadata.
	 * @return int|WP_Error Note ID or error.
	 */
	public function add_reply_to_thread( array $feedback_item, int $post_id, int $parent_id, array $review_data = array() ): int|WP_Error {
		return $this->create_note( $feedback_item, $post_id, $review_data, $parent_id );
	}
}
