<?php
/**
 * Plugin Name:       RankReady – AI SEO, Schema, llms.txt, AEO and GEO for ChatGPT, Gemini and Perplexity
 * Plugin URI:        https://hostmy.blog/plugins/rankready/
 * Description:       Make your WordPress content readable by ChatGPT, Perplexity, Claude, Gemini, and Google AI Overviews. AI summaries, FAQ schema, llms.txt, Markdown endpoints, agent discovery headers, WebMCP, and crawler controls — in one plugin.
 * Version:           1.4.0
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
                /*
                 * ADD-ON MODEL. Free and Pro are two separate plugins, always.
                 *
                 * Free is the WordPress.org plugin and is the only thing that
                 * ever ships there. Pro is a SEPARATE plugin (`rankready-pro`)
                 * that requires Free to be active and extends it through hooks.
                 * Free is never replaced, never swapped for a paid build, and
                 * a paying user runs both plugins side by side.
                 *
                 * This is Aditya's standing decision, restated 2026-09-07. An
                 * earlier pass moved this to Freemius's premium-version model
                 * (one product, paid build replaces free) on the strength of the
                 * dashboard's "Paid version slug" field. That was the wrong read
                 * of a product decision that was never mine to make.
                 *
                 * `has_addons` is true so the SDK knows a separate paid product
                 * exists and the Account screen can surface its license.
                 */
                'has_addons'          => true,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                'menu'                => array(
                    'slug'           => 'rankready-ai-llm-seo',
                    'first-path'     => 'admin.php?page=rankready-ai-llm-seo',
                    // Account and pricing must be reachable once plans exist,
                    // otherwise a buyer has nowhere to manage or start a license.
                    'account'        => true,
                    'pricing'        => true,
                    // Still no Add-Ons menu row. The add-on exists, but Upgrade
                    // Pro is the single destination we point people at, and two
                    // sidebar rows for one place is clutter. `has_addons` above
                    // is about the SDK knowing the product exists, not about
                    // growing the menu.
                    'addons'         => false,
                    'contact'        => false,
                    'support'        => false,
                ),
            ) );

            // Diagnostic telemetry is opt-in, never on by default. Forced opt-in
            // is the most-complained-about Freemius behaviour and this plugin has
            // already been rejected twice on review.
            $rnrd_fs->add_filter( 'permission_diagnostic_default', '__return_false' );

            $rnrd_fs->add_filter( 'permission_extensions_default', '__return_false' );

            /*
             * Deactivation survey, shown to EVERYONE.
             *
             * The SDK only offers it to users who opted in, which for a plugin
             * whose telemetry is opt-out by default means almost nobody: the
             * modal that appeared on deactivate was "Opt Out", not the feedback
             * form. Forcing it on means the one moment somebody tells you why
             * they are leaving is actually captured.
             *
             * It stays skippable. The form has a close button and a plain
             * "Skip & Deactivate", so nobody is held hostage to answer, which is
             * the version of this pattern that earns bad reviews.
             */
            /*
             * FREE ONLY. A paying customer who deactivates does not owe us a
             * survey, and asking is the wrong move twice over: they already
             * told us they valued this enough to pay, and a support
             * conversation beats a radio button. If someone with a license
             * turns the plugin off, that is a support signal, not research.
             *
             * `is_paying()` covers an active license; `is_trial()` covers
             * someone mid-trial, who is also not a free user. Wrapped in a
             * function_exists guard so a partially booted SDK cannot fatal
             * the Plugins screen, which is the worst place to crash.
             */
            $rnrd_fs->add_filter(
                'show_deactivation_feedback_form',
                function () use ( $rnrd_fs ) {
                    if ( ! method_exists( $rnrd_fs, 'is_paying' ) ) {
                        return true;
                    }
                    return ! ( $rnrd_fs->is_paying() || $rnrd_fs->is_trial() );
                }
            );

            // Never hold up an uninstall on a network call. If Freemius is
            // unreachable the plugin still deactivates.
            $rnrd_fs->add_filter( 'deactivate_on_activation', '__return_false' );

            /*
             * The opt-in is offered ONCE, on the activation screen. It does not then
             * follow the user around their own site.
             *
             * Freemius pins a sticky "We made a few tweaks to the plugin, Opt in to make
             * ... better!" notice to the top of EVERY wp-admin screen for anyone who did
             * not opt in: Dashboard, Plugins, Posts, Users, Settings, all of them, not
             * just RankReady's. Verified in a running WordPress, on all four. It is
             * dismissible, and it is still the single most-complained-about thing about
             * Freemius, and WordPress.org's own guidelines say a plugin's notices belong
             * on that plugin's screens.
             *
             * remove_sticky() is the SDK's own public API for this (class-freemius.php),
             * not a hack around it. The opt-in itself is untouched: the activation screen
             * still asks, Account still offers it, and anyone who already opted in is
             * unaffected. What goes away is being asked again on every page forever
             * after answering no once.
             *
             * IMPORTANT: NOT anonymous_mode. That would suppress the notice too, by skipping the
             * opt-in entirely, and that is a business call about install analytics and
             * the mailing list, not a UX fix. Aditya makes that one, not this file.
             */
            add_action(
                'admin_init',
                function () use ( $rnrd_fs ) {
                    if ( method_exists( $rnrd_fs, 'remove_sticky' ) ) {
                        $rnrd_fs->remove_sticky( 'connect_account' );
                    }
                },
                5
            );

            // Sidebar reads "Upgrade Pro", not a bare "Upgrade" with an arrow.
            //
            // There is no `pricing_menu_title` filter in the SDK; the label comes
            // from the translatable string keyed `upgrade` (class-freemius.php,
            // get_pricing_cta_label). override_i18n is the supported way to change
            // it. The arrow is a separate, unfiltered concatenation, so it has to
            // be taken off the rendered submenu entry afterwards.
            // Deferred to `init` on purpose. rnrd_fs() runs at file scope during
            // plugin load, and calling __() there trips WordPress 6.7+'s
            // _load_textdomain_just_in_time notice, which Plugin Check reports.
            // Freemius registers the submenu at admin_menu priority 999999999,
            // long after init, so the label is still in place when it renders.
            add_action(
                'init',
                function () use ( $rnrd_fs ) {
                    $rnrd_fs->override_i18n( array( 'upgrade' => __( 'Upgrade Pro', 'rankready-ai-llm-seo' ) ) );
                }
            );

            // Every Freemius upgrade link goes to OUR upgrade screen.
            //
            // GitHub issue #49: the "Upgrade Pro" link Freemius adds to the
            // plugin row on plugins.php was built from get_upgrade_url(), so it
            // pointed at admin.php?billing_cycle=annual&page=rankready-ai-llm-seo-pricing,
            // the Freemius pricing page the sidebar entry was already rerouted
            // away from (see the admin_menu rewrite of the `-pricing` submenu).
            // pricing_url is the SDK's own filter at the end of pricing_url(),
            // which get_upgrade_url() and get_trial_url() both go through, so
            // this one hook covers the row link and every other Freemius CTA.
            $rnrd_fs->add_filter(
                'pricing_url',
                function ( $url ) {
                    return function_exists( 'rnrd_upgrade_url' ) ? rnrd_upgrade_url( 'freemius' ) : $url;
                }
            );
        }

        return $rnrd_fs;
    }

    if ( false !== rnrd_fs() ) {
        do_action( 'rnrd_fs_loaded' );
    }
}

