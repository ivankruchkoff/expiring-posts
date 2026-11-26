/**
 * Expiring Posts: block editor panel.
 *
 * Adds an "Expires" row to the post summary panel (next to Status, Date
 * and so on). Reads and writes the `expiring_posts` REST field, which is
 * `{ enabled: boolean, date: "Y-m-d\TH:i:s" | null }` in the site timezone.
 * The script is only enqueued for users who can change a post's expiry.
 *
 * Written without JSX so the plugin needs no build step.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.components || ! wp.date ) {
		return;
	}

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var Button = wp.components.Button;
	var Dropdown = wp.components.Dropdown;
	var ToggleControl = wp.components.ToggleControl;
	var DateTimePicker = wp.components.DateTimePicker;
	var dateLib = wp.date;

	var PluginPostStatusInfo = ( wp.editor && wp.editor.PluginPostStatusInfo ) || ( wp.editPost && wp.editPost.PluginPostStatusInfo );
	if ( ! PluginPostStatusInfo ) {
		return;
	}

	var FIELD = 'expiring_posts';
	var NOTICE_ID = 'expiring-posts-expired';
	var DAY_IN_MS = 86400000;

	function settings() {
		return dateLib.getSettings ? dateLib.getSettings() : { formats: {}, l10n: {} };
	}

	/**
	 * Default expiry: five days from now, as a site-local "Y-m-d\TH:i:s" string.
	 *
	 * @return {string} Date.
	 */
	function defaultDate() {
		return dateLib.date( 'Y-m-d\\TH:i:00', Date.now() + 5 * DAY_IN_MS );
	}

	/**
	 * Format a site-local date string for display.
	 *
	 * @param {string} local Date in the site timezone.
	 * @return {string} Formatted date.
	 */
	function formatDate( local ) {
		var formats = settings().formats || {};
		var format = formats.datetimeAbbreviated || 'M j, Y g:i a';
		return dateLib.dateI18n( format, dateLib.getDate( local ) );
	}

	function is12Hour() {
		var time = ( settings().formats || {} ).time || '';
		// Strip escaped characters, then look for an am/pm marker.
		return /a/i.test( time.replace( /\\./g, '' ) );
	}

	function isInPast( local ) {
		return dateLib.getDate( local ).getTime() <= Date.now();
	}

	function ExpiringPostsPanel() {
		var data = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				value: editor.getEditedPostAttribute( FIELD ),
				status: editor.getEditedPostAttribute( 'status' ),
				savedStatus: editor.getCurrentPostAttribute( 'status' ),
				isSaving: editor.isSavingPost(),
			};
		}, [] );

		var editorDispatch = useDispatch( 'core/editor' );
		var noticesDispatch = useDispatch( 'core/notices' );
		var savedExpired = 'expired' === data.savedStatus;

		// Tell the user when they open a post that has already expired.
		useEffect( function () {
			if ( ! noticesDispatch ) {
				return;
			}
			if ( savedExpired ) {
				noticesDispatch.createNotice(
					'warning',
					__( 'This post has expired and is hidden from visitors. Publish it again to restore it.', 'expiring-posts' ),
					{ id: NOTICE_ID, isDismissible: true }
				);
			} else {
				noticesDispatch.removeNotice( NOTICE_ID );
			}
		}, [ savedExpired ] );

		var value = data.value;
		if ( ! value ) {
			return null;
		}

		var enabled = !! value.enabled;
		var date = value.date || null;
		var isExpired = 'expired' === data.status;
		var canExpireNow = ! isExpired && ( 'publish' === data.savedStatus || 'private' === data.savedStatus );

		function update( changes ) {
			editorDispatch.editPost( {
				expiring_posts: {
					enabled: Object.prototype.hasOwnProperty.call( changes, 'enabled' ) ? changes.enabled : enabled,
					date: Object.prototype.hasOwnProperty.call( changes, 'date' ) ? changes.date : ( date || defaultDate() ),
				},
			} );
		}

		function expireNow( onClose ) {
			editorDispatch.editPost( { status: 'expired' } );
			editorDispatch.savePost();
			onClose();
		}

		var label;
		if ( isExpired ) {
			label = date ? formatDate( date ) : __( 'Expired', 'expiring-posts' );
		} else if ( enabled && date ) {
			label = formatDate( date );
		} else {
			label = __( 'Never', 'expiring-posts' );
		}

		var help = null;
		if ( isExpired ) {
			help = __( 'This post has expired. Publish it again to make it visible.', 'expiring-posts' );
		} else if ( enabled && date && isInPast( date ) ) {
			help = __( 'This date has passed. The post will expire shortly after you save.', 'expiring-posts' );
		} else if ( enabled ) {
			help = __( 'On this date the post moves to the Expired status and is hidden from visitors.', 'expiring-posts' );
		}

		return el(
			PluginPostStatusInfo,
			{ className: 'expiring-posts-status-info' },
			el(
				'div',
				{ className: 'editor-post-panel__row expiring-posts-row' },
				el( 'div', { className: 'editor-post-panel__row-label' }, isExpired ? __( 'Expired', 'expiring-posts' ) : __( 'Expires', 'expiring-posts' ) ),
				el(
					'div',
					{ className: 'editor-post-panel__row-control' },
					el( Dropdown, {
						className: 'expiring-posts-dropdown',
						contentClassName: 'expiring-posts-dropdown__content',
						popoverProps: { placement: 'left-start', offset: 36, shift: true },
						focusOnMount: true,
						renderToggle: function ( toggle ) {
							return el(
								Button,
								{
									className: 'expiring-posts-toggle',
									variant: 'tertiary',
									size: 'compact',
									onClick: toggle.onToggle,
									'aria-expanded': toggle.isOpen,
									/* translators: %s: current expiry date, or "Never". */
									'aria-label': sprintf( __( 'Change expiry: %s', 'expiring-posts' ), label ),
								},
								label
							);
						},
						renderContent: function ( dropdown ) {
							return el(
								'div',
								{ className: 'expiring-posts-popover' },
								el(
									'div',
									{ className: 'expiring-posts-popover__header' },
									el( 'h2', { className: 'expiring-posts-popover__title' }, __( 'Expiry', 'expiring-posts' ) ),
									el( Button, {
										className: 'expiring-posts-popover__close',
										size: 'small',
										icon: 'no-alt',
										label: __( 'Close', 'expiring-posts' ),
										onClick: dropdown.onClose,
									} )
								),
								isExpired ? null : el( ToggleControl, {
									__nextHasNoMarginBottom: true,
									label: __( 'Expire this post', 'expiring-posts' ),
									checked: enabled,
									onChange: function ( next ) {
										update( { enabled: next } );
									},
								} ),
								! isExpired && enabled ? el( DateTimePicker, {
									currentDate: date || defaultDate(),
									is12Hour: is12Hour(),
									startOfWeek: ( settings().l10n || {} ).startOfWeek,
									onChange: function ( next ) {
										if ( next ) {
											update( { date: next, enabled: true } );
										}
									},
								} ) : null,
								help ? el( 'p', { className: 'expiring-posts-popover__help' }, help ) : null,
								canExpireNow ? el(
									'div',
									{ className: 'expiring-posts-popover__actions' },
									el( Button, {
										variant: 'secondary',
										isDestructive: true,
										disabled: data.isSaving,
										onClick: function () {
											expireNow( dropdown.onClose );
										},
									}, __( 'Expire now', 'expiring-posts' ) )
								) : null
							);
						},
					} )
				)
			)
		);
	}

	wp.plugins.registerPlugin( 'expiring-posts', {
		render: ExpiringPostsPanel,
	} );
}( window.wp ) );
