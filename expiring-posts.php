<?php
/**
 * Plugin Name:       Expiring Posts
 * Plugin URI:        https://github.com/ivankruchkoff/expiring-posts
 * Description:       Automatically move a post to an "Expired" status on a date you choose. Works with the block editor and the classic editor.
 * Version:           2.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Ivan Kruchkoff
 * Author URI:        https://github.com/ivankruchkoff
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       expiring-posts
 * Domain Path:       /lang
 *
 * @package ExpiringPosts
 */

/*
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
*/

defined( 'ABSPATH' ) || exit;

define( 'EXPIRING_POSTS_VERSION', '2.0.0' );
define( 'EXPIRING_POSTS_FILE', __FILE__ );
define( 'EXPIRING_POSTS_URL', plugin_dir_url( __FILE__ ) );
define( 'EXPIRING_POSTS_DIR', plugin_dir_path( __FILE__ ) );

require_once EXPIRING_POSTS_DIR . 'includes/class-exp-expiring-posts.php';
require_once EXPIRING_POSTS_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'EXP_Expiring_Posts', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EXP_Expiring_Posts', 'deactivate' ) );

EXP_Expiring_Posts::instance();