if ( ! function_exists( 'rnrd_repair_freemius_asset_url' ) ) {
	/**
	 * Repair a Freemius stylesheet or script URL the SDK built from a filesystem path.
	 *
	 * GitHub issues #47 and #48: the deactivation feedback modal and the license
	 * activation modal rendered as plain markup below the admin footer. Their
	 * layout lives in the SDK's dialog-boxes.css, and on those installs that file
	 * 404'd. The cause is fs_asset_url() in the SDK. When the plugin folder is a
	 * symlink whose name differs from its target (a development checkout linked
	 * in as `rankready-ai-llm-seo` -> `.../rankready-free`), start.php cannot
	 * resolve the link, WP_FS__DIR stays the real path outside WP_PLUGIN_DIR, and
	 * fs_asset_url() falls through to its theme branch:
	 *
	 *   /wp-content/themes//srv/src/rankready-free/vendor/freemius/assets/css/admin/dialog-boxes.css
	 *
	 * Reproduced in the lab 2026-09-29 with exactly that symlink. plugins_url()
	 * does resolve symlinked plugins (core registers their real paths at load),
	 * so the URL is rebuilt through it. A normal install never matches: its URLs
	 * never contain the absolute SDK path, so they are returned untouched.
	 *
	 * @param string|false $src Asset URL from the style/script loader.
	 * @return string|false
	 */
	function rnrd_repair_freemius_asset_url( $src ) {
		if ( ! is_string( $src ) || '' === $src || ! defined( 'WP_FS__DIR' ) ) {
			return $src;
		}
		$sdk = wp_normalize_path( WP_FS__DIR );
		$pos = strpos( $src, $sdk );
		if ( false === $pos ) {
			return $src;
		}
		// Only this plugin's own SDK copy; another plugin's copy is its problem.
		$root = untrailingslashit( wp_normalize_path( __DIR__ ) );
		if ( 0 !== strpos( $sdk, $root . '/' ) ) {
			return $src;
		}
		$rel = substr( $sdk, strlen( $root ) + 1 ) . substr( $src, $pos + strlen( $sdk ) );
		return plugins_url( $rel, __FILE__ );
	}

	if ( is_admin() ) {
		add_filter( 'style_loader_src', 'rnrd_repair_freemius_asset_url', 10 );
		add_filter( 'script_loader_src', 'rnrd_repair_freemius_asset_url', 10 );
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
	define( 'RNRD_VERSION',  '1.4.0' );
	define( 'RNRD_FILE',     __FILE__ );
	define( 'RNRD_DIR',      plugin_dir_path( __FILE__ ) );
	define( 'RNRD_URL',      plugin_dir_url( __FILE__ ) );
	define( 'RNRD_BASENAME', plugin_basename( __FILE__ ) );

	// Free build has NO monthly caps. Manual AI Summary + FAQ generation is
	// unlimited. Auto-generate on publish and Bulk regeneration are not capped
	// either: they are Pro features, shipped in the separate rankready-pro
	// plugin, and Free shows them as locked rather than as unfinished work.
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

	// rc.11 — Hide "Generated from RankReady" credit line. SHIPPED, not a
	// placeholder: RNRD_LLMs_Txt::should_hide_branding() reads this option and
	// honours it whenever Pro is licensed. This comment used to say the option
	// was ignored, which stopped being true two releases ago and made a built
	// paid feature look unfinished to anyone reading the source.
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
	// Regeneration cooldown clock, kept apart from RNRD_META_GENERATED on purpose.
	// GENERATED is the UI's "this post has a summary, made at X" and Delete must
	// clear it. The cooldown must survive Delete, or Delete becomes a one-click way
	// round the 60s throttle and straight back onto the provider's meter.
	define( 'RNRD_META_COOLDOWN', '_rnrd_last_attempt' );
	define( 'RNRD_META_DISABLE',   '_rnrd_disable_summary' );

	// Meta keys — FAQ.
	define( 'RNRD_META_FAQ',           '_rnrd_faq' );
	define( 'RNRD_META_FAQ_HASH',      '_rnrd_faq_hash' );
	define( 'RNRD_META_FAQ_GENERATED',    '_rnrd_faq_generated' );
	define( 'RNRD_META_FAQ_COOLDOWN',     '_rnrd_faq_last_attempt' ); // survives Delete, see RNRD_META_COOLDOWN.
	define( 'RNRD_META_FAQ_DISABLE',      '_rnrd_faq_disable' );
	define( 'RNRD_META_FAQ_KEYWORD',      '_rnrd_faq_keyword' );
	define( 'RNRD_META_FAQ_LAST_FAILURE', '_rnrd_faq_last_failure' ); // v1.2.0 — circuit-breaker timestamp.

	// Cron.
	define( 'RNRD_CRON_HOOK', 'rnrd_async_generate' );

	// Most posts one bulk run will queue. The queue is an option holding post
	// IDs; the cap keeps it and the per-tick read bounded on very large sites.
	// At the cap the option is about 158 KB and each claim rewrites it, measured
	// at 11 ms in the lab (2026-09-28), which is small beside the provider call
	// that follows every claim. The Pro bulk screens show the real matching
	// count beside a capped run. The Free /startover-bulk/start route returns it
	// as `available` for API callers; no Free screen calls that route.
	define( 'RNRD_BULK_MAX_POSTS', 10000 );

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

	/**
	 * Where an upgrade prompt sends the user.
	 *
	 * Prefers the Freemius pricing screen inside wp-admin, because a license
	 * bought there activates itself and the buyer never handles a key. Falls
	 * back to the public pricing page when the SDK is unavailable, which is the
	 * case on a site that skipped the opt-in.
	 *
	 * @param string $context Short slug naming the surface the click came from,
	 *                        so the upgrade page can report what people click.
	 */
	/**
	 * Where "Free vs Pro" points. A comparison is a different intent from a
	 * price: someone clicking it is not ready to buy, they are checking whether
	 * the paid thing is even relevant to them. Sending both to the same page
	 * loses that reader.
	 */
	function rnrd_compare_url( string $context = '' ): string {
		$url = 'https://hostmy.blog/plugins/rankready/#free-vs-pro';
		return $context ? add_query_arg( 'from', rawurlencode( $context ), $url ) : $url;
	}

	/**
	 * The inline gate shown next to a control a free site cannot use.
	 *
	 * Deliberately quiet: one sentence saying why THIS feature is paid, one
	 * action, one comparison link. Modelled on Relume's inline gate rather than
	 * on a marketing banner, because this sits beside a checkbox in a settings
	 * screen and anything louder reads as an advert.
	 *
	 * @param string $why     One sentence. Specific to the feature, never generic.
	 * @param string $context Slug for upgrade attribution.
	 */
	/**
	 * A field-level Pro note. One line, inline, sitting where a `.description`
	 * would sit.
	 *
	 * This used to render a bordered full-width bar with two sentences and a
	 * solid button, attached to a single checkbox row. That is the treatment
	 * competitors reserve for a whole locked feature area, not for one field,
	 * and next to a pair of checkboxes it read as an advert wedged into a form.
	 *
	 * What the two biggest plugins in this category actually ship, read from
	 * their current WordPress.org builds rather than from memory:
	 *
	 *   Yoast SEO      `.yoast-badge` + `.yoast-premium-badge`: a 10px inline
	 *                  pill, background #fff3cd on #674e00 text, sitting beside
	 *                  the label. No prose, no button. Fields carry a
	 *                  `has_premium_badge` boolean, so the flag is per field.
	 *   Rank Math      `.rank-math-pro-cta` is a box, and only ever wraps a
	 *                  whole feature area. Its inner button is even
	 *                  `pointer-events: none`, so it is decoration, not a CTA.
	 *
	 * So: a pill plus one clause for a field, and the panel (rnrd_pro_panel)
	 * stays for a whole feature. The link is kept, unlike Yoast, because a flag
	 * with no way to find out what it means is its own small frustration.
	 *
	 * @param string $why     One short clause. Not a sentence, not two.
	 * @param string $context Attribution slug for the upgrade URL.
	 */
	function rnrd_gate( string $why, string $context = 'gate' ): void {
		?>
		<p class="rnrd-gate">
			<span class="rnrd-gate__text"><?php echo wp_kses_post( $why ); ?></span>
			<?php
			/*
			 * The tag is LAST in the markup and pinned right in CSS, and it is
			 * itself the link. It used to lead the sentence with a separate
			 * "Upgrade . Free vs Pro" pair trailing it, which put three
			 * clickable things on one annotation line and left the marker at a
			 * different x-position on every row. One target, one column.
			 */
			?>
			<a class="rnrd-gate__tag" href="<?php echo esc_url( rnrd_upgrade_url( $context ) ); ?>"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Documentation base. One place, so a domain move is one edit.
	 *
	 * Docs live at /docs/{product}/{article}/ on hostmy.blog and are a separate
	 * surface from the sales page: /plugins/rankready/ converts, /docs/rankready/
	 * educates. See the docs architecture note before changing this shape; the
	 * section is a taxonomy and is deliberately NOT part of the path.
	 */
	function rnrd_docs_base(): string {
		return 'https://hostmy.blog/docs/rankready/';
	}

	/**
	 * Every admin screen mapped to the doc that answers it.
	 *
	 * Keys are `tab` or `tab/sub`, matching the admin router exactly. Every slug
	 * in this map was checked against the live site and returned 200; a link to a
	 * doc that does not exist is worse than no link, because it teaches people
	 * the Help link is broken and they stop trying it.
	 *
	 * @return array<string,string>
	 */
	function rnrd_doc_map(): array {
		return array(
			'dashboard'          => 'what-rankready-does',
			'crawlers'           => 'supported-ai-crawlers',
			'crawlers/brand'     => 'brand-terms-setup',
			'crawlers/llms'      => 'how-to-add-llms-txt-wordpress',
			'crawlers/markdown'  => 'wordpress-markdown-for-ai',
			'crawlers/okf'       => 'what-is-open-knowledge-format',
			'crawlers/robots'    => 'content-signals-robots-txt',
			'crawlers/webmcp'    => 'how-to-add-webmcp-wordpress',
			'content'            => 'make-post-ai-ready',
			'content/summary'    => 'generate-ai-summaries-wordpress',
			'content/faq'        => 'how-to-add-faq-schema-wordpress',
			'content/author'     => 'how-to-add-eeat-author-box',
			'content/schema'     => 'add-article-speakable-schema',
			'insights'           => 'check-rankready-is-working',
			'insights/bot-activity' => 'see-which-ai-crawlers-visit',
			'insights/citation'  => 'what-citation-candidate-means',
			'insights/freshness' => 'check-rankready-is-working',
			'insights/referral'  => 'track-chatgpt-perplexity-traffic',
			'settings'           => 'settings-reference',
			'settings/api-keys'  => 'do-you-need-api-key',
			'settings/cloudflare' => 'rankready-caching-cdn-fix',
			'settings/advanced'  => 'hooks-and-filters',
		);
	}

	/**
	 * URL for one doc, or the docs hub when no slug is given.
	 *
	 * @param string $slug    Article slug, or '' for the RankReady docs hub.
	 * @param string $context Attribution, so it is visible which screen sends
	 *                        readers to which doc.
	 */
	function rnrd_doc_url( string $slug = '', string $context = '' ): string {
		$url = rnrd_docs_base() . ( $slug ? rawurlencode( $slug ) . '/' : '' );

		/*
		 * UTM on our own docs, so it is possible to see which admin screen
		 * actually sends people to the documentation and which articles nobody
		 * ever opens. That is a product question the plugin cannot answer any
		 * other way: nothing is tracked locally and nothing is phoned home.
		 *
		 * These are ordinary query parameters on a first-party URL, which is
		 * what every WordPress plugin's help link carries. No identifier of the
		 * site or the user is included, and `context` is a screen slug we wrote,
		 * never anything the user typed.
		 */
		$args = array(
			'utm_source'   => 'rankready-plugin',
			'utm_medium'   => 'admin-help',
			'utm_campaign' => 'docs',
		);
		if ( '' !== $context ) {
			$args['utm_content'] = rawurlencode( $context );
		}

		return add_query_arg( $args, $url );
	}

	/**
	 * The doc slug for the screen being viewed, falling back up the hierarchy:
	 * `tab/sub`, then `tab`, then the hub.
	 */
	function rnrd_doc_for_screen(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen check; nothing is written.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$sub = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$map = rnrd_doc_map();
		if ( $sub && isset( $map[ $tab . '/' . $sub ] ) ) {
			return $map[ $tab . '/' . $sub ];
		}
		return $map[ $tab ] ?? '';
	}

	/**
	 * A documentation link that sits beside the thing it explains.
	 *
	 * Real products do not put a "read the docs for this screen" bar at the top
	 * and leave you to work out which paragraph applies. They put "Learn more"
	 * next to the option, at the moment the question occurs. This renders that:
	 * a small inline link, placed at the setting, naming what it will explain.
	 *
	 * @param string $slug  Article slug. Verified against the live site.
	 * @param string $label Link text. Defaults to "Learn more", but "See how it
	 *                      works" reads better on a feature than on a checkbox,
	 *                      so callers pass what fits.
	 */
	function rnrd_doc_link( string $slug, string $label = '' ): string {
		if ( '' === $slug ) {
			return '';
		}

		/*
		 * ONE help affordance, worded the same way on every screen.
		 *
		 * These links used to carry 32 different labels across 35 call sites:
		 * "What this does", "How to start", "Which crawlers", "Cache setup",
		 * "About llms.txt", "Common errors". Each one read fine on its own and
		 * the set read like six different products. A reader cannot learn where
		 * help lives if help is dressed differently every time they meet it.
		 *
		 * So the visible word is always "Help" and it always looks the same.
		 * The specific promise is not thrown away: it becomes the link's
		 * accessible name and its tooltip, so a screen reader announces
		 * "Help: How summaries work" and a hover shows the same. Callers keep
		 * passing their specific label; it just stops being the visible text.
		 */
		$topic   = '' !== $label ? $label : __( 'Read the documentation', 'rankready-ai-llm-seo' );
		$visible = __( 'Help', 'rankready-ai-llm-seo' );

		return sprintf(
			'<a class="rnrd-doc-link" href="%1$s" target="_blank" rel="noopener noreferrer" title="%2$s" aria-label="%3$s">%4$s<span class="dashicons dashicons-external rnrd-extlink" aria-hidden="true"></span></a>',
			esc_url( rnrd_doc_url( $slug, 'inline-' . $slug ) ),
			esc_attr( $topic ),
			esc_attr(
				sprintf(
					/* translators: %s: what the linked article explains, e.g. "How summaries work" */
					__( 'Help: %s (opens in a new tab)', 'rankready-ai-llm-seo' ),
					$topic
				)
			),
			esc_html( $visible )
		);
	}

	/** Echoing wrapper, for use inside templates. */
	function rnrd_doc( string $slug, string $label = '' ): void {
		/*
		 * wp_kses_post() drops `aria-label` and `title`, which is exactly the
		 * information this link now depends on, so the allow-list is explicit.
		 * Learned the hard way earlier in this release: wp_kses_post() silently
		 * stripped an <input> and left a gated row with no checkbox at all.
		 */
		echo wp_kses(
			rnrd_doc_link( $slug, $label ),
			array(
				'a'    => array(
					'class'      => array(),
					'href'       => array(),
					'target'     => array(),
					'rel'        => array(),
					'title'      => array(),
					'aria-label' => array(),
				),
				'span' => array(
					'class'       => array(),
					'aria-hidden' => array(),
				),
			)
		);
	}

	function rnrd_upgrade_url( string $context = '' ): string {
		if ( function_exists( 'admin_url' ) && is_admin() ) {
			$url = admin_url( 'admin.php?page=rankready-pro-upgrade' );
			return $context ? add_query_arg( 'rnrd_from', rawurlencode( $context ), $url ) : $url;
		}
		$fallback = 'https://hostmy.blog/plugins/rankready/#pricing';
		return $context ? add_query_arg( 'from', rawurlencode( $context ), $fallback ) : $fallback;
	}

	/**
	 * The priority RankReady registers its `template_redirect` handlers at.
	 *
	 * FREE-99 / issue #41 — every RankReady handler on `template_redirect`
	 * (llms.txt, llms-full.txt, OKF, MCP manifest, the .md router, the Accept:
	 * text/markdown negotiator) used to sit at a small hardcoded priority (0-2)
	 * chosen only to beat WordPress core and a couple of named page builders.
	 * A stock site running Bricks, Oxygen, Cwicly or any other builder/mu-plugin
	 * that also hooks `template_redirect` early can still register before us,
	 * intercept the request, and serve HTML where an AI crawler expected plain
	 * text or markdown — and the only fix available to the user was asking the
	 * OTHER plugin's author to change their priority, which is not something
	 * RankReady can promise or control.
	 *
	 * Defaulting to a very low (very early) number instead — filterable, so a
	 * site with a genuinely earlier requirement can push it lower still —
	 * removes the race in the overwhelming majority of installs by construction,
	 * rather than by asking every other plugin author to cooperate.
	 *
	 * @param int $offset Added to the base priority. RankReady's five
	 *                    `template_redirect` registrations each pass a small
	 *                    distinct offset (0-2) so their relative firing order
	 *                    among themselves is preserved (e.g. OKF before the
	 *                    markdown router, which is before the Accept-header
	 *                    negotiator).
	 * @return int
	 */
	function rnrd_template_redirect_priority( int $offset = 0 ): int {
		return (int) apply_filters( 'rnrd_template_redirect_priority', -9999 ) + $offset;
	}
}

/**
 * Resolve Auto-display to off|before|after.
 * Legacy Summary/FAQ stored 'on' plus a separate position option.
 * 'both' is Author Box only; maps to the feature's default position.
 */
/*
 * These two are wrapped in function_exists() and the rest of this file's
 * helpers are not, which looks inconsistent until you know why.
 *
 * PHP binds an UNCONDITIONAL top-level function at COMPILE time, before a
 * single line of the file executes. The duplicate-install guard higher up
 * returns early, and that early return cannot prevent a compile-time binding.
 * Every other rnrd_* helper here lives inside a conditional block, so it is
 * bound at runtime and the guard does protect it. These two sat at column
 * zero, so they were the only two the guard could not cover, and they were
 * exactly the two that fataled a site running two RankReady folders:
 *
 *   PHP Fatal error: Cannot redeclare rnrd_auto_display_mode()
 *
 * That is worse than an ordinary bug, because the guard's own admin notice
 * promises the duplicate "has been disabled automatically to prevent
 * conflicts" while the site is already white-screened. Reproduced in the lab
 * by activating the free and premium builds together, then fixed and
 * re-verified the same way.
 */
if ( ! function_exists( 'rnrd_auto_display_mode' ) ) {
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
}

if ( ! function_exists( 'rnrd_maybe_merge_auto_display_options' ) ) {
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
}

// ── Autoloader ────────────────────────────────────────────────────────────────
add_action( 'admin_init', function (): void {
	if ( class_exists( 'RNRD_Notices' ) ) {
		RNRD_Notices::init();
	}
} );

// Inside a hook, not at file scope: the autoloader that resolves RNRD_Pricing is
// registered further down this file, so a class_exists() at load time would run
// before it exists and silently skip the whole checkout layer.
add_action( 'init', function (): void {
	if ( class_exists( 'RNRD_Pricing' ) ) {
		RNRD_Pricing::init();
	}
} );

/**
 * Keep the RankReady menu open and highlighted on the Upgrade Pro screen.
 *
 * That screen is registered with a null parent so it does not add a second
 * visible row, and the cost of that is WordPress not knowing which menu it
 * belongs to: landing there collapsed the RankReady menu and highlighted
 * nothing, so it read as having left the plugin entirely.
 */
add_filter( 'parent_file', function ( $parent ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	return ( 'rankready-pro-upgrade' === $page ) ? 'rankready-ai-llm-seo' : $parent;
} );
add_filter( 'submenu_file', function ( $file ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	return ( 'rankready-pro-upgrade' === $page ) ? 'admin.php?page=rankready-pro-upgrade' : $file;
} );

/**
 * Our own Upgrade Pro screen, in place of the Freemius one.
 *
 * Freemius's stock pricing page lists paid plans only, so a reader cannot see
 * what they already have next to what they would be buying. This registers a
 * hidden submenu at the same slug Freemius uses, then points the sidebar item at
 * it, so "Upgrade Pro" opens a screen that shows Free beside Pro and checks out
 * without leaving WordPress.
 *
 * Registered with a null parent so it never adds a second visible row.
 */
add_action( 'admin_menu', function (): void {
	$hook = add_submenu_page(
		'',
		__( 'Upgrade Pro', 'rankready-ai-llm-seo' ),
		__( 'Upgrade Pro', 'rankready-ai-llm-seo' ),
		'manage_options',
		'rankready-pro-upgrade',
		function (): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to view this page.', 'rankready-ai-llm-seo' ) );
			}
			/*
			 * GitHub #46: core's common.js moves every .notice to just after
			 * the first `.wrap h1, .wrap h2` unless a .wp-header-end marker
			 * exists. This screen has no h1, so notices were inserted after the
			 * pricing <h2>, inside .rnrd-pricing. The marker, first child of
			 * .wrap, puts them above the pricing block instead. Same fix as the
			 * settings screen (class-rnrd-admin.php, v1.2.0).
			 */
			echo '<div class="wrap rnrd-wrap"><hr class="wp-header-end" />';
			if ( class_exists( 'RNRD_Pricing' ) ) {
				RNRD_Pricing::render();
			}
			echo '</div>';
		}
	);

	/*
	 * Name the browser tab. get_admin_page_title() only searches the top-level
	 * menu for a page with no parent, never finds this one, and returns null, so
	 * the tab read " < Site - WordPress" and admin-header.php passed null to
	 * strip_tags() (a deprecation on PHP 8.1+). Setting the title before the
	 * header loads is the only hook that runs early enough; the page, its URL
	 * and its admin_page_rankready-pro-upgrade hook suffix are unchanged.
	 */
	if ( $hook ) {
		add_action(
			'load-' . $hook,
			function (): void {
				global $title;
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- core reads $title for the tab; this page has no menu row to derive it from.
				$title = __( 'Upgrade Pro', 'rankready-ai-llm-seo' );
			}
		);
	}
}, 9 );

