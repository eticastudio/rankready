<?php
/**
 * RankReady — Content Freshness UX.
 *
 * Three-tab WP dashboard widget that buckets every published post by how long
 * ago it was last modified: Stale, Going stale, Fresh.
 *
 * The boundary between those buckets is the Stale Threshold the user picks on
 * the same card. It used to be hardcoded at 60 days here while that control sat
 * a few pixels above showing "90 days (recommended)", so one card carried two
 * different thresholds and the dropdown moved the scan table without moving the
 * buckets underneath it. Every cutoff below now derives from the one value the
 * user chose, and the labels and empty states state that value rather than a
 * literal 60.
 *
 * Each tab shows the matching posts and a bulk "Refresh dateModified" action.
 * Refresh updates `post_modified` to the current time without touching content.
 * That is the user asserting they have checked the page and it is still current,
 * which is a claim only a person can make; the plugin has no way to know it and
 * must not imply otherwise. The docblock here used to say the new date "matches
 * the date the page was actually reviewed", which describes a review this code
 * never observed, so the card now says plainly what the button does and asks the
 * user to run it only on posts they have read. Bumping dates on content nobody
 * looked at is the fake-freshness pattern search engines discount, and the
 * feature should not read as an invitation to do it in bulk.
 *
 * A percentage "citation lift" figure attributed to two named SEO vendors used
 * to sit here. It was removed on 2026-09-26: no source anybody here has read,
 * the same class of claim deleted from customer-facing copy in 1.2.1. The
 * number is not repeated here on purpose, both so it cannot be copied back out
 * and because the build gate that now reads comments would trip on it. A
 * comment is not exempt from the rule: the next person to write copy reads it.
 *
 * No score, no email digest, no nudge popup. Just the widget.
 *
 * REST endpoints (admin-only):
 *   GET  /rankready/v1/freshness/list?bucket=stale|going_stale|fresh
 *   POST /rankready/v1/freshness/refresh   { post_ids: [int, ...] }
 *
 * @package RankReady
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Freshness {

	private const NS                 = 'rankready/v1';
	private const DEFAULT_STALE_DAYS = 90;
	private const MIN_STALE_DAYS     = 30;
	private const LIST_LIMIT         = 50;

	/**
	 * The stale cutoff, in days.
	 *
	 * Defaults to 90 because that is what the Stale Threshold control on the
	 * card shows as selected and what the `/freshness` scan route already
	 * defaults to. Floor of 30 matches the clamp the scan route applies, so the
	 * two surfaces cannot be asked for different windows.
	 *
	 * @param int|string|null $days Requested threshold, null for the default.
	 * @return int
	 */
	public static function stale_days( $days = null ): int {
		$days = ( null === $days || '' === $days ) ? self::DEFAULT_STALE_DAYS : (int) $days;
		return max( self::MIN_STALE_DAYS, $days );
	}

	/**
	 * Where "going stale" starts, in days.
	 *
	 * Half the stale window, which is what the shipped pair (60 stale / 30
	 * going stale) already was, so a site that picks 60 buckets exactly as it
	 * did before this changed.
	 *
	 * @param int $stale_days Stale cutoff in days.
	 * @return int
	 */
	public static function going_stale_days( int $stale_days ): int {
		return max( 7, (int) floor( $stale_days / 2 ) );
	}

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		// Note: dashboard widget registration handled by RNRD_Agent_Dashboard
		// — single consolidated "Agent Visibility" widget replaces the two
		// separate widgets shipped in beta.1.
	}

	// ── Dashboard widget panel (rendered inside the unified widget) ──────

	public static function render_widget(): void {
		$stale_days = self::stale_days();
		$going_days = self::going_stale_days( $stale_days );
		$counts     = self::bucket_counts( $stale_days );
		$nonce      = wp_create_nonce( 'wp_rest' );
		$api        = esc_url_raw( rest_url( self::NS ) );
		?>
		<div class="rnrd-freshness-widget" data-rnrd-api="<?php echo esc_attr( $api ); ?>" data-rnrd-nonce="<?php echo esc_attr( $nonce ); ?>" data-rnrd-stale-days="<?php echo esc_attr( (string) $stale_days ); ?>" data-rnrd-going-days="<?php echo esc_attr( (string) $going_days ); ?>">
			<?php // FREE-103 — unified .rnrd-kpi chrome so freshness buckets match every other Insights tab. ?>
			<div class="rnrd-kpi-row rnrd-fw-tabs" role="radiogroup" aria-label="<?php esc_attr_e( 'Freshness bucket filter', 'rankready-ai-llm-seo' ); ?>">
				<button type="button" class="rnrd-kpi rnrd-fw-tab" data-bucket="stale" role="radio" aria-checked="false">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Stale', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period" data-rnrd-period="stale"><?php
						/* translators: %d: stale threshold in days. */
						echo esc_html( sprintf( __( '%d+ days', 'rankready-ai-llm-seo' ), $stale_days ) );
					?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( (string) $counts['stale'] ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'Oldest last-modified dates. Refresh these first.', 'rankready-ai-llm-seo' ); ?></div>
				</button>
				<button type="button" class="rnrd-kpi rnrd-fw-tab" data-bucket="going_stale" role="radio" aria-checked="false">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Going stale', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period" data-rnrd-period="going_stale"><?php
						/* translators: 1: going-stale threshold in days, 2: last day before stale. */
						echo esc_html( sprintf( __( '%1$d–%2$d days', 'rankready-ai-llm-seo' ), $going_days, $stale_days - 1 ) );
					?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( (string) $counts['going_stale'] ); ?></div>
					<div class="rnrd-kpi__foot" data-rnrd-foot="going_stale"><?php
						/* translators: %d: stale threshold in days. */
						echo esc_html( sprintf( __( 'Approaching the %d-day mark. Plan a refresh.', 'rankready-ai-llm-seo' ), $stale_days ) );
					?></div>
				</button>
				<button type="button" class="rnrd-kpi rnrd-fw-tab" data-bucket="fresh" role="radio" aria-checked="false">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Fresh', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period" data-rnrd-period="fresh"><?php
						/* translators: %d: going-stale threshold in days. */
						echo esc_html( sprintf( __( 'under %d days', 'rankready-ai-llm-seo' ), $going_days ) );
					?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( (string) $counts['fresh'] ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'Presenting a current last-modified date to crawlers.', 'rankready-ai-llm-seo' ); ?></div>
				</button>
			</div>

			<div class="rnrd-fw-toolbar">
				<label class="rnrd-fw-toolbar__select">
					<input type="checkbox" class="rnrd-fw-select-all" />
					<?php esc_html_e( 'Select all', 'rankready-ai-llm-seo' ); ?>
				</label>
				<button type="button" class="button button-primary rnrd-fw-refresh" disabled>
					<?php
					/*
					 * The button named the schema.org property it ultimately
					 * moves, while the note directly beneath it explains the
					 * same action as "the last-modified date". Two vocabularies
					 * for one action on one screen, and the raw property name is
					 * the half no one but the author recognises. The note keeps
					 * the technical term so the connection is still there.
					 */
					esc_html_e( 'Refresh last-modified date', 'rankready-ai-llm-seo' );
					?>
				</button>
			</div>

			<p class="description rnrd-fw-note"><?php esc_html_e( 'This sets the last-modified date (schema dateModified) to now. It does not change the content, so use it on posts you have read and found still accurate.', 'rankready-ai-llm-seo' ); ?></p>

			<div class="rnrd-fw-list">
				<p class="rnrd-fw-empty"><?php esc_html_e( 'Loading…', 'rankready-ai-llm-seo' ); ?></p>
			</div>

			<p class="rnrd-fw-status"></p>
		</div>
		<?php
		// FREE-100 — JS handler relocated to assets/admin.js (initFreshnessWidget).
		// Inline <script> echoes are banned by WP.org Rule #3 and the old
		// inline-style red color (#d63638) violated the mint brand palette.
	}

	// ── REST routes ───────────────────────────────────────────────────────

	public static function register_routes(): void {
		register_rest_route( self::NS, '/freshness/list', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'rest_list' ),
			'permission_callback' => array( self::class, 'permission' ),
			'args'                => array(
				'bucket' => array(
					'type'              => 'string',
					'default'           => 'stale',
					'enum'              => array( 'stale', 'going_stale', 'fresh' ),
					'sanitize_callback' => 'sanitize_key',
				),
				// The Stale Threshold control on the card. Same name and same
				// clamp as the `/freshness` scan route so both halves of that
				// card answer for the window the user actually picked.
				'days'   => array(
					'type'              => 'integer',
					'default'           => self::DEFAULT_STALE_DAYS,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( self::NS, '/freshness/refresh', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'rest_refresh' ),
			'permission_callback' => array( self::class, 'permission' ),
		) );
	}

	public static function permission() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error( 'rnrd_forbidden', __( 'Insufficient permissions.', 'rankready-ai-llm-seo' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_list( WP_REST_Request $request ) {
		$bucket     = sanitize_key( (string) $request->get_param( 'bucket' ) );
		$stale_days = self::stale_days( $request->get_param( 'days' ) );
		$going_days = self::going_stale_days( $stale_days );
		$posts      = self::query_bucket( $bucket, $stale_days );

		$out = array();
		foreach ( $posts as $p ) {
			$age = self::days_since_modified( $p );
			$out[] = array(
				'id'    => $p->ID,
				'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'edit'  => get_edit_post_link( $p->ID, 'raw' ),
				'age'   => $age,
			);
		}

		// The list is capped at LIST_LIMIT but the bucket count is not, so the
		// two can legitimately disagree. Report both, so the UI can say the list
		// is the first N of M instead of silently dropping the rest.
		$total = self::count_bucket( $bucket, $stale_days );

		// The three counts and the two cutoffs travel with the list so the card
		// can restate itself when the threshold changes, instead of leaving
		// server-rendered labels claiming a window the list no longer uses.
		return new WP_REST_Response(
			array(
				'bucket'           => $bucket,
				'posts'            => $out,
				'limit'            => self::LIST_LIMIT,
				'total'            => $total,
				'counts'           => self::bucket_counts( $stale_days ),
				'stale_days'       => $stale_days,
				'going_stale_days' => $going_days,
			),
			200
		);
	}

	public static function rest_refresh( WP_REST_Request $request ) {
		$body     = $request->get_json_params();
		$post_ids = isset( $body['post_ids'] ) && is_array( $body['post_ids'] ) ? array_map( 'absint', $body['post_ids'] ) : array();
		$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );

		if ( empty( $post_ids ) ) {
			return new WP_Error( 'rnrd_no_ids', __( 'No post IDs supplied.', 'rankready-ai-llm-seo' ), array( 'status' => 400 ) );
		}

		$now_mysql     = current_time( 'mysql' );
		$now_mysql_gmt = current_time( 'mysql', true );

		$refreshed = 0;
		$skipped   = 0;  // capability denied
		$failed    = 0;  // wp_update_post returned WP_Error

		// v1.2.0-beta.4 — save/restore the previous value instead of blanket
		// reset to false. Two concurrent admin requests sharing a PHP-FPM
		// worker would otherwise tear down each other's re-entrancy guard.
		// (Audit beta.3 #10.)
		$prev_generating = class_exists( 'RNRD_Generator' ) ? RNRD_Generator::$generating : null;
		if ( null !== $prev_generating ) {
			RNRD_Generator::$generating = true;
		}

		try {
			foreach ( $post_ids as $post_id ) {
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					$skipped++;
					continue;
				}
				$result = RNRD_Util::update_post_fields( array(
					'ID'                => $post_id,
					'post_modified'     => $now_mysql,
					'post_modified_gmt' => $now_mysql_gmt,
				), true );
				if ( is_wp_error( $result ) ) {
					$failed++;
				} else {
					$refreshed++;
				}
			}
		} finally {
			if ( null !== $prev_generating ) {
				RNRD_Generator::$generating = $prev_generating;
			}
		}

		// Honest counts so the JS UI doesn't claim N refreshed when N were skipped.
		return new WP_REST_Response( array(
			'refreshed' => $refreshed,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'requested' => count( $post_ids ),
		), 200 );
	}

	// ── Internal queries ──────────────────────────────────────────────────

	// Public so the dashboard summary widget can read the stale/going/fresh
	// counts without re-implementing the query.
	public static function bucket_counts( $stale_days = null ): array {
		/*
		 * This used to write `rnrd_freshness_last_run` once a day, with a comment
		 * saying the Agentic Ready Scorecard's "Freshness scanned" signal read it.
		 * No reader ever existed: a repo-wide grep across rankready-free,
		 * rankready-pro, lab and qa found the write and nothing else, and the
		 * scorecard itself (get_scorecard_signals + render_card_scorecard) was
		 * deleted in 1.4.0 as ~285 lines of code with zero callers. So the option
		 * was a write nobody read, justified by a signal that no longer exists,
		 * and the comment asserted an outcome the code did not produce.
		 *
		 * Removed rather than wired up: the card that renders today is
		 * RNRD_Admin::agent_visibility_signals(), which derives every row from the
		 * feature's own live state, so a "we ran a scan" timestamp is not a signal
		 * it needs. Adding one would change the score denominator for every
		 * existing install. The option name stays retired; nothing reads it, so
		 * leaving a stale copy on sites that already have it is harmless.
		 */
		$stale_days = self::stale_days( $stale_days );

		// One scan, three conditional sums. This was three COUNT(*) queries that each
		// walked the same published rows; post_modified_gmt has no index in core, so
		// every extra query was another pass. Still a live count (no cache), and the
		// buckets are the same ranges count_bucket() uses.
		global $wpdb;
		$post_types   = self::tracked_post_types();
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$stale_cutoff = gmdate( 'Y-m-d H:i:s', time() - $stale_days * DAY_IN_SECONDS );
		$going_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::going_stale_days( $stale_days ) * DAY_IN_SECONDS );

		$sql  = "SELECT
				COALESCE( SUM( post_modified_gmt < %s ), 0 ) AS stale,
				COALESCE( SUM( post_modified_gmt < %s AND post_modified_gmt >= %s ), 0 ) AS going_stale,
				COALESCE( SUM( post_modified_gmt >= %s ), 0 ) AS fresh
			FROM {$wpdb->posts}
			WHERE post_status = 'publish' AND post_password = '' AND post_type IN ($placeholders)";
		$args = array_merge( array( $stale_cutoff, $going_cutoff, $stale_cutoff, $going_cutoff ), $post_types );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return array(
			'stale'       => (int) ( $row['stale'] ?? 0 ),
			'going_stale' => (int) ( $row['going_stale'] ?? 0 ),
			'fresh'       => (int) ( $row['fresh'] ?? 0 ),
		);
	}

	private static function count_bucket( string $bucket, int $stale_days ): int {
		global $wpdb;
		$post_types     = self::tracked_post_types();
		$placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$stale_cutoff   = gmdate( 'Y-m-d H:i:s', time() - $stale_days * DAY_IN_SECONDS );
		$going_cutoff   = gmdate( 'Y-m-d H:i:s', time() - self::going_stale_days( $stale_days ) * DAY_IN_SECONDS );

		// query_bucket() passes `has_password => false`, so the rows the user can
		// actually see exclude password-protected posts. Counting them here made
		// the KPI and the list disagree permanently on any site with one, with no
		// way for the user to reconcile the two numbers. Match the list.
		$where = "post_status = 'publish' AND post_password = '' AND post_type IN ($placeholders)";

		if ( 'stale' === $bucket ) {
			$where .= ' AND post_modified_gmt < %s';
			$args   = array_merge( $post_types, array( $stale_cutoff ) );
		} elseif ( 'going_stale' === $bucket ) {
			$where .= ' AND post_modified_gmt < %s AND post_modified_gmt >= %s';
			$args   = array_merge( $post_types, array( $going_cutoff, $stale_cutoff ) );
		} else {
			$where .= ' AND post_modified_gmt >= %s';
			$args   = array_merge( $post_types, array( $going_cutoff ) );
		}

		// FREE-102 — $where is built from literals + placeholders only; the two
		// cutoffs are bound as placeholders, never interpolated.
		// $wpdb->posts is a WP core table name. Real-time count over fresh-window;
		// caching would defeat the purpose (UI shows live "stale today" totals).
		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	private static function query_bucket( string $bucket, int $stale_days ): array {
		$post_types   = self::tracked_post_types();
		$stale_cutoff = gmdate( 'Y-m-d H:i:s', time() - $stale_days * DAY_IN_SECONDS );
		$going_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::going_stale_days( $stale_days ) * DAY_IN_SECONDS );

		$date_query = array();
		if ( 'stale' === $bucket ) {
			$date_query = array( array( 'column' => 'post_modified_gmt', 'before' => $stale_cutoff, 'inclusive' => false ) );
		} elseif ( 'going_stale' === $bucket ) {
			/*
			 * The two cutoffs were the wrong way round here, and had been since
			 * this shipped: `before $stale_cutoff AND after $going_cutoff` asks
			 * for rows older than the stale line AND newer than the going-stale
			 * line, which is an empty range by construction. So the Going stale
			 * tab counted its posts correctly in the KPI above and then rendered
			 * "Nothing in the window" underneath, every time, on every site. The
			 * count query next door had it right, which is why the two never
			 * agreed. $going_cutoff is the later date, so it is the `before`.
			 */
			$date_query = array(
				'relation' => 'AND',
				array( 'column' => 'post_modified_gmt', 'before' => $going_cutoff, 'inclusive' => false ),
				array( 'column' => 'post_modified_gmt', 'after'  => $stale_cutoff, 'inclusive' => true ),
			);
		} else {
			$date_query = array( array( 'column' => 'post_modified_gmt', 'after' => $going_cutoff, 'inclusive' => true ) );
		}

		return (array) get_posts( array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'has_password'           => false,
			'orderby'                => 'modified',
			'order'                  => 'ASC',
			'posts_per_page'         => self::LIST_LIMIT,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'date_query'             => $date_query,
		) );
	}

	private static function tracked_post_types(): array {
		// Track every post type the user has opted into for ANY RankReady
		// feature — AI Summary, llms.txt, or Markdown — so a selected CPT shows
		// up in the Freshness scan too (not just 'post'). Pro unlocks CPTs in
		// those pickers; whatever the user selected is honoured here.
		$types = array();
		foreach ( array( RNRD_OPT_POST_TYPES, RNRD_OPT_LLMS_POST_TYPES, RNRD_OPT_MD_POST_TYPES ) as $opt ) {
			$val = get_option( $opt, array() );
			if ( is_array( $val ) ) {
				$types = array_merge( $types, $val );
			}
		}
		// Sensible default + page is always covered (RankReady runs on pages).
		if ( empty( $types ) ) {
			$types = array( 'post', 'page' );
		}
		if ( ! in_array( 'page', $types, true ) ) {
			$types[] = 'page';
		}
		/**
		 * Filter the post types scanned for content freshness.
		 *
		 * @param string[] $types Post type slugs.
		 */
		$types = (array) apply_filters( 'rankready_freshness_post_types', $types );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
	}

	private static function days_since_modified( WP_Post $p ): int {
		$ts = strtotime( $p->post_modified_gmt );
		if ( $ts <= 0 ) {
			return 0;
		}
		return (int) max( 0, floor( ( time() - $ts ) / DAY_IN_SECONDS ) );
	}
}
