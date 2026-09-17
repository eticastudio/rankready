<?php
/**
 * Uninstall — optionally clean up all plugin data.
 *
 * Preserves all user data by default. If the admin opted in via
 * Settings → Tools → "Delete all data on uninstall", every RankReady
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
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// ── Honor the opt-in ─────────────────────────────────────────────────────────
// Bail out early and preserve ALL user data unless the admin explicitly
// enabled "Delete all data on uninstall" in the plugin settings. The option
// itself is always cleaned up so the next install starts clean.
$rnrd_should_delete_all = 'on' === get_option( 'rnrd_delete_on_uninstall', 'off' );
delete_option( 'rnrd_delete_on_uninstall' );

if ( ! $rnrd_should_delete_all ) {
	return;
}

// ── Remove the Cloudflare Cache Rule we created (v1.2.1) ─────────────────────
// The rule lives on the user's Cloudflare zone, not in WordPress. Wiping the
// rnrd_cf_* options below without deleting it first would strand the rule
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
	// Version tracking.
	'rnrd_installed_version',
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

// ── Delete transients ─────────────────────────────────────────────────────────
delete_transient( 'rnrd_llms_txt_cache' );
delete_transient( 'rnrd_llms_full_txt_cache' );
if ( class_exists( 'RNRD_LLM' ) ) {
	RNRD_LLM::purge_models_cache();
}

// ── Delete post meta ──────────────────────────────────────────────────────────
global $wpdb;

$rnrd_meta_keys = array(
	'_rnrd_summary',
	'_rnrd_content_hash',
	'_rnrd_last_generated',
	'_rnrd_disable_summary',
	'_rnrd_faq',
	'_rnrd_faq_hash',
	'_rnrd_faq_generated',
	'_rnrd_faq_disable',
	'_rnrd_faq_keyword',
	'_rnrd_faq_last_failure',  // v1.2.0 circuit breaker timestamp
	'_rnrd_max_snippet',       // v1.2.0 per-post max-snippet override
	'_rnrd_llms_exclude',      // v1.2.0 per-post llms.txt exclusion
	'_rnrd_post_markdown',     // v1.3.2 cached clean markdown body
	'_rnrd_post_markdown_ts',  // v1.3.2 cached markdown generation timestamp
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

// ── Clear scheduled cron ──────────────────────────────────────────────────────
wp_clear_scheduled_hook( 'rnrd_async_generate' );
wp_clear_scheduled_hook( 'rnrd_async_faq_generate' );
wp_clear_scheduled_hook( 'rnrd_schema_scan' );
wp_clear_scheduled_hook( 'rnrd_cron_bulk_startover' );
wp_clear_scheduled_hook( 'rnrd_cron_bulk_faq' );
wp_clear_scheduled_hook( 'rnrd_cron_bulk_summary' );
wp_clear_scheduled_hook( 'rnrd_crawler_log_prune' );  // v0.6.6 daily prune cron

// ── Drop the crawler-log table (v0.6.6) ──────────────────────────────────────
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'rnrd_crawler_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
delete_option( 'rnrd_crawler_log_db_version' );

// ── Flush rewrite rules to clean up llms.txt and .md endpoints ───────────────
flush_rewrite_rules( false );

// TC-UNI-04: catch-all sweep for any rnrd_* option (and rnrd_* transients) not in
// the static lists above — migration/version flags added across releases
// (rnrd_installed_version, rnrd_model_migrations, rnrd_*_corrected_*, etc.).
// Runs only in the full-wipe path (guarded by the early return above).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rnrd\\_%' OR option_name LIKE '\\_transient\\_rnrd\\_%' OR option_name LIKE '\\_transient\\_timeout\\_rnrd\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