/**
 * Take the trailing arrow off our own "Upgrade Pro" submenu entry.
 *
 * Freemius appends `&nbsp;&nbsp;➤` to the pricing label unconditionally and
 * exposes no filter for it, so the only place to remove it is the rendered
 * $submenu array. Scoped by parent slug and by matching the arrow itself, so
 * this touches nothing but our entry and quietly does nothing if a future SDK
 * stops adding it.
 *
 * Hooked on admin_head, not admin_menu: Freemius registers this item at
 * priority WP_FS__LOWEST_PRIORITY (999999999), so any admin_menu callback we
 * could realistically register still runs before the item exists. admin_head
 * fires after every menu callback and before menu-header.php draws the sidebar.
 */
add_action( 'admin_head', function (): void {
	global $submenu;
	$parent = 'rankready-ai-llm-seo';

	if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
		return;
	}

	foreach ( $submenu[ $parent ] as $i => $item ) {
		if ( ! isset( $item[0] ) || false === strpos( $item[0], '&#x27a4;' ) ) {
			continue;
		}
		$submenu[ $parent ][ $i ][0] = trim( str_replace( array( '&#x27a4;', '&#x2190;', '&nbsp;' ), '', $item[0] ) );
	}

	/*
	 * Point "Upgrade Pro" at OUR upgrade screen, not Freemius's.
	 *
	 * Freemius registers its own `-pricing` submenu. That page lists paid plans
	 * only, shows the annual price divided by twelve ("$2.42 / mo"), and prints
	 * "No Support" for a plan whose support is simply not configured there. None
	 * of that is what we want to say. rankready-pro-upgrade shows Free beside Pro
	 * and checks out inline.
	 */
	foreach ( $submenu[ $parent ] as $i => $item ) {
		if ( isset( $item[2] ) && false !== strpos( (string) $item[2], '-pricing' ) ) {
			// The FULL path, not a bare slug. Freemius registers this row as a
			// link item, so WordPress uses item[2] verbatim as the href instead
			// of expanding it to admin.php?page=... A bare slug produced a
			// relative link that resolved against /wp-admin/ and 404'd.
			$submenu[ $parent ][ $i ][2] = 'admin.php?page=rankready-pro-upgrade';
		}
	}

	/*
	 * Row one is "Dashboard".
	 *
	 * add_menu_page() auto-generates a first submenu row that repeats the parent's
	 * name, so the sidebar read "RankReady / RankReady". Renaming it beats deleting
	 * it: WordPress derives the TOP-LEVEL link's target from the first submenu
	 * entry, and an earlier version that deleted this row made "RankReady" itself
	 * point at the pricing page, leaving the dashboard unreachable from the
	 * sidebar entirely.
	 */
	$dash = null;
	foreach ( $submenu[ $parent ] as $i => $item ) {
		if ( isset( $item[2] ) && $item[2] === $parent ) {
			$submenu[ $parent ][ $i ][0] = __( 'Dashboard', 'rankready-ai-llm-seo' );
			$dash = $submenu[ $parent ][ $i ];
			unset( $submenu[ $parent ][ $i ] );
			break;
		}
	}
	if ( null !== $dash ) {
		array_unshift( $submenu[ $parent ], $dash );
	}
	$submenu[ $parent ] = array_values( $submenu[ $parent ] );

	/*
	 * Row two says where this install actually stands, in four states:
	 *
	 *   Pro not installed                 "Upgrade Pro"    quiet dot
	 *   Pro installed, no license yet     "Activate Now"   amber dot, it needs action
	 *   Pro installed, license lapsed     "Renew license"  amber dot, matches the notice
	 *   Pro installed and licensed        "Activated"      green tick, nothing to do
	 *
	 * The middle state is the one worth calling out. Someone who has bought the
	 * Pro plugin and activated it but never entered their key sees an admin
	 * that looks identical to the free one, and the only honest place to say so
	 * is the row they are already looking at.
	 *
	 * Detection is `RNRD_PRO_VERSION`, the constant the separate Pro plugin
	 * defines the moment it loads. It used to look for a class from the
	 * short-lived premium-build experiment, which no longer exists: Free and Pro
	 * are two plugins and always will be.
	 */
	$is_pro           = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();
	$pro_installed    = defined( 'RNRD_PRO_VERSION' );

	if ( $is_pro ) {
		$label = __( 'Activated', 'rankready-ai-llm-seo' );
		// Empty span, drawn in CSS below, exactly like its `.rnrd-menu-dot`
		// sibling. This used to be the HTML entity `&#10003;`, which is why a
		// source grep for the `\u2713` character never found it and it survived
		// every previous emoji sweep. The runtime check in the visual harness
		// is what caught it.
		$mark  = '<span class="rnrd-menu-tick" aria-hidden="true"></span>';
		$state = 'is-active';
	} elseif ( $pro_installed && class_exists( 'RNRD_Notices' ) && RNRD_Notices::licence_expired() ) {
		/*
		 * Expired is not the same as never activated, and it used to share a
		 * branch with it. The notice at the top of the screen says "Renew
		 * license"; this row said "Activate Now" directly beneath it, which
		 * tells someone who already activated to do it again and reads as if
		 * the plugin mislaid their key. Same predicate as the notice.
		 */
		$label = __( 'Renew license', 'rankready-ai-llm-seo' );
		$mark  = '<span class="rnrd-menu-dot" aria-hidden="true"></span>';
		$state = 'needs-action';
	} elseif ( $pro_installed ) {
		$label = __( 'Activate Now', 'rankready-ai-llm-seo' );
		$mark  = '<span class="rnrd-menu-dot" aria-hidden="true"></span>';
		$state = 'needs-action';
	} else {
		$label = __( 'Upgrade Pro', 'rankready-ai-llm-seo' );
		$mark  = '<span class="rnrd-menu-dot" aria-hidden="true"></span>';
		$state = 'upgrade';
	}

	foreach ( $submenu[ $parent ] as $i => $item ) {
		if ( isset( $item[2] ) && false !== strpos( (string) $item[2], 'rankready-pro-upgrade' ) ) {
			$submenu[ $parent ][ $i ][0] = sprintf(
				'<span class="rnrd-menu-item %s">%s%s</span>',
				esc_attr( $state ),
				esc_html( $label ),
				$mark
			);
		}
	}

	/*
	 * On the Upgrade Pro screen, keep the RankReady menu EXPANDED as well as
	 * highlighted. parent_file marks it current, but WordPress only adds
	 * wp-menu-open for a page it registered under that parent, and this one is
	 * registered with a null parent so it does not create a second row. Without
	 * these classes the submenu collapses and the screen reads as though the
	 * reader has navigated out of the plugin.
	 */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
	$rnrd_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( 'rankready-pro-upgrade' === $rnrd_page ) {
		global $menu;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $i => $item ) {
				if ( isset( $item[2] ) && $item[2] === $parent ) {
					$menu[ $i ][4] = trim( ( $item[4] ?? '' ) . ' wp-has-current-submenu wp-menu-open' );
					break;
				}
			}
		}
	}

} );

