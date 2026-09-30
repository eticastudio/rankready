/**
 * RankReady onboarding wizard: step 1 live /llms.txt preview and step 2
 * feature presets. Enqueued by RNRD_Welcome::enqueue_assets() on the wizard
 * screen only; each block returns early when its markup is not on the page.
 */
( function () {
'use strict';
var cfg = window.rnrdOnboard || {};

/* Live /llms.txt preview. Line order, prefixes and the blank lines
   between blocks mirror RNRD_LLMS_Txt::render_index(), so what a person
   reads here is the file they will get and not an illustration. Plain
   textContent throughout: nothing typed into these fields is ever
   written as markup. */
( function () {
	var out = document.getElementById( 'rnrd-llms-preview' );
	if ( ! out ) { return; }
	var f = {};
	var nodes = document.querySelectorAll( '[data-preview-key]' );
	Array.prototype.forEach.call( nodes, function ( el ) {
		f[ el.getAttribute( 'data-preview-key' ) ] = el;
	} );
	var HOME = cfg.home || '/';
	var FALLBACK_NAME = cfg.siteName || '';

	/* Empty fields are omitted rather than filled with their example
	   placeholders. Showing the examples made the panel read as the
	   person's own file before they had written a word of it, which is
	   the one thing a preview must never do. An empty summary really
	   does produce a file with no blockquote, so that is what shows. */
	function val( key ) {
		var el = f[ key ];
		return el ? ( el.value || '' ).replace( /\s+/g, ' ' ).trim() : '';
	}

	function render() {
		var lines = [ '# ' + ( val( 'name' ) || FALLBACK_NAME ), '' ];
		var sum = val( 'summary' );
		if ( sum ) { lines.push( '> ' + sum, '' ); }
		var about = val( 'about' );
		if ( about ) { lines.push( about, '' ); }
		lines.push( '- URL: ' + HOME );
		var el = f.terms;
		var terms = el ? ( el.value || '' ) : '';
		terms = terms.split( /\n/ ).map( function ( t ) { return t.trim(); } )
			.filter( function ( t ) { return t.length > 0; } );
		if ( terms.length ) { lines.push( '- Brand: ' + terms.join( ', ' ) ); }
		out.textContent = lines.join( '\n' );
	}

	Array.prototype.forEach.call( nodes, function ( el ) {
		el.addEventListener( 'input', render );
	} );
	render();
}() );

(function(){
	var form = document.getElementById('rnrd-onboard-features');
	if (!form) return;
	var checks = form.querySelectorAll('.rnrd-onboard__feat-check');

	function syncDepends(){
		checks.forEach(function(el){
			var parentKey = el.getAttribute('data-rnrd-feat-parent');
			if (!parentKey) return;
			var parent = form.querySelector('[name="rnrd_feat[' + parentKey + ']"]');
			var row = el.closest('[data-rnrd-feat-depends]');
			var enabled = parent && parent.checked;
			el.disabled = !enabled;
			if (!enabled) el.checked = false;
			if (row) row.classList.toggle('rnrd-onboard__feat-row--disabled', !enabled);
		});
	}

	function applyPreset(preset){
		if (preset === 'all-off') {
			checks.forEach(function(el){ el.checked = false; });
			syncDepends();
			return;
		}
		if (preset === 'all-on') {
			checks.forEach(function(el){ el.checked = true; });
			syncDepends();
			return;
		}
		// recommended — idempotent snapshot: default-on items checked, opt-in items forced off
		checks.forEach(function(el){
			var optIn = el.getAttribute('data-rnrd-feat-optin') === '1';
			var defaultOn = el.getAttribute('data-rnrd-feat-default') === '1';
			el.checked = !optIn && defaultOn;
		});
		syncDepends();
	}

	checks.forEach(function(el){ el.addEventListener('change', syncDepends); });
	form.querySelectorAll('[data-rnrd-feat-preset]').forEach(function(btn){
		btn.addEventListener('click', function(){
			applyPreset(btn.getAttribute('data-rnrd-feat-preset'));
		});
	});
	syncDepends();
})();
}() );
