/**
 * One sentence inside the deactivation modal saying what happens to the answer.
 *
 * Freemius's form collects a reason and, unless "Anonymous feedback" is ticked,
 * sends it with the site's admin email attached. Nothing in the form says that,
 * so someone answering has no idea whether they are filing a note or handing
 * over a contact. Telling them is the honest thing and it costs one line.
 *
 * It is injected here rather than written as a CSS `content` value so the copy
 * stays a real translatable PHP string, stays selectable, and is read out by a
 * screen reader.
 *
 * Freemius builds the modal on click, so this is driven by the click rather
 * than by a long-lived MutationObserver. An observer with a timeout was the
 * first attempt and it had a real hole: leave the Plugins screen open for a
 * minute, then hit Deactivate, and the observer had already disconnected, so
 * the note silently never appeared. Reacting to the click has no such window.
 */
( function () {
	'use strict';

	var cfg = window.rnrdDeactivate || {};
	if ( ! cfg.note || ! document.body ) {
		return;
	}

	/**
	 * Append the note if the modal is present and does not already carry one.
	 *
	 * @return {boolean} True once the note is in place (or already was).
	 */
	function place() {
		var modal = document.querySelector( '.fs-modal-deactivation-feedback' );
		if ( ! modal ) {
			return false;
		}
		if ( modal.querySelector( '.rnrd-deactivate-note' ) ) {
			return true;
		}
		var body = modal.querySelector( '.fs-modal-panel.active' ) ||
			modal.querySelector( '.fs-modal-body' );
		if ( ! body ) {
			return false;
		}
		var p = document.createElement( 'p' );
		p.className = 'rnrd-deactivate-note';
		// Built server-side with wp_kses and carries only <strong>.
		p.innerHTML = cfg.note;
		body.appendChild( p );
		return true;
	}

	/*
	 * Freemius may render the modal synchronously on click or a tick later,
	 * and their panel swap can replace the body. A short bounded retry covers
	 * both without leaving anything watching the page: six attempts over about
	 * a second, then it stops whether or not it succeeded.
	 */
	function tryPlace() {
		var attempts = 0;
		( function attempt() {
			if ( place() || ++attempts >= 6 ) {
				return;
			}
			window.setTimeout( attempt, 160 );
		}() );
	}

	// Delegated, so it survives Freemius re-rendering the plugin row, and it
	// costs nothing until something is actually clicked.
	document.addEventListener( 'click', function ( e ) {
		var el = e.target;
		if ( ! el || ! el.closest ) {
			return;
		}
		// Their deactivate link, or any control inside an already-open modal
		// (the panel swap between "reason" and the follow-up step).
		if ( el.closest( '.fs-modal-deactivation-feedback' ) ||
			el.closest( 'a[href*="action=deactivate"]' ) ) {
			tryPlace();
		}
	}, true );

	// If the modal is somehow already open when this file runs, cover that too.
	place();
}() );
