<?php
/**
 * Cache Layer Compatibility — bypass headers and purge operations.
 *
 * Covers every major WordPress cache plugin and CDN/proxy layer so
 * RankReady's content-negotiated endpoints (markdown, llms.txt, homepage)
 * are never served stale by an intermediate cache.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 *
 * A single Cache-Control header is not enough. Each cache layer reads a
 * different header and has its own bypass logic:
 *
 *   CDN / edge layer (intercepts before origin server):
 *     Cloudflare APO       → cf-edge-cache: no-cache
 *                            APO ignores CDN-Cache-Control entirely. This is
 *                            the ONLY header that reliably bypasses APO from PHP.
 *     Cloudflare non-APO   → CDN-Cache-Control: no-store
 *     BunnyCDN             → CDN-Cache-Control: no-store
 *     Varnish / Fastly     → Surrogate-Control: no-store
 *     Akamai               → Edge-Control: no-store
 *
 *   Server-side proxy / FastCGI cache (nginx / Apache module):
 *     nginx FastCGI cache  → X-Accel-Expires: 0  (0 = bypass entirely)
 *     Nginx Helper plugin  → rt_nginx_helper_purge_url action
 *
 *   PHP page-cache plugins (write cached HTML files or serve from Redis):
 *     WP Rocket            → DONOTCACHEPAGE constant
 *     W3 Total Cache       → DONOTCACHEPAGE + DONOTCACHEOBJECT + DONOTCACHEDB
 *     LiteSpeed Cache      → LSCWP_NO_CACHE constant (separate from DONOTCACHEPAGE)
 *     WP Super Cache       → DONOTCACHEPAGE constant
 *     WP Fastest Cache     → WpFastestCache::deleteCache()
 *     Breeze (Cloudways)   → breeze_clear_all_cache action
 *     SG Optimizer         → sg_cachepress_purge_cache function
 *     Hummingbird          → wphb_clear_cache_url action
 *     Comet Cache          → comet_cache::clear()
 *     Cache Enabler        → cache_enabler_clear_page_cache_by_post action
 *     Swift Performance    → swift_performance_after_clear_all_cache action
 *
 *   Hosting-level edge cache (Pantheon, WP Engine, Kinsta):
 *     Pantheon             → pantheon_clear_edge_paths function
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

// File-scoped suppression. This entire class is the integration layer with
// EVERY major WordPress page-cache plugin and CDN. Each do_action() call below
// is to another plugin's PUBLISHED action name (litespeed_purge_all,
// w3tc_flush_all, breeze_clear_all_cache, wphb_clear_cache, cache_enabler_*,
// nitropack_*, autoptimize_*, swift_performance_*). We MUST use their hook
// names verbatim to trigger their cache-purge APIs — prefixing them would
// break the integration entirely. Same rationale as how Yoast / Rank Math /
// AIOSEO call into each other's hooks.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

class RNRD_Cache {

	/**
	 * Wire compatibility hooks for asset optimisers and minifiers.
	 *
	 * Runtime cache bypass for endpoint responses is handled directly by each
	 * endpoint class. This init() is for ambient compat — asset combiners
	 * (Autoptimize, WP Rocket file optimisation, LiteSpeed CSS combine) that
	 * would otherwise rewrite RankReady's admin.js into a combined bundle and
	 * break our AJAX save handler.
	 *
	 * @since 1.2.1 (FREE-99)
	 */
	public static function init(): void {
		// v1.2.0 — SWIS Performance exclusion notice. SWIS's static-file page
		// cache has no programmatic bypass — the only fix is the
		// SWIS_CACHE_EXCLUSIONS wp-config constant, which a plugin cannot set.
		// Surface a dismissible admin notice with the exact define() snippet,
		// same pattern as the Cloudflare APO notice.
		if ( is_admin() ) {
			add_action( 'admin_notices', array( self::class, 'maybe_swis_notice' ) );
			add_action( 'admin_init',    array( self::class, 'handle_swis_dismiss' ) );
		}

		// Asset optimisers match these by substring, so the paths are built from
		// the folder this copy is actually installed in. WP.org installs live in
		// rankready-ai-llm-seo/, older GitHub installs in rankready/; a hardcoded
		// rankready/ never matched the WP.org folder.
		$dir = dirname( RNRD_BASENAME );

		// Autoptimize — exclude RankReady admin assets from JS/CSS combine.
		// AJAX save handler in admin.js depends on rnrdAjax localized object,
		// which Autoptimize's combine can break by moving the script tag.
		add_filter( 'autoptimize_filter_js_exclude', function ( $exclude ) use ( $dir ) {
			$exclude .= ', ' . $dir . '/assets/admin.js';
			return $exclude;
		} );
		add_filter( 'autoptimize_filter_css_exclude', function ( $exclude ) use ( $dir ) {
			$exclude .= ', ' . $dir . '/assets/admin.css, ' . $dir . '/assets/design-tokens.css';
			return $exclude;
		} );

		// LiteSpeed CSS/JS combine — exclude RankReady admin assets.
		add_filter( 'litespeed_optm_js_defer_exc', function ( $exc ) use ( $dir ) {
			$exc[] = $dir . '/assets/admin.js';
			return $exc;
		} );

		// WP Rocket file optimisation — exclude admin assets from combine + delay JS.
		add_filter( 'rocket_exclude_js', function ( $exclude ) {
			$exclude[] = (string) wp_parse_url( plugins_url( 'assets/admin.js', RNRD_FILE ), PHP_URL_PATH );
			return $exclude;
		} );
		add_filter( 'rocket_delay_js_exclusions', function ( $exclude ) use ( $dir ) {
			$exclude[] = $dir . '/assets/admin.js';
			return $exclude;
		} );

		// SG Optimizer — exclude from JS combine.
		add_filter( 'sgo_js_minify_exclude', function ( $scripts ) {
			$scripts[] = 'rankready-admin-js';
			return $scripts;
		} );
		add_filter( 'sgo_javascript_combine_exclude', function ( $scripts ) {
			$scripts[] = 'rankready-admin-js';
			return $scripts;
		} );
	}

	// ── Bypass headers ────────────────────────────────────────────────────────

	/**
	 * Tell every page-cache plugin to never cache the listed URL patterns.
	 *
	 * Unlike no_cache_headers() — which forces no-store on a single response —
	 * this method hooks each cache plugin's "reject URI" filter so the URLs
	 * are excluded BEFORE they hit the disk cache. Use for endpoints that
	 * RankReady caches itself (llms.txt has its own transient + Cache-Control:
	 * public, max-age=3600) and where the WP page-cache layer would otherwise
	 * stomp the response.
	 *
	 * Page builders like Bricks also leave these endpoints alone because the
	 * template_redirect handlers (registered at priority 1 in v1.2.0-rc.2)
	 * exit() before the theme runs.
	 *
	 * @param string[] $patterns Path prefixes ('/llms.txt', '/llms-full.txt', '/.well-known/mcp.json') or regex fragments.
	 * @since 1.2.0-rc.2
	 */
	public static function exclude_url_patterns( array $patterns ): void {
		$patterns = array_values( array_filter( array_map( 'strval', $patterns ) ) );
		if ( empty( $patterns ) ) {
			return;
		}

		// LiteSpeed Cache — runtime filter (covers in-process requests).
		// NOTE: runtime filter alone is insufficient — LSWS server-level
		// LSCACHE module reads the persisted `litespeed.conf.cache-exc` option
		// via .htaccess BEFORE PHP runs. persist_litespeed_exclusions() handles
		// the persisted side. See rc.16 audit C2.
		add_filter( 'litespeed_excluded_url', function ( $existing ) use ( $patterns ) {
			return array_values( array_unique( array_merge( (array) $existing, $patterns ) ) );
		} );
		// Defense in depth — LiteSpeed also reads a control filter.
		add_action( 'litespeed_control_set_nocache', function ( $reason = '' ) {
			// no-op — just gives LS a signal RankReady is in charge.
		} );

		// WP Rocket — reject URI regex array.
		add_filter( 'rocket_cache_reject_uri', function ( $existing ) use ( $patterns ) {
			$regex = array_map( function ( $p ) { return '(' . preg_quote( $p, '/' ) . ')'; }, $patterns );
			return array_values( array_unique( array_merge( (array) $existing, $regex ) ) );
		} );

		// W3 Total Cache — page cache reject URI array.
		add_filter( 'w3tc_pagecache_reject_uri', function ( $existing ) use ( $patterns ) {
			return array_values( array_unique( array_merge( (array) $existing, $patterns ) ) );
		} );

		// WP Super Cache — uri reject patterns.
		// rc.16 audit fix H2: the previous global-mutation approach only
		// affected request-local state; WPSC reads `cache_rejected_uri` from
		// its persisted config BEFORE PHP runs on cached pages. We now both
		// (a) mutate the global as a runtime defense for the current request
		// and (b) persist to WPSC's settings via persist_wpsc_exclusions().
		add_filter( 'wp_cache_get_cookies_values', function ( $string ) use ( $patterns ) {
			global $cache_rejected_uri;
			if ( is_array( $cache_rejected_uri ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP Super Cache global; reading it is part of the integration handshake.
				$cache_rejected_uri = array_values( array_unique( array_merge( $cache_rejected_uri, $patterns ) ) );
			}
			return $string;
		} );

		// WP Fastest Cache — rule array.
		add_filter( 'wpfc_exclude_url', function ( $excluded ) use ( $patterns ) {
			return array_values( array_unique( array_merge( (array) $excluded, $patterns ) ) );
		} );

		// SG Optimizer — uses `sg_optimizer_dynamic_cache_excluded_urls`.
		add_filter( 'sg_optimizer_dynamic_cache_excluded_urls', function ( $excluded ) use ( $patterns ) {
			return array_values( array_unique( array_merge( (array) $excluded, $patterns ) ) );
		} );

		// Breeze (Cloudways) — reject URI option filter.
		add_filter( 'breeze_rules_cache_excluded_url', function ( $excluded ) use ( $patterns ) {
			return array_values( array_unique( array_merge( (array) $excluded, $patterns ) ) );
		} );

		// Cache Enabler — also reads URI exclusion.
		add_filter( 'cache_enabler_bypass_cache', function ( $bypass ) use ( $patterns ) {
			if ( $bypass ) {
				return $bypass;
			}
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			foreach ( $patterns as $p ) {
				if ( false !== strpos( $uri, $p ) ) {
					return true;
				}
			}
			return $bypass;
		} );

		// rc.16 audit fix M1 — NitroPack bypass.
		// NitroPack ships its own edge cache that ignores DONOTCACHEPAGE.
		// The `nitropack_should_skip_cache` filter is the supported escape hatch.
		add_filter( 'nitropack_should_skip_cache', function ( $skip ) use ( $patterns ) {
			if ( $skip ) {
				return $skip;
			}
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			foreach ( $patterns as $p ) {
				if ( false !== strpos( $uri, $p ) ) {
					return true;
				}
			}
			return $skip;
		} );

		// rc.16 audit fix M1 — Perfmatters URL exclusion. Perfmatters mostly
		// optimises CSS/JS; defensive only — its CDN rewrite shouldn't touch
		// .txt/.md/.json content types but we exclude to be explicit.
		add_filter( 'perfmatters_excluded_urls', function ( $excluded ) use ( $patterns ) {
			return array_values( array_unique( array_merge( (array) $excluded, $patterns ) ) );
		} );

		// rc.16 audit fix M1 — Hummingbird page-cache exclusion.
		add_filter( 'wphb_should_cache_request', function ( $should_cache ) use ( $patterns ) {
			if ( ! $should_cache ) {
				return $should_cache;
			}
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			foreach ( $patterns as $p ) {
				if ( false !== strpos( $uri, $p ) ) {
					return false;
				}
			}
			return $should_cache;
		} );
	}

	/**
	 * Persist exclusion rules to each cache plugin's saved option.
	 *
	 * Runtime filters in exclude_url_patterns() only affect the in-process
	 * request. Server-level caches (LiteSpeed Web Server, FastCGI cache) read
	 * the persisted option BEFORE PHP runs — so cold cache hits would bypass
	 * our runtime filter entirely.
	 *
	 * Call this on activation and on plugin upgrade (version-mismatch path in
	 * plugins_loaded). Safe to re-enter: short-circuits when nothing changed.
	 *
	 * @since 1.2.0-rc.16
	 * @param string[] $patterns Path fragments to persist.
	 */
	public static function persist_exclusions( array $patterns ): void {
		$patterns = array_values( array_filter( array_map( 'strval', $patterns ) ) );
		if ( empty( $patterns ) ) {
			return;
		}

		// ── LiteSpeed Cache / LSWS — persisted exclusion list ─────────────
		// Read by LSWS via .htaccess LSCACHE module BEFORE PHP runs.
		// Without this, a cold-cache /llms.txt request gets a stale HTML
		// response from disk and our headers never fire. (Audit C2.)
		if ( defined( 'LSCWP_V' ) || defined( 'LITESPEED_VERSION' ) ) {
			$existing = (array) get_option( 'litespeed.conf.cache-exc', array() );
			$merged   = array_values( array_unique( array_merge( $existing, $patterns ) ) );
			if ( $merged !== $existing ) {
				update_option( 'litespeed.conf.cache-exc', $merged );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache published action; must use its name to integrate.
				do_action( 'litespeed_purge_all' );
			}
		}

		// ── WP Super Cache — persisted reject URI ─────────────────────────
		// (Audit H2 + cache-compat M4.)
		// In WPSC mod_rewrite mode (the default for performance), .htaccess
		// rules serve cached HTML files directly — the runtime filter never
		// fires. We must rewrite the .htaccess after persisting the option
		// so the new rejection patterns actually take effect at the server
		// level on the very next request.
		if ( function_exists( 'wp_cache_setting' ) ) {
			$existing = (array) wp_cache_setting( 'cache_rejected_uri', array() );
			$merged   = array_values( array_unique( array_merge( $existing, $patterns ) ) );
			if ( $merged !== $existing ) {
				wp_cache_setting( 'cache_rejected_uri', $merged );
				if ( function_exists( 'wp_cache_update_rewrite_rules' ) ) {
					wp_cache_update_rewrite_rules();
				}
			}
		}

		// ── WP Rocket — persisted reject URI ─────────────────────────────
		if ( defined( 'WP_ROCKET_VERSION' ) && function_exists( 'get_rocket_option' ) && function_exists( 'update_rocket_option' ) ) {
			$existing = (array) get_rocket_option( 'cache_reject_uri', array() );
			$regex    = array_map( function ( $p ) { return preg_quote( $p, '/' ); }, $patterns );
			$merged   = array_values( array_unique( array_merge( $existing, $regex ) ) );
			if ( $merged !== $existing ) {
				update_rocket_option( 'cache_reject_uri', $merged );
			}
		}

		// ── W3 Total Cache — persisted reject URI ─────────────────────────
		if ( defined( 'W3TC' ) && class_exists( '\\W3TC\\Dispatcher' ) ) {
			try {
				$config   = \W3TC\Dispatcher::config();
				$existing = (array) $config->get_array( 'pgcache.reject.uri' );
				$merged   = array_values( array_unique( array_merge( $existing, $patterns ) ) );
				if ( $merged !== $existing ) {
					$config->set( 'pgcache.reject.uri', $merged );
					$config->save();
				}
			} catch ( \Throwable $e ) {
				// W3TC API surface shifts across versions — skip on error.
			}
		}
	}

	/**
	 * Lightweight variant of no_cache_headers() — sets page-cache bypass
	 * constants WITHOUT overriding Cache-Control. Use when RankReady wants
	 * to control its own caching via response headers (llms.txt at 1h, mcp
	 * manifest at 5min) but doesn't want WP page-cache plugins to layer
	 * their own cache on top.
	 *
	 * @since 1.2.0-rc.2
	 */
	public static function bypass_page_cache_plugins_only(): void {
		// Tell every plugin that respects DONOTCACHEPAGE / LSCWP_NO_CACHE to
		// skip this response. We're NOT setting Cache-Control here — that's
		// the caller's responsibility.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress-standard cache-bypass constant respected by all major page-cache plugins.
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- W3 Total Cache standard bypass constant.
			define( 'DONOTCACHEOBJECT', true );
		}
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- W3 Total Cache standard bypass constant.
			define( 'DONOTCACHEDB', true );
		}
		if ( ! defined( 'LSCWP_NO_CACHE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- LiteSpeed Cache plugin's published bypass constant.
			define( 'LSCWP_NO_CACHE', true );
		}

		// rc.16 audit fix C2 + M5 — emit LSWS server-level bypass headers.
		// The LSCWP_NO_CACHE constant only signals the LSCWP PHP plugin.
		// LiteSpeed Web Server (LSWS) reads these response headers to decide
		// whether to store the response at the server level. Without these,
		// LSWS will cache /llms.txt + /.well-known/mcp.json on disk.
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			header( 'X-LiteSpeed-Tag: rnrd-dynamic' );
		}
	}

	/**
	 * v1.0.1 — Canonical no-cache header set for dynamic endpoints.
	 *
	 * Audit reference: see docs/CACHE-HEADER-AUDIT.md. Headers in Title-Case
	 * (RFC 9110 convention; case-insensitive in transport, but enterprise tools
	 * present consistent casing). Each layer reads a different header — every
	 * one of the directives below points the same direction (no-cache /
	 * no-store), so no conflicts arise even when multiple layers coexist.
	 *
	 * Call this early (before any output) on every RankReady virtual endpoint
	 * that uses content negotiation so the correct variant (markdown vs HTML)
	 * always reaches the client. Safe to call multiple times: headers_sent()
	 * and defined() guards prevent duplicate headers and constant
	 * re-declaration errors.
	 */
	public static function no_cache_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		// ── HTTP standard (RFC 9111) ─────────────────────────────────────────
		// `no-store` is the strongest directive; `no-cache` blocks reuse without
		// revalidation; `must-revalidate` forbids serving stale responses.
		// Combined, they cover every HTTP/1.1 cache and browser.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );

		// Belt-and-suspenders for ancient HTTP/1.0 proxies that ignore
		// Cache-Control. Modern caches ignore Expires when Cache-Control is set.
		header( 'Expires: 0' );

		// NOTE — Pragma: no-cache removed (was here pre-v1.0.1). Per RFC 9111
		// §5.4, Pragma is a request-direction header; sending it on a response
		// is a spec violation. Modern caches ignore it; legacy ones honour
		// Expires: 0 anyway.

		// ── CDN / reverse-proxy ──────────────────────────────────────────────
		// Cloudflare APO: the only header APO actually reads. CDN-Cache-Control
		// alone does not stop APO.
		header( 'CF-Edge-Cache: no-cache' );

		// Cloudflare standard cache (non-APO): Cloudflare's own override that
		// wins over CDN-Cache-Control when both are present.
		header( 'Cloudflare-CDN-Cache-Control: no-store' );

		// Generic CDN cache directive (Bunny, KeyCDN, Cloudflare non-APO).
		header( 'CDN-Cache-Control: no-store' );

		// Varnish / Fastly / any RFC-5861-compatible surrogate cache.
		header( 'Surrogate-Control: no-store' );

		// Akamai — both the modern header and the legacy one for max coverage.
		header( 'Akamai-Cache-Control: no-store' );
		header( 'Edge-Control: no-store' );

		// ── Server / FastCGI ─────────────────────────────────────────────────
		// Nginx FastCGI cache — `0` means do not store this response at all.
		header( 'X-Accel-Expires: 0' );

		// LiteSpeed Web Server (LSWS) — reads response headers to decide
		// on-disk caching. Distinct from the LSCWP PHP plugin constant.
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-LiteSpeed-Tag: rnrd-dynamic' );

		// ── PHP page-cache plugin constants ──────────────────────────────────

		// All major WP page-cache plugins respect DONOTCACHEPAGE as an early
		// bail-out: WP Rocket, W3TC, WP Super Cache, WP Fastest Cache,
		// Cache Enabler, Comet Cache, Breeze, SG Optimizer.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Cross-plugin standard cache-bypass constant.
			define( 'DONOTCACHEPAGE', true );
		}

		// W3 Total Cache: separate constants for object cache and DB cache.
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- W3 Total Cache standard bypass constant.
			define( 'DONOTCACHEOBJECT', true );
		}
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- W3 Total Cache standard bypass constant.
			define( 'DONOTCACHEDB', true );
		}

		// LiteSpeed Cache: uses its own constant, does not check DONOTCACHEPAGE.
		if ( ! defined( 'LSCWP_NO_CACHE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- LiteSpeed Cache plugin's published bypass constant.
			define( 'LSCWP_NO_CACHE', true );
		}
	}

	// ── Cache purge ───────────────────────────────────────────────────────────

	/**
	 * Purge a single URL from every active cache layer.
	 *
	 * Uses function_exists / class_exists guards before every call — safe on
	 * any WordPress install regardless of which plugins are active.
	 * Unknown plugins / hosting environments are silently skipped.
	 *
	 * @param string $url Absolute URL to purge (e.g. home_url('/robots.txt')).
	 */
	public static function purge_url( string $url ): void {
		// WP Rocket — precise single-file purge.
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( [ $url ] );
		}

		// LiteSpeed Cache.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed published action; integration.
		do_action( 'litespeed_purge_url', $url );

		// W3 Total Cache.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- W3 Total Cache published action; integration.
		do_action( 'w3tc_flush_url', $url );

		// WP Super Cache — no per-URL API; clears the full site cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// WP Fastest Cache.
		if ( class_exists( 'WpFastestCache' ) && method_exists( 'WpFastestCache', 'deleteCache' ) ) {
			( new \WpFastestCache() )->deleteCache( true );
		}

		// Cloudflare official WP plugin (non-APO).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cloudflare plugin published action; integration.
		do_action( 'cloudflare_purge_by_url', [ $url ] );

		// Cloudflare APO — official plugin's `cloudflare_purge_by_url` is
		// non-APO only. APO holds the response at the edge for up to 30 days
		// and ignores `cf-edge-cache: no-cache` once cached. The supported
		// purge path is the Cloudflare API: POST /zones/{zone}/purge_cache.
		// We only call it when the user has stored credentials via the
		// official Cloudflare plugin (filter exposed for advanced users).
		if ( defined( 'CLOUDFLARE_APO' ) || apply_filters( 'rankready_cloudflare_apo_active', false ) ) {
			$zone  = (string) apply_filters( 'rankready_cloudflare_zone_id', '' );
			$token = (string) apply_filters( 'rankready_cloudflare_api_token', '' );
			if ( '' !== $zone && '' !== $token ) {
				wp_remote_post(
					'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ) . '/purge_cache',
					array(
						'timeout' => 5,
						'headers' => array(
							'Authorization' => 'Bearer ' . $token,
							'Content-Type'  => 'application/json',
						),
						'body'    => wp_json_encode( array( 'files' => array( $url ) ) ),
					)
				);
			}
		}

		// Nginx Helper / FastCGI cache.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Nginx Helper published action; integration.
		do_action( 'rt_nginx_helper_purge_url', $url );

		// SG Optimizer (SiteGround).
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		// Breeze (Cloudways).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze published action; integration.
		do_action( 'breeze_clear_all_cache' );

		// Hummingbird (WPMU Dev).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hummingbird published action; integration.
		do_action( 'wphb_clear_cache_url', $url );

		// Cache Enabler — needs a post ID. url_to_postid() dereferences
		// $wp_rewrite, which does not exist before init; a purge that runs
		// earlier skips this one lookup instead of taking the request down.
		$post_id = ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof WP_Rewrite ) ? url_to_postid( $url ) : 0;
		if ( $post_id > 0 ) {
			do_action( 'cache_enabler_clear_page_cache_by_post', $post_id );
		}

		// Comet Cache.
		if ( class_exists( 'comet_cache' ) && method_exists( 'comet_cache', 'clear' ) ) {
			\comet_cache::clear();
		}

		// Swift Performance.
		do_action( 'swift_performance_after_clear_all_cache' );

		// Autoptimize — handles CSS/JS asset cache, relevant when URL changes
		// invalidate inlined or combined assets.
		do_action( 'autoptimize_action_cachepurged' );

		// Pantheon / Pressable hosting edge cache.
		if ( function_exists( 'pantheon_clear_edge_paths' ) ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' !== $path ) {
				pantheon_clear_edge_paths( [ $path ] );
			}
		}

		// NitroPack — per-URL invalidation hook.
		do_action( 'nitropack_purge_individual_url', $url );

		// Kinsta — full-site purge (Kinsta has no per-URL PHP API exposed).
		if ( class_exists( '\\Kinsta\\Cache' ) && method_exists( '\\Kinsta\\Cache', 'purge_complete_caches' ) ) {
			try {
				\Kinsta\Cache::purge_complete_caches();
			} catch ( \Throwable $e ) {
				// Kinsta API surface varies — best effort.
			}
		}

		// WP Engine — edge cache via WpeCommon helper (present on WPE hosts).
		if ( class_exists( '\\WpeCommon' ) && method_exists( '\\WpeCommon', 'purge_varnish_cache' ) ) {
			try {
				\WpeCommon::purge_varnish_cache();
			} catch ( \Throwable $e ) {
				// no-op
			}
		}
	}

	/**
	 * Nuclear purge — clears ALL caches site-wide.
	 *
	 * Use on plugin activation / full-settings-save when multiple virtual
	 * endpoints may have changed simultaneously. Prefer purge_url() for
	 * targeted invalidation (e.g. a single robots.txt update).
	 */
	public static function purge_all(): void {
		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_all' );

		// W3 Total Cache.
		do_action( 'w3tc_flush_all' );

		// Breeze.
		do_action( 'breeze_clear_all_cache' );

		// SG Optimizer.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		// Hummingbird.
		do_action( 'wphb_clear_cache' );

		// Comet Cache.
		if ( class_exists( 'comet_cache' ) && method_exists( 'comet_cache', 'clear' ) ) {
			\comet_cache::clear();
		}

		// Swift Performance.
		do_action( 'swift_performance_after_clear_all_cache' );
	}

	/**
	 * Purge every RankReady-managed endpoint from CDN / page-cache layers.
	 *
	 * Covers /llms.txt, /llms-full.txt, /robots.txt, /mcp.json, /index.md,
	 * and the blog-index .md URL when applicable. Fires purge_url() for each,
	 * which dispatches to whichever cache plugin is active (LiteSpeed, Cloudflare,
	 * WP Rocket, Nginx Helper, Hummingbird, NitroPack, etc.).
	 *
	 * @since 1.2.2
	 */
	public static function purge_all_endpoints(): void {
		$urls = array(
			home_url( '/llms.txt' ),
			home_url( '/llms-full.txt' ),
			home_url( '/robots.txt' ),
			home_url( '/.well-known/mcp.json' ),
			home_url( '/index.md' ),
		);

		if ( 'on' === get_option( RNRD_OPT_MD_HOME_ENABLE, 'on' ) && class_exists( 'RNRD_Markdown' ) && 'page' === get_option( 'show_on_front' ) ) {
			$posts_page_id = (int) get_option( 'page_for_posts', 0 );
			if ( $posts_page_id > 0 ) {
				$posts_page = get_post( $posts_page_id );
				if ( $posts_page instanceof WP_Post ) {
					$urls[] = RNRD_Markdown::get_md_url( $posts_page );
				}
			}
		}

		/*
		 * The OKF bundle ships the longest edge TTL in the plugin (s-maxage=3600
		 * plus stale-while-revalidate=86400, so an edge may serve a stale bundle
		 * for up to 25 hours) and was the one endpoint family missing from this
		 * list, which meant a settings change that purged llms.txt left /okf/
		 * stale at the edge for a day. Only added when the bundle is actually
		 * enabled, so a site that never serves /okf/ does not pay for purge
		 * calls against a 404.
		 */
		if ( class_exists( 'RNRD_OKF' ) && RNRD_OKF::enabled() ) {
			$urls[] = home_url( '/okf/' );
			$urls[] = home_url( '/okf/index.md' );
			$urls[] = home_url( '/okf/log.md' );
		}

		$urls = (array) apply_filters( 'rankready_purge_urls', $urls );

		foreach ( $urls as $url ) {
			self::purge_url( $url );
		}
	}

	// ── Detection ─────────────────────────────────────────────────────────────

	/**
	 * Detect which page-cache plugins are currently active.
	 *
	 * Returns an array of slug => human-readable label.
	 * Used by the health-check dashboard to show the current cache environment
	 * so admins can verify RankReady's bypass is correctly configured.
	 *
	 * @return array<string, string>
	 */
	public static function detect_active(): array {
		$active = array();

		if ( defined( 'WP_ROCKET_VERSION' ) )                                              $active['wp-rocket']      = 'WP Rocket';
		if ( defined( 'LSCWP_V' ) )                                                        $active['litespeed']      = 'LiteSpeed Cache';
		if ( defined( 'W3TC_VERSION' ) )                                                   $active['w3tc']           = 'W3 Total Cache';
		if ( defined( 'WPCACHEHOME' ) )                                                    $active['wp-super-cache'] = 'WP Super Cache';
		if ( class_exists( 'WpFastestCache' ) )                                            $active['wp-fastest']     = 'WP Fastest Cache';
		if ( defined( 'SG_OPTIMIZER_DIR' ) )                                               $active['sg-optimizer']   = 'SG Optimizer';
		if ( defined( 'BREEZE_VERSION' ) )                                                 $active['breeze']         = 'Breeze';
		if ( class_exists( 'Hummingbird\\WP_Hummingbird' ) )                               $active['hummingbird']    = 'Hummingbird';
		if ( class_exists( 'autoptimizeCache' ) )                                          $active['autoptimize']    = 'Autoptimize';
		if ( defined( 'CE_FILE' ) )                                                        $active['cache-enabler']  = 'Cache Enabler';
		if ( class_exists( 'comet_cache' ) )                                               $active['comet-cache']    = 'Comet Cache';
		if ( class_exists( 'Swift_Performance_Lite' ) || class_exists( 'Swift_Performance' ) ) $active['swift']     = 'Swift Performance';
		if ( function_exists( 'pantheon_clear_edge_paths' ) )                              $active['pantheon']       = 'Pantheon Edge';
		// rc.16 audit fix M2 — Nginx Helper detection.
		if ( class_exists( 'Nginx_Helper' ) || defined( 'RT_WP_NGINX_HELPER_PATH' ) )      $active['nginx-helper']   = 'Nginx Helper';
		// rc.16 audit — NitroPack detection.
		if ( defined( 'NITROPACK_VERSION' ) )                                              $active['nitropack']      = 'NitroPack';
		// rc.16 audit — Perfmatters detection (caching-adjacent).
		if ( defined( 'PERFMATTERS_VERSION' ) )                                            $active['perfmatters']    = 'Perfmatters';
		// FREE-99 audit — Cloudflare APO, Kinsta, WP Engine detection.
		if ( defined( 'CLOUDFLARE_APO' ) || apply_filters( 'rankready_cloudflare_apo_active', false ) ) $active['cloudflare-apo'] = 'Cloudflare APO';
		if ( class_exists( '\\Kinsta\\Cache' ) )                                           $active['kinsta']         = 'Kinsta Edge';
		if ( class_exists( '\\WpeCommon' ) )                                               $active['wp-engine']      = 'WP Engine Edge';
		// v1.2.0 — SWIS Performance (Exactly WWW). Premium, closed-source; the
		// `swis()` global + `\SWIS\Cache` class are the canonical detection used
		// by its sibling plugin EWWW (ewww-image-optimizer/common.php:441). SWIS's
		// page cache is a server-level static-file cache with NO per-request
		// DONOTCACHEPAGE and NO public purge hook — exclusion is only via the
		// SWIS_CACHE_EXCLUSIONS wp-config constant, surfaced as an admin notice.
		if ( function_exists( 'swis' ) && class_exists( '\\SWIS\\Cache' ) )                $active['swis']           = 'SWIS Performance';

		return $active;
	}

	/**
	 * v1.2.0 — Dismissible admin notice for SWIS Performance.
	 *
	 * SWIS caches finished HTML to disk at the server layer; a PHP-set
	 * DONOTCACHEPAGE cannot reliably stop a static-file cache HIT, and SWIS
	 * exposes no purge action hook. The documented (and only) way to keep our
	 * dynamic endpoints out of its cache is the SWIS_CACHE_EXCLUSIONS wp-config
	 * constant — which a plugin cannot write. So we show the user the exact
	 * snippet to paste, scoped to RankReady screens and only when a dynamic
	 * endpoint is actually enabled.
	 *
	 * Sources: docs.ewww.io/article/87-swis-overrides (constant),
	 * docs.ewww.io/article/103-page-caching (static-file cache behaviour).
	 */
	public static function maybe_swis_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'rankready' ) ) {
			return;
		}

		// SWIS active?
		$active = self::detect_active();
		if ( ! isset( $active['swis'] ) ) {
			return;
		}

		// At least one dynamic endpoint enabled (otherwise nothing to exclude).
		$llms = 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$md   = 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' );
		$mcp  = 'on' === get_option( RNRD_OPT_MCP_ENABLE, 'off' );
		if ( ! $llms && ! $md && ! $mcp ) {
			return;
		}

		// Dismissed forever?
		if ( get_user_meta( get_current_user_id(), '_rnrd_swis_notice_dismissed', true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'rnrd_dismiss_swis_notice', '1', admin_url( 'admin.php?page=rankready-ai-llm-seo' ) ),
			'rnrd_dismiss_swis_notice',
			'_rnrd_nonce'
		);

		// Build the exclusion list from the endpoints that are actually on.
		$paths = array();
		if ( $llms ) {
			$paths[] = '/llms.txt';
			$paths[] = '/llms-full.txt';
		}
		if ( $mcp ) {
			$paths[] = '/.well-known/mcp.json';
		}
		if ( $md ) {
			$paths[] = '.md';
		}
		$snippet  = "define( 'SWIS_CACHE_EXCLUSIONS', array(\n";
		foreach ( $paths as $p ) {
			$snippet .= "\t'" . $p . "',\n";
		}
		$snippet .= ") );";
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php esc_html_e( 'SWIS Performance detected — add one line to wp-config.php so AI agents get fresh content.', 'rankready-ai-llm-seo' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'SWIS stores finished pages as static files, which can serve a cached copy of RankReady\'s dynamic endpoints (llms.txt, Markdown, the WebMCP manifest) to AI crawlers. SWIS has no per-request bypass or purge hook, so the fix is its documented exclusion constant. Add this to wp-config.php, above the "That\'s all, stop editing" line:', 'rankready-ai-llm-seo' ); ?>
			</p>
			<p><code style="display:block;white-space:pre;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;"><?php echo esc_html( $snippet ); ?></code></p>
			<p style="font-size:12.5px;color:#646970;">
				<?php esc_html_e( 'SWIS matches these as simple substrings of the request URL. Distinct .md URLs (e.g. /post-slug.md) are covered by the ".md" entry. After adding it, clear the SWIS cache once from the admin bar.', 'rankready-ai-llm-seo' ); ?>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:8px;"><?php esc_html_e( 'Don\'t show again', 'rankready-ai-llm-seo' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the "Don't show again" click on the SWIS notice.
	 * Hooked on admin_init so wp_safe_redirect() fires before any output.
	 */
	public static function handle_swis_dismiss(): void {
		if ( empty( $_GET['rnrd_dismiss_swis_notice'] ) || empty( $_GET['_rnrd_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed to wp_verify_nonce() which validates; unslashed.
		if ( ! wp_verify_nonce( wp_unslash( $_GET['_rnrd_nonce'] ), 'rnrd_dismiss_swis_notice' ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), '_rnrd_swis_notice_dismissed', 1 );
		wp_safe_redirect( remove_query_arg( array( 'rnrd_dismiss_swis_notice', '_rnrd_nonce' ) ) );
		exit;
	}

	/**
	 * Probe a public URL and inspect response headers for server-level cache HITs.
	 *
	 * PHP cannot detect nginx FastCGI cache, Varnish, Cloudflare APO, or LSWS
	 * server-only cache from inside WordPress — they sit in front of PHP. The
	 * only reliable signal is to fetch the URL externally and read the cache
	 * indicator headers (x-cache, age, cf-cache-status, x-litespeed-cache,
	 * x-proxy-cache).
	 *
	 * Returns one of:
	 *   ['status' => 'hit',     'layer' => 'cloudflare-apo', 'detail' => '...']
	 *   ['status' => 'miss',    'layer' => null,              'detail' => '...']
	 *   ['status' => 'unknown', 'layer' => null,              'detail' => 'no indicator headers']
	 *   ['status' => 'error',   'layer' => null,              'detail' => 'wp_remote_get error']
	 *
	 * @since 1.2.1
	 * @param string $url Absolute URL to probe (e.g. home_url('/llms.txt')).
	 * @return array{status: string, layer: ?string, detail: string, headers: array}
	 */
	public static function probe_edge_cache_status( string $url ): array {
		$response = wp_remote_get( $url, array(
			'timeout'     => 5,
			'redirection' => 1,
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
			'user-agent'  => 'RankReady-Diagnostic/1.0',
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'error', 'layer' => null, 'detail' => $response->get_error_message(), 'headers' => array() );
		}

		$headers = wp_remote_retrieve_headers( $response );
		$h       = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
		// Normalise to lowercase keys, scalar string values.
		$norm = array();
		foreach ( $h as $k => $v ) {
			$norm[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
		}

		// Indicators in priority order — most specific first.
		$signals = array(
			'cf-cache-status'      => 'cloudflare',
			'x-litespeed-cache'    => 'litespeed-server',
			'x-proxy-cache'        => 'nginx-proxy',
			'x-cache'              => 'generic-cdn',
			'x-cache-status'       => 'generic-cdn',
			'x-nginx-cache-status' => 'nginx-fastcgi',
			'x-varnish-cache'      => 'varnish',
		);

		foreach ( $signals as $header => $layer ) {
			if ( ! isset( $norm[ $header ] ) ) {
				continue;
			}
			$value = strtolower( $norm[ $header ] );
			if ( false !== strpos( $value, 'hit' ) ) {
				return array( 'status' => 'hit', 'layer' => $layer, 'detail' => $header . ': ' . $norm[ $header ], 'headers' => $norm );
			}
			if ( false !== strpos( $value, 'miss' ) || false !== strpos( $value, 'bypass' ) || false !== strpos( $value, 'dynamic' ) ) {
				return array( 'status' => 'miss', 'layer' => $layer, 'detail' => $header . ': ' . $norm[ $header ], 'headers' => $norm );
			}
		}

		// Age > 0 with no explicit miss indicator = something stored it.
		if ( isset( $norm['age'] ) && (int) $norm['age'] > 0 ) {
			return array( 'status' => 'hit', 'layer' => 'unknown-edge', 'detail' => 'Age: ' . $norm['age'], 'headers' => $norm );
		}

		return array( 'status' => 'unknown', 'layer' => null, 'detail' => 'No cache indicator headers in response', 'headers' => $norm );
	}

	// ── Server bypass snippets ───────────────────────────────────────────────

	/**
	 * Apache / LiteSpeed `.htaccess` bypass snippet for RankReady endpoints.
	 *
	 * Apply at server level when runtime headers aren't sufficient — i.e. when
	 * LiteSpeed Web Server caches a stale HTML response for /llms.txt before
	 * PHP runs. Surfaced in Diagnostics → "Server bypass snippet".
	 *
	 * @since 1.2.0-rc.16
	 */
	public static function apache_htaccess_snippet(): string {
		// FREE-102 — PCP disallows heredoc; use plain string concatenation.
		$lines = array(
			'# BEGIN RankReady — LiteSpeed/Apache cache bypass',
			'<IfModule LiteSpeed>',
			'  RewriteEngine On',
			'  RewriteRule ^llms(-full)?\\.txt$       - [E=cache-control:no-cache]',
			'  RewriteRule ^\\.well-known/mcp\\.json$  - [E=cache-control:no-cache]',
			'  RewriteRule \\.md$                     - [E=cache-control:no-cache]',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'  <FilesMatch "\\.(txt|md|json)$">',
			'    Header set X-LiteSpeed-Cache-Control "no-cache"',
			'    Header set Cache-Control             "no-store, no-cache, must-revalidate"',
			'  </FilesMatch>',
			'</IfModule>',
			'# END RankReady',
		);
		return implode( "\n", $lines );
	}

	/**
	 * Nginx server-block bypass snippet for RankReady endpoints.
	 *
	 * Use with FastCGI cache stacks (nginx + php-fpm) where header-based
	 * bypass via X-Accel-Expires alone is unreliable on cold cache builds.
	 *
	 * @since 1.2.0-rc.16
	 */
	public static function nginx_snippet(): string {
		// FREE-102 — PCP disallows heredoc; use plain string concatenation.
		$lines = array(
			'# RankReady — FastCGI cache bypass (add to your nginx server block)',
			'location ~ ^/(llms\\.txt|llms-full\\.txt|\\.well-known/mcp\\.json)$ {',
			'    fastcgi_cache_bypass 1;',
			'    fastcgi_no_cache     1;',
			'    add_header X-RR-Bypass "1" always;',
			'    try_files $uri $uri/ /index.php?$args;',
			'}',
			'location ~ \\.md$ {',
			'    fastcgi_cache_bypass 1;',
			'    fastcgi_no_cache     1;',
			'    add_header X-RR-Bypass "1" always;',
			'    try_files $uri $uri/ /index.php?$args;',
			'}',
		);
		return implode( "\n", $lines );
	}

	/**
	 * Nginx ACCESS snippet — allow /.well-known/ to reach WordPress.
	 *
	 * Distinct from nginx_snippet() (which is a cache bypass). Some nginx stacks
	 * (RunCloud defaults, hardened configs) deny every dot-path, returning 403
	 * for /.well-known/mcp.json even though WordPress would serve it. A `^~`
	 * prefix location outranks the dotfile-deny regex, so /.well-known/ is
	 * allowed and routed to WordPress. Add once, then reload nginx. No plugin
	 * can apply this automatically: nginx ignores .htaccess and its config needs
	 * a service reload outside WordPress. Verified against an nginx dotfile-deny.
	 *
	 * @since 1.2.1
	 */
	public static function nginx_well_known_snippet(): string {
		$lines = array(
			'# RankReady — allow /.well-known/ (fixes 403 on /.well-known/mcp.json)',
			'# Add to your nginx server { } block, then reload nginx.',
			'location ^~ /.well-known/ {',
			'    allow all;',
			'    try_files $uri $uri/ /index.php?$args;',
			'}',
		);
		return implode( "\n", $lines );
	}

	/**
	 * Cloudflare Cache Rule expression for content-negotiated Markdown.
	 *
	 * The problem: Cloudflare's default cache key does NOT vary by the
	 * `Accept` header — only by URL + Vary: Accept-Encoding. So once
	 * Cloudflare caches the HTML representation of a post, every subsequent
	 * request with `Accept: text/markdown` gets the cached HTML back, even
	 * though RankReady's origin correctly emits `Vary: Accept`.
	 *
	 * The fix: a single Cache Rule in Cloudflare dashboard that bypasses
	 * cache whenever the request asks for markdown. HTML requests still
	 * cache normally — both audiences served correctly.
	 *
	 * Returns the Cloudflare Cache Rule expression a user pastes into:
	 *   Cloudflare → Caching → Cache Rules → Create rule → Custom filter expression
	 *
	 * @since 1.0.1 (FREE-108)
	 */
	public static function cloudflare_cache_rule_snippet(): string {
		$lines = array(
			'# RankReady — Cloudflare Cache Rule for AI content negotiation',
			'#',
			'# Dashboard path: Cloudflare → Caching → Cache Rules → Create rule',
			'#',
			'# 1. Rule name:',
			'#    RankReady: bypass cache for AI markdown requests',
			'#',
			'# 2. When incoming requests match (Custom filter expression):',
			'(any(http.request.headers["accept"][*] contains "text/markdown"))',
			'#',
			'# 3. Then:',
			'#    Cache eligibility: Bypass cache',
			'#',
			'# Effect: requests with Accept: text/markdown bypass the edge cache,',
			'# always reach origin, get the markdown response. Regular browser',
			'# requests (Accept: text/html) still hit the cache as normal.',
			'#',
			'# Alternative — if you have Cloudflare Enterprise, add Accept to the',
			'# custom cache key instead:',
			'#   Cache Rules → Custom Cache Key → Headers → Accept',
		);
		return implode( "\n", $lines );
	}
}
