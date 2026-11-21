<?php
/**
 * Checks what `wp plugin uninstall` left behind after seed.php. Run with
 * `wp eval-file`; exits non-zero when anything is wrong.
 *
 * @package AI_Feedback
 */

// phpcs:disable

$ids    = get_option( 'uninstall_check_ids' );
$errors = array();
$check  = static function ( bool $ok, string $what ) use ( &$errors ): void {
	if ( ! $ok ) {
		$errors[] = $what;
	}
	WP_CLI::log( ( $ok ? 'ok   ' : 'FAIL ' ) . $what );
};

global $wpdb;
$options = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, '%ai\_feedback%', '%ai-feedback%' ) );
$check( array() === $options, 'no plugin options or transients left' . ( $options ? ': ' . implode( ', ', $options ) : '' ) );
$check( 'kept' === get_option( 'uninstall_check_other_plugin' ), 'other plugin option kept' );

$user_keys = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_key FROM %i WHERE user_id = %d AND meta_key LIKE %s', $wpdb->usermeta, $ids['user'], '%ai\_feedback%' ) );
$check( array( 'myplugin_ai_feedback_personal_house_style' ) === $user_keys, 'only the look-alike user meta key left: ' . implode( ', ', $user_keys ) );
$prefs = get_user_meta( $ids['user'], $ids['prefs_key'], true );
$check( is_array( $prefs ) && ! isset( $prefs['ai-feedback'] ) && isset( $prefs['core/edit-post'] ), 'editor preferences scope removed, others kept' );

$check( '' === get_post_meta( $ids['post'], '_ai_feedback_history', true ), 'review history removed' );
$check( 'kept' === get_post_meta( $ids['post'], 'uninstall_check_other_meta', true ), 'other post meta kept' );

$note = get_comment( $ids['note'] );
$check( $note instanceof WP_Comment && 'note' === $note->comment_type, 'note kept' );
$note_keys = array_keys( get_comment_meta( $ids['note'] ) );
$kept      = $ids['kept_note_meta'];
sort( $note_keys );
sort( $kept );
$check( $kept === $note_keys, 'note meta kept, feedback_revision removed: ' . implode( ', ', $note_keys ) );

$check( false === wp_next_scheduled( 'ai_feedback_generate_reply', array( $ids['note'] ) ), 'scheduled reply removed' );
$check( $ids['fresh_eyes'] > 0 && false === get_userdata( $ids['fresh_eyes'] ), 'Fresh Eyes user deleted' );

delete_option( 'uninstall_check_ids' );
delete_option( 'uninstall_check_other_plugin' );
wp_delete_post( $ids['post'], true );

if ( $errors ) {
	WP_CLI::error( count( $errors ) . ' uninstall checks failed.' );
}
WP_CLI::success( 'Uninstall removed the plugin data and kept the notes.' );
