<?php
/**
 * The Upgrade Pro screen, and inline checkout.
 *
 * Replaces two things that were not good enough:
 *
 *  1. Freemius's stock pricing screen, which lists paid plans only. Someone
 *     looking at it cannot see what they already have, so "what am I actually
 *     buying?" is unanswerable without leaving the page. Free is listed here
 *     beside Pro for exactly that reason.
 *
 *  2. The redirect to checkout.freemius.com. Sending someone off the site to a
 *     different domain mid-decision is the single largest drop-off in a funnel
 *     like this. Freemius publishes a Checkout JS overlay for this, so the
 *     purchase happens on the page the person is already on.
 *
 * Progressive by construction: every button is a real link to the hosted
 * checkout, and the JS only upgrades the click into an overlay. With JS blocked,
 * or if Freemius's script fails to load, the button still buys the license.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Pricing {

	/*
	 * Live Freemius identifiers, read from the dashboard rather than guessed.
	 *
	 * Pro is sold as an ADD-ON of the free product, so the checkout must open
	 * against the ADD-ON, not the parent. These used to be the parent's own plan
	 * and pricing ids (plan 64498, pricing 85906/85907), which belonged to the
	 * short-lived premium-version experiment. Buying through those would have
	 * sold a license for the wrong product.
	 *
	 * Created 2026-09-07: add-on 39103 (`rankready-pro`), plan 65030 (`pro`),
	 * with no free plan. The parent product is 37729, hardcoded where it is
	 * actually needed, in this plugin's fs_dynamic_init() call; it is not
	 * repeated as a constant here because nothing in either build reads it.
	 */
	const FS_PLUGIN_ID  = '39103';
	const FS_PUBLIC_KEY = 'pk_597a8c6a85b1e7d39b931e78b1e8d';
	const FS_PLAN_PRO   = '65030';

	/*
	 * Pricing ids, so a checkout opens on the right license tier.
	 *
	 * CONFIRMED LIVE 2026-09-26, read off plan 65030 in the vendor dashboard and
	 * then proven against the checkout a buyer actually lands on, not inferred
	 * from the dashboard alone:
	 *
	 *   91115  Single Site   lifetime $49, monthly and annual blank
	 *   91196  100 Sites     lifetime $99, monthly and annual blank
	 *
	 * checkout.freemius.com/plugin/39103/plan/65030/?billing_cycle=lifetime
	 * &licenses=1&pricing_id=91115 renders "RankReady Pro License, One Time
	 * payment, $49.00, Single Site"; the licenses=100 / pricing_id=91196 variant
	 * renders "$99.00, 100-Sites". Both show the 14-day guarantee. Monthly and
	 * annual staying blank is what keeps the launch lifetime-only.
	 *
	 * FS_PRICING_UNLIMITED keeps its name because a public class const is a
	 * shipped contract, but the tier it addresses is now the 100-site one. The
	 * old "Unlimited Sites" pricing (91118) is HIDDEN in the dashboard, so
	 * pointing a live button at it would open a checkout for a tier that is no
	 * longer sold.
	 *
	 * Blanking either one is still the safe state rather than a broken one:
	 * has_checkout() below returns false, every Buy button falls back to an
	 * ordinary link, and nobody is dropped into a checkout that would charge the
	 * wrong amount. Re-verify against the dashboard before changing a value here;
	 * a stale id sells the wrong tier silently.
	 */
	const FS_PRICING_SINGLE    = '91115';
	const FS_PRICING_UNLIMITED = '91196';

	/**
	 * Whether the inline checkout overlay can safely be offered.
	 *
	 * @return bool
	 */
	public static function has_checkout(): bool {
		return '' !== self::FS_PLUGIN_ID
			&& '' !== self::FS_PLAN_PRO
			&& '' !== self::FS_PRICING_SINGLE
			&& '' !== self::FS_PRICING_UNLIMITED;
	}

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * The Freemius Checkout library, loaded only on RankReady screens.
	 *
	 * Never enqueued globally: a third-party script on every admin page of every
	 * site running this plugin is exactly the behaviour that gets a plugin
	 * pulled, and it would be loaded for people who will never see a Pro button.
	 */
	public static function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'rankready' ) ) {
			return;
		}

		// No prices configured yet means no overlay. The buttons are real links
		// either way, so the page keeps working; it just does not try to open a
		// checkout that cannot complete.
		if ( ! self::has_checkout() ) {
			return;
		}

		// Version is deliberately null. This URL is Freemius's own versioned
		// endpoint (/js/v1/) and appending ?ver= to a third-party CDN asset both
		// busts their cache and pins nothing, since the file is not ours to
		// version. The sniff cannot tell an external endpoint from a local file.
		wp_enqueue_script(
			'rnrd-fs-checkout',
			'https://checkout.freemius.com/js/v1/',
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party CDN URL, already versioned in its path.
			true
		);

		wp_enqueue_script(
			'rnrd-checkout',
			RNRD_URL . 'assets/checkout.js',
			array( 'rnrd-fs-checkout' ),
			RNRD_VERSION,
			true
		);

		wp_localize_script(
			'rnrd-checkout',
			'rnrdCheckout',
			array(
				'pluginId'  => self::FS_PLUGIN_ID,
				'publicKey' => self::FS_PUBLIC_KEY,
				'planId'    => self::FS_PLAN_PRO,
				'pricing'   => array(
					'single'    => self::FS_PRICING_SINGLE,
					'unlimited' => self::FS_PRICING_UNLIMITED,
				),
				'title'     => 'RankReady Pro',
				// Prefills the buyer's email so they are not retyping something
				// WordPress already knows. Nothing is sent anywhere until they
				// choose to buy.
				'email'     => wp_get_current_user()->user_email,
			)
		);
	}

	/**
	 * A Pro call to action that opens checkout in place.
	 *
	 * @param string $label   Button text.
	 * @param string $context Which feature sent them here. Travels to Freemius as
	 *                        a UTM parameter, so the vendor dashboard answers
	 *                        "which locked feature actually drives purchases?"
	 *                        without this plugin tracking anybody locally.
	 * @param string $classes Extra CSS classes.
	 * @param string $tier    'single' (1 site) or 'unlimited' (the 100-site tier;
	 *                        the key predates the rename and is kept because
	 *                        checkout.js and FS_PRICING_UNLIMITED both use it).
	 */
	public static function cta( string $label, string $context, string $classes = '', string $tier = 'single' ): string {
		return sprintf(
			'<a class="rnrd-buy %s" href="%s" data-rnrd-checkout data-rnrd-context="%s" data-rnrd-tier="%s">%s</a>',
			esc_attr( $classes ),
			esc_url( self::buy_url( $context, $tier ) ),
			esc_attr( $context ),
			esc_attr( $tier ),
			esc_html( $label )
		);
	}

	/**
	 * Where a Buy button actually goes.
	 *
	 * IMPORTANT: NOT rnrd_upgrade_url(). That helper resolves to this very pricing
	 * screen, which is the right destination for a Pro pill sitting next to a
	 * locked field ("show me what Pro is") and exactly the wrong one for a Buy
	 * button ALREADY ON that screen: the button reloads the page it is on and
	 * the customer cannot buy. That is how this shipped until 1.4.0.
	 *
	 * The href has to be somewhere a purchase can complete on its own, because
	 * it is what runs whenever the overlay does not:
	 *   - the pricing ids are still blank, so checkout.js was never enqueued,
	 *   - JavaScript is blocked, broken or still loading,
	 *   - the visitor middle-clicked or opened it in a new tab.
	 *
	 * It is NOT Freemius's own in-admin pricing page either, which is what
	 * `$fs->get_upgrade_url()` returns. That page is scoped to the PARENT product
	 * (37729), and Pro is sold as an ADD-ON (39103) with its plan on the add-on,
	 * so the parent's pricing screen renders zero plans and zero buy buttons: a
	 * dead end for anyone whose JavaScript did not run. `$fs->is_addon()` is false
	 * on this instance for exactly that reason, so no Freemius helper on it can
	 * produce the add-on's checkout.
	 *
	 * So the href is the add-on's own hosted checkout, built from the same ids
	 * checkout.js uses and verified against the page a buyer actually lands on
	 * (plan 65030, pricing 91115 = $49 single, 91196 = $99 for 100 sites, lifetime).
	 * Overlay and fallback therefore open the same tier at the same price, and
	 * when the ids are blank `has_checkout()` is false and this drops to the
	 * public pricing page rather than to a checkout that cannot complete.
	 *
	 * The billing cycle is passed explicitly. Freemius defaults it to annual, so
	 * leaving it off asked for the annual cycle on a launch that sells lifetime
	 * and nothing else. When annual comes back after the offer, this is the
	 * single line that changes.
	 *
	 * @param string $context Which surface sent them here.
	 * @param string $tier    'single' or 'unlimited', matching cta()'s own keys.
	 * @return string
	 */
	private static function buy_url( string $context, string $tier = 'single' ): string {
		if ( self::has_checkout() ) {
			$pricing_id = 'unlimited' === $tier ? self::FS_PRICING_UNLIMITED : self::FS_PRICING_SINGLE;
			$licenses   = 'unlimited' === $tier ? '100' : '1';

			$url = add_query_arg(
				array(
					'billing_cycle' => 'lifetime',
					'licenses'      => $licenses,
					'pricing_id'    => $pricing_id,
					'rnrd_from'     => rawurlencode( $context ),
				),
				'https://checkout.freemius.com/plugin/' . self::FS_PLUGIN_ID . '/plan/' . self::FS_PLAN_PRO . '/'
			);

			return $url;
		}

		// No pricing ids configured, so there is no checkout to open. The public
		// pricing page is the honest destination; the in-admin one is where we
		// already are, and the parent's Freemius pricing page has no plans on it.
		$fallback = 'https://hostmy.blog/plugins/rankready/#pricing';
		return $context ? add_query_arg( 'from', rawurlencode( $context ), $fallback ) : $fallback;
	}

	/**
	 * The Upgrade Pro screen.
	 *
	 * LIFETIME ONLY, as a launch offer. There is deliberately no annual option
	 * and no billing toggle: a toggle with one real choice is a control that
	 * does nothing, and the launch price only exists in one shape. Annual comes
	 * back when the offer ends, and the toggle returns with it.
	 *
	 * No Free vs Pro table here either. This screen is inside the plugin, so the
	 * reader is already running Free and knows what it does; a comparison of
	 * what they have against what they could have belongs on the public site,
	 * where the reader has neither. In here it was a wall of ticks between
	 * somebody and the button.
	 *
	 * Site count is the qualifier that decides which card someone picks, so it
	 * sits directly under the price at the same weight rather than as bullet
	 * four. Reference: Plain's pricing, where "Up to 150 active customers" reads
	 * as part of the price, not as a feature.
	 */
	public static function render(): void {
		$is_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();
		?>
		<div class="rnrd-pricing">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Upgrade to RankReady Pro', 'rankready-ai-llm-seo' ); ?></h1>

			<?php
			/*
			 * The state this screen used to have no answer for.
			 *
			 * Someone who has already PAID, installed the Pro zip and not yet
			 * entered their key lands here from the sidebar row that reads
			 * "Activate Now" — and the only thing on the page was two Buy
			 * buttons asking them to pay again. The license form itself is a
			 * Freemius modal on plugins.php opened by its own JS, so there is
			 * no URL to deep-link to; the honest move is to say where it is.
			 *
			 * Shown only when Pro is present (RNRD_PRO_VERSION) and unlicensed.
			 * A site without Pro installed never sees it, so it is not a nag.
			 */
			if ( ! $is_pro && defined( 'RNRD_PRO_VERSION' ) ) :
				/*
				 * Two different people land on this block, and telling them the
				 * same thing insults one of them. Someone who bought, installed
				 * and never entered a key needs to be told where the key field
				 * is. Someone whose license has LAPSED already did all of that,
				 * and "no license key has been entered yet" tells them the
				 * plugin lost their key, on the same click where the notice
				 * above correctly says the license expired.
				 *
				 * Freemius keeps is_registered() true across an expiry, which is
				 * exactly why the Pro notice in rankready-pro.php uses it as the
				 * discriminator. This screen now derives from the same read, so
				 * the two surfaces cannot contradict each other.
				 */
				$rnrd_registered = false;
				$rnrd_pending    = false;
				$rnrd_account    = '';
				if ( function_exists( 'rnrd_pro_fs' ) ) {
					$rnrd_fs = rnrd_pro_fs();
					if ( is_object( $rnrd_fs ) ) {
						try {
							if ( method_exists( $rnrd_fs, 'is_registered' ) ) {
								$rnrd_registered = (bool) $rnrd_fs->is_registered();
							}
							if ( method_exists( $rnrd_fs, 'is_pending_activation' ) ) {
								$rnrd_pending = (bool) $rnrd_fs->is_pending_activation();
							}
							if ( method_exists( $rnrd_fs, 'get_account_url' ) ) {
								$rnrd_account = (string) $rnrd_fs->get_account_url();
							}
						} catch ( \Throwable $e ) {
							// A pricing screen must never fatal over a license read.
							$rnrd_registered = false;
							$rnrd_pending    = false;
						}
					}
				}
				/*
				 * "Registered" alone is not "expired": a site that opted in to
				 * Freemius without entering a key is registered too, and so is a
				 * cancelled license. Only a license whose expiry date has passed
				 * gets the renewal copy; everyone else gets the key prompt, and a
				 * key that is waiting on its confirmation email gets told so
				 * instead of being asked for the key a second time.
				 */
				$rnrd_expired = class_exists( 'RNRD_Notices' ) && RNRD_Notices::licence_expired();
				if ( '' === $rnrd_account && function_exists( 'rnrd_upgrade_url' ) ) {
					$rnrd_account = rnrd_upgrade_url( 'renewal' );
				}
				/*
				 * plugins.php is the correct destination, and NOT
				 * $fs->get_activation_url(). For an add-on that has a free plan,
				 * get_activation_url() forwards to the PARENT's activation URL
				 * (class-freemius.php: "if ( $this->is_addon() && $this->has_free_plan() )
				 * return $this->get_parent_instance()->get_activation_url()"), and
				 * has_free_plan() is "! is_only_premium()". Pro declares `parent`
				 * and deliberately does not declare is_premium_only, so that URL
				 * resolves to Free's opt-in screen, which carries no license key
				 * field. plugins.php carries one: Freemius adds an "Activate
				 * License" row action under RankReady Pro there and it opens the
				 * key dialog. Verified in the bundled SDK, not assumed.
				 */
				$rnrd_activate = admin_url( 'plugins.php' );
				/*
				 * #48 / #50: Pro can open that same key dialog right here, so
				 * plugins.php becomes the no-JavaScript fallback rather than a
				 * second errand. The class comes from Pro; this file carries
				 * no Pro code, only a function_exists() check.
				 */
				$rnrd_dialog = function_exists( 'rnrd_pro_licence_dialog_class' ) ? (string) rnrd_pro_licence_dialog_class() : '';
				?>
				<div class="rnrd-pricing__owned">
					<?php if ( $rnrd_pending ) : ?>
					<p>
						<strong><?php esc_html_e( 'One step left: confirm your email.', 'rankready-ai-llm-seo' ); ?></strong>
						<?php esc_html_e( 'Freemius sent you a confirmation email. Open it and click the link, and the Pro features switch on. No need to enter the key again.', 'rankready-ai-llm-seo' ); ?>
					</p>
					<?php elseif ( $rnrd_registered && $rnrd_expired ) : ?>
					<p>
						<strong><?php esc_html_e( 'Your Pro license has expired.', 'rankready-ai-llm-seo' ); ?></strong>
						<?php esc_html_e( 'Everything you already generated stays exactly as it is, and generating by hand is still unlimited. Renewing switches the Pro features and Pro updates back on.', 'rankready-ai-llm-seo' ); ?>
					</p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( $rnrd_account ); ?>"><?php esc_html_e( 'Renew license', 'rankready-ai-llm-seo' ); ?></a>
					</p>
					<?php else : ?>
					<p>
						<strong><?php esc_html_e( 'Already bought Pro?', 'rankready-ai-llm-seo' ); ?></strong>
						<?php esc_html_e( 'RankReady Pro is installed. Add your license key and the Pro features switch on.', 'rankready-ai-llm-seo' ); ?>
					</p>
					<p>
						<a class="<?php echo esc_attr( trim( 'button button-primary ' . $rnrd_dialog ) ); ?>" href="<?php echo esc_url( $rnrd_activate ); ?>"><?php esc_html_e( 'Activate License', 'rankready-ai-llm-seo' ); ?></a>
						<span class="rnrd-pricing__owned-hint">
							<?php
							if ( '' !== $rnrd_dialog ) {
								esc_html_e( 'Paste the key from your purchase email.', 'rankready-ai-llm-seo' );
							} else {
								esc_html_e( 'This opens your Plugins screen. Use "Activate License" under RankReady Pro and paste the key from your purchase email.', 'rankready-ai-llm-seo' );
							}
							?>
						</span>
					</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="rnrd-pricing__head">
				<span class="rnrd-launch"><?php esc_html_e( 'Launch offer', 'rankready-ai-llm-seo' ); ?></span>
				<h2><?php esc_html_e( 'Pay once. Keep it for the life of the product.', 'rankready-ai-llm-seo' ); ?></h2>
				<p><?php esc_html_e( 'Everything you generate by hand stays free and uncapped, on every plan. Pro adds the work that does not fit one post at a time, switched on once from the same screens you already use. What you buy is a one-time license that never renews. The launch price is what may change later, not your license.', 'rankready-ai-llm-seo' ); ?></p>
			</div>

			<div class="rnrd-plans">

				<article class="rnrd-plan rnrd-plan--free">
					<span class="rnrd-plan__eyebrow"><?php esc_html_e( 'What you have now', 'rankready-ai-llm-seo' ); ?></span>
					<h3 class="rnrd-plan__name"><?php esc_html_e( 'Free', 'rankready-ai-llm-seo' ); ?></h3>
					<p class="rnrd-plan__price"><strong>$0</strong></p>
					<?php
					/*
					 * The count slot is the line the eye compares across the
					 * three cards, and it used to say "Unlimited sites" here
					 * while the two paid cards say "1 site" and "100 sites".
					 * Read left to right that is Unlimited, then 1, then 100:
					 * the Free card was arguing against the purchase on the one
					 * axis where Free genuinely wins, and the axis is the wrong
					 * one anyway. Free is not limited by how many sites you run
					 * it on, it is limited to one post at a time. So the slot
					 * now names the real constraint, and the unlimited-sites
					 * fact moves into the line below rather than being dropped.
					 */
					?>
					<p class="rnrd-plan__count"><?php esc_html_e( 'One post at a time', 'rankready-ai-llm-seo' ); ?></p>
					<p class="rnrd-plan__sub"><?php esc_html_e( 'From WordPress.org, on any number of sites. No account, no card, no expiry.', 'rankready-ai-llm-seo' ); ?></p>
					<ul class="rnrd-plan__list">
						<li><?php esc_html_e( 'Unlimited summaries and FAQs, by hand', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'llms.txt, Markdown endpoints, Accept negotiation', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php
						// Counted, never hardcoded: the wizard already counts the
						// same list with %d and the two must not drift apart.
						$rnrd_crawlers = class_exists( 'RNRD_Crawler_Access' ) ? count( RNRD_Crawler_Access::get_llm_crawlers() ) : 0;
						/* translators: %d is the number of AI crawlers RankReady can allow or block. */
						echo esc_html( sprintf( _n( 'Allow or block %d AI crawler', 'Allow or block %d AI crawlers', $rnrd_crawlers, 'rankready-ai-llm-seo' ), $rnrd_crawlers ) );
						?></li>
						<li><?php esc_html_e( 'Article, FAQPage, Speakable and Person schema', 'rankready-ai-llm-seo' ); ?></li>
					</ul>
					<?php
					/*
					 * This line used to render unconditionally, so a licensed
					 * Pro site saw "Your current plan" on the Free card AND on
					 * the Pro card: two cards claiming to be the plan in force,
					 * on the screen that exists to make the plan unambiguous.
					 * The marker belongs to exactly one card at a time.
					 */
					?>
					<?php if ( $is_pro ) : ?>
						<p class="rnrd-plan__current rnrd-plan__current--included"><?php esc_html_e( 'Included in your plan', 'rankready-ai-llm-seo' ); ?></p>
					<?php else : ?>
						<p class="rnrd-plan__current"><?php esc_html_e( 'Your current plan', 'rankready-ai-llm-seo' ); ?></p>
					<?php endif; ?>
				</article>

				<article class="rnrd-plan rnrd-plan--pro">
					<?php
					/*
					 * NOT "Most chosen". Freemius has sold zero licenses, so a
					 * claim about what other buyers picked would be invented
					 * social proof on the one screen where honesty is worth the
					 * most. This badge says what we recommend, which is a
					 * position we can actually hold.
					 */
					?>
					<span class="rnrd-plan__flag"><?php esc_html_e( 'Start with one site', 'rankready-ai-llm-seo' ); ?></span>
					<span class="rnrd-plan__eyebrow"><?php esc_html_e( 'For one site', 'rankready-ai-llm-seo' ); ?></span>
					<h3 class="rnrd-plan__name"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></h3>
					<p class="rnrd-plan__price"><strong>$49</strong> <span><?php esc_html_e( 'once', 'rankready-ai-llm-seo' ); ?></span></p>
					<p class="rnrd-plan__count"><strong>1</strong> <?php esc_html_e( 'site', 'rankready-ai-llm-seo' ); ?></p>
					<p class="rnrd-plan__sub"><?php esc_html_e( 'Updates for the life of the product. No renewal.', 'rankready-ai-llm-seo' ); ?></p>
					<ul class="rnrd-plan__list">
						<li><?php esc_html_e( 'Everything in Free, uncapped as always', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'Bulk generation across the archive, resumable', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'Generate on publish, so nothing is missed', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'Custom post types: products, docs, anything', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'HowTo and ItemList schema, headless API', 'rankready-ai-llm-seo' ); ?></li>
						<?php /* Shipped, not pending: RNRD_LLMs_Txt::should_hide_branding() honours RNRD_OPT_HIDE_BRANDING the moment a license is active, and the toggle is on the LLMs.txt subtab. Flagging a built feature as unreleased on the page that asks for the money is both untrue and the reason the row never sells. (Phrase avoided verbatim on purpose: build-free.sh pins its count in this file.) */ ?>
						<li><?php esc_html_e( 'Remove the RankReady credit from llms.txt', 'rankready-ai-llm-seo' ); ?></li>
					</ul>
					<?php if ( $is_pro ) : ?>
						<p class="rnrd-plan__current"><?php esc_html_e( 'Your current plan', 'rankready-ai-llm-seo' ); ?></p>
					<?php else : ?>
						<?php echo wp_kses_post( self::cta( __( 'Buy lifetime, $49', 'rankready-ai-llm-seo' ), 'pricing-pro', 'rnrd-buy--primary', 'single' ) ); ?>
						<p class="rnrd-plan__risk"><?php esc_html_e( '14 day refund, no questions asked', 'rankready-ai-llm-seo' ); ?></p>
					<?php endif; ?>
				</article>

				<article class="rnrd-plan rnrd-plan--agency">
					<?php
					/*
					 * This card sold "Agency, $249, Unlimited sites" while the
					 * live plan charges $99 for a 100-site license, so the
					 * button quoted a price the checkout does not take and
					 * named a tier the dashboard has hidden (pricing 91118).
					 *
					 * Both cards are the SAME plan (65030) at two site counts,
					 * which is also why the feature list no longer claims
					 * priority support or white-labelling here: one plan cannot
					 * hand the 100-site buyer a capability the single-site
					 * buyer pays for and does not get. What genuinely differs
					 * is how many activations the license carries.
					 *
					 * The `rnrd-plan--agency` class no longer matches the card's
					 * title, and it stays that way on purpose: public CSS class
					 * names are a shipped contract under the backward
					 * compatibility rule, and this one is also asserted on by
					 * the e2e suite. Rename the heading, never the hook.
					 */
					?>
					<span class="rnrd-plan__eyebrow"><?php esc_html_e( 'For many sites', 'rankready-ai-llm-seo' ); ?></span>
					<h3 class="rnrd-plan__name"><?php esc_html_e( '100 Sites', 'rankready-ai-llm-seo' ); ?></h3>
					<p class="rnrd-plan__price"><strong>$99</strong> <span><?php esc_html_e( 'once', 'rankready-ai-llm-seo' ); ?></span></p>
					<p class="rnrd-plan__count rnrd-plan__count--big"><strong>100</strong> <?php esc_html_e( 'sites', 'rankready-ai-llm-seo' ); ?></p>
					<p class="rnrd-plan__sub"><?php esc_html_e( 'One license you activate across up to 100 sites, for the life of the product.', 'rankready-ai-llm-seo' ); ?></p>
					<ul class="rnrd-plan__list">
						<li><?php esc_html_e( 'Everything in Pro, on up to 100 sites', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'One license key, one place to manage it', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'Move an activation between sites any time', 'rankready-ai-llm-seo' ); ?></li>
					</ul>
					<?php echo wp_kses_post( self::cta( __( 'Buy lifetime, $99', 'rankready-ai-llm-seo' ), 'pricing-agency', '', 'unlimited' ) ); ?>
					<p class="rnrd-plan__risk"><?php esc_html_e( '14 day refund, no questions asked', 'rankready-ai-llm-seo' ); ?></p>
				</article>

			</div>

			<?php
			/*
			 * Three facts that remove risk, in the order the hesitation actually
			 * arrives: what does it cost me, what if I hate it, will it break
			 * what I already run.
			 *
			 * The third slot used to read "GPL, never locked / Nothing you
			 * generated is ever removed". Two unrelated ideas welded together,
			 * led by a software license acronym. Nobody hesitates at a checkout
			 * because they are unsure of the license; they hesitate because they
			 * already run Yoast or Rank Math and are afraid this fights it.
			 * That is the objection, so that is what the slot answers now. The
			 * conflict matrix was measured across all three at delta 0, so this
			 * is a tested claim rather than a reassurance.
			 *
			 * The "nothing is removed" fact was worth keeping, so it moved into
			 * the refund line, which is exactly where someone wonders what
			 * happens to their content if they ask for the money back.
			 */
			?>
			<ul class="rnrd-assure">
				<li><strong><?php esc_html_e( 'Pay once', 'rankready-ai-llm-seo' ); ?></strong><?php esc_html_e( 'Your license does not renew. One payment, and it stays yours.', 'rankready-ai-llm-seo' ); ?></li>
				<li><strong><?php esc_html_e( '14 day refund', 'rankready-ai-llm-seo' ); ?></strong><?php esc_html_e( 'No questions asked, and every summary and FAQ you generated stays on your site.', 'rankready-ai-llm-seo' ); ?></li>
				<li><strong><?php esc_html_e( 'Works with your SEO plugin', 'rankready-ai-llm-seo' ); ?></strong><?php esc_html_e( 'Runs alongside Yoast, Rank Math and AIOSEO, no conflict.', 'rankready-ai-llm-seo' ); ?></li>
			</ul>

			<p class="rnrd-pricing__foot">
				<?php esc_html_e( 'The launch price will not last. What you pay is one-time, permanently.', 'rankready-ai-llm-seo' ); ?>
				<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'what-rankready-does', __( 'What RankReady does', 'rankready-ai-llm-seo' ) ); } ?>
			</p>

		</div>
		<?php
	}
}
