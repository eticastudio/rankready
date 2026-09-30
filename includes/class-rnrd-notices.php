<?php
/**
 * Admin notices: review request, Pro availability, license renewal.
 *
 * One class, because three separate `admin_notices` callbacks is how a plugin
 * ends up showing three banners at once. Everything here obeys the same rules:
 *
 *  - Never more than ONE RankReady notice on a screen, ever.
 *  - Only on RankReady's own screens, never on someone else's dashboard.
 *  - Earned by usage or time, never shown on day one.
 *  - Dismiss means dismissed. "Later" means later, with a real date.
 *  - Per user, not per site, so one admin dismissing does not silence another.
 *  - No emojis (hard rule 8). SVG or nothing.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Notices {

	/** User meta prefix. Per user on purpose. */
	const META = '_rnrd_notice_';

	/** Nothing is shown before the plugin has been installed this long. */
	const MIN_AGE_DAYS = 7;

	/** A review is only worth asking for after the plugin has actually done work. */
	const REVIEW_MIN_AGE_DAYS    = 14;
	const REVIEW_MIN_GENERATIONS = 5;

	/** How long a "remind me later" lasts. */
	const SNOOZE_DAYS = 30;

	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_rnrd_notice_action', array( __CLASS__, 'handle' ) );
	}

	/* ── plumbing ─────────────────────────────────────────────────────────── */

	/**
	 * The Upgrade Pro screen is deliberately notice-free.
	 *
	 * Asking for a WordPress.org review, or announcing that Pro exists, on the
	 * one screen where somebody is deciding whether to pay is an interruption at
	 * the worst possible moment. It also pushes the plans below the fold on a
	 * phone. Nothing here needs saying on that screen.
	 */
	/*
	 * Two RankReady screens must never carry a notice, for two different
	 * reasons, so they are listed together rather than gated separately.
	 *
	 * `rankready-pro-upgrade` is the checkout screen. A notice there competes
	 * with the one decision the screen exists for, and a "see Pro" notice on
	 * the Pro pricing page is noise by definition.
	 *
	 * `rankready-welcome` is the onboarding wizard, and this one was a visible
	 * defect rather than a preference. is_rankready_screen() matches any page
	 * whose slug starts with `rankready`, so the wizard qualified, but
	 * RNRD_Admin::enqueue_admin_assets() loads admin.css on exactly two screen
	 * groups and the wizard is neither: it prints its own <style> block. So the
	 * notice rendered there with no stylesheet at all, which made its dismiss
	 * control a bare 17px dashicon in a 17px box against a 24px minimum, and
	 * the whole notice a stack of unstyled divs on the first screen a new user
	 * ever sees. Caught by the visual matrix at free-aged/onboarding-1..3,
	 * where the review notice becomes eligible.
	 *
	 * Enqueuing admin.css on the wizard would have fixed the pixels and kept
	 * the real problem: a linear three-step flow should not be interrupted by
	 * an unrelated ask. The notices are not lost, they appear on the next
	 * RankReady screen the person opens.
	 */
	private static function is_notice_free_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, array( 'rankready-pro-upgrade', 'rankready-welcome' ), true );
	}

	private static function is_rankready_screen(): bool {
		// No nonce here on purpose. This reads the current screen only to decide
		// whether to DISPLAY a notice; it changes nothing and acts on nothing.
		// The one place that does change state, handle(), verifies both a nonce
		// and a capability. The value is passed through sanitize_key() before
		// any use, and only ever compared against a literal prefix.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, see above.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return '' !== $page && 0 === strpos( $page, 'rankready' );
	}

	private static function installed_days(): int {
		$first = (int) get_option( 'rnrd_first_activated', 0 );
		if ( ! $first ) {
			// Never recorded, so backfill once rather than guessing zero forever.
			$first = time();
			update_option( 'rnrd_first_activated', $first, false );
		}
		return (int) floor( ( time() - $first ) / DAY_IN_SECONDS );
	}

	private static function state( string $key ): string {
		return (string) get_user_meta( get_current_user_id(), self::META . $key, true );
	}

	/** A notice is hidden when dismissed forever, or snoozed until a future date. */
	private static function hidden( string $key ): bool {
		$v = self::state( $key );
		if ( 'dismissed' === $v ) {
			return true;
		}
		return ( '' !== $v && ctype_digit( $v ) && (int) $v > time() );
	}

	private static function action_url( string $key, string $action ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=rnrd_notice_action&notice=' . rawurlencode( $key ) . '&do=' . rawurlencode( $action ) ),
			'rnrd_notice_' . $key
		);
	}

	public static function handle(): void {
		$key = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$do  = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';

		if ( ! $key || ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'rnrd_notice_' . $key ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}

		$value = ( 'later' === $do ) ? (string) ( time() + ( self::SNOOZE_DAYS * DAY_IN_SECONDS ) ) : 'dismissed';
		if ( 'renewal' === $key && 'dismissed' === $value ) {
			// Scoped to this lapse. A flat 'dismissed' silenced the later
			// "expired" notice and every renewal window after it.
			$value = 'dismissed@' . self::renewal_window();
		}
		update_user_meta( get_current_user_id(), self::META . $key, $value );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=rankready-ai-llm-seo' ) );
		exit;
	}

	/**
	 * How many posts actually carry a RankReady summary.
	 *
	 * Counted from post meta rather than kept in a counter option. A counter has
	 * to be incremented at every generation site and silently under-reports the
	 * moment one is missed, and it would read zero for every site that installed
	 * before the counter existed. This cannot drift.
	 *
	 * Cached for a day because it runs on an admin screen and the answer only
	 * needs to be roughly right.
	 */
	private static function generated_count(): int {
		$cached = get_transient( 'rnrd_generated_count' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$meta_key = defined( 'RNRD_META_SUMMARY' ) ? RNRD_META_SUMMARY : '_rnrd_summary';

		// A direct query is the right tool: there is no core API that counts posts
		// by meta key without loading the posts themselves, and WP_Query with
		// fields=ids would still build and hydrate the whole result set. It runs
		// once a day at most (the caller returns the transient above and sets it
		// below), and only on an admin screen.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached in a DAY_IN_SECONDS transient by the caller.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
				$meta_key
			)
		);

		set_transient( 'rnrd_generated_count', $count, DAY_IN_SECONDS );
		return $count;
	}

	/* ── the notices ──────────────────────────────────────────────────────── */

	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) || ! self::is_rankready_screen() ) {
			return;
		}
		if ( self::is_notice_free_screen() ) {
			return;
		}

		/*
		 * Pro installed but never activated owns the slot. Pro prints its own
		 * "activate your license key" notice on these same screens, so without
		 * this the one-notice-per-screen rule below quietly became two. Worse,
		 * notice_pro() keys off rnrd_is_pro(), which is false for an
		 * unactivated license, so the person who had already bought and
		 * installed Pro was the one being told that Pro exists.
		 *
		 * An EXPIRED license is deliberately NOT covered here. Freemius keeps
		 * is_registered() true across an expiry, Pro's notice stands down in
		 * that case, and notice_renewal() below is the notice that says the
		 * true thing about a lapsed license. Suppressing the whole stack on
		 * `! rnrd_pro_is_licensed()` alone would have silenced exactly that.
		 *
		 * Both functions are declared by the Pro plugin, so this whole block is
		 * a no-op on a Free-only install, which is the common case.
		 */
		if ( function_exists( 'rnrd_pro_is_licensed' ) && ! rnrd_pro_is_licensed() ) {
			$registered = false;
			if ( function_exists( 'rnrd_pro_fs' ) ) {
				try {
					$fs = rnrd_pro_fs();
					$registered = is_object( $fs )
						&& method_exists( $fs, 'is_registered' )
						&& $fs->is_registered();
				} catch ( \Throwable $e ) {
					$registered = false;
				}
			}
			if ( ! $registered ) {
				return;
			}
		}

		// Order matters. A license about to lapse outranks everything, because it
		// is the only one where doing nothing costs the reader something.
		$keys = array( 'renewal', 'review', 'pro' );

		/*
		 * A staging, development or local copy is a developer at work, not a
		 * site owner deciding anything. Asking them for a WordPress.org review
		 * or pointing them at Pro there is noise, and it is noise they see on
		 * every clone of every client site. Yoast SEO does the same with its
		 * "optimize your SEO data" notification on non-production sites:
		 * https://developer.yoast.com/customization/yoast-seo/wp-get-environment-type-in-yoast-seo/
		 * The renewal notice stays: a lapsing license is true on any copy of
		 * the site.
		 * wp_get_environment_type() returns 'production' unless
		 * WP_ENVIRONMENT_TYPE says otherwise, so an ordinary live site is
		 * unaffected.
		 */
		if ( 'production' !== wp_get_environment_type() ) {
			$keys = array( 'renewal' );
		}

		foreach ( $keys as $key ) {
			if ( 'renewal' !== $key && self::hidden( $key ) ) {
				continue; // Renewal checks its own, window-scoped, dismissal.
			}
			$method = 'notice_' . $key;
			if ( self::$method() ) {
				return; // One notice per screen. Always.
			}
		}
	}

	/**
	 * Whether this install has a Pro license that has already lapsed, as
	 * opposed to one that was never entered.
	 *
	 * The sidebar needs this. Its second row has three states, and an expired
	 * license fell into the middle one, so someone whose license lapsed was
	 * told to "Activate Now" while the notice at the top of the same screen
	 * told them to "Renew license". They have already activated; being sent to
	 * do it again reads as though the plugin lost their key. Both surfaces now
	 * derive from this one predicate, so they cannot drift apart.
	 *
	 * Deliberately the same Freemius read as notice_renewal() below, minus the
	 * fourteen-day window: the sidebar has no reason to go quiet while the
	 * license is still lapsed.
	 */
	public static function licence_expired(): bool {
		if ( ! function_exists( 'rnrd_pro_fs' ) ) {
			return false;
		}
		$fs = rnrd_pro_fs();
		if ( false === $fs || ! method_exists( $fs, '_get_license' ) ) {
			return false;
		}
		$license = $fs->_get_license();
		if ( ! $license || empty( $license->expiration ) ) {
			return false; // Lifetime licenses never expire, which is the point of them.
		}
		return strtotime( $license->expiration ) < time();
	}

	/**
	 * The registered, non-trial, non-cancelled Pro license, or null.
	 *
	 * Deliberately not gated on Freemius is_paying(): that requires a license
	 * that is not expired, so the moment a license lapsed this notice, whose
	 * whole job is to speak about a lapsed license, stopped being reachable.
	 */
	private static function renewal_license( $fs ) {
		if ( false === $fs || ! is_object( $fs ) || ! method_exists( $fs, 'is_registered' ) || ! $fs->is_registered() ) {
			return null;
		}
		if ( method_exists( $fs, 'is_trial' ) && $fs->is_trial() ) {
			return null;
		}
		$license = method_exists( $fs, '_get_license' ) ? $fs->_get_license() : null;
		if ( ! $license || empty( $license->expiration ) ) {
			return null; // Lifetime licenses have no expiration, and that is fine.
		}
		if ( method_exists( $license, 'is_active' ) && ! $license->is_active() ) {
			return null; // Cancelled or refunded: "renew" would be the wrong word.
		}
		return $license;
	}

	/**
	 * Which lapse a renewal dismissal belongs to: state plus expiration day.
	 * Empty when there is nothing to dismiss.
	 */
	private static function renewal_window(): string {
		if ( ! function_exists( 'rnrd_pro_fs' ) ) {
			return '';
		}
		$license = self::renewal_license( rnrd_pro_fs() );
		if ( ! $license ) {
			return '';
		}
		$exp = strtotime( $license->expiration );
		return ( $exp < time() ? 'expired-' : 'soon-' ) . gmdate( 'Ymd', $exp );
	}

	/**
	 * License expiring or expired. Pro only, and only when Freemius says so.
	 */
	private static function notice_renewal(): bool {
		if ( ! function_exists( 'rnrd_pro_fs' ) ) {
			return false;
		}
		$fs      = rnrd_pro_fs();
		$license = self::renewal_license( $fs );
		if ( ! $license ) {
			return false;
		}
		if ( 'dismissed@' . self::renewal_window() === self::state( 'renewal' ) ) {
			return false;
		}

		$days = (int) floor( ( strtotime( $license->expiration ) - time() ) / DAY_IN_SECONDS );
		if ( $days > 14 ) {
			return false;
		}

		$expired = ( $days < 0 );
		$body    = $expired
			/*
			 * The heading above this body already says "Pro features are off",
			 * so the body must not spend its first clause saying it again. What
			 * the reader still does not know is what happened to their work and
			 * what they can still do, so the body carries only that.
			 */
			? __( 'Everything you already generated stays exactly as it is, and generating by hand is still unlimited. Pro stops getting updates until you renew.', 'rankready-ai-llm-seo' )
			: sprintf(
				/* translators: %d: number of days */
				_n(
					'Your Pro license covers this site for %d more day. When it lapses, the Pro features switch off, everything you have already generated stays exactly as it is, and generating by hand stays unlimited.',
					'Your Pro license covers this site for %d more days. When it lapses, the Pro features switch off, everything you have already generated stays exactly as it is, and generating by hand stays unlimited.',
					max( 0, $days ),
					'rankready-ai-llm-seo'
				),
				max( 0, $days )
			);

		self::box(
			'renewal',
			$expired ? 'error' : 'warning',
			$expired ? __( 'Pro features are off', 'rankready-ai-llm-seo' ) : __( 'Your Pro license expires soon', 'rankready-ai-llm-seo' ),
			$body,
			method_exists( $fs, 'get_account_url' ) ? $fs->get_account_url() : rnrd_upgrade_url( 'renewal' ),
			$expired ? __( 'Renew license', 'rankready-ai-llm-seo' ) : __( 'Manage license', 'rankready-ai-llm-seo' ),
			false // No "later" on money. Dismiss only.
		);
		return true;
	}

	/**
	 * Review request. Earned, not begged: only after real use.
	 */
	private static function notice_review(): bool {
		if ( self::installed_days() < self::REVIEW_MIN_AGE_DAYS ) {
			return false;
		}
		$generated = self::generated_count();
		if ( $generated < self::REVIEW_MIN_GENERATIONS ) {
			return false;
		}

		self::box(
			'review',
			'info',
			__( 'Has RankReady been useful?', 'rankready-ai-llm-seo' ),
			sprintf(
				/* translators: %d: number of items generated */
				_n(
					'You have generated %d item on this site so far. If it saved you time, a short review on WordPress.org helps the next person decide whether to try it.',
					'You have generated %d items on this site so far. If it saved you time, a short review on WordPress.org helps the next person decide whether to try it.',
					$generated,
					'rankready-ai-llm-seo'
				),
				$generated
			),
			'https://wordpress.org/support/plugin/rankready-ai-llm-seo/reviews/#new-post',
			__( 'Leave a review', 'rankready-ai-llm-seo' ),
			true
		);
		return true;
	}

	/**
	 * Whether the review notice is asking right now: its own earn-it gates pass
	 * and it has not been dismissed or snoozed.
	 *
	 * This exists for the sidebar. The dashboard aside carries a permanent
	 * "Loving RankReady so far?" star card, and the review notice is a second
	 * ask for exactly the same thing, so on an aged install with real usage the
	 * screen ran both at once: a full-width notice at the top asking for a
	 * WordPress.org review, and a star widget in the sidebar asking for a
	 * WordPress.org review. Two asks do not double the chance of a review, they
	 * halve the credibility of both, and the notice is the stronger of the two
	 * because it says what the plugin actually did for you and how many times.
	 * So the notice wins while it is up, and the card comes back the moment it
	 * is dismissed or snoozed.
	 */
	public static function review_ask_active(): bool {
		if ( self::hidden( 'review' ) ) {
			return false;
		}
		if ( self::installed_days() < self::REVIEW_MIN_AGE_DAYS ) {
			return false;
		}
		return self::generated_count() >= self::REVIEW_MIN_GENERATIONS;
	}

	/**
	 * Pro exists. Shown to free users only, once they have used the plugin.
	 */
	private static function notice_pro(): bool {
		if ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) {
			return false;
		}
		if ( self::installed_days() < self::MIN_AGE_DAYS ) {
			return false;
		}

		self::box(
			'pro',
			'info',
			__( 'What RankReady Pro adds', 'rankready-ai-llm-seo' ),
			__( 'Everything you generate by hand stays free and uncapped. Pro is for the work you would not do post by post: bulk generation across the whole archive, generate on publish, custom post types, and the headless API.', 'rankready-ai-llm-seo' ),
			rnrd_upgrade_url( 'admin-notice' ),
			__( 'See Pro, $49 once', 'rankready-ai-llm-seo' ),
			true
		);
		return true;
	}

	/* ── rendering ────────────────────────────────────────────────────────── */

	/**
	 * The notice itself.
	 *
	 * Deliberately NOT a `notice notice-info` div. That core class is what draws
	 * the coloured bar down the left edge, and a stack of those bars is the look
	 * Aditya has ruled out. `.rnrd-notice` opts out of the core styling entirely
	 * and draws a plain card: full hairline border, its own icon, no accent rail.
	 *
	 * Inline styles are gone too. They were unthemeable and could not respond to
	 * width; everything lives in admin.css now.
	 */
	private static function box( string $key, string $type, string $title, string $body, string $cta_url, string $cta_text, bool $allow_later ): void {
		// An icon per kind, so the sort of message is legible before the words.
		$icons = array(
			'error'   => 'dashicons-warning',
			'warning' => 'dashicons-clock',
			'info'    => 'dashicons-star-filled',
		);
		$icon = $icons[ $type ] ?? 'dashicons-info';

		// External links open in a new tab; admin links must not.
		$is_external = ( 0 === strpos( $cta_url, 'http' ) && false === strpos( $cta_url, admin_url() ) );
		?>
		<div class="rnrd-notice rnrd-notice--<?php echo esc_attr( $type ); ?>">
			<span class="rnrd-notice__icon dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<div class="rnrd-notice__body">
				<p class="rnrd-notice__title"><?php echo esc_html( $title ); ?></p>
				<p class="rnrd-notice__text"><?php echo esc_html( $body ); ?></p>
				<p class="rnrd-notice__actions">
					<a class="rnrd-notice__cta" href="<?php echo esc_url( $cta_url ); ?>"
						<?php echo $is_external ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
						<?php echo esc_html( $cta_text ); ?>
						<?php if ( $is_external ) : ?>
							<span class="dashicons dashicons-external rnrd-extlink" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'rankready-ai-llm-seo' ); ?></span>
						<?php endif; ?>
					</a>
					<?php if ( $allow_later ) : ?>
						<a class="rnrd-notice__link" href="<?php echo esc_url( self::action_url( $key, 'later' ) ); ?>">
							<?php esc_html_e( 'Remind me later', 'rankready-ai-llm-seo' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</div>
			<?php // Dismiss is an X in the corner, where every dismissible thing keeps it. ?>
			<a class="rnrd-notice__x" href="<?php echo esc_url( self::action_url( $key, 'dismiss' ) ); ?>">
				<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice', 'rankready-ai-llm-seo' ); ?></span>
			</a>
		</div>
		<?php
	}
}
