<?php
/**
 * Core plugin class.
 *
 * @package ExpiringPosts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Expiring Posts.
 *
 * Expiry data is stored in two post meta keys, unchanged since 1.x so that
 * existing sites keep working:
 *
 * - `exp_expiration_date`    The expiry date in the site's timezone, `Y-m-d H:i:s`.
 * - `exp_pending_expiration` `1` when the post is set to expire on that date.
 *
 * A single WP-Cron event (`exp_expire_post_event`) is always scheduled for the
 * next post that is due to expire.
 */
class EXP_Expiring_Posts { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound,Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- 1.x public class name.

	const STATUS       = 'expired';
	const META_DATE    = 'exp_expiration_date';
	const META_PENDING = 'exp_pending_expiration';
	const CRON_HOOK    = 'exp_expire_post_event';
	const NONCE_ACTION = 'save_expiration_post_meta';
	const NONCE_NAME   = 'expiring_posts_nonce';
	const REST_FIELD   = 'expiring_posts';
	const DATE_FORMAT  = 'Y-m-d H:i:s';

	/**
	 * The only instance of the class.
	 *
	 * @var EXP_Expiring_Posts|null
	 */
	private static $instance = null;

	/**
	 * IDs of posts that moved out of the expired status during this request.
	 *
	 * @var array<int, bool>
	 */
	private $restored = array();

	/**
	 * Whether the cron run is currently expiring posts.
	 *
	 * @var bool
	 */
	private $expiring = false;

	/**
	 * Whether a reschedule has been queued for the end of the request.
	 *
	 * @var bool
	 */
	private $reset_queued = false;

	/**
	 * Returns the main instance.
	 *
	 * @return EXP_Expiring_Posts
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_filter( 'load_script_translation_file', array( $this, 'bundled_script_translation' ), 10, 3 );
		add_action( 'init', array( $this, 'expiring_post_status' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'init', array( $this, 'maybe_repair_schedule' ), 20 );
		add_action( 'rest_api_init', array( $this, 'register_rest_field' ) );

		// Classic editor.
		add_action( 'post_submitbox_misc_actions', array( $this, 'post_meta_box' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'save_post', array( $this, 'save_expiration_date' ), 10, 2 );

		// Block editor.
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_editor_assets' ) );

		// Status changes, deletion and the cron event.
		add_action( 'transition_post_status', array( $this, 'handle_status_transition' ), 10, 3 );
		add_action( self::CRON_HOOK, array( $this, 'check_and_expire_scheduled_post' ) );

		// Expiry meta written by other code (imports, WP-CLI, XML-RPC) or removed
		// when a post is deleted still reschedules.
		add_action( 'added_post_meta', array( $this, 'handle_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'handle_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'handle_meta_change' ), 10, 3 );

		// List tables.
		add_filter( 'display_post_states', array( $this, 'add_expiry_post_states' ), 10, 2 );

		// Hide the meta keys from the Custom Fields box and XML-RPC so they can only
		// be changed through the validated UI and REST field.
		add_filter( 'is_protected_meta', array( $this, 'protect_meta' ), 10, 3 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Setup
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Activation: make sure the next expiry event is scheduled.
	 */
	public static function activate() {
		self::instance()->reset_expiration_event();
	}

	/**
	 * Deactivation: remove the cron event. Expiry dates are kept.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Use the translations bundled in /lang when there is no language pack
	 * from translate.wordpress.org. Language packs always take priority.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'expiring-posts', false, dirname( plugin_basename( EXPIRING_POSTS_FILE ) ) . '/lang' );
	}

	/**
	 * Fall back to the block editor translations bundled in /lang.
	 *
	 * WordPress calls this filter with `false` only after every lookup failed (no
	 * language pack installed, or WordPress before 7.1, which doesn't look in the
	 * plugin's Domain Path for script translations), so language packs still win.
	 *
	 * @param string|false $file   Translation file WordPress is about to load.
	 * @param string       $handle Script handle.
	 * @param string       $domain Text domain.
	 * @return string|false
	 */
	public function bundled_script_translation( $file, $handle, $domain ) {
		if ( false !== $file || 'expiring-posts' !== $domain || 'expiring-posts-block-editor' !== $handle ) {
			return $file;
		}

		$bundled = EXPIRING_POSTS_DIR . 'lang/expiring-posts-' . determine_locale() . '-' . md5( 'inc/js/block-editor.js' ) . '.json';

		return is_readable( $bundled ) ? $bundled : $file;
	}

