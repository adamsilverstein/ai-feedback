<?php
/**
 * Uninstall Fresh Eyes.
 *
 * Removes the hidden Fresh Eyes user. It authors nothing, so nothing is
 * reassigned. On multisite the user is shared by the network and removed once.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-logger.php';
require_once __DIR__ . '/includes/class-fresh-eyes-user.php';

Fresh_Eyes_User::uninstall();
