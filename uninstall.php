<?php
/**
 * Uninstall — optionally clean up all plugin data.
 *
 * Preserves all user data by default. If the admin opted in via
 * RankReady → Settings → Advanced → "Delete all RankReady data when the
 * plugin is deleted", every RankReady
 * option, post meta, user meta, and transient is removed.
 *
 * This file only runs on a full plugin "Delete" from the Plugins page,
 * never on deactivation. Deactivation preserves everything.
 *
 * @package RankReady
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// FREE-102 — file-level phpcs ignore for the direct-DB sniffs.
// Uninstall.php deletes plugin data in bulk: $wpdb->delete() on $wpdb->postmeta
// and $wpdb->usermeta filtered by our own RankReady meta_key list, plus a
// DROP TABLE on $wpdb->prefix . 'rnrd_crawler_log'. No user input flows into
// the queries. Caching is meaningless — we are deleting the data the cache
// would describe. Runs once per uninstall, never on a normal request.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall-only bulk delete of RankReady's own rows, see above.

/*
 * ── Multisite: every site decides for itself ────────────────────────────────
 *
 * On a network, "Delete" on the Plugins screen removes the plugin files for
 * every site at once, but WordPress runs this file ONCE, in the context of the
 * main site. Before 1.4.0 that meant only the main site was cleaned and every
 * other site kept its options, post meta, cron events and crawler-log table
 * forever, with the plugin that could read them gone.
 *
 * Now each site is visited and cleaned only if THAT site's admin ticked
 * "Delete all data on uninstall". A site that did not tick it keeps
 * everything, exactly as a single-site install does. User meta is the one
 * exception to "per site": the usermeta table is shared by the whole network,
 * so it is only removed when no site is keeping its RankReady data (see
 * rnrd_uninstall_users() below).
 */
