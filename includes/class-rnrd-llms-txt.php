<?php
/**
 * LLMs.txt generator — full spec compliance per llmstxt.org.
 *
 * Generates /llms.txt and optionally /llms-full.txt.
 * Uses WordPress rewrite rules + transient caching.
 *
 * Spec requirements:
 * - H1 with site name (required)
 * - Blockquote summary (recommended)
 * - Markdown body with site info
 * - H2-delimited sections with file lists: - [title](url): description
 * - Optional section for secondary content
 *
 * llms-full.txt format (per real-world implementations like Lovable/Mintlify):
 * - Each page starts with: # Page Title\nSource: URL
 * - Full content inlined as clean markdown below
 * - No XML wrappers, just concatenated markdown pages
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Llms_Txt {

	public static function init(): void {
		add_action( 'init',             array( self::class, 'add_rewrite_rules' ) );
		// issue #41 — shared, filterable base priority (rnrd_template_redirect_priority(),
		// defaults to -9999) instead of a hardcoded small number, so page builders
		// or mu-plugins hooking template_redirect early cannot intercept
		// /llms.txt + /llms-full.txt before we respond. Our handler exit()s when matched.
		add_action( 'template_redirect', array( self::class, 'handle_request' ), rnrd_template_redirect_priority( 1 ) );

		// v1.2.0-rc.2 — tell every WP page-cache plugin to never cache the
		// llms.txt endpoints. RankReady already caches the response in a
		// 1-hour transient and sets Cache-Control: public, max-age=3600.
		// Layered page-cache would stomp the dynamic header.
		add_action( 'init', array( self::class, 'register_cache_exclusions' ), 11 );

		// Prevent WordPress from adding trailing slash to .txt URLs.
		add_filter( 'redirect_canonical', array( self::class, 'prevent_txt_trailing_slash' ), 10, 2 );

		// Rewrite flush: pre_update_option_* busts rnrd_rewrite_ok; admin_init self-heal flushes.

		// Bust cache when posts are published/updated/deleted.
		add_action( 'transition_post_status', array( self::class, 'bust_cache_on_status_change' ), 10, 3 );
		add_action( 'deleted_post',           array( self::class, 'bust_cache' ) );

		// rc.16 audit fix C1 — bust transient + purge CDN/page-cache on EVERY
		// option that mutates llms.txt output. Without this, brand identity
		// edits stay invisible for up to 1 hour (default TTL) and CDN/page-cache
		// layers serve the prior version even longer. (slift.co user report.)
		$busters = array(
			RNRD_OPT_LLMS_ENABLE,           RNRD_OPT_LLMS_FULL_ENABLE,
			RNRD_OPT_LLMS_SITE_NAME,        RNRD_OPT_LLMS_SUMMARY,
			RNRD_OPT_LLMS_ABOUT,            RNRD_OPT_BRAND_TERMS,
			RNRD_OPT_LLMS_POST_TYPES,       RNRD_OPT_LLMS_MAX_POSTS,
			RNRD_OPT_LLMS_EXCLUDE_CATS,     RNRD_OPT_LLMS_EXCLUDE_TAGS,
			RNRD_OPT_LLMS_SHOW_CATEGORIES,  RNRD_OPT_LLMS_CACHE_TTL,
			RNRD_OPT_LLMS_USE_MD_URLS,      RNRD_OPT_MD_ENABLE,
			RNRD_OPT_MD_POST_TYPES,
			// Was missing until 1.4.0, and it is the one option a PAYING
			// customer touches: flipping "Hide RankReady credit" changed the
			// generated body but never busted the transient, so the credit
			// line stayed for the full TTL (1 hour by default) and longer
			// behind a CDN. Same bug class as the rc.16 fix above; this
			// option was simply left out of that list.
			RNRD_OPT_HIDE_BRANDING,
		);
		foreach ( $busters as $opt ) {
			add_action( 'update_option_' . $opt, array( self::class, 'bust_cache_and_purge_cdn' ) );
			add_action( 'add_option_' . $opt, array( self::class, 'bust_cache_and_purge_cdn' ) );
		}

		// Emit Link: headers and <link> tags for AI discovery on every front-end page.
		add_action( 'send_headers', array( self::class, 'add_discovery_link_headers' ) );
		add_action( 'wp_head',      array( self::class, 'add_discovery_link_tags' ) );
	}

	/**
	 * Prevent WordPress from adding a trailing slash to /llms.txt and /llms-full.txt.
	 *
	 * WordPress canonical redirect turns /llms.txt into /llms.txt/ by default,
	 * causing a 301 loop. This filter stops that.
	 */
	public static function prevent_txt_trailing_slash( $redirect_url, $requested_url ) {
		if ( preg_match( '/\/llms(?:-full)?\.txt\/?$/i', $requested_url ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Emit Link: HTTP response headers for AI agent discovery on all front-end pages.
	 *
	 * These are checked by isitagentready.com and similar agent-readiness scanners
	 * to verify the site exposes its LLM-readable endpoints via standard headers.
	 */
	public static function add_discovery_link_headers(): void {
		if ( is_admin() || defined( 'REST_REQUEST' ) ) {
			return;
		}

		if ( 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) {
			header( 'Link: <' . esc_url( home_url( '/llms.txt' ) ) . '>; rel="llms-txt"', false );
		}

		// The route serves llms-full.txt only when llms.txt is on too, so the
		// advertisement follows the same condition or crawlers get a 404.
		if ( 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) && 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			header( 'Link: <' . esc_url( home_url( '/llms-full.txt' ) ) . '>; rel="llms-full-txt"', false );
		}

		// Archives / search / other listings. Front and Posts-page indexes emit
		// their own alternates from RNRD_Markdown. Emitting /index.md here would
		// duplicate the front and mis-label the blog index.
		if ( 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ) && 'on' === get_option( RNRD_OPT_MD_HOME_ENABLE, 'on' ) && ! is_singular() && ! is_front_page() && ! is_home() ) {
			header( 'Link: <' . esc_url( home_url( '/index.md' ) ) . '>; rel="alternate"; type="text/markdown"', false );
		}

		// rc.16 audit — sitemap Link header removed. Sitemap discovery belongs
		// in robots.txt per Google Search Central docs, NOT in HTTP Link
		// headers for LLM/agent discovery.

		/*
		 * REMOVED: hreflang alternate Link headers for /<lang>/llms.txt.
		 *
		 * rc.16 emitted one of these per detected WPML/Polylang language, with a
		 * comment claiming per-language generation "ships in v1.3 Pro". It never
		 * shipped, in Free or in Pro. add_rewrite_rules() below registers exactly
		 * one route, `^llms\.txt$`, and handle_request() matches the literal
		 * string 'llms.txt', so /es/llms.txt has no handler anywhere in either
		 * plugin. Verified in the lab: /llms.txt returns 200 and /es/llms.txt
		 * returns 404.
		 *
		 * So on every multilingual site running RankReady, the plugin was
		 * advertising a 404 to AI agents in an RFC 8288 header, as a language
		 * alternate, which is the precise failure this plugin exists to prevent.
		 * The option check made it look guarded but only proved the DEFAULT
		 * endpoint was on. Compare RNRD_Markdown::add_homepage_link_headers(),
		 * whose docblock states the standard this violated: "Only fires when
		 * llms.txt is enabled, guarantees the advertised URL actually resolves."
		 * The .md per-language alternates are honest by contrast: they are built
		 * from real translated permalinks and the .md rule matches any path.
		 *
		 * Do not restore this block as a discovery "hint". A header that names a
		 * URL is a claim that the URL exists. If per-language llms.txt is ever
		 * built, the route lands first and the header follows it.
		 */
	}

	/**
	 * Emit <link> tags in <head> for AI agent discovery.
	 *
	 * Mirrors the Link: headers as HTML meta-equivalents so HTML parsers
	 * (and tools that don't inspect response headers) can also discover endpoints.
	 */
	public static function add_discovery_link_tags(): void {
		if ( 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) {
			echo '<link rel="llms-txt" type="text/plain" href="' . esc_url( home_url( '/llms.txt' ) ) . '" />' . "\n";
		}

		if ( 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) && 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			echo '<link rel="llms-full-txt" type="text/plain" href="' . esc_url( home_url( '/llms-full.txt' ) ) . '" />' . "\n";
		}
	}

	/**
	 * Whether the "Generated from RankReady" credit line should be hidden.
	 *
	 * Free build: always returns false, because `rnrd_is_pro()` is false
	 * without the Pro plugin and a valid license, so the credit always shows.
	 * With Pro licensed the toggle is live and this is what hides the line.
	 * (It is NOT a placeholder; that docblock outlived the implementation.)
	 *
	 * @since 1.2.0-rc.11
	 */
	public static function should_hide_branding(): bool {
		return ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() )
			&& 'on' === get_option( RNRD_OPT_HIDE_BRANDING, 'off' );
	}

	// ── Rewrite rules ─────────────────────────────────────────────────────────

	public static function add_rewrite_rules(): void {
		if ( 'on' !== get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) {
			return;
		}

		// Skip llms.txt if a major SEO plugin already generates it.
		// RankReady still registers llms-full.txt since no SEO plugin does that.
		if ( ! self::another_plugin_handles_llms_txt() ) {
			add_rewrite_rule( '^llms\.txt$', 'index.php?rnrd_llms_txt=1', 'top' );
		}

		if ( 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			add_rewrite_rule( '^llms-full\.txt$', 'index.php?rnrd_llms_full_txt=1', 'top' );
		}

		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
	}

	/**
	 * Check if another plugin already handles /llms.txt generation.
	 *
	 * Detects Rank Math, Yoast, AIOSEO, SEOPress, and standalone llms.txt plugins.
	 * Returns true if RankReady should NOT register its own llms.txt route.
	 */
	// v1.1.5 (#10) — made public so the rewrite self-heal in rankready.php reuses this
	// single source of truth (RM + Yoast + AIOSEO + SEOPress + force filter) instead of
	// its own RM/Yoast-only inline check.
	public static function another_plugin_handles_llms_txt(): bool {
		// Allow users to force RankReady's llms.txt via filter.
		if ( apply_filters( 'rankready_force_llms_txt', false ) ) {
			return false;
		}

		// Rank Math llms.txt (has its own module).
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm_modules = (array) get_option( 'rank_math_modules', array() );
			if ( in_array( 'llms-txt', $rm_modules, true ) ) {
				return true;
			}
		}

		// Yoast SEO llms.txt.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$yoast_features = get_option( 'wpseo', array() );
			if ( ! empty( $yoast_features['enable_llms_txt'] ) ) {
				return true;
			}
		}

		// AIOSEO llms.txt (v1.2.0-rc.8 fix: was unconditionally true, broke
		// sites where AIOSEO is installed but llms.txt feature is off).
		// AIOSEO stores its toggles under `aioseo_options` JSON; check the
		// llms.txt key explicitly. If we can't verify it's ON, we serve.
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$aio = get_option( 'aioseo_options', '' );
			if ( is_string( $aio ) && $aio ) {
				$decoded = json_decode( $aio, true );
				if ( is_array( $decoded ) && ! empty( $decoded['llmsTxt']['enable'] ) ) {
					return true;
				}
			}
			// AIOSEO present but feature not verified on → RankReady serves.
		}

		// SEOPress llms.txt (v1.2.0-rc.8 fix: was unconditionally true for
		// 9.5+, broke slift.co where SEOPress 9.8.5 is installed but the
		// llms.txt feature wasn't enabled in SEOPress settings).
		// Check the SEOPress option explicitly. If we can't verify it's on,
		// we serve our own /llms.txt — RankReady's rewrite rule uses 'top'
		// priority so it wins anyway if both register.
		// rc.12 — read whichever constant is defined (Pro can be active without Free).
		$_seopress_v = defined( 'SEOPRESS_VERSION' ) ? SEOPRESS_VERSION : ( defined( 'SEOPRESS_PRO_VERSION' ) ? SEOPRESS_PRO_VERSION : '0' );
		if ( '0' !== $_seopress_v && version_compare( $_seopress_v, '9.5', '>=' ) ) {
			// SEOPress Pro stores llms.txt config under
			// `seopress_pro_option_name` array, key `seopress_pro_llms_txt`.
			$seopress = get_option( 'seopress_pro_option_name', array() );
			if ( is_array( $seopress ) && ! empty( $seopress['seopress_pro_llms_txt'] ) ) {
				return true;
			}
			// SEOPress present but llms.txt not verified on → RankReady serves.
		}

		return false;
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = 'rnrd_llms_txt';
		$vars[] = 'rnrd_llms_full_txt';
		return $vars;
	}

	// ── Request handler ───────────────────────────────────────────────────────

	public static function handle_request(): void {
		// Primary path: WordPress resolved our rewrite rule into a query var.
		// Fallback path: match the raw request URI directly. On some stacks (e.g. an
		// SEO plugin's early template_redirect router, aggressive rewrite ordering, or
		// a query_vars strip) WP never surfaces our query var even though the rule
		// matched — the raw-path check keeps the endpoint working. (Support: barisdayak.com.)
		$path     = self::request_path();
		$llms_on  = 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$full_on  = 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' );
		$other    = self::another_plugin_handles_llms_txt();

		if ( $llms_on && ! $other
			&& ( get_query_var( 'rnrd_llms_txt' ) || 'llms.txt' === $path ) ) {
			RNRD_Crawler_Log::log( 'llms_txt' );
			self::serve_llms_txt( false );
		}

		if ( $llms_on && $full_on
			&& ( get_query_var( 'rnrd_llms_full_txt' ) || 'llms-full.txt' === $path ) ) {
			RNRD_Crawler_Log::log( 'llms_full' );
			self::serve_llms_txt( true );
		}
	}

	/** Normalised current request path: no query string, no surrounding slashes, subdirectory-aware. */
	private static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$req = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home ) {
			if ( 0 === strpos( $req, $home . '/' ) ) {
				$req = trim( substr( $req, strlen( $home ) ), '/' );
			} elseif ( $req === $home ) {
				$req = '';
			}
		}
		return $req;
	}

	// ── Serve ─────────────────────────────────────────────────────────────────

	private static function serve_llms_txt( bool $full = false ): void {
		if ( 'on' !== get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) {
			status_header( 404 );
			exit;
		}

		if ( $full && 'on' !== get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			status_header( 404 );
			exit;
		}

		$cache_key = $full ? RNRD_LLMS_FULL_CACHE_KEY : RNRD_LLMS_CACHE_KEY;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			/**
			 * rc.16 — programmatic override hook for the rendered llms.txt body.
			 *
			 * Chosen over a UI placeholder template editor (which would have
			 * required ~15 placeholders, preview UI, and would generate broken
			 * llmstxt.org-spec output from 99% of users who don't read the doc).
			 *
			 * @param string $content Final llms.txt body about to be served.
			 * @param array  $context ['full' => bool] full or index variant.
			 */
			$cached = (string) apply_filters( 'rankready_llms_txt_content', $cached, array( 'full' => $full ) );
			self::output_txt( $cached );
			return; // output_txt calls exit, but guard against refactoring.
		}

		$content = $full ? self::generate_full() : self::generate();

		$ttl = (int) get_option( RNRD_OPT_LLMS_CACHE_TTL, 3600 );
		if ( $ttl < 60 ) {
			$ttl = 3600;
		}
		set_transient( $cache_key, $content, $ttl );

		// rc.16 — same filter as above, applied on the fresh-generation path.
		$content = (string) apply_filters( 'rankready_llms_txt_content', $content, array( 'full' => $full ) );

		self::output_txt( $content );
	}

	private static function output_txt( string $content ): void {
		// v1.2.0-rc.2 — bypass WP page-cache plugins (LiteSpeed Cache, WP Rocket,
		// W3TC, etc.) so they don't layer their own cache on top.
		// RankReady manages its own caching via wp_transient.
		if ( class_exists( 'RNRD_Cache' ) ) {
			RNRD_Cache::bypass_page_cache_plugins_only();
		}

		// v1.0.1 — ETag-based revalidation. The strong ETag is the SHA-1 of
		// the response body. If the client sends If-None-Match matching this,
		// reply 304 Not Modified with no body — 60–90% bandwidth savings on
		// re-fetches, no origin work to regenerate content. Standard enterprise
		// pattern used by Cloudflare docs, Stripe docs, Vercel docs.
		$etag          = '"' . sha1( $content ) . '"';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- If-None-Match header used for ETag string comparison; wp_unslash applied, no echo/store.
		$client_etag   = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
		if ( '' !== $client_etag && $client_etag === $etag ) {
			status_header( 304 );
			header( 'ETag: ' . $etag );
			header( 'Cache-Control: public, max-age=60, s-maxage=600, stale-while-revalidate=3600' );
			exit;
		}

		// Assert 200 explicitly. We run on template_redirect, which fires AFTER
		// the main query — if another plugin intercepted the rewrite rule, WP has
		// already resolved this request as a 404 and sent that status. Serving the
		// correct body under a 404 makes agents and scanners discard it. Mirrors
		// RNRD_MCP::handle_request(), which has always done this.
		status_header( 200 );

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: text/plain; charset=utf-8' );

		// v1.2.0 — Keep llms.txt / llms-full.txt CRAWLABLE (AI agents fetch it,
		// and Google must be able to read this directive) but OUT of Google's
		// search results. Google ignores llms.txt for ranking, and it's a
		// machine-readable file, not a user-facing page — the recommended
		// practice for such files is "allow crawling, then X-Robots-Tag: noindex".
		// This response already bypasses page caches, so the header survives.
		header( 'X-Robots-Tag: noindex, follow' );

		// Browser-vs-edge TTL split (Mark Nottingham's caching tutorial §6.2).
		// max-age=60 keeps end-user browsers re-checking every minute (cheap
		// with ETag → 304). s-maxage=600 lets shared CDN edges cache 10x longer
		// so origin sees ~one request per 10 minutes per edge POP regardless
		// of how many readers hit each edge. stale-while-revalidate=3600 lets
		// edges serve a slightly-stale response for up to an hour while
		// fetching a fresh one in the background — zero user-facing latency
		// for the refresh.
		header( 'Cache-Control: public, max-age=60, s-maxage=600, stale-while-revalidate=3600' );
		header( 'CDN-Cache-Control: public, max-age=600, stale-while-revalidate=3600' );
		header( 'Cloudflare-CDN-Cache-Control: public, max-age=600' );
		header( 'Surrogate-Control: max-age=600' );
		header( 'ETag: ' . $etag );

		// Vary on Accept-Encoding so a gzip-compressed body is never served to
		// a non-gzip client. The plain-text endpoint doesn't content-negotiate
		// on Accept, so that's not in the Vary list.
		header( 'Vary: Accept-Encoding' );

		// CORS — AI agents fetch llms.txt cross-origin from their runtime.
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, HEAD, OPTIONS' );
		header( 'Access-Control-Expose-Headers: Content-Type, ETag, Last-Modified' );

		header( 'X-RankReady-Source: llms-txt' );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Register URL patterns with WP page-cache plugins so they don't cache
	 * the endpoints RankReady manages itself.
	 *
	 * Hooked at init priority 11 so cache plugins have already registered
	 * their filters when we add ours.
	 *
	 * @since 1.2.0-rc.2
	 */
	public static function register_cache_exclusions(): void {
		if ( ! class_exists( 'RNRD_Cache' ) ) {
			return;
		}
		$patterns = array( '/llms.txt', '/llms-full.txt' );
		// Add per-post .md when markdown is on (RNRD_Markdown registers its own
		// exclusions; we list here so a misconfigured cache plugin still
		// honours at least one filter).
		if ( 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			$patterns[] = '.md';
		}
		RNRD_Cache::exclude_url_patterns( $patterns );
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// GENERATE: /llms.txt (index with links only)
	// ═══════════════════════════════════════════════════════════════════════════

	public static function generate(): string {
		$lines = array();

		// v1.2.0-beta.4 — read every brand field from the unified getter.
		// Single source of truth: see get_brand_identity().
		$brand = RNRD_Brand_Identity::get_brand_identity();

		// ── H1: Site name (REQUIRED per spec) ─────────────────────────────
		$lines[] = '# ' . self::clean_text( $brand['name'] );
		$lines[] = '';

		// ── Blockquote: Brief summary (RECOMMENDED per spec) ──────────────
		if ( '' !== $brand['summary'] ) {
			$lines[] = '> ' . trim( (string) preg_replace( '/\s+/u', ' ', self::clean_text( $brand['summary'] ) ) );
			$lines[] = '';
		}

		// ── About section (detailed info) ─────────────────────────────────
		if ( '' !== $brand['about'] ) {
			$lines[] = self::clean_text( $brand['about'] );
			$lines[] = '';
		}

		// ── Site metadata ─────────────────────────────────────────────────
		$lines[] = '- URL: ' . home_url( '/' );

		// Brand Terms — canonical names for entity consistency. Helps AI engines
		// recognise the same site/brand across variant spellings.
		$brand_terms = RNRD_Brand_Identity::get_brand_terms_list();
		if ( ! empty( $brand_terms ) ) {
			$lines[] = '- Brand: ' . implode( ', ', $brand_terms );
		}

		$feed_url = get_bloginfo( 'rss2_url' );
		if ( ! empty( $feed_url ) ) {
			$lines[] = '- RSS Feed: ' . $feed_url;
		}

		// rc.16 audit — sitemap intentionally NOT listed in llms.txt body.
		// llmstxt.org spec does not require it; Google requires Sitemap: in
		// robots.txt (where RankReady already emits it). Anthropic / OpenAI /
		// Perplexity have published no docs requiring it in llms.txt. Real-
		// world llms.txt files (Anthropic docs, Lovable, Mintlify) omit it.

		// Link to llms-full.txt if enabled.
		if ( 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			$lines[] = '- Full version: ' . home_url( '/llms-full.txt' );
		}

		// Tell crawlers that markdown is available per page.
		if ( 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			$lines[] = '- Markdown: Append .md to any page URL for clean markdown (e.g., /page-slug.md)';
			$lines[] = '- Content negotiation: Send `Accept: text/markdown` header on any page URL';
		}

		$lines[] = '';

		// ── Post type sections (H2-delimited file lists) ──────────────────
		$post_types = (array) get_option( RNRD_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) );
		$max_posts  = (int) get_option( RNRD_OPT_LLMS_MAX_POSTS, 100 );

		if ( $max_posts < 1 ) {
			$max_posts = 100;
		}

		// Get taxonomy exclusions from settings.
		$exclude_cats = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_CATS, array() );
		$exclude_tags = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_TAGS, array() );

		foreach ( $post_types as $pt ) {
			$type_obj = get_post_type_object( $pt );
			if ( ! $type_obj ) {
				continue;
			}

			$query_args = self::build_llms_query( $pt, $max_posts, $exclude_cats, $exclude_tags );
			$posts      = get_posts( $query_args );

			if ( empty( $posts ) ) {
				continue;
			}

			// Filter out posts flagged noindex by SEO plugins.
			$filtered = array();
			foreach ( $posts as $post ) {
				if ( self::should_exclude_from_llms( $post ) ) {
					continue;
				}
				$filtered[] = $post;
			}

			if ( empty( $filtered ) ) {
				continue;
			}

			// H2 section header.
			$section_title = self::flatten_for_list_line( self::clean_text( $type_obj->labels->name ) );
			$lines[]       = '## ' . $section_title;

			foreach ( $filtered as $post ) {
				$title    = self::flatten_for_list_line( self::clean_text( get_the_title( $post ) ) );
				$url      = self::entry_url_for_post( $post );
				$excerpt  = self::get_post_description( $post );
				$lastmod  = get_post_modified_time( 'Y-m-d', false, $post );

				// No description means no ": " either, so the line never dangles.
				$lines[] = '- [' . $title . '](' . $url . ')' . ( '' !== trim( $excerpt ) ? ': ' . trim( $excerpt ) : '' ) . ' (updated: ' . $lastmod . ')';
			}

			$lines[] = '';
		}

		// ── Optional section (per spec: secondary/skippable content) ──────
		// Controlled by admin setting — user can toggle it off entirely.
		if ( 'on' === get_option( RNRD_OPT_LLMS_SHOW_CATEGORIES, 'on' ) ) {
			$cat_args = array(
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 20,
				'hide_empty' => true,
			);

			// Respect excluded categories.
			if ( ! empty( $exclude_cats ) ) {
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Exclude list is the user-curated category exclusion; bounded, intentional.
				$cat_args['exclude'] = $exclude_cats;
			}

			$categories = get_categories( $cat_args );

			if ( ! empty( $categories ) ) {
				$lines[] = '## Optional';

				foreach ( $categories as $cat ) {
					$lines[] = '- [' . self::flatten_for_list_line( self::clean_text( $cat->name ) ) . '](' . get_category_link( $cat->term_id ) . '): '
						. sprintf( '%d posts', $cat->count );
				}

				$lines[] = '';
			}
		}

		// ── Footer ────────────────────────────────────────────────────────
		// rc.11 — Unbranded credit line. Hide entirely when Pro toggle on, and then
		// the separator goes with it so the file never ends on a lone "---".
		if ( ! self::should_hide_branding() ) {
			$lines[] = '---';
			$lines[] = 'Generated from RankReady';
		} else {
			while ( ! empty( $lines ) && in_array( end( $lines ), array( '', '---' ), true ) ) {
				array_pop( $lines );
			}
		}

		return implode( "\n", $lines );
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// GENERATE: /llms-full.txt (full content inlined per page)
	//
	// Format follows real-world implementations (Lovable/Mintlify):
	//   # Page Title
	//   Source: https://example.com/page-url
	//
	//   [full page content as clean markdown]
	//
	// ═══════════════════════════════════════════════════════════════════════════

	public static function generate_full(): string {
		$lines = array();

		// v1.2.0-beta.4 — unified getter, same brand truth as generate().
		$brand = RNRD_Brand_Identity::get_brand_identity();

		// ── Header (same as llms.txt) ─────────────────────────────────────
		$lines[] = '# ' . self::clean_text( $brand['name'] );
		$lines[] = '';

		if ( '' !== $brand['summary'] ) {
			$lines[] = '> ' . trim( (string) preg_replace( '/\s+/u', ' ', self::clean_text( $brand['summary'] ) ) );
			$lines[] = '';
		}

		if ( '' !== $brand['about'] ) {
			$lines[] = self::clean_text( $brand['about'] );
			$lines[] = '';
		}

		// Brand Terms — canonical names for entity consistency.
		$brand_terms = RNRD_Brand_Identity::get_brand_terms_list();
		if ( ! empty( $brand_terms ) ) {
			$lines[] = '- Brand: ' . implode( ', ', $brand_terms );
			$lines[] = '';
		}

		$lines[] = '---';
		$lines[] = '';

		// ── Inline each page as clean markdown ────────────────────────────
		$post_types = (array) get_option( RNRD_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) );
		$max_posts  = (int) get_option( RNRD_OPT_LLMS_MAX_POSTS, 100 );

		if ( $max_posts < 1 ) {
			$max_posts = 100;
		}

		// Get taxonomy exclusions from settings.
		$exclude_cats = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_CATS, array() );
		$exclude_tags = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_TAGS, array() );

		foreach ( $post_types as $pt ) {
			$type_obj = get_post_type_object( $pt );
			if ( ! $type_obj ) {
				continue;
			}

			$query_args = self::build_llms_query( $pt, $max_posts, $exclude_cats, $exclude_tags );
			$posts      = get_posts( $query_args );

			if ( empty( $posts ) ) {
				continue;
			}

			foreach ( $posts as $post ) {
				// Skip noindex posts.
				if ( self::should_exclude_from_llms( $post ) ) {
					continue;
				}

				$content = self::post_to_clean_markdown( $post, false );

				// issue #9 — a page-builder post whose content could not be
				// resolved at all (see RNRD_Generator::resolve_post_content())
				// gets skipped entirely rather than publishing an empty
				// `# Title` / `Source:` block with nothing under it — a 200
				// response with no body is worse than simply not listing the
				// page, since it reads as confirmed thin/duplicate content.
				if ( '' === trim( (string) $content ) ) {
					continue;
				}

				$title = self::flatten_for_list_line( self::clean_text( get_the_title( $post ) ) );
				$url   = self::entry_url_for_post( $post );

				// Per-page separator: # Title + Source URL
				$lines[] = '# ' . $title;
				$lines[] = 'Source: ' . $url;
				$lines[] = '';
				$lines[] = $content;

				$lines[] = '';
				$lines[] = '---';
				$lines[] = '';
			}
		}

		// rc.11 — Unbranded credit line. Hide entirely when Pro toggle on; the trailing
		// per-page separator goes with it.
		if ( ! self::should_hide_branding() ) {
			$lines[] = 'Generated from RankReady';
		} else {
			while ( ! empty( $lines ) && in_array( end( $lines ), array( '', '---' ), true ) ) {
				array_pop( $lines );
			}
		}

		return implode( "\n", $lines );
	}

	// ── Cache busting ─────────────────────────────────────────────────────────

	public static function bust_cache_on_status_change( $new_status, $old_status, $post ): void {
		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			self::bust_cache();
		}
	}

	public static function bust_cache(): void {
		delete_transient( RNRD_LLMS_CACHE_KEY );
		delete_transient( RNRD_LLMS_FULL_CACHE_KEY );
	}

	/**
	 * Bust transient AND purge upstream CDN / page-cache layers.
	 *
	 * Called from update_option hooks for every setting that mutates llms.txt
	 * output. Without the CDN/page-cache purge, edits stay invisible at the
	 * edge even after we delete the transient.
	 *
	 * @since 1.2.0-rc.16 (audit C1 fix)
	 */
	public static function bust_cache_and_purge_cdn(): void {
		self::bust_cache();
		if ( class_exists( 'RNRD_Cache' ) ) {
			RNRD_Cache::purge_all_endpoints();
		}
	}

	// ── Multilingual detection ────────────────────────────────────────────────

	/**
	 * Detect active multilingual plugins so the admin can be warned that
	 * /llms.txt currently serves only the default language.
	 *
	 * Per-language generation (/es/llms.txt etc.) does NOT exist, in Free or in
	 * Pro. The old claim here that the plugin emits hreflang discovery tags for
	 * language-prefixed URLs described a real block in add_link_headers() that
	 * pointed at routes nothing registers; it was removed, see the comment there.
	 * The only caller today is the admin warning on the LLMs.txt screen, which is
	 * what this method was written for. Keep it that way: this detects, it does
	 * not advertise.
	 *
	 * @since 1.2.0-rc.16
	 * @return array<int, array{plugin:string,langs:string[],default:string}>
	 */
	public static function detect_multilingual(): array {
		$found = array();

		// WPML — most common, ships its own API.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML filter; must use its published name to integrate.
			$langs   = (array) apply_filters( 'wpml_active_languages', null );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML filter; must use its published name to integrate.
			$default = (string) apply_filters( 'wpml_default_language', 'en' );
			$found[] = array(
				'plugin'  => 'WPML ' . ICL_SITEPRESS_VERSION,
				'langs'   => array_keys( $langs ),
				'default' => $default,
			);
		}

		// Polylang.
		if ( function_exists( 'pll_languages_list' ) ) {
			$langs   = (array) pll_languages_list();
			$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';
			$found[] = array(
				'plugin'  => 'Polylang' . ( defined( 'POLYLANG_VERSION' ) ? ' ' . POLYLANG_VERSION : '' ),
				'langs'   => $langs,
				'default' => $default,
			);
		}

		// TranslatePress.
		if ( defined( 'TRP_PLUGIN_VERSION' ) ) {
			$settings = (array) get_option( 'trp_settings', array() );
			$langs    = (array) ( $settings['translation-languages'] ?? array() );
			$default  = (string) ( $settings['default-language'] ?? '' );
			$found[]  = array(
				'plugin'  => 'TranslatePress ' . TRP_PLUGIN_VERSION,
				'langs'   => $langs,
				'default' => $default,
			);
		}

		// Weglot.
		if ( class_exists( 'Weglot\\Util\\Helper_Util_Weglot' ) || defined( 'WEGLOT_VERSION' ) ) {
			$weglot   = (array) get_option( 'weglot_options', array() );
			$langs    = (array) ( $weglot['destination_language'] ?? array() );
			$default  = (string) ( $weglot['original_language'] ?? '' );
			$found[]  = array(
				'plugin'  => 'Weglot' . ( defined( 'WEGLOT_VERSION' ) ? ' ' . WEGLOT_VERSION : '' ),
				'langs'   => $langs,
				'default' => $default,
			);
		}

		// GTranslate — minimal data exposed via options.
		if ( defined( 'GTRANSLATE_VERSION' ) || function_exists( 'gtranslate' ) ) {
			$opts    = (array) get_option( 'GTranslate', array() );
			$langs   = ! empty( $opts['flag_codes'] ) ? explode( ',', (string) $opts['flag_codes'] ) : array();
			$found[] = array(
				'plugin'  => 'GTranslate',
				'langs'   => array_filter( array_map( 'trim', $langs ) ),
				'default' => '',
			);
		}

		return $found;
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// HTML-TO-MARKDOWN: Aggressive page-builder-safe converter
	//
	// Strips ALL Elementor, Beaver Builder, Divi, WPBakery, and generic
	// page builder wrapper divs/sections/spans. Preserves only semantic
	// content: headings, paragraphs, lists, links, images, blockquotes, code.
	// ═══════════════════════════════════════════════════════════════════════════

	public static function post_to_clean_markdown( $post, bool $run_shortcodes = true ): string {
		// issue #9 — page builders (Elementor, and anything else storing its body
		// in postmeta) leave post_content empty or near-empty even on a fully
		// rendered, published page. Route through RNRD_Generator::resolve_post_content()
		// so this reads the ACTUAL body — post_content, else the builder's own
		// render pipeline, else the `the_content` filter chain — instead of
		// silently producing an empty markdown body for a real page.
		$html = ( $post instanceof WP_Post && class_exists( 'RNRD_Generator' ) )
			? RNRD_Generator::resolve_post_content( $post )
			: ( is_object( $post ) ? (string) $post->post_content : '' );

		if ( '' === trim( (string) $html ) ) {
			return '';
		}

		// v1.4.1 - resolve_post_content() hands back RAW post_content when it has real
		// text, so dynamic blocks (latest-posts, reusable refs, ACF blocks) would be
		// deleted with their comments in Step 1. Render them first. Gains content only:
		// any failure returns the raw HTML unchanged.
		if ( $post instanceof WP_Post && class_exists( 'RNRD_Generator' ) && (string) $post->post_content === (string) $html ) {
			$html = RNRD_Generator::render_dynamic_blocks( $post, (string) $html );
		}

		// ── Step 1: Strip Gutenberg block comments ────────────────────────
		$html = preg_replace( '/<!--\s*\/?wp:[^\>]+-->/s', '', $html );

		// ── Step 2: Shortcodes — run them for individual requests, strip for bulk ──
		$html = $run_shortcodes ? do_shortcode( $html ) : strip_shortcodes( $html );

		// Drop script, style, noscript and template elements WITH their contents.
		// Stripping only the tags left the code as plain words ("alert(1)") in the
		// body. An unclosed opener removes everything after it, as a browser would.
		$html = preg_replace( '#<(script|style|noscript|template)\b[^>]*>.*?</\1\s*>#si', '', $html );
		$html = preg_replace( '#<(script|style|noscript|template)\b[^>]*>.*$#si', '', (string) $html );
		$html = (string) $html;

		// Author Box: topic chips are adjacent spans, which ran together as "WordPressTesting"
		// once the class attributes were stripped; and the bio is plain text, so Markdown
		// punctuation in it must not turn into live syntax. Both need the class, so run first.
		$html = preg_replace( '#</span>\s*(?=<span\b[^>]*class="[^"]*rnrd-ab-topic)#i', '</span>, ', $html );
		$html = preg_replace_callback(
			'#(<p\b[^>]*class="[^"]*rnrd-ab-bio[^"]*"[^>]*>)(.*?)(</p>)#si',
			static function ( $m ) {
				return $m[1] . preg_replace( '/([\\\\\[\]*_`])/', '\\\\$1', $m[2] ) . $m[3];
			},
			$html
		);

		// ── Step 3: Strip ALL page builder wrapper elements ───────────────
		// Elementor: div.elementor-*, section.elementor-*, div.e-*, etc.
		// Divi: div.et_pb_*, div.et_*, etc.
		// WPBakery: div.vc_*, div.wpb_*, etc.
		// Beaver Builder: div.fl-*, etc.
		// Generic: section, article, aside, main, figure wrappers
		//
		// Strategy: Remove open/close tags for non-semantic containers,
		// keeping their inner content.

		// Strip Elementor widget/section/column wrappers (keep inner HTML).
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:elementor-|e-con|e-child)[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<section[^>]*class="[^"]*elementor-[^"]*"[^>]*>/si', '', $html );

		// Strip Divi wrappers.
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:et_pb_|et_builder_)[^"]*"[^>]*>/si', '', $html );

		// Strip WPBakery wrappers.
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:vc_|wpb_)[^"]*"[^>]*>/si', '', $html );

		// Strip Beaver Builder wrappers.
		$html = preg_replace( '/<div[^>]*class="[^"]*fl-[^"]*"[^>]*>/si', '', $html );

		// Strip generic layout wrappers (div with only class/id/style attrs).
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:wp-block-|entry-|post-|content-|container|wrapper|row|col-|grid)[^"]*"[^>]*>/si', '', $html );

		// Remove stray closing divs and sections.
		$html = preg_replace( '/<\/(?:div|section|article|aside|main|header|footer|nav|figure|figcaption)>/si', '', $html );

		// Strip inline styles and data attributes from remaining elements.
		$html = preg_replace( '/\s+style="[^"]*"/si', '', $html );
		$html = preg_replace( '/\s+data-[a-z0-9_-]+="[^"]*"/si', '', $html );
		$html = preg_replace( '/\s+class="[^"]*"/si', '', $html );
		$html = preg_replace( '/\s+id="[^"]*"/si', '', $html );

		// ── Step 4: Convert semantic HTML to markdown ─────────────────────

		// Headings (callback for dynamic #).
		$html = preg_replace_callback( '/<h([1-6])[^>]*>(.*?)<\/h\1>/si', function ( $m ) {
			return "\n" . str_repeat( '#', (int) $m[1] ) . ' ' . wp_strip_all_tags( $m[2] ) . "\n";
		}, $html );

		// Bold and italic (before stripping tags).
		$html = preg_replace( '/<(strong|b)>(.*?)<\/\1>/si', '**$2**', $html );
		$html = preg_replace( '/<(em|i)>(.*?)<\/\1>/si', '*$2*', $html );

		// Links — strip anchor-only hrefs (#section) since they're meaningless outside the page.
		$html = preg_replace( '/<a\s[^>]*href=["\']#[^"\']*["\'][^>]*>(.*?)<\/a>/si', '$1', $html );
		$html = preg_replace( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', '[$2]($1)', $html );

		// Images — extract src and alt only.
		$html = preg_replace_callback( '/<img[^>]*>/si', function ( $m ) {
			$tag = $m[0];
			$src = '';
			$alt = '';
			if ( preg_match( '/src=["\']([^"\']+)["\']/i', $tag, $sm ) ) {
				$src = $sm[1];
			}
			if ( preg_match( '/alt=["\']([^"\']*)["\']/', $tag, $am ) ) {
				$alt = $am[1];
			}
			if ( empty( $src ) ) {
				return '';
			}
			return '![' . $alt . '](' . $src . ')';
		}, $html );

		// Lists.
		$html = preg_replace( '/<li[^>]*>(.*?)<\/li>/si', '- $1', $html );
		$html = preg_replace( '/<\/?[ou]l[^>]*>/si', '', $html );

		// Paragraphs and br.
		$html = preg_replace( '/<p[^>]*>(.*?)<\/p>/si', "$1\n\n", $html );
		$html = preg_replace( '/<br\s*\/?>/si', "\n", $html );

		// Blockquotes.
		$html = preg_replace_callback( '/<blockquote[^>]*>(.*?)<\/blockquote>/si', function ( $m ) {
			$inner = wp_strip_all_tags( trim( $m[1] ) );
			$bq_lines = explode( "\n", $inner );
			return implode( "\n", array_map( function ( $l ) { return '> ' . trim( $l ); }, $bq_lines ) );
		}, $html );

		// Code blocks.
		$html = preg_replace( '/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/si', "\n```\n$1\n```\n", $html );
		$html = preg_replace( '/<code[^>]*>(.*?)<\/code>/si', '`$1`', $html );

		// Tables (basic).
		$html = preg_replace_callback( '/<table[^>]*>(.*?)<\/table>/si', function ( $m ) {
			return self::table_to_markdown( $m[1] );
		}, $html );

		// Horizontal rules.
		$html = preg_replace( '/<hr[^>]*\/?>/si', "\n---\n", $html );

		// ── Step 5: Strip ALL remaining HTML tags ─────────────────────────
		$html = wp_strip_all_tags( $html );

		// ── Step 6: Decode entities and clean whitespace ──────────────────
		$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
		$html = preg_replace( '/\n{3,}/', "\n\n", $html );
		$html = preg_replace( '/[ \t]+/', ' ', $html );

		// Clean up lines — remove lines that are just whitespace.
		$final_lines = array();
		foreach ( explode( "\n", $html ) as $line ) {
			$trimmed = trim( $line );
			if ( '' !== $trimmed || ( ! empty( $final_lines ) && '' !== end( $final_lines ) ) ) {
				$final_lines[] = $trimmed;
			}
		}

		return trim( implode( "\n", $final_lines ) );
	}

	/**
	 * Basic HTML table to markdown table.
	 */
	private static function table_to_markdown( string $table_html ): string {
		$rows = array();
		preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/si', $table_html, $row_matches );

		if ( empty( $row_matches[1] ) ) {
			return wp_strip_all_tags( $table_html );
		}

		$is_header = true;
		foreach ( $row_matches[1] as $row_html ) {
			preg_match_all( '/<t[hd][^>]*>(.*?)<\/t[hd]>/si', $row_html, $cell_matches );
			if ( empty( $cell_matches[1] ) ) {
				continue;
			}

			$cells  = array_map( function ( $c ) { return trim( wp_strip_all_tags( $c ) ); }, $cell_matches[1] );
			$rows[] = '| ' . implode( ' | ', $cells ) . ' |';

			if ( $is_header ) {
				$separator = array_map( function ( $c ) { return str_repeat( '-', max( 3, strlen( $c ) ) ); }, $cells );
				$rows[]    = '| ' . implode( ' | ', $separator ) . ' |';
				$is_header = false;
			}
		}

		return "\n" . implode( "\n", $rows ) . "\n";
	}

	// ── Query builder ─────────────────────────────────────────────────────────

	/**
	 * Build WP_Query args for llms.txt post retrieval.
	 *
	 * Applies:
	 * - Post type and publish status filter
	 * - Rank Math noindex meta_query exclusion (query-level)
	 * - Taxonomy exclusions from admin settings (category, tag)
	 *
	 * @param string $post_type    Post type slug.
	 * @param int    $max_posts    Max posts to retrieve.
	 * @param array  $exclude_cats Category term IDs to exclude.
	 * @param array  $exclude_tags Tag term IDs to exclude.
	 * @return array WP_Query compatible args.
	 */
	private static function build_llms_query( string $post_type, int $max_posts, array $exclude_cats, array $exclude_tags ): array {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => $max_posts,
			// Freshest content first: order by last-modified, not publish date.
			// Each list line shows "(updated: <modified>)", so sorting by modified
			// keeps the displayed freshness signal consistent with the ordering — a
			// recently refreshed post surfaces at the top for AI crawlers, instead of
			// being buried just because it was first published long ago.
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		// Exclude Rank Math noindex posts at query level.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Filtering by AI-readiness meta; intentional and bounded by post_type.
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => 'rank_math_robots',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'rank_math_robots',
					'value'   => 'noindex',
					'compare' => 'NOT LIKE',
				),
			);
		}

		// Taxonomy exclusions from admin settings.
		$tax_query = array();

		if ( ! empty( $exclude_cats ) ) {
			$tax_query[] = array(
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', $exclude_cats ),
				'operator' => 'NOT IN',
			);
		}

		if ( ! empty( $exclude_tags ) ) {
			$tax_query[] = array(
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', $exclude_tags ),
				'operator' => 'NOT IN',
			);
		}

		if ( ! empty( $tax_query ) ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Filtering by category exclusion; bounded by post_type + cached internally.
			$args['tax_query'] = $tax_query;
		}

		return $args;
	}

	// ── Post exclusion logic ──────────────────────────────────────────────────

	/**
	 * Whether llms.txt post links should use .md URLs (when Markdown is on).
	 */
	public static function use_md_urls_in_index(): bool {
		return 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' )
			&& 'on' === get_option( RNRD_OPT_LLMS_USE_MD_URLS, 'on' );
	}

	/**
	 * Permalink for a post row in llms.txt — HTML or .md depending on settings.
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function entry_url_for_post( WP_Post $post ): string {
		$url = get_permalink( $post );
		if ( ! self::use_md_urls_in_index() || ! class_exists( 'RNRD_Markdown' ) ) {
			return (string) $url;
		}

		if ( ! RNRD_Markdown::post_has_servable_md_url( $post ) ) {
			return (string) $url;
		}

		return RNRD_Markdown::get_md_url( $post );
	}

	/**
	 * Check if a post should be excluded from llms.txt output.
	 *
	 * Only excludes posts marked noindex by SEO plugins. Everything else
	 * is controlled via taxonomy settings in the admin (Exclude Categories,
	 * Exclude Tags) and post type selection.
	 *
	 * @param WP_Post $post The post to check.
	 * @return bool True if the post should be excluded.
	 */
	// v1.1.5 — made public so RNRD_OKF reuses the same exclusion rules (per-post
	// "Exclude this post from AI surfaces" toggle + Yoast/Rank Math/AIOSEO/SEOPress noindex) as the
	// single source of truth for what belongs on an AI-readable surface.
	public static function should_exclude_from_llms( WP_Post $post ): bool {
		$post_id = $post->ID;

		// ── Per-post RankReady opt-out (v1.2.0) ──────────────────────────
		// Editors can tick "Exclude this post from AI surfaces" in the meta box.
		if ( '1' === (string) get_post_meta( $post_id, RNRD_META_LLMS_EXCLUDE, true ) ) {
			return true;
		}

		// ── WooCommerce cart, checkout and my-account (visitor session pages) ──
		// One rule here covers llms.txt, llms-full.txt, the .md endpoint, the
		// OKF bundle and the WebMCP manifest, because they all call this method.
		if ( RNRD_Util::is_commerce_session_page( $post ) ) {
			return true;
		}

		// ── Yoast noindex ────────────────────────────────────────────────
		if ( defined( 'WPSEO_VERSION' ) ) {
			$yoast_noindex = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
			if ( '1' === $yoast_noindex ) {
				return true;
			}
		}

		// ── AIOSEO noindex ───────────────────────────────────────────────
		if ( defined( 'AIOSEO_VERSION' ) ) {
			// v1.1.5 (#8) — AIOSEO v4 stores per-post robots in its own
			// {prefix}_aioseo_posts table (robots_default + robots_noindex), NOT postmeta.
			// The old _aioseo_noindex postmeta check never fired on v4, so AIOSEO-noindexed
			// posts leaked into llms.txt / llms-full.txt. A post counts as noindex only when
			// it overrides the global default (robots_default = 0) AND robots_noindex = 1.
			// (Global-default noindex is intentionally not resolved here — that mirrors the
			// SEO plugin's own per-post override semantics; the explicit toggle is what users set.)
			global $wpdb;
			$aioseo_robots = $wpdb->get_row( $wpdb->prepare( "SELECT robots_default, robots_noindex FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- AIOSEO keeps no postmeta mirror; called during the (transient-cached) llms.txt build
			if ( $aioseo_robots && ! (int) $aioseo_robots->robots_default && (int) $aioseo_robots->robots_noindex ) {
				return true;
			}

			// Back-compat: AIOSEO v3 (and pre-migration installs) used postmeta.
			if ( '1' === (string) get_post_meta( $post_id, '_aioseo_noindex', true ) ) {
				return true;
			}
		}

		// ── SEOPress noindex ─────────────────────────────────────────────
		if ( ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) ) ) {
			$sp_noindex = get_post_meta( $post_id, '_seopress_robots_index', true );
			if ( 'yes' === $sp_noindex ) {
				return true;
			}
		}

		// ── Rank Math noindex (secondary check — primary is in meta_query) ──
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm_robots = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true ) ) {
				return true;
			}
		}

		/**
		 * Filter to exclude specific posts from llms.txt.
		 *
		 * @param bool    $exclude Whether to exclude the post (default false).
		 * @param WP_Post $post    The post being checked.
		 */
		return (bool) apply_filters( 'rankready_exclude_from_llms', false, $post );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function clean_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		// Collapse runs of spaces/tabs within each line, but preserve newlines.
		$text = preg_replace( '/[^\S\n]+/', ' ', $text );
		// Collapse 3+ consecutive newlines to 2.
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Flatten a value that will be interpolated into a SINGLE Markdown line.
	 *
	 * clean_text() deliberately preserves newlines (llms.txt has multi-line
	 * brand/about sections), but titles, category names, CPT section labels,
	 * and descriptions are spliced into one `# Heading`, `## Section`, or
	 * `- [title](url): desc` line. An Author-level user can put newlines in a
	 * post title via REST / wp_insert_post() / importers (classic + Gutenberg
	 * strip them client-side) and forge extra `## Section` headings in the
	 * public file that AI crawlers treat as the site's authoritative index.
	 *
	 * Descriptions were hardened first; titles and other single-line fields
	 * use the same flattener.
	 *
	 * @param string $text Cleaned text that may contain newlines.
	 * @return string Single-line, length-capped text.
	 */
	private static function flatten_for_list_line( string $text ): string {
		$text = preg_replace( '/\s*\R\s*/u', ' ', $text );
		$text = trim( preg_replace( '/\s{2,}/u', ' ', (string) $text ) );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > 300 ) {
			$text = rtrim( mb_substr( $text, 0, 300, 'UTF-8' ) ) . '…';
		}

		return $text;
	}

	private static function get_post_description( $post ): string {
		// Try Yoast.
		$yoast = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		if ( ! empty( $yoast ) ) {
			return self::flatten_for_list_line( self::clean_text( RNRD_Util::strip_shortcode_markup( (string) $yoast ) ) );
		}

		// Try Rank Math.
		$rankmath = get_post_meta( $post->ID, 'rank_math_description', true );
		if ( ! empty( $rankmath ) ) {
			return self::flatten_for_list_line( self::clean_text( RNRD_Util::strip_shortcode_markup( (string) $rankmath ) ) );
		}

		// Try AIOSEO.
		$aioseo = get_post_meta( $post->ID, '_aioseo_description', true );
		if ( ! empty( $aioseo ) ) {
			return self::flatten_for_list_line( self::clean_text( RNRD_Util::strip_shortcode_markup( (string) $aioseo ) ) );
		}

		// Excerpt.
		if ( ! empty( $post->post_excerpt ) ) {
			$excerpt = self::clean_text( RNRD_Util::strip_shortcode_markup( (string) $post->post_excerpt ) );
			if ( '' !== $excerpt ) {
				return self::flatten_for_list_line( $excerpt );
			}
		}

		// Auto excerpt. Shortcodes are stripped, not executed: running them
		// here published whatever a shortcode printed for the current request
		// (an account dashboard, a members-only block) as the page description.
		$content = RNRD_Util::strip_tags_spaced( RNRD_Util::strip_shortcode_markup( (string) $post->post_content ) );
		return self::flatten_for_list_line( self::clean_text( wp_trim_words( $content, 30, '...' ) ) );
	}
}