	/**
	 * Register the custom status for expired posts.
	 */
	public function expiring_post_status() {
		$args = array(
			'label'                     => _x( 'Expired', 'post status', 'expiring-posts' ),
			'public'                    => false,
			'internal'                  => false,
			// Protected: hidden from visitors, but editors can still preview the post.
			'protected'                 => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of expired posts. */
			'label_count'               => _n_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'expiring-posts' ),
		);

		/**
		 * Filters the arguments used to register the `expired` post status.
		 *
		 * @param array $args Arguments passed to register_post_status().
		 */
		$args = apply_filters( 'exp_expired_post_status_args', $args );
		register_post_status( self::STATUS, $args );
	}

	/**
	 * Register the meta keys so every write is sanitised and permission checked.
	 */
	public function register_meta() {
		register_meta(
			'post',
			self::META_DATE,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'normalize_local_date' ),
				'auth_callback'     => array( $this, 'meta_auth_callback' ),
			)
		);

		register_meta(
			'post',
			self::META_PENDING,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_pending_meta' ),
				'auth_callback'     => array( $this, 'meta_auth_callback' ),
			)
		);
	}

	/**
	 * Mark the plugin's meta keys as protected.
	 *
	 * @param bool   $is_protected Whether the key is protected.
	 * @param string $meta_key  Meta key.
	 * @param string $meta_type Object type.
	 * @return bool
	 */
	public function protect_meta( $is_protected, $meta_key, $meta_type = '' ) {
		if ( 'post' === $meta_type && in_array( $meta_key, array( self::META_DATE, self::META_PENDING ), true ) ) {
			return true;
		}

		return $is_protected;
	}

	/**
	 * Capability check used by register_meta().
	 *
	 * @param bool   $allowed   Whether the user can add the meta.
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Post ID.
	 * @return bool
	 */
	public function meta_auth_callback( $allowed, $meta_key, $object_id ) {
		return $this->current_user_can_manage( $object_id );
	}

	/**
	 * Sanitise the pending flag.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_pending_meta( $value ) {
		return $value ? '1' : '';
	}

	/**
	 * Re-create the cron event if it has gone missing (for example after a
	 * cron array was cleared or the site was migrated). Runs at most hourly.
	 */
	public function maybe_repair_schedule() {
		if ( wp_next_scheduled( self::CRON_HOOK ) || get_transient( 'exp_schedule_checked' ) ) {
			return;
		}

		set_transient( 'exp_schedule_checked', 1, HOUR_IN_SECONDS );
		$this->reset_expiration_event();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Post types that support expiry.
	 *
	 * @return string[]
	 */
	public function get_supported_post_types() {
		$post_types = get_post_types( array( 'show_ui' => true ), 'names' );
		$excluded   = array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face' );
		$post_types = array_values( array_diff( $post_types, $excluded ) );

		/**
		 * Filters the post types that can expire.
		 *
		 * @param string[] $post_types Post type names.
		 */
		return array_values( array_filter( (array) apply_filters( 'exp_supported_post_types', $post_types ), 'is_string' ) );
	}

	/**
	 * Post statuses that are checked for expiry. Drafts and pending posts are
	 * never expired.
	 *
	 * @return string[]
	 */
	public function get_expirable_statuses() {
		/**
		 * Filters the post statuses that can move to the expired status.
		 *
		 * @param string[] $statuses Post statuses.
		 */
		$statuses = (array) apply_filters( 'exp_expirable_post_statuses', array( 'publish', 'private', 'future' ) );

		return array_values( array_diff( array_filter( $statuses, 'is_string' ), array( self::STATUS ) ) );
	}

	/**
	 * Whether a post type supports expiry.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function is_supported_post_type( $post_type ) {
		return in_array( $post_type, $this->get_supported_post_types(), true );
	}

	/**
	 * Whether expiry has been disabled for a post with the
	 * `exp_disable_expiration_for_this_post` filter.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function is_disabled_for_post( $post_id ) {
		/**
		 * Disable expiry for a single post. When true the UI is hidden and the
		 * post never expires.
		 *
		 * @param bool $disabled Default false.
		 * @param int  $post_id  Post ID.
		 */
		return true === apply_filters( 'exp_disable_expiration_for_this_post', false, (int) $post_id );
	}

	/**
	 * Whether the current user may change a post's expiry. Requires both the
	 * ability to edit the post and to publish posts of its type, which matches
	 * who can see the expiry controls.
	 *
	 * @param int|WP_Post $post Post.
	 * @return bool
	 */
	public function current_user_can_manage( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}

		$post_type_object = get_post_type_object( $post->post_type );
		$can              = $post_type_object
			&& current_user_can( 'edit_post', $post->ID )
			&& current_user_can( $post_type_object->cap->publish_posts );

		/**
		 * Filters whether the current user can change a post's expiry.
		 *
		 * @param bool    $can  Whether the user can manage expiry.
		 * @param WP_Post $post Post object.
		 */
		return (bool) apply_filters( 'exp_user_can_manage_expiration', $can, $post );
	}

	/**
	 * Normalise a local date string to `Y-m-d H:i:s`.
	 *
	 * Accepts `Y-m-d H:i`, `Y-m-d H:i:s` and the `T` separated form. Anything
	 * else, including impossible dates, returns an empty string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function normalize_local_date( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		if ( ! preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/', trim( $value, " \n\r\t\v\0" ), $m ) ) {
			return '';
		}

		$year   = (int) $m[1];
		$month  = (int) $m[2];
		$day    = (int) $m[3];
		$hour   = (int) $m[4];
		$minute = (int) $m[5];
		$second = isset( $m[6] ) ? (int) $m[6] : 0;

		if ( $year < 1000 || ! checkdate( $month, $day, $year ) || $hour > 23 || $minute > 59 || $second > 59 ) {
			return '';
		}

		return sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second );
	}

	/**
	 * Convert a local `Y-m-d H:i:s` date to a Unix timestamp.
	 *
	 * @param string $local Local date.
	 * @return int|false
	 */
	public static function local_to_timestamp( $local ) {
		$local = self::normalize_local_date( $local );
		if ( '' === $local ) {
			return false;
		}

		$date = date_create_immutable_from_format( self::DATE_FORMAT, $local, wp_timezone() );

		return $date ? $date->getTimestamp() : false;
	}

	/**
	 * Convert a Unix timestamp to a local `Y-m-d H:i:s` date.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function timestamp_to_local( $timestamp ) {
		return (string) wp_date( self::DATE_FORMAT, (int) $timestamp );
	}

	/**
	 * Get a post's expiry settings.
	 *
	 * @param int|WP_Post $post Post.
	 * @return array{enabled: bool, date: string, timestamp: int|false}
	 */
	public function get_expiration( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return array(
				'enabled'   => false,
				'date'      => '',
				'timestamp' => false,
			);
		}

		$date = self::normalize_local_date( get_post_meta( $post->ID, self::META_DATE, true ) );

		return array(
			'enabled'   => (bool) get_post_meta( $post->ID, self::META_PENDING, true ),
			'date'      => $date,
			'timestamp' => self::local_to_timestamp( $date ),
		);
	}

	/**
	 * Store a post's expiry settings and reschedule the cron event.
	 *
	 * @param int    $post_id Post ID.
	 * @param bool   $enabled Whether the post should expire.
	 * @param string $date    Local date. An empty string keeps the stored date.
	 * @return true|WP_Error
	 */
	public function set_expiration( $post_id, $enabled, $date = '' ) {
		$date = self::normalize_local_date( $date );

		if ( '' !== $date ) {
			update_post_meta( $post_id, self::META_DATE, $date );
		} elseif ( $enabled && ! $this->get_expiration( $post_id )['timestamp'] ) {
			return new WP_Error( 'exp_invalid_date', __( 'This post cannot be expired, the expiration date is invalid.', 'expiring-posts' ) );
		}

		if ( $enabled ) {
			update_post_meta( $post_id, self::META_PENDING, '1' );
		} else {
			delete_post_meta( $post_id, self::META_PENDING );
		}

		$this->reset_expiration_event();

		return true;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Status transitions and expiry
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Keep expiry data in step with status changes.
	 *
	 * - Moving to `expired` (manually or by cron) records the expiry date.
	 * - Moving out of `expired` (re-publishing) turns expiry off if the date has
	 *   already passed, so the post is not immediately expired again. A later
	 *   date set in the same save is honoured.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function handle_status_transition( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || ! $post instanceof WP_Post ) {
			return;
		}

		if ( self::STATUS === $new_status ) {
			$timestamp = $this->get_expiration( $post )['timestamp'];
			if ( ! $timestamp || $timestamp > time() ) {
				update_post_meta( $post->ID, self::META_DATE, self::timestamp_to_local( time() ) );
			}
			update_post_meta( $post->ID, self::META_PENDING, '1' );
		} elseif ( self::STATUS === $old_status ) {
			$this->restored[ $post->ID ] = true;

			$timestamp = $this->get_expiration( $post )['timestamp'];
			if ( ! $timestamp || $timestamp <= time() ) {
				delete_post_meta( $post->ID, self::META_PENDING );
			}
		}

		if ( ! $this->expiring && get_post_meta( $post->ID, self::META_PENDING, true ) ) {
			$this->reset_expiration_event();
		}
	}

	/**
	 * Queue a reschedule when expiry meta changes outside the plugin's own code paths.
	 *
	 * @param int|int[] $meta_ids  Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public function handle_meta_change( $meta_ids, $object_id, $meta_key ) {
		if ( $this->expiring || $this->reset_queued || ! in_array( $meta_key, array( self::META_DATE, self::META_PENDING ), true ) ) {
			return;
		}

		$this->reset_queued = true;
		add_action( 'shutdown', array( $this, 'run_queued_reset' ) );
	}

	/**
	 * Run a queued reschedule.
	 */
	public function run_queued_reset() {
		$this->reset_queued = false;
		$this->reset_expiration_event();
	}

	/**
	 * Move a post to the expired status. Mirrors wp_publish_post().
	 *
	 * @param int|WP_Post $post Post.
	 * @return bool Whether the post was expired.
	 */
	public function expire_post( $post ) {
		global $wpdb;

		$post = get_post( $post );
		if ( ! $post || self::STATUS === $post->post_status || $this->is_disabled_for_post( $post->ID ) ) {
			return false;
		}

		$post_before = clone $post;
		$old_status  = $post->post_status;

		$wpdb->update( $wpdb->posts, array( 'post_status' => self::STATUS ), array( 'ID' => $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $post->ID );

		$post->post_status = self::STATUS;
		wp_transition_post_status( self::STATUS, $old_status, $post );

		/** This action is documented in wp-includes/post.php */
		do_action( 'edit_post', $post->ID, $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, as in wp_publish_post().
		/** This action is documented in wp-includes/post.php */
		do_action( "save_post_{$post->post_type}", $post->ID, $post, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, as in wp_publish_post().
		/** This action is documented in wp-includes/post.php */
		do_action( 'save_post', $post->ID, $post, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, as in wp_publish_post().
		/** This action is documented in wp-includes/post.php */
		do_action( 'wp_insert_post', $post->ID, $post, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, as in wp_publish_post().

		wp_after_insert_post( $post, true, $post_before );

		/**
		 * Fires after a post has been moved to the expired status.
		 *
		 * @param int    $post_id    Post ID.
		 * @param string $old_status Status before expiry.
		 */
		do_action( 'exp_post_expired', $post->ID, $old_status );

		return true;
	}

	/**
	 * Turn on expiry for a post, using its stored date.
	 *
	 * @param int|WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public function schedule_post_expiration( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return new WP_Error( 'exp_expiring_posts_error', __( 'This is not a valid post.', 'expiring-posts' ) );
		}

		return $this->set_expiration( $post->ID, true );
	}

	/**
	 * Turn off expiry for a post.
	 *
	 * @param int|WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public function unschedule_expired_post( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return new WP_Error( 'exp_expiring_posts_error', __( 'The post provided is not a valid post.', 'expiring-posts' ) );
		}

		delete_post_meta( $post->ID, self::META_PENDING );

		/**
		 * Fires after expiry is turned off for a post.
		 *
		 * @param int $post_id Post ID.
		 */
		do_action( 'unschedule_expiring_post', $post->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 1.x hook name.

		$this->reset_expiration_event();

		return true;
	}

	/**
	 * Schedule the cron event for the next post due to expire.
	 */
	public function reset_expiration_event() {
		$times = $this->get_expiring_posts();

		if ( ! $times ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		$next_time      = (int) min( $times );
		$next_scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( $next_scheduled && (int) $next_scheduled === $next_time ) {
			return;
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( $next_time, self::CRON_HOOK );
	}

	/**
	 * Cron callback: expire every post that is due, in batches.
	 */
	public function check_and_expire_scheduled_post() {
		$times = $this->get_expiring_posts();

		if ( $times ) {
			asort( $times, SORT_NUMERIC );

			/**
			 * Filters how many posts are expired per cron run. If more are due,
			 * another run is scheduled straight away.
			 *
			 * @param int $batch_size Default 100.
			 */
			$batch = max( 1, (int) apply_filters( 'exp_expire_batch_size', 100 ) );
			$now   = time();
			$count = 0;

			$this->expiring = true;
			foreach ( $times as $post_id => $time ) {
				if ( $time > $now || $count >= $batch ) {
					break;
				}
				if ( $this->expire_post( $post_id ) ) {
					++$count;
				}
			}
			$this->expiring = false;
		}

		$this->reset_expiration_event();
	}

	/**
	 * Get every post that is set to expire.
	 *
	 * @return array<int, int> Post ID => expiry Unix timestamp.
	 */
	public function get_expiring_posts() {
		global $wpdb;

		$statuses   = $this->get_expirable_statuses();
		$post_types = $this->get_supported_post_types();
		if ( ! $statuses || ! $post_types ) {
			return array();
		}

		$status_in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$type_in   = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are generated to match the arrays.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, d.meta_value AS expiry
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} e ON ( e.post_id = p.ID AND e.meta_key = %s )
				INNER JOIN {$wpdb->postmeta} d ON ( d.post_id = p.ID AND d.meta_key = %s )
				WHERE e.meta_value NOT IN ( '', '0' )
				AND p.post_status IN ( $status_in )
				AND p.post_type IN ( $type_in )",
				array_merge( array( self::META_PENDING, self::META_DATE ), $statuses, $post_types )
			)
		);
		// phpcs:enable

		$expiring_posts = array();
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row->ID;
			if ( isset( $expiring_posts[ $post_id ] ) || $this->is_disabled_for_post( $post_id ) ) {
				continue;
			}

			$timestamp = self::local_to_timestamp( $row->expiry );
			if ( $timestamp ) {
				$expiring_posts[ $post_id ] = $timestamp;
			}
		}

		/**
		 * Filters the posts that are scheduled to expire.
		 *
		 * @param array<int, int> $expiring_posts Post ID => Unix timestamp.
		 */
		return (array) apply_filters( 'exp_get_expiring_posts', $expiring_posts );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Classic editor
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether the classic expiry controls should be shown for a post.
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	private function show_ui_for( $post ) {
		return $post instanceof WP_Post
			&& $this->is_supported_post_type( $post->post_type )
			&& ! $this->is_disabled_for_post( $post->ID )
			&& $this->current_user_can_manage( $post );
	}

	/**
	 * Enqueue the classic editor script and styles.
	 *
	 * @param string $hook_suffix Admin page.
	 */
	public function admin_scripts( $hook_suffix ) {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		$post = get_post();
		if ( ! $this->show_ui_for( $post ) ) {
			return;
		}

		global $wp_locale;
		$months = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$months[] = $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) );
		}

		wp_enqueue_script( 'admin-expiring-posts', EXPIRING_POSTS_URL . 'inc/js/expiring-posts.js', array( 'jquery' ), EXPIRING_POSTS_VERSION, true );
		wp_localize_script(
			'admin-expiring-posts',
			'AdminExpiringPosts',
			array(
				/* translators: %s: expiry date. */
				'expires_on'    => __( 'Expires on: %s', 'expiring-posts' ),
				/* translators: %s: expiry date, or the word "Never". */
				'expires_never' => __( 'Expires: %s', 'expiring-posts' ),
				'never'         => __( 'Never', 'expiring-posts' ),
				'expired_text'  => _x( 'Expired', 'post status', 'expiring-posts' ),
				'save_text'     => __( 'Save Post', 'expiring-posts' ),
				'date_format'   => $this->classic_date_format(),
				'months'        => $months,
				'post_status'   => get_post_status( $post ),
			)
		);

		wp_enqueue_style( 'expiring-posts-css', EXPIRING_POSTS_URL . 'inc/css/expiring-posts.css', array(), EXPIRING_POSTS_VERSION );
	}

	/**
	 * Format used for the classic editor label.
	 *
	 * @return string
	 */
	private function classic_date_format() {
		/* translators: 1: month, 2: day, 3: year, 4: hour, 5: minute. */
		return __( '%1$s %2$s, %3$s at %4$s:%5$s', 'expiring-posts' );
	}

	/**
	 * Render the expiry controls in the classic editor's Publish box.
	 *
	 * @param WP_Post|null $post Post being edited.
	 */
	public function post_meta_box( $post = null ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post();
		}

		if ( ! $this->show_ui_for( $post ) || ! $post->ID ) {
			return;
		}

		$expiration = $this->get_expiration( $post );
		$timestamp  = $expiration['timestamp'] ? $expiration['timestamp'] : time() + 5 * DAY_IN_SECONDS;

		$date = sprintf(
			$this->classic_date_format(),
			wp_date( 'M', $timestamp ),
			wp_date( 'j', $timestamp ),
			wp_date( 'Y', $timestamp ),
			wp_date( 'H', $timestamp ),
			wp_date( 'i', $timestamp )
		);

		if ( self::STATUS === $post->post_status ) {
			/* translators: %s: expiry date. */
			$label = sprintf( esc_html__( 'Expired on: %s', 'expiring-posts' ), '<b>' . esc_html( $date ) . '</b>' );
		} elseif ( $expiration['enabled'] ) {
			/* translators: %s: expiry date. */
			$label = sprintf( esc_html__( 'Expires on: %s', 'expiring-posts' ), '<b>' . esc_html( $date ) . '</b>' );
		} else {
			/* translators: %s: expiry date, or the word "Never". */
			$label = sprintf( esc_html__( 'Expires: %s', 'expiring-posts' ), '<b>' . esc_html__( 'Never', 'expiring-posts' ) . '</b>' );
		}

		// The "Expired" option is moved into the core status dropdown by JS.
		?>
		<select style="display:none;" aria-hidden="true" tabindex="-1"><option <?php selected( $post->post_status, self::STATUS ); ?> id="expired-status" value="<?php echo esc_attr( self::STATUS ); ?>"><?php echo esc_html_x( 'Expired', 'post status', 'expiring-posts' ); ?></option></select>
		<div class="misc-pub-section curtime misc-pub-expiry">
			<span id="exp-timestamp"><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></span>
			<a href="#exp-timestampdiv" class="exp-edit-timestamp hide-if-no-js" role="button"><span aria-hidden="true"><?php esc_html_e( 'Edit', 'expiring-posts' ); ?></span> <span class="screen-reader-text"><?php esc_html_e( 'Edit expiry date and time', 'expiring-posts' ); ?></span></a>
			<fieldset id="exp-timestampdiv" class="hide-if-js">
				<legend class="screen-reader-text"><?php esc_html_e( 'Expiry date and time', 'expiring-posts' ); ?></legend>
				<?php $this->select_time( $post->ID, $timestamp, $expiration['enabled'] ); ?>
			</fieldset>
		</div>
		<?php
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
	}

	/**
	 * Render the date and time inputs, modelled on core's touch_time().
	 *
	 * @param int  $post_id   Post ID.
	 * @param int  $timestamp Timestamp to pre-fill.
	 * @param bool $enabled   Whether expiry is turned on.
	 */
	public function select_time( $post_id, $timestamp = 0, $enabled = null ) {
		global $wp_locale;

		if ( ! $timestamp ) {
			$expiration = $this->get_expiration( $post_id );
			$timestamp  = $expiration['timestamp'] ? $expiration['timestamp'] : time() + 5 * DAY_IN_SECONDS;
			$enabled    = $expiration['enabled'];
		}

		$values = array(
			'mm' => wp_date( 'm', $timestamp ),
			'jj' => wp_date( 'd', $timestamp ),
			'aa' => wp_date( 'Y', $timestamp ),
			'hh' => wp_date( 'H', $timestamp ),
			'mn' => wp_date( 'i', $timestamp ),
		);

		$month = '<label><span class="screen-reader-text">' . esc_html__( 'Month', 'expiring-posts' ) . '</span><select id="exp-mm" name="exp-mm">' . "\n";
		for ( $i = 1; $i <= 12; $i++ ) {
			$monthnum = zeroise( $i, 2 );
			$month   .= sprintf(
				'<option value="%1$s" data-text="%2$s"%3$s>%4$s</option>' . "\n",
				esc_attr( $monthnum ),
				esc_attr( $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) ) ),
				selected( $values['mm'], $monthnum, false ),
				/* translators: 1: month number (01, 02, etc.), 2: month abbreviation. */
				esc_html( sprintf( __( '%1$s-%2$s', 'expiring-posts' ), $monthnum, $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) ) ) )
			);
		}
		$month .= '</select></label>';

		$input = static function ( $name, $label, $value, $size ) {
			return sprintf(
				'<label><span class="screen-reader-text">%1$s</span><input type="text" id="exp-%2$s" name="exp-%2$s" value="%3$s" size="%4$d" maxlength="%4$d" inputmode="numeric" autocomplete="off" /></label>',
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( $value ),
				(int) $size
			);
		};

		$day    = $input( 'jj', __( 'Day', 'expiring-posts' ), $values['jj'], 2 );
		$year   = $input( 'aa', __( 'Year', 'expiring-posts' ), $values['aa'], 4 );
		$hour   = $input( 'hh', __( 'Hour', 'expiring-posts' ), $values['hh'], 2 );
		$minute = $input( 'mn', __( 'Minute', 'expiring-posts' ), $values['mn'], 2 );

		echo '<div class="exp-timestamp-wrap">';
		printf(
			/* translators: 1: month, 2: day, 3: year, 4: hour, 5: minute. */
			esc_html__( '%1$s %2$s, %3$s at %4$s:%5$s', 'expiring-posts' ),
			$month, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
			$day, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$year, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$hour, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$minute // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		echo '</div>' . "\n";

		foreach ( $values as $unit => $value ) {
			printf( '<input type="hidden" id="hidden_exp-%1$s" value="%2$s" />' . "\n", esc_attr( $unit ), esc_attr( $value ) );
		}
		?>
		<p class="exp-never-wrap">
			<input type="checkbox" name="exp-never" id="exp-never" value="1" <?php checked( ! $enabled ); ?> />
			<label for="exp-never"><?php esc_html_e( 'Never expire', 'expiring-posts' ); ?></label>
		</p>
		<p>
			<a href="#exp-timestampdiv" class="exp-save-timestamp hide-if-no-js button"><?php esc_html_e( 'OK', 'expiring-posts' ); ?></a>
			<a href="#exp-timestampdiv" class="exp-cancel-timestamp hide-if-no-js button-cancel"><?php esc_html_e( 'Cancel', 'expiring-posts' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Save expiry settings from the classic editor.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post.
	 */
	public function save_expiration_date( $post_id, $post = null ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
		if ( ! isset( $_POST[ self::NONCE_NAME ], $_POST['post_ID'] ) ) {
			return;
		}

		// Only handle the post the form belongs to, not other posts saved in the same request.
		if ( absint( $_POST['post_ID'] ) !== (int) $post_id ) {
			return;
		}
		// phpcs:enable

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $this->show_ui_for( $post ) ) {
			return;
		}

		// An expired post keeps the date it expired on.
		if ( self::STATUS === $post->post_status ) {
			return;
		}

		$never = ! empty( $_POST['exp-never'] );
		$parts = array();
		foreach ( array( 'aa', 'mm', 'jj', 'hh', 'mn' ) as $unit ) {
			$parts[ $unit ] = isset( $_POST[ 'exp-' . $unit ] ) ? absint( wp_unslash( $_POST[ 'exp-' . $unit ] ) ) : -1;
		}

		$date = self::normalize_local_date( sprintf( '%04d-%02d-%02d %02d:%02d:00', $parts['aa'], $parts['mm'], $parts['jj'], $parts['hh'], $parts['mn'] ) );
		if ( in_array( -1, $parts, true ) ) {
			$date = '';
		}

		if ( $never ) {
			$this->set_expiration( $post_id, false, $date );
			return;
		}

		if ( '' === $date ) {
			return;
		}

		// Re-published with a date that has already passed: don't expire again straight away.
		if ( isset( $this->restored[ $post_id ] ) && self::local_to_timestamp( $date ) <= time() ) {
			$this->set_expiration( $post_id, false, $date );
			return;
		}

		$this->set_expiration( $post_id, true, $date );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Block editor and REST API
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Register the `expiring_posts` REST field on supported post types.
	 */
	public function register_rest_field() {
		$post_types = array_filter(
			$this->get_supported_post_types(),
			static function ( $post_type ) {
				$object = get_post_type_object( $post_type );
				return $object && $object->show_in_rest;
			}
		);

		if ( ! $post_types ) {
			return;
		}

		register_rest_field(
			array_values( $post_types ),
			self::REST_FIELD,
			array(
				'get_callback'    => array( $this, 'rest_get_field' ),
				'update_callback' => array( $this, 'rest_update_field' ),
				'schema'          => array(
					'description' => __( 'Post expiry settings.', 'expiring-posts' ),
					'type'        => 'object',
					'context'     => array( 'edit' ),
					'properties'  => array(
						'enabled' => array(
							'description' => __( 'Whether the post expires on the given date.', 'expiring-posts' ),
							'type'        => 'boolean',
						),
						'date'    => array(
							'description' => __( 'Expiry date in the site timezone. Dates with a UTC offset are converted.', 'expiring-posts' ),
							'type'        => array( 'string', 'null' ),
							'format'      => 'date-time',
						),
					),
				),
			)
		);
	}

	/**
	 * REST get callback.
	 *
	 * @param array $data Prepared post data.
	 * @return array
	 */
	public function rest_get_field( $data ) {
		$post_id    = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$expiration = $this->get_expiration( $post_id );

		// Only writable properties, so the block editor's edits compare equal to
		// the saved value after a save.
		return array(
			'enabled' => $expiration['enabled'],
			'date'    => $expiration['date'] ? str_replace( ' ', 'T', $expiration['date'] ) : null,
		);
	}

	/**
	 * REST update callback.
	 *
	 * @param mixed   $value New value.
	 * @param WP_Post $post  Post.
	 * @return true|WP_Error
	 */
	public function rest_update_field( $value, $post ) {
		if ( ! $post instanceof WP_Post || ! is_array( $value ) ) {
			return true;
		}

		if ( $this->is_disabled_for_post( $post->ID ) ) {
			return true;
		}

		if ( ! $this->current_user_can_manage( $post ) ) {
			return new WP_Error( 'rest_cannot_edit_expiration', __( 'Sorry, you are not allowed to change the expiry of this post.', 'expiring-posts' ), array( 'status' => rest_authorization_required_code() ) );
		}

		$current = $this->get_expiration( $post );
		$enabled = array_key_exists( 'enabled', $value ) ? (bool) $value['enabled'] : $current['enabled'];
		$date    = '';

		if ( isset( $value['date'] ) && '' !== $value['date'] ) {
			$date = $this->parse_rest_date( $value['date'] );
			if ( '' === $date ) {
				return new WP_Error( 'rest_invalid_expiration_date', __( 'Invalid expiry date.', 'expiring-posts' ), array( 'status' => 400 ) );
			}
		}

		// An expired post keeps the date it expired on.
		if ( self::STATUS === $post->post_status ) {
			return true;
		}

		$timestamp = '' !== $date ? self::local_to_timestamp( $date ) : $current['timestamp'];
		if ( $enabled && ! $timestamp ) {
			return new WP_Error( 'rest_invalid_expiration_date', __( 'An expiry date is required.', 'expiring-posts' ), array( 'status' => 400 ) );
		}

		$result = $this->set_expiration( $post->ID, $enabled, $date );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Parse a REST date. Dates without an offset are in the site timezone.
	 *
	 * @param mixed $value Raw value.
	 * @return string Local `Y-m-d H:i:s` date or an empty string.
	 */
	private function parse_rest_date( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value, " \n\r\t\v\0" );
		if ( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $value ) ) {
			$timestamp = rest_parse_date( $value );
			return $timestamp ? self::timestamp_to_local( $timestamp ) : '';
		}

		return self::normalize_local_date( $value );
	}

	/**
	 * Enqueue the block editor panel.
	 */
	public function block_editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		$post = get_post();
		if ( ! $this->show_ui_for( $post ) ) {
			return;
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object || ! $post_type_object->show_in_rest ) {
			return;
		}

		wp_enqueue_script(
			'expiring-posts-block-editor',
			EXPIRING_POSTS_URL . 'inc/js/block-editor.js',
			array( 'wp-components', 'wp-data', 'wp-date', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-notices', 'wp-plugins' ),
			EXPIRING_POSTS_VERSION,
			true
		);
		wp_set_script_translations( 'expiring-posts-block-editor', 'expiring-posts' );

		wp_enqueue_style( 'expiring-posts-block-editor', EXPIRING_POSTS_URL . 'inc/css/block-editor.css', array( 'wp-components' ), EXPIRING_POSTS_VERSION );
	}

	/*
	 * ---------------------------------------------------------------------
	 * List tables
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Show "Expired" / "Expires: …" next to post titles in list tables.
	 *
	 * @param string[]     $states States applied to the post.
	 * @param WP_Post|null $post   Post.
	 * @return string[]
	 */
	public function add_expiry_post_states( $states, $post = null ) {
		if ( ! is_array( $states ) ) {
			$states = array();
		}

		if ( ! $post instanceof WP_Post || ! $this->is_supported_post_type( $post->post_type ) ) {
			return $states;
		}

		if ( self::STATUS === $post->post_status ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$filter = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : '';
			if ( self::STATUS !== $filter ) {
				$states['expired'] = esc_html_x( 'Expired', 'post status', 'expiring-posts' );
			}
			return $states;
		}

		$expiration = $this->get_expiration( $post );
		if ( $expiration['enabled'] && $expiration['timestamp'] ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			/* translators: %s: expiry date, or the word "Never". */
			$states['expires'] = esc_html( sprintf( __( 'Expires: %s', 'expiring-posts' ), wp_date( $format, $expiration['timestamp'] ) ) );
		}

		return $states;
	}
}
