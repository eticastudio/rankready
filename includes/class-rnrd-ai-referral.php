<?php
/**
 * RankReady — AI Referral Traffic tracker.
 *
 * Catches visits where the HTTP Referer points to a known AI engine front-end
 * (chatgpt.com, perplexity.ai, gemini.google.com, claude.ai, copilot.microsoft.com)
 * and records them as a separate analytics source.
 *
 * Why this matters: AI engines that send click-through traffic do so from
 * their own UI, not from search-engine result pages. Regular analytics tools
 * lump these visits into "Direct" or "Referral" without identifying the AI
 * source. RankReady surfaces them as their own segment so users can see
 * which engines are actually driving traffic to their site.
 *
 * Implementation notes:
 *   - Server-side detection in init hook. No JS, no third-party API.
 *   - Aggregates daily counters in a single wp_options row (rolling 30 days).
 *   - Skips bots, admin requests, REST/AJAX/cron, and own-site referrers.
 *   - Honours DNT / GDPR by tracking only domain + date — no IP, no path.
 *
 * Storage shape (rnrd_ai_referral_stats option):
 *   [
 *     'YYYY-MM-DD' => [ 'chatgpt' => 5, 'perplexity' => 2, ... ],
 *     ...
 *   ]
 *
 * @package RankReady
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

class RNRD_AI_Referral {

	/**
	 * Known AI engine referrer hosts. Add entries here as new engines emerge.
	 * Matched via stripos so subdomains (e.g. "ios.chatgpt.com") count too.
	 */
	private const SOURCES = array(
		'chatgpt'    => array( 'chatgpt.com', 'chat.openai.com' ),
		'perplexity' => array( 'perplexity.ai', 'www.perplexity.ai' ),
		'gemini'     => array( 'gemini.google.com' ),
		'claude'     => array( 'claude.ai' ),
		'copilot'    => array( 'copilot.microsoft.com', 'm365.cloud.microsoft' ),
	);

	private const RETENTION_DAYS = 30;

	public static function init(): void {
		add_action( 'init', array( self::class, 'maybe_record_referral' ), 5 );
		// Note: dashboard widget registration handled by RNRD_Agent_Dashboard
		// — single consolidated "Agent Visibility" widget replaces the two
		// separate widgets shipped in beta.1.
	}

	/*
	 * render_widget() and its private source_colour() helper lived here and were
	 * deleted on 2026-09-26: ~55 lines of inline-styled markup with zero callers.
	 * The dashboard panel is rendered by RNRD_Agent_Dashboard, as the note in
	 * init() already said. The dead copy also carried the only string in the
	 * plugin that claimed "Each visit here is a real person", which its own bot
	 * filter (a User-Agent substring match) cannot support, so deleting the
	 * function removed a live-if-ever-reached honesty problem with it.
	 */


	/**
	 * Inspect the current request and, if its Referer is an AI engine,
	 * increment the daily counter for that engine.
	 */
	public static function maybe_record_referral(): void {
		// Master toggle — Insights → Real AI Referrals (default on).
		if ( 'on' !== get_option( RNRD_OPT_AI_REFERRAL_ENABLE, 'on' ) ) {
			return;
		}

		// v1.2.0-beta.4 — honour Sec-GPC and DNT (the docblock previously
		// claimed this without code support). Audit beta.3 #11.
		// FREE-102 — unslash + sanitize before reading per WP coding standards.
		$sec_gpc = isset( $_SERVER['HTTP_SEC_GPC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '';
		if ( '1' === $sec_gpc ) {
			return;
		}
		$dnt = isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '';
		if ( '1' === $dnt ) {
			return;
		}

		// Skip admin, REST, AJAX, cron, CLI — we only want public front-end hits.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Need a referer header.
		if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
			return;
		}

		$referer = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		$host    = wp_parse_url( $referer, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return;
		}
		$host = strtolower( $host );

		// Ignore self-referrals (internal navigation).
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $host === $site_host ) {
			return;
		}

		// Match against the AI source map.
		$source = self::resolve_source( $host );
		if ( null === $source ) {
			return;
		}

		// Skip bots — referrer spoofing exists but most bots don't send Referer
		// at all. This is a cheap defence-in-depth check.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua || self::looks_like_bot( $ua ) ) {
			return;
		}

		self::increment( $source );
	}

	/**
	 * Map a referer host to one of the known source keys. Returns null when
	 * the host doesn't match any AI engine.
	 */
	private static function resolve_source( string $host ): ?string {
		foreach ( self::SOURCES as $key => $hosts ) {
			foreach ( $hosts as $candidate ) {
				if ( $host === $candidate || str_ends_with( $host, '.' . $candidate ) ) {
					return $key;
				}
			}
		}
		return null;
	}

	private static function looks_like_bot( string $ua ): bool {
		static $patterns = array( 'bot', 'spider', 'crawler', 'scraper', 'curl', 'wget', 'python-requests', 'php-fpm' );
		$ua_lc = strtolower( $ua );
		foreach ( $patterns as $p ) {
			if ( false !== strpos( $ua_lc, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Increment today's counter for $source.
	 *
	 * Buffered to shutdown so many referrals inside one request cost one write
	 * per source per day, then persisted by flush_pending_increments().
	 *
	 * The docblock that used to sit here called this path "race-safe". It was
	 * not. It described an atomic wp_cache_incr() (which happened, but whose
	 * value NOTHING ever read back: aggregate_last_n_days() reads the option
	 * only) in front of a flush that was a plain
	 * get_option → modify → update_option. Two concurrent requests both read
	 * the same array and the second overwrote the first, on the highest-traffic
	 * public path in the plugin, feeding a screen sold as analytics. Counts were
	 * silently lost under exactly the concurrency the comment claimed to handle.
	 *
	 * The counters are now integers in their own option rows, incremented with a
	 * single atomic `UPDATE … SET option_value = option_value + N` (see
	 * bump_counter()), so no request can clobber another's. The old aggregate
	 * option is still read for historical data and never written again.
	 *
	 * The wp_cache_incr() call is gone rather than fixed: it was a second,
	 * separate store that no reader consulted, and a number nobody reads is not
	 * a safeguard. One source of truth, and it is the one the dashboard reads.
	 */
	private static $pending_increments = array();

	private static function increment( string $source ): void {
		$today = wp_date( 'Y-m-d' );
		$key   = $today . '|' . $source;

		// Buffer for the shutdown flush, so many referrals in one request cost
		// one atomic increment per source rather than one per referral.
		if ( ! isset( self::$pending_increments[ $key ] ) ) {
			self::$pending_increments[ $key ] = 0;
			// Register the shutdown flush only once.
			if ( 1 === count( self::$pending_increments ) ) {
				add_action( 'shutdown', array( self::class, 'flush_pending_increments' ), 1 );
			}
		}
		self::$pending_increments[ $key ]++;
	}

	/** Option-name prefix for the per-day, per-source integer counters. */
	const COUNTER_PREFIX = 'rnrd_ref_c_';

	/** Byte offset (1-indexed, for SQL SUBSTRING) of the date inside a counter name. */
	private const COUNTER_DATE_POS = 12;

	/**
	 * Counter option name for one day and one source, e.g.
	 * `rnrd_ref_c_20260926_chatgpt`. The date is fixed-width and comes first
	 * after the prefix so it sorts lexicographically, which is what makes the
	 * single-query prune in prune_counters() possible.
	 */
	private static function counter_name( string $date, string $source ): string {
		return self::COUNTER_PREFIX . str_replace( '-', '', $date ) . '_' . $source;
	}

	/**
	 * Add $by to one counter, atomically.
	 *
	 * `UPDATE … SET option_value = option_value + %d` is resolved by MySQL
	 * itself, so two concurrent requests produce two increments rather than one
	 * lost write. That is the whole point: this runs on a public page load and
	 * there can be as many of them at once as there is traffic.
	 *
	 * The row may not exist yet. RNRD_Atomic::claim_option() creates it, because
	 * it is a no-op when another request has created it first (option_name is
	 * UNIQUE), and the UPDATE is then retried. There is no window in which two
	 * requests can both believe they created it.
	 */
	private static function bump_counter( string $name, int $by ): void {
		// Was a hand-rolled atomic UPDATE here. Moved to RNRD_Atomic when the same
		// primitive was needed for the token and DataForSEO spend counters: two
		// copies of a concurrency fix is how one of them quietly stops matching
		// the other. This wrapper stays so the call sites read in this class's own
		// vocabulary.
		RNRD_Atomic::bump_option_int( $name, $by );
	}

	/**
	 * Shutdown flush. One atomic increment per source touched this request.
	 */
	public static function flush_pending_increments(): void {
		if ( empty( self::$pending_increments ) ) {
			return;
		}

		foreach ( self::$pending_increments as $key => $count ) {
			list( $date, $source ) = explode( '|', $key, 2 );
			if ( ! isset( self::SOURCES[ $source ] ) ) {
				continue;
			}
			self::bump_counter( self::counter_name( $date, $source ), (int) $count );
		}

		self::$pending_increments = array();
		self::prune_counters();
	}

	/**
	 * Delete counter rows older than RETENTION_DAYS.
	 *
	 * Once a day at most: the guard is a claim_option() claim, which is atomic,
	 * so a traffic spike cannot run the DELETE once per request.
	 */
	private static function prune_counters(): void {
		global $wpdb;

		$today = wp_date( 'Y-m-d' );
		$guard = 'rnrd_ref_pruned';
		$last  = (string) get_option( $guard, '' );
		if ( $last === $today ) {
			return;
		}
		if ( '' === $last ) {
			if ( ! RNRD_Atomic::claim_option( $guard, $today ) ) {
				return; // Another request claimed it.
			}
		} elseif ( ! update_option( $guard, $today, false ) ) {
			return;
		}

		$cutoff = gmdate( 'Ymd', (int) strtotime( '-' . self::RETENTION_DAYS . ' days', (int) strtotime( $today ) ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded maintenance DELETE, once a day.
		$stale = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				   AND SUBSTRING( option_name, %d, 8 ) < %s",
				$wpdb->esc_like( self::COUNTER_PREFIX ) . '%',
				self::COUNTER_DATE_POS,
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $stale as $name ) {
			delete_option( $name );
		}
	}

	/**
	 * Read every counter inside the window, plus whatever the pre-1.4.1
	 * aggregate option still holds.
	 *
	 * @return array<string, array<string, int>> date => source => count
	 */
	private static function read_counts( int $days ): array {
		global $wpdb;

		$out    = array();
		$cutoff = (int) strtotime( '-' . max( 1, $days ) . ' days' );

		// Historical data written by the old aggregate option. Still read, never
		// written again, so sites upgrading keep the numbers they already had.
		$legacy = get_option( RNRD_OPT_AI_REFERRAL_STATS, array() );
		if ( is_array( $legacy ) ) {
			foreach ( $legacy as $date => $by_source ) {
				if ( ! is_string( $date ) || strtotime( $date ) < $cutoff ) {
					continue;
				}
				foreach ( (array) $by_source as $source => $count ) {
					$out[ $date ][ $source ] = ( $out[ $date ][ $source ] ?? 0 ) + (int) $count;
				}
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one read of at most RETENTION_DAYS × SOURCES rows, for an admin screen.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				   AND SUBSTRING( option_name, %d, 8 ) >= %s",
				$wpdb->esc_like( self::COUNTER_PREFIX ) . '%',
				self::COUNTER_DATE_POS,
				gmdate( 'Ymd', $cutoff )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $row ) {
			$rest = substr( (string) $row['option_name'], strlen( self::COUNTER_PREFIX ) );
			if ( strlen( $rest ) < 10 ) {
				continue;
			}
			$ymd    = substr( $rest, 0, 8 );
			$source = substr( $rest, 9 );
			$date   = substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 ) . '-' . substr( $ymd, 6, 2 );
			if ( ! isset( self::SOURCES[ $source ] ) ) {
				continue;
			}
			$out[ $date ][ $source ] = ( $out[ $date ][ $source ] ?? 0 ) + (int) $row['option_value'];
		}

		return $out;
	}

	// ── Reporting ─────────────────────────────────────────────────────────

	/**
	 * Returns aggregate counts per source for the last N days.
	 *
	 *   [ 'chatgpt' => 47, 'perplexity' => 18, ... ]
	 */
	public static function aggregate_last_n_days( int $days = 30 ): array {
		$stats = self::read_counts( $days );
		$out   = array_fill_keys( array_keys( self::SOURCES ), 0 );

		$cutoff = strtotime( '-' . max( 1, $days ) . ' days' );
		foreach ( $stats as $date => $by_source ) {
			if ( strtotime( $date ) < $cutoff ) {
				continue;
			}
			foreach ( (array) $by_source as $source => $count ) {
				if ( isset( $out[ $source ] ) ) {
					$out[ $source ] += (int) $count;
				}
			}
		}

		arsort( $out );
		return $out;
	}

	/**
	 * Total visits across all AI sources for the period.
	 */
	public static function total_last_n_days( int $days = 30 ): int {
		return array_sum( self::aggregate_last_n_days( $days ) );
	}

	/**
	 * Human-readable label for a source key.
	 */
	public static function source_label( string $source ): string {
		$labels = array(
			'chatgpt'    => 'ChatGPT',
			'perplexity' => 'Perplexity',
			'gemini'     => 'Gemini',
			'claude'     => 'Claude',
			'copilot'    => 'Copilot',
		);
		return $labels[ $source ] ?? ucfirst( $source );
	}
}
