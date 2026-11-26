=== Expiring Posts ===
Contributors: ivankk
Tags: expire, expiry, unpublish, schedule, post status
Requires at least: 6.6
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pick a date and a post moves to an "Expired" status automatically. Works in the block editor and the classic editor.

== Description ==

Expiring Posts lets you set an expiry date on any post, page or custom post type. When the date arrives, the post moves to a new **Expired** status and is no longer visible to visitors. Nothing is deleted: expired posts stay in your admin under the *Expired* view, and you can publish them again at any time.

Use it for sales and promotions, job listings, event announcements, limited offers, or anything that should only be online for a while.

= Features =

* **Block editor:** an *Expires* row in the post sidebar, next to Status and Date, with a date and time picker.
* **Classic editor:** an *Expires* field in the Publish box, just like the publish date.
* **Expire now:** expire a published post straight away, from either editor.
* **Expired status:** expired posts are hidden from visitors, search and feeds, but editors can still open and preview them.
* **Easy to restore:** publish an expired post again and it is back online. If its expiry date has passed, expiry is turned off so it doesn't expire again straight away.
* **Post list:** see at a glance which posts are expiring, and when.
* **Show the date on your site** with the `[expiring_posts_date]` shortcode or the `exp_the_expiration_date()` template tag.
* Uses your site's timezone everywhere.
* Works with custom post types.
* Developer friendly: filters, actions, a PHP API and a REST API field.

= How it works =

When you set an expiry date, the plugin schedules a single WP-Cron event for the next post that is due. When it runs, every post whose date has passed moves to the Expired status, and the event is scheduled for the next one.

Only published, scheduled and private posts expire. Drafts and pending posts are left alone.

Only users who can publish a post can change its expiry.

= Developer notes =

Development happens on GitHub: https://github.com/ivankruchkoff/expiring-posts

* The post status is `expired`.
* Expiry is stored in post meta: `exp_expiration_date` (site timezone, `Y-m-d H:i:s`) and `exp_pending_expiration` (`1` when expiry is on). Both keys are protected, so they don't show in the Custom Fields box.
* The `expiring_posts` REST API field (in the `edit` context) reads and writes `{ "enabled": true, "date": "2026-12-01T23:59:00" }`. Dates are in the site timezone; dates with a UTC offset are converted.

**Functions**

* `exp_expire_post( $post, $timestamp = false )` sets a post to expire at a Unix timestamp. Without a timestamp the post expires immediately.
* `exp_unschedule_expiring_post( $post )` turns expiry off.
* `exp_get_expiration_timestamp( $post = null )` returns the expiry as a Unix timestamp, or `false`.
* `exp_get_expiration_date( $post = null, $format = '' )` returns the formatted expiry date.
* `exp_the_expiration_date( $format = '', $before = '', $after = '' )` prints it.

**Shortcode**

`[expiring_posts_date format="F j, Y" none="Never expires"]` shows the current post's expiry date. Both attributes are optional.

**Filters**

* `exp_disable_expiration_for_this_post` ( bool $disabled, int $post_id ): return `true` to hide the controls and stop a post from expiring.
* `exp_supported_post_types` ( string[] $post_types ): post types that can expire.
* `exp_expirable_post_statuses` ( string[] $statuses ): statuses that can move to Expired. Default `publish`, `private`, `future`.
* `exp_user_can_manage_expiration` ( bool $can, WP_Post $post ): who can change a post's expiry.
* `exp_expired_post_status_args` ( array $args ): arguments for `register_post_status()`. For example, set `public` to `true` to keep expired posts visible.
* `exp_expire_batch_size` ( int $size ): posts expired per cron run. Default 100.
* `exp_get_expiring_posts` ( array $posts ): the post ID => timestamp list used for scheduling.

**Actions**

* `exp_post_expired` ( int $post_id, string $old_status ): after a post expires.
* `exp_expire_post` ( int $post_id, int $timestamp ): after `exp_expire_post()` sets a date.
* `unschedule_expiring_post` ( int $post_id ): after expiry is turned off.

== Installation ==

1. Install and activate the plugin from **Plugins → Add New**.
2. Edit a post. In the block editor, click **Expires** in the sidebar. In the classic editor, click **Edit** next to *Expires* in the Publish box.
3. Turn on expiry, pick a date and time, and save the post.

== Frequently Asked Questions ==

= What happens to my existing posts when I activate the plugin? =

Nothing. Posts never expire unless you set a date.

= Where do expired posts go? =

