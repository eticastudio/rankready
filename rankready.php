<?php
/**
 * Plugin Name:       RankReady – AI SEO, Schema, llms.txt, AEO and GEO for ChatGPT, Gemini and Perplexity
 * Plugin URI:        https://hostmy.blog/plugins/rankready/
 * Description:       Make your WordPress content readable by ChatGPT, Perplexity, Claude, Gemini, and Google AI Overviews. AI summaries, FAQ schema, llms.txt, Markdown endpoints, agent discovery headers, WebMCP, and crawler controls — in one plugin.
 * Version:           1.3.2-beta3
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            HostMyBlog
 * Author URI:        https://hostmy.blog
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rankready-ai-llm-seo
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'rnrd_fs' ) ) {
    // Create a helper function for easy SDK access.
    function rnrd_fs() {
        global $rnrd_fs;

        if ( ! isset( $rnrd_fs ) ) {
            $rnrd_fs_sdk = dirname( __FILE__ ) . '/vendor/freemius/start.php';
            if ( ! file_exists( $rnrd_fs_sdk ) ) {
                return false;
            }

            require_once $rnrd_fs_sdk;

            $rnrd_fs = fs_dynamic_init( array(
                'id'                  => '37729',
                'slug'                => 'rankready-ai-llm-seo',
                'type'                => 'plugin',
                'public_key'          => 'pk_4a3356e64068eb259388059c5c167',
                'is_premium'          => false,
                'has_addons'          => false,
                'has_paid_plans'      => false,
                'is_org_compliant'    => true,
                'menu'                => array(
                    'slug'           => 'rankready-ai-llm-seo',
                    'first-path'     => 'admin.php?page=rankready-ai-llm-seo',
                    'account'        => false,
                    'contact'        => false,
                    'support'        => false,
                ),
            ) );
        }

        return $rnrd_fs;
    }

    if ( false !== rnrd_fs() ) {
        do_action( 'rnrd_fs_loaded' );
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// Duplicate-install guard — prevent fatals when two copies are active.
// ─────────────────────────────────────────────────────────────────────────────
// WordPress does not dedupe plugin installs by slug. If a site ends up with
// two RankReady folders in /wp-content/plugins/ (e.g. one installed from a
// GitHub "Source code" zip named "RankReady-LLM-SEO-EEAT-AI-Optimization-1.5"
// and one from a release asset named "rankready"), WordPress will happily
// try to activate both. The second copy used to fatal the entire site because
// the autoloader captured RNRD_DIR from the first copy's location but the
// second copy's classes were in a different directory. This guard makes the
// second-loaded copy bail out cleanly with a dashboard notice instead.
//
// Regardless of folder name: the FIRST plugin file to define RNRD_VERSION wins.
// Every subsequent copy becomes a no-op and surfaces a warning to admins.
// ═════════════════════════════════════════════════════════════════════════════
if ( defined( 'RNRD_VERSION' ) ) {
	add_action( 'admin_notices', function (): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo '<strong>RankReady:</strong> ';
		echo esc_html( sprintf(
			/* translators: 1: active version, 2: second plugin folder name */
			__( 'Another copy of RankReady is already active (version %1$s). The duplicate copy in "%2$s" has been disabled automatically to prevent conflicts. Delete the older folder from Plugins → Installed Plugins or via SFTP.', 'rankready-ai-llm-seo' ),
			RNRD_VERSION,
			basename( __DIR__ )
		) );
		echo '</p></div>';
	} );
	return; // Abort the rest of this file. No constants, no autoloader, no hooks.
}

