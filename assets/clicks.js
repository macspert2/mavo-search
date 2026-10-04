/**
 * Counts which search results are clicked (MVS_Clicks). One beacon per click,
 * sent as the browser leaves — it never delays the navigation, and carries
 * only the query, the language, the post and its rank.
 *
 *   result tiles  any link inside an element with data-mavo-search-rank
 *                 (mavo_search_result_attributes())
 *   photo rows    any link inside a [data-post-id] tile of a .mavo-search-photos
 *                 container, ranked by position in the row
 *
 * Middle clicks (a new tab) count too.
 */
( function () {
	'use strict';

	var cfg = window.MAVO_SEARCH_CLICKS;

	if ( ! cfg || ! navigator.sendBeacon || ! window.URLSearchParams ) {
		return;
	}

	var sent = {};

	function send( post, rank, source ) {
		var key = source + ':' + post;

		// One count per result per page view, however often it is clicked.
		if ( ! post || ! rank || sent[ key ] ) {
			return;
		}
		sent[ key ] = true;

		var body = new URLSearchParams();
		body.append( 'q', cfg.query );
		body.append( 'lang', cfg.lang );
		body.append( 'post', String( post ) );
		body.append( 'rank', String( rank ) );
		body.append( 'source', source );

		navigator.sendBeacon( cfg.endpoint, body );
	}

	function onClick( event ) {
		if ( 'auxclick' === event.type && 1 !== event.button ) {
			return;
		}

		var link = event.target && event.target.closest ? event.target.closest( 'a[href]' ) : null;

		if ( ! link ) {
			return;
		}

		var result = link.closest( '[data-mavo-search-rank]' );

		if ( result ) {
			send( result.getAttribute( 'data-mavo-search-post' ), result.getAttribute( 'data-mavo-search-rank' ), 'result' );
			return;
		}

		var row  = link.closest( '.mavo-search-photos' );
		var tile = link.closest( '[data-post-id]' );

		if ( row && tile ) {
			var tiles = Array.prototype.slice.call( row.querySelectorAll( '[data-post-id]' ) );
			send( tile.getAttribute( 'data-post-id' ), tiles.indexOf( tile ) + 1, 'photos' );
		}
	}

	document.addEventListener( 'click', onClick, true );
	document.addEventListener( 'auxclick', onClick, true );
}() );