They move to the *Expired* view in your post list (for example **Posts → Expired**). They are not deleted, and you can edit, preview, publish or trash them as normal.

= How do I bring an expired post back? =

Open it and click **Publish**. If its expiry date has already passed, expiry is turned off so the post stays online. If you want it to expire again later, set a new date before publishing.

= How do I show the expiry date on my site? =

Add the `[expiring_posts_date]` shortcode to the post, or the Shortcode block. In a theme, use `<?php exp_the_expiration_date(); ?>` inside the loop. Both show nothing when the post doesn't expire.

= My posts expire late, or not at all. =

The plugin uses WP-Cron, which runs when someone visits your site. On a quiet site a post can expire a little after its date. If `DISABLE_WP_CRON` is set in `wp-config.php`, make sure a real cron job calls `wp-cron.php`.

= Which timezone is used? =

Your site's timezone, from **Settings → General**.

= Does it work with custom post types? =

Yes, with any post type that has an admin screen. Use the `exp_supported_post_types` filter to change the list. In the block editor the post type must have `show_in_rest` enabled, which is needed for the block editor anyway.

= Who can set an expiry date? =

Anyone who can edit the post and publish posts of that type, such as Authors on their own posts, and Editors and Administrators. Contributors can't.

= Can I stop a particular post from expiring? =

Yes. Return `true` from the `exp_disable_expiration_for_this_post` filter for that post ID. The controls are hidden and the post won't expire.

== Screenshots ==

1. Block editor: the expiry date sits in the post sidebar, next to Status and Date.
2. Block editor: turn expiry on, pick a date and time, or expire the post now.
3. Block editor: an expired post. Publish it again to restore it.
4. Classic editor: set the expiry date in the Publish box.
5. The post list shows which posts are expiring, and which have expired.

== Changelog ==

= 2.0.0 =
* New: block editor support. Set or change the expiry date from the post sidebar, and expire a post straight away with *Expire now*.
* New: the `expiring_posts` REST API field.
* New: `[expiring_posts_date]` shortcode and `exp_get_expiration_date()`, `exp_the_expiration_date()` and `exp_get_expiration_timestamp()` template functions for showing the expiry date.
* New: `exp_supported_post_types`, `exp_expirable_post_statuses`, `exp_user_can_manage_expiration`, `exp_expire_batch_size` and `exp_get_expiring_posts` filters, and the `exp_post_expired` action.
* New: editors can preview expired posts.
* New: the plugin is translatable (text domain `expiring-posts`).
* Security: expiry dates are escaped in the post list. Previously a crafted value in the expiry field could run a script in the browser of anyone viewing the list.
* Security: the expiry meta fields are protected and validated, so they can no longer be edited through the Custom Fields box or XML-RPC.
* Security: only users who can publish a post can change its expiry. Previously anyone who could edit it could.
* Security: the classic editor only saves expiry for the post being edited, not for other posts saved in the same request.
* Security: the date fields are validated as numbers before use.
* Security: plugin files can't be loaded directly.
* Fix: a fatal error on PHP 8 (a warning on PHP 7) when post states were requested without a current post, which caused the "critical error" reported on the support forum. Props Luke Gedeon (#7, #8).
* Fix: expiry times were stored or shown in UTC instead of the site timezone in several places, so posts could expire hours early or late.
* Fix: re-publishing an expired post with a later expiry date now keeps it scheduled. Props Rinat Khaziev (#1).
* Fix: the classic editor no longer shows a blank status on new posts. Props Philip John (#3).
* Fix: switching a privately published post back to Public in the classic editor no longer leaves the status blank and saves it as private again (#2).
* Changed: the classic editor expiry field now matches the publish date field: "at" instead of "@", "Never" capitalised, and the same input sizes, spacing and mobile layout (#9).
* Fix: drafts and pending posts are no longer expired.
* Fix: an invalid nonce no longer stops the whole post save with an error page; the expiry change is just skipped.
* Fix: the scheduled event is cleaned up when the last expiring post is deleted, and when the plugin is deactivated.
* Fix: many posts due at once are expired in batches instead of all in one request.
* Changed: requires WordPress 6.6 and PHP 7.4. Tested up to WordPress 7.2 and PHP 8.6.

= 1.3 =
* Add the `exp_disable_expiration_for_this_post` filter and readme for WordPress.org.

== Upgrade Notice ==

= 2.0.0 =
Adds block editor support and fixes several security issues and a PHP 8 fatal error. Requires WordPress 6.6 and PHP 7.4. Existing expiry dates are kept.