// ── Constants (guarded to prevent conflicts) ─────────────────────────────────
if ( ! defined( 'RNRD_VERSION' ) ) {
	define( 'RNRD_VERSION',  '1.3.2-beta3' );
	define( 'RNRD_FILE',     __FILE__ );
	define( 'RNRD_DIR',      plugin_dir_path( __FILE__ ) );
	define( 'RNRD_URL',      plugin_dir_url( __FILE__ ) );
	define( 'RNRD_BASENAME', plugin_basename( __FILE__ ) );

	// Free build has NO monthly caps. Manual AI Summary + FAQ generation is
	// unlimited. Auto/Bulk generation are "Coming Soon" placeholders, not
	// capped — they're simply not yet implemented in the Free build.
	//
	// No store URL constant either. The Free WP.org build relies entirely on
	// WordPress.org's native update mechanism — no EDD updater, no Plugin
	// Update Checker, no custom-update-server endpoint, no license activation.
	// Pro (internal-only) lives in a separate branch with its own distribution.

	// Option keys — LLM provider selection (multi-provider, since v1.1.1).
	// `RNRD_OPT_KEY` and `RNRD_OPT_MODEL` below remain the OpenAI key/model for
	// backwards compatibility — every existing install keeps working.
	define( 'RNRD_OPT_LLM_PROVIDER',     'rnrd_llm_provider' ); // 'openai' | 'anthropic' | 'gemini' | 'deepseek'

	// Anthropic (Claude).
	define( 'RNRD_OPT_ANTHROPIC_KEY',    'rnrd_anthropic_api_key' );
	define( 'RNRD_OPT_ANTHROPIC_MODEL',  'rnrd_anthropic_model' );

	// Google Gemini.
	define( 'RNRD_OPT_GEMINI_KEY',       'rnrd_gemini_api_key' );
	define( 'RNRD_OPT_GEMINI_MODEL',     'rnrd_gemini_model' );

	// DeepSeek.
	define( 'RNRD_OPT_DEEPSEEK_KEY',     'rnrd_deepseek_api_key' );
	define( 'RNRD_OPT_DEEPSEEK_MODEL',   'rnrd_deepseek_model' );

	// "What's new" banner — last seen plugin version, per-user dismiss.
	define( 'RNRD_OPT_INSTALLED_VERSION', 'rnrd_installed_version' );

	// Option keys — AI Summary (OpenAI legacy keys, kept for back-compat).
	define( 'RNRD_OPT_KEY',              'rnrd_openai_api_key' );
	define( 'RNRD_OPT_MODEL',            'rnrd_openai_model' );
	define( 'RNRD_OPT_POST_TYPES',       'rnrd_post_types' );
	define( 'RNRD_OPT_LABEL',            'rnrd_default_label' );
	define( 'RNRD_OPT_SHOW_LABEL',       'rnrd_default_show_label' );
	define( 'RNRD_OPT_HEADING_TAG',      'rnrd_default_heading_tag' );
	define( 'RNRD_OPT_AUTO_GENERATE',    'rnrd_auto_generate' );
	define( 'RNRD_OPT_SUMMARY_ENABLE',   'rnrd_summary_enable' ); // Frontend: block, widget, shortcode, auto-display.
	define( 'RNRD_OPT_AUTO_DISPLAY',     'rnrd_auto_display' );   // 'off' | 'before' | 'after' (legacy: 'on' + RNRD_OPT_DISPLAY_POSITION).
	define( 'RNRD_OPT_DISPLAY_POSITION', 'rnrd_display_position' ); // Legacy; read fallback only.
	define( 'RNRD_OPT_CUSTOM_PROMPT',    'rnrd_custom_prompt' );
	define( 'RNRD_OPT_PRODUCT_CONTEXT',  'rnrd_product_context' );

	// Option keys — LLMs.txt.
	define( 'RNRD_OPT_LLMS_ENABLE',       'rnrd_llms_enable' );
	define( 'RNRD_OPT_LLMS_SITE_NAME',    'rnrd_llms_site_name' );
	define( 'RNRD_OPT_LLMS_SUMMARY',      'rnrd_llms_summary' );
	define( 'RNRD_OPT_LLMS_ABOUT',        'rnrd_llms_about' );
	define( 'RNRD_OPT_LLMS_POST_TYPES',   'rnrd_llms_post_types' );
	define( 'RNRD_OPT_LLMS_MAX_POSTS',    'rnrd_llms_max_posts' );
	define( 'RNRD_OPT_LLMS_CACHE_TTL',    'rnrd_llms_cache_ttl' );
	define( 'RNRD_OPT_LLMS_FULL_ENABLE',  'rnrd_llms_full_enable' );

	// Option keys — LLMs.txt taxonomy controls.
	define( 'RNRD_OPT_LLMS_EXCLUDE_CATS',    'rnrd_llms_exclude_cats' );
	define( 'RNRD_OPT_LLMS_EXCLUDE_TAGS',    'rnrd_llms_exclude_tags' );
	define( 'RNRD_OPT_LLMS_SHOW_CATEGORIES', 'rnrd_llms_show_categories' );
	define( 'RNRD_OPT_LLMS_USE_MD_URLS',     'rnrd_llms_use_md_urls' );

	// Option keys — LLM Crawler robots.txt controls.
	define( 'RNRD_OPT_ROBOTS_ENABLE',   'rnrd_robots_enable' );
	define( 'RNRD_OPT_ROBOTS_CRAWLERS', 'rnrd_robots_crawlers' );
	define( 'RNRD_OPT_ROBOTS_BLOCKED',  'rnrd_robots_blocked' );  // AI crawlers to hard-block (Disallow: /). Default empty = back-compat.
	// v1.2.1 — UI transport for the per-crawler Allow/Default/Block radio. Map of
	// user-agent => 'allow'|'block'|'default'. The two arrays above stay the
	// source of truth for robots.txt output and are DERIVED from this on save,
	// so every existing reader keeps working unchanged.
	define( 'RNRD_OPT_ROBOTS_MODE',     'rnrd_robots_mode' );

	// Option keys — Content Signals (contentsignals.org).
	define( 'RNRD_OPT_CONTENT_SIGNALS_ENABLE',   'rnrd_content_signals_enable' );
	define( 'RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN', 'rnrd_content_signals_ai_train' );
	define( 'RNRD_OPT_CONTENT_SIGNALS_SEARCH',   'rnrd_content_signals_search' );
	define( 'RNRD_OPT_CONTENT_SIGNALS_AI_INPUT', 'rnrd_content_signals_ai_input' );

	// Option keys — Markdown.
	define( 'RNRD_OPT_MD_ENABLE',         'rnrd_md_enable' );
	define( 'RNRD_OPT_MD_POST_TYPES',     'rnrd_md_post_types' );
	define( 'RNRD_OPT_MD_INCLUDE_META',   'rnrd_md_include_meta' );

	// Option keys — Open Knowledge Format (OKF) bundle (v1.1.5).
	define( 'RNRD_OPT_OKF_ENABLE',        'rnrd_okf_enable' );
	define( 'RNRD_OPT_OKF_POST_TYPES',    'rnrd_okf_post_types' );

	// Option keys — Schema Automation.
	define( 'RNRD_OPT_SCHEMA_ARTICLE',    'rnrd_schema_article' );
	define( 'RNRD_OPT_SCHEMA_FAQ',        'rnrd_schema_faq' );
	define( 'RNRD_OPT_SCHEMA_HOWTO',      'rnrd_schema_howto' );
	define( 'RNRD_OPT_SCHEMA_ITEMLIST',   'rnrd_schema_itemlist' );
	define( 'RNRD_OPT_SCHEMA_SPEAKABLE',  'rnrd_schema_speakable' );
	define( 'RNRD_OPT_SCHEMA_BATCH_SIZE', 'rnrd_schema_batch_size' );

	// Meta keys — Schema Automation (stored by WP-Cron scanner).
	define( 'RNRD_META_SCHEMA_TYPE', '_rnrd_schema_type' );   // 'howto', 'itemlist', or ''
	define( 'RNRD_META_SCHEMA_DATA', '_rnrd_schema_data' );   // Serialized schema array
	define( 'RNRD_META_SCHEMA_HASH', '_rnrd_schema_hash' );   // md5(title+content) for change detection

	// Cron — Schema scanner.
	define( 'RNRD_SCHEMA_CRON_HOOK', 'rnrd_schema_scan' );

	// Cron — Bulk operations (run even after browser close).
	define( 'RNRD_CRON_BULK_STARTOVER', 'rnrd_cron_bulk_startover' );
	define( 'RNRD_CRON_BULK_FAQ',       'rnrd_cron_bulk_faq' );
	define( 'RNRD_CRON_BULK_SUMMARY',   'rnrd_cron_bulk_summary' );

	// Bulk state — Schema scan.
	define( 'RNRD_SCHEMA_QUEUE',   'rnrd_schema_queue' );
	define( 'RNRD_SCHEMA_DONE',    'rnrd_schema_done' );
	define( 'RNRD_SCHEMA_TOTAL',   'rnrd_schema_total' );
	define( 'RNRD_SCHEMA_RUNNING', 'rnrd_schema_running' );

	// Option keys — FAQ.
	define( 'RNRD_OPT_DFS_LOGIN',        'rnrd_dfs_login' );
	define( 'RNRD_OPT_DFS_PASSWORD',     'rnrd_dfs_password' );
	define( 'RNRD_OPT_FAQ_POST_TYPES',   'rnrd_faq_post_types' );
	define( 'RNRD_OPT_FAQ_COUNT',        'rnrd_faq_count' );
	define( 'RNRD_OPT_FAQ_BRAND_TERMS',  'rnrd_faq_brand_terms' );
	define( 'RNRD_OPT_FAQ_ENABLE',       'rnrd_faq_enable' ); // Frontend: block, widget, shortcode, auto-display.
	define( 'RNRD_OPT_FAQ_AUTO_DISPLAY', 'rnrd_faq_auto_display' ); // 'off' | 'before' | 'after' (legacy: 'on' + RNRD_OPT_FAQ_POSITION).
	define( 'RNRD_OPT_FAQ_POSITION',     'rnrd_faq_position' );     // Legacy; read fallback only.
	define( 'RNRD_OPT_FAQ_HEADING_TAG',  'rnrd_faq_heading_tag' );
	define( 'RNRD_OPT_FAQ_SHOW_REVIEWED','rnrd_faq_show_reviewed' );
	define( 'RNRD_OPT_FAQ_AUTO_GENERATE','rnrd_faq_auto_generate' );

	// Data retention.
	define( 'RNRD_OPT_DELETE_ON_UNINSTALL', 'rnrd_delete_on_uninstall' );

	// rc.11 — Hide "Generated from RankReady" credit line (placeholder, Coming Soon).
	// Today: option ignored, credit always shows.
	define( 'RNRD_OPT_HIDE_BRANDING', 'rnrd_hide_branding' );

	// Option keys — Author Box (EEAT).
	define( 'RNRD_OPT_AUTHOR_ENABLE',         'rnrd_author_enable' );          // Frontend: block, widget, shortcode, auto-display.
	define( 'RNRD_OPT_AUTHOR_AUTO_DISPLAY',   'rnrd_author_auto_display' );    // 'off' | 'before' | 'after' | 'both'
	define( 'RNRD_OPT_AUTHOR_LAYOUT',         'rnrd_author_layout' );          // 'card' | 'compact' | 'inline'
	define( 'RNRD_OPT_AUTHOR_HEADING',        'rnrd_author_heading' );         // Default heading text ("About the Author").
	define( 'RNRD_OPT_AUTHOR_HEADING_TAG',    'rnrd_author_heading_tag' );     // Default heading tag.
	define( 'RNRD_OPT_AUTHOR_SCHEMA_ENABLE',  'rnrd_author_schema_enable' );   // Emit Person schema (auto-skipped vs SEO plugins → merged instead).
	define( 'RNRD_OPT_AUTHOR_EDITORIAL_URL',  'rnrd_author_editorial_url' );   // Site-wide publishingPrinciples URL.
	define( 'RNRD_OPT_AUTHOR_FACTCHECK_URL',  'rnrd_author_factcheck_url' );   // "How we fact-check" URL (footer link).
	define( 'RNRD_OPT_AUTHOR_POST_TYPES',     'rnrd_author_post_types' );      // Which post types auto-display the box on.
	define( 'RNRD_OPT_AUTHOR_TRUST_ENABLE',   'rnrd_author_trust_enable' );    // Opt-in for the per-post Fact-Checked/Reviewed/Last-Reviewed panel.

	// Per-post meta keys — Author Trust panel.
	define( 'RNRD_META_AUTHOR_FACT_CHECKED_BY', '_rnrd_author_fact_checked_by' );  // user_id of fact-checker
	define( 'RNRD_META_AUTHOR_REVIEWED_BY',     '_rnrd_author_reviewed_by' );      // user_id of reviewer
	define( 'RNRD_META_AUTHOR_LAST_REVIEWED',   '_rnrd_author_last_reviewed' );    // YYYY-MM-DD string
	define( 'RNRD_META_AUTHOR_DISABLE',         '_rnrd_author_disable' );          // per-post opt-out

	// Headless / Public API options.
	define( 'RNRD_OPT_HEADLESS_ENABLE',          'rnrd_headless_enable' );            // Master toggle for public read-only API.
	define( 'RNRD_OPT_HEADLESS_CORS_ORIGINS',    'rnrd_headless_cors_origins' );      // Comma-separated allowed frontend origins.
	define( 'RNRD_OPT_HEADLESS_EXPOSE_META',     'rnrd_headless_expose_meta' );       // Register _rnrd_faq / _rnrd_summary in core REST.
	define( 'RNRD_OPT_HEADLESS_CACHE_TTL',       'rnrd_headless_cache_ttl' );         // CDN cache max-age in seconds (s-maxage).
	define( 'RNRD_OPT_HEADLESS_RATE_LIMIT',      'rnrd_headless_rate_limit' );        // Requests per minute per IP.
	define( 'RNRD_OPT_HEADLESS_REVALIDATE_URL',  'rnrd_headless_revalidate_url' );    // Next.js/Nuxt webhook URL.
	define( 'RNRD_OPT_HEADLESS_REVALIDATE_SEC',  'rnrd_headless_revalidate_secret' ); // Shared secret for webhook auth.
	define( 'RNRD_OPT_HEADLESS_GRAPHQL',         'rnrd_headless_graphql' );           // Register WPGraphQL fields.

	// ── v1.2.0 — Agent Ready options ──────────────────────────────────────────
	// Brand Terms — single canonical input wired to llms.txt, robots.txt, FAQ prompt, and summary prompt.
	define( 'RNRD_OPT_BRAND_TERMS',          'rnrd_brand_terms' );

	// AI snippet preview controls.
	define( 'RNRD_OPT_MAX_SNIPPET_DEFAULT',  'rnrd_max_snippet_default' );  // 'on'/'off' — default for new posts
	define( 'RNRD_META_MAX_SNIPPET',          '_rnrd_max_snippet' );          // per-post override: 'on'|'off'|'' (inherit)

	// Per-post llms.txt exclusion.
	define( 'RNRD_META_LLMS_EXCLUDE',         '_rnrd_llms_exclude' );         // '1' = exclude from AI surfaces (llms.txt, Markdown, WebMCP, OKF)

	// Per-post cached markdown (v1.3.2).
	define( 'RNRD_META_POST_MARKDOWN',        '_rnrd_post_markdown' );        // Cached clean markdown body (HTML→MD conversion).
	define( 'RNRD_META_POST_MARKDOWN_TS',     '_rnrd_post_markdown_ts' );     // Unix timestamp of last markdown generation.

	// AI Insights tracking toggles.
	define( 'RNRD_OPT_AI_TRAINING_ENABLE',    'rnrd_ai_training_enable' ); // 'on' | 'off' — master toggle for training-bot logging.
	define( 'RNRD_OPT_AI_CITATION_ENABLE',    'rnrd_ai_citation_enable' ); // 'on' | 'off' — master toggle for citation-bot logging.
	// AI Referral Traffic — daily counts per source, rolling 30 days.
	define( 'RNRD_OPT_AI_REFERRAL_STATS',     'rnrd_ai_referral_stats' );
	define( 'RNRD_OPT_AI_REFERRAL_ENABLE',    'rnrd_ai_referral_enable' ); // 'on' | 'off' — master toggle.

	// WebMCP — master toggle for /.well-known/mcp.json + Abilities API registration.
	define( 'RNRD_OPT_MCP_ENABLE',            'rnrd_mcp_enable' );          // 'on' | 'off' — opt-in, default off.

	// WebMCP — per-resource exposure toggles (v1.2.0-beta.6).
	// Sensible defaults: public content ON, PII/heavy/stack-reveal resources OFF.
	define( 'RNRD_OPT_MCP_EXPOSE_POSTS',      'rnrd_mcp_expose_posts' );      // ON  — core public content
	define( 'RNRD_OPT_MCP_EXPOSE_PAGES',      'rnrd_mcp_expose_pages' );      // ON  — static pages
	define( 'RNRD_OPT_MCP_EXPOSE_AUTHORS',    'rnrd_mcp_expose_authors' );    // ON  — EEAT signal
	define( 'RNRD_OPT_MCP_EXPOSE_TAXONOMIES', 'rnrd_mcp_expose_taxonomies' ); // ON  — discovery graph
	define( 'RNRD_OPT_MCP_EXPOSE_SITEMAP',    'rnrd_mcp_expose_sitemap' );    // ON  — cold-crawl seed
	define( 'RNRD_OPT_MCP_EXPOSE_MENUS',      'rnrd_mcp_expose_menus' );      // ON  — public anyway
	define( 'RNRD_OPT_MCP_EXPOSE_LLMS_TXT',   'rnrd_mcp_expose_llms_txt' );   // ON  — content already public
	define( 'RNRD_OPT_MCP_EXPOSE_RR_AI',      'rnrd_mcp_expose_rr_ai' );      // ON  — summaries / FAQs / brand
	define( 'RNRD_OPT_MCP_EXPOSE_FRESHNESS',  'rnrd_mcp_expose_freshness' );  // ON  — public surface
	define( 'RNRD_OPT_MCP_EXPOSE_CPTS',       'rnrd_mcp_expose_cpts' );       // array — opt-in per CPT
	define( 'RNRD_OPT_MCP_EXPOSE_COMMENTS',   'rnrd_mcp_expose_comments' );   // OFF — PII (author names/emails)
	define( 'RNRD_OPT_MCP_EXPOSE_MEDIA',      'rnrd_mcp_expose_media' );      // OFF — heavy + non-attached uploads
	define( 'RNRD_OPT_MCP_EXPOSE_USERS',      'rnrd_mcp_expose_users' );      // OFF — PII (full user list)
	define( 'RNRD_OPT_MCP_EXPOSE_PLUGINS',    'rnrd_mcp_expose_plugins' );    // OFF — reveals stack / attack surface
	define( 'RNRD_OPT_MCP_EXPOSE_THEMES',     'rnrd_mcp_expose_themes' );     // OFF — reveals stack
	define( 'RNRD_OPT_MCP_EXPOSE_SETTINGS',   'rnrd_mcp_expose_settings' );   // OFF — may leak secrets

	// Markdown layer sub-toggles (controlled inside the Markdown Endpoints card).
	define( 'RNRD_OPT_MD_HOME_ENABLE',        'rnrd_md_home_enable' );        // 'on' | 'off' — homepage / posts-page markdown surfaces
	define( 'RNRD_OPT_MD_HINT_DIV',           'rnrd_md_hint_div' );         // 'on' | 'off' — hidden AI-hint div in body
	define( 'RNRD_OPT_MD_BOT_AUTO_SERVE',     'rnrd_md_bot_auto_serve' );   // 'on' | 'off' — UA-based forced markdown for AI bots
	// v1.1.2 — Same-URL Accept-header content negotiation. DEFAULT OFF.
	// When ON, a request to the canonical URL (/, /post-slug/) sending
	// `Accept: text/markdown` (or a known AI-bot UA) receives markdown INLINE on
	// that URL. This is UNSAFE behind any cache that ignores `Vary: Accept` —
	// Cloudflare APO, Varnish, Fastly default, and most shared-host page caches
	// all do. They cache the markdown body under the canonical URL and then serve
	// it to every subsequent browser, blanking the page (live bug, nexterwp.com,
	// 2026-06-02). Cloudflare's own docs confirm both that it ignores Vary values
	// and that APO ignores origin Cache-Control at the edge, so NO origin header
	// can make this safe. Markdown is therefore served ONLY at distinct `.md`
	// URLs by default (different cache key = impossible to poison), discovered via
	// the `Link: rel="alternate"; type="text/markdown"` header + llms.txt. The
	// llms.txt spec, Vercel, Mintlify and GitBook all use distinct `.md` URLs as
	// the cache-safe layer. Only turn this on if you control your cache key.
	define( 'RNRD_OPT_MD_ACCEPT_NEGOTIATION', 'rnrd_md_accept_negotiation' ); // 'on' | 'off' — same-URL Accept negotiation (v1.2: default on; auto-guarded off on Cloudflare APO)

	// Meta keys.
	define( 'RNRD_META_SUMMARY',   '_rnrd_summary' );
	define( 'RNRD_META_HASH',      '_rnrd_content_hash' );
	define( 'RNRD_META_GENERATED', '_rnrd_last_generated' );
	define( 'RNRD_META_DISABLE',   '_rnrd_disable_summary' );

	// Meta keys — FAQ.
	define( 'RNRD_META_FAQ',           '_rnrd_faq' );
	define( 'RNRD_META_FAQ_HASH',      '_rnrd_faq_hash' );
	define( 'RNRD_META_FAQ_GENERATED',    '_rnrd_faq_generated' );
	define( 'RNRD_META_FAQ_DISABLE',      '_rnrd_faq_disable' );
	define( 'RNRD_META_FAQ_KEYWORD',      '_rnrd_faq_keyword' );
	define( 'RNRD_META_FAQ_LAST_FAILURE', '_rnrd_faq_last_failure' ); // v1.2.0 — circuit-breaker timestamp.

	// Cron.
	define( 'RNRD_CRON_HOOK', 'rnrd_async_generate' );

	// Bulk state — summary.
	define( 'RNRD_BULK_QUEUE',   'rnrd_bulk_queue' );
	define( 'RNRD_BULK_DONE',    'rnrd_bulk_done' );
	define( 'RNRD_BULK_TOTAL',   'rnrd_bulk_total' );
	define( 'RNRD_BULK_RUNNING', 'rnrd_bulk_running' );

	// Bulk state — FAQ.
	define( 'RNRD_FAQ_QUEUE',   'rnrd_faq_queue' );
	define( 'RNRD_FAQ_DONE',    'rnrd_faq_done' );
	define( 'RNRD_FAQ_TOTAL',   'rnrd_faq_total' );
	define( 'RNRD_FAQ_RUNNING', 'rnrd_faq_running' );

	// Bulk state — start over.
	define( 'RNRD_SO_QUEUE',   'rnrd_so_queue' );
	define( 'RNRD_SO_DONE',    'rnrd_so_done' );
	define( 'RNRD_SO_TOTAL',   'rnrd_so_total' );
	define( 'RNRD_SO_RUNNING', 'rnrd_so_running' );

	// Bulk state — author.
	define( 'RNRD_BAC_QUEUE',   'rnrd_bac_queue' );
	define( 'RNRD_BAC_TOTAL',   'rnrd_bac_total' );
	define( 'RNRD_BAC_DONE',    'rnrd_bac_done' );
	define( 'RNRD_BAC_RUNNING', 'rnrd_bac_running' );
	define( 'RNRD_BAC_TO',      'rnrd_bac_to_author' );

	// Transient keys.
	define( 'RNRD_LLMS_CACHE_KEY',      'rnrd_llms_txt_cache' );
	define( 'RNRD_LLMS_FULL_CACHE_KEY', 'rnrd_llms_full_txt_cache' );
}

