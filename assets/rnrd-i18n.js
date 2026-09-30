/**
 * RankReady — shared JS i18n helpers (t / tr).
 * Strings live in PHP wp_localize_script; this file only binds lookups.
 */
( function ( window ) {
	'use strict';

	function lookup( bag, key, fallback ) {
		return ( bag && bag[ key ] ) || fallback || '';
	}

	function format( bag, key, fallback ) {
		var s = lookup( bag, key, fallback );
		for ( var i = 3; i < arguments.length; i++ ) {
			var n = i - 2;
			var val = String( arguments[ i ] );
			s = s.split( '%' + n + '$d' ).join( val );
			s = s.split( '%' + n + '$s' ).join( val );
		}
		if ( arguments.length > 3 ) {
			var first = String( arguments[ 3 ] );
			var at    = s.indexOf( '%s' );
			if ( at < 0 ) { at = s.indexOf( '%d' ); }
			if ( at >= 0 ) { s = s.slice( 0, at ) + first + s.slice( at + 2 ); }
		}
		// The PHP strings are php-format, so a literal percent sign is written
		// %% for translators and their tools. printf would collapse it; this
		// does the same, or the progress line reads "(50%%)".
		return s.split( '%%' ).join( '%' );
	}

	window.rnrdI18n = {
		bind: function ( bag ) {
			bag = bag || {};
			return {
				t: function ( key, fallback ) {
					return lookup( bag, key, fallback );
				},
				tr: function ( key, fallback ) {
					var args = [ bag, key, fallback ].concat(
						Array.prototype.slice.call( arguments, 2 )
					);
					return format.apply( null, args );
				},
			};
		},
	};
} )( window );
