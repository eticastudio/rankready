<?php
/**
 * Markdown endpoint — serves every post as clean .md at its URL.
 *
 * Per llmstxt.org spec: "pages on websites that have information that might be
 * useful for LLMs to read provide a clean markdown version of those pages at
 * the same URL as the original page, but with .md appended."
 *
 * URL format: /post-slug.md (appends .md to the post URL path)
 * Examples:
 *   /hello-world/   -> /hello-world.md
 *   /docs/refunds/  -> /docs/refunds.md
 *   /2024/01/post/  -> /2024/01/post.md
 *
 * Also supports:
 * - Accept: text/markdown content negotiation (like Next.js)
 * - <link rel="alternate" type="text/markdown"> discovery (per Joost de Valk)
 * - Link HTTP header for crawler discovery
 *
 * YAML frontmatter includes title, date, author, excerpt, tags, categories.
 * Page builder rendering and stripping is handled by RNRD_Integrations.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Markdown {

	/**
	 * Guard: tracks which post IDs have already been processed in this
	 * request to avoid double-regeneration across overlapping hooks
	 * (wp_after_insert_post, save_post, transition_post_status).
	 *
	 * @var array<int, true>
	 */
	private static $processed_post_ids = array();

	public static function init(): void {
		// Admin-only hooks — gated so the markdown class (which MUST load on the
		// frontend to serve .md) doesn't register no-op admin handlers on public
		// requests. The APO notice + its dismiss handler only matter in wp-admin.
		if ( is_admin() ) {
			add_action( 'admin_init',    array( self::class, 'handle_apo_dismiss' ) );
			add_action( 'admin_notices', array( self::class, 'maybe_cloudflare_apo_notice' ) );
		}
		add_action( 'init',              array( self::class, 'add_rewrite_rules' ) );
		// v1.2.0-rc.2 — priority 1 beats page builders (Bricks ~ default 10).
		add_action( 'template_redirect', array( self::class, 'handle_request' ), 1 );

		// Content negotiation: serve markdown when Accept: text/markdown is sent.
		// Priority 2 — still before page builders but AFTER the explicit
		// .md URL handler at priority 1.
		add_action( 'template_redirect', array( self::class, 'handle_accept_header' ), 2 );

		// Emit Vary: Accept on all HTML pages so caches store markdown and HTML separately.
		// v1.2.1 — PHP_INT_MAX so we merge Vary LAST. add_vary_header() collapses
		// every Vary line already sent into one field line; running at the default
		// priority let a later plugin append a second line and re-split it.
		add_action( 'send_headers', array( self::class, 'add_vary_header' ), PHP_INT_MAX );

		// Add Link header on HTML pages pointing to .md version.
		add_action( 'wp_head',           array( self::class, 'add_md_link_tag' ) );
		add_action( 'send_headers',      array( self::class, 'add_md_link_header' ) );
		add_action( 'send_headers',      array( self::class, 'add_homepage_link_headers' ) );

		// v1.2.0 — Hidden AI hint div, visually invisible to humans, readable
		// by AI scrapers parsing raw HTML. Helps agents (incl. those that don't
		// honour Link headers) discover the .md endpoint.
		add_action( 'wp_body_open',      array( self::class, 'add_ai_hint_div' ) );

		// Prevent WordPress from adding trailing slash to .md URLs.
		add_filter( 'redirect_canonical', array( self::class, 'prevent_md_trailing_slash' ), 10, 2 );

		// Rewrite flush: pre_update_option_* busts rnrd_rewrite_ok; admin_init self-heal flushes.

		// Register query vars via named method (not anonymous closure).
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );

		// v1.0.1 — Per-post .md URL purge on save/update/trash so any cache
		// (Cloudflare APO, LiteSpeed, Nginx FCGI, Rocket, NitroPack, Hummingbird)
		// always serves fresh markdown for the post that just changed. The
		// global bust_cache_and_purge_cdn() handles llms.txt + robots + mcp.json +
		// /index.md on setting changes; this handles per-post .md on content
		// changes. Together they cover every RankReady-managed URL.
		add_action( 'save_post',              array( self::class, 'purge_post_md_url' ), 20, 1 );
		add_action( 'transition_post_status', array( self::class, 'purge_post_md_on_status' ), 20, 3 );
		add_action( 'before_delete_post',     array( self::class, 'purge_post_md_url' ), 20, 1 );

		// v1.3.2 — Regenerate and cache post markdown on save/update.
		// Priority 9999 ensures we run AFTER everything else: page builder meta
		// saves, SEO plugin hooks, ACF field saves, Yoast/Rank Math, etc. The
		// content we read must reflect the final saved state.
		//
		// Multiple hooks for reliability:
		// - wp_after_insert_post (WP 5.6+): fires after ALL meta, terms, and
		//   taxonomies are saved — the most reliable single hook.
		// - save_post: fallback for edge cases (e.g. programmatic wp_update_post
		//   calls that bypass the REST API flow).
		// - transition_post_status: catches status changes from quick edit, bulk
		//   actions, and REST API that may not trigger save_post.
		// A static guard inside on_save_post() prevents double-processing within
		// the same request.
		add_action( 'wp_after_insert_post',   array( self::class, 'on_after_insert_post' ), 9999, 3 );
		add_action( 'save_post',              array( self::class, 'on_save_post' ), 9999, 2 );
		add_action( 'transition_post_status', array( self::class, 'on_transition_post_status' ), 9999, 3 );

		// v1.0.1 — The Cloudflare APO ↔ content-negotiation notice
		// (maybe_cloudflare_apo_notice) is registered above, inside the is_admin()
		// guard. APO's cache key ignores the Accept request header, so once a URL's
		// HTML is cached at the edge a markdown request for the same URL receives
		// the HTML instead; distinct-URL endpoints (/post.md, /index.md) are
		// unaffected.
	}

	/**
	 * Show a one-time, dismissible admin notice if Cloudflare APO is detected
	 * AND markdown endpoints are enabled. Provides the exact Cache Rule snippet
	 * the user needs to paste into Cloudflare → Caching → Cache Rules.
	 *
	 * The notice is only shown on RankReady's own admin pages so it doesn't
	 * pollute every wp-admin screen.
	 */
	/**
	 * Is Cloudflare APO active? APO caches HTML at the edge and ignores both
	 * Vary: Accept and origin Cache-Control, so same-URL Accept negotiation is
	 * unsafe there. Cheap to call per-request — RNRD_Cache::detect_active() is
	 * all defined()/class_exists() checks, no HTTP or DB.
	 */
	private static function is_apo_active(): bool {
		if ( ! class_exists( 'RNRD_Cache' ) || ! method_exists( 'RNRD_Cache', 'detect_active' ) ) {
			return false;
		}
		$active = RNRD_Cache::detect_active();
		return isset( $active['cloudflare-apo'] );
	}

	public static function maybe_cloudflare_apo_notice(): void {
		// Only on RankReady screens — never spam other plugin pages.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'rankready' ) ) {
			return;
		}

		// Feature must be on.
		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}

		// v1.2 — Same-URL Accept negotiation is ON by default. On APO we auto-
		// fall back to the distinct `.md` URLs (safe), and this notice tells the
		// user how to add a Cache Rule if they want canonical-URL negotiation.
		if ( 'on' !== get_option( RNRD_OPT_MD_ACCEPT_NEGOTIATION, 'on' ) ) {
			return;
		}

		// User dismissed it forever?
		if ( get_user_meta( get_current_user_id(), '_rnrd_apo_notice_dismissed', true ) ) {
			return;
		}

		// APO detection — defer to the cache class, which knows.
		if ( ! class_exists( 'RNRD_Cache' ) || ! method_exists( 'RNRD_Cache', 'detect_active' ) ) {
			return;
		}
		$active = RNRD_Cache::detect_active();
		if ( ! isset( $active['cloudflare-apo'] ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'rnrd_dismiss_apo_notice', '1', admin_url( 'admin.php?page=' . self::menu_slug_safe() ) ),
			'rnrd_dismiss_apo_notice',
			'_rnrd_nonce'
		);
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Cloudflare APO detected — purge cache once to activate markdown content negotiation.', 'rankready-ai-llm-seo' ); ?></strong>
			</p>
			<p>
				<strong><?php esc_html_e( 'Step 1 — required, one-time:', 'rankready-ai-llm-seo' ); ?></strong>
				<?php
				printf(
					/* translators: %s: link to Cloudflare Purge Cache */
					wp_kses_post( __( 'In Cloudflare, open %s. This clears any stale HTML APO cached before RankReady was installed. Going forward, RankReady auto-purges every relevant URL when you change settings or save a post.', 'rankready-ai-llm-seo' ) ),
					// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Instructional link to the user's own Cloudflare dashboard, not an offloaded asset.
					'<a href="https://dash.cloudflare.com/?to=/:account/:zone/caching/configuration" target="_blank" rel="noopener"><strong>Caching → Configuration → Purge Everything</strong></a>'
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'After the purge, RankReady\'s distinct Markdown URLs (/post-slug.md, /index.md) and Accept-header content negotiation both work through APO. Validators like AcceptMarkdown.com score 4/4.', 'rankready-ai-llm-seo' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Optional Step 2 — for sites that publish very frequently:', 'rankready-ai-llm-seo' ); ?></strong>
				<?php esc_html_e( 'If you push content edits every minute and APO\'s 5-minute TTL feels slow, either option below makes negotiation instant. Most sites do not need this.', 'rankready-ai-llm-seo' ); ?>
			</p>
			<ol style="margin-left:18px;">
				<li>
					<strong><?php echo wp_kses_post( __( 'Best — turn on Cloudflare\'s built-in Markdown for Agents', 'rankready-ai-llm-seo' ) ); ?></strong><br />
					<?php
					printf(
						/* translators: %s: link to Cloudflare AI Crawl Control */
						wp_kses_post( __( 'In your Cloudflare dashboard (Pro/Business plan), open %s. Cloudflare\'s edge converts HTML to Markdown automatically on Accept: text/markdown requests — happens before APO caching kicks in.', 'rankready-ai-llm-seo' ) ),
						// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Instructional link to the user's own Cloudflare dashboard, not an offloaded asset.
						'<a href="https://dash.cloudflare.com/?to=/:account/:zone/ai-crawl-control" target="_blank" rel="noopener"><strong>AI Crawl Control → Markdown for Agents</strong></a>'
					);
					?>
				</li>
				<li style="margin-top:8px;">
					<strong><?php echo wp_kses_post( __( 'Alternative — add a Cache Rule (any plan, including free)', 'rankready-ai-llm-seo' ) ); ?></strong><br />
					<?php
					printf(
						/* translators: %s: link to Cloudflare Cache Rules */
						wp_kses_post( __( 'In %s create a rule. Match: <code>(http.request.headers["accept"][0] contains "text/markdown")</code>. Then: <strong>Cache eligibility → Bypass cache</strong>.', 'rankready-ai-llm-seo' ) ),
						// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Instructional link to the user's own Cloudflare dashboard, not an offloaded asset.
						'<a href="https://dash.cloudflare.com/?to=/:account/:zone/caching/cache-rules" target="_blank" rel="noopener"><strong>Caching → Cache Rules → Create rule</strong></a>'
					);
					?>
				</li>
			</ol>
			<p style="margin-top:10px;font-size:12.5px;color:#646970;">
				<?php esc_html_e( 'Both options are Cloudflare-side toggles, not PHP changes. RankReady cannot influence APO\'s cache key from origin — that\'s an architectural limit of APO. Research notes: Joost de Valk\'s Markdown Alternate plugin reaches the same conclusion and uses distinct URLs as the primary mechanism; illodev\'s markdown-negotiation-for-agents plugin recommends Cache Rules; the squin.org production guide explicitly says it does not address APO.', 'rankready-ai-llm-seo' ); ?>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:8px;"><?php esc_html_e( 'Don\'t show again', 'rankready-ai-llm-seo' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the "Don't show again" click on the APO notice.
	 * Hooked on admin_init so wp_safe_redirect() fires before any output.
	 */
	public static function handle_apo_dismiss(): void {
		if ( empty( $_GET['rnrd_dismiss_apo_notice'] ) || empty( $_GET['_rnrd_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce read passed to wp_verify_nonce() which validates; also unslashed.
		if ( ! wp_verify_nonce( wp_unslash( $_GET['_rnrd_nonce'] ), 'rnrd_dismiss_apo_notice' ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), '_rnrd_apo_notice_dismissed', 1 );
		wp_safe_redirect( remove_query_arg( array( 'rnrd_dismiss_apo_notice', '_rnrd_nonce' ) ) );
		exit;
	}

	/**
	 * Resolve RNRD_Admin::MENU_SLUG without forcing a load order dependency.
	 */
	private static function menu_slug_safe(): string {
		if ( defined( '\\RNRD_Admin::MENU_SLUG' ) ) {
			return constant( '\\RNRD_Admin::MENU_SLUG' );
		}
		return 'rankready-ai-llm-seo';
	}


	/**
	 * Purge a post's .md URL across every cache layer when its content changes.
	 * No-op when markdown endpoints are disabled, when the post type isn't
	 * enabled, or when RNRD_Cache is unavailable.
	 *
	 * Also delete the per-post transient that memoises the rendered markdown
	 * so the next request rebuilds from current post_content.
	 *
	 * @since 1.0.1
	 * @param int $post_id Post ID being saved/trashed.
	 */
	public static function purge_post_md_url( $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}

		// v1.1.5 (#6) — listing transients (homepage /index.md and Posts-page .md)
		// depend only on permalink_structure, so they were never invalidated and
		// "Recent Posts" could lag up to an hour behind a publish/edit/delete.
		delete_transient( 'rnrd_md_homepage_' . md5( (string) get_option( 'permalink_structure', '' ) ) );
		delete_transient( self::posts_page_listing_transient_key() );

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$enabled_types = (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		// Legacy cleanup (v1.1.5 → v1.3.1): purge old rendered-markdown
		// transients left over from before the post-meta cache (v1.3.2).
		// Safe to remove in a future version once all sites have upgraded.
		global $wpdb;
		$rnrd_md_key_like = $wpdb->esc_like( '_transient_rnrd_md_' . (int) $post->ID . '_' ) . '%';
		$rnrd_md_to_like  = $wpdb->esc_like( '_transient_timeout_rnrd_md_' . (int) $post->ID . '_' ) . '%';
		$rnrd_md_opts     = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $rnrd_md_key_like, $rnrd_md_to_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transient prefix lookup
		foreach ( (array) $rnrd_md_opts as $rnrd_md_opt ) {
			delete_option( $rnrd_md_opt ); // object-cache-aware: clears both the DB row and the in-memory options cache so a same-request re-read misses too.
		}

		if ( ! class_exists( 'RNRD_Cache' ) ) {
			return;
		}

		$md_url = self::get_md_url( $post );
		if ( empty( $md_url ) ) {
			return;
		}

		// Also purge the canonical post URL — Accept-header negotiation means
		// HTML and markdown share the URL, so a stale HTML cache there would
		// cross-serve to a later markdown request on the same URL.
		$canonical = get_permalink( $post );

		$urls = array( $md_url );
		if ( ! empty( $canonical ) ) {
			$urls[] = $canonical;
		}

		// Recent-posts listings on the dedicated Posts page also changed.
		$posts_page_id = self::posts_page_id();
		if ( $posts_page_id > 0 && (int) $post->ID !== $posts_page_id ) {
			$posts_page = get_post( $posts_page_id );
			if ( $posts_page instanceof WP_Post ) {
				$urls[] = self::get_md_url( $posts_page );
				$permalink = get_permalink( $posts_page );
				if ( ! empty( $permalink ) ) {
					$urls[] = $permalink;
				}
			}
		}

		// Allow the Pro addon / third parties to extend the per-post purge list
		// (e.g. translated permalinks, AMP variants).
		$urls = (array) apply_filters( 'rankready_post_purge_urls', $urls, $post );

		foreach ( $urls as $url ) {
			RNRD_Cache::purge_url( $url );
		}
	}

	/**
	 * Wrapper for transition_post_status so purges fire on publish/unpublish/
	 * trash even when save_post doesn't (e.g. quick edit, bulk actions).
	 *
	 * @since 1.0.1
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function purge_post_md_on_status( $new_status, $old_status, $post ): void {
		if ( $new_status === $old_status ) {
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		self::purge_post_md_url( $post->ID );
	}

	/**
	 * Prevent WordPress from adding trailing slash to .md URLs.
	 */
	public static function prevent_md_trailing_slash( $redirect_url, $requested_url ) {
		if ( preg_match( '/\.md\/?$/i', $requested_url ) ) {
			return false;
		}
		return $redirect_url;
	}

	// ── Query vars (named method so it can be removed) ───────────────────────

	public static function register_query_vars( array $vars ): array {
		$vars[] = 'rnrd_md_path';
		return $vars;
	}

	// ── Rewrite rules ────────────────────────────────────────────────────────

	public static function add_rewrite_rules(): void {
		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}

		// IMPORTANT: Exclude wp-admin, wp-content, wp-includes, wp-json paths
		// to prevent hijacking real .md files or admin/API routes.
		// Only match front-end content paths.
		add_rewrite_rule(
			'^(?!wp-admin|wp-content|wp-includes|wp-json)(.+)\.md$',
			'index.php?rnrd_md_path=$matches[1]',
			'top'
		);
	}

	// ── Handle .md URL request ───────────────────────────────────────────────

	/** Normalised current request path (no query string / surrounding slashes, subdirectory-aware). */
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

	public static function handle_request(): void {
		$md_path = get_query_var( 'rnrd_md_path', '' );

		// Fallback: if WP didn't surface the query var (SEO-plugin early router,
		// rewrite ordering, or a query_vars strip), match the raw ".md" request path.
		if ( '' === (string) $md_path && 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			$rnrd_path = self::request_path();
			if ( preg_match( '#^(?!wp-admin|wp-content|wp-includes|wp-json)(.+)\\.md$#', $rnrd_path, $rnrd_m ) ) {
				$md_path = $rnrd_m[1];
			}
		}

		if ( empty( $md_path ) ) {
			return;
		}

		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo '# 404 Not Found';
			exit;
		}

		// Extensible gate — integration filters (e.g. TranslatePress non-default
		// language) can return false to suppress markdown for this request.
		if ( ! (bool) apply_filters( 'rankready_should_serve_markdown', true ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo '# 404 Not Found';
			exit;
		}

		// 1) Front page surface at `/index.md`. serve_homepage_markdown() exits.
		if ( self::home_surfaces_enabled() && 'index' === trim( (string) $md_path, '/' ) ) {
			self::serve_homepage_markdown( false );
		}

		// 2) Posts page listing (is_home), e.g. /blog.md — match by page URI,
		// not url_to_postid() (returns 0 for the blog index) and not Markdown
		// post types. serve_posts_page_markdown() exits.
		$posts_md_path = self::posts_page_md_path();
		if ( self::home_surfaces_enabled() && '' !== $posts_md_path && $posts_md_path === trim( (string) $md_path, '/' ) ) {
			self::serve_posts_page_markdown( false );
		}

		// 3) Singular post/page.
		$post = self::resolve_post_from_path( $md_path );

		// Password-protected posts must not leak via .md — get_the_content()
		// hides the body on the HTML side, but post_to_markdown() reads raw
		// post_content, so gate explicitly here as the headless REST API does.
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo '# 404 Not Found';
			exit;
		}

		// When homepage/blog-index markdown is disabled, front/posts-page routes
		// must not fall through and reappear via the singular resolver.
		if ( ! self::home_surfaces_enabled() ) {
			$front_id      = (int) get_option( 'page_on_front', 0 );
			$posts_page_id = self::posts_page_id();
			if ( ( $front_id > 0 && (int) $post->ID === $front_id ) || ( $posts_page_id > 0 && (int) $post->ID === $posts_page_id ) ) {
				status_header( 404 );
				header( 'Content-Type: text/plain; charset=utf-8' );
				echo '# 404 Not Found';
				exit;
			}
		}

		// Check post type is enabled.
		$enabled_types = (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo '# 404 Not Found';
			exit;
		}

		if ( class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo '# 404 Not Found';
			exit;
		}

		// v1.1.17 — Switch to translated post if WPML / Polylang resolves a
		// translation for the active locale. Falls through to the original
		// $post when no translation plugin is active.
		$post = self::translate_post( $post );

		// Log with the resolved post so CPT, title, and ID are captured.
		RNRD_Crawler_Log::log( 'markdown', $post );

		$markdown = self::post_to_markdown( $post );
		self::serve_markdown( $markdown, get_permalink( $post ) );
	}

	// ── Accept header content negotiation ────────────────────────────────────
	// Like Next.js: when a request sends Accept: text/markdown on a normal
	// post URL, serve the markdown version directly.
	//
	// Implements RFC 9110 §12 content negotiation:
	//   - Parses q-values so text/html;q=1.0 beats text/markdown;q=0.5
	//   - Returns 406 when Accept excludes every type we can produce
	//
	// Also detects known AI crawler User-Agents (GPTBot, ClaudeBot, etc.)
	// and serves markdown regardless of Accept header.

	public static function handle_accept_header(): void {
		// Don't interfere when an explicit .md URL is being processed.
		// At this stage WordPress hasn't resolved the queried object yet
		// (only `rnrd_md_path` query var is set), so is_home() returns true
		// and we'd incorrectly serve the homepage index instead of the page.
		// handle_request() at priority 10 serves the correct page markdown.
		// Reported by a user in early testing.
		if ( '' !== (string) get_query_var( 'rnrd_md_path', '' ) ) {
			return;
		}

		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}

		// v1.2 — Same-URL Accept negotiation is ON by default. This is what the
		// ecosystem and validators (acceptmarkdown.com, Lighthouse Agentic
		// Browsing) expect, and what Joost de Valk / Roots ship in production
		// (Vary: Accept + a revalidating Cache-Control). Users can turn it off.
		if ( 'on' !== get_option( RNRD_OPT_MD_ACCEPT_NEGOTIATION, 'on' ) ) {
			return;
		}

		// …EXCEPT on Cloudflare APO, which caches HTML at the edge and ignores
		// both Vary: Accept and origin Cache-Control. Serving markdown on the
		// canonical URL there would poison the page cache and blank it for real
		// browsers. On APO we auto-fall-back to the distinct `.md` URLs (a
		// separate, poison-proof cache key) and the admin notice tells the user
		// how to enable negotiation via an APO Cache Rule. APO users who HAVE
		// added that Cache Rule can force negotiation back on with the filter.
		if ( self::is_apo_active() && ! apply_filters( 'rankready_force_accept_negotiation', false ) ) {
			return;
		}

		$ua             = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		// v1.2.0-beta.3 — sub-toggle for UA-based forced markdown. Default on.
		// Disabling restricts markdown to explicit Accept: text/markdown only.
		$force_markdown = 'on' === get_option( RNRD_OPT_MD_BOT_AUTO_SERVE, 'on' )
			&& ! empty( $ua )
			&& self::is_ai_bot( $ua );

		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';

		if ( ! $force_markdown ) {
			if ( empty( $accept ) ) {
				return;
			}

			$types = self::parse_accept_types( $accept );

			$q_markdown = self::get_type_q( $types, 'text/markdown' );
			$q_html     = self::get_type_q( $types, 'text/html' );
			$q_any      = self::get_type_q( $types, '*/*' );
			$q_text     = self::get_type_q( $types, 'text/*' );

			// Effective HTML q-value — wildcards count for HTML too.
			$q_html_eff = max( $q_html, $q_any, $q_text );

			if ( $q_markdown <= 0.0 ) {
				// text/markdown not in Accept or explicitly excluded (q=0).
				// If the client also can't accept HTML, nothing we serve will satisfy it.
				if ( $q_html_eff <= 0.0 ) {
					status_header( 406 );
					header( 'Content-Type: text/plain; charset=utf-8' );
					header( 'Vary: Accept' );
					echo '406 Not Acceptable'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					exit;
				}
				return;
			}

			// If HTML is strictly more preferred than markdown, let WordPress serve HTML normally.
			if ( $q_html > $q_markdown ) {
				return;
			}
		}

		// text/markdown is preferred (or tied, or forced by AI bot UA). Serve it.

		// Extensible gate — see rankready_should_serve_markdown filter.
		if ( ! (bool) apply_filters( 'rankready_should_serve_markdown', true ) ) {
			return;
		}

		// is_front_page() → is_home() → is_singular(). Front must win on a
		// latest-posts home where both front and home are true.
		if ( self::home_surfaces_enabled() && is_front_page() ) {
			self::serve_homepage_markdown();
			return;
		}

		if ( self::home_surfaces_enabled() && is_home() ) {
			if ( self::get_posts_page_post() instanceof WP_Post ) {
				self::serve_posts_page_markdown();
			}
			return;
		}

		// Singular post/page views.
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return;
		}

		$enabled_types = (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		if ( class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
			return;
		}

		// v1.1.17 — Switch to translated post if a translation plugin resolves
		// one for the active locale or for the visitor's Accept-Language.
		$post = self::translate_post( $post );

		// Log Accept-header markdown hit with the resolved post (CPT + title captured).
		RNRD_Crawler_Log::log( 'markdown', $post );

		$markdown = self::post_to_markdown( $post );

		// If markdown is empty, do not serve it — let WordPress serve the
		// normal HTML page so AI crawlers don't see an empty/404 markdown.
		if ( empty( trim( $markdown ) ) ) {
			return;
		}

		// v1.0.33 — Shared URL (canonical post URL, NOT `.md`): force no-store
		// so Cloudflare APO can't poison the cache with markdown for HTML clients.
		self::serve_markdown( $markdown, get_permalink( $post ), true );
	}

	/**
	 * Parse an Accept header string into [ 'type/subtype' => q_value ] map.
	 */
	private static function parse_accept_types( string $accept ): array {
		$types = array();
		foreach ( explode( ',', $accept ) as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}
			$segments = explode( ';', $part );
			$type     = strtolower( trim( $segments[0] ) );
			$q        = 1.0;
			foreach ( array_slice( $segments, 1 ) as $param ) {
				$param = trim( $param );
				if ( 0 === strncasecmp( $param, 'q=', 2 ) ) {
					$q = (float) substr( $param, 2 );
					break;
				}
			}
			$types[ $type ] = $q;
		}
		return $types;
	}

	/**
	 * Get the q-value for a media type from a parsed Accept types map.
	 * Returns 0.0 when the type is absent.
	 */
	private static function get_type_q( array $types, string $type ): float {
		return isset( $types[ $type ] ) ? (float) $types[ $type ] : 0.0;
	}

	public static function home_surfaces_enabled(): bool {
		return 'on' === get_option( RNRD_OPT_MD_HOME_ENABLE, 'on' );
	}

	/**
	 * Whether a post's distinct .md URL is actually served (not 404).
	 *
	 * Mirrors the gates in handle_request() so llms.txt never advertises dead .md links.
	 */
	public static function post_has_servable_md_url( WP_Post $post ): bool {
		if ( ! (bool) apply_filters( 'rankready_should_serve_markdown', true ) ) {
			return false;
		}

		$enabled_types = (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return false;
		}

		if ( class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
			return false;
		}

		if ( 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return false;
		}

		// Plain permalinks produce ?p=123.md paths the .md router cannot match.
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			return false;
		}

		if ( ! self::home_surfaces_enabled() ) {
			$front_id = (int) get_option( 'page_on_front', 0 );
			$blog_id  = self::posts_page_id();
			if ( ( $front_id > 0 && (int) $post->ID === $front_id ) || ( $blog_id > 0 && (int) $post->ID === $blog_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Distinct homepage markdown URL. Always /index.md — never example.com.md.
	 */
	public static function homepage_md_url(): string {
		return untrailingslashit( home_url( '/' ) ) . '/index.md';
	}

	/**
	 * Static front page when it can be served as homepage markdown.
	 *
	 * Homepage is first-class: does not require `page` in Markdown post types.
	 * Password-protected or AI-excluded front pages fall through to the overview.
	 */
	private static function get_front_page_post(): ?WP_Post {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return null;
		}
		$page_on_front = (int) get_option( 'page_on_front', 0 );
		if ( $page_on_front <= 0 ) {
			return null;
		}
		$post = get_post( $page_on_front );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return null;
		}
		if ( class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
			return null;
		}
		return $post;
	}

	/**
	 * Site-overview markdown for latest-posts homes (and excluded/password fronts).
	 */
	private static function build_homepage_overview_markdown(): string {
		// v1.2.0-rc.2 — hash the permalink structure so the transient name
		// stays under WordPress's 172-char limit on exotic configurations
		// (multilingual prefixes, custom CPT date paths, etc.).
		// Audit beta.3 #15.
		$cache_key = 'rnrd_md_homepage_' . md5( (string) get_option( 'permalink_structure', '' ) );
		$markdown  = get_transient( $cache_key );

		if ( false !== $markdown ) {
			return (string) $markdown;
		}

		$site_name = get_bloginfo( 'name' );
		$tagline   = get_bloginfo( 'description' );
		$home_url  = home_url( '/' );

		$lines   = array();
		$lines[] = '# ' . $site_name;
		if ( ! empty( $tagline ) ) {
			$lines[] = '';
			$lines[] = '> ' . $tagline;
		}
		$lines[] = '';
		$lines[] = 'Source: ' . $home_url;
		$lines[] = '';

		if ( 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) {
			$lines[] = 'Full site index: [llms.txt](' . home_url( '/llms.txt' ) . ')';
			$lines[] = '';
		}

		$posts = get_posts( array(
			'numberposts'      => 10,
			'post_status'      => 'publish',
			'has_password'     => false,
			'suppress_filters' => false,
		) );

		if ( ! empty( $posts ) ) {
			$lines[] = '## ' . __( 'Recent Posts', 'rankready-ai-llm-seo' );
			$lines[] = '';
			foreach ( $posts as $post ) {
				$title   = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
				$lines[] = '- [' . $title . '](' . get_permalink( $post ) . ')';
			}
			$lines[] = '';
		}

		$markdown = implode( "\n", $lines );
		set_transient( $cache_key, $markdown, HOUR_IN_SECONDS );

		return $markdown;
	}

	/**
	 * Reading Settings → Posts page ID, or 0 when latest posts are the front.
	 */
	private static function posts_page_id(): int {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}
		return (int) get_option( 'page_for_posts', 0 );
	}

	/**
	 * Path of the Posts page without leading/trailing slashes (e.g. "blog").
	 * Used to map /blog.md → the listing surface. Empty when there is no Posts page.
	 */
	private static function posts_page_md_path(): string {
		$id = self::posts_page_id();
		if ( $id <= 0 ) {
			return '';
		}
		return trim( (string) get_page_uri( $id ), '/' );
	}

	private static function posts_page_listing_transient_key(): string {
		return 'rnrd_md_posts_page_' . self::posts_page_id() . '_' . md5( (string) get_option( 'permalink_structure', '' ) );
	}

	/**
	 * Dedicated Posts page when it can be served as a listing surface.
	 *
	 * First-class: does not require `page` in Markdown post types.
	 * Password-protected or AI-excluded Posts pages are not advertised or served.
	 */
	private static function get_posts_page_post(): ?WP_Post {
		$id = self::posts_page_id();
		if ( $id <= 0 ) {
			return null;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return null;
		}
		if ( class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
			return null;
		}
		return $post;
	}

	/**
	 * Recent-posts listing for the dedicated Posts page (e.g. /blog.md).
	 */
	private static function build_posts_page_markdown( WP_Post $page ): string {
		$cache_key = self::posts_page_listing_transient_key();
		$markdown  = get_transient( $cache_key );
		if ( false !== $markdown ) {
			return (string) $markdown;
		}

		$title = html_entity_decode( get_the_title( $page ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $title ) {
			$title = __( 'Blog', 'rankready-ai-llm-seo' );
		}
		$source = get_permalink( $page );

		$lines   = array();
		$lines[] = '# ' . $title;
		$lines[] = '';
		$lines[] = 'Source: ' . $source;
		$lines[] = '';

		if ( 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) {
			$lines[] = 'Full site index: [llms.txt](' . home_url( '/llms.txt' ) . ')';
			$lines[] = '';
		}

		$posts = get_posts( array(
			'numberposts'      => 10,
			'post_status'      => 'publish',
			'has_password'     => false,
			'suppress_filters' => false,
		) );

		if ( ! empty( $posts ) ) {
			$lines[] = '## ' . __( 'Recent Posts', 'rankready-ai-llm-seo' );
			$lines[] = '';
			foreach ( $posts as $post ) {
				$post_title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
				$lines[]    = '- [' . $post_title . '](' . get_permalink( $post ) . ')';
			}
			$lines[] = '';
		}

		$markdown = implode( "\n", $lines );
		set_transient( $cache_key, $markdown, HOUR_IN_SECONDS );

		return $markdown;
	}

	/**
	 * Serve Posts-page listing markdown at `{posts-page}.md` or on the HTML
	 * blog URL via Accept / AI-bot UA. Exits.
	 */
	private static function serve_posts_page_markdown( bool $shared_url = true ): void {
		$page = self::get_posts_page_post();
		if ( ! $page instanceof WP_Post ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo '# 404 Not Found';
			exit;
		}

		RNRD_Crawler_Log::log( 'home_md' );
		$markdown  = self::build_posts_page_markdown( $page );
		$canonical = $shared_url ? get_permalink( $page ) : self::get_md_url( $page );
		self::serve_markdown( $markdown, $canonical, $shared_url );
	}

	/**
	 * Serve homepage markdown at `/index.md` or on `/` via Accept / AI-bot UA.
	 *
	 * Static front page → that page's markdown (even if Pages is unchecked).
	 * Latest-posts home → site overview. Both URLs share the same body.
	 *
	 * Cache policy depends on the URL:
	 *   - $shared_url = true  → canonical `/` (Accept). Force no-store so APO
	 *     cannot poison the HTML homepage (bug seen on nexterwp.com, 2026-06-02).
	 *   - $shared_url = false → distinct `/index.md`. Safe to cache publicly.
	 */
	private static function serve_homepage_markdown( bool $shared_url = true ): void {
		$post = self::get_front_page_post();
		if ( $post instanceof WP_Post ) {
			$post = self::translate_post( $post );
			RNRD_Crawler_Log::log( 'markdown', $post );
			$markdown = self::post_to_markdown( $post );
		} else {
			RNRD_Crawler_Log::log( 'home_md' );
			$markdown = self::build_homepage_overview_markdown();
		}

		$canonical = $shared_url ? home_url( '/' ) : self::homepage_md_url();
		self::serve_markdown( $markdown, $canonical, $shared_url );
	}

	// ── Vary: Accept header ───────────────────────────────────────────────────
	// Tells downstream caches (CDN, reverse proxy) to store separate versions
	// based on the Accept header, enabling correct content negotiation.

	public static function add_vary_header(): void {
		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}
		if ( is_admin() || defined( 'REST_REQUEST' ) ) {
			return;
		}

		// v1.2 — Only relevant when same-URL Accept negotiation is enabled
		// (ON by default). On Cloudflare APO we don't negotiate on the canonical
		// URL (see handle_accept_header), so we skip Vary: Accept there too —
		// markdown is served from the distinct `.md` URLs instead.
		if ( 'on' !== get_option( RNRD_OPT_MD_ACCEPT_NEGOTIATION, 'on' ) ) {
			return;
		}
		if ( self::is_apo_active() && ! apply_filters( 'rankready_force_accept_negotiation', false ) ) {
			return;
		}

		// v1.2.1 — Emit ONE merged Vary field line instead of appending a second.
		//
		// `header( 'Vary: Accept', false )` used to append, so a response that
		// already carried `Vary: Accept-Encoding` went out as two separate field
		// lines. RFC 9110 §5.3 says repeated field lines are equivalent to one
		// comma-joined line, so that was legal — but plenty of intermediaries and
		// validators read only the FIRST line. dualmark.dev's md.vary check
		// reported "got Accept-Encoding" on a production site for exactly this
		// reason. serve_markdown() already emits a single combined line; this
		// makes the HTML response consistent with it.
		$rnrd_vary = array();
		foreach ( headers_list() as $rnrd_sent ) {
			if ( 0 !== stripos( $rnrd_sent, 'Vary:' ) ) {
				continue;
			}
			foreach ( explode( ',', substr( $rnrd_sent, 5 ) ) as $rnrd_token ) {
				$rnrd_token = trim( $rnrd_token );
				if ( '' === $rnrd_token ) {
					continue;
				}
				// `Vary: *` means "uncacheable, never reuse" — adding tokens to it
				// would be meaningless and could confuse caches. Leave it alone.
				if ( '*' === $rnrd_token ) {
					return;
				}
				$rnrd_vary[ strtolower( $rnrd_token ) ] = $rnrd_token;
			}
		}
		$rnrd_vary['accept'] = 'Accept';

		// replace=true (the default) collapses every Vary line already sent into
		// this single one, preserving the original token order.
		header( 'Vary: ' . implode( ', ', $rnrd_vary ) );

		// NOTE — Why we DON'T try to defeat Cloudflare APO's cache key from PHP.
		//
		// Research (May 2026) of every WordPress plugin attempting markdown
		// content negotiation (Joost de Valk's Markdown Alternate, illodev's
		// markdown-negotiation-for-agents, the squin.org guide) found NONE of
		// them solve Cloudflare APO from origin code. Cloudflare's own cache
		// docs confirm APO's cache key is fixed: URL + querystring + device-
		// type only. There is no Vary-on-Accept support at the APO layer; no
		// PHP-emitted header can change that.
		//
		// The actual production answer is Cloudflare's "Markdown for Agents"
		// feature (launched Feb 2026, Pro/Business plan). It performs
		// HTML→Markdown conversion at the edge when `Accept: text/markdown` is
		// received, before APO's cache key ever matters. Site owners enable
		// it in Cloudflare Dashboard → AI Crawl Control. That's a one-toggle
		// fix that requires no plugin code.
		//
		// What RankReady DOES guarantee:
		//   - Distinct URLs (/post.md, /index.md, /llms.txt, /mcp.json) work
		//     through APO automatically (different cache keys per URL). This
		//     is how real AI agents discover content (via <link rel="alternate">
		//     in the HTML head) — and that path is APO-proof.
		//   - Origin-level content negotiation works on any CDN that respects
		//     Vary: Accept (Varnish, Fastly, BunnyCDN, etc.). Cloudflare APO
		//     specifically does not — that's a Cloudflare design choice.
		//
		// The admin notice for APO users explains both paths: Cloudflare's
		// built-in feature (preferred) or distinct URLs (always works).

		// rc.16 audit fix H1 — only fire the full cache-bypass stack when this
		// request is ACTUALLY negotiating markdown. The prior behaviour ran
		// no_cache_headers() on every HTML homepage hit, which killed page
		// cache for all visitors on cache-plugin sites. Vary: Accept (above)
		// alone is enough to tell well-behaved caches to store separate
		// representations.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		$wants_md = '' !== $accept && false !== stripos( $accept, 'text/markdown' );
		if ( self::home_surfaces_enabled() && is_front_page() && $wants_md ) {
			RNRD_Cache::no_cache_headers();
		}
		if ( self::home_surfaces_enabled() && is_home() && $wants_md ) {
			RNRD_Cache::no_cache_headers();
		}

		// FREE-108 / v1.0.1 — When the page has a markdown variant AND the
		// request explicitly asks for markdown, bypass every CDN/edge cache so
		// the markdown response always reaches the AI client. Cloudflare's
		// default cache key does NOT vary by Accept, so without this an HTML
		// response cached for one visitor gets served to every subsequent
		// markdown request. The headers below force a re-fetch from origin.
		//
		// We only fire this on Accept: text/markdown requests — regular browser
		// requests still hit the CDN cache normally, so cache-hit ratios stay
		// healthy for the 99% of traffic that is humans loading HTML.
		// v1.0.1 — canonical no-cache set centralised in RNRD_Cache::no_cache_headers().
		// Drops the previous duplicate emission here (was sending 4 of the same
		// headers twice on every markdown request). Casing now Title-Case across
		// the board.
		if ( $wants_md && ! headers_sent() ) {
			RNRD_Cache::no_cache_headers();
		}
	}

	// ── Link tag + header to .md version ─────────────────────────────────────
	// Helps crawlers discover the markdown version from the HTML page.

	public static function add_md_link_tag(): void {
		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}

		if ( ! (bool) apply_filters( 'rankready_should_serve_markdown', true ) ) {
			return;
		}

		// Front page: always advertise /index.md (not gated on Pages post type).
		if ( self::home_surfaces_enabled() && is_front_page() ) {
			self::echo_homepage_md_link_tags();
			return;
		}

		if ( self::home_surfaces_enabled() && is_home() ) {
			self::echo_posts_page_md_link_tags();
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$enabled_types = (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		if ( class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
			return;
		}

		$md_url = self::get_md_url( $post );
		echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $md_url ) . '" />' . "\n";

		// v1.1.17 — Emit per-language .md alternates when WPML / Polylang
		// expose translations of this post. Lets AI agents pick the right
		// language copy directly from <head>.
		foreach ( self::get_translation_md_urls( $post ) as $code => $translated_md_url ) {
			echo '<link rel="alternate" type="text/markdown" hreflang="' . esc_attr( $code ) . '" href="' . esc_url( $translated_md_url ) . '" />' . "\n";
		}
	}

	/**
	 * Emit a hidden div in <body> pointing AI agents to the .md version.
	 *
	 * Hooked on wp_body_open. Visually hidden via inline CSS + aria-hidden so
	 * it never reaches a screen reader or affects layout, but raw-HTML
	 * scrapers (which most AI agents are) see the text and the URL.
	 *
	 * Per the Evil Martians technique (April 2026) — combined with content
	 * negotiation, this gives AI scrapers a no-ambiguity "the canonical
	 * machine-readable copy is here" signal.
	 */
	public static function add_ai_hint_div(): void {
		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}
		if ( ! (bool) apply_filters( 'rankready_should_serve_markdown', true ) ) {
			return;
		}
		// v1.2.0-beta.3 — sub-toggle, default on. Users who prefer no hidden
		// content (some SEO purists do) can disable from Markdown Endpoints card.
		if ( 'on' !== get_option( RNRD_OPT_MD_HINT_DIV, 'on' ) ) {
			return;
		}

		if ( self::home_surfaces_enabled() && is_front_page() ) {
			self::echo_ai_hint_div( self::homepage_md_url() );
			return;
		}

		if ( self::home_surfaces_enabled() && is_home() ) {
			$page = self::get_posts_page_post();
			if ( $page instanceof WP_Post ) {
				self::echo_ai_hint_div( self::get_md_url( $page ) );
			}
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$enabled_types = (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		if ( class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
			return;
		}

		self::echo_ai_hint_div( self::get_md_url( $post ) );
	}

	/**
	 * Hidden body hint pointing scrapers at a .md URL.
	 */
	private static function echo_ai_hint_div( string $md_url ): void {
		// `aria-hidden` + clip-path drop the div from assistive tech and visual
		// layout entirely. Keeping it in the DOM (not display:none) means raw
		// HTML scrapers still see it — display:none can be ignored by some
		// rendering-aware crawlers as "hidden = irrelevant".
		echo '<div aria-hidden="true" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;">';
		// `tabindex="-1"` keeps the link out of the keyboard tab order. Without it,
		// an `aria-hidden` container holding a focusable <a> trips axe-core's
		// `aria-hidden-focus` rule (WCAG 4.1.2) — a keyboard/agent user can land on
		// an invisible link. Scrapers still read the href + text regardless.
		echo 'AI agents: a clean Markdown version of this page is available at <a href="' . esc_url( $md_url ) . '" tabindex="-1">' . esc_html( $md_url ) . '</a>. Send Accept: text/markdown to any URL for the same content.';
		echo '</div>' . "\n";
	}

	/**
	 * <link rel="alternate"> tags for the homepage /index.md surface.
	 */
	private static function echo_homepage_md_link_tags(): void {
		$md_url = self::homepage_md_url();
		echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $md_url ) . '" />' . "\n";

		$front = self::get_front_page_post();
		if ( ! $front instanceof WP_Post ) {
			return;
		}
		foreach ( self::get_translation_md_urls( $front ) as $code => $translated_md_url ) {
			echo '<link rel="alternate" type="text/markdown" hreflang="' . esc_attr( $code ) . '" href="' . esc_url( $translated_md_url ) . '" />' . "\n";
		}
	}

	/**
	 * HTTP Link headers for the homepage /index.md surface.
	 */
	private static function send_homepage_md_link_headers(): void {
		header( 'Link: <' . esc_url( self::homepage_md_url() ) . '>; rel="alternate"; type="text/markdown"', false );

		$front = self::get_front_page_post();
		if ( ! $front instanceof WP_Post ) {
			return;
		}
		foreach ( self::get_translation_md_urls( $front ) as $code => $translated_md_url ) {
			header( 'Link: <' . esc_url( $translated_md_url ) . '>; rel="alternate"; type="text/markdown"; hreflang="' . $code . '"', false );
		}
	}

	/**
	 * <link rel="alternate"> tags for the dedicated Posts page listing.
	 */
	private static function echo_posts_page_md_link_tags(): void {
		$page = self::get_posts_page_post();
		if ( ! $page instanceof WP_Post ) {
			return;
		}
		$md_url = self::get_md_url( $page );
		echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $md_url ) . '" />' . "\n";
		foreach ( self::get_translation_md_urls( $page ) as $code => $translated_md_url ) {
			echo '<link rel="alternate" type="text/markdown" hreflang="' . esc_attr( $code ) . '" href="' . esc_url( $translated_md_url ) . '" />' . "\n";
		}
	}

	/**
	 * HTTP Link headers for the dedicated Posts page listing.
	 */
	private static function send_posts_page_md_link_headers(): void {
		$page = self::get_posts_page_post();
		if ( ! $page instanceof WP_Post ) {
			return;
		}
		header( 'Link: <' . esc_url( self::get_md_url( $page ) ) . '>; rel="alternate"; type="text/markdown"', false );
		foreach ( self::get_translation_md_urls( $page ) as $code => $translated_md_url ) {
			header( 'Link: <' . esc_url( $translated_md_url ) . '>; rel="alternate"; type="text/markdown"; hreflang="' . $code . '"', false );
		}
	}

	public static function add_md_link_header(): void {
		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}

		if ( ! (bool) apply_filters( 'rankready_should_serve_markdown', true ) ) {
			return;
		}

		if ( self::home_surfaces_enabled() && is_front_page() ) {
			self::send_homepage_md_link_headers();
			return;
		}

		if ( self::home_surfaces_enabled() && is_home() ) {
			self::send_posts_page_md_link_headers();
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$enabled_types = (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		if ( class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
			return;
		}

		$md_url = self::get_md_url( $post );
		header( 'Link: <' . esc_url( $md_url ) . '>; rel="alternate"; type="text/markdown"', false );

		// v1.1.17 — per-language alternates as separate Link headers (RFC 8288).
		foreach ( self::get_translation_md_urls( $post ) as $code => $translated_md_url ) {
			header( 'Link: <' . esc_url( $translated_md_url ) . '>; rel="alternate"; type="text/markdown"; hreflang="' . $code . '"', false );
		}
	}

	/**
	 * Advertise the site's llms.txt to AI agents via an RFC 8288 Link header
	 * on the homepage. Improves agent discovery (per isitagentready.com /
	 * Dualmark checks) without any frontend impact.
	 *
	 * Only fires when llms.txt is enabled — guarantees the advertised URL
	 * actually resolves.
	 */
	public static function add_homepage_link_headers(): void {
		if ( ! is_front_page() || 'on' !== get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) {
			return;
		}
		header( 'Link: <' . esc_url( home_url( '/llms.txt' ) ) . '>; rel="describedby"; type="text/plain"', false );
	}

	// ── Multilingual resolution ──────────────────────────────────────────────
	// Delegated to RNRD_Integrations via filters. These thin wrappers keep
	// the public API intact so existing callers don't break.

	/**
	 * Best-effort swap of $post to its translated counterpart.
	 *
	 * @param WP_Post $post Original post resolved from the URL.
	 * @return WP_Post Translated post or the original.
	 */
	public static function translate_post( WP_Post $post ): WP_Post {
		$lang = self::detect_request_language();
		return apply_filters( 'rankready_translate_post', $post, $lang );
	}

	/**
	 * Resolve the locale string for a post.
	 *
	 * Returns the WPML/Polylang/TranslatePress language code for the post,
	 * or the WP locale as a last-resort fingerprint.
	 *
	 * @since 1.1.17
	 * @param WP_Post $post The post.
	 * @return string Locale/language code.
	 */
	public static function resolved_locale( WP_Post $post ): string {
		$locale = (string) apply_filters( 'rankready_resolved_locale', '', $post );
		if ( '' !== $locale ) {
			return $locale;
		}

		return sanitize_key( (string) get_locale() );
	}

	/**
	 * Pick the visitor's request language.
	 */
	private static function detect_request_language(): string {
		return (string) apply_filters( 'rankready_detect_language', '' );
	}

	/**
	 * Return [ language_code => translated_md_url ] pairs for hreflang.
	 *
	 * @param WP_Post $post Source post.
	 * @return array<string,string>
	 */
	public static function get_translation_md_urls( WP_Post $post ): array {
		return (array) apply_filters( 'rankready_translation_md_urls', array(), $post );
	}

	// ── AI bot User-Agent detection ──────────────────────────────────────────

	private static function is_ai_bot( string $ua ): bool {
		static $patterns = array(
			'GPTBot', 'ChatGPT-User', 'OAI-SearchBot',
			'ClaudeBot', 'anthropic-ai',
			'PerplexityBot',
			'Google-Extended', 'Googlebot-Extended',
			'cohere-ai',
			'AI2Bot',
			'Bytespider',
			'Diffbot',
		);
		foreach ( $patterns as $pattern ) {
			if ( false !== stripos( $ua, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	// ── Serve markdown response ──────────────────────────────────────────────

	private static function serve_markdown( string $markdown, string $canonical_url, bool $shared_url = false ): void {
		// v1.0.33 — Cache policy depends on whether the URL is DISTINCT or SHARED.
		//
		// DISTINCT URL (.md extension, e.g. `/post-slug.md`):
		//   The URL ALWAYS returns markdown regardless of request headers, so it
		//   is safe to cache publicly. We send a public Cache-Control with a TTL
		//   so any CDN — Cloudflare APO included — stores it for speed. Per-post
		//   purge on save keeps reachable layers fresh.
		//
		// SHARED URL (Accept-header negotiation on `/` or `/post-slug/`):
		//   The same URL returns HTML to browsers and markdown to AI agents. The
		//   correct cache key would include Accept, but Cloudflare APO and many
		//   shared-hosting page caches IGNORE `Vary: Accept` — they would store
		//   the markdown body under the canonical URL and serve it to every
		//   subsequent browser request, breaking the homepage site-wide.
		//   We therefore force `no-store` at every CDN layer for shared-URL
		//   responses. AI agents pay a small latency cost; the site stays safe.
		if ( $shared_url ) {
			RNRD_Cache::no_cache_headers();
		} else {
			$rnrd_md_ttl = (int) apply_filters( 'rankready_md_cache_max_age', HOUR_IN_SECONDS );
			if ( $rnrd_md_ttl > 0 ) {
				header( 'Cache-Control: public, max-age=' . $rnrd_md_ttl . ', s-maxage=' . $rnrd_md_ttl );
				// v1.2.0 — Keep the .md CDN/browser-cacheable (above), but tell PHP-level
				// page-cache PLUGINS (WP Rocket, LiteSpeed, W3TC, WP Super Cache…) to
				// bypass it. They serve a stored copy BEFORE this handler runs, which
				// drops the `X-Robots-Tag: noindex` + canonical Link set below — that is
				// how `.md` pages leak into Google's index as duplicate content. This
				// only defines DONOTCACHEPAGE/LSCWP_NO_CACHE (+ LSWS bypass headers); the
				// public Cache-Control stays, so CDNs (which preserve headers) still cache.
				if ( class_exists( 'RNRD_Cache' ) ) {
					RNRD_Cache::bypass_page_cache_plugins_only();
				}
			} else {
				RNRD_Cache::no_cache_headers();
			}
		}

		// Assert 200 explicitly — see the note in RNRD_Llms_Txt::serve_llms_txt().
		// template_redirect runs after the main query, so an intercepted rewrite
		// leaves WP's 404 status attached to an otherwise correct response.
		status_header( 200 );

		// Security / typing
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: text/markdown; charset=utf-8' );

		// v1.0.22 — For distinct `.md` URLs, the URL itself selects markdown so
		// Accept is irrelevant; Vary only on Accept-Encoding for gzip/identity.
		// v1.0.33 — For shared URLs (Accept-header path), include Accept in Vary.
		// Cloudflare APO won't honour it, but well-behaved caches (Varnish, Fastly,
		// Akamai, browser caches) will — combined with no-store above this still
		// gives the strongest possible defence against cache poisoning.
		// Distinct `.md` URLs: the URL itself selects markdown, so Accept has no
		// influence on the response — verified live, `/index.md` returns
		// text/markdown for `text/markdown`, `*/*`, `text/html`, `image/png` and
		// `application/json` alike. Declaring `Vary: Accept` here would therefore
		// be FALSE: it advertises a variance that does not exist and splits the
		// cache entry per Accept string for zero benefit.
		//
		// AEO Spec v1.0 §4 asks for `Vary: Accept` on every markdown response,
		// and dualmark's `md.vary` check fails us for this. We decline on
		// purpose. The spec's own §8 rationale for the rule is "caches that key
		// on URL alone MAY serve the wrong representation" — a risk that exists
		// only on the SHARED canonical URL, where we do set it (below). On a
		// distinct URL there is no wrong representation to serve. Correct cache
		// behaviour on real user sites outranks a conformance point.
		//
		// Shared canonical URL is the opposite case: there the representation
		// genuinely depends on Accept, so Vary: Accept is required for
		// correctness, not decoration.
		if ( $shared_url ) {
			header( 'Vary: Accept-Encoding, Accept' );
		} else {
			header( 'Vary: Accept-Encoding' );
		}

		// Robots / discovery
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'Link: <' . esc_url( $canonical_url ) . '>; rel="canonical"', false );

		// CORS — AI agents fetch from chat.openai.com, claude.ai, perplexity.ai
		// (different origin from the WordPress site). Without these headers
		// the browser-side fetch in the agent's runtime fails CORS preflight.
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, HEAD, OPTIONS' );
		header( 'Access-Control-Expose-Headers: Content-Type, ETag, Last-Modified, Link, X-Markdown-Tokens' );

		// Diagnostic / observability (Title-Case standardised, drops the stray
		// lowercase x-markdown-source from pre-v1.0.1).
		header( 'X-RankReady-Source: markdown-accept' );
		// v1.2.1 — `X-AEO-Version: 1.0` removed.
		//
		// It advertised conformance to the AEO Spec (dualmark.dev), which is a
		// vendor-authored proposed convention — its own overview states it "has
		// not been reviewed or adopted by the IETF, W3C, WHATWG, or any other
		// recognized standards body". Nothing read the header: not RankReady, not
		// any agent we could find, only that vendor's own scanner.
		//
		// It was also becoming a false claim. We deliberately do NOT implement
		// that spec's `Vary: Accept`-on-every-markdown-response rule, because on
		// a distinct `.md` URL the response does not vary by Accept (verified) and
		// declaring otherwise splits the cache for nothing. Advertising a spec
		// version while knowingly diverging from it is the kind of unprovable
		// claim we strip from copy — it does not belong in headers either.
		//
		// Everything else on this response stays because it is standards-based
		// and load-bearing: Vary (RFC 9110), Link rel=canonical (RFC 8288),
		// X-Robots-Tag noindex (de-facto since 2007, stops `.md` duplicates
		// indexing), CORS (W3C), nosniff.
		header( 'X-Markdown-Tokens: ' . max( 1, (int) ceil( mb_strlen( $markdown, 'UTF-8' ) / 4 ) ) );

		// Discard any stacked output buffers (e.g. TranslatePress) so they
		// don't process our plain-text markdown as HTML and corrupt it.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// ── Post resolver ────────────────────────────────────────────────────────

	/**
	 * Resolve a URL path (without .md) back to a WP_Post.
	 *
	 * Handles:
	 * - Pages: /about -> page "about"
	 * - Hierarchical pages: /docs/refunds -> page "refunds" under "docs"
	 * - Posts with /%postname%/: /hello-world -> post "hello-world"
	 * - Posts with date permalinks: /2024/01/15/hello-world -> post "hello-world"
	 * - Custom post types: /product/widget -> CPT "widget"
	 *
	 * @param string $path The URL path captured before .md (no leading/trailing slash).
	 * @return WP_Post|null
	 */
	private static function resolve_post_from_path( string $path ): ?WP_Post {
		// Clean path.
		$path = trim( $path, '/' );

		if ( empty( $path ) ) {
			return null;
		}

		// v1.0.1 — Strategy 0: Front-page alias. get_md_url() emits "/index.md"
		// for the site home when a static page is set as the front page. Map
		// that path to the actual front-page post here.
		if ( 'index' === $path ) {
			$page_on_front = (int) get_option( 'page_on_front', 0 );
			if ( $page_on_front > 0 ) {
				$post = get_post( $page_on_front );
				if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
					return $post;
				}
			}
		}

		// Posts page listing (is_home). url_to_postid() returns 0 because the
		// URL is the blog index, not a singular page. Map by page URI instead.
		$posts_md_path = self::posts_page_md_path();
		if ( '' !== $posts_md_path && $path === $posts_md_path ) {
			$post = get_post( self::posts_page_id() );
			if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
				return $post;
			}
		}

		// Strategy 1: Use url_to_postid() — works for most permalink structures.
		$url     = home_url( '/' . $path . '/' );
		$post_id = url_to_postid( $url );

		if ( $post_id > 0 ) {
			return get_post( $post_id );
		}

		// Also try without trailing slash.
		$url     = home_url( '/' . $path );
		$post_id = url_to_postid( $url );

		if ( $post_id > 0 ) {
			return get_post( $post_id );
		}

		// Strategy 2: Try get_page_by_path() for pages and hierarchical types.
		$enabled_types = (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		$post          = get_page_by_path( $path, OBJECT, $enabled_types );

		if ( $post instanceof WP_Post ) {
			return $post;
		}

		// Strategy 3: For CPTs with rewrite slugs, strip the CPT prefix.
		$segments = explode( '/', $path );
		if ( count( $segments ) >= 2 ) {
			$possible_cpt  = $segments[0];
			$possible_slug = implode( '/', array_slice( $segments, 1 ) );

			foreach ( $enabled_types as $pt ) {
				$type_obj = get_post_type_object( $pt );
				if ( ! $type_obj ) {
					continue;
				}

				$rewrite_slug = $pt;
				if ( ! empty( $type_obj->rewrite['slug'] ) ) {
					$rewrite_slug = $type_obj->rewrite['slug'];
				}

				if ( $possible_cpt === $rewrite_slug ) {
					$found = get_page_by_path( $possible_slug, OBJECT, $pt );
					if ( $found instanceof WP_Post ) {
						return $found;
					}
				}
			}
		}

		// Strategy 4: Last resort — slug lookup.
		$slug  = basename( $path );
		$posts = get_posts( array(
			'name'                   => $slug,
			'post_type'              => $enabled_types,
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		) );

		if ( ! empty( $posts ) ) {
			return $posts[0];
		}

		return null;
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// HTML-TO-MARKDOWN: Aggressive page-builder-safe converter
	//
	// Moved from RNRD_Llms_Txt in v1.3.2 — single source of truth for the
	// HTML→Markdown conversion. Strips ALL page builder wrappers and converts
	// semantic HTML to clean markdown. Page builder content is resolved via
	// the `rankready_post_content` filter (Elementor, BB, Divi, Oxygen,
	// Bricks, WPBakery, BeTheme Muffin Builder). WooCommerce transactional
	// blocks (cart, checkout, account) are stripped automatically.
	// ═══════════════════════════════════════════════════════════════════════════

	/**
	 * Convert a post's HTML content to clean markdown.
	 *
	 * Strips ALL Elementor, Beaver Builder, Divi, WPBakery, and generic
	 * page builder wrapper divs/sections/spans. Preserves only semantic
	 * content: headings, paragraphs, lists, links, images, blockquotes, code.
	 *
	 * @param WP_Post $post       The post to convert.
	 * @param bool    $run_filter Whether to run the `rankready_post_content`
	 *                            filter (page builders, shortcodes, WooCommerce
	 *                            stripping, etc.). Pass false for a lightweight
	 *                            post_content-only conversion.
	 * @return string Clean markdown body.
	 */
	public static function post_to_clean_markdown( $post, bool $run_filter = true ): string {
		if ( $run_filter ) {
			/**
			 * Filter the raw HTML content before markdown conversion.
			 *
			 * Page builders that don't store rendered output in post_content
			 * can use this filter to supply their rendered HTML. Each builder
			 * filter also strips its own wrapper markup.
			 *
			 * Shortcodes are executed by a dedicated filter at priority 90 —
			 * after all builder filters (which run their own do_shortcode
			 * internally when needed) but before WooCommerce cleanup at 99.
			 *
			 * @since 1.3.2-beta1
			 * @param string  $html The raw HTML (post_content by default).
			 * @param WP_Post $post The post being converted.
			 */
			$html = (string) apply_filters( 'rankready_post_content', $post->post_content, $post );
		} else {
			$html = $post->post_content;
		}

		if ( empty( $html ) ) {
			return '';
		}

		// ── Step 1: Strip Gutenberg block comments ────────────────────────
		$html = preg_replace( '/<!--\s*\/?wp:[^\>]+-->/s', '', $html );

		// ── Step 2: Strip remaining layout wrappers ───────────────────────
		// Builder-specific wrappers (Elementor, Divi, WPBakery, BB, Bricks,
		// Oxygen, Muffin) are stripped inside each builder's own filter. This
		// step handles generic wrappers that any theme or plugin may produce.

		// Strip generic layout wrappers.
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:wp-block-|entry-|post-|content-|container|wrapper|row|col-|grid)[^"]*"[^>]*>/si', '', $html );

		// Remove stray closing divs and sections.
		$html = preg_replace( '/<\/(?:div|section|article|aside|main|header|footer|nav|figure|figcaption)>/si', '', $html );

		// Strip inline styles and data attributes from remaining elements.
		$html = preg_replace( '/\s+style="[^"]*"/si', '', $html );
		$html = preg_replace( '/\s+data-[a-z0-9_-]+="[^"]*"/si', '', $html );
		$html = preg_replace( '/\s+class="[^"]*"/si', '', $html );
		$html = preg_replace( '/\s+id="[^"]*"/si', '', $html );

		// ── Step 3: Convert semantic HTML to markdown ─────────────────────

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

		// ── Step 4: Strip ALL remaining HTML tags ─────────────────────────
		$html = wp_strip_all_tags( $html );

		// ── Step 5: Decode entities and clean whitespace ──────────────────
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
	public static function table_to_markdown( string $table_html ): string {
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

	// ═══════════════════════════════════════════════════════════════════════════
	// CACHED MARKDOWN — post meta storage with timestamp tracking (v1.3.2)
	//
	// Instead of converting HTML→Markdown on every request, we cache the
	// clean markdown body in post meta and regenerate on post save/update.
	// ═══════════════════════════════════════════════════════════════════════════

	/**
	 * Get cached markdown for a post, generating + caching if needed.
	 *
	 * This is the primary entry point for consumers that need the clean
	 * markdown body of a post. It checks post meta first and only runs
	 * the HTML→Markdown conversion when the cache is missing or stale.
	 *
	 * Cache is considered valid when the cached timestamp is > 0 AND
	 * was written after the post's last modification date.
	 *
	 * When generation is not possible (cap reached or another request
	 * holds the lock), we return stale cache if available, or fall back
	 * to a lightweight post_content-only markdown (no page builder
	 * filters) to avoid returning empty.
	 *
	 * @since 1.3.2-beta1
	 * @param WP_Post $post The post to get markdown for.
	 * @return string Clean markdown body (may be empty for posts with no content).
	 */
	public static function get_post_markdown( WP_Post $post ): string {
		$markdown      = (string) get_post_meta( $post->ID, RNRD_META_POST_MARKDOWN, true );
		$cached_ts     = (int) get_post_meta( $post->ID, RNRD_META_POST_MARKDOWN_TS, true );
		$post_modified = (int) get_post_modified_time( 'U', true, $post );

		// Cache hit — fresh and valid.
		if ( ! empty( $markdown ) && $cached_ts > 0 && $cached_ts >= $post_modified ) {
			return $markdown;
		}

		// Cap: prevent a single request from generating hundreds of posts.
		if ( self::$generation_count >= self::GENERATION_CAP ) {
			if ( ! empty( $markdown ) ) {
				return $markdown;
			}
			// Lightweight fallback — post_content only, no builder filters.
			if ( ! empty( $post->post_content ) ) {
				return self::post_to_clean_markdown( $post, false );
			}
			return '';
		}

		// Short lock to prevent concurrent requests from duplicating rows.
		$lock_key = 'rnrd_md_gen_' . $post->ID;
		if ( false === get_transient( $lock_key ) ) {
			set_transient( $lock_key, 1, 30 );
		} else {
			// Another request is already generating.
			if ( ! empty( $markdown ) ) {
				return $markdown;
			}
			if ( ! empty( $post->post_content ) ) {
				return self::post_to_clean_markdown( $post, false );
			}
			return '';
		}

		$markdown = self::post_to_clean_markdown( $post );
		self::save_post_markdown( $post->ID, $markdown );
		self::$generation_count++;

		delete_transient( $lock_key );

		return $markdown;
	}

	/**
	 * Save the cached markdown and timestamp for a post.
	 *
	 * @since 1.3.2-beta1
	 * @param int    $post_id  Post ID.
	 * @param string $markdown The markdown body to cache.
	 */
	private static function save_post_markdown( int $post_id, string $markdown ): void {
		update_post_meta( $post_id, RNRD_META_POST_MARKDOWN, $markdown );
		update_post_meta( $post_id, RNRD_META_POST_MARKDOWN_TS, time() );
	}

	/**
	 * Hook callback for wp_after_insert_post (WP 5.6+).
	 *
	 * This is the most reliable hook: it fires after all meta, terms, and
	 * taxonomies have been saved, covering classic editor, Gutenberg, REST
	 * API, and programmatic wp_insert_post/wp_update_post calls.
	 *
	 * @since 1.3.2-beta1
	 * @param int          $post_id Post ID.
	 * @param WP_Post      $post    Post object.
	 * @param bool         $update  Whether this is an update (vs insert).
	 */
	public static function on_after_insert_post( $post_id, $post, $update ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		self::maybe_regenerate_markdown_on_save( (int) $post_id, $post );
	}

	/**
	 * Hook callback for save_post — fallback for edge cases not covered
	 * by wp_after_insert_post (e.g. older WP versions, custom code paths).
	 *
	 * @since 1.3.2-beta1
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function on_save_post( $post_id, $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		self::maybe_regenerate_markdown_on_save( (int) $post_id, $post );
	}

	/**
	 * Hook callback for transition_post_status — catches status changes from
	 * quick edit, bulk actions, and REST API that may not trigger save_post.
	 *
	 * Only regenerates when the new status is 'publish'. When a post is
	 * unpublished, the cache is cleared inside maybe_regenerate_markdown_on_save.
	 *
	 * @since 1.3.2-beta1
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		// Only act when status actually changed.
		if ( $new_status === $old_status ) {
			return;
		}
		self::maybe_regenerate_markdown_on_save( (int) $post->ID, $post );
	}

	/**
	 * Regenerate and cache a post's markdown on save/update.
	 *
	 * Only runs when at least one AI surface feature (Markdown endpoints,
	 * llms.txt, or OKF) is enabled, the post type is supported by at least
	 * one of those features, and the post is not excluded from AI surfaces.
	 *
	 * @since 1.3.2-beta1
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function maybe_regenerate_markdown_on_save( int $post_id, WP_Post $post ): void {
		// Skip revisions and autosaves.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Only process published posts.
		if ( 'publish' !== $post->post_status ) {
			// If the post was unpublished, clear the cached markdown.
			delete_post_meta( $post_id, RNRD_META_POST_MARKDOWN );
			delete_post_meta( $post_id, RNRD_META_POST_MARKDOWN_TS );
			return;
		}

		// Guard: multiple hooks (wp_after_insert_post, save_post,
		// transition_post_status) may fire in the same request. Only
		// process each post once. Flag is set AFTER generation (not
		// before) so the most-reliable hook (wp_after_insert_post)
		// is never blocked by an earlier hook that ran before meta
		// and terms were fully saved.
		if ( isset( self::$processed_post_ids[ $post_id ] ) ) {
			return;
		}

		// Check if at least one AI surface feature is enabled.
		$md_on   = 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' );
		$llms_on = 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$okf_on  = class_exists( 'RNRD_OKF' ) && 'on' === get_option( RNRD_OPT_OKF_ENABLE, 'off' );

		if ( ! $md_on && ! $llms_on && ! $okf_on ) {
			// All AI surfaces off — clear stale cache so edits aren't
			// served from an outdated snapshot once a surface is re-enabled.
			delete_post_meta( $post_id, RNRD_META_POST_MARKDOWN );
			delete_post_meta( $post_id, RNRD_META_POST_MARKDOWN_TS );
			return;
		}

		// Check if the post type is supported by any enabled feature.
		$supported = false;
		if ( $md_on ) {
			$md_types = (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
			if ( in_array( $post->post_type, $md_types, true ) ) {
				$supported = true;
			}
		}
		if ( ! $supported && $llms_on ) {
			$llms_types = (array) get_option( RNRD_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) );
			if ( in_array( $post->post_type, $llms_types, true ) ) {
				$supported = true;
			}
		}
		if ( ! $supported && $okf_on ) {
			$okf_types = class_exists( 'RNRD_OKF' ) ? RNRD_OKF::post_types() : array( 'post', 'page' );
			if ( in_array( $post->post_type, $okf_types, true ) ) {
				$supported = true;
			}
		}

		if ( ! $supported ) {
			// Post type not covered by any active surface — clear stale cache.
			delete_post_meta( $post_id, RNRD_META_POST_MARKDOWN );
			delete_post_meta( $post_id, RNRD_META_POST_MARKDOWN_TS );
			return;
		}

		// Check if the post is excluded from AI surfaces.
		if ( class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
			// Post is excluded — clear any stale cached markdown.
			delete_post_meta( $post_id, RNRD_META_POST_MARKDOWN );
			delete_post_meta( $post_id, RNRD_META_POST_MARKDOWN_TS );
			return;
		}

		// Generate and cache the markdown. Set the guard AFTER so a
		// second wp_update_post() in the same request (ACF, Yoast,
		// translation plugins) can still re-generate if needed.
		$markdown = self::post_to_clean_markdown( $post );
		self::save_post_markdown( $post_id, $markdown );
		self::$processed_post_ids[ $post_id ] = true;
	}

	// ── Markdown generator ───────────────────────────────────────────────────

	public static function post_to_markdown( WP_Post $post ): string {
		$lines = array();

		// ── YAML frontmatter ─────────────────────────────────────────────
		$include_meta = (bool) get_option( RNRD_OPT_MD_INCLUDE_META, '1' );

		if ( $include_meta ) {
			$lines[] = '---';
			$lines[] = 'title: "' . self::yaml_escape( get_the_title( $post ) ) . '"';
			$lines[] = 'url: ' . get_permalink( $post );
			$lines[] = 'date: ' . get_post_time( 'Y-m-d', false, $post );
			$lines[] = 'modified: ' . get_post_modified_time( 'Y-m-d', false, $post );
			$lines[] = 'lang: ' . RNRD_LLM::detect_content_language( (int) $post->ID )['code'];
			$lines[] = 'author: "' . self::yaml_escape( get_the_author_meta( 'display_name', $post->post_author ) ) . '"';

			// Excerpt.
			$excerpt = ! empty( $post->post_excerpt )
				? $post->post_excerpt
				: wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '...' );
			$lines[] = 'description: "' . self::yaml_escape( $excerpt ) . '"';

			// Categories.
			$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
			if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
				$lines[] = 'categories:';
				foreach ( $categories as $cat ) {
					$lines[] = '  - "' . self::yaml_escape( $cat ) . '"';
				}
			}

			// Tags.
			$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
			if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
				$lines[] = 'tags:';
				foreach ( $tags as $tag ) {
					$lines[] = '  - "' . self::yaml_escape( $tag ) . '"';
				}
			}

			// Featured image.
			if ( has_post_thumbnail( $post->ID ) ) {
				$thumb_url = get_the_post_thumbnail_url( $post->ID, 'large' );
				if ( $thumb_url ) {
					$lines[] = 'image: ' . $thumb_url;
				}
			}

			// Word count.
			$plain    = wp_strip_all_tags( $post->post_content );
			$wc       = preg_match_all( '/\S+/', $plain ); // locale-safe word count.
			$lines[]  = 'word_count: ' . ( false !== $wc ? $wc : 0 );

			$lines[] = '---';
			$lines[] = '';
		}

		// ── Title ────────────────────────────────────────────────────────
		$lines[] = '# ' . self::clean_text( get_the_title( $post ) );
		$lines[] = '';

		// ── AI Summary (if available) ────────────────────────────────────
		if ( class_exists( 'RNRD_Summary' ) && RNRD_Summary::is_enabled() && RNRD_Summary::is_post_type_enabled( $post->post_type ) ) {
			$summary_raw = (string) get_post_meta( $post->ID, RNRD_META_SUMMARY, true );
			if ( ! empty( $summary_raw ) ) {
				$summary = RNRD_Generator::decode_summary( $summary_raw );
				if ( 'bullets' === $summary['type'] && ! empty( $summary['data'] ) ) {
					$lines[] = '## ' . get_option( RNRD_OPT_LABEL, __( 'Key Takeaways', 'rankready-ai-llm-seo' ) );
					$lines[] = '';
					foreach ( $summary['data'] as $bullet ) {
						$lines[] = '- ' . self::clean_text( $bullet );
					}
					$lines[] = '';
				}
			}
		}

		// ── Content ──────────────────────────────────────────────────────
		$content = self::get_post_markdown( $post );

		if ( ! empty( $content ) ) {
			$lines[] = $content;
		}

		// ── FAQ section (if available) ───────────────────────────────
		if ( class_exists( 'RNRD_Faq' ) ) {
			$faq_md = RNRD_Faq::get_faq_markdown( $post->ID );
			if ( ! empty( $faq_md ) ) {
				$lines[] = '';
				$lines[] = $faq_md;
			}
		}

		return implode( "\n", $lines );
	}

	// ── Get .md URL for a post ───────────────────────────────────────────────

	/**
	 * Get the .md URL for a given post.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return string The .md URL (e.g., https://example.com/hello-world.md).
	 */
	public static function get_md_url( $post ): string {
		$permalink = get_permalink( $post );

		// v1.0.1 — Front-page edge case. When a static page is set as the site
		// home (Reading Settings → "A static page"), get_permalink() returns
		// the bare home URL like "https://example.com/". untrailingslashit() +
		// ".md" would produce "https://example.com.md", which is a different
		// host entirely (`.md` is the country TLD for Moldova). Use a stable
		// "/index.md" path under the same domain instead. The rewrite rule
		// already catches `.md` paths; resolve_post_from_path() recognises
		// "index" as the front page.
		$home_no_slash = untrailingslashit( home_url( '/' ) );
		if ( untrailingslashit( $permalink ) === $home_no_slash ) {
			return $home_no_slash . '/index.md';
		}

		return untrailingslashit( $permalink ) . '.md';
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private static function clean_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	private static function yaml_escape( string $text ): string {
		$text = self::clean_text( $text );
		$text = str_replace( '"', '\\"', $text );
		return $text;
	}
}
