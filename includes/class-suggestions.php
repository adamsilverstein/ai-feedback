<?php
/**
 * Gutenberg Suggestion Mode integration.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the Gutenberg suggestions that AI quick fixes become.
 *
 * A suggestion is a note carrying a JSON payload in `_wp_suggestion` comment
 * meta, plus `<mark>` markers in the block content. The editor writes both
 * through the core comments endpoint, the same way Gutenberg's own suggester
 * does. Requires the Gutenberg "Suggestion Mode" experiment.
 */
class Suggestions {

	/**
	 * Gutenberg experiment that provides suggestions.
	 */
	public const EXPERIMENT = 'gutenberg-suggestion-mode';

	/**
	 * Comment meta key holding the suggestion payload.
	 */
	public const PAYLOAD_META = '_wp_suggestion';

	/**
	 * Comment meta key holding the suggestion lifecycle status.
	 */
	public const STATUS_META = '_wp_suggestion_status';

	/**
	 * Payload key naming the AI reply whose edit a thread's root note holds.
	 *
	 * Gutenberg only links a thread's root note to its block, so a reply's
	 * edit is suggested on the root note and this key says which reply it is.
	 * The editor writes it: keep in step with EDIT_OWNER_KEY in
	 * src/utils/suggestions.js.
	 */
	public const EDIT_OWNER_KEY = 'aiFeedbackEditOwner';

	/**
	 * Most notes between a reply and its thread's root, against a parent cycle.
	 */
	private const MAX_THREAD_DEPTH = 10;

	/**
	 * Checks whether Gutenberg's suggestion storage is available.
	 *
	 * @return bool True when the experiment is on and its meta is registered.
	 */
	public static function is_available(): bool {
		$available = function_exists( 'gutenberg_is_experiment_enabled' )
			&& gutenberg_is_experiment_enabled( self::EXPERIMENT )
			&& registered_meta_key_exists( 'comment', self::PAYLOAD_META );

		/**
		 * Filters whether AI quick fixes are added as Gutenberg suggestions.
		 *
		 * @param bool $available Whether Suggestion Mode is available.
		 */
		return (bool) apply_filters( 'ai_feedback_use_suggestions', $available );
	}

	/**
	 * Gutenberg function that only ships with structural suggestions.
	 */
	public const STRUCTURAL_FEATURE = 'gutenberg_strip_pending_structural_suggestions';

	/**
	 * Checks whether Gutenberg supports structural suggestions (adding,
	 * removing and moving blocks), which came after inline ones.
	 *
	 * Detected by a function Gutenberg ships with them, so a Gutenberg that
	 * has only inline suggestions never gets structural ones.
	 *
	 * @return bool True when structural edits may become suggestions.
	 */
	public static function is_structural_available(): bool {
		$available = self::is_available() && function_exists( self::STRUCTURAL_FEATURE );

		/**
		 * Filters whether AI structural edits are added as Gutenberg suggestions.
		 *
		 * @param bool $available Whether structural suggestions are available.
		 */
		return (bool) apply_filters( 'ai_feedback_use_structural_suggestions', $available );
	}

	/**
	 * Returns a note's suggestion status.
	 *
	 * @param  int $note_id Note ID.
	 * @return string|null Status, or null when the note is not a suggestion.
	 */
	public function get_status( int $note_id ): ?string {
		$payload = get_comment_meta( $note_id, self::PAYLOAD_META, true );
		if ( ! is_string( $payload ) || '' === $payload ) {
			return null;
		}

		$status = get_comment_meta( $note_id, self::STATUS_META, true );
		return is_string( $status ) && '' !== $status ? $status : 'pending';
	}

	/**
	 * Returns the note whose edit a note's suggestion is.
	 *
	 * The payload is meta anyone who can edit the note can write, so a reply
	 * it names only counts when it is an AI reply in the note's own thread.
	 * One that is not, or that was deleted since, makes the suggestion
	 * nobody's: it is still a suggestion on the note, but neither the note's
	 * own edit nor any reply's reads its status.
	 *
	 * @param  int $note_id Note ID.
	 * @return int|null The reply named in the payload, the note itself when none is named, 0 when the one named is not an AI reply in the note's thread, or null when the note is not a suggestion.
	 */
	public function get_owner_id( int $note_id ): ?int {
		$payload = get_comment_meta( $note_id, self::PAYLOAD_META, true );
		if ( ! is_string( $payload ) || '' === $payload ) {
			return null;
		}

		$decoded = json_decode( $payload, true );
		$owner   = is_array( $decoded ) ? ( $decoded[ self::EDIT_OWNER_KEY ] ?? null ) : null;
		if ( ! is_int( $owner ) || $owner <= 0 || $owner === $note_id ) {
			return $note_id;
		}
		return self::is_ai_reply_in_thread( $owner, $note_id ) ? $owner : 0;
	}