// ── rnrd_is_pro() — Pro extension point ─────────────────────────────────────
// The Free build always returns false by default. The companion Pro addon
// (or any third party with permission) opts in by attaching to the
// `rnrd_is_pro` filter:
//
//     add_filter( 'rnrd_is_pro', '__return_true' );
//
// This filter pattern means Pro never needs to win a function_exists race,
// never needs a mu-plugin trick, never needs to load before Free in the
// plugin order — it just hooks in like any other WordPress filter. Free
// stays the single source of truth for the function itself.
if ( ! function_exists( 'rnrd_is_pro' ) ) {
	function rnrd_is_pro(): bool {
		return (bool) apply_filters( 'rnrd_is_pro', false );
	}
}

/**
 * Resolve Auto-display to off|before|after.
 * Legacy Summary/FAQ stored 'on' plus a separate position option.
 * 'both' is Author Box only; maps to the feature's default position.
 */
function rnrd_auto_display_mode( string $option, string $legacy_position_option, string $legacy_position_default ): string {
	$v = (string) get_option( $option, 'off' );
	if ( in_array( $v, array( 'off', 'before', 'after' ), true ) ) {
		return $v;
	}
	if ( 'both' === $v ) {
		return $legacy_position_default;
	}
	if ( 'on' === $v ) {
		$pos = (string) get_option( $legacy_position_option, $legacy_position_default );
		return 'before' === $pos ? 'before' : 'after';
	}
	return 'off';
}

