/**
 * Expiring Posts: classic editor controls in the Publish box.
 *
 * AdminExpiringPosts is provided by wp_localize_script().
 */
/* global AdminExpiringPosts, jQuery */
( function ( $ ) {
	'use strict';

	$( function () {
		var timestamp = document.getElementById( 'exp-timestamp' );
		if ( ! timestamp || ! window.AdminExpiringPosts ) {
			return;
		}

		var cfg = window.AdminExpiringPosts;
		var $postStatus = $( '#post_status' );
		var $expiredOption = $( '#expired-status' );
		var saveButton = document.getElementById( 'save-post' );
		var originalStatus = $postStatus.val();

		// Add "Expired" to the core status dropdown.
		if ( $postStatus.length && $expiredOption.length && ! $postStatus.find( 'option[value="expired"]' ).length ) {
			$postStatus.append( $expiredOption );
		}

		// Keep the current selection. An expired post has no matching core option,
		// and a new post (auto-draft) must not end up with a blank status.
		if ( 'expired' === cfg.post_status ) {
			$postStatus.val( 'expired' );
		} else if ( originalStatus ) {
			$postStatus.val( originalStatus );
		}

		if ( 'expired' === cfg.post_status ) {
			$( '#post-status-display' ).text( cfg.expired_text );
			if ( saveButton ) {
				saveButton.value = cfg.save_text;
				// Core's post.js rewrites the label after status changes.
				$( '#post_status, .save-post-status, .cancel-post-status' ).on( 'change click', function () {
					window.setTimeout( function () {
						if ( 'expired' === $postStatus.val() ) {
							saveButton.value = cfg.save_text;
						}
					}, 10 );
				} );
			}
		}

		var $timestamp = $( timestamp );
		var $timestampDiv = $( '#exp-timestampdiv' );
		var $edit = $( '.exp-edit-timestamp' );
		var $never = $( '#exp-never' );
		var neverSaved = $never.prop( 'checked' );
		var units = [ 'mm', 'jj', 'aa', 'hh', 'mn' ];

		function field( unit ) {
			return $( '#exp-' + unit );
		}

		function pad( value ) {
			value = String( parseInt( value, 10 ) );
			return value.length < 2 ? '0' + value : value;
		}

		function format( template, values ) {
			return template.replace( /%(\d)\$s/g, function ( match, index ) {
				var value = values[ parseInt( index, 10 ) - 1 ];
				return undefined === value ? match : value;
			} );
		}

		function setLabel( template, boldText ) {
			var parts = template.split( '%s' );
			$timestamp.empty();
			$timestamp.append( document.createTextNode( parts[ 0 ] || '' ) );
			$timestamp.append( $( '<b />' ).text( boldText ) );
			$timestamp.append( document.createTextNode( parts.slice( 1 ).join( '%s' ) ) );
		}

		function toggleFields() {
			$( '.exp-timestamp-wrap', $timestampDiv ).toggleClass( 'exp-disabled', $never.prop( 'checked' ) );
		}

		function close() {
			$timestampDiv.stop( true, true ).slideUp( 'fast' );
			$edit.show().trigger( 'focus' );
		}

		$edit.on( 'click', function ( event ) {
			event.preventDefault();
			// Finish any running slide so a quick Cancel → Edit still opens the form.
			$timestampDiv.stop( true, true );
			if ( $timestampDiv.is( ':hidden' ) ) {
				$timestampDiv.slideDown( 'fast', function () {
					( $never.prop( 'checked' ) ? $never : field( 'mm' ) ).trigger( 'focus' );
				} );
				$edit.hide();
			}
		} );

		$never.on( 'change', toggleFields );
		toggleFields();

		$( '.exp-cancel-timestamp' ).on( 'click', function ( event ) {
			event.preventDefault();
			units.forEach( function ( unit ) {
				field( unit ).val( $( '#hidden_exp-' + unit ).val() );
			} );
			$never.prop( 'checked', neverSaved );
			toggleFields();
			$( '.exp-timestamp-wrap', $timestampDiv ).removeClass( 'form-invalid' );
			close();
		} );

		$( '.exp-save-timestamp' ).on( 'click', function ( event ) {
			event.preventDefault();

			var aa = field( 'aa' ).val(),
				mm = field( 'mm' ).val(),
				jj = field( 'jj' ).val(),
				hh = field( 'hh' ).val(),
				mn = field( 'mn' ).val(),
				date = new Date( aa, mm - 1, jj, hh, mn ),
				$wrap = $( '.exp-timestamp-wrap', $timestampDiv );

			neverSaved = $never.prop( 'checked' );

			if ( ! neverSaved ) {
				var valid = /^\d{4}$/.test( aa ) && /^\d{1,2}$/.test( jj ) && /^\d{1,2}$/.test( hh ) && /^\d{1,2}$/.test( mn ) &&
					date.getFullYear() === parseInt( aa, 10 ) &&
					date.getMonth() + 1 === parseInt( mm, 10 ) &&
					date.getDate() === parseInt( jj, 10 ) &&
					date.getHours() === parseInt( hh, 10 ) &&
					date.getMinutes() === parseInt( mn, 10 );

				if ( ! valid ) {
					$wrap.addClass( 'form-invalid' );
					return;
				}
			}

			$wrap.removeClass( 'form-invalid' );

			if ( neverSaved ) {
				setLabel( cfg.expires_never, cfg.never );
			} else {
				var month = cfg.months[ parseInt( mm, 10 ) - 1 ] || mm;
				setLabel( cfg.expires_on, format( cfg.date_format, [ month, String( parseInt( jj, 10 ) ), aa, pad( hh ), pad( mn ) ] ) );
			}

			close();
		} );
	} );
}( jQuery ) );
