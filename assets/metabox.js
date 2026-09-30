/**
 * RankReady — post-edit meta box Generate controls (Summary + FAQ).
 *
 * Summary: POST /rankready/v1/regenerate/{id}  DELETE /summary/{id}
 * FAQ:     POST /rankready/v1/faq/generate/{id} DELETE /faq/{id}
 * Same 60s cooldown as the Gutenberg blocks / Elementor widgets.
 * Lean file: post-edit screens must not load assets/admin.js.
 */
( function () {
	'use strict';

	var cfg = window.rnrdMetabox || {};
	var COOLDOWN = parseInt( cfg.cooldown, 10 ) || 60;
	var i18n = window.rnrdI18n.bind( cfg.i18n || {} );
	var t    = i18n.t;

	function remaining( generatedUnix ) {
		generatedUnix = parseInt( generatedUnix, 10 ) || 0;
		if ( generatedUnix < 1 ) {
			return 0;
		}
		var left = COOLDOWN - ( Math.floor( Date.now() / 1000 ) - generatedUnix );
		return left > 0 ? left : 0;
	}

	function restError( body, fallback ) {
		if ( body && body.message ) {
			return String( body.message );
		}
		return fallback || t( 'failed', 'Generation failed.' );
	}

	function decodeBullets( raw ) {
		if ( ! raw ) {
			return [];
		}
		try {
			var parsed = JSON.parse( raw );
			if ( parsed && Array.isArray( parsed.bullets ) && parsed.bullets.length ) {
				return parsed.bullets.filter( function ( b ) {
					return String( b || '' ).trim() !== '';
				} );
			}
		} catch ( e ) { /* not JSON */ }
		var text = String( raw ).trim();
		return text ? [ text ] : [];
	}

	function normalizeFaq( rows ) {
		if ( ! Array.isArray( rows ) ) {
			return [];
		}
		return rows.filter( function ( row ) {
			return row && String( row.question || '' ).trim() !== '';
		} );
	}

	function kindConfig( kind ) {
		if ( kind === 'faq' ) {
			return {
				path: 'faq/generate/',
				deletePath: 'faq/',
				body: { keyword: '', count: 0 },
				idle: 'generateFaq',
				idleFb: 'Generate FAQ',
				done: 'regenerateFaq',
				doneFb: 'Regenerate FAQ',
				busy: 'generatingFaq',
				busyFb: 'Generating FAQ…',
				busyDone: 'regeneratingFaq',
				busyDoneFb: 'Regenerating FAQ…',
				just: 'generatedFaqJust',
				justFb: 'FAQ generated just now.',
				deleted: 'deletedFaq',
				deletedFb: 'FAQ removed.',
				confirm: 'confirmFaq',
				confirmFb: 'Remove the generated FAQ for this post?',
				deleteLabel: 'deleteFaq',
				deleteLabelFb: 'Delete FAQ',
				parse: function ( body ) {
					return normalizeFaq( body && body.faq );
				},
				fill: fillFaq,
				getPath: 'faq/get/',
			};
		}
		return {
			path: 'regenerate/',
			deletePath: 'summary/',
			body: null,
			idle: 'generate',
			idleFb: 'Generate Summary',
			done: 'regenerate',
			doneFb: 'Regenerate Summary',
			busy: 'generating',
			busyFb: 'Generating Summary…',
			busyDone: 'regenerating',
			busyDoneFb: 'Regenerating Summary…',
			just: 'generatedJust',
			justFb: 'Summary generated just now.',
			deleted: 'deletedSummary',
			deletedFb: 'Summary removed.',
			confirm: 'confirmSummary',
			confirmFb: 'Remove the generated summary for this post?',
			deleteLabel: 'deleteSummary',
			deleteLabelFb: 'Delete summary',
			parse: function ( body ) {
				return decodeBullets( body && body.summary );
			},
			fill: fillSummary,
			getPath: 'summary/',
		};
	}

	function fillSummary( list, items ) {
		list.textContent = '';
		items.forEach( function ( bullet ) {
			var li = document.createElement( 'li' );
			li.textContent = bullet;
			list.appendChild( li );
		} );
	}

	function fillFaq( list, items ) {
		list.textContent = '';
		items.forEach( function ( row ) {
			var li = document.createElement( 'li' );
			var q = document.createElement( 'strong' );
			q.textContent = String( row.question || '' );
			li.appendChild( q );
			var answer = String( row.answer || '' ).replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
			if ( answer ) {
				var a = document.createElement( 'span' );
				a.className = 'rnrd-mb__faq-a';
				a.textContent = answer;
				li.appendChild( a );
			}
			list.appendChild( li );
		} );
	}

	function init( wrap ) {
		var kind = wrap.getAttribute( 'data-rnrd-mb-gen' ) || 'summary';
		var spec = kindConfig( kind );
		var btn = wrap.querySelector( '[data-rnrd-gen]' );
		var errEl = wrap.querySelector( '.rnrd-mb__error' );
		var details = wrap.querySelector( '.rnrd-mb__reveal' );
		var list = wrap.querySelector( '[data-rnrd-mb-list]' );
		var generatedEl = wrap.querySelector( '.rnrd-mb__generated' );
		var statusTextEl = generatedEl ? generatedEl.querySelector( '[data-rnrd-mb-status-text]' ) : null;
		var statusBaseText = statusTextEl
			? String( statusTextEl.getAttribute( 'data-status-base' ) || statusTextEl.textContent || '' ).trim()
			: '';
		var deleteWrap = generatedEl ? generatedEl.querySelector( '[data-rnrd-mb-delete-wrap]' ) : null;
		var delBtn = deleteWrap ? deleteWrap.querySelector( '[data-rnrd-delete]' ) : null;
		if ( ! btn ) {
			return;
		}

		var postId = parseInt( wrap.getAttribute( 'data-post-id' ), 10 ) || 0;
		var hasKey = wrap.getAttribute( 'data-has-key' ) === '1';
		var typeEnabled = wrap.getAttribute( 'data-type-enabled' ) === '1';
		var hasContent = wrap.getAttribute( 'data-has-content' ) === '1';
		var generated = parseInt( wrap.getAttribute( 'data-generated' ), 10 ) || 0;
		var loading = false;
		var deleting = false;
		var cooldownTimer = null;
		var statusMode = 'idle'; // idle | just | cooldown

		function canGenerate() {
			return postId > 0 && hasKey && typeEnabled && ! loading && ! deleting && remaining( generated ) < 1;
		}

		function canDelete() {
			return postId > 0 && hasContent && ! loading && ! deleting;
		}

		function setError( msg ) {
			if ( ! errEl ) {
				return;
			}
			if ( msg ) {
				errEl.textContent = msg;
				errEl.hidden = false;
			} else {
				errEl.textContent = '';
				errEl.hidden = true;
			}
		}

		function actionLabel() {
			return hasContent ? t( spec.done, spec.doneFb ) : t( spec.idle, spec.idleFb );
		}

		function regenInShort( left ) {
			return t( 'regenInShort', 'You can regenerate again in %ds.' ).replace( '%d', String( left ) );
		}

		function ensureDeleteControl() {
			if ( ! generatedEl || ! hasContent ) {
				return;
			}
			if ( ! deleteWrap ) {
				deleteWrap = document.createElement( 'span' );
				deleteWrap.setAttribute( 'data-rnrd-mb-delete-wrap', '' );
				deleteWrap.appendChild( document.createTextNode( ' ' ) );
				generatedEl.appendChild( deleteWrap );
			}
			if ( ! delBtn ) {
				delBtn = document.createElement( 'button' );
				delBtn.type = 'button';
				delBtn.className = 'rnrd-mb__delete';
				delBtn.setAttribute( 'data-rnrd-delete', '' );
				delBtn.textContent = t( spec.deleteLabel, spec.deleteLabelFb );
				delBtn.addEventListener( 'click', deleteContent );
				deleteWrap.appendChild( delBtn );
			}
		}

		function setStatusText( text ) {
			if ( statusTextEl ) {
				statusTextEl.textContent = text;
			}
		}

		function appendCooldown( base, left ) {
			if ( ! base ) {
				return regenInShort( left );
			}
			return base + ' ' + regenInShort( left );
		}

		function setDeleteVisible( show ) {
			if ( show ) {
				ensureDeleteControl();
			}
			if ( deleteWrap ) {
				deleteWrap.hidden = ! show;
			}
			if ( delBtn ) {
				delBtn.disabled = ! canDelete();
			}
		}

		function paintButton() {
			if ( loading ) {
				btn.textContent = hasContent
					? t( spec.busyDone, spec.busyDoneFb )
					: t( spec.busy, spec.busyFb );
			} else {
				btn.textContent = actionLabel();
			}
			btn.disabled = ! canGenerate();
			btn.setAttribute( 'aria-busy', loading ? 'true' : 'false' );

			var left = remaining( generated );
			if ( ! loading && left > 0 ) {
				btn.setAttribute(
					'title',
					t( 'regenIn', 'Regenerate available in %ds.' ).replace( '%d', String( left ) )
				);
			} else {
				btn.removeAttribute( 'title' );
			}
		}

		function paintStatus() {
			if ( ! generatedEl ) {
				return;
			}
			var left = remaining( generated );

			if ( deleting ) {
				generatedEl.hidden = false;
				setDeleteVisible( false );
				setStatusText( t( 'deleting', 'Deleting…' ) );
				return;
			}

			if ( statusMode === 'deleted' ) {
				generatedEl.hidden = false;
				setDeleteVisible( false );
				setStatusText( t( spec.deleted, spec.deletedFb ) );
				return;
			}

			if ( ! hasContent && generated <= 0 ) {
				generatedEl.hidden = true;
				return;
			}

			generatedEl.hidden = false;
			setDeleteVisible( hasContent );

			if ( left > 0 ) {
				setStatusText( appendCooldown( statusBaseText, left ) );
				return;
			}

			if ( statusMode === 'just' ) {
				setStatusText( statusBaseText );
				return;
			}

			if ( statusBaseText ) {
				setStatusText( statusBaseText );
			}
		}

		function refreshUi() {
			paintButton();
			paintStatus();
		}

		function tickCooldown() {
			if ( cooldownTimer ) {
				clearInterval( cooldownTimer );
				cooldownTimer = null;
			}
			refreshUi();
			if ( remaining( generated ) < 1 ) {
				if ( statusMode === 'just' ) {
					statusMode = 'idle';
				}
				refreshUi();
				return;
			}
			cooldownTimer = setInterval( function () {
				if ( remaining( generated ) < 1 ) {
					clearInterval( cooldownTimer );
					cooldownTimer = null;
					if ( statusMode === 'just' ) {
						statusMode = 'idle';
					}
				}
				refreshUi();
			}, 1000 );
		}

		function applyItems( items ) {
			if ( ! list || ! details ) {
				return;
			}
			spec.fill( list, items );
			var show = items.length > 0;
			details.hidden = ! show;
			if ( show ) {
				details.open = true;
			}
			hasContent = show;
			wrap.setAttribute( 'data-has-content', show ? '1' : '0' );
		}

		function markGenerated() {
			generated = Math.floor( Date.now() / 1000 );
			wrap.setAttribute( 'data-generated', String( generated ) );
			statusMode = 'just';
			statusBaseText = t( spec.just, spec.justFb );
		}

		function clearContent() {
			applyItems( [] );
			generated = 0;
			wrap.setAttribute( 'data-generated', '0' );
			statusMode = 'deleted';
		}

		function apiRequest( method, pathSuffix, body ) {
			var opts = {
				method: method,
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': cfg.nonce || '',
				},
			};
			if ( body !== null && body !== undefined ) {
				opts.headers[ 'Content-Type' ] = 'application/json';
				opts.body = JSON.stringify( body );
			}
			return fetch( String( cfg.restUrl || '' ) + pathSuffix + postId, opts )
				.then( function ( r ) {
					return r.json().then( function ( payload ) {
						return { ok: r.ok, status: r.status, body: payload || {} };
					} );
				} );
		}

		function generate() {
			if ( ! canGenerate() ) {
				if ( postId < 1 ) {
					setError( t( 'saveFirst', 'Save the post first, then generate.' ) );
				}
				return;
			}
			loading = true;
			setError( '' );
			refreshUi();

			apiRequest( 'POST', spec.path, spec.body )
				.then( function ( res ) {
					loading = false;
					var body = res.body;
					var failed = ! res.ok || body.success === false;
					if ( failed ) {
						var msg = restError( body, t( 'failed', 'Generation failed.' ) );
						if ( body.code === 'rnrd_rate_limited' ) {
							var secs = parseInt( String( msg ).match( /\d+/ ), 10 );
							if ( secs ) {
								generated = Math.floor( Date.now() / 1000 ) - ( COOLDOWN - secs );
								wrap.setAttribute( 'data-generated', String( generated ) );
								statusMode = 'cooldown';
							}
						}
						setError( msg );
						tickCooldown();
						return;
					}
					applyItems( spec.parse( body ) );
					markGenerated();
					tickCooldown();
				} )
				.catch( function () {
					loading = false;
					setError( t( 'failed', 'Generation failed.' ) );
					refreshUi();
				} );
		}

		function deleteContent() {
			if ( ! canDelete() ) {
				return;
			}
			if ( ! window.confirm( t( spec.confirm, spec.confirmFb ) ) ) {
				return;
			}
			deleting = true;
			setError( '' );
			refreshUi();

			apiRequest( 'DELETE', spec.deletePath, null )
				.then( function ( res ) {
					deleting = false;
					if ( ! res.ok || res.body.success === false ) {
						setError( restError( res.body, t( 'deleteFailed', 'Could not delete. Try again.' ) ) );
						refreshUi();
						return;
					}
					clearContent();
					tickCooldown();
				} )
				.catch( function () {
					deleting = false;
					setError( t( 'deleteFailed', 'Could not delete. Try again.' ) );
					refreshUi();
				} );
		}

		// Auto-generate on publish runs through WP-Cron, so a save is followed by
		// a short silent gap. Say so, and swap the content in when it lands.
		var autogenEl = wrap.querySelector( '[data-rnrd-autogen]' );
		var autogenErrEl = wrap.querySelector( '[data-rnrd-autogen-error]' );
		var autogenTimer = null;
		var autogenTries = 0;

		function stopAutogenPoll() {
			if ( autogenTimer ) {
				clearTimeout( autogenTimer );
				autogenTimer = null;
			}
		}

		// A failure message inside a collapsed panel, or a sidebar that is shut,
		// is as invisible as no message at all. Open whatever hides it: the
		// panel, and in the block editor the document sidebar the panel lives in.
		function revealAutogenError() {
			if ( ! autogenErrEl || autogenErrEl.hidden ) {
				return;
			}
			var box = autogenErrEl.closest( '.postbox' );
			if ( box && box.classList.contains( 'closed' ) ) {
				box.classList.remove( 'closed' );
				var toggle = box.querySelector( '.handlediv' );
				if ( toggle ) {
					toggle.setAttribute( 'aria-expanded', 'true' );
				}
			}
			if ( autogenErrEl.offsetParent === null && window.wp && wp.data && wp.data.dispatch ) {
				try {
					var editPost = wp.data.dispatch( 'core/edit-post' );
					if ( editPost && editPost.openGeneralSidebar ) {
						editPost.openGeneralSidebar( 'edit-post/document' );
					}
				} catch ( e ) {}
			}
		}

		function pollAutogen() {
			stopAutogenPoll();
			if ( ! autogenEl || postId < 1 || loading ) {
				return;
			}
			if ( autogenTries > 45 ) {
				// Roughly three minutes of polling with no result: say so instead of
				// leaving the "queued" notice up forever.
				if ( ! autogenEl.hidden && autogenErrEl ) {
					autogenEl.hidden = true;
					autogenErrEl.textContent = t( 'autogenTimeout', 'This is taking longer than expected. Reload the page in a minute, or use the Generate button to run it now.' );
					autogenErrEl.hidden = false;
					revealAutogenError();
				}
				return;
			}
			autogenTries++;
			apiRequest( 'GET', spec.getPath, null )
				.then( function ( res ) {
					if ( res.ok && res.body && res.body.autogen_pending ) {
						autogenEl.hidden = false;
						if ( autogenErrEl ) {
							autogenErrEl.hidden = true;
						}
						autogenTimer = setTimeout( pollAutogen, 4000 );
						return;
					}
					// The run ended. If it failed, say why instead of leaving the
					// author to wonder why nothing appeared. This also covers a run
					// that failed before the first poll ever saw it queued.
					if ( res.ok && res.body && res.body.autogen_error && autogenErrEl ) {
						autogenEl.hidden = true;
						autogenErrEl.textContent = res.body.autogen_error;
						autogenErrEl.hidden = false;
						revealAutogenError();
						return;
					}
					if ( ! autogenEl.hidden ) {
						autogenEl.hidden = true;
						var items = res.ok ? spec.parse( res.body ) : [];
						if ( items.length ) {
							applyItems( items );
							markGenerated();
							tickCooldown();
						}
					}
				} )
				.catch( function () {
					autogenTimer = setTimeout( pollAutogen, 8000 );
				} );
		}

		if ( autogenEl ) {
			if ( ! autogenEl.hidden ) {
				pollAutogen();
			}
			if ( autogenErrEl && ! autogenErrEl.hidden ) {
				// The block editor mounts its sidebar after this script runs.
				setTimeout( revealAutogenError, 1200 );
			}
			// Block editor: re-check when a save completes.
			if ( window.wp && wp.data && wp.data.subscribe && wp.data.select ) {
				var wasSaving = false;
				wp.data.subscribe( function () {
					var ed = wp.data.select( 'core/editor' );
					if ( ! ed || ! ed.isSavingPost ) {
						return;
					}
					var saving = ed.isSavingPost() && ! ed.isAutosavingPost();
					if ( wasSaving && ! saving ) {
						autogenTries = 0;
						setTimeout( pollAutogen, 1500 );
					}
					wasSaving = saving;
				} );
			}
		}

		btn.addEventListener( 'click', generate );
		if ( delBtn ) {
			delBtn.addEventListener( 'click', deleteContent );
		}
		refreshUi();
		tickCooldown();
	}

	function boot() {
		var wraps = document.querySelectorAll( '[data-rnrd-mb-gen]' );
		Array.prototype.forEach.call( wraps, init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
