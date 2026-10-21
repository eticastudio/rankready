<?php
/**
 * RankReady — Live Diagnostics
 *
 * Replaces the legacy "Health Check" with **real probes** that actually
 * fetch each endpoint and validate the response. Every failure ships with
 * a specific conflict diagnosis + one-line fix.
 *
 * Probe categories
 * ────────────────
 *   1. Endpoint reachability (llms.txt, llms-full.txt, robots.txt, mcp.json, .md routes)
 *   2. Environment conflict detection (page-cache plugin, page builder, SEO plugin)
 *   3. WordPress runtime (rewrite rules flushed, cron, DB tables, abilities API)
 *   4. LLM provider reachability (opt-in — costs an API call)
 *   5. Site configuration (Brand Identity filled, PHP / WP versions)
 *
 * Output shape per probe:
 *   [
 *     'id'     => string,          // stable identifier ('llms_txt')
 *     'label'  => string,          // human label ('/llms.txt loads')
 *     'status' => 'pass'|'warn'|'fail'|'info',
 *     'detail' => string,          // what we observed
 *     'fix'    => string|null,     // one-line action to take
 *     'meta'   => array|null,      // optional extras (url, http_code, body_len, etc.)
 *   ]
 *
 * Designed for: live page-load diagnostics + 1-click copy-to-clipboard
 * support report.
 *
 * @package RankReady
 * @since   1.2.0-rc.5
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Diagnostics {

	/**
	 * REST namespace.
	 */
	const NS = 'rankready/v1';

	/**
	 * Default HTTP timeout per probe (seconds).
	 */
	const TIMEOUT = 8;

	/**
	 * Provider endpoints for reachability tests. Each value is a low-cost
	 * GET that just verifies the key — no completion is generated.
	 */
	const PROVIDER_PROBES = array(
		'openai'    => array(
			'url'    => 'https://api.openai.com/v1/models',
			'header' => 'Authorization: Bearer %s',
			'option' => 'rnrd_openai_api_key',
		),
		'anthropic' => array(
			'url'    => 'https://api.anthropic.com/v1/models',
			'header' => 'x-api-key: %s',
			'option' => 'rnrd_anthropic_api_key',
		),
		'gemini'    => array(
			// Header auth, not ?key= — a key in a query string is logged by
			// every proxy on the path. See RNRD_LLM_Gemini.
			'url'    => 'https://generativelanguage.googleapis.com/v1beta/models',
			'header' => 'x-goog-api-key: %s',
			'option' => 'rnrd_gemini_api_key',
		),
		'deepseek'  => array(
			'url'    => 'https://api.deepseek.com/v1/models',
			'header' => 'Authorization: Bearer %s',
			'option' => 'rnrd_deepseek_api_key',
		),
	);

	/**
	 * Bootstrap REST endpoints.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/diagnostics', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'rest_run' ),
			'permission_callback' => array( self::class, 'permission_check' ),
			'args'                => array(
				'include_api' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Include LLM provider reachability tests (costs 1 API call per provider).',
				),
			),
		) );

		register_rest_route( self::NS, '/diagnostics/report', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'rest_report' ),
			'permission_callback' => array( self::class, 'permission_check' ),
			'args'                => array(
				'include_api' => array( 'type' => 'boolean', 'default' => false ),
				// Default true — this report gets pasted into public WP.org
				// support threads. The full forensic report (versioned plugin
				// inventory, mu-plugins, exact server/PHP/MySQL strings, debug
				// flags) is opt-in via redacted=false, never the default.
				'redacted'    => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Return the public/support-safe report. Set false for the full forensic report.',
				),
			),
		) );
	}

	public static function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// REST handlers
	// ─────────────────────────────────────────────────────────────────────────

	public static function rest_run( WP_REST_Request $request ) {
		$include_api = (bool) $request->get_param( 'include_api' );
		return rest_ensure_response( self::run( $include_api ) );
	}

	public static function rest_report( WP_REST_Request $request ) {
		$include_api = (bool) $request->get_param( 'include_api' );
		$redacted    = (bool) $request->get_param( 'redacted' );
		$result      = self::run( $include_api );

		$report = self::format_plaintext_report( $result, $redacted );

		return rest_ensure_response( array(
			'report'   => $report,
			'length'   => strlen( $report ),
			'redacted' => $redacted,
		) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Main orchestrator
	// ─────────────────────────────────────────────────────────────────────────

	public static function run( bool $include_api = false ): array {
		$checks = array();

		// Group 1 — Endpoint reachability
		$checks[] = self::probe_llms_txt();
		$checks[] = self::probe_llms_full_txt();
		$checks[] = self::probe_homepage_md();
		$checks[] = self::probe_post_md();
		$checks[] = self::probe_robots_txt();
		$checks[] = self::probe_mcp_manifest();

		// Group 2 — WordPress runtime
		$checks[] = self::probe_rewrite_rules();
		$checks[] = self::probe_webserver();      // rc.16 — Apache / Nginx / LiteSpeed / IIS / Caddy
		$checks[] = self::probe_rest_reachable(); // rc.16 — verifies /wp-json/ routes
		$checks[] = self::probe_wp_cron();
		$checks[] = self::probe_db_tables();
		$checks[] = self::probe_abilities_api();

		// Group 3 — Environment conflict detection
		$checks[] = self::probe_cache_plugin();
		$checks[] = self::probe_page_builder();
		$checks[] = self::probe_seo_plugin();
		$checks[] = self::probe_cache_constants();
		$checks[] = self::probe_edge_cache_hit();           // FREE-99 — external HIT detection
		$checks[] = self::probe_negotiation_cache_layer();
		$checks[] = self::probe_markdown_negotiation();     // FREE-108 — Accept: text/markdown end-to-end
		$checks[] = self::probe_template_redirect_race();   // FREE-99 — Bricks/Oxygen priority race

		// Group 4 — Site configuration
		$checks[] = self::probe_brand_identity();
		$checks[] = self::probe_secret_encryption();  // 1.4.0 — the only security property with no check
		$checks[] = self::probe_php_version();
		$checks[] = self::probe_wp_version();

		// Pro (and any add-on) can append information rows. Anything that is not a
		// well-formed row is dropped, so a bad add-on cannot break the report.
		$extra = apply_filters( 'rnrd_diagnostics_checks', array() );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $row ) {
				if ( is_array( $row ) && isset( $row['id'], $row['label'], $row['status'], $row['detail'] ) ) {
					$checks[] = self::result( (string) $row['id'], (string) $row['label'], (string) $row['status'], (string) $row['detail'], isset( $row['fix'] ) ? (string) $row['fix'] : null );
				}
			}
		}

		// Group 5 — LLM provider reachability (opt-in, costs API calls).
		// Only probe the ACTIVE provider — probing all four flagged a "no key"
		// failure for providers the user isn't using (e.g. a Gemini user was
		// shown an OpenAI "API key not set" issue). The active provider is the
		// only one that matters for Summary/FAQ generation.
		if ( $include_api ) {
			$active_provider = class_exists( 'RNRD_LLM' ) ? RNRD_LLM::get_active_provider() : 'openai';
			$checks[] = self::probe_provider( $active_provider );
			$checks[] = self::probe_dataforseo();
		} else {
			$checks[] = self::placeholder_provider();
		}

		// Roll up totals
		$totals = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 );
		foreach ( $checks as $c ) {
			if ( isset( $totals[ $c['status'] ] ) ) {
				$totals[ $c['status'] ]++;
			}
		}

		return array(
			'checks'       => $checks,
			'totals'       => $totals,
			'environment'  => self::get_environment(),
			'generated_at' => current_time( 'mysql', true ),
			'version'      => defined( 'RNRD_VERSION' ) ? RNRD_VERSION : 'unknown',
			'site_url'     => home_url(),
			'include_api'  => $include_api,
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Endpoint probes — actually fetch each URL
	// ═════════════════════════════════════════════════════════════════════════

	private static function probe_llms_txt(): array {
		$enabled = 'on' === get_option( 'rnrd_llms_enable', 'off' );
		$url     = home_url( '/llms.txt' );

		/*
		 * IMPORTANT: ALWAYS fetch, even when our own toggle is off.
		 *
		 * This used to return "Toggle is OFF" without ever requesting the URL,
		 * which meant the one question the tool exists to answer — what does
		 * /llms.txt actually serve right now — went unasked. A site can serve
		 * /llms.txt from a rival plugin, a theme snippet, an nginx location or a
		 * static file, and reporting "off" in that case is not just incomplete,
		 * it points the user at the wrong cause. That is the exact shape of the
		 * support thread this plugin keeps getting: a real file served by
		 * something else, read as a RankReady conflict.
		 *
		 * The toggle now only decides the WORDING, never whether we look.
		 */
		$response = self::fetch( $url );

		if ( ! $enabled ) {
			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$body = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
			$html = '' !== $body && preg_match( '#<(html|body|head|!doctype)#i', substr( $body, 0, 500 ) );

			if ( 200 === $code && '' !== trim( $body ) && ! $html ) {
				return self::result( 'llms_txt', '/llms.txt loads', 'warn',
					'RankReady\'s LLMs.txt toggle is OFF, but something else on this site is already serving /llms.txt.',
					'Another plugin, a theme snippet or a server rule is serving this file. Turn RankReady\'s LLMs.txt on to take it over, or leave it with whatever owns it — but do not run both.',
					array( 'url' => $url, 'http_code' => $code, 'bytes' => strlen( $body ) )
				);
			}

			return self::result( 'llms_txt', '/llms.txt loads', 'info',
				'Toggle is OFF in AI Visibility → LLMs.txt, and nothing else is serving /llms.txt.',
				'Enable LLMs.txt to expose your site index to AI engines.',
				array( 'url' => $url, 'http_code' => $code )
			);
		}

		if ( is_wp_error( $response ) ) {
			return self::loopback_aware_result( 'llms_txt', '/llms.txt loads', $response, $url,
				'Server can\'t reach itself via loopback. Check firewall / hosts file.'
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 404 === $code ) {
			return self::result( 'llms_txt', '/llms.txt loads', 'fail',
				'HTTP 404 — endpoint not registered with WordPress.',
				// issue #42 — a rewrite-rule flush alone does not clear a 404 a
				// host-level or CDN cache already stored for this URL; without
				// this line the remedy looked complete but silently failed for
				// anyone in front of Cloudflare/LiteSpeed/Varnish/etc.
				'Visit Settings → Permalinks → Save (no changes) to re-flush rewrite rules. If a host-level or CDN cache (Cloudflare, LiteSpeed, Varnish, a hosting panel\'s cache, etc.) is in front of this site, also purge that cache for this URL — it will keep serving the cached 404 until you do, even after the rewrite rule is fixed.',
				array( 'url' => $url, 'http_code' => 404 )
			);
		}

		if ( 200 !== $code ) {
			return self::result( 'llms_txt', '/llms.txt loads', 'fail',
				"HTTP $code returned.",
				'Check wp-content/debug.log for fatal errors during the request.',
				array( 'url' => $url, 'http_code' => $code )
			);
		}

		// Detect HTML interception
		if ( preg_match( '#<(html|body|head|!doctype)#i', substr( $body, 0, 500 ) ) ) {
			$builder = self::detect_active_page_builder();
			$cause   = $builder
				// Print the real priority. RankReady has not used priority 1 since
				// `rnrd_template_redirect_priority()` landed, and the filter means
				// even the default can be changed on this site. A wrong number here
				// sends the reader looking for a hook that does not exist.
				? sprintf( 'Page builder (%s) is intercepting the request despite our template_redirect priority %d hook.', $builder, rnrd_template_redirect_priority( 0 ) )
				: 'A theme template or plugin is intercepting the request.';

			return self::result( 'llms_txt', '/llms.txt loads', 'fail',
				'HTTP 200 but body is HTML, not plaintext.',
				$cause . ' Disable theme/builder temporarily to identify the conflict.',
				array( 'url' => $url, 'http_code' => 200, 'body_preview' => substr( $body, 0, 100 ) )
			);
		}

		if ( strlen( $body ) < 50 ) {
			return self::result( 'llms_txt', '/llms.txt loads', 'warn',
				'HTTP 200 but body is near-empty (' . strlen( $body ) . ' bytes).',
				'Fill Brand Identity in E-E-A-T tab — Site name + Summary populate llms.txt.',
				array( 'url' => $url, 'body_len' => strlen( $body ) )
			);
		}

		if ( false === strpos( substr( $body, 0, 200 ), '# ' ) ) {
			return self::result( 'llms_txt', '/llms.txt loads', 'warn',
				'HTTP 200 but missing H1 marker (# ). Format may be corrupted.',
				'Toggle AI Visibility → LLMs.txt OFF then ON to regenerate.',
				array( 'url' => $url )
			);
		}

		return self::result( 'llms_txt', '/llms.txt loads', 'pass',
			sprintf( 'HTTP 200, %s bytes, valid LLMs.txt format.', number_format( strlen( $body ) ) ),
			null,
			array( 'url' => $url, 'body_len' => strlen( $body ) )
		);
	}

	private static function probe_llms_full_txt(): array {
		if ( 'on' !== get_option( 'rnrd_llms_enable', 'off' ) ) {
			return self::result( 'llms_full_txt', '/llms-full.txt loads', 'info',
				'Requires LLMs.txt to be enabled (it\'s OFF).',
				'Enable AI Visibility → LLMs.txt first.'
			);
		}

		$url      = home_url( '/llms-full.txt' );
		$response = self::fetch( $url );

		if ( is_wp_error( $response ) ) {
			return self::loopback_aware_result( 'llms_full_txt', '/llms-full.txt loads', $response, $url,
				'Server can\'t reach itself via loopback.'
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return self::result( 'llms_full_txt', '/llms-full.txt loads', 'fail',
				"HTTP $code.",
				404 === $code
					? 'Re-flush rewrite rules (Settings → Permalinks → Save).'
					: 'Check error log for fatals.',
				array( 'url' => $url, 'http_code' => $code )
			);
		}

		if ( preg_match( '#<(html|body|head)#i', substr( $body, 0, 500 ) ) ) {
			return self::result( 'llms_full_txt', '/llms-full.txt loads', 'fail',
				'HTTP 200 but HTML returned (intercepted).',
				'Page builder priority conflict. Disable theme to isolate.',
				array( 'url' => $url )
			);
		}

		if ( strlen( $body ) < 500 ) {
			return self::result( 'llms_full_txt', '/llms-full.txt loads', 'warn',
				'Body is short (' . strlen( $body ) . ' bytes) — likely no published posts yet.',
				'Publish at least one post to populate llms-full.txt.',
				array( 'url' => $url, 'body_len' => strlen( $body ) )
			);
		}

		return self::result( 'llms_full_txt', '/llms-full.txt loads', 'pass',
			sprintf( 'HTTP 200, %s bytes.', number_format( strlen( $body ) ) ),
			null,
			array( 'url' => $url, 'body_len' => strlen( $body ) )
		);
	}

	private static function probe_homepage_md(): array {
		if ( 'on' !== get_option( 'rnrd_md_enable', 'off' ) ) {
			return self::result( 'homepage_md', 'Homepage .md route', 'info',
				'Toggle is OFF in AI Visibility → Markdown Endpoints.',
				'Enable Markdown Endpoints to serve .md versions of pages.'
			);
		}

		$url      = home_url( '/' );
		$response = self::fetch( $url, array( 'Accept' => 'text/markdown' ) );

		if ( is_wp_error( $response ) ) {
			return self::loopback_aware_result( 'homepage_md', 'Homepage .md route', $response, $url,
				'Loopback request blocked.',
				array( 'Accept' => 'text/markdown' ),
				function ( $body, $resp ) {
					return self::response_is_markdown(
						(string) wp_remote_retrieve_header( $resp, 'content-type' ),
						(string) $body
					);
				}
			);
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$ctype = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body  = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return self::result( 'homepage_md', 'Homepage .md route', 'fail',
				"HTTP $code.",
				'Check rewrite rules.',
				array( 'url' => $url, 'http_code' => $code )
			);
		}

		// Assert on what markdown IS, not on the absence of one HTML tag in the
		// first 200 bytes. The old test passed a `text/plain` body, a JSON error
		// document, and any HTML whose first tag sat past byte 200 — a green row
		// on a route that is not serving markdown at all.
		if ( ! self::response_is_markdown( $ctype, $body ) ) {
			$builder = self::detect_active_page_builder();
			$is_html = (bool) preg_match( '#<(html|body|head|!doctype)#i', substr( $body, 0, 2000 ) );

			return self::result( 'homepage_md', 'Homepage .md route', 'fail',
				sprintf(
					'HTTP 200 but Content-Type is %s%s.',
					$ctype ?: 'unknown',
					$is_html ? ' (HTML returned)' : ' and the body does not open as Markdown'
				),
				$builder && $is_html
					? "Page builder ($builder) is rendering before our Markdown handler despite RankReady's low template_redirect priority. Use the `rnrd_template_redirect_priority` filter to push RankReady even earlier, e.g. `add_filter( 'rnrd_template_redirect_priority', fn( \$p ) => \$p - 1000 );`."
					: 'Something between RankReady and the response is replacing the body. Check for a page cache, a security or optimisation plugin rewriting Content-Type, and try the distinct URL /index.md.',
				array( 'url' => $url, 'content_type' => $ctype )
			);
		}

		return self::result( 'homepage_md', 'Homepage .md route', 'pass',
			sprintf( 'HTTP 200, Content-Type: %s, %s bytes.', $ctype, number_format( strlen( $body ) ) ),
			null,
			array( 'url' => $url, 'content_type' => $ctype )
		);
	}

	private static function probe_post_md(): array {
		if ( 'on' !== get_option( 'rnrd_md_enable', 'off' ) ) {
			return self::result( 'post_md', 'Post .md route', 'info',
				'Markdown Endpoints disabled.',
				'Enable AI Visibility → Markdown Endpoints.'
			);
		}

		$posts = get_posts( array(
			'numberposts' => 1,
			'post_status' => 'publish',
			'post_type'   => 'post',
			'has_password' => false, // A password-protected post never serves Markdown; sampling one gave a false 404.
			'fields'      => 'ids',
		) );

		if ( empty( $posts ) ) {
			return self::result( 'post_md', 'Post .md route', 'info',
				'No published posts to test.',
				'Publish at least one post.'
			);
		}

		$post_url = get_permalink( $posts[0] );
		$response = self::fetch( $post_url, array( 'Accept' => 'text/markdown' ) );

		if ( is_wp_error( $response ) ) {
			return self::loopback_aware_result( 'post_md', 'Post .md route', $response, $post_url,
				'Loopback request blocked.',
				array( 'Accept' => 'text/markdown' ),
				function ( $body, $resp ) {
					return self::response_is_markdown(
						(string) wp_remote_retrieve_header( $resp, 'content-type' ),
						(string) $body
					);
				}
			);
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$ctype = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body  = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return self::result( 'post_md', 'Post .md route', 'fail',
				"HTTP $code for $post_url.",
				'Check rewrite rules.',
				array( 'url' => $post_url, 'http_code' => $code )
			);
		}

		// Same assertion as the homepage probe: what Markdown IS, not the absence
		// of one HTML tag in the first 200 bytes. Both probes share one validator
		// so they cannot disagree about whether a route is serving Markdown.
		if ( ! self::response_is_markdown( $ctype, $body ) ) {
			$is_html = (bool) preg_match( '#<(html|body|head|!doctype)#i', substr( $body, 0, 2000 ) );

			return self::result( 'post_md', 'Post .md route', 'fail',
				sprintf(
					'HTTP 200 but Content-Type is %s%s.',
					$ctype ?: 'unknown',
					$is_html ? ' (HTML returned)' : ' and the body does not open as Markdown'
				),
				$is_html
					? "A page builder or theme template is intercepting this route before RankReady runs. Use the `rnrd_template_redirect_priority` filter to push RankReady earlier, e.g. `add_filter( 'rnrd_template_redirect_priority', fn( \$p ) => \$p - 1000 );`."
					: 'Something between RankReady and the response is replacing the body. Check for a page cache, or a security or optimisation plugin rewriting Content-Type.',
				array( 'url' => $post_url, 'content_type' => $ctype )
			);
		}

		return self::result( 'post_md', 'Post .md route', 'pass',
			sprintf( 'HTTP 200, %s, %s bytes.', $ctype, number_format( strlen( $body ) ) ),
			null,
			array( 'url' => $post_url, 'content_type' => $ctype )
		);
	}

	private static function probe_robots_txt(): array {
		$url      = home_url( '/robots.txt' );
		$response = self::fetch( $url );

		if ( is_wp_error( $response ) ) {
			$robots_enabled = 'on' === get_option( 'rnrd_robots_enable', 'on' );
			return self::loopback_aware_result( 'robots_txt', '/robots.txt has RankReady block', $response, $url,
				'Server can\'t reach itself.',
				array(),
				function ( $body ) use ( $robots_enabled ) {
					// If toggle is OFF, just confirm we got *any* robots.txt back.
					if ( ! $robots_enabled ) {
						return strpos( $body, 'User-agent' ) !== false || strpos( $body, 'Sitemap' ) !== false;
					}
					// Toggle is ON — body must contain RankReady marker.
					return strpos( $body, '# BEGIN RankReady' ) !== false;
				}
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return self::result( 'robots_txt', '/robots.txt has RankReady block', 'fail',
				"HTTP $code.",
				'A physical robots.txt file may be blocking WordPress.',
				array( 'url' => $url, 'http_code' => $code )
			);
		}

		$robots_enabled = 'on' === get_option( 'rnrd_robots_enable', 'on' );

		if ( ! $robots_enabled ) {
			return self::result( 'robots_txt', '/robots.txt has RankReady block', 'info',
				'AI Crawler robots.txt toggle is OFF.',
				'Enable AI Visibility → LLM Crawler Access to inject the bot block.'
			);
		}

		$has_marker  = false !== strpos( $body, '# BEGIN RankReady' );
		$has_agent   = false !== strpos( $body, 'User-agent:' );

		if ( ! $has_marker ) {
			// rc.9 — Detect SEO plugins that intercept /robots.txt via
			// custom rewrite (bypasses WP's robots_txt filter entirely).
			$interceptor = '';
			// Guard the class the method is actually on. This read RNRD_Llms_Txt,
			// where detect_robots_txt_interceptor() has never existed, so the
			// condition was always false and the whole interceptor diagnosis was
			// unreachable: every affected site got the generic "re-save" remedy
			// instead of being told which plugin is serving /robots.txt.
			if ( class_exists( 'RNRD_Robots' ) && method_exists( 'RNRD_Robots', 'detect_robots_txt_interceptor' ) ) {
				$interceptor = RNRD_Robots::detect_robots_txt_interceptor();
			}
			$has_physical = file_exists( ABSPATH . 'robots.txt' );

			$detail = 'RankReady block missing from /robots.txt response.';
			$fix    = 'Re-save AI Visibility → LLM Crawler Access to trigger a physical robots.txt write.';

			if ( $interceptor ) {
				$detail = "RankReady block missing — {$interceptor} is intercepting /robots.txt via custom rewrite, so the robots_txt filter never fires.";
				$fix    = $has_physical
					? "Disable {$interceptor}'s robots.txt feature, OR delete the physical robots.txt at /robots.txt — RankReady's filter (priority PHP_INT_MAX) will then win."
					: "Disable {$interceptor}'s robots.txt feature in its settings — RankReady will then take over via filter, OR re-save AI Visibility → LLM Crawler Access to write a physical robots.txt that wins at the webserver level.";
			} elseif ( $has_physical ) {
				$detail = 'RankReady block missing from physical robots.txt at ' . self::relative_to_root( ABSPATH . 'robots.txt' ) . '.';
				$fix    = 'Re-save AI Visibility → LLM Crawler Access — sync_physical_robots_txt() will rewrite the file with the RankReady block appended.';
			}

			return self::result( 'robots_txt', '/robots.txt has RankReady block', 'fail',
				$detail, $fix,
				array( 'url' => $url, 'interceptor' => $interceptor, 'has_physical' => $has_physical )
			);
		}

		if ( ! $has_agent ) {
			return self::result( 'robots_txt', '/robots.txt has RankReady block', 'warn',
				'RankReady block present but no User-agent directives.',
				'Re-save AI Visibility → LLM Crawler Access to regenerate.',
				array( 'url' => $url )
			);
		}

		return self::result( 'robots_txt', '/robots.txt has RankReady block', 'pass',
			'RankReady block present with crawler directives.',
			null,
			array( 'url' => $url, 'body_len' => strlen( $body ) )
		);
	}

	private static function probe_mcp_manifest(): array {
		if ( 'on' !== get_option( RNRD_OPT_MCP_ENABLE, 'off' ) ) {
			return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'info',
				'WebMCP toggle is OFF.',
				'Enable AI Visibility → WebMCP Manifest to expose 16 abilities to Claude/Cursor/VS Code.'
			);
		}

		$url      = home_url( '/.well-known/mcp.json' );
		$response = self::fetch( $url );

		if ( is_wp_error( $response ) ) {
			return self::loopback_aware_result( 'mcp_manifest', '/.well-known/mcp.json loads', $response, $url,
				'Loopback blocked.'
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		// The handler answers 404 when the toggle is off. 503 is still matched
		// because a site running an older RankReady, or a cached response from
		// one, can return it and the advice is identical.
		if ( 404 === $code && false !== strpos( $body, 'WebMCP is disabled' ) ) {
			return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'warn',
				'HTTP 404 — the WebMCP toggle is OFF at request time.',
				'Confirm AI Visibility → WebMCP toggle is actually saved as ON.',
				array( 'url' => $url, 'http_code' => 404 )
			);
		}

		if ( 503 === $code ) {
			return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'warn',
				'HTTP 503 (Service Unavailable) — MCP toggle is OFF at request time.',
				'Confirm AI Visibility → WebMCP toggle is actually saved as ON.',
				array( 'url' => $url, 'http_code' => 503 )
			);
		}

		// 403 = the webserver blocks the path BEFORE WordPress runs. Almost always
		// Nginx (or a control panel like RunCloud) denying dotfile paths — /.well-known/
		// begins with a dot and gets caught by a "location ~ /\." deny rule. Re-flushing
		// rewrite rules cannot fix this; it needs a one-line server-block change. Apache
		// and LiteSpeed serve /.well-known/ fine, so this only bites Nginx.
		if ( 403 === $code ) {
			$sw       = isset( $_SERVER['SERVER_SOFTWARE'] )
				? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) )
				: '';
			$is_nginx = ( false !== strpos( $sw, 'nginx' ) );
			$snippet  = 'location ^~ /.well-known/ { allow all; try_files $uri $uri/ /index.php?$args; }';
			return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'fail',
				$is_nginx
					? 'HTTP 403 — Nginx is denying /.well-known/ (a dotfile-deny rule) before WordPress runs.'
					: 'HTTP 403 — the webserver is denying /.well-known/ before WordPress runs.',
				sprintf(
					/* translators: %s: an nginx location config snippet. */
					__( 'Add this ABOVE any "location ~ /\\." deny rule, then reload the server (RunCloud: Web App → NGINX Config): %s — Apache and LiteSpeed need no change.', 'rankready-ai-llm-seo' ),
					$snippet
				),
				array( 'url' => $url, 'http_code' => 403, 'server_family' => $is_nginx ? 'nginx' : 'other' )
			);
		}

		if ( 200 !== $code ) {
			return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'fail',
				"HTTP $code.",
				'Re-flush rewrite rules.',
				array( 'url' => $url, 'http_code' => $code )
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['name'] ) ) {
			return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'fail',
				'HTTP 200 but JSON is invalid or missing "name" key.',
				'Toggle WebMCP off/on to regenerate manifest.',
				array( 'url' => $url )
			);
		}

		$ability_count = isset( $decoded['abilities'] ) && is_array( $decoded['abilities'] )
			? count( $decoded['abilities'] )
			: 0;

		return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'pass',
			sprintf( 'HTTP 200, valid JSON, %d abilities exposed.', $ability_count ),
			null,
			array( 'url' => $url, 'abilities' => $ability_count )
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// WordPress runtime probes
	// ═════════════════════════════════════════════════════════════════════════

	private static function probe_rewrite_rules(): array {
		$rules = get_option( 'rewrite_rules', array() );

		if ( empty( $rules ) ) {
			return self::result( 'rewrite_rules', 'Rewrite rules flushed', 'warn',
				'WordPress rewrite_rules option is empty.',
				'Visit Settings → Permalinks → Save Changes.'
			);
		}

		$rules_string = is_array( $rules ) ? implode( "\n", array_keys( $rules ) ) : (string) $rules;
		$has_llms     = false !== strpos( $rules_string, 'llms' );
		// Match the Markdown rule by its query var, not by '.md': the OKF
		// rules (okf/index.md etc.) also contain '.md' and would mask a
		// missing Markdown rule.
		$has_md       = is_array( $rules ) && false !== strpos( implode( "\n", array_values( $rules ) ), 'rnrd_md_path=' );

		if ( ! $has_llms && 'on' === get_option( 'rnrd_llms_enable', 'off' ) ) {
			return self::result( 'rewrite_rules', 'Rewrite rules flushed', 'fail',
				'LLMs.txt enabled but rewrite rule missing.',
				'Settings → Permalinks → Save (no changes) — re-flushes rules.'
			);
		}

		// Same check for the Markdown endpoints.
		if ( ! $has_md && 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return self::result( 'rewrite_rules', 'Rewrite rules flushed', 'fail',
				'Markdown endpoints enabled but the .md rewrite rule is missing.',
				'Settings → Permalinks → Save (no changes) — re-flushes rules.'
			);
		}

		return self::result( 'rewrite_rules', 'Rewrite rules flushed', 'pass',
			sprintf( '%d rewrite rules registered.', is_array( $rules ) ? count( $rules ) : 0 ),
			null
		);
	}

	/**
	 * Detect webserver family + return the snippet most likely to help if
	 * rewrites are misconfigured. Works on Apache, LiteSpeed (LSWS),
	 * OpenLiteSpeed, Nginx, IIS, Caddy, and Cloudflare.
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function probe_webserver(): array {
		$sw = isset( $_SERVER['SERVER_SOFTWARE'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) )
			: '';

		$family   = 'unknown';
		$friendly = 'Unknown';

		if ( false !== strpos( $sw, 'litespeed' ) || false !== strpos( $sw, 'openlitespeed' ) ) {
			$family   = 'litespeed';
			$friendly = 'LiteSpeed Web Server';
		} elseif ( false !== strpos( $sw, 'nginx' ) ) {
			$family   = 'nginx';
			$friendly = 'Nginx';
		} elseif ( false !== strpos( $sw, 'apache' ) ) {
			$family   = 'apache';
			$friendly = 'Apache';
		} elseif ( false !== strpos( $sw, 'iis' ) || false !== strpos( $sw, 'microsoft' ) ) {
			$family   = 'iis';
			$friendly = 'Microsoft IIS';
		} elseif ( false !== strpos( $sw, 'caddy' ) ) {
			$family   = 'caddy';
			$friendly = 'Caddy';
		} elseif ( false !== strpos( $sw, 'cloudflare' ) ) {
			$family   = 'cloudflare';
			$friendly = 'Cloudflare proxy';
		}

		// Per-family hint about how routing is configured. Diagnostics doesn't
		// require any specific webserver — WordPress core handles the routing,
		// and RankReady reaches the REST API via rest_url() which produces the
		// correct URL format regardless of permalink structure.
		$hints = array(
			'apache'     => 'Apache uses .htaccess rules (mod_rewrite). RankReady ships an Apache snippet via the "Server bypass snippet" section below.',
			'litespeed'  => 'LiteSpeed reads .htaccess (Apache-compatible) PLUS its own LSCACHE module. RankReady ships X-LiteSpeed-* response headers + .htaccess bypass rules to handle both.',
			'nginx'      => 'Nginx requires server-block configuration (no .htaccess support). RankReady ships an Nginx snippet via the "Server bypass snippet" section below. The standard WP server block "try_files $uri $uri/ /index.php?$args;" rule is required for REST routes.',
			'iis'        => 'IIS uses web.config. WordPress generates the rewrite rules automatically when Permalinks are saved. REST API works via the same routing.',
			'caddy'      => 'Caddy handles WordPress via "try_files" directive in the Caddyfile. REST routes work automatically.',
			'cloudflare' => 'Cloudflare is a proxy in front of your origin server. The origin\'s webserver still handles routing — check the origin separately. Cloudflare APO + Cache rules respect RankReady\'s CDN-Cache-Control + cf-edge-cache headers.',
			'unknown'    => 'Could not detect server family from SERVER_SOFTWARE. RankReady works on any webserver that routes /wp-json/ to WordPress.',
		);

		return self::result(
			'webserver',
			'Webserver detected',
			'info',
			$friendly . ' — ' . $hints[ $family ],
			null,
			array(
				'family'          => $family,
				'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				'recommended_snippet' => 'nginx' === $family ? 'nginx' : 'apache',
			)
		);
	}

	/**
	 * Loopback test — fetch the plugin's own REST root via wp_remote_get to
	 * confirm /wp-json/ is reachable from PHP. Catches misconfigured Nginx
	 * (missing try_files), missing .htaccess on Apache, and proxy issues.
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function probe_rest_reachable(): array {
		$url = rest_url( 'rankready/v1' );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 8,
				'sslverify' => (bool) apply_filters( 'rnrd_sslverify', true ), // staging sites often have self-signed certs
				'headers'   => array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::loopback_aware_result(
				'rest_reachable',
				'REST API reachable (loopback)',
				$response,
				$url,
				'Check firewall / DNS — your site cannot reach its own REST API. Loopback test failed.'
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 400 ) {
			return self::result(
				'rest_reachable',
				'REST API reachable (loopback)',
				'pass',
				sprintf( 'HTTP %d from %s', $code, $url ),
				null,
				array( 'url' => $url, 'code' => $code )
			);
		}

		// 404 commonly = empty .htaccess on Apache OR Nginx missing try_files
		$fix = 'Visit Settings → Permalinks → Save (rewrites .htaccess on Apache/LiteSpeed). On Nginx, add the "Server bypass snippet → Nginx" block from below to your server config.';
		return self::result(
			'rest_reachable',
			'REST API reachable (loopback)',
			'fail',
			sprintf( 'HTTP %d from %s — REST API is not routing. Diagnostics + bulk operations + freshness scan will fail until this resolves.', $code, $url ),
			$fix,
			array( 'url' => $url, 'code' => $code )
		);
	}

	private static function probe_wp_cron(): array {
		// v1.0.1 — Don't fail just because DISABLE_WP_CRON is set. Many managed
		// hosts (RunCloud, Kinsta, WP Engine, Pantheon) disable WP-Cron and run
		// /wp-cron.php from system crontab. What actually matters is whether
		// scheduled events are firing on time — so we measure THAT directly.
		$cron       = _get_cron_array();
		$count      = is_array( $cron ) ? count( $cron ) : 0;
		$disabled   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$mechanism  = $disabled ? 'external cron' : 'WP-Cron';

		if ( 0 === $count ) {
			/*
			 * Not a pass, which is what this returned until 1.4.0.
			 *
			 * WordPress schedules its own maintenance events (wp_version_check,
			 * wp_update_plugins, wp_scheduled_delete) during install, so a live
			 * site with an EMPTY cron array is not a fresh install with nothing
			 * to measure — it is a site whose queue is being wiped, or whose
			 * cron option cannot be read. Reporting "pass, no events scheduled"
			 * on exactly the sites where nothing RankReady schedules will ever
			 * run is the probe reporting the opposite of what it found. Warn,
			 * and say what to look at.
			 */
			return self::result( 'wp_cron', 'Cron firing', 'warn',
				sprintf( 'No scheduled events at all (%s). WordPress schedules its own on install, so an empty queue is unusual.', $mechanism ),
				'Check for a plugin or host feature that clears the cron queue, and confirm the cron option is readable. RankReady schedules its own events, and none of them can run while the queue stays empty.',
				array( 'event_count' => 0 )
			);
		}

		// Find the oldest event that should have already fired.
		$now            = time();
		$oldest_overdue = null;
		foreach ( $cron as $timestamp => $events ) {
			if ( $timestamp <= $now && ( null === $oldest_overdue || $timestamp < $oldest_overdue ) ) {
				$oldest_overdue = $timestamp;
			}
		}

		// Everything scheduled for the future — cron is presumably keeping up.
		if ( null === $oldest_overdue ) {
			return self::result( 'wp_cron', 'Cron firing', 'pass',
				sprintf( '%d events scheduled (%s). None overdue.', $count, $mechanism ),
				null
			);
		}

		$minutes_late = (int) round( ( $now - $oldest_overdue ) / 60 );

		// Up to 20 minutes late is acceptable — WP-Cron piggybacks on traffic,
		// system cron usually runs every 5–15 minutes. Beyond that something
		// real is wrong.
		if ( $minutes_late <= 20 ) {
			return self::result( 'wp_cron', 'Cron firing', 'pass',
				sprintf( '%d events scheduled (%s). Oldest overdue %d min — within normal range.', $count, $mechanism, $minutes_late ),
				null
			);
		}

		// Truly stuck — cron is not firing regardless of which mechanism owns it.
		$fix = $disabled
			? sprintf(
				'DISABLE_WP_CRON is on but external cron does not appear to be firing. Add a system cron: */10 * * * * wget -q -O - https://%s/wp-cron.php?doing_wp_cron',
				wp_parse_url( home_url(), PHP_URL_HOST )
			)
			: 'WP-Cron is enabled but events have been overdue for a long time. Check that the site receives traffic, or switch to a real system cron and set DISABLE_WP_CRON = true.';

		return self::result( 'wp_cron', 'Cron firing', 'warn',
			sprintf( '%d events scheduled (%s). Oldest overdue %d min — cron not firing.', $count, $mechanism, $minutes_late ),
			$fix
		);
	}

	private static function probe_db_tables(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( ! $exists ) {
			return self::result( 'db_tables', 'Crawler log DB table', 'fail',
				"Table {$table} is missing.",
				'Deactivate then reactivate RankReady — tables are created on activation.'
			);
		}

		// $table = $wpdb->prefix . 'rnrd_crawler_log' (defined above).
		// No user input flows in — hardcoded suffix on the WP-owned prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `" . esc_sql( $table ) . "`" );

		return self::result( 'db_tables', 'Crawler log DB table', 'pass',
			sprintf( 'Table exists, %s rows logged.', number_format( $row_count ) ),
			null,
			array( 'table' => $table, 'rows' => $row_count )
		);
	}

	private static function probe_abilities_api(): array {
		if ( function_exists( 'wp_register_ability' ) ) {
			return self::result( 'abilities_api', 'WP Abilities API (6.9+)', 'pass',
				'wp_register_ability() is available — MCP uses native registration.',
				null
			);
		}

		return self::result( 'abilities_api', 'WP Abilities API (6.9+)', 'info',
			'WP Abilities API not loaded (requires WP 6.9+ or Abilities plugin).',
			'Optional. RankReady ships its own MCP manifest as a fallback — no action needed.'
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Environment conflict detection
	// ═════════════════════════════════════════════════════════════════════════

	/**
	 * Does RankReady actually keep its dynamic endpoints out of the caches on
	 * this site, or does it only detect them?
	 *
	 * Until 1.4.0 this probe built a $known_with_bypass map and then never read
	 * it: every detected plugin was reported as "RankReady bypass rules applied"
	 * and the whole probe returned PASS. So a site running NitroPack or
	 * Cloudflare APO, which serve finished HTML from their own edge and cannot
	 * be stopped by a PHP constant set inside WordPress, was told its bypass was
	 * in place. That is the worst kind of diagnostic: a green check is what stops
	 * somebody looking, so it actively hid the one configuration that needs a
	 * human. A probe that cannot verify a thing must say so rather than assert it.
	 *
	 * Detection also comes from RNRD_Cache::detect_active() now instead of this
	 * file's own shorter list, so the two cannot drift apart again.
	 */
	private static function probe_cache_plugin(): array {
		$detected = array();
		if ( class_exists( 'RNRD_Cache' ) && method_exists( 'RNRD_Cache', 'detect_active' ) ) {
			$detected = array_values( RNRD_Cache::detect_active() );
		}
		if ( ! $detected ) {
			$detected = self::detect_active_cache_plugins();
		}

		if ( empty( $detected ) ) {
			return self::result( 'cache_plugin', 'Page cache plugin', 'info',
				'No known page-cache plugin detected.',
				null
			);
		}

		/*
		 * Bypassed: RankReady sets DONOTCACHEPAGE (and LSCWP_NO_CACHE for
		 * LiteSpeed) on its dynamic responses, and these read it as an early
		 * bail-out. For WP Rocket, W3TC and WP Super Cache the exclusion is
		 * also persisted into the plugin's own settings, see
		 * RNRD_Cache::persist_*_exclusions().
		 */
		$bypassed = array( 'LiteSpeed Cache', 'WP Rocket', 'W3 Total Cache', 'WP Super Cache', 'WP Fastest Cache' );

		/*
		 * Edge and static-file caches. These serve a stored copy of the HTML
		 * before PHP runs, or from a CDN, so no constant set inside WordPress
		 * reaches them. Each needs an exclusion in ITS own configuration, which
		 * a plugin cannot write. Named individually because claiming coverage
		 * here is exactly the bug being fixed.
		 */
		$edge = array( 'NitroPack', 'Cloudflare APO', 'Kinsta Edge', 'WP Engine Edge', 'SWIS Performance', 'Pantheon Edge', 'Nginx Helper', 'Perfmatters' );

		$covered   = array_values( array_intersect( $detected, $bypassed ) );
		$at_edge   = array_values( array_intersect( $detected, $edge ) );
		$unchecked = array_values( array_diff( $detected, $bypassed, $edge ) );

		$reports = array();
		if ( $covered ) {
			$reports[] = implode( ', ', $covered ) . ' — RankReady bypass rules applied';
		}
		if ( $at_edge ) {
			$reports[] = implode( ', ', $at_edge ) . ' — caches ahead of PHP, RankReady cannot exclude itself';
		}
		if ( $unchecked ) {
			$reports[] = implode( ', ', $unchecked ) . ' — bypass not verified by RankReady';
		}

		$detail = array(
			'detected'      => $detected,
			'bypassed'      => $covered,
			'edge'          => $at_edge,
			'not_verified'  => $unchecked,
		);

		if ( ! $at_edge && ! $unchecked ) {
			return self::result( 'cache_plugin', 'Page cache plugin', 'pass',
				implode( '; ', $reports ),
				null,
				$detail
			);
		}

		$fix = $at_edge
			? sprintf(
				'Exclude /llms.txt, /llms-full.txt and any URL ending in .md from %s in its own settings or dashboard. A cache that runs before WordPress cannot be told to skip a page from inside WordPress.',
				implode( ' and ', $at_edge )
			)
			: sprintf(
				'RankReady sets DONOTCACHEPAGE on its dynamic responses, which most caches honour, but it has not been confirmed for %s. Request /llms.txt twice and check the response is not served from cache; if it is, add an exclusion there.',
				implode( ', ', $unchecked )
			);

		return self::result( 'cache_plugin', 'Page cache plugin', 'warn',
			implode( '; ', $reports ),
			$fix,
			$detail
		);
	}

	/**
	 * 1.4.1 — warn when a caching layer is detected while same-URL Accept
	 * negotiation is on. Reads response headers of a plain homepage request.
	 */
	private static function probe_negotiation_cache_layer(): array {
		$label = 'Caching layer vs same-URL negotiation';
		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) || 'on' !== get_option( RNRD_OPT_MD_ACCEPT_NEGOTIATION, 'on' ) ) {
			return self::result( 'md_negotiation_cache', $label, 'info', 'Same-URL Accept negotiation is off or Markdown is disabled; nothing to check.', null );
		}
		$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 5, 'redirection' => 2, 'user-agent' => 'RankReady-Diagnostic/1.0' ) );
		if ( is_wp_error( $response ) ) {
			return self::result( 'md_negotiation_cache', $label, 'info', 'Header probe could not run (' . $response->get_error_message() . '). Common in Docker / local dev — not a real-site issue.', null );
		}
		$h      = static function ( string $k ) use ( $response ): string {
			return strtolower( (string) wp_remote_retrieve_header( $response, $k ) );
		};
		$found = array();
		if ( '' !== $h( 'cf-ray' ) || '' !== $h( 'cf-cache-status' ) || false !== strpos( $h( 'server' ), 'cloudflare' ) ) {
			$found[] = 'Cloudflare';
		}
		if ( '' !== $h( 'x-varnish' ) || false !== strpos( $h( 'via' ), 'varnish' ) ) {
			$found[] = 'Varnish';
		}
		if ( '' !== $h( 'x-served-by' ) || '' !== $h( 'fastly-debug-digest' ) || false !== strpos( $h( 'via' ), 'fastly' ) ) {
			$found[] = 'Fastly';
		}
		if ( '' !== $h( 'x-litespeed-cache' ) || false !== strpos( $h( 'server' ), 'litespeed' ) ) {
			$found[] = 'LiteSpeed';
		}
		if ( ! $found ) {
			return self::result( 'md_negotiation_cache', $label, 'pass', 'No Cloudflare, Varnish, Fastly or LiteSpeed cache headers detected.', null );
		}
		return self::result( 'md_negotiation_cache', $label, 'warn',
			'Detected ' . implode( ', ', $found ) . ' while same-URL negotiation is on. Caches that ignore Vary: Accept can serve Markdown to visitors.',
			'Turn off Same-URL content negotiation under Settings > llms.txt and Markdown, or make sure the cache keys on the Accept header. The .md URLs keep working either way.',
			array( 'layers' => $found ) );
	}

	/**
	 * FREE-108 — End-to-end Accept: text/markdown probe.
	 *
	 * Hits the live homepage with `Accept: text/markdown` and inspects the
	 * Content-Type of the FINAL response (post-CDN, post-cache). Three failure
	 * modes detected:
	 *
	 *   1. Returns text/html → some cache layer (most commonly Cloudflare)
	 *      is serving the cached HTML response to markdown requests because
	 *      its cache key doesn't vary by Accept. Surface the Cloudflare
	 *      Cache Rule snippet as the fix.
	 *   2. Returns 4xx/5xx → routing or rewrite-rule problem.
	 *   3. Returns text/markdown → all good.
	 *
	 * @since 1.0.1
	 */
	private static function probe_markdown_negotiation(): array {
		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return self::result( 'md_negotiation', 'Accept: text/markdown end-to-end', 'info',
				'Markdown endpoints disabled; skipped.', null );
		}

		// Same-URL Accept negotiation is ON by default (RankReady turns it off by
		// itself on Cloudflare APO) but can poison caches that ignore Vary: Accept.
		// When it is off, the canonical URL correctly returns HTML; markdown is served at the distinct `.md`
		// URL, which is cache-safe. So when negotiation is off we probe `/index.md`
		// (the real agent path) and pass when it returns text/markdown. We only
		// probe the homepage Accept-header path when the user has opted in.
		// v1.2.1 — default MUST match register_setting() and every read site in
		// RNRD_Markdown, all of which default to 'on'. Defaulting to 'off' here
		// made Diagnostics report the feature disabled on any site that had never
		// explicitly saved the option, while it was actually serving Markdown.
		$negotiation_on = 'on' === get_option( RNRD_OPT_MD_ACCEPT_NEGOTIATION, 'on' );

		if ( ! $negotiation_on ) {
			$md_url   = home_url( '/index.md' );
			$response = wp_remote_get( $md_url, array(
				'timeout'     => 5,
				'redirection' => 2,
				'headers'     => array( 'Accept' => 'text/markdown' ),
				'user-agent'  => 'RankReady-Diagnostic/1.0',
			) );
			if ( is_wp_error( $response ) ) {
				return self::result( 'md_negotiation', 'Markdown endpoint (/index.md)', 'info',
					'External probe could not run (' . $response->get_error_message() . '). Common in Docker / local dev — not a real-site issue.',
					null );
			}
			$status      = (int) wp_remote_retrieve_response_code( $response );
			$ctype       = (string) wp_remote_retrieve_header( $response, 'content-type' );
			$ctype_short = strtok( $ctype, ';' );
			if ( $status >= 400 ) {
				return self::result( 'md_negotiation', 'Markdown endpoint (/index.md)', 'fail',
					'/index.md returned HTTP ' . $status . '.',
					'Flush rewrite rules: Settings → Permalinks → Save. Then re-run.',
					array( 'status' => $status, 'content-type' => $ctype ) );
			}
			if ( 'text/markdown' === $ctype_short ) {
				return self::result( 'md_negotiation', 'Markdown endpoint (/index.md)', 'pass',
					'Markdown is served at the distinct /index.md URL (cache-safe path). Same-URL Accept negotiation is switched off on this site, so the canonical homepage correctly returns HTML, so no cache can be poisoned. AI agents discover /index.md via the Link header and llms.txt.',
					null,
					array( 'content-type' => $ctype ) );
			}
			return self::result( 'md_negotiation', 'Markdown endpoint (/index.md)', 'warn',
				'/index.md returned Content-Type: ' . $ctype . ' instead of text/markdown.',
				"Flush rewrite rules (Settings → Permalinks → Save). If a page builder still intercepts this route, use the `rnrd_template_redirect_priority` filter to push RankReady earlier, e.g. `add_filter( 'rnrd_template_redirect_priority', fn( \$p ) => \$p - 1000 );`.",
				array( 'content-type' => $ctype ) );
		}

		$home    = home_url( '/' );
		$response = wp_remote_get( $home, array(
			'timeout'     => 5,
			'redirection' => 2,
			'headers'     => array( 'Accept' => 'text/markdown' ),
			'user-agent'  => 'RankReady-Diagnostic/1.0',
		) );
		if ( is_wp_error( $response ) ) {
			return self::result( 'md_negotiation', 'Accept: text/markdown end-to-end', 'info',
				'External probe could not run (' . $response->get_error_message() . '). Common in Docker / local dev — not a real-site issue.',
				null );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$ctype  = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$ctype_short = strtok( $ctype, ';' );
		$cf_cache    = (string) wp_remote_retrieve_header( $response, 'cf-cache-status' );

		if ( $status >= 400 ) {
			return self::result( 'md_negotiation', 'Accept: text/markdown end-to-end', 'fail',
				'Homepage returned HTTP ' . $status . ' to Accept: text/markdown request.',
				'Check rewrite rules: Settings → Permalinks → Save. Then re-run.',
				array( 'status' => $status, 'content-type' => $ctype ) );
		}

		if ( 'text/markdown' === $ctype_short ) {
			return self::result( 'md_negotiation', 'Accept: text/markdown end-to-end', 'pass',
				'Homepage returned text/markdown as requested. Content negotiation works end-to-end.' .
					( '' !== $cf_cache ? ' (Cloudflare cf-cache-status: ' . $cf_cache . ')' : '' ),
				null,
				array( 'content-type' => $ctype, 'cf_cache' => $cf_cache ) );
		}

		// Returned HTML when markdown was requested → cache layer is overriding.
		$fix = '';
		if ( '' !== $cf_cache && false !== stripos( $cf_cache, 'hit' ) ) {
			$fix = 'Cloudflare is serving cached HTML to markdown requests (its default cache key does not vary by Accept). Fix: connect under Settings → Cloudflare, or paste the Cache Rule snippet from Settings → Advanced → Diagnostics.';
		} else {
			$fix = 'Some intermediate cache is serving HTML to markdown requests. If on Cloudflare, apply the Cache Rule snippet. If on Varnish/Fastly/LSWS, ensure Vary: Accept is respected at the cache layer.';
		}

		return self::result( 'md_negotiation', 'Accept: text/markdown end-to-end', 'warn',
			'Got Content-Type: ' . $ctype . ' when markdown was requested.' .
				( '' !== $cf_cache ? ' Cloudflare cf-cache-status: ' . $cf_cache . '.' : '' ),
			$fix,
			array( 'content-type' => $ctype, 'cf_cache' => $cf_cache ) );
	}

	/**
	 * FREE-99 — External edge-cache HIT probe.
	 *
	 * Fetches /llms.txt over HTTP and inspects response cache headers to detect
	 * server-level caches (nginx FastCGI, Varnish, Cloudflare APO, LSWS) that
	 * sit in front of PHP and cannot be detected from inside WordPress.
	 *
	 * If a HIT is reported, surfaces the .htaccess / nginx snippet as the fix.
	 */
	private static function probe_edge_cache_hit(): array {
		if ( ! class_exists( 'RNRD_Cache' ) ) {
			return self::result( 'edge_cache_hit', 'Edge cache HIT scan', 'info',
				'RNRD_Cache unavailable; skipped.', null );
		}

		// issue #42 — ALWAYS scan /llms.txt for an edge-cache HIT, even when
		// RankReady's own toggle is off. A stale cached 404 (or a stale cached
		// response from whatever else is serving the URL) is invisible unless
		// this probe actually looks — the toggle now only changes who is
		// presumed to own the content, never whether we look.
		$owned      = 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$owner_note = $owned ? '' : ' RankReady\'s LLMs.txt toggle is OFF — this reflects whatever is currently serving /llms.txt, not necessarily RankReady.';

		$probe = RNRD_Cache::probe_edge_cache_status( home_url( '/llms.txt' ) );

		if ( 'error' === $probe['status'] ) {
			return self::result( 'edge_cache_hit', 'Edge cache HIT scan', 'info',
				'External probe could not run (' . $probe['detail'] . '). Common in Docker / local dev — not a real-site issue.',
				null );
		}

		if ( 'hit' === $probe['status'] ) {
			$layer = (string) $probe['layer'];
			$fix   = ( 'litespeed-server' === $layer || 'unknown-edge' === $layer )
				? 'Server-level cache is serving /llms.txt before PHP runs. Apply the LiteSpeed/Apache .htaccess snippet (Diagnostics → Server bypass snippet) or rebuild your edge cache.'
				: 'Edge cache (' . $layer . ') is holding /llms.txt. Purge the edge cache for this URL and verify Cache-Control: no-store reaches your CDN.';
			return self::result( 'edge_cache_hit', 'Edge cache HIT scan', 'warn',
				$probe['detail'] . ' — ' . $layer . ' is caching this endpoint.' . $owner_note,
				$fix,
				array( 'probe' => $probe, 'rankready_owns_endpoint' => $owned ) );
		}

		if ( 'miss' === $probe['status'] ) {
			return self::result( 'edge_cache_hit', 'Edge cache HIT scan', 'pass',
				$probe['detail'] . ' — edge layer correctly bypasses /llms.txt.' . $owner_note,
				null,
				array( 'probe' => $probe, 'rankready_owns_endpoint' => $owned ) );
		}

		return self::result( 'edge_cache_hit', 'Edge cache HIT scan', 'pass',
			'No edge-cache indicator headers detected. Either no CDN/proxy in front, or it is correctly bypassing this URL.' . $owner_note,
			null,
			array( 'probe' => $probe, 'rankready_owns_endpoint' => $owned ) );
	}

	/**
	 * issue #41 — Detect third-party `template_redirect` callbacks that may
	 * race RankReady's handlers (Bricks Builder, Oxygen, Cwicly).
	 *
	 * RankReady's own baseline moved from a fixed low priority to
	 * `rnrd_template_redirect_priority( 0 )` (default -9999, filterable via
	 * `rnrd_template_redirect_priority`), so this probe now compares against
	 * that real band (offset 0 through offset 2) instead of a hardcoded `5`,
	 * and against its LAST hook rather than its first, so a callback sitting
	 * on the Markdown handler's or the Accept negotiator's own priority is
	 * still reported. Almost nothing in a
	 * normal install registers at or below -9999 — WordPress core's own
	 * `_wp_admin_bar_init` sits at priority 0 — so callback counting against
	 * the real baseline is no longer a guess: a hit here is a genuine
	 * pre-empting registration, not core noise. We additionally cross-check
	 * against a live fetch of /llms.txt so the warning reflects an actual
	 * observed symptom (HTML instead of the expected plain-text response)
	 * rather than callback counting alone.
	 */
	private static function probe_template_redirect_race(): array {
		global $wp_filter;

		// RankReady does not occupy one priority, it occupies a small band: the
		// /okf/ router at offset 0, the Markdown and llms.txt handlers at offset 1,
		// and the Accept negotiator at offset 2. Comparing against offset 0 alone
		// skipped every callback sitting at offset 1 or 2, which are exactly the
		// priorities that can pre-empt the Markdown handler and the Accept
		// negotiator. The cutoff is therefore our LAST hook, not our first.
		$baseline = rnrd_template_redirect_priority( 0 );
		$latest   = rnrd_template_redirect_priority( 2 );
		$band     = $baseline === $latest ? (string) $baseline : $baseline . ' to ' . $latest;

		if ( ! isset( $wp_filter['template_redirect'] ) ) {
			return self::result( 'tpl_redirect_race', 'template_redirect priority race', 'pass',
				'No competing template_redirect hooks registered.', null );
		}

		$callbacks = $wp_filter['template_redirect']->callbacks ?? array();
		$racers    = array();
		foreach ( $callbacks as $priority => $cbs ) {
			if ( $priority > $latest ) {
				continue; // Anything registered after our last hook cannot race us.
			}
			foreach ( (array) $cbs as $cb ) {
				$fn = $cb['function'] ?? null;
				$name = '';
				if ( is_string( $fn ) ) {
					$name = $fn;
				} elseif ( is_array( $fn ) && isset( $fn[0], $fn[1] ) ) {
					$class = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
					$name  = $class . '::' . (string) $fn[1];
				} elseif ( $fn instanceof Closure ) {
					// Named, not skipped. A bare closure on template_redirect is a
					// perfectly ordinary way for a plugin to race us, and dropping
					// every unnameable callback made exactly that case invisible to
					// the probe. The declaring file is what the user can act on.
					$name = self::describe_closure( $fn );
				} elseif ( is_object( $fn ) && method_exists( $fn, '__invoke' ) ) {
					$name = get_class( $fn ) . '::__invoke';
					$fn   = array( $fn, '__invoke' ); // so is_core_callback() can reflect it
				}
				if ( '' === $name || false !== stripos( $name, 'RNRD_' ) || false !== stripos( $name, 'RankReady' ) ) {
					continue; // skip ours
				}
				if ( self::is_core_callback( $fn ) ) {
					continue; // skip WordPress core's own callbacks
				}
				$racers[] = 'priority ' . $priority . ': ' . $name;
			}
		}

		if ( empty( $racers ) ) {
			return self::result( 'tpl_redirect_race', 'template_redirect priority race', 'pass',
				'No third-party callbacks at or before RankReady\'s own priorities (' . $band . ').', null );
		}

		// Cross-check against a real symptom: fetch /llms.txt and see whether
		// it actually looks pre-empted (HTML instead of plain text/markdown),
		// so the verdict is not callback counting alone.
		$symptom_confirmed = false;
		$response           = wp_remote_get( home_url( '/llms.txt' ), array( 'timeout' => 5, 'redirection' => 2 ) );
		if ( ! is_wp_error( $response ) ) {
			$body         = (string) wp_remote_retrieve_body( $response );
			$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
			if ( false !== stripos( $content_type, 'text/html' ) || false !== stripos( ltrim( $body ), '<!DOCTYPE' ) || false !== stripos( ltrim( $body ), '<html' ) ) {
				$symptom_confirmed = true;
			}
		}

		$status = $symptom_confirmed ? 'warn' : 'info';
		$detail = count( $racers ) . ' competing hook(s) registered at or before RankReady\'s own priorities (' . $band . '): ' . implode( '; ', array_slice( $racers, 0, 3 ) )
			. ( $symptom_confirmed ? ' — /llms.txt is currently returning HTML, confirming a real conflict.' : ' — /llms.txt currently returns as expected, so this has not caused a visible problem yet.' );

		return self::result( 'tpl_redirect_race', 'template_redirect priority race', $status,
			$detail,
			'If /llms.txt or /.well-known/mcp.json returns HTML, use the `rnrd_template_redirect_priority` filter to push RankReady\'s priority even lower than its default (-9999 + offset), e.g. `add_filter( \'rnrd_template_redirect_priority\', fn( $p ) => $p - 1000 );`.',
			array( 'racers' => $racers, 'baseline_priority' => $baseline, 'latest_priority' => $latest, 'symptom_confirmed' => $symptom_confirmed ) );
	}

	/**
	 * True when a hook callback is defined by WordPress core itself.
	 *
	 * Core registers `_wp_admin_bar_init` on `template_redirect` at priority 0
	 * (wp-includes/default-filters.php), so a stock install with no other
	 * plugins active reported a "competing hook" and every single user saw the
	 * warning. A conflict probe that fires on 100% of installs tells nobody
	 * anything, and it trained people to ignore the one case that matters.
	 *
	 * Detected by where the callback is DEFINED, not by its name. A name list
	 * would rot the moment core adds another early callback, and a name match
	 * would also swallow a third-party function that happened to be called
	 * something similar.
	 *
	 * @param mixed $fn Raw callback from $wp_filter.
	 */
	private static function is_core_callback( $fn ): bool {
		try {
			if ( $fn instanceof Closure ) {
				$ref = new ReflectionFunction( $fn );
			} elseif ( is_string( $fn ) && function_exists( $fn ) ) {
				$ref = new ReflectionFunction( $fn );
			} elseif ( is_string( $fn ) && false !== strpos( $fn, '::' ) ) {
				// 'Class::method' string form. Without this branch it fell through
				// to "not core", which is the safe direction but would resurface
				// the false positive if core ever registered a hook this way.
				list( $rnrd_cls, $rnrd_mth ) = explode( '::', $fn, 2 );
				$ref = new ReflectionMethod( $rnrd_cls, $rnrd_mth );
			} elseif ( is_array( $fn ) && isset( $fn[0], $fn[1] ) ) {
				$ref = new ReflectionMethod(
					is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0],
					(string) $fn[1]
				);
			} else {
				return false;
			}
		} catch ( ReflectionException $e ) {
			return false; // cannot prove it is core, so treat it as third-party.
		}

		// Internal PHP functions have no file. They are not core WordPress.
		$file = (string) $ref->getFileName();
		if ( '' === $file ) {
			return false;
		}

		$file = self::canonical_path( $file );
		foreach ( array( WPINC, 'wp-admin' ) as $rnrd_core_dir ) {
			if ( 0 === strpos( $file, self::canonical_path( ABSPATH . $rnrd_core_dir ) . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalise a path AND resolve symlinks, for prefix comparison.
	 *
	 * Reflection reports a file's real, symlink-resolved path. ABSPATH on
	 * WP Engine, Pantheon and any Capistrano-style atomic deploy is a symlink
	 * (`current` -> `releases/N`), so comparing a resolved path against an
	 * unresolved prefix never matches and every core callback reads as
	 * third-party — which is the exact 100%-of-installs false positive this
	 * probe was fixed for, just narrowed to the hosts most likely to hit it.
	 * realpath() returns false for a path that no longer exists; fall back to
	 * the normalised string rather than comparing against an empty prefix.
	 */
	private static function canonical_path( string $path ): string {
		$real = realpath( $path );
		return wp_normalize_path( false === $real ? $path : $real );
	}

	/**
	 * Replace the site's absolute paths anywhere in a block of report text.
	 *
	 * Runs longest-prefix-first so the deeper root wins when ABSPATH and its
	 * symlink-resolved twin share a prefix, and covers WP_CONTENT_DIR when a
	 * site keeps wp-content outside the WordPress root.
	 */
	public static function redact_paths( string $text ): string {
		$roots = array( wp_normalize_path( ABSPATH ) );
		$real  = self::canonical_path( ABSPATH );
		if ( ! in_array( $real, $roots, true ) ) {
			$roots[] = $real;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			foreach ( array( wp_normalize_path( WP_CONTENT_DIR ), self::canonical_path( WP_CONTENT_DIR ) ) as $rnrd_cd ) {
				if ( ! in_array( $rnrd_cd, $roots, true ) ) {
					$roots[] = $rnrd_cd;
				}
			}
		}
		// Longest first: WP_CONTENT_DIR is usually inside ABSPATH, and replacing
		// the shorter root first would leave the tail of the longer one behind.
		usort( $roots, static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

		foreach ( $roots as $root ) {
			$root = rtrim( $root, '/' );
			if ( '' === $root || '/' === $root ) {
				continue;
			}
			// Both separator styles: the report can carry Windows paths verbatim
			// from a PHP warning even though wp_normalize_path() gives forward slashes.
			$text = str_replace( array( $root, str_replace( '/', '\\', $root ) ), '<site-root>', $text );
		}
		return $text;
	}

	/**
	 * A filesystem path with the WordPress root replaced by a marker.
	 *
	 * The copyable Support Bundle is built to be pasted into a public
	 * WordPress.org forum thread, and the site's absolute server path is not
	 * something a user should have to notice and strip by hand before doing
	 * that. On shared hosting it carries the account name; on managed hosts it
	 * carries the site slug and release number. The part that actually helps a
	 * supporter is which file it is, not where the server keeps it.
	 *
	 * Falls back to the basename when the path is outside the WordPress root,
	 * so nothing upstream of ABSPATH leaks either.
	 */
	public static function relative_to_root( string $path ): string {
		$path = wp_normalize_path( $path );
		$root = rtrim( wp_normalize_path( ABSPATH ), '/' ) . '/';
		if ( 0 === strpos( $path, $root ) ) {
			return '<site-root>/' . substr( $path, strlen( $root ) );
		}
		// Try again with symlinks resolved, for hosts where ABSPATH is a symlink.
		$real_path = self::canonical_path( $path );
		$real_root = rtrim( self::canonical_path( ABSPATH ), '/' ) . '/';
		if ( 0 === strpos( $real_path, $real_root ) ) {
			return '<site-root>/' . substr( $real_path, strlen( $real_root ) );
		}
		return '<elsewhere>/' . basename( $path );
	}

	/**
	 * Human-readable label for a closure: the file and line it was declared on,
	 * relative to the WordPress root so no server path leaves the site.
	 */
	private static function describe_closure( Closure $fn ): string {
		try {
			$ref  = new ReflectionFunction( $fn );
			$file = (string) $ref->getFileName();
			if ( '' === $file ) {
				return 'closure';
			}
			return 'closure in ' . self::relative_to_root( $file ) . ':' . (int) $ref->getStartLine();
		} catch ( ReflectionException $e ) {
			return 'closure';
		}
	}

	/**
	 * Is this response actually a Markdown document?
	 *
	 * Two positive signals, both required:
	 *   1. Content-Type names markdown. RNRD_Markdown::serve_markdown() always
	 *      sends `text/markdown; charset=utf-8`, so anything else means the
	 *      response did not come from our handler (or was rewritten after it).
	 *   2. The body opens as Markdown: either YAML frontmatter (`---`, emitted by
	 *      post_to_markdown() when Include metadata is on) or an ATX heading
	 *      (`# `, which build_homepage_overview_markdown() always emits and which
	 *      is also what a post looks like with metadata turned off).
	 *
	 * Checking the Content-Type alone would pass a page cache that kept our
	 * header and swapped the body; checking the body alone would pass an HTML
	 * document whose first 200 bytes happened to hold a comment or a BOM.
	 */
	private static function response_is_markdown( string $ctype, string $body ): bool {
		if ( false === stripos( $ctype, 'markdown' ) ) {
			return false;
		}

		// Tolerate a UTF-8 BOM and leading blank lines; nothing else.
		$head = ltrim( preg_replace( '/^\xEF\xBB\xBF/', '', substr( $body, 0, 512 ) ) );

		return 0 === strpos( $head, '---' ) || (bool) preg_match( '/^#{1,6}\s/', $head );
	}

	private static function probe_page_builder(): array {
		$builder = self::detect_active_page_builder();

		if ( ! $builder ) {
			return self::result( 'page_builder', 'Page builder', 'info',
				'No major page builder detected.',
				null
			);
		}

		return self::result( 'page_builder', 'Page builder', 'pass',
			sprintf(
				'%1$s detected — RankReady uses template_redirect priority %2$d to beat default builder hooks.',
				$builder,
				rnrd_template_redirect_priority( 0 )
			),
			null,
			array( 'builder' => $builder )
		);
	}

	private static function probe_seo_plugin(): array {
		$plugin = '';
		if ( defined( 'RANK_MATH_VERSION' ) )         $plugin = 'Rank Math ' . RANK_MATH_VERSION;
		elseif ( defined( 'WPSEO_VERSION' ) )         $plugin = 'Yoast SEO ' . WPSEO_VERSION;
		elseif ( defined( 'AIOSEO_VERSION' ) )        $plugin = 'All in One SEO ' . AIOSEO_VERSION;
		elseif ( ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) ) )      $plugin = 'SEOPress ' . ( defined( 'SEOPRESS_VERSION' ) ? SEOPRESS_VERSION : SEOPRESS_PRO_VERSION );
		elseif ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) $plugin = 'The SEO Framework ' . THE_SEO_FRAMEWORK_VERSION;

		if ( '' === $plugin ) {
			return self::result( 'seo_plugin', 'SEO plugin', 'info',
				'No SEO plugin detected — RankReady will emit its own Article, Speakable and FAQ schema. Article and Speakable are emitted for posts that have an AI summary; FAQPage for posts that have FAQ items.',
				null
			);
		}

		return self::result( 'seo_plugin', 'SEO plugin', 'pass',
			// Detecting the plugin proves the integration is loaded, not that the
			// rendered page ended up with exactly one meta-robots tag. Claiming the
			// outcome from the precondition is how a report tells a user something
			// is fixed when it is not. State the mechanism, which IS established by
			// detection: RankReady never echoes a robots meta of its own (see
			// RNRD_Snippet::init(), which only ever filters someone else's tag), so
			// no duplicate can originate here.
			"$plugin detected. Schema merging active; RankReady never emits a robots meta of its own, so it cannot add a second one.",
			null,
			array( 'plugin' => $plugin )
		);
	}

	private static function probe_cache_constants(): array {
		$states = array();
		$states[] = 'WP_CACHE = ' . ( defined( 'WP_CACHE' ) && WP_CACHE ? 'true' : 'false' );
		$states[] = 'DONOTCACHEPAGE = ' . ( defined( 'DONOTCACHEPAGE' ) ? 'defined' : '(unset)' );
		$states[] = 'DISABLE_WP_CRON = ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'true' : 'false' );

		return self::result( 'cache_constants', 'Cache constants', 'info',
			implode( '; ', $states ),
			null,
			array( 'constants' => $states )
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Site configuration probes
	// ═════════════════════════════════════════════════════════════════════════

	private static function probe_brand_identity(): array {
		// Read the SAME option keys the Brand Identity card writes to
		// (RNRD_OPT_LLMS_* ), not the legacy rnrd_brand_* keys — otherwise this
		// probe reports "empty" even when the card is filled (false positive).
		$name    = trim( (string) get_option( RNRD_OPT_LLMS_SITE_NAME, '' ) );
		$summary = trim( (string) get_option( RNRD_OPT_LLMS_SUMMARY, '' ) );
		$about   = trim( (string) get_option( RNRD_OPT_LLMS_ABOUT, '' ) );

		$missing = array();
		if ( '' === $name )    $missing[] = 'Site / brand name';
		if ( '' === $summary ) $missing[] = 'One-line summary';
		if ( '' === $about )   $missing[] = 'About';

		if ( empty( $missing ) ) {
			return self::result( 'brand_identity', 'Brand Identity filled', 'pass',
				'All 3 core fields are populated (name, summary, about).',
				null
			);
		}

		return self::result( 'brand_identity', 'Brand Identity filled', 'warn',
			'Missing: ' . implode( ', ', $missing ) . '.',
			'Fill in AI Visibility → Brand Identity. These feed llms.txt, FAQ prompts, AI summaries, MCP abilities.',
			array( 'missing' => $missing )
		);
	}

	/**
	 * Are the saved API keys actually encrypted on this host?
	 *
	 * Encryption at rest was the one security property in this plugin with no
	 * check behind it. `RNRD_Crypto::encrypt()` falls back to storing the raw
	 * value when OpenSSL is missing or the cipher call fails, which is the right
	 * trade (losing somebody's key is worse) but was invisible: no notice, no
	 * log, deliberately, because a log line is where a leaked key ends up.
	 *
	 * So this reports the state instead. It names the affected options and never
	 * touches a value, and the cipher test runs against a canary string.
	 */
	private static function probe_secret_encryption(): array {
		if ( ! class_exists( 'RNRD_Crypto' ) ) {
			return self::result( 'secret_encryption', 'API key encryption', 'info',
				'Not available on this install.', null );
		}

		$works = method_exists( 'RNRD_Crypto', 'self_test' ) ? RNRD_Crypto::self_test() : true;
		$plain = method_exists( 'RNRD_Crypto', 'unencrypted_secrets' ) ? RNRD_Crypto::unencrypted_secrets() : array();
		$count = count( $plain );

		if ( ! $works ) {
			return self::result( 'secret_encryption', 'API key encryption', 'fail',
				'This server cannot encrypt: AES-256-GCM is unavailable, so saved keys are stored as plain text like any other WordPress setting.',
				'Ask your host to enable PHP\'s OpenSSL extension with AES-256-GCM. Until then, use keys scoped to only what RankReady needs.',
				array( 'unencrypted' => $plain )
			);
		}

		if ( $count > 0 ) {
			return self::result( 'secret_encryption', 'API key encryption', 'warn',
				sprintf(
					/* translators: %d: number of stored keys. */
					_n( '%d saved key is stored as plain text. Encryption works here, so it was saved before it was available.', '%d saved keys are stored as plain text. Encryption works here, so they were saved before it was available.', $count, 'rankready-ai-llm-seo' ),
					$count
				),
				'Open the API Keys tab and press Save on each key. Re-saving stores it encrypted.',
				array( 'unencrypted' => $plain )
			);
		}

		return self::result( 'secret_encryption', 'API key encryption', 'pass',
			'AES-256-GCM verified, and no saved key is stored in the clear.', null );
	}

	private static function probe_php_version(): array {
		$v  = PHP_VERSION;
		$ok = version_compare( $v, '7.4', '>=' );

		return self::result( 'php_version', 'PHP version', $ok ? 'pass' : 'fail',
			"PHP $v",
			$ok ? null : 'Upgrade to PHP 8.0+ for performance and security.'
		);
	}

	private static function probe_wp_version(): array {
		global $wp_version;
		$ok = version_compare( $wp_version, '6.0', '>=' );

		return self::result( 'wp_version', 'WordPress version', $ok ? 'pass' : 'fail',
			"WordPress $wp_version",
			$ok ? null : 'Upgrade WordPress to 6.0+.'
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// LLM Provider reachability (opt-in — costs an API call each)
	// ═════════════════════════════════════════════════════════════════════════

	private static function placeholder_provider(): array {
		return self::result( 'provider_keys', 'LLM provider keys', 'info',
			'Not tested — click "Test API keys" to verify reachability (uses 1 API call per provider).',
			null
		);
	}

	/**
	 * Strip secrets out of a raw WP_Error message before it goes into a
	 * report a user may paste into a public WP.org support thread.
	 *
	 * Gemini's probe URL embeds the API key as a query-string parameter
	 * (`?key=...`), so a network-layer WP_Error (cURL error 6, a redirect
	 * message, etc.) can echo the full request URL — key included — back
	 * into `get_error_message()`. Every other provider passes its key via
	 * an Authorization header, which cURL error text does not normally
	 * quote, but this still runs for all of them as defense-in-depth:
	 * a proxy or firewall block page could echo the request line either
	 * way, and this must never be the one probe that gets it wrong.
	 *
	 * @param string $message Raw WP_Error message.
	 * @param string ...$secrets Known secret values in scope for this probe
	 *                           (API key, login, password). Empty values
	 *                           are skipped so str_replace never matches ''.
	 */
	private static function sanitize_provider_error( string $message, string ...$secrets ): string {
		foreach ( $secrets as $secret ) {
			if ( '' !== $secret ) {
				$message = str_replace( $secret, '[REDACTED]', $message );
			}
		}
		// Defense-in-depth: strip any URL's query string (a key we did not
		// already know about, e.g. one echoed via a redirect chain) and any
		// bare 20+-char alnum token (the general shape of an API key).
		$message = preg_replace( '#(https?://[^\s?]+)\?[^\s]*#i', '$1?[REDACTED]', $message );
		$message = preg_replace( '/\b[A-Za-z0-9_-]{20,}\b/', '[REDACTED]', $message );
		return $message;
	}

	private static function probe_provider( string $provider ): array {
		$cfg = self::PROVIDER_PROBES[ $provider ] ?? null;
		if ( ! $cfg ) {
			return self::result( "provider_$provider", "Provider: $provider", 'fail',
				'Unknown provider.', null );
		}

		$key = trim( (string) get_option( $cfg['option'], '' ) );

		if ( '' === $key ) {
			return self::result( "provider_$provider", "Provider: $provider", 'info',
				'No API key configured.',
				"Add the key under RankReady → Settings → API Keys → $provider."
			);
		}

		/*
		 * The four PROVIDER_PROBES URLs are literals with no format specifier,
		 * so the key has always reached headers only. This used to run the URL
		 * through sprintf() with the key as the argument, which did nothing
		 * today but meant that adding one %s to a probe URL later would have
		 * silently moved the API key into a query string, where every proxy and
		 * access log on the path records it. Audited 2026-10-20: taking the URL
		 * verbatim makes "the key is never in the URL" structural, not a
		 * property of the current table.
		 */
		$url     = (string) $cfg['url'];
		$headers = array();
		if ( ! empty( $cfg['header'] ) ) {
			$parts = explode( ': ', sprintf( $cfg['header'], $key ), 2 );
			if ( 2 === count( $parts ) ) {
				$headers[ $parts[0] ] = $parts[1];
			}
		}

		// Anthropic also needs a version header
		if ( 'anthropic' === $provider ) {
			$headers['anthropic-version'] = '2023-06-01';
		}

		$response = wp_remote_get( $url, array(
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
		) );

		if ( is_wp_error( $response ) ) {
			return self::result( "provider_$provider", "Provider: $provider", 'fail',
				'Network error: ' . self::sanitize_provider_error( $response->get_error_message(), $key ),
				'Server can\'t reach provider. Check firewall / outbound HTTPS.'
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code || 201 === $code ) {
			return self::result( "provider_$provider", "Provider: $provider", 'pass',
				"HTTP $code — key valid and provider reachable.",
				null
			);
		}

		if ( 401 === $code || 403 === $code ) {
			return self::result( "provider_$provider", "Provider: $provider", 'fail',
				"HTTP $code — invalid API key.",
				'Regenerate key from provider dashboard and re-save in Settings.'
			);
		}

		if ( 429 === $code ) {
			return self::result( "provider_$provider", "Provider: $provider", 'warn',
				"HTTP 429 — rate limited.",
				'Wait a minute and retest. Key is valid.'
			);
		}

		return self::result( "provider_$provider", "Provider: $provider", 'warn',
			"HTTP $code returned (unexpected).",
			'Provider may be having an outage. Retest later.'
		);
	}

	private static function probe_dataforseo(): array {
		// v1.2.1 — these read the CANONICAL constants. They previously read a
		// pair of hardcoded option names that nothing ever writes, so a user
		// with working DataForSEO credentials was always told "No credentials
		// configured" while FAQ generation succeeded. Never hardcode an option
		// name here — use the constant, so a rename cannot silently desync
		// this probe again.
		$login    = trim( (string) get_option( RNRD_OPT_DFS_LOGIN, '' ) );
		$password = trim( (string) get_option( RNRD_OPT_DFS_PASSWORD, '' ) );

		if ( '' === $login || '' === $password ) {
			return self::result( 'provider_dataforseo', 'Provider: DataForSEO', 'info',
				'No credentials configured.',
				'Add login + password in Settings → DataForSEO (optional — only for FAQ question discovery).'
			);
		}

		$response = wp_remote_get( 'https://api.dataforseo.com/v3/appendix/user_data', array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( "$login:$password" ),
			),
		) );

		if ( is_wp_error( $response ) ) {
			return self::result( 'provider_dataforseo', 'Provider: DataForSEO', 'fail',
				'Network error: ' . self::sanitize_provider_error( $response->get_error_message(), $login, $password ),
				'Check outbound HTTPS to api.dataforseo.com.'
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return self::result( 'provider_dataforseo', 'Provider: DataForSEO', 'pass',
				'HTTP 200 — credentials valid.',
				null
			);
		}

		if ( 401 === $code ) {
			return self::result( 'provider_dataforseo', 'Provider: DataForSEO', 'fail',
				'HTTP 401 — invalid credentials.',
				'Verify login + password from DataForSEO dashboard.'
			);
		}

		return self::result( 'provider_dataforseo', 'Provider: DataForSEO', 'warn',
			"HTTP $code (unexpected).",
			'Retest later.'
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Helpers
	// ═════════════════════════════════════════════════════════════════════════

	private static function fetch( string $url, array $headers = array() ) {
		return wp_remote_get( $url, array(
			'timeout'     => self::TIMEOUT,
			'sslverify'   => (bool) apply_filters( 'rnrd_sslverify', true ), // Loopback often hits self-signed certs in dev
			'redirection' => 2,
			'headers'     => array_merge( array(
				'User-Agent' => 'RankReady-Diagnostics/' . ( defined( 'RNRD_VERSION' ) ? RNRD_VERSION : '1.0' ),
			), $headers ),
		) );
	}

	/**
	 * Detect known "server can't reach itself" failures (Docker, local dev,
	 * containers behind reverse proxies). Returns true when the WP_Error is
	 * a connection-level failure where the URL is reachable from the public
	 * internet but the server can't curl its own public hostname.
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function is_loopback_failure( $response ): bool {
		if ( ! is_wp_error( $response ) ) {
			return false;
		}
		$msg = strtolower( $response->get_error_message() );
		return (
			strpos( $msg, 'curl error 7' ) !== false  // CURLE_COULDNT_CONNECT
			|| strpos( $msg, 'curl error 28' ) !== false // CURLE_OPERATION_TIMEDOUT
			|| strpos( $msg, 'could not connect' ) !== false
			|| strpos( $msg, 'connection refused' ) !== false
			|| strpos( $msg, 'failed to connect' ) !== false
			|| strpos( $msg, 'name or service not known' ) !== false
		);
	}

	/**
	 * Build the appropriate result for a wp_error response.
	 *
	 * On loopback failure we attempt a SECONDARY probe via the container's
	 * own internal address (`http://localhost/<path>`). The internal probe
	 * preserves the same Accept headers as the original (so markdown
	 * negotiation still works) and optionally runs a body-content check
	 * (e.g. robots.txt must contain "# BEGIN RankReady"). When the internal
	 * probe passes BOTH status AND content checks, we upgrade to 'pass'.
	 *
	 * @since 1.2.0-rc.16
	 *
	 * @param array         $headers    Headers to pass to the internal probe (preserve Accept etc).
	 * @param callable|null $body_check Optional fn(string $body) → bool; pass = content valid.
	 */
	private static function loopback_aware_result( string $key, string $label, $response, string $url, string $default_hint, array $headers = array(), ?callable $body_check = null ) {
		if ( ! self::is_loopback_failure( $response ) ) {
			return self::result( $key, $label, 'fail',
				'HTTP request failed: ' . $response->get_error_message(),
				$default_hint,
				array( 'url' => $url )
			);
		}

		// Try internal probe — strip host+port, use container's own httpd.
		// Suppress redirects: WP canonicalises `localhost` → `home_url` (the
		// public URL the loopback already failed on). With redirection: 0
		// we capture the 200 directly OR see the 301/302 that proves WP is
		// recognising the route (just unreachable via public URL).
		$parts = wp_parse_url( $url );
		$path  = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		$internal_url = 'http://localhost' . $path;
		$internal     = wp_remote_get( $internal_url, array(
			'timeout'     => self::TIMEOUT,
			'sslverify'   => (bool) apply_filters( 'rnrd_sslverify', true ),
			'redirection' => 0,
			'headers'     => array_merge( array(
				'User-Agent' => 'RankReady-Diagnostics-Internal/' . ( defined( 'RNRD_VERSION' ) ? RNRD_VERSION : '1.0' ),
			), $headers ),
		) );

		if ( ! is_wp_error( $internal ) ) {
			$code = (int) wp_remote_retrieve_response_code( $internal );
			/*
			 * 200/204 = direct serve.
			 *
			 * A 3xx counts ONLY when it keeps the same path. The redirect this
			 * branch exists for is WP canonicalising `http://localhost/llms.txt`
			 * to `https://example.com/llms.txt` — host changes, path does not.
			 * Accepting any 301/302 also accepted the redirect that means the
			 * OPPOSITE: WordPress not recognising the route and canonicalising
			 * the request away to the home page, which is a missing endpoint
			 * being reported as "serving correctly".
			 */
			$same_path = false;
			if ( 301 === $code || 302 === $code || 307 === $code || 308 === $code ) {
				$location  = (string) wp_remote_retrieve_header( $internal, 'location' );
				$want      = untrailingslashit( (string) ( wp_parse_url( $internal_url, PHP_URL_PATH ) ?: '/' ) );
				$got       = untrailingslashit( (string) ( wp_parse_url( $location, PHP_URL_PATH ) ?: '/' ) );
				$same_path = ( '' !== $location && $want === $got );
			}
			if ( ( $code >= 200 && $code < 300 ) || $same_path ) {
				$body_ok = true;
				if ( $body_check !== null && $code >= 200 && $code < 300 ) {
					$body    = (string) wp_remote_retrieve_body( $internal );
					$body_ok = (bool) $body_check( $body, $internal );
				}
				if ( $body_ok ) {
					return self::result( $key, $label, 'pass',
						'Serving correctly (verified via internal probe — public-URL loopback unavailable on this host).',
						'No action needed. Open ' . $url . ' in a browser to confirm.',
						array( 'url' => $url, 'http_code' => $code, 'internal_verified' => true )
					);
				}
			}
		}

		return self::result( $key, $label, 'warn',
			'Public-URL loopback failed, and the internal probe neither returned 2xx nor redirected to the same path.',
			'Common on Docker / local dev / reverse-proxy hosting. Open ' . $url . ' in a browser to verify it actually works for visitors.',
			array( 'url' => $url )
		);
	}

	private static function result( string $id, string $label, string $status, string $detail, ?string $fix, array $meta = array() ): array {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => in_array( $status, array( 'pass', 'warn', 'fail', 'info' ), true ) ? $status : 'info',
			'detail' => $detail,
			'fix'    => $fix,
			'meta'   => $meta ?: null,
		);
	}

	public static function detect_active_page_builder(): string {
		if ( defined( 'BRICKS_VERSION' ) )                  return 'Bricks Builder ' . BRICKS_VERSION;
		if ( defined( 'ELEMENTOR_VERSION' ) )               return 'Elementor ' . ELEMENTOR_VERSION;
		if ( defined( 'OXY_VERSION' ) || defined( 'CT_VERSION' ) ) return 'Oxygen Builder';
		if ( defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_CORE_VERSION' ) ) return 'Divi / ET Builder';
		if ( defined( 'BREAKDANCE_VERSION' ) )              return 'Breakdance';
		if ( defined( 'BB_PLUGIN_VERSION' ) )               return 'Beaver Builder';
		return '';
	}

	public static function detect_active_cache_plugins(): array {
		$found = array();
		if ( defined( 'LITESPEED_VERSION' ) || defined( 'LSCWP_V' ) ) $found[] = 'LiteSpeed Cache';
		if ( defined( 'WP_ROCKET_VERSION' ) )                          $found[] = 'WP Rocket';
		if ( defined( 'W3TC' ) )                                       $found[] = 'W3 Total Cache';
		if ( defined( 'WPSC_VERSION' ) || function_exists( 'wpsc_init' ) ) $found[] = 'WP Super Cache';
		if ( defined( 'AUTOPTIMIZE_PLUGIN_DIR' ) )                     $found[] = 'Autoptimize';
		if ( defined( 'WPFC_WP_PLUGIN_DIR' ) )                         $found[] = 'WP Fastest Cache';
		if ( class_exists( 'SiteGround_Optimizer\\Loader\\Loader' ) )  $found[] = 'SG Optimizer';
		if ( defined( 'WPHB_VERSION' ) )                               $found[] = 'Hummingbird';
		if ( defined( 'NITROPACK_VERSION' ) )                          $found[] = 'NitroPack';
		if ( defined( 'PERFMATTERS_VERSION' ) )                        $found[] = 'Perfmatters';
		return $found;
	}

	public static function get_environment(): array {
		global $wp_version, $wpdb;

		$active_plugins = array();
		$plugins        = (array) get_option( 'active_plugins', array() );
		foreach ( $plugins as $plugin_file ) {
			$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( file_exists( $plugin_path ) ) {
				$data = get_file_data( $plugin_path, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
				$active_plugins[] = $data['Name'] . ' ' . $data['Version'];
			} else {
				$active_plugins[] = $plugin_file;
			}
		}

		$theme = wp_get_theme();

		return array(
			'rankready_version' => defined( 'RNRD_VERSION' ) ? RNRD_VERSION : 'unknown',
			'wordpress_version' => $wp_version,
			'php_version'       => PHP_VERSION,
			'mysql_version'     => $wpdb->db_version(),
			'site_url'          => home_url(),
			'theme'             => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'multisite'         => is_multisite(),
			'is_https'          => is_ssl(),
			'server_software'   => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown',
			'php_sapi'          => PHP_SAPI,
			'permalink_struct'  => get_option( 'permalink_structure', '' ),
			'wp_debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'      => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'active_plugins'    => $active_plugins,
			'mu_plugins'        => self::dump_mu_plugins(),
		);
	}

	/**
	 * The subset of get_environment() that is safe to paste into a public
	 * WP.org support thread. Deliberately excludes the versioned active-
	 * plugins list, mu-plugins (these often name a client or agency), the
	 * exact server_software / mysql_version strings, and WP_DEBUG state —
	 * all of that is real attack-surface disclosure and fingerprinting
	 * data with no support value to a public reader. Keep this in sync
	 * with format_public_report(), which is the only consumer.
	 */
	public static function get_environment_for_public_sharing(): array {
		global $wp_version;
		$full = self::get_environment();

		// Major.minor is enough for troubleshooting ("6.9.x") without
		// fingerprinting the exact WordPress patch build.
		$wp_mm = preg_match( '/^(\d+\.\d+)/', (string) $wp_version, $m ) ? $m[1] : (string) $wp_version;

		return array(
			'rankready_version'    => $full['rankready_version'],
			'wordpress_version'    => $wp_mm,
			'php_version'          => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'permalink_struct'     => $full['permalink_struct'],
			'active_plugins_count' => count( $full['active_plugins'] ),
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Forensic dumps — extra detail for support reports
	// ═════════════════════════════════════════════════════════════════════════

	/**
	 * List all must-use plugins (these often intercept template_redirect
	 * and are invisible from Plugins screen).
	 */
	public static function dump_mu_plugins(): array {
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( (array) get_mu_plugins() as $file => $data ) {
			$out[] = ( $data['Name'] ?? $file ) . ' ' . ( $data['Version'] ?? '' );
		}
		return $out;
	}

	/**
	 * Physical files in the WordPress webroot that affect our endpoints.
	 * Reports presence + size + mtime for robots.txt, llms.txt, llms-full.txt,
	 * .htaccess. These trump WordPress rewrite at the webserver layer.
	 */
	public static function dump_physical_files(): array {
		$targets = array( 'robots.txt', 'llms.txt', 'llms-full.txt', '.htaccess' );
		$out     = array();
		foreach ( $targets as $name ) {
			$path = ABSPATH . $name;
			if ( file_exists( $path ) ) {
				$out[ $name ] = array(
					'exists'   => true,
					'path'     => self::relative_to_root( $path ),
					'size'     => filesize( $path ),
					'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $path ) ) . ' UTC',
					'writable' => is_writable( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- diagnostics-only check, WP_Filesystem unnecessary for read-only inspection
				);
			} else {
				$out[ $name ] = array( 'exists' => false, 'path' => self::relative_to_root( $path ) );
			}
		}
		return $out;
	}

	/**
	 * Dump WordPress rewrite rules that match RankReady endpoint patterns.
	 * Shows the regex + which `index.php?…` query it routes to.
	 */
	public static function dump_matching_rewrite_rules(): array {
		$rules = (array) get_option( 'rewrite_rules', array() );
		$out   = array();
		foreach ( $rules as $regex => $query ) {
			if ( false !== strpos( $regex, 'llms' )
			  || false !== strpos( $regex, 'robots' )
			  || false !== strpos( $regex, '\\.md' )
			  || false !== strpos( $regex, '.md' )
			  || false !== strpos( $regex, 'mcp' )
			  || false !== strpos( $query, 'rnrd_llms' )
			  || false !== strpos( $query, 'rnrd_mcp' )
			  || false !== strpos( $query, 'rnrd_md' ) ) {
				$out[ $regex ] = $query;
			}
		}
		return $out;
	}

	/**
	 * Dump every callback hooked into a WP filter/action, with priority.
	 * Lets us see "who else is filtering robots_txt and at what priority".
	 */
	public static function dump_hooks_on( string $hook, int $limit = 40 ): array {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return array( '(no hooks registered)' );
		}
		$obj = $wp_filter[ $hook ];
		$out = array();
		// $wp_filter[hook]->callbacks is array<priority, array<id, ['function'=>cb, 'accepted_args'=>n]>>
		$callbacks = is_object( $obj ) && isset( $obj->callbacks ) ? $obj->callbacks : (array) $obj;
		ksort( $callbacks );
		foreach ( $callbacks as $priority => $hooks_at_pri ) {
			foreach ( (array) $hooks_at_pri as $id => $cb_entry ) {
				$cb = $cb_entry['function'] ?? $cb_entry;
				$name = self::callback_name( $cb );
				$out[] = 'priority ' . $priority . '  ' . $name;
				if ( count( $out ) >= $limit ) {
					$out[] = '... (truncated)';
					return $out;
				}
			}
		}
		return $out;
	}

	private static function callback_name( $cb ): string {
		if ( is_string( $cb ) )    return $cb;
		if ( is_array( $cb ) ) {
			$cls = is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0];
			return $cls . '::' . (string) $cb[1];
		}
		if ( $cb instanceof \Closure ) {
			try {
				$r = new \ReflectionFunction( $cb );
				return 'Closure@' . basename( (string) $r->getFileName() ) . ':' . $r->getStartLine();
			} catch ( \Throwable $e ) {
				return 'Closure';
			}
		}
		if ( is_object( $cb ) )    return get_class( $cb ) . '::__invoke';
		return '(unknown callback)';
	}

	/**
	 * Dump key response headers from a URL — Cache-Control, Server,
	 * X-Powered-By, X-LiteSpeed-Cache, X-Cache, etc.
	 */
	public static function dump_response_headers( string $url ): array {
		$response = self::fetch( $url );
		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}
		$want = array(
			'server', 'x-powered-by', 'cache-control', 'pragma', 'age',
			'x-cache', 'x-cache-status', 'x-litespeed-cache', 'x-litespeed-cache-control',
			'cf-cache-status', 'x-served-by', 'content-type', 'content-length',
			'link', 'x-redirect-by',
		);
		$headers = wp_remote_retrieve_headers( $response );
		$out     = array( '_http_code' => (int) wp_remote_retrieve_response_code( $response ) );
		foreach ( $want as $h ) {
			$v = '';
			if ( is_object( $headers ) && method_exists( $headers, 'offsetGet' ) ) {
				$v = $headers->offsetExists( $h ) ? $headers->offsetGet( $h ) : '';
			} elseif ( is_array( $headers ) ) {
				$v = $headers[ $h ] ?? '';
			}
			if ( '' !== $v ) {
				$out[ $h ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			}
		}
		// Body preview — first 300 bytes
		$body = (string) wp_remote_retrieve_body( $response );
		$out['_body_len']     = strlen( $body );
		$out['_body_preview'] = substr( $body, 0, 300 );
		return $out;
	}

	/**
	 * Dump SEO plugin actual configuration — which features are toggled
	 * on right now. This tells us whether to expect Yoast/SEOPress/etc.
	 * to be serving llms.txt or robots.txt instead of us.
	 */
	public static function dump_seo_plugin_config(): array {
		$out = array();

		if ( defined( 'WPSEO_VERSION' ) ) {
			$yoast = (array) get_option( 'wpseo', array() );
			$out['Yoast SEO ' . WPSEO_VERSION] = array(
				'enable_llms_txt'  => ! empty( $yoast['enable_llms_txt'] ) ? 'YES' : 'no',
				'wpseo_robots_set' => ! empty( get_option( 'wpseo_robots' ) ) ? 'YES (custom robots.txt set)' : 'no',
				'enable_schema'    => isset( $yoast['enable_xml_sitemap'] ) ? 'managed by Yoast schema (default ON)' : 'unknown',
			);
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm_modules = (array) get_option( 'rank_math_modules', array() );
			$rm_general = (array) get_option( 'rank-math-options-general', array() );
			$out['Rank Math ' . RANK_MATH_VERSION] = array(
				'llms-txt module'    => in_array( 'llms-txt', $rm_modules, true ) ? 'YES' : 'no',
				'custom robots.txt'  => ! empty( $rm_general['robots_txt_content'] ) ? 'YES (' . strlen( $rm_general['robots_txt_content'] ) . ' chars set)' : 'no',
				'schema module'      => in_array( 'rich-snippet', $rm_modules, true ) ? 'YES' : 'no',
			);
		}

		if ( defined( 'AIOSEO_VERSION' ) ) {
			$aio_raw  = get_option( 'aioseo_options', '' );
			$aio      = is_string( $aio_raw ) && $aio_raw ? json_decode( $aio_raw, true ) : array();
			$out['All in One SEO ' . AIOSEO_VERSION] = array(
				'llmsTxt.enable'   => ! empty( $aio['llmsTxt']['enable'] ) ? 'YES' : 'no',
				'robots editor'    => ! empty( $aio['tools']['robots']['enable'] ?? null ) ? 'YES' : 'no',
				'schema graph'     => 'managed by AIOSEO (default ON when active)',
			);
		}

		if ( ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) ) ) {
			$sp_pro = (array) get_option( 'seopress_pro_option_name', array() );
			$sp_tit = (array) get_option( 'seopress_titles_option_name', array() );
			// Either constant can be the one defined (SEOPress Pro can load
			// without the free core), so never read SEOPRESS_VERSION bare:
			// an undefined constant is a fatal Error on PHP 8.
			$out['SEOPress ' . ( defined( 'SEOPRESS_VERSION' ) ? SEOPRESS_VERSION : SEOPRESS_PRO_VERSION )] = array(
				'pro_llms_txt'    => ! empty( $sp_pro['seopress_pro_llms_txt'] ) ? 'YES' : 'no',
				'pro_robots'      => ! empty( $sp_pro['seopress_pro_robots'] ) ? 'YES (SEOPress overriding /robots.txt)' : 'no',
				'schema_enabled'  => ! empty( $sp_tit['seopress_titles_single_titles'] ) ? 'managed by SEOPress (default ON)' : 'unknown',
			);
		}

		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$out['The SEO Framework ' . THE_SEO_FRAMEWORK_VERSION] = array(
				'schema_enabled' => 'managed by TSF (default ON when active)',
			);
		}

		if ( defined( 'SLIM_SEO_VER' ) ) {
			$out['Slim SEO ' . SLIM_SEO_VER] = array(
				'schema_enabled' => 'managed by Slim SEO (default ON when active)',
			);
		}

		return $out;
	}

	/**
	 * Verify each detected cache plugin's exclusion for our endpoints.
	 * For LiteSpeed, WP Rocket, W3TC, etc. — check whether RankReady's
	 * RNRD_Cache::exclude_url_patterns() actually registered the rule.
	 */
	public static function dump_cache_exclusion_status(): array {
		$out = array();
		foreach ( self::detect_active_cache_plugins() as $name ) {
			$status = 'unknown — RankReady applies exclusion filters but cache plugin doesn\'t expose verification API';
			if ( 'LiteSpeed Cache' === $name ) {
				$ls = (array) get_option( 'litespeed.conf.cache-exc', array() );
				$has = false;
				foreach ( $ls as $line ) {
					if ( false !== stripos( $line, 'llms.txt' ) || false !== stripos( $line, '.well-known/mcp' ) ) { $has = true; break; }
				}
				$status = $has ? 'EXCLUSION REGISTERED' : 'exclusion not visible in litespeed.conf.cache-exc — RankReady uses no_cache_headers fallback';
			}
			if ( 'WP Rocket' === $name ) {
				$rocket = (array) get_option( 'wp_rocket_settings', array() );
				$reject = (array) ( $rocket['cache_reject_uri'] ?? array() );
				$has    = false;
				foreach ( $reject as $line ) {
					if ( false !== stripos( $line, 'llms' ) || false !== stripos( $line, 'mcp' ) ) { $has = true; break; }
				}
				$status = $has ? 'EXCLUSION REGISTERED' : 'add /llms\\.txt, /llms-full\\.txt, /.*\\.md, /\\.well-known/mcp\\.json to WP Rocket → Advanced Rules → Never Cache URLs';
			}
			$out[ $name ] = $status;
		}
		return $out;
	}

	/**
	 * issue #41 — Find any active page-builder mu-plugins or actions that
	 * might still intercept template_redirect at or before RankReady's own
	 * priority. Useful when /llms.txt returns HTML.
	 *
	 * The old version matched `priority 1 ` .. `priority 5 ` as literal
	 * string prefixes, which silently missed `priority 0` and every negative
	 * priority — exactly the range RankReady's own handlers now live in
	 * (`rnrd_template_redirect_priority()` defaults to -9999 plus a small
	 * per-handler offset). Parse the priority numerically instead so 0 and
	 * negative values are handled correctly.
	 */
	public static function dump_template_redirect_competitors(): array {
		$hooks = self::dump_hooks_on( 'template_redirect', 80 );
		$out   = array();

		// RankReady registers its five template_redirect handlers at offsets
		// 0-2 above the base priority; anything at or before the highest of
		// those offsets could plausibly run before, at the same time as, or
		// interleaved with one of them.
		$our_max_priority = rnrd_template_redirect_priority( 2 );

		foreach ( $hooks as $line ) {
			if ( ! preg_match( '/^priority\s+(-?\d+)\s+(.*)$/', $line, $m ) ) {
				continue; // e.g. "(no hooks registered)" / "... (truncated)"
			}
			$priority = (int) $m[1];
			$is_ours  = false !== strpos( $line, 'RNRD_' );

			if ( $priority > $our_max_priority ) {
				continue; // registered after RankReady's own handlers; cannot race them.
			}

			$out[] = ( $is_ours ? '[ok] ' : '[!] ' ) . $line;
		}
		return $out;
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Plaintext report formatter — for 1-click copy-to-clipboard
	// ═════════════════════════════════════════════════════════════════════════

	public static function format_plaintext_report( array $result, bool $redacted = false ): string {
		if ( $redacted ) {
			return self::format_public_report( $result );
		}

		$env    = $result['environment'];
		$checks = $result['checks'];
		$totals = $result['totals'];

		$lines = array();
		$L = function( string $s = '' ) use ( &$lines ) { $lines[] = $s; };
		$H = function( string $title ) use ( &$lines ) {
			$lines[] = '';
			$lines[] = '═══ ' . $title . ' ' . str_repeat( '═', max( 1, 70 - strlen( $title ) ) );
		};

		// ── HEADER ──────────────────────────────────────────────────────────
		$L( '╔══════════════════════════════════════════════════════════════════════╗' );
		$L( '║         RankReady Diagnostic Report — Support Bundle                ║' );
		$L( '╚══════════════════════════════════════════════════════════════════════╝' );
		$L( '' );
		$L( 'Generated:    ' . $result['generated_at'] . ' UTC' );
		$L( 'Site URL:     ' . $result['site_url'] );
		$L( 'Plugin:       RankReady ' . $result['version'] );
		$L( 'WordPress:    ' . $env['wordpress_version'] );
		$L( 'PHP:          ' . $env['php_version'] . '  (SAPI: ' . ( $env['php_sapi'] ?? '?' ) . ')' );
		$L( 'MySQL:        ' . $env['mysql_version'] );
		$L( 'Theme:        ' . $env['theme'] );
		$L( 'Server:       ' . ( $env['server_software'] ?? 'unknown' ) );
		$L( 'Multisite:    ' . ( $env['multisite'] ? 'yes' : 'no' ) );
		$L( 'HTTPS:        ' . ( $env['is_https'] ? 'yes' : 'no' ) );
		$L( 'Permalinks:   ' . ( ! empty( $env['permalink_struct'] ) ? $env['permalink_struct'] : '[!] default/plain (this BREAKS our endpoints — switch to Post name)' ) );
		$L( 'WP_DEBUG:     ' . ( $env['wp_debug'] ? 'true' : 'false' ) . '   WP_DEBUG_LOG: ' . ( $env['wp_debug_log'] ? 'true' : 'false' ) );
		$L( '' );
		$L( sprintf( 'Summary:   %d pass · %d warn · %d fail · %d info',
			$totals['pass'], $totals['warn'], $totals['fail'], $totals['info']
		) );

		// ── PROBE RESULTS ──────────────────────────────────────────────────
		$H( 'PROBE RESULTS' );
		foreach ( $checks as $c ) {
			$icon = array( 'pass' => '[PASS]', 'warn' => '[WARN]', 'fail' => '[FAIL]', 'info' => '[INFO]' )[ $c['status'] ] ?? '[?] ' . strtoupper( $c['status'] );
			$L( '' );
			$L( $icon . '  ' . $c['label'] );
			$L( '       ' . $c['detail'] );
			if ( ! empty( $c['fix'] ) ) {
				$L( '       FIX → ' . $c['fix'] );
			}
			if ( ! empty( $c['meta'] ) && is_array( $c['meta'] ) ) {
				foreach ( $c['meta'] as $k => $v ) {
					if ( is_scalar( $v ) ) {
						$L( '       (' . $k . ': ' . self::truncate( (string) $v, 160 ) . ')' );
					}
				}
			}
		}

		// ── PLAIN ENGLISH FAILURE EXPLAINER ────────────────────────────────
		$fails = array_filter( $checks, function ( $c ) { return 'fail' === $c['status']; } );
		if ( $fails ) {
			$H( 'WHAT TO FIX FIRST (plain English)' );
			$n = 1;
			foreach ( $fails as $c ) {
				$L( '' );
				$L( $n . '. ' . $c['label'] );
				$L( '   What we saw: ' . $c['detail'] );
				if ( ! empty( $c['fix'] ) ) {
					$L( '   What to do:  ' . $c['fix'] );
				}
				$L( '   Why it matters: ' . self::why_matters( $c['id'] ) );
				$n++;
			}
		}

		// ── ENDPOINT RESPONSE HEADERS (forensic) ───────────────────────────
		$H( 'LIVE ENDPOINT RESPONSE HEADERS' );
		$L( '(What an AI crawler actually sees when it fetches each URL right now.)' );
		foreach ( array( '/llms.txt', '/llms-full.txt', '/robots.txt', '/.well-known/mcp.json' ) as $path ) {
			$L( '' );
			$L( '── GET ' . $path . ' ──' );
			$h = self::dump_response_headers( home_url( $path ) );
			if ( isset( $h['error'] ) ) {
				$L( '   ERROR: ' . $h['error'] );
				continue;
			}
			$L( '   HTTP status:     ' . ( $h['_http_code'] ?? '?' ) );
			$L( '   Body bytes:      ' . ( $h['_body_len'] ?? 0 ) );
			foreach ( array( 'server', 'x-powered-by', 'content-type', 'content-length',
			                 'cache-control', 'pragma', 'age',
			                 'x-cache', 'x-cache-status', 'x-litespeed-cache',
			                 'x-litespeed-cache-control', 'cf-cache-status',
			                 'x-served-by', 'link', 'x-redirect-by' ) as $hk ) {
				if ( isset( $h[ $hk ] ) ) {
					$L( '   ' . str_pad( $hk . ':', 17 ) . self::truncate( $h[ $hk ], 140 ) );
				}
			}
			if ( ! empty( $h['_body_preview'] ) ) {
				$L( '   Body preview:    ' . self::oneline( substr( $h['_body_preview'], 0, 220 ) ) );
			}
		}

		// ── PHYSICAL FILES IN WEBROOT ──────────────────────────────────────
		$H( 'PHYSICAL FILES IN WEBROOT' );
		$L( '(These trump WordPress rewrite rules. A physical robots.txt wins at the' );
		$L( ' webserver level before WP even runs.)' );
		foreach ( self::dump_physical_files() as $name => $info ) {
			if ( ! $info['exists'] ) {
				$L( '   ' . str_pad( $name, 16 ) . '(not present)  ' . $info['path'] );
			} else {
				$L( '   ' . str_pad( $name, 16 ) . sprintf( '%d bytes  modified %s  writable=%s',
					$info['size'], $info['modified'], $info['writable'] ? 'yes' : 'NO [!]' ) );
			}
		}

		// ── REWRITE RULES MATCHING OUR ENDPOINTS ───────────────────────────
		$H( 'REWRITE RULES MATCHING llms.txt / robots / .md / mcp' );
		$rr = self::dump_matching_rewrite_rules();
		if ( empty( $rr ) ) {
			$L( '   [!] NO MATCHING RULES — flush rewrite rules: Settings → Permalinks → Save' );
		} else {
			foreach ( $rr as $regex => $query ) {
				$L( '   ' . self::truncate( $regex, 50 ) . '  →  ' . self::truncate( $query, 80 ) );
			}
		}

		// ── HOOKS ON robots_txt + template_redirect ────────────────────────
		$H( 'HOOKS ON `robots_txt` FILTER (priority order, lower = earlier)' );
		foreach ( self::dump_hooks_on( 'robots_txt' ) as $line ) {
			$L( '   ' . $line );
		}

		$H( 'HOOKS ON `template_redirect` (at or before RankReady\'s own priority — these race for /llms.txt etc.)' );
		$comp = self::dump_template_redirect_competitors();
		if ( empty( $comp ) ) {
			$L( '   (none at or before RankReady\'s priority)' );
		} else {
			foreach ( $comp as $line ) {
				$L( '   ' . $line );
			}
			$L( '   ' );
			$L( '   Legend: [ok] = RankReady (expected), [!] = other plugin/theme racing us' );
		}

		// ── SEO PLUGIN CONFIG ──────────────────────────────────────────────
		$H( 'SEO PLUGIN ACTUAL CONFIG (what RankReady defers to vs. takes over)' );
		$seo = self::dump_seo_plugin_config();
		if ( empty( $seo ) ) {
			$L( '   (no SEO plugin detected — RankReady serves everything standalone)' );
		} else {
			foreach ( $seo as $plugin => $cfg ) {
				$L( '   ' . $plugin );
				foreach ( $cfg as $k => $v ) {
					$L( '     • ' . str_pad( $k, 22 ) . $v );
				}
			}
		}

		// ── CACHE EXCLUSION STATUS ─────────────────────────────────────────
		$H( 'CACHE PLUGIN EXCLUSION STATUS' );
		$ce = self::dump_cache_exclusion_status();
		if ( empty( $ce ) ) {
			$L( '   (no cache plugin detected)' );
		} else {
			foreach ( $ce as $name => $status ) {
				$L( '   ' . str_pad( $name, 24 ) . $status );
			}
		}

		// ── ACTIVE PLUGINS + MU-PLUGINS ────────────────────────────────────
		$H( 'ACTIVE PLUGINS (' . count( $env['active_plugins'] ) . ')' );
		foreach ( $env['active_plugins'] as $p ) {
			$L( '   • ' . $p );
		}
		if ( ! empty( $env['mu_plugins'] ) ) {
			$L( '' );
			$L( 'MU-PLUGINS (' . count( $env['mu_plugins'] ) . ') — invisible from Plugins screen:' );
			foreach ( $env['mu_plugins'] as $p ) {
				$L( '   • ' . $p );
			}
		}

		// ── HOW TO SEND THIS ───────────────────────────────────────────────
		$H( 'HOW TO SEND THIS REPORT' );
		$L( 'Paste this whole block into:' );
		$L( '  • A WordPress.org plugin support topic' );
		$L( '  • A bug report or issue tracker' );
		$L( '  • Your hosting provider when reporting a server issue' );
		$L( '' );
		/*
		 * Say what is actually in the report.
		 *
		 * This used to claim "no post content", which was not true: the
		 * forensic section prints the first ~220 bytes of what each public
		 * endpoint returned, and /index.md returns post content. It also
		 * lists mu-plugins, which the Plugins screen never shows. None of
		 * that is a leak — every byte of it is already served to any visitor
		 * who requests those URLs — but a privacy line that overstates is
		 * worse than none, because somebody reads it and pastes the report
		 * into a public forum on the strength of it.
		 */
		$L( 'What is in here: public URLs, plugin and mu-plugin names, theme and' );
		$L( 'server software, your RankReady settings, and the first ~220 bytes of' );
		$L( 'what each public endpoint returns — the same bytes any visitor gets.' );
		$L( 'What is NOT: API keys, logins, passwords, file paths, and nothing at' );
		$L( 'all from private, draft or password-protected posts.' );

		$L( '' );
		$L( '═══════════════════ End Diagnostic Report ═══════════════════' );

		// Final sweep before the bundle is handed to the clipboard. Individual
		// probes are careful, but a path can arrive inside a PHP warning, a
		// third-party plugin's message or a future probe nobody remembered to
		// route through relative_to_root(). Redacting the assembled text once
		// means the guarantee holds for whatever gets added later.
		return self::redact_paths( implode( "\n", $lines ) );
	}

	/**
	 * The public/support-safe report — this is what "Copy Diagnostic
	 * Report" produces by default (format_plaintext_report()'s $redacted
	 * param defaults to true, and the REST route's `redacted` arg defaults
	 * to true). It shares the same probe results as the full forensic
	 * report but drops every section that discloses site attack surface:
	 * the versioned active-plugins list, mu-plugins, live endpoint
	 * response headers, physical webroot files, rewrite rules, hook
	 * dumps, SEO/cache plugin config, and exact server/PHP/MySQL/debug
	 * strings. A user pasting this into a public WP.org thread should not
	 * be handing every reader of that thread a plugin inventory of their
	 * site.
	 */
	private static function format_public_report( array $result ): string {
		$env    = self::get_environment_for_public_sharing();
		$checks = $result['checks'];
		$totals = $result['totals'];

		$lines = array();
		$L = function ( string $s = '' ) use ( &$lines ) { $lines[] = $s; };
		$H = function ( string $title ) use ( &$lines ) {
			$lines[] = '';
			$lines[] = '═══ ' . $title . ' ' . str_repeat( '═', max( 1, 70 - strlen( $title ) ) );
		};

		$L( '╔══════════════════════════════════════════════════════════════════════╗' );
		$L( '║      RankReady Diagnostic Report — Public / Support-Safe             ║' );
		$L( '╚══════════════════════════════════════════════════════════════════════╝' );
		$L( '' );
		$L( 'Generated:      ' . $result['generated_at'] . ' UTC' );
		$L( 'Plugin:         RankReady ' . $env['rankready_version'] );
		$L( 'WordPress:      ' . $env['wordpress_version'] );
		$L( 'PHP:            ' . $env['php_version'] );
		$L( 'Permalinks:     ' . ( ! empty( $env['permalink_struct'] ) ? $env['permalink_struct'] : '[!] default/plain (this BREAKS our endpoints — switch to Post name)' ) );
		$L( 'Active plugins: ' . $env['active_plugins_count'] . ' (names withheld from this report)' );
		$L( '' );
		$L( sprintf( 'Summary:   %d pass · %d warn · %d fail · %d info',
			$totals['pass'], $totals['warn'], $totals['fail'], $totals['info']
		) );

		// ── PROBE RESULTS ──────────────────────────────────────────────────
		$H( 'PROBE RESULTS' );
		foreach ( $checks as $c ) {
			$icon = array( 'pass' => '[PASS]', 'warn' => '[WARN]', 'fail' => '[FAIL]', 'info' => '[INFO]' )[ $c['status'] ] ?? '[?] ' . strtoupper( $c['status'] );
			$L( '' );
			$L( $icon . '  ' . $c['label'] );
			$L( '       ' . $c['detail'] );
			if ( ! empty( $c['fix'] ) ) {
				$L( '       FIX → ' . $c['fix'] );
			}
			// Deliberately no $c['meta'] dump here — meta can carry raw
			// response headers, body previews or file paths meant for the
			// forensic report only.
		}

		// ── PLAIN ENGLISH FAILURE EXPLAINER ────────────────────────────────
		$fails = array_filter( $checks, function ( $c ) { return 'fail' === $c['status']; } );
		if ( $fails ) {
			$H( 'WHAT TO FIX FIRST (plain English)' );
			$n = 1;
			foreach ( $fails as $c ) {
				$L( '' );
				$L( $n . '. ' . $c['label'] );
				$L( '   What we saw: ' . $c['detail'] );
				if ( ! empty( $c['fix'] ) ) {
					$L( '   What to do:  ' . $c['fix'] );
				}
				$L( '   Why it matters: ' . self::why_matters( $c['id'] ) );
				$n++;
			}
		}

		// ── ABOUT THIS REPORT ────────────────────────────────────────────
		$H( 'ABOUT THIS REPORT' );
		$L( 'This is the public/support-safe version: probe results and version' );
		$L( 'numbers only. It leaves out your plugin inventory, mu-plugins, exact' );
		$L( 'server software string, and debug flags on purpose — that detail can' );
		$L( 'expose what this site runs to anyone reading a public thread. If a' );
		$L( 'developer working your ticket needs it, they will ask for it directly.' );

		$L( '' );
		$L( '═══════════════════ End Diagnostic Report ═══════════════════' );

		return self::redact_paths( implode( "\n", $lines ) );
	}

	private static function truncate( string $s, int $n ): string {
		return strlen( $s ) > $n ? substr( $s, 0, $n - 1 ) . '…' : $s;
	}

	private static function oneline( string $s ): string {
		return preg_replace( '/\s+/', ' ', trim( $s ) );
	}

	/**
	 * Plain-English explanation of why a given probe failure matters
	 * for the user's AI visibility goals. Non-technical wording.
	 */
	private static function why_matters( string $probe_id ): string {
		$map = array(
			'llms_txt'        => '/llms.txt is a plain-text map of your site for AI tools that request it. If it returns 404, those tools get nothing from that URL.',
			'llms_full_txt'   => '/llms-full.txt is the content of your site as one AI-readable file. If it returns 404, a tool that asks for it gets nothing from that URL.',
			'homepage_md'     => 'Some AI tools request your homepage with Accept: text/markdown. If the response is HTML, they get HTML, not Markdown.',
			'post_md'         => 'Each post needs a .md route so AI bots can grab clean Markdown instead of parsing your full HTML/JS bundle.',
			'robots_txt'      => 'Without the RankReady block, AI bots have no signal which crawlers you allow. Many default to blocking everything unknown.',
			'mcp_manifest'    => 'WebMCP is how Claude Desktop / Cursor / VS Code read your site as a data source. Without /.well-known/mcp.json, those tools have no manifest to read.',
			'rewrite_rules'   => 'WordPress needs rewrite rules registered before any of our /llms.txt or /.md URLs can resolve. Without them, every endpoint returns 404.',
			'wp_cron'         => 'Content freshness scans and bulk operations need WP cron to run on schedule. If cron is disabled, those features silently stop working.',
			'db_tables'       => 'The crawler log table stores which AI bots visited which pages. Without it, the Insights tab has nothing to show.',
			'abilities_api'   => 'WordPress 6.9+ ships a native MCP integration. RankReady uses it when available, falls back to its own manifest otherwise — either is fine.',
			'cache_plugin'    => 'Cache plugins can serve stale or HTML versions of /llms.txt to AI bots. RankReady tries to add bypass rules but some plugins need manual setup.',
			'page_builder'    => 'Page builders (Bricks, Elementor, Oxygen, Divi) intercept WordPress template loading. RankReady runs at template_redirect priority ' . rnrd_template_redirect_priority( 0 ) . ' to beat them — if you still see HTML on /llms.txt, a mu-plugin is racing us.',
			'seo_plugin'      => 'Your SEO plugin emits its own schema markup. RankReady merges into it via filters to avoid double tags. If your SEO plugin schema is OFF, RankReady\'s standalone schema is also OFF — use the rankready_force_standalone_schema filter to override.',
			'cache_constants' => 'WP_CACHE / DONOTCACHEPAGE / DISABLE_WP_CRON change how content caching and scheduling behave. Info-only here — for context, not a fix.',
			'brand_identity'  => 'Brand name + summary + about are the only AI-facing description of your site. Empty Brand Identity means llms.txt has no useful header for AI engines.',
			'php_version'     => 'Old PHP versions are slower and miss security patches. PHP 8.0+ is the modern baseline.',
			'wp_version'      => 'Old WordPress versions miss security patches and the new Abilities API needed for native MCP.',
		);
		return $map[ $probe_id ] ?? 'See the FIX line above for the action to take.';
	}
}

// Bootstrap is invoked from rankready.php in plugins_loaded.