/**
 * One-shot: rewrite legacy 'on' Auto-display rows to before/after.
 */
function rnrd_maybe_merge_auto_display_options(): void {
	if ( get_option( 'rnrd_auto_display_merged' ) ) {
		return;
	}
	$summary = (string) get_option( RNRD_OPT_AUTO_DISPLAY, 'off' );
	if ( 'on' === $summary ) {
		$pos = (string) get_option( RNRD_OPT_DISPLAY_POSITION, 'before' );
		update_option( RNRD_OPT_AUTO_DISPLAY, 'before' === $pos ? 'before' : 'after', false );
	}
	$faq = (string) get_option( RNRD_OPT_FAQ_AUTO_DISPLAY, 'off' );
	if ( 'on' === $faq ) {
		$pos = (string) get_option( RNRD_OPT_FAQ_POSITION, 'after' );
		update_option( RNRD_OPT_FAQ_AUTO_DISPLAY, 'before' === $pos ? 'before' : 'after', false );
	}
	update_option( 'rnrd_auto_display_merged', '1', false );
}

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
	if ( 0 !== strpos( $class, 'RNRD_' ) ) {
		return;
	}
	$file = RNRD_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// ── Duplicate-install scanner (belt + braces) ────────────────────────────────
// The guard at the top of this file stops the second-loaded copy from running,
// but the first-loaded copy has no way to know a duplicate exists until
// something queries active_plugins. This hook scans active_plugins on every
// admin page load and shows a warning to admins if more than one plugin file
// ending in /rankready.php is active. The check is cheap — one array_filter
// over a single option read — and only runs when is_admin() is true.
add_action( 'admin_init', function (): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$active = (array) get_option( 'active_plugins', array() );
	$rnrd_entries = array_values( array_filter( $active, function ( $plugin_file ) {
		return 'rankready.php' === basename( (string) $plugin_file );
	} ) );
	if ( count( $rnrd_entries ) <= 1 ) {
		return;
	}
	add_action( 'admin_notices', function () use ( $rnrd_entries ): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo '<strong>RankReady:</strong> ';
		echo esc_html__( 'Multiple RankReady plugin folders are active at the same time. Only one is running; the rest are disabled by the duplicate-install guard but are still consuming a slot in active_plugins. Deactivate the duplicates to silence this notice:', 'rankready-ai-llm-seo' );
		echo '</p><ul style="margin-left:20px;list-style:disc;">';
		foreach ( $rnrd_entries as $entry ) {
			echo '<li><code>' . esc_html( dirname( (string) $entry ) ) . '/</code></li>';
		}
		echo '</ul><p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">';
		echo esc_html__( 'Go to Plugins → Installed Plugins', 'rankready-ai-llm-seo' );
		echo '</a></p></div>';
	} );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Updates: handled by WordPress.org SVN (auto-updates via Plugins screen).
// ─────────────────────────────────────────────────────────────────────────────
// v1.0.0+ ships exclusively from WordPress.org. The Plugin Update Checker
// (PUC) library was removed from the WP.org distribution per WP.org policy.
// ═════════════════════════════════════════════════════════════════════════════

// ═════════════════════════════════════════════════════════════════════════════
// Folder name enforcement — WP.org policy compliant version.
// ─────────────────────────────────────────────────────────────────────────────
// Only ONE guard remains: upgrader_source_selection — renames the EXTRACTED
// temp folder during plugin install/update. This is allowed under WP.org
// guidelines because it operates only on the staging directory during the
// upgrade process; it does NOT touch wp-content/plugins/ post-install and
// does NOT modify the active_plugins option.
//
// Removed for WP.org policy compliance (May 2026 review):
//   - admin_init auto-migration that renamed the plugin folder in place
//   - direct write to the active plugins option after folder rename
//   - multisite sitewide active plugins option rewrite
//
// If a user installs from a non-canonical zip (GitHub "Download ZIP"), the
// duplicate-install guard at the top of this file handles deactivation
// cleanly and the user is shown a dashboard notice telling them to rename
// the folder via SFTP. No silent DB writes.
// ═════════════════════════════════════════════════════════════════════════════