/*
 * Admin-menu state styles.
 *
 * This used to be a raw style tag echoed from the admin_head callback above,
 * which is a straight WP.org rule 3 violation (no hardcoded style or script
 * tags printed from PHP) and one of the patterns the review team rejects on
 * sight. Caught by release gate check #3. The wording here deliberately avoids
 * the literal tag so the gate's own grep does not re-flag this comment.
 *
 * It lives on admin_enqueue_scripts rather than beside the menu code it belongs
 * to, and that separation is deliberate: admin_head fires AFTER WordPress has
 * already printed the head styles, so wp_add_inline_style() there is a silent
 * no-op. The menu array still has to be rewritten on admin_head (Freemius
 * registers its submenu at priority 999999999), so the two halves cannot share
 * a hook. Verified by checking the rendered page, not by reading the docs.
 *
 * The handle is registered with `false` as its src, so WordPress prints the CSS
 * in a <style> block it owns and issues no extra HTTP request.
 */
add_action( 'admin_enqueue_scripts', function (): void {
	wp_register_style( 'rnrd-menu', false, array(), RNRD_VERSION );
	wp_enqueue_style( 'rnrd-menu' );
	wp_add_inline_style(
		'rnrd-menu',
		'#adminmenu .rnrd-menu-item{display:inline-flex;align-items:center;gap:6px}' .
		'#adminmenu .rnrd-menu-dot{width:7px;height:7px;border-radius:50%;flex:none}' .
		'#adminmenu .upgrade .rnrd-menu-dot{background:#8c8f94}' .
		'#adminmenu .needs-action .rnrd-menu-dot{background:#DBA617;box-shadow:0 0 0 3px rgba(219,166,23,.22)}' .
		'#adminmenu .rnrd-menu-tick{width:6px;height:10px;flex:none;border:solid #2FB344;border-width:0 2px 2px 0;transform:rotate(45deg);margin:-3px 3px 0 1px}' .
		'#adminmenu .is-active{color:#2FB344}'
	);
} );

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

	// Land a non-canonical upload (a GitHub "Download ZIP" folder) in the folder
	// the running copy already lives in, so it replaces that install instead of
	// adding a second copy. With no running copy, use the WP.org slug. A zip
	// that is already named for the WP.org slug is left alone, so a legacy
	// install in another folder that uploads the WP.org zip still ends up with
	// two copies; the duplicate-install guard handles that case.
	$target = 'rankready-ai-llm-seo';
	if ( defined( 'RNRD_BASENAME' ) && '.' !== dirname( RNRD_BASENAME ) ) {
		$target = dirname( RNRD_BASENAME );
	}

	$current = basename( untrailingslashit( $source ) );
	if ( $target === $current || 'rankready-ai-llm-seo' === $current ) {
		return $source;
	}

	$new_source = trailingslashit( $remote_source ) . $target . '/';

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

