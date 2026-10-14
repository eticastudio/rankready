<?php
/**
 * FREE-102 — file-level phpcs ignore for the custom Plugin Check sniff
 * `PluginCheck.Security.DirectDB.UnescapedDBParameter`. Every direct query
 * in this file targets our own plugin-owned table (`$wpdb->prefix . 'rnrd_crawler_log'`)
 * with $table built only from constants — there is no user input in any
 * table-name interpolation. The WordPress.DB.* sniffs are already disabled
 * around each query block; this pragma silences the parallel PCP sniff.
 *
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 */
/**
 * AI Crawler Access Log — tracks bot hits to llms.txt, .md endpoints,
 * resolves each hit to a WordPress post/CPT, and surfaces organized stats
 * in the AI Crawlers admin tab.
 *
 * DB schema v3 removes the user_agent column (redundant — bot_name captures
 * the identity in human-readable form) and enforces a hard row cap (50 000)
 * plus a 30-day retention window so the table never clutters the database.
 *
 * Migration path:
 *   v1 → v2: dbDelta adds post_id, post_type, post_title.
 *   v2 → v3: dbDelta is a no-op (no new columns); ALTER TABLE drops user_agent.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Crawler_Log {

	const DB_VERSION_KEY = 'rnrd_crawler_log_db_version';
	const DB_VERSION     = 3;
	const RETENTION_DAYS = 30;   // rows older than 30 days are pruned daily
	const MAX_ROWS       = 50000; // hard cap — oldest rows deleted when exceeded

	// ── Known bots (most-specific first) ─────────────────────────────────

	const BOTS = array(
		'GPTBot'               => 'GPTBot (OpenAI)',
		'ChatGPT-User'         => 'ChatGPT-User (OpenAI)',
		'OAI-SearchBot'        => 'OAI-SearchBot (OpenAI)',
		'ClaudeBot'            => 'ClaudeBot (Anthropic)',
		'Claude-Web'           => 'Claude-Web (Anthropic)',
		'anthropic-ai'         => 'Anthropic AI',
		'Google-Extended'      => 'Google-Extended (Gemini training)',
		'PerplexityBot'        => 'PerplexityBot',
		'cohere-ai'            => 'Cohere AI',
		'AI2Bot'               => 'AI2Bot (Allen Institute)',
		'Bytespider'           => 'Bytespider (ByteDance)',
		'FacebookBot'          => 'FacebookBot (Meta)',
		'Meta-ExternalAgent'   => 'Meta-ExternalAgent',
		'Meta-ExternalFetcher' => 'Meta-ExternalFetcher',
		'YouBot'               => 'YouBot (You.com)',
		'DuckAssistBot'        => 'DuckAssistBot (DuckDuckGo)',
		'Diffbot'              => 'Diffbot',
		'Applebot-Extended'    => 'Applebot-Extended (Apple)',
		'Applebot'             => 'Applebot (Apple)',
		'CCBot'                => 'CCBot (Common Crawl)',
		'omgili'               => 'Omgili',
		'Timpibot'             => 'Timpibot',
		'ImagesiftBot'         => 'ImagesiftBot',
		'magpie-crawler'       => 'Magpie (Brave)',
		'Amazonbot'            => 'Amazonbot (Amazon)',
	);

	const ENDPOINT_LABELS = array(
		'llms_txt'  => 'llms.txt',
		'llms_full' => 'llms-full.txt',
		'markdown'  => '.md URL',
		'home_md'   => 'Homepage .md',
	);

	// ── Bot intent classification (v1.2.0-beta.3) ────────────────────────
	// Citation-intent bots fetch on behalf of a real user query happening NOW.
	// A hit from one of these bots is the strongest in-product signal that the
	// page is being fetched for an AI response. A fetch is not proof the answer
	// quoted or linked the page.
	const BOTS_CITATION = array(
		'OAI-SearchBot',  // OpenAI search engine bot (ChatGPT Search)
		'ChatGPT-User',   // ChatGPT browsing on behalf of a user
		'PerplexityBot',  // Perplexity main bot
		'Claude-Web',     // Claude web fetcher for live queries
		'DuckAssistBot',  // DuckDuckGo AI assistant
	);

	// Training-intent bots ingest content for future model training. No
	// immediate citation, but content may surface from model weights later.
	const BOTS_TRAINING = array(
		'GPTBot',             // OpenAI training crawler
		'ClaudeBot',          // Anthropic training crawler
		'anthropic-ai',       // older Anthropic training UA
		'Google-Extended',    // Gemini training opt-out token
		'Bytespider',         // ByteDance / Doubao training
		'CCBot',              // Common Crawl (used by many AI labs)
		'Amazonbot',          // Amazon training crawler
		'cohere-ai',          // Cohere training
		'AI2Bot',             // Allen Institute training
		'Applebot-Extended',  // Apple AI training opt-out token
		'Meta-ExternalAgent', // Meta AI training crawler
	);

	/**
	 * Classify a bot UA pattern into 'citation' | 'training' | 'indexing' | 'unknown'.
	 *
	 * Accepts either the raw bot pattern ('GPTBot') or the friendly label
	 * stored in the DB ('GPTBot (OpenAI)'). Substring match.
	 */
	public static function bot_intent( string $bot ): string {
		foreach ( self::BOTS_CITATION as $pattern ) {
			if ( false !== stripos( $bot, $pattern ) ) {
				return 'citation';
			}
		}
		foreach ( self::BOTS_TRAINING as $pattern ) {
			if ( false !== stripos( $bot, $pattern ) ) {
				return 'training';
			}
		}
		// Anything else that's in BOTS but not classified above is indexing.
		foreach ( array_keys( self::BOTS ) as $pattern ) {
			if ( false !== stripos( $bot, $pattern ) ) {
				return 'indexing';
			}
		}
		return 'unknown';
	}

	/**
	 * Whether logging is enabled for the given bot intent.
	 *
	 * Only training and citation are user-toggleable from Insights. All other
	 * intents stay on so generic/indexing diagnostics continue to work.
	 */
	public static function is_tracking_enabled_for_intent( string $intent ): bool {
		if ( 'training' === $intent ) {
			return 'on' === get_option( RNRD_OPT_AI_TRAINING_ENABLE, 'on' );
		}
		if ( 'citation' === $intent ) {
			return 'on' === get_option( RNRD_OPT_AI_CITATION_ENABLE, 'on' );
		}
		return true;
	}

	/**
	 * Whether RankReady is serving at least one AI surface that crawler
	 * logging can observe (llms.txt or Markdown).
	 */
	public static function has_loggable_endpoints(): bool {
		$llms_on = defined( 'RNRD_OPT_LLMS_ENABLE' ) && 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$md_on   = defined( 'RNRD_OPT_MD_ENABLE' ) && 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' );
		return $llms_on || $md_on;
	}

	/**
	 * Build a SQL fragment matching any citation-intent bot. Returns the
	 * fragment + the corresponding params array, ready to splice into a
	 * $wpdb->prepare() call. Returns null when the list is empty (defensive).
	 *
	 * Output: [ 'fragment' => '(bot_name LIKE %s OR bot_name LIKE %s ...)', 'params' => [...] ]
	 */
	private static function citation_bot_sql(): ?array {
		if ( empty( self::BOTS_CITATION ) ) {
			return null;
		}
		$frags  = array();
		$params = array();
		foreach ( self::BOTS_CITATION as $pattern ) {
			$frags[]  = 'bot_name LIKE %s';
			$params[] = '%' . $GLOBALS['wpdb']->esc_like( $pattern ) . '%';
		}
		return array(
			'fragment' => '(' . implode( ' OR ', $frags ) . ')',
			'params'   => $params,
		);
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────

	public static function init(): void {
		$stored = (int) get_option( self::DB_VERSION_KEY, 0 );

		if ( $stored < self::DB_VERSION ) {
			self::create_table();

			// v2 → v3: drop the redundant user_agent column if it still exists.
			// dbDelta cannot remove columns, so we use ALTER TABLE explicitly.
			if ( $stored < 3 ) {
				self::drop_user_agent_column();
			}
		}

		// Defer scheduling to init — wp_schedule_event() calls wp_get_schedules(),
		// which must not trigger translated cron labels before init (WP 6.7+).
		add_action( 'init', array( self::class, 'maybe_schedule_prune' ), 10 );
		add_action( 'rnrd_crawler_log_prune', array( self::class, 'prune' ) );
		add_action( 'admin_init', array( self::class, 'add_privacy_policy_content' ) );
	}

	/** Schedule the daily prune cron once, after init. */
	public static function maybe_schedule_prune(): void {
		if ( ! wp_next_scheduled( 'rnrd_crawler_log_prune' ) ) {
			wp_schedule_event( time(), 'daily', 'rnrd_crawler_log_prune' );
		}
	}

	/**
	 * Suggested text for Settings > Privacy: what the crawler log keeps, plus the external
	 * services RankReady can contact (the chosen AI provider, DataForSEO, Cloudflare, the tips
	 * email and the Freemius opt-in), mirrored from the readme External services section.
	 * Nothing the log stores identifies a visitor, so no exporter or eraser is registered:
	 * there is no personal data for one to find.
	 */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<h2>' . esc_html__( 'AI crawler activity', 'rankready-ai-llm-seo' ) . '</h2>'
			. '<p>' . esc_html__( 'RankReady records when a known AI crawler requests a page, llms.txt or a Markdown version: the crawler name, the page path, the endpoint and the time. It does not store IP addresses, user agents, cookies or any information about human visitors. Entries are pruned automatically.', 'rankready-ai-llm-seo' ) . '</p>'
			. '<h2>' . esc_html__( 'AI referral counts', 'rankready-ai-llm-seo' ) . '</h2>'
			. '<p>' . esc_html__( 'RankReady counts visits that arrive from AI assistants such as ChatGPT, Perplexity, Claude and Gemini as totals by source. These counts are anonymous and are not linked to a person.', 'rankready-ai-llm-seo' ) . '</p>'
			. '<h2>' . esc_html__( 'AI providers', 'rankready-ai-llm-seo' ) . '</h2>'
			. '<p>' . esc_html__( 'When a site administrator or editor clicks Generate for an AI summary or FAQ, RankReady sends that post\'s title and text to the AI provider the site has chosen (OpenAI, Anthropic Claude, Google Gemini or DeepSeek) using the site\'s own API key, and saves the reply on the site. No visitor data is sent. The provider\'s own privacy policy applies to what it receives.', 'rankready-ai-llm-seo' ) . '</p>'
			. '<h2>' . esc_html__( 'Optional services', 'rankready-ai-llm-seo' ) . '</h2>'
			. '<p>' . esc_html__( 'These only run if the site owner sets them up. DataForSEO: when a FAQ is generated with a DataForSEO login saved, the post\'s focus keyword (never the article text) is sent to api.dataforseo.com. Cloudflare: if an API token is saved, RankReady sends the zone ID and token to api.cloudflare.com to add or remove one cache rule and to clear cached pages; no post content is sent. Tips email: if an administrator ticks the opt-in box, their first name and email address are sent once to webhook.hostmy.blog.', 'rankready-ai-llm-seo' ) . '</p>'
			. '<h2>' . esc_html__( 'Plugin usage data', 'rankready-ai-llm-seo' ) . '</h2>'
			. '<p>' . esc_html__( 'If an administrator accepts the optional opt-in from Freemius, usage data is shared with Freemius (api.freemius.com): the site URL, WordPress and PHP versions, the administrator\'s name and email, active plugins and theme, and activation events. It is never post content, API keys or visitor data, and it can be turned off at any time from the Plugins page.', 'rankready-ai-llm-seo' ) . '</p>';
		wp_add_privacy_policy_content( 'RankReady', wp_kses_post( $text ) );
	}

	// ── Table management ───────────────────────────────────────────────────

	public static function create_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'rnrd_crawler_log';
		$charset = $wpdb->get_charset_collate();

		// user_agent is intentionally absent (removed in v3 — redundant storage).
		// dbDelta is idempotent: safe to run on fresh installs and existing tables.
		$sql = "CREATE TABLE {$table} (
			id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			logged_at  datetime     NOT NULL,
			bot_name   varchar(120) NOT NULL DEFAULT '',
			url_path   varchar(500) NOT NULL DEFAULT '',
			endpoint   varchar(20)  NOT NULL DEFAULT '',
			post_id    bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type  varchar(50)  NOT NULL DEFAULT '',
			post_title varchar(250) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY idx_bot       (bot_name(60)),
			KEY idx_date      (logged_at),
			KEY idx_endpoint  (endpoint),
			KEY idx_post_type (post_type(30))
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Only record the schema version if the table actually exists. Bumping it
		// unconditionally meant a failed CREATE (no CREATE privilege on locked-down
		// managed MySQL, disk full, quota) was permanent: init() only calls this
		// when stored < DB_VERSION, so it never retried, every insert silently
		// errored, and AI Crawler Insights showed "0 visits" forever — which reads
		// as "no bots have visited yet" rather than "logging is broken".
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $exists ) {
			update_option( self::DB_VERSION_KEY, self::DB_VERSION );
			return;
		}

		if ( class_exists( 'RNRD_Generator' ) ) {
			RNRD_Generator::log_error(
				'CrawlerLog',
				'Could not create the crawler-log table (' . $table . '). AI crawler visits are not being recorded. '
					. ( $wpdb->last_error ? 'MySQL: ' . $wpdb->last_error : 'No MySQL error reported — check DB user CREATE privilege and disk space.' )
			);
		}
	}

	/**
	 * v2 → v3 migration: drop user_agent if it exists.
	 * Runs once via init() when upgrading from a v2 install.
	 */
	private static function drop_user_agent_column(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'user_agent'" );
		if ( ! empty( $exists ) ) {
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN user_agent" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function drop_table(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'rnrd_crawler_log' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		delete_option( self::DB_VERSION_KEY );
	}

	// ── Bot detection ──────────────────────────────────────────────────────

	public static function detect_bot(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		if ( empty( $ua ) ) {
			return '';
		}
		foreach ( self::BOTS as $pattern => $label ) {
			if ( false !== stripos( $ua, $pattern ) ) {
				return $label;
			}
		}
		return '';
	}

	// ── Logging ────────────────────────────────────────────────────────────

	/**
	 * Record one bot hit. Pass the resolved WP_Post so we store the exact
	 * piece of content the crawler read — title, ID, and post type (CPT).
	 *
	 * @param string        $endpoint  'llms_txt' | 'llms_full' | 'markdown' | 'home_md'
	 * @param WP_Post|null  $post      Resolved post object (null for llms.txt / homepage).
	 */
	// Bot logging fires on template_redirect when RankReady serves one of its AI
	// endpoints (llms.txt / *.md / mcp.json). Those endpoints are excluded from every
	// supported page cache (WP Rocket / W3TC / WP Super Cache / LiteSpeed reject-URI +
	// DONOTCACHEPAGE), so they always reach PHP and ARE logged even when a full-page
	// cache is active — verified against WP Super Cache. Regular (cached) pages are not
	// logged by design; only the always-uncached AI endpoints are the bot-visit signal.
	public static function log( string $endpoint, ?WP_Post $post = null ): void {
		$bot = self::detect_bot();
		if ( '' === $bot ) {
			return;
		}
		$intent = self::bot_intent( $bot );
		if ( ! self::is_tracking_enabled_for_intent( $intent ) ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		// v1.1.5 (#9) — real-time write throttle. Bot detection is User-Agent-substring
		// based and therefore spoofable, so a single client replaying a request with a bot
		// UA could insert unbounded rows between the once-daily prune(). Collapse repeated
		// identical hits (same IP + bot + path) to one row per 5 minutes — this kills the
		// flood vector while still logging genuine crawls of distinct pages.
		$rnrd_cl_ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rnrd_cl_path = explode( '?', $uri, 2 )[0]; // TC-SEC-03: throttle on PATH only — a spoofed bot cannot bypass the 5-min collapse by varying the query string.
		$rnrd_cl_key  = 'rnrd_cl_' . md5( $rnrd_cl_ip . '|' . $bot . '|' . $rnrd_cl_path );
		// MAX_ROWS used to be enforced ONLY by prune(), which runs once a day on
		// cron. Between two prunes the write path had no cap at all: the 5-minute
		// transient collapse below is keyed on IP + bot + path, so a client
		// replaying a spoofed bot User-Agent across distinct paths (or from
		// distinct addresses) inserts a new row per key per 5 minutes, unbounded,
		// on a crawler-reachable public endpoint. The cap is a safety net, so it
		// belongs on the write, not on a timer that may be a day away.
		// It runs before the throttle transient too: at the cap, each distinct
		// IP + bot + path would otherwise still write one wp_options row.
		if ( ! self::write_within_row_cap() ) {
			return;
		}
		if ( get_transient( $rnrd_cl_key ) ) {
			return;
		}
		set_transient( $rnrd_cl_key, 1, 5 * MINUTE_IN_SECONDS );

		// Resolve post metadata.
		$post_id    = 0;
		$post_type  = '';
		$post_title = '';

		if ( $post instanceof WP_Post ) {
			$post_id    = (int) $post->ID;
			$post_type  = $post->post_type;
			$post_title = $post->post_title;
		} elseif ( 'home_md' === $endpoint ) {
			$post_type  = 'homepage';
			$post_title = get_bloginfo( 'name' );
		}
		// llms_txt / llms_full: virtual files, no post — fields stay empty.

		global $wpdb;
		// Direct insert into our own custom crawler-log table — no WP-API equivalent;
		// caching is not appropriate for a write operation.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->prefix . 'rnrd_crawler_log',
			array(
				'logged_at'  => current_time( 'mysql' ),
				'bot_name'   => $bot,
				'url_path'   => substr( $uri, 0, 500 ),
				'endpoint'   => $endpoint,
				'post_id'    => $post_id,
				'post_type'  => $post_type,
				'post_title' => substr( $post_title, 0, 250 ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		self::note_row_written();
	}

	/**
	 * Transient holding the cached row-count estimate, and how long it is trusted.
	 *
	 * COUNT(*) on this table is a full index scan, and the point of the cap is to
	 * stop a flood, not to keep an exact ledger. So the count is measured for real
	 * at most once a minute and adjusted in between. It can drift low under
	 * concurrency, because a transient write is not atomic, which is why the cap
	 * is checked against a headroom allowance rather than the exact limit.
	 */
	private const ROWS_ESTIMATE_KEY = 'rnrd_cl_rows';
	private const ROWS_ESTIMATE_TTL = 60;

	/** Option name of the claim that serialises an inline cap prune. */
	private const CAP_PRUNE_CLAIM = 'rnrd_cl_cap_prune';

	/**
	 * May this request add a row, or is the table at its hard cap?
	 *
	 * @return bool True to insert, false to drop this hit on the floor.
	 */
	private static function write_within_row_cap(): bool {
		global $wpdb;

		$rows = get_transient( self::ROWS_ESTIMATE_KEY );

		if ( false === $rows ) {
			$table = $wpdb->prefix . 'rnrd_crawler_log';
			// Own table with no API equivalent; the transient below is the cache.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery
			set_transient( self::ROWS_ESTIMATE_KEY, $rows, self::ROWS_ESTIMATE_TTL );
		}

		if ( (int) $rows < self::MAX_ROWS ) {
			return true;
		}

		// At the cap. Prune inline so logging resumes on its own, but let exactly
		// one request do it: claim_option() fails for everyone else because
		// option_name is UNIQUE, so a flood does not become a flood of DELETEs.
		if ( RNRD_Atomic::claim_option( self::CAP_PRUNE_CLAIM, (string) time() ) ) {
			self::prune();
			delete_transient( self::ROWS_ESTIMATE_KEY );
			delete_option( self::CAP_PRUNE_CLAIM );
			return true;
		}

		// Someone else is pruning. Drop this hit rather than queue behind them:
		// this runs on a public request that is serving a crawler a real page, and
		// one missing analytics row matters less than that response.
		$claimed = (int) get_option( self::CAP_PRUNE_CLAIM, 0 );
		if ( $claimed > 0 && ( time() - $claimed ) > self::ROWS_ESTIMATE_TTL ) {
			// Claim outlived any plausible prune: the request holding it died.
			delete_option( self::CAP_PRUNE_CLAIM );
		}

		return false;
	}

	/**
	 * Adjust the cached row estimate after a successful insert.
	 *
	 * Deliberately not read-modify-write-correct: see ROWS_ESTIMATE_KEY. The real
	 * COUNT(*) reclaims any drift within ROWS_ESTIMATE_TTL seconds.
	 */
	private static function note_row_written(): void {
		$rows = get_transient( self::ROWS_ESTIMATE_KEY );
		if ( false !== $rows ) {
			set_transient( self::ROWS_ESTIMATE_KEY, (int) $rows + 1, self::ROWS_ESTIMATE_TTL );
		}
	}

	// ── Stat queries ───────────────────────────────────────────────────────

	/**
	 * Per-bot totals with endpoint breakdown and unique page count.
	 */
	public static function get_bot_stats( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					bot_name,
					COUNT(*)                             AS total,
					MAX(logged_at)                       AS last_seen,
					SUM(endpoint = 'llms_txt')           AS llms_txt,
					SUM(endpoint = 'llms_full')          AS llms_full,
					SUM(endpoint = 'markdown')           AS markdown,
					SUM(endpoint = 'home_md')            AS home_md,
					COUNT(DISTINCT NULLIF(post_id, 0))   AS unique_pages
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 GROUP BY bot_name
				 ORDER BY total DESC",
				$days
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Top 5 pages/posts a specific bot accessed most — used for per-bot expand.
	 */
	public static function get_bot_top_pages( string $bot_name, int $days = 30, int $limit = 5 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, post_type, post_title, url_path, COUNT(*) AS total
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				   AND bot_name = %s
				   AND (post_id > 0 OR post_type IN ('homepage'))
				 GROUP BY post_id, post_type, post_title
				 ORDER BY total DESC
				 LIMIT %d",
				$days,
				$bot_name,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Hits grouped by WordPress post type (CPT breakdown).
	 */
	public static function get_cpt_stats( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_type, COUNT(*) AS total, COUNT(DISTINCT post_id) AS unique_posts
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				   AND post_type != ''
				 GROUP BY post_type
				 ORDER BY total DESC",
				$days
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Top individual pages/posts with which bots read them.
	 */
	public static function get_top_pages( int $days = 30, int $limit = 15 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					post_id,
					post_type,
					post_title,
					url_path,
					COUNT(*)                           AS total,
					COUNT(DISTINCT bot_name)           AS unique_bots,
					GROUP_CONCAT(
						DISTINCT bot_name
						ORDER BY bot_name
						SEPARATOR '|'
					)                                  AS bots_csv
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				   AND (post_id > 0 OR post_type = 'homepage')
				 GROUP BY post_id, post_type, post_title
				 ORDER BY total DESC
				 LIMIT %d",
				$days,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Endpoint-level totals (llms.txt vs markdown etc.) — for summary strip.
	 */
	public static function get_endpoint_totals( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT endpoint, COUNT(*) AS total
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 GROUP BY endpoint",
				$days
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array( 'llms_txt' => 0, 'llms_full' => 0, 'markdown' => 0, 'home_md' => 0 );
		foreach ( $rows as $r ) {
			if ( isset( $out[ $r['endpoint'] ] ) ) {
				$out[ $r['endpoint'] ] = (int) $r['total'];
			}
		}
		return $out;
	}

	/**
	 * Recent individual hits for the live log table.
	 */
	public static function get_recent_hits( int $limit = 40 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT logged_at, bot_name, endpoint, post_type, post_title, url_path
				 FROM {$table}
				 ORDER BY logged_at DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Total hits — used for dashboard stat card. */
	public static function get_total( int $days = 30 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count hits in the last N days from citation-intent bots only.
	 *
	 * This is the most useful crawl number to surface in the UI: a hit from a
	 * citation-intent bot means a live AI query was in flight when the page
	 * was fetched. It does NOT mean the answer used or quoted the page, and
	 * nothing here can observe whether it did. The earlier note claimed a
	 * rough 1:1 with answer citations, which is what produced the UI strings
	 * that had to be walked back. Keep the distinction: fetch, not quote.
	 *
	 * @since 1.2.0-beta.3
	 */
	public static function get_citation_hits_total( int $days = 30 ): int {
		$cite = self::citation_bot_sql();
		if ( null === $cite ) {
			return 0;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$sql = "SELECT COUNT(*) FROM {$table}
		        WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
		          AND {$cite['fragment']}";
		$args = array_merge( array( $days ), $cite['params'] );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Sum of hits in last N days from training-intent bots. Complement to
	 * get_citation_hits_total() — together with that they give the
	 * "is my content being fetched to answer a live query right now, or
	 * crawled to train a model for later" split. Not a citation split: a
	 * citation-intent hit is a fetch during a live query, never proof the
	 * answer quoted the page. This comment previously said "being cited",
	 * and two UI strings were written from it.
	 *
	 * @since 1.2.0-beta.3
	 */
	public static function get_training_hits_total( int $days = 30 ): int {
		if ( empty( self::BOTS_TRAINING ) ) {
			return 0;
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'rnrd_crawler_log';
		$frags  = array();
		$params = array( $days );
		foreach ( self::BOTS_TRAINING as $pattern ) {
			$frags[]  = 'bot_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $pattern ) . '%';
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$sql = "SELECT COUNT(*) FROM {$table}
		        WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
		          AND (" . implode( ' OR ', $frags ) . ')';
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Top pages crawled by citation-intent bots in the last N days.
	 *
	 * The single most actionable view of the crawl log: these are the posts
	 * AI engines actually pull as sources when answering live user queries.
	 * Optimise these posts first — they're already winning.
	 *
	 * @since 1.2.0-beta.3
	 * @return array<int,array{post_id:int,post_title:string,post_type:string,url_path:string,hits:int,unique_bots:int,last_seen:string}>
	 */
	public static function get_citation_top_pages( int $days = 30, int $limit = 10 ): array {
		$cite = self::citation_bot_sql();
		if ( null === $cite ) {
			return array();
		}
		global $wpdb;
		$table = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$sql = "SELECT
		            post_id,
		            MAX(post_title) AS post_title,
		            MAX(post_type)  AS post_type,
		            MAX(url_path)   AS url_path,
		            COUNT(*)        AS hits,
		            COUNT(DISTINCT bot_name) AS unique_bots,
		            MAX(logged_at)  AS last_seen
		        FROM {$table}
		        WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
		          AND {$cite['fragment']}
		          AND (post_id > 0 OR url_path <> '')
		        GROUP BY post_id, url_path
		        ORDER BY hits DESC, last_seen DESC
		        LIMIT %d";
		$args = array_merge( array( $days ), $cite['params'], array( $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** Total unique pages crawled. */
	public static function get_unique_pages( int $days = 30 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY) AND post_id > 0",
				$days
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ── Maintenance ────────────────────────────────────────────────────────

	/**
	 * Daily pruning — two passes:
	 * 1. Delete rows older than RETENTION_DAYS (time-based expiry).
	 * 2. If total rows still exceed MAX_ROWS, delete the oldest excess rows
	 *    (hard cap against runaway crawlers on high-traffic sites).
	 */
	public static function prune(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rnrd_crawler_log';

		// Pass 1: time-based expiry.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE logged_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				self::RETENTION_DAYS
			)
		);

		// Pass 2: hard row cap — delete oldest rows beyond MAX_ROWS.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $total > self::MAX_ROWS ) {
			$excess = $total - self::MAX_ROWS;
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} ORDER BY logged_at ASC LIMIT %d",
					$excess
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Both passes just changed the row count, so the write-path estimate is
		// wrong. Leaving it would keep logging blocked for up to a minute after a
		// prune that made room.
		delete_transient( self::ROWS_ESTIMATE_KEY );
	}
}