add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra ) {
	if ( ! is_object( $upgrader ) || ! is_a( $upgrader, 'Plugin_Upgrader' ) ) {
		return $source;
	}

	$source = trailingslashit( $source );
	$main   = $source . 'rankready.php';

	if ( ! is_readable( $main ) ) {
		return $source;
	}

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data = get_plugin_data( $main, false, false );
	if ( empty( $data['Name'] ) || false === stripos( $data['Name'], 'RankReady' ) ) {
		return $source;
	}

	$current = basename( untrailingslashit( $source ) );
	if ( 'rankready-ai-llm-seo' === $current ) {
		return $source;
	}

	$new_source = trailingslashit( $remote_source ) . 'rankready/';

	if ( file_exists( $new_source ) ) {
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $new_source, true );
		}
	}

	// FREE-102 — use WP_Filesystem::move() instead of native rename() per WP.org policy.
	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
	}
	if ( ! $wp_filesystem || ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $new_source ), true ) ) {
		return $source;
	}

	return $new_source;
}, 1, 4 );

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {
	// FREE-102 — load_plugin_textdomain() removed. Since WP 4.6 WordPress.org
	// auto-loads translations for plugins hosted on WordPress.org. Calling it
	// manually triggers a PCP warning and is no longer needed for the .org build.

	if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
		add_action( 'admin_notices', function (): void {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'RankReady requires WordPress 6.9 or higher.', 'rankready-ai-llm-seo' )
				. '</p></div>';
		} );
		return;
	}

	rnrd_maybe_merge_auto_display_options();

	// Auto-flush rewrite rules after plugin update (activation hook doesn't fire on updates).
	$stored_version = get_option( 'rnrd_installed_version', '' );
	if ( $stored_version !== RNRD_VERSION ) {
		// v1.2.0-rc.1 — silent-upgrade safety. Existing v1.1.x installs
		// should NOT auto-flip new behaviour-changing toggles on. The
		// register_setting() defaults make these ON for fresh installs;
		// for upgrades, we explicitly seed 'off' if the option row is
		// missing AND the previous version is v1.1.x or earlier.
		// (Audit beta.3 #7.)
		if ( '' !== $stored_version && version_compare( $stored_version, '1.2.0-beta.1', '<' ) ) {
			// v1.1.19 — Dropped 'rnrd_md_hint_div' and 'rnrd_md_bot_auto_serve'
			// from the safe-off list. The hidden hint div is invisible to humans
			// (clip-path + aria-hidden) and the UA auto-serve only fires for
			// known AI bots — neither has any user-facing surface that
			// justifies hiding them behind an opt-in. The old guard was
			// capping the dashboard agent-signal scorecard at 8/10 on every
			// fresh install where these two had never been written to the DB.
			$upgrade_safe_off = array(
				'rnrd_max_snippet_default',  // emits <meta robots> sitewide
				'rnrd_mcp_enable',           // publishes /.well-known/mcp.json
			);
			foreach ( $upgrade_safe_off as $opt ) {
				if ( false === get_option( $opt, false ) ) {
					update_option( $opt, 'off', false );
				}
			}
		}

		// v1.1.19 — One-time corrective for installs that got the 'off' marker
		// written by the over-aggressive pre-1.1.19 migration. If markdown is
		// already enabled, the hint div and bot auto-serve should be on too —
		// they're the two halves of "AI agents can find your markdown copy."
		// Guarded by a fixed migration key so it only runs once per site.
		if ( ! get_option( 'rnrd_md_signals_corrected_v1119' ) ) {
			if ( 'on' === get_option( 'rnrd_md_enable', 'off' ) ) {
				if ( 'on' !== get_option( 'rnrd_md_hint_div', 'on' ) ) {
					update_option( 'rnrd_md_hint_div', 'on', false );
				}
				if ( 'on' !== get_option( 'rnrd_md_bot_auto_serve', 'on' ) ) {
					update_option( 'rnrd_md_bot_auto_serve', 'on', false );
				}
			}
			update_option( 'rnrd_md_signals_corrected_v1119', 1, false );
		}

		// v1.1.21 — One-shot seed for llms-full when the site already has
		// llms.txt on (the signal was unreachable from the onboarding wizard
		// prior to v1.1.21). Referral tracking is no longer forced here —
		// default is on via get_option fallback; the user controls it from
		// Insights → Real AI Referrals.
		if ( ! get_option( 'rnrd_scorecard_corrected_v1121' ) ) {
			if ( 'on' === get_option( 'rnrd_llms_enable', 'off' ) ) {
				if ( 'on' !== get_option( 'rnrd_llms_full_enable', 'off' ) ) {
					update_option( 'rnrd_llms_full_enable', 'on', false );
				}
			}
			update_option( 'rnrd_scorecard_corrected_v1121', 1, false );
		}

		// v1.3.1 — Seed llms .md-URL toggle: on for fresh installs, off for upgrades
		// (silent-upgrade safety — do not change public /llms.txt output on update).
		if ( false === get_option( RNRD_OPT_LLMS_USE_MD_URLS, false ) ) {
			$md_urls_default = ( '' === $stored_version ) ? 'on' : 'off';
			update_option( RNRD_OPT_LLMS_USE_MD_URLS, $md_urls_default, false );
			if ( class_exists( 'RNRD_Llms_Txt' ) ) {
				RNRD_Llms_Txt::bust_cache_and_purge_cdn();
			}
		}

		// v1.3.1 — Drop legacy per-provider model-list transients (v4 keys and
		// pre-fingerprint rows). Safe to re-run; only clears rnrd_models_* cache.
		if ( '' !== $stored_version && version_compare( $stored_version, '1.3.1', '<' ) && ! get_option( 'rnrd_models_cache_migrated_v131' ) ) {
			if ( class_exists( 'RNRD_LLM' ) ) {
				RNRD_LLM::purge_models_cache();
			}
			update_option( 'rnrd_models_cache_migrated_v131', 1, false );
		}

		// v1.1.1 — One-shot UTF-8 rewrite of existing summary + FAQ post meta.
		// Sites with non-Latin content (Turkish, CJK, Arabic, Hindi, Cyrillic,
		// etc.) generated before 1.1.1 stored values as JSON with \uXXXX escapes
		// because wp_json_encode defaults to escaping non-ASCII. That's valid
		// JSON, but fragile across WP's slash-handling layers — a single
		// dropped backslash turns "Yatırım" into visible "Yu0131lu0131".
		//
		// 1.1.1 stores everything as real UTF-8. This migration decodes any
		// remaining \u-escaped rows and re-encodes them with the new flags.
		// Safe and backward-compatible: the old decode path (json_decode)
		// handles both shapes identically, so rolling back to 1.1.0 still
		// reads these rows correctly. Capped at 2000 rows per request to
		// avoid timing out on huge sites; subsequent page loads finish the
		// rest because the migration flag is only set after a full sweep.
		if ( ! get_option( 'rnrd_unicode_meta_migrated_v111' ) ) {
			global $wpdb;
			// Match all rnrd-owned summary + FAQ meta rows. No SQL-level
			// content filter — escaping a literal "\u" across PHP/JSON/MySQL
			// is brittle (each layer interprets backslash differently) and a
			// failed filter silently degrades to "match everything" or
			// "match nothing" with no error surface. The per-row
			// `$reencoded === $stored` check is the actual filter: rows that
			// already store pure UTF-8 (or pure ASCII) round-trip to the same
			// bytes and get skipped without a DB write. Process up to 5000
			// rows per page load — json_decode + encode runs ~100 µs per row,
			// so the whole loop is < 1 s on any realistic host.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- meta_key list is hardcoded literals
			$rows = $wpdb->get_results(
				"SELECT meta_id, meta_key, meta_value
				 FROM {$wpdb->postmeta}
				 WHERE meta_key IN ('_rnrd_summary', '_rnrd_faq')
				 LIMIT 5000",
				ARRAY_A
			);
			$migrated = 0;
			foreach ( (array) $rows as $row ) {
				$decoded = json_decode( $row['meta_value'], true );
				if ( ! is_array( $decoded ) ) {
					continue; // Skip values that aren't valid JSON arrays.
				}
				$reencoded = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( false === $reencoded || $reencoded === $row['meta_value'] ) {
					continue; // Already clean or encoder failed.
				}
				$wpdb->update(
					$wpdb->postmeta,
					array( 'meta_value' => $reencoded ),
					array( 'meta_id'    => (int) $row['meta_id'] )
				);
				$migrated++;
			}
			// Only set the done-flag if we processed fewer than the per-batch
			// cap — on huge sites the migration finishes silently across page
			// loads. The flag must be truthy to be considered "done"; if the
			// first sweep found 0 \u-escaped rows the flag value is 0 (falsy)
			// so the migration would re-run forever. Use '0_completed' string
			// so any future change can still detect "ran once".
			if ( count( (array) $rows ) < 5000 ) {
				update_option(
					'rnrd_unicode_meta_migrated_v111',
					$migrated > 0 ? (string) $migrated : '0_completed',
					false
				);
			}
		}

		// Re-persist cache exclusions on upgrade. Activation does not fire on
		// WordPress.org auto-updates, and this must run BEFORE the version
		// marker is bumped — an older admin_init silent-update path was
		// unreachable because plugins_loaded already wrote the new version.
		if ( class_exists( 'RNRD_Cache' ) ) {
			RNRD_Cache::persist_exclusions( array(
				'/llms.txt',
				'/llms-full.txt',
				'/.well-known/mcp.json',
				'.md',
			) );
		}

		// v1.1.2 — One-shot FAQ Count repair. Pre-1.1.0 Settings API cross-nulling
		// stored 0 on some installs when adjacent options saved. Repair stored 0
		// (or any out-of-range value) to the documented default of 5.
		$faq_count = (int) get_option( RNRD_OPT_FAQ_COUNT, 5 );
		if ( $faq_count < 3 || $faq_count > 10 ) {
			update_option( RNRD_OPT_FAQ_COUNT, 5 );
		}

		update_option( 'rnrd_installed_version', RNRD_VERSION );
		// Defer rewrite rule registration + flush to 'init' — $wp_rewrite is not
		// ready at plugins_loaded and calling add_rewrite_rule() before init causes
		// a fatal "Call to a member function add_rule() on null".
		add_action( 'init', function () {
			RNRD_Llms_Txt::add_rewrite_rules();
			RNRD_Markdown::add_rewrite_rules();
			RNRD_OKF::add_rewrite_rules();
			RNRD_MCP::add_rewrite_rules();
			flush_rewrite_rules( false );
		}, 99 );
		RNRD_Robots::sync_physical_robots_txt();

		// Migrate data from old AI Post Summary plugin (_aps_ meta) if present.
		// Only run once — skip if already migrated.
		if ( ! get_option( 'rnrd_aps_migrated' ) ) {
			global $wpdb;
			// FREE-102 — one-time legacy data migration from AI Post Summary plugin.
			// Runs ONCE per site (guarded by rnrd_aps_migrated option), so caching
			// would never hit. Direct DB query is the only correct choice here —
			// get_post_meta() would require iterating every post on the site.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_aps = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT 1",
				'_aps_summary'
			) );
			if ( $has_aps > 0 ) {
				// Update existing empty _rnrd_summary entries with old _aps_summary data.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->postmeta} rr
					 INNER JOIN {$wpdb->postmeta} aps ON aps.post_id = rr.post_id AND aps.meta_key = %s AND aps.meta_value != ''
					 SET rr.meta_value = aps.meta_value
					 WHERE rr.meta_key = %s AND (rr.meta_value = '' OR rr.meta_value IS NULL)",
					'_aps_summary',
					'_rnrd_summary'
				) );
				// Insert for posts that have _aps_summary but no _rnrd_summary row at all.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
					 SELECT pm.post_id, %s, pm.meta_value
					 FROM {$wpdb->postmeta} pm
					 WHERE pm.meta_key = %s
					   AND pm.meta_value != ''
					   AND pm.post_id NOT IN (
					       SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s
					   )",
					'_rnrd_summary',
					'_aps_summary',
					'_rnrd_summary'
				) );
			}
			update_option( 'rnrd_aps_migrated', true );
		}
	}

	// ── Self-healing rewrite rules (1.7.0) ─────────────────────────────────────
	// Problem: flush_rewrite_rules() only fires via update_option_ hooks, which
	// only trigger when a value *changes*. If llms/md were already 'on' before
	// save, the hook never fires and rules stay missing.
	//
	// Fix part 1: bust the "rules OK" transient on every settings save so the
	// self-heal re-runs, even when the saved value is unchanged.
	add_filter( 'pre_update_option_' . RNRD_OPT_LLMS_ENABLE,      function ( $v ) { delete_transient( 'rnrd_rewrite_ok' ); return $v; } );
	add_filter( 'pre_update_option_' . RNRD_OPT_LLMS_FULL_ENABLE, function ( $v ) { delete_transient( 'rnrd_rewrite_ok' ); return $v; } );
	add_filter( 'pre_update_option_' . RNRD_OPT_MD_ENABLE,        function ( $v ) { delete_transient( 'rnrd_rewrite_ok' ); return $v; } );
	add_filter( 'pre_update_option_' . RNRD_OPT_OKF_ENABLE,       function ( $v ) { delete_transient( 'rnrd_rewrite_ok' ); return $v; } );
	add_filter( 'pre_update_option_' . RNRD_OPT_MCP_ENABLE,       function ( $v ) { delete_transient( 'rnrd_rewrite_ok' ); return $v; } );

	// Fix part 2: on admin GET loads, detect missing or stale rules and auto-flush.
	// Skip POST — options.php runs admin_init before saving; evaluating here would
	// mark rewrites OK with the pre-save option and throttle the post-redirect GET.
	// Transient throttles this to at most once per hour.
	add_action( 'admin_init', function (): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		if ( get_transient( 'rnrd_rewrite_ok' ) ) {
			return;
		}

		$rules = (array) get_option( 'rewrite_rules', array() );
		$needs = false;

		$ours = static function ( string $pattern, string $query_var ) use ( $rules ): bool {
			return isset( $rules[ $pattern ] )
				&& false !== strpos( (string) $rules[ $pattern ], $query_var );
		};

		$llms_on    = 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$llms_other = class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::another_plugin_handles_llms_txt();
		$want_llms  = $llms_on && ! $llms_other;
		$want_full  = $llms_on && 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' );

		// Check llms.txt — skip if another plugin is known to handle it.
		if ( $want_llms && ! isset( $rules['^llms\.txt$'] ) ) {
			// v1.1.5 (#10) — defer to the canonical detector; see RNRD_Llms_Txt::another_plugin_handles_llms_txt().
			$needs = true;
		}

		// Check llms-full.txt — requires both master llms.txt and the full toggle.
		if ( ! $needs && $want_full && ! isset( $rules['^llms-full\.txt$'] ) ) {
			$needs = true;
		}

		// Check .md rewrite rule.
		if ( ! $needs && 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			$md_found = false;
			foreach ( array_keys( $rules ) as $k ) {
				if ( false !== strpos( $k, '\.md$' ) ) {
					$md_found = true;
					break;
				}
			}
			if ( ! $md_found ) {
				$needs = true;
			}
		}

		// Check OKF bundle rewrite rule (v1.1.5).
		if ( ! $needs && 'on' === get_option( RNRD_OPT_OKF_ENABLE, 'off' ) && ! isset( $rules['^okf/?$'] ) ) {
			$needs = true;
		}

		// Check WebMCP manifest rewrite rule (v1.2.0 — restored serving endpoint).
		if ( ! $needs && 'on' === get_option( RNRD_OPT_MCP_ENABLE, 'off' ) && ! isset( $rules['^\.well-known/mcp\.json$'] ) ) {
			$needs = true;
		}

		// Stale rules: feature OFF but our rewrite still persisted (e.g. same-request
		// flush after a toggle used to bake in rules registered from the old value).
		if ( ! $needs && ! $want_llms && $ours( '^llms\.txt$', 'rnrd_llms_txt' ) ) {
			$needs = true;
		}

		if ( ! $needs && ! $want_full && $ours( '^llms-full\.txt$', 'rnrd_llms_full_txt' ) ) {
			$needs = true;
		}

		$md_pattern = '^(?!wp-admin|wp-content|wp-includes|wp-json)(.+)\.md$';
		if ( ! $needs && 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' )
			&& $ours( $md_pattern, 'rnrd_md_path' ) ) {
			$needs = true;
		}

		if ( ! $needs && 'on' !== get_option( RNRD_OPT_OKF_ENABLE, 'off' )
			&& $ours( '^okf/?$', 'rnrd_okf' ) ) {
			$needs = true;
		}

		if ( ! $needs && 'on' !== get_option( RNRD_OPT_MCP_ENABLE, 'off' )
			&& $ours( '^\.well-known/mcp\.json$', 'rnrd_mcp' ) ) {
			$needs = true;
		}

		if ( $needs ) {
			RNRD_Llms_Txt::add_rewrite_rules();
			RNRD_Markdown::add_rewrite_rules();
			RNRD_OKF::add_rewrite_rules();
			RNRD_MCP::add_rewrite_rules();
			flush_rewrite_rules( false );
		}

		set_transient( 'rnrd_rewrite_ok', 1, HOUR_IN_SECONDS );
	}, 20 );

	// Register custom cron schedules on init — __() in the display labels must not
	// run during plugins_loaded (WP 6.7+ _load_textdomain_just_in_time notice).
	add_action( 'init', function (): void {
		add_filter( 'cron_schedules', function ( array $schedules ): array {
			if ( ! isset( $schedules['rnrd_five_minutes'] ) ) {
				$schedules['rnrd_five_minutes'] = array(
					'interval' => 5 * MINUTE_IN_SECONDS,
					'display'  => __( 'Every 5 Minutes (RankReady)', 'rankready-ai-llm-seo' ),
				);
			}
			if ( ! isset( $schedules['rnrd_one_minute'] ) ) {
				$schedules['rnrd_one_minute'] = array(
					'interval' => MINUTE_IN_SECONDS,
					'display'  => __( 'Every Minute (RankReady Bulk)', 'rankready-ai-llm-seo' ),
				);
			}
			return $schedules;
		} );
	}, 1 );

	// Shared t/tr helpers for localized admin + block scripts.
	add_action( 'init', function (): void {
		if ( wp_script_is( 'rnrd-i18n', 'registered' ) ) {
			return;
		}
		$path = RNRD_DIR . 'assets/rnrd-i18n.js';
		$ver  = RNRD_VERSION;
		if ( file_exists( $path ) ) {
			$ver .= '.' . filemtime( $path );
		}
		wp_register_script( 'rnrd-i18n', RNRD_URL . 'assets/rnrd-i18n.js', array(), $ver, true );
	}, 2 );

	// v1.1.0 — Encrypts API secrets at rest. Must run BEFORE any class that
	// reads RNRD_OPT_KEY / DataForSEO password, so the decryption filter is
	// registered when the read happens.
	RNRD_Crypto::init();

	// v1.1.0 — Cloudflare auto-fix (Settings → Cloudflare) + REST endpoints for
	// connect / disconnect. Lives outside RNRD_Admin so the REST routes
	// register on every admin AND front-end request.
	RNRD_Cloudflare::init();

	// Performance: admin-only modules register nothing the front end uses
	// (admin_menu, admin_init, admin_notices, meta boxes, list columns). Only
	// boot them in the admin so their large classes are never autoloaded /
	// parsed on a public page load — that overhead was hurting front-end TTFB.
	// is_admin() is true for wp-admin, admin-ajax, and the block-editor
	// meta-box save POST, so the settings UI + meta box keep working.
	if ( is_admin() ) {
		RNRD_Admin::init();
		RNRD_Metabox::init();       // Post-edit Summary / FAQ / Visibility boxes.
		RNRD_Welcome::init();          // 1-question onboarding flow on first activation.
		RNRD_Agent_Dashboard::init();  // Unified dashboard widget (admin only).
	}

	RNRD_Generator::init();
	RNRD_Summary::init();
	RNRD_Schema::init();
	RNRD_Block::init();
	RNRD_Rest::init();
	RNRD_Crawler_Access::init();
	RNRD_Robots::init();
	RNRD_Llms_Txt::init();
	RNRD_Integrations::init();     // Page builder + multilingual + WooCommerce integration filters.
	RNRD_Markdown::init();
	RNRD_OKF::init();              // Open Knowledge Format (OKF) bundle at /okf/.
	RNRD_Faq::init();
	RNRD_Author_Box::init();
	RNRD_Shortcode::init();    // [rankready_summary], [rankready_faq], [rankready_author].
	RNRD_Crawler_Log::init();

	// v1.2.0 — Agent Ready feature modules.
	RNRD_Snippet::init();          // <meta robots max-snippet:-1> per-post + sitewide.
	RNRD_AI_Referral::init();      // Track AI-referrer visits (ChatGPT/Perplexity/etc).
	RNRD_Freshness::init();        // REST + bulk dateModified refresh.
	RNRD_MCP::init();              // WebMCP — WordPress Abilities API + /.well-known/mcp.json.
	// v1.2.0-rc.5 — Live endpoint probes + conflict detection. Register the REST
	// hook by class-name string so the 82KB diagnostics class only autoloads when
	// rest_api_init actually fires (REST requests) — never on a public page load.
	add_action( 'rest_api_init', array( 'RNRD_Diagnostics', 'register_routes' ) );
	RNRD_Cache::init();            // FREE-99 — cache compat init (Autoptimize asset excludes, etc).

	/**
	 * Fires after every core RankReady class has booted.
	 *
	 * The Pro addon attaches its own classes here so it has a guaranteed-safe
	 * moment to call the Free classes it composes with (RNRD_Generator,
	 * RNRD_Faq, etc.) without race conditions or order dependencies.
	 *
	 * @since 1.0.1
	 */
	do_action( 'rnrd_loaded' );

	// Free tier limits — REST endpoint for admin JS usage display.
	add_action( 'rest_api_init', array( 'RNRD_Limits', 'register_rest' ) );

	if ( did_action( 'elementor/loaded' ) ) {
		// Dedicated "RankReady" panel category so all three widgets group
		// together (mirrors the Gutenberg block category — keep both in sync).
		add_action( 'elementor/elements/categories_registered', function ( $elements_manager ): void {
			$elements_manager->add_category( 'rankready', array(
				'title' => esc_html__( 'RankReady', 'rankready-ai-llm-seo' ),
				'icon'  => 'eicon-bullet-list',
			) );
		} );

		add_action( 'elementor/widgets/register', function ( $widgets_manager ): void {
			require_once RNRD_DIR . 'includes/class-rnrd-elementor.php';
			$widgets_manager->register( new RNRD_Elementor_Widget() );

			require_once RNRD_DIR . 'includes/class-rnrd-elementor-faq.php';
			$widgets_manager->register( new RNRD_Elementor_Faq_Widget() );

			require_once RNRD_DIR . 'includes/class-rnrd-elementor-author-box.php';
			$widgets_manager->register( new RNRD_Elementor_Author_Box_Widget() );
		} );

		// NOTE: the front-end stylesheet is NOT enqueued globally here. Each
		// widget declares get_style_depends() => ['rankready-style'], so Elementor
		// loads the (registered) CSS only on pages that actually render a
		// RankReady widget. Keeps every other Elementor page byte-for-byte clean.
	}
} );

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( RNRD_FILE, function (): void {
	// v1.2.0 — flag the one-shot post-activation redirect (onboarding for fresh
	// installs, main settings page when the wizard was already completed).
	if ( class_exists( 'RNRD_Welcome' ) ) {
		RNRD_Welcome::flag_activation();
	}

	if ( false === get_option( RNRD_OPT_POST_TYPES ) ) {
		update_option( RNRD_OPT_POST_TYPES, array( 'post' ) );
	}
	// v1.2.1 — deliberately NOT seeded here. Activation runs before the text
	// domain is reliably loaded, so writing a default would bake the English
	// string into the DB and a German site would render German bullets under an
	// English "Key Takeaways" heading. Leaving the option unset lets every read
	// site fall back to __( 'Key Takeaways' ), which resolves in the site's
	// language. Existing installs already hold a value and are untouched.
	if ( false === get_option( RNRD_OPT_SHOW_LABEL ) ) {
		update_option( RNRD_OPT_SHOW_LABEL, true );
	}
	if ( false === get_option( RNRD_OPT_HEADING_TAG ) ) {
		update_option( RNRD_OPT_HEADING_TAG, 'h4' );
	}
	// Create crawler access log table.
	RNRD_Crawler_Log::create_table();

	if ( false === get_option( RNRD_OPT_LLMS_ENABLE ) ) {
		update_option( RNRD_OPT_LLMS_ENABLE, 'off' );
	}
	if ( false === get_option( RNRD_OPT_MD_ENABLE ) ) {
		update_option( RNRD_OPT_MD_ENABLE, 'off' );
	}
	if ( false === get_option( RNRD_OPT_MCP_ENABLE ) ) {
		update_option( RNRD_OPT_MCP_ENABLE, 'off' );
	}
	if ( false === get_option( RNRD_OPT_MD_HOME_ENABLE ) ) {
		update_option( RNRD_OPT_MD_HOME_ENABLE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_ROBOTS_ENABLE ) ) {
		update_option( RNRD_OPT_ROBOTS_ENABLE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_ROBOTS_CRAWLERS ) ) {
		update_option( RNRD_OPT_ROBOTS_CRAWLERS, array_keys( RNRD_Crawler_Access::get_llm_crawlers() ) );
	}
	if ( false === get_option( RNRD_OPT_FAQ_COUNT ) ) {
		update_option( RNRD_OPT_FAQ_COUNT, 5 );
	}
	if ( false === get_option( RNRD_OPT_FAQ_HEADING_TAG ) ) {
		update_option( RNRD_OPT_FAQ_HEADING_TAG, 'h3' );
	}
	if ( false === get_option( RNRD_OPT_SUMMARY_ENABLE ) ) {
		update_option( RNRD_OPT_SUMMARY_ENABLE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_FAQ_ENABLE ) ) {
		update_option( RNRD_OPT_FAQ_ENABLE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_FAQ_AUTO_DISPLAY ) ) {
		update_option( RNRD_OPT_FAQ_AUTO_DISPLAY, 'off' );
	}
	if ( false === get_option( RNRD_OPT_FAQ_SHOW_REVIEWED ) ) {
		update_option( RNRD_OPT_FAQ_SHOW_REVIEWED, 'on' );
	}
	if ( false === get_option( RNRD_OPT_FAQ_AUTO_GENERATE ) ) {
		update_option( RNRD_OPT_FAQ_AUTO_GENERATE, 'off' );
	}
	// Author Box defaults.
	if ( false === get_option( RNRD_OPT_AUTHOR_ENABLE ) ) {
		update_option( RNRD_OPT_AUTHOR_ENABLE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_AUTO_DISPLAY ) ) {
		update_option( RNRD_OPT_AUTHOR_AUTO_DISPLAY, 'off' );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_LAYOUT ) ) {
		update_option( RNRD_OPT_AUTHOR_LAYOUT, 'card' );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_HEADING ) ) {
		update_option( RNRD_OPT_AUTHOR_HEADING, 'About the Author' );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_HEADING_TAG ) ) {
		update_option( RNRD_OPT_AUTHOR_HEADING_TAG, 'h3' );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_SCHEMA_ENABLE ) ) {
		update_option( RNRD_OPT_AUTHOR_SCHEMA_ENABLE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_POST_TYPES ) ) {
		update_option( RNRD_OPT_AUTHOR_POST_TYPES, array( 'post' ) );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_TRUST_ENABLE ) ) {
		update_option( RNRD_OPT_AUTHOR_TRUST_ENABLE, 'off' );
	}

	// Front-end toggles read on every request. Seed autoloaded rows so a
	// default install does not pay a SELECT per missing option (O-4).
	// MCP enable and author auto-display are already seeded above.
	if ( false === get_option( RNRD_OPT_AI_REFERRAL_ENABLE ) ) {
		update_option( RNRD_OPT_AI_REFERRAL_ENABLE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_LLMS_USE_MD_URLS ) ) {
		update_option( RNRD_OPT_LLMS_USE_MD_URLS, 'on' );
	}
	if ( false === get_option( RNRD_OPT_LLMS_FULL_ENABLE ) ) {
		update_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' );
	}
	if ( false === get_option( RNRD_OPT_MD_POST_TYPES ) ) {
		update_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
	}
	if ( false === get_option( RNRD_OPT_MD_ACCEPT_NEGOTIATION ) ) {
		update_option( RNRD_OPT_MD_ACCEPT_NEGOTIATION, 'on' );
	}
	if ( false === get_option( RNRD_OPT_MD_BOT_AUTO_SERVE ) ) {
		update_option( RNRD_OPT_MD_BOT_AUTO_SERVE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_MD_HINT_DIV ) ) {
		update_option( RNRD_OPT_MD_HINT_DIV, 'on' );
	}
	if ( false === get_option( RNRD_OPT_AUTO_DISPLAY ) ) {
		update_option( RNRD_OPT_AUTO_DISPLAY, 'off' );
	}
	if ( false === get_option( RNRD_OPT_MAX_SNIPPET_DEFAULT ) ) {
		update_option( RNRD_OPT_MAX_SNIPPET_DEFAULT, 'on' );
	}
	if ( false === get_option( RNRD_OPT_SCHEMA_ARTICLE ) ) {
		update_option( RNRD_OPT_SCHEMA_ARTICLE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_SCHEMA_SPEAKABLE ) ) {
		update_option( RNRD_OPT_SCHEMA_SPEAKABLE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_SCHEMA_FAQ ) ) {
		update_option( RNRD_OPT_SCHEMA_FAQ, 'on' );
	}

	// Register rewrite rules before flushing so they get written.
	RNRD_Llms_Txt::add_rewrite_rules();
	RNRD_Markdown::add_rewrite_rules();
	RNRD_OKF::add_rewrite_rules();
	RNRD_MCP::add_rewrite_rules();
	flush_rewrite_rules();

	// Sync to physical robots.txt if one exists.
	RNRD_Robots::sync_physical_robots_txt();

	// rc.16 audit fix C2 + M3 — persist exclusions to every cache plugin's
	// saved option so LSWS / FastCGI / WPSC honour our bypass BEFORE PHP runs.
	// Runtime filters alone aren't enough — server-level caches read the
	// persisted option before WordPress boots.
	if ( class_exists( 'RNRD_Cache' ) ) {
		RNRD_Cache::persist_exclusions( array(
			'/llms.txt',
			'/llms-full.txt',
			'/.well-known/mcp.json',
			'.md',
		) );
		// Unconditional purge — covers the case where a stale cache existed
		// before RankReady was activated.
		RNRD_Cache::purge_url( home_url( '/robots.txt' ) );
		RNRD_Cache::purge_url( home_url( '/llms.txt' ) );
		RNRD_Cache::purge_url( home_url( '/llms-full.txt' ) );
		RNRD_Cache::purge_url( home_url( '/.well-known/mcp.json' ) );
	}

	// NOTE: the HowTo/ItemList schema scanner cron (RNRD_SCHEMA_CRON_HOOK) is a
	// PRO engine. The Pro add-on (RNRD_Pro_Schema) schedules it on its own init.
	// The Free build no longer schedules it — the constant stays defined so the
	// deactivation cleanup below can still clear any leftover event.
} );

