/**
 * Tools → Mavo Search: runs a rebuild as a chain of small requests, one
 * MVS_Rebuild::step() each, so no single request runs long.
 * A button may name several modes ("all,stale"); they run in turn.
 */
( function () {
	'use strict';

	var cfg = window.MVS_ADMIN;
	var out = document.getElementById( 'mvs-progress' );
	var buttons = document.querySelectorAll( '[data-mvs-modes]' );

	if ( ! cfg || ! out || ! buttons.length ) {
		return;
	}

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return template.replace( /%(\d+\$)?[sd]/g, function ( token, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : i++;
			return String( args[ index ] );
		} );
	}

	function setBusy( busy ) {
		Array.prototype.forEach.call( buttons, function ( b ) {
			b.disabled = busy;
		} );
	}

	function step( mode, cursor ) {
		var body = new URLSearchParams();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'mode', mode );
		body.append( 'cursor', String( cursor ) );

		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'HTTP ' + r.status );
				}
				return r.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || 'error' );
				}
				return json.data;
			} );
	}

	function runMode( mode, state ) {
		var total = null;
		var done = 0;

		function next( cursor ) {
			return step( mode, cursor ).then( function ( data ) {
				if ( null !== data.total && undefined !== data.total ) {
					total = data.total;
				}
				done += data.processed;
				state.failed += data.failed.length;

				out.textContent = format( cfg.i18n.running, mode, done ) + ( null !== total ? ' ' + format( cfg.i18n.of, total ) : '' );

				return data.done ? null : next( data.cursor );
			} );
		}

		return next( 0 );
	}

	Array.prototype.forEach.call( buttons, function ( button ) {
		button.addEventListener( 'click', function () {
			var modes = button.getAttribute( 'data-mvs-modes' ).split( ',' );
			var state = { failed: 0 };
			var chain = Promise.resolve();

			setBusy( true );

			modes.forEach( function ( mode ) {
				chain = chain.then( function () {
					return runMode( mode, state );
				} );
			} );

			chain
				.then( function () {
					out.textContent = format( cfg.i18n.done, state.failed );
				} )
				.catch( function ( err ) {
					out.textContent = format( cfg.i18n.error, err.message );
				} )
				.then( function () {
					setBusy( false );
				} );
		} );
	} );
}() );