/**
 * One batch of the 1.1.1 Unicode meta re-encode, resumable by meta_id cursor.
 *
 * Walks `_rnrd_summary` / `_rnrd_faq` rows in meta_id order from the stored
 * cursor. A full batch saves the cursor and schedules the next batch as a
 * single cron event, so a site with any number of rows finishes without a
 * version bump. The done-flag is written only when a batch comes back short.
 * Rows that already hold clean UTF-8 round-trip to the same bytes and are
 * skipped without a write.
 *
 * @since 1.4.0
 */
if ( ! function_exists( 'rnrd_migrate_unicode_meta_batch' ) ) {
function rnrd_migrate_unicode_meta_batch(): void {
	if ( get_option( 'rnrd_unicode_meta_migrated_v111' ) ) {
		delete_option( 'rnrd_unicode_meta_cursor' );
		return;
	}

	global $wpdb;
	$batch  = 2000;
	$cursor = (int) get_option( 'rnrd_unicode_meta_cursor', 0 );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-time data migration over this plugin's own meta keys; no core API walks postmeta by meta_id.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_id, meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key IN ('_rnrd_summary', '_rnrd_faq') AND meta_id > %d
			 ORDER BY meta_id ASC LIMIT %d",
			$cursor,
			$batch
		),
		ARRAY_A
	);

	$migrated = (int) get_option( 'rnrd_unicode_meta_migrated_count', 0 );
	foreach ( (array) $rows as $row ) {
		$cursor  = max( $cursor, (int) $row['meta_id'] );
		$decoded = json_decode( $row['meta_value'], true );
		if ( ! is_array( $decoded ) ) {
			continue;
		}
		$reencoded = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $reencoded || $reencoded === $row['meta_value'] ) {
			continue;
		}
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => $reencoded ),
			array( 'meta_id' => (int) $row['meta_id'] )
		);
		$migrated++;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

	if ( count( (array) $rows ) < $batch ) {
		// The flag must be truthy to read as done, so a sweep that changed
		// nothing stores '0_completed' rather than 0.
		update_option( 'rnrd_unicode_meta_migrated_v111', $migrated > 0 ? (string) $migrated : '0_completed', false );
		delete_option( 'rnrd_unicode_meta_cursor' );
		delete_option( 'rnrd_unicode_meta_migrated_count' );
		if ( $migrated > 0 && wp_cache_supports( 'flush_group' ) ) {
			// Cached reads may still hold the escaped values.
			wp_cache_flush_group( 'post_meta' );
		}
		return;
	}

	update_option( 'rnrd_unicode_meta_cursor', $cursor, false );
	update_option( 'rnrd_unicode_meta_migrated_count', $migrated, false );
	if ( ! wp_next_scheduled( 'rnrd_unicode_meta_migrate' ) ) {
		wp_schedule_single_event( time() + 30, 'rnrd_unicode_meta_migrate' );
	}
}
}
add_action( 'rnrd_unicode_meta_migrate', 'rnrd_migrate_unicode_meta_batch' );

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
		//
		// Only on installs that actually RAN the bad migration. The corrective
		// cannot tell an 'off' written by pre-1.1.19 code from an 'off' the site
		// owner chose, so on any install that never ran that code there is
		// nothing to correct and flipping the value back on is simply
		// overwriting a deliberate setting. A site first installed on 1.3.x has
		// no `rnrd_md_signals_corrected_v1119` row either, so without the
		// version test this ran on its very next upgrade and switched the hint
		// div and the bot auto-serve back on underneath it. The key is still
		// stamped in that case, so this never re-evaluates.
		if ( ! get_option( 'rnrd_md_signals_corrected_v1119' ) ) {
			$ran_buggy_migration = ( '' !== $stored_version && version_compare( $stored_version, '1.1.19', '<' ) );
			if ( $ran_buggy_migration && 'on' === get_option( 'rnrd_md_enable', 'off' ) ) {
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
		// Version-gated for the same reason as the v1.1.19 corrective above, and
		// it matters more here: llms-full.txt publishes the whole body of every
		// included post, so forcing it back on for somebody who switched it off
		// starts serving content they had stopped serving.
		if ( ! get_option( 'rnrd_scorecard_corrected_v1121' ) ) {
			$seed_llms_full = ( '' !== $stored_version && version_compare( $stored_version, '1.1.21', '<' ) );
			if ( $seed_llms_full && 'on' === get_option( 'rnrd_llms_enable', 'off' ) ) {
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
			// Deferred to 'init' for the same reason as the rewrite flush below:
			// the purge resolves URLs with url_to_postid(), which needs
			// $wp_rewrite, and WordPress only creates it after plugins_loaded.
			// Called here directly, every site upgrading from 1.3.0 or older
			// hit a fatal on its first request after the update.
			if ( class_exists( 'RNRD_Llms_Txt' ) ) {
				add_action( 'init', array( 'RNRD_Llms_Txt', 'bust_cache_and_purge_cdn' ), 100 );
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
		// reads these rows correctly. One bounded batch runs here; a large
		// site finishes the rest in single-event cron batches, see
		// rnrd_migrate_unicode_meta_batch().
		if ( ! get_option( 'rnrd_unicode_meta_migrated_v111' ) ) {
			rnrd_migrate_unicode_meta_batch();
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
		$rnrd_method = isset( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';
		if ( 'POST' === $rnrd_method ) {
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
	// First activation timestamp, so time-gated notices have a real anchor.
	// add_option() so a reactivation never resets it.
	add_option( 'rnrd_first_activated', time(), '', false );

	// Resume an interrupted Unicode meta migration. Deactivation clears its
	// cron event, and on reactivation the stored version already matches, so
	// the version-change block never calls it again. A cursor left behind means
	// batches remain; queue the next one.
	if ( ! get_option( 'rnrd_unicode_meta_migrated_v111' )
		&& false !== get_option( 'rnrd_unicode_meta_cursor', false )
		&& ! wp_next_scheduled( 'rnrd_unicode_meta_migrate' ) ) {
		wp_schedule_single_event( time() + 30, 'rnrd_unicode_meta_migrate' );
	}

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
	// RNRD_OPT_AUTHOR_HEADING is deliberately not seeded: an English literal in
	// the DB cannot be translated. Readers go through RNRD_Author_Box::heading_text().
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
	// Both async hooks are scheduled WITH a post id argument. wp_next_scheduled() and
	// wp_clear_scheduled_hook() without args only match argument-less events, so every
	// queued per-post job survived deactivation. wp_unschedule_hook() clears them all.
	wp_unschedule_hook( RNRD_CRON_HOOK );
	wp_unschedule_hook( 'rnrd_async_faq_generate' );
	wp_clear_scheduled_hook( RNRD_SCHEMA_CRON_HOOK );

	// v1.2.0-beta.4 — clear cron hooks that were uncovered in beta.3 audit #9.
	wp_clear_scheduled_hook( 'rnrd_crawler_log_prune' );  // daily prune was leaving zombie queries against a (possibly dropped) table.
	wp_clear_scheduled_hook( RNRD_CRON_BULK_STARTOVER );
	wp_clear_scheduled_hook( RNRD_CRON_BULK_FAQ );
	wp_clear_scheduled_hook( RNRD_CRON_BULK_SUMMARY );
	wp_clear_scheduled_hook( 'rnrd_okf_purge_edge' );     // RNRD_OKF::PURGE_EVENT, scheduled on content change.
	wp_clear_scheduled_hook( 'rnrd_unicode_meta_migrate' ); // resumable meta migration batches.
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