register_deactivation_hook( RNRD_FILE, function (): void {
	$timestamp = wp_next_scheduled( RNRD_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, RNRD_CRON_HOOK );
	}
	wp_clear_scheduled_hook( 'rnrd_async_faq_generate' );
	wp_clear_scheduled_hook( RNRD_SCHEMA_CRON_HOOK );

	// v1.2.0-beta.4 — clear cron hooks that were uncovered in beta.3 audit #9.
	wp_clear_scheduled_hook( 'rnrd_crawler_log_prune' );  // daily prune was leaving zombie queries against a (possibly dropped) table.
	wp_clear_scheduled_hook( RNRD_CRON_BULK_STARTOVER );
	wp_clear_scheduled_hook( RNRD_CRON_BULK_FAQ );
	wp_clear_scheduled_hook( RNRD_CRON_BULK_SUMMARY );
	update_option( RNRD_BULK_RUNNING, false );
	update_option( RNRD_BAC_RUNNING, false );
	update_option( RNRD_FAQ_RUNNING, false );
	update_option( RNRD_SCHEMA_RUNNING, false );
	delete_transient( RNRD_LLMS_CACHE_KEY );
	delete_transient( RNRD_LLMS_FULL_CACHE_KEY );

	// Clean up RankReady block from physical robots.txt on deactivation.
	$robots_file = ABSPATH . 'robots.txt';
	if ( file_exists( $robots_file ) ) {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( WP_Filesystem() && $wp_filesystem->exists( $robots_file ) && $wp_filesystem->is_writable( $robots_file ) ) {
			$contents = $wp_filesystem->get_contents( $robots_file );
			if ( false !== $contents && false !== strpos( $contents, 'RankReady' ) ) {
				$contents = RNRD_Robots::strip_rankready_robots_block( $contents );
				$contents = rtrim( $contents ) . "\n";
				$wp_filesystem->put_contents( $robots_file, $contents, FS_CHMOD_FILE );
			}
		}
	}

	flush_rewrite_rules();
} );
