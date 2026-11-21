<?php
/**
 * Seeds a site with everything the plugin stores, plus data that isn't the
 * plugin's, before `wp plugin uninstall`. Run with `wp eval-file`.
 *
 * @package AI_Feedback
 */

// phpcs:disable

$admin = get_user_by( 'login', 'admin' );
$uid   = (int) $admin->ID;

// The Fresh Eyes user's own options are left as the plugin set them, so
// uninstall can find the user.
$user_options = array( AI_Feedback\Fresh_Eyes_User::OPTION, AI_Feedback\Fresh_Eyes_User::CHECKED_OPTION, 'ai_feedback_user_lock' );
foreach ( array_diff( AI_Feedback\Uninstaller::OPTIONS, $user_options ) as $option ) {
	update_option( $option, 'uninstall-check' );
}
update_option( 'ai_feedback_chunk_lock_' . $uid, (string) time() );
update_option( 'uninstall_check_other_plugin', 'kept' );
set_transient( 'ai_feedback_chunked_review_' . $uid, array( 'review_id' => 'r1' ) );
set_transient( 'ai_feedback_available_models_v4', array( 'models' ) );
set_transient( 'ai_feedback_user_retry', 1 );

foreach ( AI_Feedback\Uninstaller::USER_META as $key ) {
	update_user_meta( $uid, $key, 'uninstall-check' );
}
update_user_meta( $uid, AI_Feedback\House_Style::user_meta_key(), array( array( 'id' => 'r1', 'text' => 'Use sentence case' ) ) );
update_user_meta( $uid, AI_Feedback\House_Style::USER_META_KEY, array( array( 'id' => 'r2', 'text' => 'Legacy rule' ) ) );
update_user_meta( $uid, $GLOBALS['wpdb']->base_prefix . '99_' . AI_Feedback\House_Style::USER_META_KEY, array( array( 'id' => 'r3', 'text' => 'Rule on a site the user left' ) ) );
update_user_meta( $uid, 'myplugin_ai_feedback_personal_house_style', 'kept' );
$prefs_key = $GLOBALS['wpdb']->get_blog_prefix() . 'persisted_preferences';
update_user_meta(
	$uid,
	$prefs_key,
	array(
		'core/edit-post' => array( 'welcomeGuide' => false ),
		'ai-feedback'    => array( 'welcomed' => true ),
	)
);

$post_id = wp_insert_post(
	array(
		'post_title'   => 'Uninstall check',
		'post_content' => '<!-- wp:paragraph --><p>Text.</p><!-- /wp:paragraph -->',
		'post_status'  => 'draft',
		'post_author'  => $uid,
	)
);
update_post_meta( $post_id, AI_Feedback\Review_History::META_KEY, wp_slash( array( array( 'review_id' => 'r1' ) ) ) );
update_post_meta( $post_id, 'uninstall_check_other_meta', 'kept' );

$note_id = wp_insert_comment(
	array(
		'comment_post_ID'  => $post_id,
		'comment_type'     => 'note',
		'comment_content'  => 'Add a source.',
		'comment_approved' => 0,
		'comment_author'   => 'Fresh Eyes',
	)
);
foreach ( AI_Feedback\Uninstaller::KEPT_COMMENT_META as $key ) {
	update_comment_meta( $note_id, $key, 'kept' );
}
update_comment_meta( $note_id, 'feedback_revision', 2 );

wp_schedule_single_event( time() + HOUR_IN_SECONDS, AI_Feedback\Reply_Cron_Dispatcher::CRON_HOOK, array( $note_id ) );

update_option( 'uninstall_check_ids', array( 'user' => $uid, 'post' => $post_id, 'note' => $note_id, 'fresh_eyes' => AI_Feedback\Fresh_Eyes_User::get_id(), 'prefs_key' => $prefs_key, 'kept_note_meta' => AI_Feedback\Uninstaller::KEPT_COMMENT_META ) );

WP_CLI::success( sprintf( 'Seeded post %d, note %d, Fresh Eyes user %d.', $post_id, $note_id, AI_Feedback\Fresh_Eyes_User::get_id() ) );
