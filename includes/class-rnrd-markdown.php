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
 * Aggressively strips Elementor, Divi, WPBakery, Beaver Builder markup.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Markdown {

	public static function init(): void {
		// Admin-only hooks — gated so the markdown class (which MUST load on the
		// frontend to serve .md) doesn't register no-op admin handlers on public
		// requests. The APO notice + its dismiss handler only matter in wp-admin.
		if ( is_admin() ) {
			add_action( 'admin_init',    array( self::class, 'handle_apo_dismiss' ) );
			add_action( 'admin_notices', array( self::class, 'maybe_cloudflare_apo_notice' ) );
		}
		add_action( 'init',              array( self::class, 'add_rewrite_rules' ) );
		// issue #41 — shared, filterable base priority instead of a hardcoded
		// small number (was: "priority 1 beats page builders ~ default 10", which
		// a builder or mu-plugin hooking template_redirect even earlier could
		// still beat). rnrd_template_redirect_priority() defaults to -9999.
		add_action( 'template_redirect', array( self::class, 'handle_request' ), rnrd_template_redirect_priority( 1 ) );

		// Content negotiation: serve markdown when Accept: text/markdown is sent.
		// Offset 2 — still before page builders but AFTER the explicit .md URL
		// handler (offset 1), same relative order as before.
		add_action( 'template_redirect', array( self::class, 'handle_accept_header' ), rnrd_template_redirect_priority( 2 ) );

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

		// v1.4.1 - per-post cache VERSION, bumped when output-affecting meta or
		// terms change without touching post_modified. Works on any object cache.
		add_action( 'added_post_meta',   array( self::class, 'on_watched_meta_change' ), 20, 3 );
		add_action( 'updated_post_meta', array( self::class, 'on_watched_meta_change' ), 20, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'on_watched_meta_change' ), 20, 3 );
		add_action( 'set_object_terms',  array( self::class, 'on_terms_change' ), 20, 1 );

		// Settings that change the rendered body of EVERY post: a stale body
		// survived un-ticking and re-ticking the frontmatter toggle.
		foreach ( array( RNRD_OPT_MD_INCLUDE_META, RNRD_OPT_LABEL ) as $rnrd_md_opt ) {
			add_action( 'update_option_' . $rnrd_md_opt, array( self::class, 'bump_md_settings_version' ) );
			add_action( 'add_option_' . $rnrd_md_opt, array( self::class, 'bump_md_settings_version' ) );
		}

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


	/** Post meta key holding the per-post .md cache version. Permanent once shipped. */
	const MD_VERSION_META = '_rnrd_md_ver';

	/** @var array<int,bool> Posts already version-bumped in this request. */
	private static $md_bumped = array();

	/**
	 * Current per-post .md cache version (0 when never bumped).
	 */
	public static function md_cache_version( int $post_id ): int {
		return max( 0, (int) get_post_meta( $post_id, self::MD_VERSION_META, true ) );
	}

	/**
	 * Transient key for a post's rendered Markdown. Includes the per-post
	 * version so a bump invalidates every locale variant on ANY cache backend
	 * (Redis, Memcached, DB); the old LIKE sweep only ever saw the options table.
	 */
	public static function md_cache_key( WP_Post $post, string $locale ): string {
		// A key read means this request is about to serve or store output; a later
		// change in the same request must bump again, not be swallowed as a duplicate.
		unset( self::$md_bumped[ (int) $post->ID ] );
		/**
		 * Extra cache salt for add-ons whose settings change every post's output
		 * (Pro custom fields allowlist). Empty by default; changing the value
		 * invalidates all cached Markdown on any cache backend.
		 *
		 * @since 1.4.1
		 *
		 * @param string $salt Salt, digits and letters only.
		 */
		$salt = preg_replace( '/[^A-Za-z0-9]/', '', (string) apply_filters( 'rankready_md_cache_salt', '' ) );
		$settings_ver = preg_replace( '/[^A-Za-z0-9]/', '', (string) get_option( self::MD_SETTINGS_VER_OPT, '' ) );
		return 'rnrd_md_' . $post->ID . '_' . $locale . '_' . strtotime( $post->post_modified ) . '_v' . self::md_cache_version( (int) $post->ID ) . ( '' !== $settings_ver ? '_o' . $settings_ver : '' ) . ( '' !== $salt ? '_s' . $salt : '' );
	}

	/** Option holding the global Markdown-settings cache version. Permanent once shipped. */
	const MD_SETTINGS_VER_OPT = 'rnrd_md_settings_ver';

	/** @var bool A settings bump already ran in this request (several options save together). */
	private static $md_settings_bumped = false;

	/**
	 * Invalidate every cached Markdown body after an output-affecting setting
	 * changed (frontmatter on/off, summary label). Debounced to one write per
	 * request, because a settings form saves several options at once. The new
	 * value is part of the transient key, so it works on any object cache and
	 * the old entries simply expire.
	 */
	public static function bump_md_settings_version(): void {
		if ( self::$md_settings_bumped ) {
			return;
		}
		self::$md_settings_bumped = true;
		update_option( self::MD_SETTINGS_VER_OPT, substr( md5( uniqid( '', true ) ), 0, 12 ), false );
	}

	/**
	 * Atomically bump a post's .md cache version. Once per post per request.
	 * Old-key transients simply expire (5 min TTL); nothing is migrated.
	 */
	public static function bump_md_version( int $post_id ): void {
		if ( $post_id <= 0 || isset( self::$md_bumped[ $post_id ] ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! class_exists( 'RNRD_Atomic' ) ) {
			return;
		}
		self::$md_bumped[ $post_id ] = true;
		try {
			RNRD_Atomic::bump_post_meta_int( $post_id, self::MD_VERSION_META, 1 );
		} catch ( \Throwable $e ) {
			unset( self::$md_bumped[ $post_id ] );
		}
	}

	/**
	 * Meta keys whose change alters the rendered Markdown.
	 *
	 * RankReady's own output-affecting keys, plus anything a third party (Pro:
	 * ACF fields) registers. Bookkeeping keys (hashes, cooldown clocks, the
	 * version counter itself) are deliberately excluded so meta write storms do
	 * not purge the cache.
	 *
	 * @param int $post_id Post whose meta changed.
	 * @return string[] Exact meta keys.
	 */
	public static function watched_meta_keys( int $post_id ): array {
		$keys = array(
			'_rnrd_summary',
			'_rnrd_faq',
			'_rnrd_disable_summary',
			'_rnrd_faq_disable',
			'_rnrd_llms_exclude',
			'_rnrd_schema_type',
			'_rnrd_schema_data',
			'_rnrd_max_snippet',
		);
		/**
		 * Meta keys that invalidate a post's cached Markdown when written.
		 *
		 * Exact keys only (no wildcards). Pro registers ACF field keys here.
		 * RankReady's own output-affecting keys are always watched; this adds to them.
		 * Permanent public contract: the signature will not change.
		 *
		 * @since 1.4.1
		 * @param string[] $keys    Exact meta keys, empty by default.
		 * @param int      $post_id Post whose meta changed.
		 */
		$extra = apply_filters( 'rankready_md_watched_meta_keys', array(), $post_id );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $k ) {
				if ( is_string( $k ) && '' !== $k ) {
					$keys[] = $k;
				}
			}
		}
		return $keys;
	}

	/**
	 * added/updated/deleted_post_meta handler. Cheap early exits so unrelated
	 * meta write storms cost one in_array.
	 *
	 * @param mixed  $meta_id  Meta ID (or IDs on delete). Unused.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 */
	public static function on_watched_meta_change( $meta_id, $post_id = 0, $meta_key = '' ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! is_string( $meta_key ) || '' === $meta_key || self::MD_VERSION_META === $meta_key ) {
			return;
		}
		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}
		if ( ! in_array( $meta_key, self::watched_meta_keys( $post_id ), true ) ) {
			return;
		}
		self::bump_md_version( $post_id );
	}

	/**
	 * set_object_terms handler (categories and tags appear in frontmatter).
	 *
	 * @param int $object_id Object whose terms were set.
	 */
	public static function on_terms_change( $object_id = 0 ): void {
		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}
		self::bump_md_version( (int) $object_id );
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

		// v1.4.1 - bump the per-post cache version (object-cache safe).
		self::bump_md_version( (int) $post_id );

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

		// v1.1.5 (#7) — purge ALL rendered-markdown transients for this post. The cache
		// key is rnrd_md_<id>_<locale>_<mtime> (set at render time), but the old explicit
		// delete used 'rnrd_md_<id>_<mtime>' — it omitted the locale AND rebuilt the key
		// with the *new* post_modified time, so it never matched the stored entry (it only
		// self-expired via the 5-min TTL, and multilingual locale variants leaked as orphan
		// transients). Sweep by the post-ID prefix so every locale/timestamp variant is
		// dropped and explicit busts actually work. (DB transients; with an external object
		// cache the per-key TTL + mtime-in-key still guarantees freshness.)
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

		// v1.1.17 — Switch to translated post if WPML / Polylang / TranslatePress
		// resolves a translation for the active locale. Falls through to the
		// original $post when no translation plugin is active.
		$post   = self::translate_post( $post );
		$locale = self::resolved_locale( $post );

		// issue #9 — page-builder posts (Elementor et al.) can have an empty
		// `post_content` column even though the frontend renders real content.
		// RNRD_Generator::resolve_post_content() already tries the builder's own
		// render pipeline and the `the_content` filter chain before giving up; if
		// even that resolves to nothing, there is no real body to serve. A 200
		// with an empty content section reads to an AI crawler as confirmed thin
		// content, which is worse than a 404 — so 404 rather than serve it.
		// Log with the resolved post so CPT, title, and ID are captured.
		RNRD_Crawler_Log::log( 'markdown', $post );

		$cache_key = self::md_cache_key( $post, $locale );
		$markdown  = get_transient( $cache_key );
		if ( false === $markdown ) {
			// Emptiness probe runs on a cache MISS only, so a cached hit costs
			// nothing (builder pages used to pay a full render per crawler hit).
			if ( class_exists( 'RNRD_Generator' ) && ! self::has_servable_facts( $post ) ) {
				$rnrd_resolved_body = trim( wp_strip_all_tags( RNRD_Generator::resolve_post_content( $post ) ) );
				// A post made of RankReady blocks that render nothing (feature off, no data yet) is still a real page: serve title and frontmatter.
				$rnrd_has_own_blocks = false !== strpos( (string) $post->post_content, '<!-- wp:rankready/' );
				if ( '' === $rnrd_resolved_body && ! $rnrd_has_own_blocks ) {
					status_header( 404 );
					header( 'Content-Type: text/plain; charset=utf-8' );
					echo '# 404 Not Found';
					exit;
				}
			}
			$markdown = self::post_to_markdown( $post );
			set_transient( $cache_key, $markdown, 5 * MINUTE_IN_SECONDS );
		}
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

		// A URL that does not exist is 404, whatever the client will accept.
		// Without this, a request for a missing page carrying an Accept header
		// that excludes HTML fell through to the 406 branch below and answered
		// "Not Acceptable" — which tells a crawler the resource EXISTS and we
		// merely cannot represent it. template_redirect runs after the main
		// query, so is_404() is already authoritative here.
		if ( is_404() ) {
			return;
		}

		// Feeds, robots.txt, embeds and the core XML sitemaps have their own
		// content types and are never a page RankReady could serve as markdown.
		// template_redirect fires for all of them, so without this an RSS reader
		// or a crawler sending an Accept header without HTML got "406" on the feed.
		if ( is_feed() || is_robots() || is_embed() || is_trackback() || '' !== (string) get_query_var( 'sitemap', '' ) || '' !== (string) get_query_var( 'sitemap-stylesheet', '' ) ) {
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
					// Only answer 406 on a URL RankReady could have served as
					// markdown. An archive, search or any other page is not ours to
					// refuse (an uptime probe sending Accept: application/json).
					if ( ! is_singular() && ! is_front_page() && ! is_home() ) {
						return;
					}
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
		$post   = self::translate_post( $post );
		$locale = self::resolved_locale( $post );

		// Log Accept-header markdown hit with the resolved post (CPT + title captured).
		RNRD_Crawler_Log::log( 'markdown', $post );

		$cache_key = self::md_cache_key( $post, $locale );
		$markdown  = get_transient( $cache_key );
		if ( false === $markdown ) {
			$markdown = self::post_to_markdown( $post );
			set_transient( $cache_key, $markdown, 5 * MINUTE_IN_SECONDS );
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

		// A page with no body at all (the WooCommerce shop page is the usual
		// one: an archive with empty post_content) is answered with a 404 by
		// handle_request(), so it must not be advertised as a .md link.
		// Pages that carry a shortcode, a block comment or builder data may
		// still render real content, so only a truly empty page is skipped.
		$raw = (string) $post->post_content;
		if ( '' === trim( wp_strip_all_tags( $raw ) ) && false === strpos( $raw, '[' ) && false === strpos( $raw, '<!-- wp:' )
			&& '' === (string) get_post_meta( $post->ID, '_elementor_data', true )
			&& ! self::has_servable_facts( $post ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether an add-on supplies facts for a post whose body is empty, so its
	 * .md is worth serving (a WooCommerce product with a price and stock but no
	 * description). False by default: an empty page stays a 404.
	 *
	 * @since 1.4.2
	 *
	 * @param WP_Post $post Post being served.
	 */
	public static function has_servable_facts( WP_Post $post ): bool {
		/**
		 * Return true when the add-on will write real facts into this post's
		 * Markdown even though its body is empty. Pro returns true for a
		 * WooCommerce product that has product facts.
		 *
		 * @since 1.4.2
		 *
		 * @param bool    $has_facts Default false.
		 * @param WP_Post $post      Post being served.
		 */
		return (bool) apply_filters( 'rankready_md_has_facts', false, $post );
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

		$lines = array_merge( $lines, self::recent_posts_section() );

		$markdown = implode( "\n", $lines );
		set_transient( $cache_key, $markdown, HOUR_IN_SECONDS );

		return $markdown;
	}

	/**
	 * "Recent Posts" section shared by the homepage and posts-page listings.
	 *
	 * Honours the per-post "exclude from AI surfaces" setting like every other
	 * Markdown surface, and flattens titles to one line so a crafted title
	 * cannot forge Markdown structure (a newline plus "## ") in the list.
	 * Over-fetches so excluded posts do not shrink the list below 10.
	 *
	 * @return string[] Lines, empty when there is nothing to list.
	 */
	private static function recent_posts_section(): array {
		$posts = get_posts( array(
			'numberposts'      => 30,
			'post_status'      => 'publish',
			'has_password'     => false,
			'suppress_filters' => false,
		) );

		$items = array();
		foreach ( $posts as $post ) {
			if ( class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
				continue;
			}
			$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
			$title = trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
			$title = str_replace( array( '[', ']' ), array( '\\[', '\\]' ), $title );
			$items[] = '- [' . $title . '](' . get_permalink( $post ) . ')';
			if ( count( $items ) >= 10 ) {
				break;
			}
		}

		if ( empty( $items ) ) {
			return array();
		}

		return array_merge(
			array( '## ' . __( 'Recent Posts', 'rankready-ai-llm-seo' ), '' ),
			$items,
			array( '' )
		);
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

		$lines = array_merge( $lines, self::recent_posts_section() );

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
		// Always the HTML URL, on both routes. `Link: rel=canonical` on a
		// markdown representation exists to point an agent at the canonical
		// resource, and `/blog.md` naming ITSELF as canonical tells it nothing
		// and breaks the association with the page it represents. The singular
		// route has always sent get_permalink() here regardless of which URL
		// served it; these two home surfaces were the only ones that did not.
		$canonical = get_permalink( $page );
		self::serve_markdown( $markdown, $canonical, $shared_url );
	}

	/**
	 * Serve homepage markdown at `/index.md` or on `/` via Accept / AI-bot UA.
	 *
	 * Static front page → that page's markdown (even if Pages is unchecked).
	 * Latest-posts home → site overview. Both URLs share the same body.
	 *
	 * Cache policy depends on the URL:
	 *   - $shared_url = true  → served on `/` via Accept. Force no-store so APO
	 *     cannot poison the HTML homepage (bug seen on nexterwp.com, 2026-06-02).
	 *   - $shared_url = false → distinct `/index.md`. Safe to cache publicly.
	 *
	 * Either way the declared canonical is the HTML home page.
	 */
	private static function serve_homepage_markdown( bool $shared_url = true ): void {
		$post = self::get_front_page_post();
		if ( $post instanceof WP_Post ) {
			$post   = self::translate_post( $post );
			$locale = self::resolved_locale( $post );
			RNRD_Crawler_Log::log( 'markdown', $post );

			$cache_key = self::md_cache_key( $post, $locale );
			$markdown  = get_transient( $cache_key );
			if ( false === $markdown ) {
				$markdown = self::post_to_markdown( $post );
				set_transient( $cache_key, $markdown, 5 * MINUTE_IN_SECONDS );
			}
		} else {
			RNRD_Crawler_Log::log( 'home_md' );
			$markdown = self::build_homepage_overview_markdown();
		}

		// See the note in serve_posts_page_markdown(): the canonical is the HTML
		// home page whichever URL served the markdown.
		$canonical = home_url( '/' );
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
		header( 'Link: <' . esc_url_raw( self::homepage_md_url() ) . '>; rel="alternate"; type="text/markdown"', false );

		$front = self::get_front_page_post();
		if ( ! $front instanceof WP_Post ) {
			return;
		}
		foreach ( self::get_translation_md_urls( $front ) as $code => $translated_md_url ) {
			header( 'Link: <' . esc_url_raw( $translated_md_url ) . '>; rel="alternate"; type="text/markdown"; hreflang="' . $code . '"', false );
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
		header( 'Link: <' . esc_url_raw( self::get_md_url( $page ) ) . '>; rel="alternate"; type="text/markdown"', false );
		foreach ( self::get_translation_md_urls( $page ) as $code => $translated_md_url ) {
			header( 'Link: <' . esc_url_raw( $translated_md_url ) . '>; rel="alternate"; type="text/markdown"; hreflang="' . $code . '"', false );
		}
	}

	public static function add_md_link_header(): void {
		if ( 'on' !== get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}

		// A .md response is itself the alternate; advertising the front page's
		// index.md here pointed every post's Markdown at the homepage.
		if ( '' !== (string) get_query_var( 'rnrd_md_path', '' ) ) {
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
		header( 'Link: <' . esc_url_raw( $md_url ) . '>; rel="alternate"; type="text/markdown"', false );

		// v1.1.17 — per-language alternates as separate Link headers (RFC 8288).
		foreach ( self::get_translation_md_urls( $post ) as $code => $translated_md_url ) {
			header( 'Link: <' . esc_url_raw( $translated_md_url ) . '>; rel="alternate"; type="text/markdown"; hreflang="' . $code . '"', false );
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
		header( 'Link: <' . esc_url_raw( home_url( '/llms.txt' ) ) . '>; rel="describedby"; type="text/plain"', false );
	}

	// ── Multilingual resolution (WPML / Polylang / TranslatePress / Weglot) ──
	//
	// Treats post translation as a four-step waterfall so any one plugin is
	// enough. None of these hooks fire when no translation plugin is active,
	// so the helper is a no-op on monolingual sites.

	/**
	 * Best-effort swap of $post to its translated counterpart for the active
	 * locale (or the visitor's Accept-Language when no plugin language is set).
	 *
	 * Order: explicit lang query var → WPML → Polylang → original post.
	 * Returns the original post if no translation plugin resolves anything.
	 *
	 * @param WP_Post $post Original post resolved from the URL.
	 * @return WP_Post Translated post or the original if no translation exists.
	 */
	/** True when detect_request_language() fell through to Accept-Language. */
	private static $lang_from_header = false;

	/** True when an Accept-Language header actually selected a different post. */
	private static $negotiated_by_header = false;

	/**
	 * Whether the response being served was chosen by the Accept-Language
	 * header rather than by the URL.
	 *
	 * A distinct `.md` URL is cached publicly, and serve_markdown() deliberately
	 * declares no variance it cannot demonstrate. Accept-Language is the one
	 * header that can change the body: on a WPML or Polylang site where neither
	 * plugin resolves a current language from the URL, detect_request_language()
	 * falls through to the header and translate_post() can hand back a different
	 * translation. Without this, the first visitor's language would be stored
	 * under the shared URL and served to everyone after them for the whole TTL.
	 * It stays false on monolingual sites, where no translation plugin is hooked
	 * and the header changes nothing, so their cache is not split for nothing.
	 */
	public static function language_negotiated(): bool {
		return self::$negotiated_by_header;
	}

	public static function translate_post( WP_Post $post ): WP_Post {
		self::$negotiated_by_header = false;

		$lang = self::detect_request_language();

		// WPML — `wpml_object_id` returns translated post ID for the given lang.
		if ( has_filter( 'wpml_object_id' ) ) {
			try {
				$translated_id = apply_filters( 'wpml_object_id', $post->ID, $post->post_type, false, $lang ?: null );
			} catch ( \Throwable $e ) {
				$translated_id = 0;
			}
			if ( $translated_id && (int) $translated_id !== $post->ID ) {
				$translated = get_post( (int) $translated_id );
				if ( $translated instanceof WP_Post && self::post_has_servable_md_url( $translated ) ) {
					self::$negotiated_by_header = self::$lang_from_header;
					return $translated;
				}
			}
		}

		// Polylang — `pll_get_post` returns translated post ID for given slug.
		if ( $lang && function_exists( 'pll_get_post' ) ) {
			try {
				$translated_id = pll_get_post( $post->ID, $lang );
			} catch ( \Throwable $e ) {
				$translated_id = 0;
			}
			if ( $translated_id && (int) $translated_id !== $post->ID ) {
				$translated = get_post( (int) $translated_id );
				if ( $translated instanceof WP_Post && self::post_has_servable_md_url( $translated ) ) {
					self::$negotiated_by_header = self::$lang_from_header;
					return $translated;
				}
			}
		}

		return $post;
	}

	/**
	 * Resolve the locale string used by translate_post(). Returned value is
	 * embedded in the markdown transient cache key so per-language responses
	 * never collide.
	 */
	public static function resolved_locale( WP_Post $post ): string {
		// WPML stores post language as a taxonomy term.
		if ( function_exists( 'apply_filters' ) && has_filter( 'wpml_post_language_details' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post->ID );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				return sanitize_key( (string) $details['language_code'] );
			}
		}

		// Polylang per-post language.
		if ( function_exists( 'pll_get_post_language' ) ) {
			$pll = pll_get_post_language( $post->ID );
			if ( ! empty( $pll ) ) {
				return sanitize_key( (string) $pll );
			}
		}

		// WP locale as last-resort fingerprint (monolingual sites still get
		// a stable suffix; the transient name stays bounded).
		return sanitize_key( (string) get_locale() );
	}

	/**
	 * Pick the visitor's request language. Priority:
	 *   1. ?lang= query var
	 *   2. WPML current language filter
	 *   3. Polylang current language function
	 *   4. Accept-Language header (parsed for highest q-value)
	 */
	private static function detect_request_language(): string {
		self::$lang_from_header = false;

		// 1. Explicit query var.
		if ( ! empty( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( wp_unslash( (string) $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// 2. WPML active language.
		if ( has_filter( 'wpml_current_language' ) ) {
			$wpml_lang = apply_filters( 'wpml_current_language', null );
			if ( ! empty( $wpml_lang ) ) {
				return sanitize_key( (string) $wpml_lang );
			}
		}

		// 3. Polylang active language.
		if ( function_exists( 'pll_current_language' ) ) {
			$pll_lang = pll_current_language();
			if ( ! empty( $pll_lang ) ) {
				return sanitize_key( (string) $pll_lang );
			}
		}

		// 4. Accept-Language header (best q-value). Everything above this point
		// is carried in the URL, so it is already part of any cache key; this
		// branch is the only one that makes the response depend on a request
		// header, which is why it is recorded (see $lang_from_header).
		if ( ! empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			self::$lang_from_header = true;
			$header = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );
			$best   = '';
			$best_q = 0.0;
			foreach ( explode( ',', $header ) as $segment ) {
				$segment = trim( $segment );
				if ( '' === $segment ) {
					continue;
				}
				$parts = explode( ';', $segment );
				$tag   = strtolower( trim( $parts[0] ) );
				if ( '' === $tag || '*' === $tag ) {
					continue;
				}
				$q = 1.0;
				foreach ( array_slice( $parts, 1 ) as $param ) {
					$param = trim( $param );
					if ( 0 === strncasecmp( $param, 'q=', 2 ) ) {
						$q = (float) substr( $param, 2 );
						break;
					}
				}
				if ( $q > $best_q ) {
					$best_q = $q;
					// Trim region: en-US → en (matches WPML/Polylang language codes
					// which are 2-letter by default).
					$best = strtok( $tag, '-' );
				}
			}
			if ( '' !== $best ) {
				return sanitize_key( $best );
			}
		}

		return '';
	}

	/**
	 * Return [ language_code => translated_md_url ] pairs for hreflang
	 * emission. Empty array when no translation plugin is active.
	 *
	 * @param WP_Post $post Source post.
	 * @return array<string,string>
	 */
	public static function get_translation_md_urls( WP_Post $post ): array {
		$out = array();

		// WPML — `wpml_active_languages` returns all enabled languages with URLs.
		if ( has_filter( 'wpml_active_languages' ) ) {
			$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 1 ) );
			if ( is_array( $langs ) ) {
				foreach ( $langs as $code => $info ) {
					$translated_id = apply_filters( 'wpml_object_id', $post->ID, $post->post_type, false, $code );
					if ( $translated_id ) {
						$translated = get_post( (int) $translated_id );
						if ( $translated instanceof WP_Post && self::post_has_servable_md_url( $translated ) ) {
							$out[ sanitize_key( (string) $code ) ] = self::get_md_url( $translated );
						}
					}
				}
			}
		}

		// Polylang — `pll_the_languages` raw map.
		if ( empty( $out ) && function_exists( 'pll_languages_list' ) && function_exists( 'pll_get_post' ) ) {
			$langs = pll_languages_list();
			if ( is_array( $langs ) ) {
				foreach ( $langs as $code ) {
					$translated_id = pll_get_post( $post->ID, $code );
					if ( $translated_id ) {
						$translated = get_post( (int) $translated_id );
						if ( $translated instanceof WP_Post && self::post_has_servable_md_url( $translated ) ) {
							$out[ sanitize_key( (string) $code ) ] = self::get_md_url( $translated );
						}
					}
				}
			}
		}

		return $out;
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
		//
		// Accept-Language is added only when it actually chose this body. See
		// language_negotiated(): on a monolingual site the header changes
		// nothing and declaring it would split the cache per browser locale for
		// no benefit, while on a translated site that negotiated by header,
		// leaving it out is what lets one visitor's language be stored under the
		// shared URL and served to everyone else for the rest of the TTL.
		$rnrd_vary = array( 'Accept-Encoding' );
		if ( $shared_url ) {
			$rnrd_vary[] = 'Accept';
		}
		if ( self::language_negotiated() ) {
			$rnrd_vary[] = 'Accept-Language';
		}
		header( 'Vary: ' . implode( ', ', $rnrd_vary ) );

		// Robots / discovery
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'Link: <' . esc_url_raw( $canonical_url ) . '>; rel="canonical"', false );

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
		// Content Signals (contentsignals.org) also travel as a response header, so an
		// agent that only fetched this .md sees the same ai-train/search/ai-input
		// policy robots.txt declares. Absent when the feature is off.
		if ( class_exists( 'RNRD_Content_Signals' ) ) {
			$rnrd_signal = (string) RNRD_Content_Signals::get_directive();
			if ( '' !== $rnrd_signal ) {
				header( $rnrd_signal );
			}
		}
		header( 'X-Markdown-Tokens: ' . max( 1, (int) ceil( mb_strlen( $markdown, 'UTF-8' ) / 4 ) ) );

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
			$excerpt = '';
			if ( ! empty( $post->post_excerpt ) ) {
				$excerpt = RNRD_Util::strip_shortcode_markup( (string) $post->post_excerpt );
			}
			if ( '' === trim( wp_strip_all_tags( $excerpt ) ) ) {
				$excerpt = wp_trim_words( RNRD_Util::strip_tags_spaced( RNRD_Util::strip_shortcode_markup( (string) $post->post_content ) ), 30, '...' );
			}
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

			/**
			 * Extra YAML frontmatter lines for a post, added before the closing
			 * fence. Pro uses this for WooCommerce product facts. Each entry is
			 * one complete `key: value` line, already escaped by the caller.
			 *
			 * @param string[] $extra Extra lines, empty by default.
			 * @param WP_Post  $post  The post being rendered.
			 */
			$extra = apply_filters( 'rankready_md_frontmatter', array(), $post );
			foreach ( is_array( $extra ) ? $extra : array() as $extra_line ) {
				if ( is_string( $extra_line ) && '' !== $extra_line ) {
					$lines[] = $extra_line;
				}
			}

			$lines[] = '---';
			$lines[] = '';
		}

		// ── Title ────────────────────────────────────────────────────────
		$lines[] = '# ' . self::clean_text( get_the_title( $post ) );
		$lines[] = '';

		/**
		 * Extra Markdown lines right under the title, for sites that switched the
		 * frontmatter off and would otherwise lose them. Empty by default.
		 *
		 * @param string[] $extra Extra lines.
		 * @param WP_Post  $post  The post being rendered.
		 * @param bool     $has_frontmatter Whether the frontmatter block was written.
		 */
		$intro = apply_filters( 'rankready_md_intro', array(), $post, $include_meta );
		foreach ( is_array( $intro ) ? $intro : array() as $intro_line ) {
			if ( is_string( $intro_line ) ) {
				$lines[] = $intro_line;
			}
		}

		// ── AI Summary (if available) ────────────────────────────────────
		// can_emit() carries the per-post opt-out. Without it, unticking
		// "disable" removed the summary from the page and left it in the
		// Markdown that AI crawlers actually read.
		if ( class_exists( 'RNRD_Summary' ) && RNRD_Summary::is_enabled() && RNRD_Summary::is_post_type_enabled( $post->post_type )
			&& RNRD_Util::can_emit( (int) $post->ID, RNRD_META_DISABLE ) ) {
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
		$content = RNRD_Llms_Txt::post_to_clean_markdown( $post );

		if ( ! empty( $content ) ) {
			$lines[] = $content;
		}

		// ── FAQ section (if available) ───────────────────────────────
		// Same per-post opt-out the page and the MCP tools honour: a post with the FAQ
		// switched off must not keep publishing it in the Markdown crawlers read.
		if ( class_exists( 'RNRD_Faq' ) && RNRD_Util::can_emit( (int) $post->ID, RNRD_META_FAQ_DISABLE ) ) {
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

		// issue #3 — plain permalinks (Settings → Permalinks → "Plain") make
		// get_permalink() return a query-string URL like "?p=123". Appending
		// ".md" to that produces "?p=123.md", which the .md rewrite rule can
		// never match (it routes on the URL PATH, not the query string) — a
		// link that 404s the moment anyone follows it. post_has_servable_md_url()
		// already keeps callers that check first (e.g. llms.txt's index) from
		// reaching this method at all on a plain-permalink site, but several
		// other callers (the <link rel="alternate">/header tags, the AI hint
		// div) call get_md_url() directly, so the guard belongs here too,
		// defensively, not only upstream.
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			return (string) $permalink;
		}

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
		// Backslash first: an unescaped trailing one would swallow the closing quote.
		$text = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $text );
		return $text;
	}
}
