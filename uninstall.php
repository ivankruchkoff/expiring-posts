<?php
/**
 * Uninstall: remove the scheduled event.
 *
 * Expiry dates and expired posts are left in place so that nothing is lost
 * if the plugin is reinstalled.
 *
 * @package ExpiringPosts
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'exp_expire_post_event' );
delete_transient( 'exp_schedule_checked' );
