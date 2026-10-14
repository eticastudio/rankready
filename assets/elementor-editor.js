/**
 * RankReady — Elementor editor panel script.
 *
 * Drives the panel "Generate / Regenerate" control that the AI Summary AND FAQ
 * widgets render (RNRD_Elementor_Widget::regen_control_html). One handler, two
 * kinds — selected via the wrapper's data-rnrd-kind ("summary" | "faq"):
 *
 *   summary → state GET /summary/{id}      generate POST /regenerate/{id}
 *   faq     → state GET /faq/get/{id}       generate POST /faq/generate/{id}
 *
 * The button label is resolved PER-POST at runtime (Elementor caches RAW_HTML
 * control config at the widget-type level, so it can't be set per-post in PHP):
 * "Generate" the first time, "Regenerate" once content exists. Mirrors the
 * Gutenberg blocks so both editors behave identically.
 *
 * Bound at document level (editor parent frame) so it survives Elementor
 * re-rendering the panel. 60-second cooldown matches the blocks.
 *
 * @package RankReady
 * @since   1.0.30
 */
( function () {
	'use strict';

	var COOLDOWN_SECONDS = 60;
	var cooldowns        = {}; // postId:kind → unix timestamp when next run allowed

	var editorCfg = window.rnrdElEditor || {};
	var i18n      = window.rnrdI18n ? window.rnrdI18n.bind( editorCfg.i18n || {} ) : null;
	var t         = i18n ? i18n.t : function ( key, fallback ) { return fallback; };
	var tr        = i18n ? i18n.tr : function ( key, fallback ) { return fallback; };

	function statusEl( wrap ) { return wrap.querySelector( '.rnrd-el-regen__status' ); }
	function btnEl( wrap )    { return wrap.querySelector( '.rnrd-el-regen__btn' ); }
	function kindOf( wrap )   { return wrap.getAttribute( 'data-rnrd-kind' ) === 'faq' ? 'faq' : 'summary'; }

	// Per-kind config: endpoints, label keys, and how to read "content exists".
	function cfg( kind ) {
		if ( 'faq' === kind ) {
			return {
				statePath: '/faq/get/',
				genPath:   '/faq/generate/',
				idle:      t( 'generateFaq', 'Generate FAQ' ),
				done:      t( 'regenerateFaq', 'Regenerate FAQ' ),
				busy:      t( 'generatingFaq', 'Generating FAQ…' ),
				has:       function ( body ) { return !! ( body && Array.isArray( body.faq ) && body.faq.length ); }
			};
		}
		return {
			statePath: '/summary/',
			genPath:   '/regenerate/',
			idle:      t( 'generate', 'Generate Summary' ),
			done:      t( 'regenerate', 'Regenerate Summary' ),
			busy:      t( 'generating', 'Generating…' ),
			has:       function ( body ) { return hasSummaryFromRaw( body && body.summary ); }
		};
	}

	function setStatus( wrap, text, kind ) {
		var el = statusEl( wrap );
		if ( ! el ) return;
		el.textContent = text || '';
		el.setAttribute( 'data-kind', kind || '' );
	}

	function setBusy( wrap, busy ) {
		var btn = btnEl( wrap );
		if ( ! btn ) return;
		btn.disabled = !! busy;
		btn.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		var idle = btn.getAttribute( 'data-idle-label' ) || cfg( kindOf( wrap ) ).idle;
		btn.textContent = busy ? cfg( kindOf( wrap ) ).busy : idle;
	}

	// The post currently being edited (authoritative regardless of cached HTML).
	function getPostId() {
		try {
			if ( window.elementor ) {
				if ( elementor.documents && typeof elementor.documents.getCurrent === 'function' ) {
					var d = elementor.documents.getCurrent();
					if ( d && d.id ) return String( d.id );
				}
				if ( elementor.config && elementor.config.document && elementor.config.document.id ) {
					return String( elementor.config.document.id );
				}
				if ( elementor.config && elementor.config.initial_document && elementor.config.initial_document.id ) {
					return String( elementor.config.initial_document.id );
				}
			}
		} catch ( e ) {}
		return '';
	}

	// Mirror of RNRD_Generator::decode_summary(): non-empty bullets OR any
	// non-empty raw string counts as an existing summary.
	function hasSummaryFromRaw( raw ) {
		if ( ! raw || ! String( raw ).trim() ) return false;
		try {
			var j = JSON.parse( raw );
			if ( j && Array.isArray( j.bullets ) && j.bullets.length ) return true;
		} catch ( e ) {}
		return String( raw ).trim().length > 0;
	}

	// Optional FAQ focus keyword, read from the sibling Elementor text control.
	function getKeyword() {
		var input = document.querySelector( '.elementor-control-keyword input, .elementor-control-keyword textarea' );
		return input ? String( input.value || '' ).trim() : '';
	}

	// Fetch per-post state and set each control's button label.
	function refreshLabels() {
		var wraps = document.querySelectorAll( '.rnrd-el-regen' );
		if ( ! wraps.length ) return;

		var settings = window.rnrdElEditor || {};
		var postId   = getPostId();

		Array.prototype.forEach.call( wraps, function ( wrap ) {
			var btn = btnEl( wrap );
			if ( ! btn || wrap.getAttribute( 'data-resolving' ) === '1' ) return;
			if ( ! postId || ! settings.restUrl ) return;

			var c = cfg( kindOf( wrap ) );
			wrap.setAttribute( 'data-resolving', '1' );
			fetch( settings.restUrl + c.statePath + encodeURIComponent( postId ), {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': settings.nonce || '' }
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( body ) {
					var label = c.has( body ) ? c.done : c.idle;
					btn.setAttribute( 'data-idle-label', label );
					if ( btn.getAttribute( 'aria-busy' ) !== 'true' ) {
						btn.textContent = label;
					}
				} )
				.catch( function () {} )
				.then( function () { wrap.removeAttribute( 'data-resolving' ); } );
		} );
	}

	function startCooldown( wrap, key, successMsg ) {
		cooldowns[ key ] = Date.now() + COOLDOWN_SECONDS * 1000;
		var btn = btnEl( wrap );
		var c   = cfg( kindOf( wrap ) );
		if ( ! btn ) return;
		var idle = btn.getAttribute( 'data-idle-label' ) || c.done;
		var tick = function () {
			var remaining = Math.max( 0, Math.ceil( ( cooldowns[ key ] - Date.now() ) / 1000 ) );
			if ( remaining <= 0 ) {
				setBusy( wrap, false );
				setStatus( wrap, successMsg || '', 'success' );
				return;
			}
			btn.disabled = true;
			btn.textContent = idle;
			var hint = tr( 'regenIn', 'Regenerate available in %ds.', remaining );
			setStatus( wrap, successMsg ? ( successMsg + ' ' + hint ) : hint, 'success' );
			setTimeout( tick, 1000 );
		};
		tick();
	}

	function handleClick( e ) {
		var target = e.target && e.target.nodeType === 1 ? e.target : ( e.target && e.target.parentElement );
		var btn    = target && target.closest && target.closest( '.rnrd-el-regen__btn' );
		if ( ! btn ) return;
		e.preventDefault();
		e.stopPropagation(); // Elementor's panel views listen on the same click; one run per click.

		var wrap   = btn.closest( '.rnrd-el-regen' );
		if ( ! wrap ) return;
		var postId = getPostId() || wrap.getAttribute( 'data-post-id' );
		if ( ! postId ) {
			setStatus( wrap, t( 'noPostId', 'Save the page once, then try again.' ), 'error' );
			return;
		}

		if ( ! window.rnrdElEditor || ! window.rnrdElEditor.restUrl ) {
			setStatus( wrap, t( 'configMissing', 'RankReady editor settings did not load. Reload the editor and try again.' ), 'error' );
			return;
		}

		var kind = kindOf( wrap );
		var c    = cfg( kind );
		var key  = postId + ':' + kind;

		if ( cooldowns[ key ] && Date.now() < cooldowns[ key ] ) {
			return; // still cooling down; the status line already shows the countdown
		}
		if ( btn.disabled ) return;

		var settings = window.rnrdElEditor || {};
		if ( ! settings.restUrl ) {
			setStatus( wrap, t( 'restUnavailable', 'REST URL unavailable.' ), 'error' );
			return;
		}

		var fetchOpts = {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   settings.nonce || ''
			},
			credentials: 'same-origin'
		};
		if ( 'faq' === kind ) {
			var kw = getKeyword();
			if ( kw ) {
				fetchOpts.body = JSON.stringify( { keyword: kw } );
			}
		}

		setBusy( wrap, true );
		setStatus( wrap, '', '' );

		fetch( settings.restUrl + c.genPath + encodeURIComponent( postId ), fetchOpts )
			.then( function ( r ) {
				// A non-JSON reply (a security plugin's HTML page, a 500) must read as an error, not a silent no-op.
				return r.json().then( function ( body ) { return { status: r.status, body: body }; }, function () { return { status: r.status || 500, body: null }; } );
			} )
			.then( function ( res ) {
				var ok = res.status >= 200 && res.status < 300 && ( ! res.body || res.body.success !== false );
				if ( ok ) {
					var successMsg = t( 'updatedRefresh', 'Updated. Refresh the preview to see the result.' );
					setStatus( wrap, successMsg, 'success' );
					btn.setAttribute( 'data-idle-label', c.done ); // content now exists
					startCooldown( wrap, key, successMsg );
				} else {
					var msg = ( res.body && res.body.message ) || t( 'generationFailed', 'Generation failed.' );
					setStatus( wrap, msg, 'error' );
					setBusy( wrap, false );
				}
			} )
			.catch( function () {
				setStatus( wrap, t( 'networkError', 'Network error. Try again.' ), 'error' );
				setBusy( wrap, false );
			} );
	}

	document.addEventListener( 'click', handleClick, true ); // Capture: runs before any panel handler can swallow the click.

	// The panel control is (re)inserted whenever the user selects/edits the
	// widget. Watch for it and resolve the label each time it appears.
	if ( typeof MutationObserver !== 'undefined' ) {
		var mo = new MutationObserver( function ( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				var added = mutations[ i ].addedNodes;
				if ( ! added || ! added.length ) continue;
				for ( var j = 0; j < added.length; j++ ) {
					var n = added[ j ];
					if ( n.nodeType !== 1 ) continue;
					if ( ( n.classList && n.classList.contains( 'rnrd-el-regen' ) ) ||
						( n.querySelector && n.querySelector( '.rnrd-el-regen' ) ) ) {
						refreshLabels();
						return;
					}
				}
			}
		} );
		mo.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', refreshLabels );
	} else {
		refreshLabels();
	}
} )();
