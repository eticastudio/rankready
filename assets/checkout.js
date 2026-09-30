/**
 * Inline checkout.
 *
 * Every Pro button in the admin is a real link to the hosted checkout. This file
 * upgrades the click into a Freemius overlay so the purchase happens on the page
 * the person is already on, and does nothing at all if that is not possible.
 *
 * The rule throughout: never break the link. If the Freemius library did not
 * load, if the overlay throws, if anything is missing, the click falls through
 * to the href and the person still gets to buy. A checkout that silently does
 * nothing is worse than a redirect.
 */
( function () {
	'use strict';

	var cfg = window.rnrdCheckout;
	if ( ! cfg || ! cfg.pluginId || ! cfg.publicKey ) {
		return; // Nothing configured: leave every link exactly as it is.
	}

	var handler = null;

	/**
	 * Build the overlay lazily, once, and only if the library is really there.
	 * Freemius is a third-party script on a CDN; assuming it loaded is how you
	 * end up with a dead button.
	 */
	function getHandler() {
		if ( handler ) {
			return handler;
		}
		/*
		 * FS.Checkout is a CONSTRUCTOR, not an object with a .configure() factory.
		 * Much of the documentation still shows `FS.Checkout.configure({...})`;
		 * on the live v1 library that throws "configure is not a function", and
		 * the prototype carries open / close / destroy / getGuid / recoverCart.
		 * Verified by reading the loaded object in the browser, not from docs.
		 */
		if ( ! window.FS || typeof window.FS.Checkout !== 'function' ) {
			return null;
		}
		try {
			handler = new window.FS.Checkout( {
				plugin_id: cfg.pluginId,
				plan_id: cfg.planId,
				public_key: cfg.publicKey
			} );
		} catch ( e ) {
			handler = null;
		}
		return handler;
	}

	/*
	 * LIFETIME, always, with no lookup.
	 *
	 * There used to be a billing() reader here that asked the page for
	 * `input[name="rnrd-billing"]:checked` and fell back to 'annual'. No such
	 * control exists anywhere in this plugin — the launch is lifetime-only and
	 * RNRD_Pricing::render() says so in its own docblock — so the fallback was
	 * not a fallback, it was the only branch that ever ran. Every overlay asked
	 * Freemius for the annual cycle on a plan whose monthly and annual prices
	 * are deliberately null, which is a checkout with nothing purchasable in it.
	 *
	 * The PHP fallback href had this same bug and was fixed for it in 1.4.0 by
	 * passing WP_FS__PERIOD_LIFETIME explicitly (see buy_url()); the overlay
	 * that runs INSTEAD of that href never got the matching fix. When annual
	 * comes back after the offer, a real toggle comes back with it and this
	 * constant becomes a reader again.
	 */
	var BILLING_CYCLE = 'lifetime';

	/*
	 * How many activations each tier's license carries.
	 *
	 * 'unlimited' is the internal key for what the dashboard now sells as the
	 * 100-site tier; the key is kept because FS_PRICING_UNLIMITED and the
	 * data-rnrd-tier attribute both use it. It used to request `0`, which is
	 * Freemius's genuine "unlimited" and belongs to pricing 91118 — a tier that
	 * is HIDDEN in the dashboard and is not what the button charges for.
	 */
	var LICENSES = { single: 1, unlimited: 100 };

	function open( link ) {
		var h = getHandler();
		if ( ! h ) {
			return false; // Caller lets the link do its job.
		}

		var tier = link.getAttribute( 'data-rnrd-tier' ) || 'single';
		var context = link.getAttribute( 'data-rnrd-context' ) || 'admin';
		var opts = {
			name: cfg.title,
			licenses: LICENSES[ tier ] || LICENSES.single,
			billing_cycle: BILLING_CYCLE,

			/*
			 * Attribution, and the whole of the analytics answer.
			 *
			 * The context is the feature whose Pro button was clicked. It rides
			 * along as a standard UTM parameter on a purchase the person chose
			 * to start, so the Freemius dashboard can report which locked
			 * feature actually drives revenue. Nothing is stored on the site,
			 * nothing is sent anywhere on page load, and there is no tracking of
			 * anyone who does not click Buy. That keeps it inside the
			 * WordPress.org guideline on not phoning home without consent.
			 */
			utm_source: 'wp-admin',
			utm_medium: 'plugin',
			utm_campaign: 'rankready-pro',
			utm_content: context
		};

		if ( cfg.pricing && cfg.pricing[ tier ] ) {
			opts.pricing_id = cfg.pricing[ tier ];
		}
		if ( cfg.email ) {
			opts.user_email = cfg.email;
		}

		try {
			h.open( opts );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	document.addEventListener( 'click', function ( ev ) {
		var link = ev.target.closest ? ev.target.closest( '[data-rnrd-checkout]' ) : null;
		if ( ! link ) {
			return;
		}
		// Let people open it in a new tab or window if that is what they meant.
		if ( ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0 ) {
			return;
		}
		if ( open( link ) ) {
			ev.preventDefault();
		}
		// If open() failed, do nothing: the href takes over.
	} );

	/*
	 * The billing-toggle listener that used to sit here is gone with the toggle.
	 *
	 * It watched `.rnrd-pricing` for a change on `input[name="rnrd-billing"]`
	 * and rewrote every `[data-lifetime]` / `[data-annual]` element from its own
	 * attribute. Neither the input nor those attributes exist in the rendered
	 * screen, so it was an event listener waiting for something that cannot
	 * happen, and its comment ("the markup already carries the annual figures")
	 * described markup that has never shipped. It comes back with the toggle.
	 */
} )();