	/**
	 * Whether a note is an AI reply in a thread: it exists, is not trashed,
	 * is on the root note's post, is Fresh Eyes' own and descends from the
	 * root note.
	 *
	 * @param  int $reply_id Note to check.
	 * @param  int $root_id  The thread's root note.
	 * @return bool
	 */
	private static function is_ai_reply_in_thread( int $reply_id, int $root_id ): bool {
		$reply = get_comment( $reply_id );
		$root  = get_comment( $root_id );
		if (
			! $reply instanceof \WP_Comment ||
			! $root instanceof \WP_Comment ||
			(int) $reply->comment_post_ID !== (int) $root->comment_post_ID ||
			in_array( $reply->comment_approved, array( 'trash', 'spam' ), true ) ||
			! get_comment_meta( $reply_id, 'ai_feedback', true )
		) {
			return false;
		}

		$note = $reply;
		for ( $depth = 0; $depth < self::MAX_THREAD_DEPTH; $depth++ ) {
			$parent_id = (int) $note->comment_parent;
			if ( $parent_id === $root_id ) {
				return true;
			}
			$note = $parent_id > 0 ? get_comment( $parent_id ) : null;
			if ( ! $note instanceof \WP_Comment ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * Returns the suggestion status of one edit in a thread.
	 *
	 * A reply's edit may be suggested on the thread's root note, and then the
	 * root note's own edit is not a suggestion.
	 *
	 * @param  int $edit_id Note that proposed the edit: the root note or an AI reply.
	 * @param  int $root_id The thread's root note.
	 * @return string|null Status, or null when the edit is not a suggestion.
	 */
	public function get_edit_status( int $edit_id, int $root_id ): ?string {
		$root_owner = $this->get_owner_id( $root_id );
		if ( null === $root_owner || $root_owner === $root_id ) {
			return $this->get_status( $edit_id );
		}
		if ( $edit_id === $root_owner ) {
			return $this->get_status( $root_id );
		}
		// The root note's own edit gave way to a reply's, which may be gone
		// since. Another reply may still hold its own, from before reply edits
		// moved to the root note.
		return $edit_id === $root_id ? null : $this->get_status( $edit_id );
	}

	/**
	 * Seconds a pending AI suggestion is kept before it may be cleaned up.
	 *
	 * An editor holding unsaved markers autosaves them within a minute or so,
	 * into the post for a draft or an autosave revision otherwise, where the
	 * anchor check below finds them.
	 */
	public const CLEANUP_DELAY = HOUR_IN_SECONDS;

	/**
	 * Lists pending AI suggestions whose markers are gone.
	 *
	 * Markers live in the post content, so they are lost when the editor
	 * reloads before saving. A note is listed only when neither the saved post
	 * nor any user's autosave holds its anchor and it is older than the cleanup delay,
	 * so markers that are still unsaved in another editor are left alone. A
	 * reply's edit held by its thread's root note is dated by the reply. A
	 * save alone is not taken as proof, since it may come from an editor that
	 * never had the markers.
	 *
	 * @param  int $post_id Post ID.
	 * @return list<int> Note IDs.
	 */
	public function get_saved_pending_ids( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_available() ) {
			return array();
		}

		$autosaves = self::get_autosaves( $post_id );
		foreach ( $autosaves as $autosave ) {
			if ( $autosave->post_modified_gmt > $post->post_modified_gmt ) {
				return array();
			}
		}

		$notes = get_comments(
			array(
				'post_id'    => $post_id,
				'type'       => 'note',
				'status'     => 'all',
				// Replies too: an AI reply's edit can be a suggestion.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => 'ai_feedback',
						'value' => '1',
					),
					array(
						'key'     => self::PAYLOAD_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		/**
		 * Filters how long a pending AI suggestion is kept before cleanup.
		 *
		 * @param int $delay   Seconds.
		 * @param int $post_id Post ID.
		 */
		$delay   = (int) apply_filters( 'ai_feedback_suggestion_cleanup_delay', self::CLEANUP_DELAY, $post_id );
		$cutoff  = time() - max( 0, $delay );
		$content = array( $post->post_content );
		foreach ( $autosaves as $autosave ) {
			$content[] = $autosave->post_content;
		}

		$ids = array();
		foreach ( $notes as $note ) {
			$note_id = (int) $note->comment_ID;
			// A root note holding a reply's edit is as old as that reply: the
			// suggestion was made when the reply arrived.
			$owner_id = $this->get_owner_id( $note_id );
			$owner    = null !== $owner_id && $owner_id > 0 && $owner_id !== $note_id ? get_comment( $owner_id ) : null;
			$created  = strtotime( ( $owner instanceof \WP_Comment ? $owner : $note )->comment_date_gmt . ' UTC' );
			if (
				'pending' === $this->get_status( $note_id ) &&
				false !== $created &&
				$created <= $cutoff &&
				! self::is_anchored( $note_id, $content )
			) {
				$ids[] = $note_id;
			}
		}

		return $ids;
	}

	/**
	 * Returns every user's autosave of a post.
	 *
	 * `wp_get_post_autosave()` without a user returns only the latest one,
	 * while each editor autosaves separately.
	 *
	 * @param  int $post_id Post ID.
	 * @return list<\WP_Post> Autosave revisions.
	 */
	private static function get_autosaves( int $post_id ): array {
		$autosaves = array();
		foreach ( wp_get_post_revisions( $post_id, array( 'check_enabled' => false ) ) as $revision ) {
			if ( false !== wp_is_post_autosave( $revision ) ) {
				$autosaves[] = $revision;
			}
		}
		return $autosaves;
	}

	/**
	 * Checks whether any of the given content holds a suggestion's anchor.
	 *
	 * @param  int           $note_id  Note ID.
	 * @param  array<string> $contents Post content to search.
	 * @return bool True if a marker for the note is present.
	 */
	private static function is_anchored( int $note_id, array $contents ): bool {
		foreach ( $contents as $content ) {
			if ( function_exists( 'gutenberg_get_suggestion_anchor_index' ) ) {
				if ( isset( gutenberg_get_suggestion_anchor_index( $content )[ $note_id ] ) ) {
					return true;
				}
				continue;
			}
			if ( str_contains( $content, 'data-suggestion-id="' . $note_id . '"' ) ) {
				return true;
			}
			// A structural suggestion's marker sits in the block's attributes.
			if ( 1 === preg_match( '/"commentId":' . $note_id . '(?![0-9])/', $content ) ) {
				return true;
			}
		}
		return false;
	}
}