if ( ! function_exists( 'rnrd_uninstall_site' ) ) {
	/**
	 * Clean the CURRENT site (the one switch_to_blog() selected).
	 *
	 * @return string 'wiped' when the site opted in and was cleaned, 'kept' when
	 *                it holds RankReady data and did not opt in, 'unused' when
	 *                RankReady never stored anything there.
	 */
	function rnrd_uninstall_site(): string {
		global $wpdb;

		// ── Honor the opt-in ─────────────────────────────────────────────────
		// Bail out early and preserve ALL user data unless the admin explicitly
		// enabled "Delete all data on uninstall" in the plugin settings. The
		// option itself is always cleaned up so the next install starts clean.
		$rnrd_should_delete_all = 'on' === get_option( 'rnrd_delete_on_uninstall', 'off' );
		delete_option( 'rnrd_delete_on_uninstall' );

		if ( ! $rnrd_should_delete_all ) {
			$rnrd_has_data = $wpdb->get_var( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rnrd\\_%' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			return null === $rnrd_has_data ? 'unused' : 'kept';
		}

		// ── Remove the Cloudflare Cache Rule we created (v1.2.1) ─────────────────────
		// The rule lives on the user's Cloudflare zone, not in WordPress. Wiping the
		// rnrd_cf_* options (removed by the rnrd_% sweep below) without deleting it first would strand the rule
		// permanently: the token needed to remove it is deleted in the same pass, so
		// the user would have to hunt it down in the Cloudflare dashboard by hand.
		// Delete remote first, local second.
		//
		// Uninstall runs with the plugin NOT loaded, so RNRD_Crypto's option filters
		// are not registered and get_option() returns ciphertext — decrypt explicitly.
		// Every step is guarded; a Cloudflare outage must never block the uninstall.
		$rnrd_cf_rule_id = (string) get_option( 'rnrd_cf_rule_id', '' );
		$rnrd_cf_zone_id = (string) get_option( 'rnrd_cf_zone_id', '' );

		if ( '' !== $rnrd_cf_rule_id && '' !== $rnrd_cf_zone_id ) {
			$rnrd_crypto_file = __DIR__ . '/includes/class-rnrd-crypto.php';
			if ( file_exists( $rnrd_crypto_file ) ) {
				require_once $rnrd_crypto_file;
			}

			$rnrd_cf_decrypt = static function ( string $raw ): string {
				if ( '' === $raw || ! class_exists( 'RNRD_Crypto' ) ) {
					return $raw;
				}
				return RNRD_Crypto::decrypt( $raw );
			};

			$rnrd_cf_token  = $rnrd_cf_decrypt( (string) get_option( 'rnrd_cf_api_token', '' ) );
			$rnrd_cf_gkey   = $rnrd_cf_decrypt( (string) get_option( 'rnrd_cf_global_key', '' ) );
			$rnrd_cf_email  = (string) get_option( 'rnrd_cf_email', '' );
			$rnrd_cf_hdrs   = array();

			if ( '' !== $rnrd_cf_gkey && '' !== $rnrd_cf_email ) {
				$rnrd_cf_hdrs = array(
					'X-Auth-Email' => $rnrd_cf_email,
					'X-Auth-Key'   => $rnrd_cf_gkey,
					'Content-Type' => 'application/json',
				);
			} elseif ( '' !== $rnrd_cf_token ) {
				$rnrd_cf_hdrs = array(
					'Authorization' => 'Bearer ' . $rnrd_cf_token,
					'Content-Type'  => 'application/json',
				);
			}

			if ( ! empty( $rnrd_cf_hdrs ) ) {
				// Single-rule deletion needs the ruleset ID, not the phase entrypoint —
				// a DELETE on the entrypoint path silently no-ops. Same reason
				// RNRD_Cloudflare::delete_rule() resolves the entrypoint first.
				$rnrd_cf_base  = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $rnrd_cf_zone_id );
				$rnrd_cf_entry = wp_remote_get(
					$rnrd_cf_base . '/rulesets/phases/http_request_cache_settings/entrypoint',
					array(
						'headers' => $rnrd_cf_hdrs,
						'timeout' => 10,
					)
				);

				if ( ! is_wp_error( $rnrd_cf_entry ) ) {
					$rnrd_cf_body = json_decode( (string) wp_remote_retrieve_body( $rnrd_cf_entry ), true );
					$rnrd_cf_rsid = isset( $rnrd_cf_body['result']['id'] ) ? (string) $rnrd_cf_body['result']['id'] : '';

					if ( '' !== $rnrd_cf_rsid ) {
						wp_remote_request(
							$rnrd_cf_base . '/rulesets/' . rawurlencode( $rnrd_cf_rsid ) . '/rules/' . rawurlencode( $rnrd_cf_rule_id ),
							array(
								'method'  => 'DELETE',
								'headers' => $rnrd_cf_hdrs,
								'timeout' => 10,
							)
						);
					}
				}
			}

			unset( $rnrd_cf_token, $rnrd_cf_gkey, $rnrd_cf_hdrs );
		}

		// ── Purge the per-provider remote-model-list transients ──────────────────────
		// Must run BEFORE the "Delete options" block below: RNRD_LLM::purge_models_cache()
		// deletes the CURRENT v5 transient via models_cache_key( $provider ), which reads
		// the provider's API key with get_api_key() to build the key's fingerprint. If the
		// rnrd_*_api_key options are deleted first, get_api_key() returns '' and the
		// lookup resolves to the harmless-but-useless "..._none" fingerprint — a key that
		// was never actually used to cache anything, since a real cache entry is only ever
		// stored under a non-empty key's fingerprint (get_models_for() skips caching when
		// no key is set). Running this first is what makes the deletion match a real row.
		//
		// This is also what makes the purge object-cache-safe: on a Redis/Memcached site
		// transients never touch wp_options, so the raw-SQL sweep near the end of this
		// file cannot see them, and delete_transient() is the only thing that reaches them
		// (WordPress core routes delete_transient() through wp_cache_delete() whenever a
		// persistent object cache is active).
		//
		// Uninstall runs standalone — WP includes ONLY this file, never the plugin
		// bootstrap (rankready.php) — so RNRD_LLM was never class_exists()-true here and
		// this call was silently a no-op on every uninstall until now. Load the class file
		// directly, same pattern as the RNRD_Crypto require above.
		//
		// get_api_key( 'openai' ) inside RNRD_LLM reads the RNRD_OPT_KEY constant, which
		// normally comes from rankready.php and is otherwise undefined in this standalone
		// context (a PHP 8 fatal on an undefined constant would abort the rest of this
		// script, including the postmeta/usermeta cleanup and the catch-all sweep further
		// down) — define it here, guarded, before loading.
		if ( ! class_exists( 'RNRD_LLM' ) ) {
			if ( ! defined( 'RNRD_OPT_KEY' ) ) {
				define( 'RNRD_OPT_KEY', 'rnrd_openai_api_key' );
			}
			if ( ! defined( 'RNRD_OPT_MODEL' ) ) {
				define( 'RNRD_OPT_MODEL', 'rnrd_openai_model' );
			}
			$rnrd_llm_file = __DIR__ . '/includes/class-rnrd-llm.php';
			if ( file_exists( $rnrd_llm_file ) ) {
				require_once $rnrd_llm_file;
			}
		}
		if ( class_exists( 'RNRD_LLM' ) ) {
			RNRD_LLM::purge_models_cache();
		}

		// ── Delete options ────────────────────────────────────────────────────────────
		$rnrd_options = array(
			// Multi-LLM provider keys + models (v1.1.0+). MUST be in this list — opting
			// into "delete all" for key rotation / GDPR compliance is meaningless if
			// API keys survive uninstall. (Audit beta.3 finding #2.)
			'rnrd_llm_provider',
			'rnrd_anthropic_api_key',
			'rnrd_anthropic_model',
			'rnrd_gemini_api_key',
			'rnrd_gemini_model',
			'rnrd_deepseek_api_key',
			'rnrd_deepseek_model',

			// v1.2.0 — Agent Ready options.
			'rnrd_brand_terms',
			'rnrd_max_snippet_default',
			'rnrd_ai_training_enable',
			'rnrd_ai_citation_enable',
			'rnrd_ai_referral_stats',
			'rnrd_ai_referral_enable',
			'rnrd_mcp_enable',
			'rnrd_mcp_expose_posts',
			'rnrd_mcp_expose_pages',
			'rnrd_mcp_expose_authors',
			'rnrd_mcp_expose_taxonomies',
			'rnrd_mcp_expose_sitemap',
			'rnrd_mcp_expose_menus',
			'rnrd_mcp_expose_llms_txt',
			'rnrd_mcp_expose_rr_ai',
			'rnrd_mcp_expose_freshness',
			'rnrd_mcp_expose_cpts',
			'rnrd_mcp_expose_comments',
			'rnrd_mcp_expose_media',
			'rnrd_mcp_expose_users',
			'rnrd_mcp_expose_plugins',
			'rnrd_mcp_expose_themes',
			'rnrd_mcp_expose_settings',
			'rnrd_md_hint_div',
			'rnrd_md_bot_auto_serve',
			'rnrd_welcome_completed',
			'rnrd_welcome_prompted',
			'rnrd_tips_optin_sent',   // legacy site-wide flag (pre per-admin user_meta); kept for cleanup.

			// v1.1.x — Content Signals.
			'rnrd_content_signals_enable',
			'rnrd_content_signals_ai_train',
			'rnrd_content_signals_search',
			'rnrd_content_signals_ai_input',

			// v1.1.x — Headless / Public API.
			'rnrd_headless_enable',
			'rnrd_headless_cors_origins',
			'rnrd_headless_expose_meta',
			'rnrd_headless_cache_ttl',
			'rnrd_headless_rate_limit',
			'rnrd_headless_revalidate_url',
			'rnrd_headless_revalidate_secret',
			'rnrd_headless_graphql',

			// v1.1.1+ — "What's new" banner version tracker.
			'rnrd_installed_version',

			// AI Summary.
			'rnrd_openai_api_key',
			'rnrd_openai_model',
			'rnrd_post_types',
			'rnrd_default_label',
			'rnrd_default_show_label',
			'rnrd_default_heading_tag',
			'rnrd_summary_enable',
			'rnrd_auto_display',
			'rnrd_display_position',
			'rnrd_auto_display_merged',
			'rnrd_custom_prompt',
			'rnrd_product_context',
			'rnrd_auto_generate',
			// Bulk summary state.
			'rnrd_bulk_queue',
			'rnrd_bulk_done',
			'rnrd_bulk_total',
			'rnrd_bulk_running',
			// LLMs.txt.
			'rnrd_llms_enable',
			'rnrd_llms_site_name',
			'rnrd_llms_summary',
			'rnrd_llms_about',
			'rnrd_llms_post_types',
			'rnrd_llms_max_posts',
			'rnrd_llms_cache_ttl',
			'rnrd_llms_full_enable',
			// Markdown.
			'rnrd_md_enable',
			'rnrd_md_home_enable',
			'rnrd_md_post_types',
			'rnrd_md_include_meta',
			'rnrd_okf_enable',
			'rnrd_okf_post_types',
			// LLMs.txt taxonomy controls.
			'rnrd_llms_exclude_cats',
			'rnrd_llms_exclude_tags',
			'rnrd_llms_show_categories',
			'rnrd_llms_use_md_urls',
			// Robots.txt crawler settings.
			'rnrd_robots_enable',
			'rnrd_robots_crawlers',
			// Bulk author state.
			'rnrd_bac_queue',
			'rnrd_bac_total',
			'rnrd_bac_done',
			'rnrd_bac_running',
			'rnrd_bac_to_author',
			// FAQ settings.
			'rnrd_dfs_login',
			'rnrd_dfs_password',
			'rnrd_faq_post_types',
			'rnrd_faq_count',
			'rnrd_faq_brand_terms',
			'rnrd_faq_enable',
			'rnrd_faq_auto_display',
			'rnrd_faq_position',
			'rnrd_faq_heading_tag',
			'rnrd_faq_show_reviewed',
			'rnrd_faq_auto_generate',
			// Bulk FAQ state.
			'rnrd_faq_queue',
			'rnrd_faq_done',
			'rnrd_faq_total',
			'rnrd_faq_running',
			// Bulk start-over state.
			'rnrd_so_queue',
			'rnrd_so_done',
			'rnrd_so_total',
			'rnrd_so_running',
			// Bulk operation tracking.
			'rnrd_bulk_skipped',
			'rnrd_bulk_failed',
			'rnrd_faq_skipped',
			'rnrd_faq_failed',
			// Error log.
			'rnrd_error_log',
			// Token usage.
			'rnrd_token_usage',
			// DataForSEO usage.
			'rnrd_dfs_usage',
			// Migration flag.
			'rnrd_aps_migrated',
			// Schema automation.
			'rnrd_schema_article',
			'rnrd_schema_faq',
			'rnrd_schema_howto',
			'rnrd_schema_itemlist',
			'rnrd_schema_speakable',
			'rnrd_schema_batch_size',
			// Schema scan bulk state.
			'rnrd_schema_queue',
			'rnrd_schema_done',
			'rnrd_schema_total',
			'rnrd_schema_running',
			// Author Box.
			'rnrd_author_enable',
			'rnrd_author_auto_display',
			'rnrd_author_layout',
			'rnrd_author_heading',
			'rnrd_author_heading_tag',
			'rnrd_author_schema_enable',
			'rnrd_author_editorial_url',
			'rnrd_author_factcheck_url',
			'rnrd_author_post_types',
			'rnrd_author_trust_enable',
		);

		foreach ( $rnrd_options as $rnrd_option ) {
			delete_option( $rnrd_option );
		}

		// ── Delete remaining transients ───────────────────────────────────────────────
		// The per-provider model-cache transients were already purged further up, before
		// the API key options were deleted — see the comment there for why the order
		// matters.
		delete_transient( 'rnrd_llms_txt_cache' );
		delete_transient( 'rnrd_llms_full_txt_cache' );

		// ── Delete post meta ──────────────────────────────────────────────────────────
		$rnrd_meta_keys = array(
			'_rnrd_summary',
			'_rnrd_md_ver',           // v1.4.1 per-post .md cache version
			'_rnrd_content_hash',
			'_rnrd_last_generated',
			'_rnrd_last_attempt',      // v1.4.0 regeneration cooldown clock
			'_rnrd_disable_summary',
			'_rnrd_faq',
			'_rnrd_faq_hash',
			'_rnrd_faq_generated',
			'_rnrd_faq_last_attempt',  // v1.4.0 FAQ regeneration cooldown clock
			'_rnrd_faq_disable',
			'_rnrd_faq_keyword',
			'_rnrd_faq_last_failure',  // v1.2.0 circuit breaker timestamp
			'_rnrd_max_snippet',       // v1.2.0 per-post max-snippet override
			'_rnrd_llms_exclude',      // v1.2.0 per-post llms.txt exclusion
			'_rnrd_tokens_used',
			'_rnrd_schema_type',
			'_rnrd_schema_data',
			'_rnrd_schema_hash',
			// Author Box per-post meta.
			'_rnrd_author_fact_checked_by',
			'_rnrd_author_reviewed_by',
			'_rnrd_author_last_reviewed',
			'_rnrd_author_disable',
		);

		foreach ( $rnrd_meta_keys as $rnrd_key ) {
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $rnrd_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		// Catch-all for any _rnrd_* post meta added after the list above was written.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_rnrd\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		// ── Clear scheduled cron ──────────────────────────────────────────────────────
		wp_unschedule_hook( 'rnrd_async_generate' ); // scheduled with a post id arg; clear_scheduled_hook() without args misses them.
		wp_unschedule_hook( 'rnrd_async_faq_generate' ); // scheduled with a post id arg; clear_scheduled_hook() without args misses them.
		wp_clear_scheduled_hook( 'rnrd_schema_scan' );
		wp_clear_scheduled_hook( 'rnrd_cron_bulk_startover' );
		wp_clear_scheduled_hook( 'rnrd_cron_bulk_faq' );
		wp_clear_scheduled_hook( 'rnrd_cron_bulk_summary' );
		wp_clear_scheduled_hook( 'rnrd_crawler_log_prune' );  // v0.6.6 daily prune cron
		wp_unschedule_hook( 'rnrd_okf_purge_edge' ); // may carry args; clear_scheduled_hook() without args misses them.
		wp_clear_scheduled_hook( 'rnrd_unicode_meta_migrate' );

		// ── Drop the crawler-log table (v0.6.6) ──────────────────────────────────────
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'rnrd_crawler_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		delete_option( 'rnrd_crawler_log_db_version' );

		// ── Drop the stored rewrite rules so llms.txt and .md endpoints go ────────
		// Deleting the option rather than calling flush_rewrite_rules(): inside
		// switch_to_blog() the global $wp_rewrite still belongs to the main site,
		// so a flush would write the MAIN site's rules into this site's option.
		// WordPress rebuilds the rules on the site's next request, without ours,
		// because the plugin is gone. Same result on a single site.
		delete_option( 'rewrite_rules' );

		// TC-UNI-04: catch-all sweep for any rnrd_* option (and rnrd_* transients) not in
		// the static lists above — migration/version flags added across releases
		// (rnrd_installed_version, rnrd_model_migrations, rnrd_*_corrected_*, etc.).
		// Runs only in the full-wipe path (guarded by the early return above).
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rnrd\\_%' OR option_name LIKE '\\_transient\\_rnrd\\_%' OR option_name LIKE '\\_transient\\_timeout\\_rnrd\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		delete_transient( 'rnrd_generated_count' );
		delete_option( 'rnrd_first_activated' );

		return 'wiped';
	}
}

if ( ! function_exists( 'rnrd_uninstall_users' ) ) {
	/**
	 * Remove RankReady's user meta. Network-wide on multisite, because the
	 * usermeta table is shared: an author's RankReady profile fields belong to
	 * the person, not to one site.
	 */
	function rnrd_uninstall_users(): void {
		global $wpdb;

		// ── Delete user meta (Author Box profile fields) ─────────────────────────────
		$rnrd_user_meta_keys = array(
			'rnrd_author_job_title',
			'rnrd_author_employer',
			'rnrd_author_employer_url',
			'rnrd_author_bio',
			'rnrd_author_headshot',
			'rnrd_author_headshot_alt',
			'rnrd_author_started_year',
			'rnrd_author_expertise',
			'rnrd_author_credentials_suffix',
			'rnrd_author_education',
			'rnrd_author_certifications',
			'rnrd_author_memberships',
			'rnrd_author_awards',
			'rnrd_author_wikidata',
			'rnrd_author_wikipedia',
			'rnrd_author_orcid',
			'rnrd_author_scholar',
			'rnrd_author_linkedin',
			'rnrd_author_github',
			'rnrd_author_youtube',
			'rnrd_author_twitter',
			'rnrd_author_website',
			'rnrd_author_contact_url',
		);

		// Per-user admin-notice + tutorial dismissal flags. These are not author-profile
		// fields so they were missing from the list above, which meant a full "delete all
		// data" uninstall followed by a reinstall silently suppressed the tutorial, the
		// what's-new banner and three admin notices — the user could never get them back.
		$rnrd_user_meta_keys[] = 'rnrd_tutorial_dismissed';
		$rnrd_user_meta_keys[] = 'rnrd_whatsnew_dismissed_version';
		$rnrd_user_meta_keys[] = 'rnrd_tips_optin_sent'; // per-admin tips email opt-in (no raw email stored locally).
		$rnrd_user_meta_keys[] = '_rnrd_nginx_wk_dismissed';
		$rnrd_user_meta_keys[] = '_rnrd_swis_notice_dismissed';
		$rnrd_user_meta_keys[] = '_rnrd_apo_notice_dismissed';

		foreach ( $rnrd_user_meta_keys as $rnrd_key ) {
			$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $rnrd_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		/*
		 * Notice state and the derived count. User meta is per user, so a site with
		 * five admins leaves five rows per notice behind if this is skipped.
		 */
		$wpdb->query(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\_rnrd\_notice\_%'"
		);
	}
}

// ── Run it ───────────────────────────────────────────────────────────────────
$rnrd_results = array();

if ( is_multisite() ) {
	// 'number' => 0 means no limit. get_sites() defaults to 100, which would
	// silently skip every site past the hundredth on a large network.
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $rnrd_site_id ) {
		switch_to_blog( (int) $rnrd_site_id );
		$rnrd_results[] = rnrd_uninstall_site();
		restore_current_blog();
	}
} else {
	$rnrd_results[] = rnrd_uninstall_site();
}

// User meta goes only when at least one site asked for a full wipe and no
// site is keeping its RankReady data. On a single site that is simply "the
// admin opted in", the same behaviour as every release before 1.4.0.
if ( in_array( 'wiped', $rnrd_results, true ) && ! in_array( 'kept', $rnrd_results, true ) ) {
	rnrd_uninstall_users();
}
