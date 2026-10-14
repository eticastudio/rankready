<?php
/**
 * RankReady — WebMCP via WordPress Abilities API.
 *
 * Registers RankReady's read-only content surfaces as typed WordPress
 * Abilities (WP 6.9+ Abilities API). Any AI agent that speaks Model Context
 * Protocol (Claude Desktop, Cursor, VS Code, custom OpenAI-Functions agents)
 * can then discover and call these abilities — turning the WordPress site
 * into a first-class agentic tool, not just an HTML source to scrape.
 *
 * Registered abilities (namespace `rankready-ai-llm-seo/`, i.e. self::NS):
 *   - get-site-info       → name, description, URL, brand terms, languages
 *   - get-brand-terms     → canonical brand names array
 *   - search-posts        → keyword search across published content
 *   - get-post-summary    → AI summary bullets for a post
 *   - get-post-faq        → FAQ Q&A array for a post
 *   - list-recent-posts   → paginated list with titles, URLs, modified dates
 *
 * Discovery:
 *   GET /.well-known/mcp.json    → MCP-style manifest listing abilities
 *   GET /wp-json/wp/v2/abilities → core WP route (when Abilities API plugin is active)
 *
 * Security:
 *   - Read-only; no write abilities exposed.
 *   - All abilities filter to published, public-status posts.
 *   - Permission callback returns true for all reads (matches public REST
 *     headless endpoints) — host site can add auth via standard WP filters.
 *
 * Graceful degradation:
 *   - If the Abilities API plugin is not active, this class no-ops silently.
 *     RankReady stays fully functional; only the MCP layer is dormant.
 *
 * @package RankReady
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

class RNRD_MCP {

	private const NS = 'rankready-ai-llm-seo';

	/**
	 * Ability category slug.
	 *
	 * WordPress 6.9 made `category` a required property on every registered
	 * ability, and validates that the category itself was registered first.
	 * Without it core rejects the registration outright with a
	 * _doing_it_wrong(), which is silent on a production site.
	 */
	private const CAT = 'rankready';

	public static function init(): void {
		// RankReady registers its read-only abilities with the WordPress Abilities API
		// (core in WP 7.0) so the official MCP Adapter can expose them over MCP.
		// The core hook is `wp_abilities_api_init` (wp-includes/abilities-api.php).
		// We were listening on `abilities_api_init`, which core never fires, so
		// none of the abilities below ever reached the registry and the official
		// MCP Adapter saw an empty plugin. The unprefixed name is kept as a second
		// binding only because an early Abilities API draft used it; both are
		// idempotent via the guard in register_abilities().
		// Categories must be registered on their own, earlier action, and before
		// any ability referencing them. Core fires this from the categories
		// registry constructor, which the abilities registry primes first.
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_ability_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
		add_action( 'abilities_api_init',    array( self::class, 'register_abilities' ) );

		// It ALSO serves its own /.well-known/mcp.json manifest directly, so the endpoint
		// works standalone (no MCP Adapter required) — this is what the readme advertises,
		// Diagnostics probes, and onboarding lists. (Restored in v1.2.0: the manifest
		// serving was dropped during the Abilities-API migration while everything that
		// depends on it stayed.)
		add_action( 'init', array( self::class, 'add_rewrite_rules' ), 9 );
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'handle_request' ), rnrd_template_redirect_priority( 1 ) );
	}

	/** Rewrite for /.well-known/mcp.json (only when WebMCP is enabled). */
	public static function add_rewrite_rules(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		add_rewrite_rule( '^\.well-known/mcp\.json$', 'index.php?rnrd_mcp=1', 'top' );
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = 'rnrd_mcp';
		return $vars;
	}

	/** Serve the manifest JSON at /.well-known/mcp.json. */
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
		if ( '' === (string) get_query_var( 'rnrd_mcp', '' )
			&& '.well-known/mcp.json' !== self::request_path() ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, follow' );
		// AI agents (Claude Desktop, Cursor, VS Code, ChatGPT) fetch this cross-origin.
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, HEAD, OPTIONS' );
		header( 'Access-Control-Expose-Headers: Content-Type' );

		if ( ! self::is_enabled() ) {
			// 404, not 503. 503 means "temporarily unavailable, retry later",
			// so agents and readiness scanners back off and retry a URI that is
			// never coming back, and some grade the site as unhealthy for it.
			// A feature the site owner switched off simply has no resource here.
			status_header( 404 );
			echo wp_json_encode( array( 'error' => 'WebMCP is disabled' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		status_header( 200 );
		echo wp_json_encode( self::build_manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Build the MCP-style manifest from the live ability gates + exposure state.
	 * Only abilities whose required resources are all enabled are listed.
	 */
	public static function build_manifest(): array {
		$labels = array(
			'get-site-info'      => 'Site name, description, URL and language.',
			'list-content-types' => 'Public post types available on the site.',
			'get-brand-terms'    => 'Canonical brand names and entities.',
			'get-post-summary'   => 'AI summary for a post.',
			'get-post-faq'       => 'FAQ (question/answer pairs) for a post.',
			'search-posts'       => 'Search published posts by keyword.',
			'list-recent-posts'  => 'Most recently published posts.',
			'get-post'           => 'Full content of a post by ID.',
			'get-post-by-url'    => 'Resolve a URL to its post content.',
			'list-pages'         => 'Published pages.',
			'list-categories'    => 'Categories.',
			'list-tags'          => 'Tags.',
			'get-author'         => 'Author E-E-A-T profile.',
			'get-llms-txt'       => 'The site llms.txt index.',
			'get-sitemap'        => 'The site sitemap entries.',
			'get-fresh-content'  => 'Recently updated content.',
		);

		$abilities = array();
		foreach ( self::ability_gates() as $name => $needed ) {
			if ( ! self::is_ability_enabled( $name ) ) {
				continue;
			}
			$abilities[] = array(
				// self::NS, not a second hardcoded prefix. The manifest advertised
				// `rankready/<x>` while register_abilities() registers
				// `rankready-ai-llm-seo/<x>`, so a client that read the manifest and
				// then called through the MCP Adapter asked for a name that is not
				// in the registry.
				'name'        => self::NS . '/' . $name,
				'description' => isset( $labels[ $name ] ) ? $labels[ $name ] : $name,
			);
		}

		$resources = array();
		foreach ( self::exposure_state() as $key => $on ) {
			if ( ( is_array( $on ) && ! empty( $on ) ) || ( ! is_array( $on ) && $on ) ) {
				$resources[] = $key;
			}
		}

		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => self::manifest_description(),
			'url'         => home_url( '/' ),
			'generator'   => 'RankReady',
			'abilities'   => $abilities,
			'resources'   => $resources,
		);
	}

	/**
	 * Master toggle check. Defaults to off — WebMCP is opt-in, matching
	 * the onboarding wizard. Sites that already saved 'on' stay on.
	 */
	public static function is_enabled(): bool {
		return 'on' === get_option( RNRD_OPT_MCP_ENABLE, 'off' );
	}

	// ── Abilities API registration ────────────────────────────────────────

	/**
	 * Permission callback shared by every RankReady MCP ability.
	 *
	 * Read-only public — same trust level as `/.well-known/mcp.json` and
	 * the headless REST surface — BUT gated by the master toggle so turning
	 * MCP off in admin actually shuts off the abilities (audit beta.3 #3).
	 *
	 * @return true|WP_Error true when allowed, WP_Error 404 otherwise.
	 */
	public static function ability_permission() {
		if ( ! self::is_enabled() ) {
			return new WP_Error(
				'rnrd_mcp_disabled',
				__( 'WebMCP is disabled on this site.', 'rankready-ai-llm-seo' ),
				array( 'status' => 404 )
			);
		}
		return true;
	}

	/**
	 * v1.2.0-beta.6 — Per-resource toggle check for safe public resources.
	 * Missing option rows default ON, matching register_setting() and the
	 * settings UI. Saved 'off' stays off. Do not use this helper for PII /
	 * unwired resources (those default off and are not in exposure_state).
	 *
	 * @param string $resource_option One of the RNRD_OPT_MCP_EXPOSE_* constants.
	 */
	public static function resource_enabled( string $resource_option ): bool {
		return 'on' === (string) get_option( $resource_option, 'on' );
	}

	/**
	 * Returns the full per-resource exposure state for the manifest +
	 * admin UI. Used by both the JSON manifest and the WebMCP settings
	 * card so the two always agree on what's exposed.
	 */
	public static function exposure_state(): array {
		return array(
			'posts'      => self::resource_enabled( RNRD_OPT_MCP_EXPOSE_POSTS ),
			'pages'      => self::resource_enabled( RNRD_OPT_MCP_EXPOSE_PAGES ),
			'authors'    => self::resource_enabled( RNRD_OPT_MCP_EXPOSE_AUTHORS ),
			'taxonomies' => self::resource_enabled( RNRD_OPT_MCP_EXPOSE_TAXONOMIES ),
			'sitemap'    => self::resource_enabled( RNRD_OPT_MCP_EXPOSE_SITEMAP ),
			// TC-MCP-07: only resources with a wired, gated ability (see ability_gates())
			// are advertised. 'menus' + comments/media/users/plugins/themes/settings had no
			// ability, so advertising them was misleading and a latent footgun. Re-add each
			// here only when a real gated ability ships for it.
			'llms_txt'   => self::resource_enabled( RNRD_OPT_MCP_EXPOSE_LLMS_TXT ),
			'rnrd_ai'    => self::resource_enabled( RNRD_OPT_MCP_EXPOSE_RR_AI ),
			'freshness'  => self::resource_enabled( RNRD_OPT_MCP_EXPOSE_FRESHNESS ),
			// CPT exposure is a Pro feature — never expose custom post types on a
			// Free install even if the option somehow holds slugs (matches the UI,
			// where the per-CPT toggles only render when rnrd_is_pro() is true).
			'cpts'       => ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() )
				? (array) get_option( RNRD_OPT_MCP_EXPOSE_CPTS, array() )
				: array(),
		);
	}

	/**
	 * Auto-detect every public CPT (not posts/pages) so the UI can list
	 * them as opt-in toggles. Returns slug => label.
	 */
	public static function detected_cpts(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $obj ) {
			if ( in_array( $slug, array( 'post', 'page', 'attachment' ), true ) ) {
				continue;
			}
			// Same content test as the post type pickers (#53), so internal
			// builder types such as Elementor templates are not offered.
			if ( class_exists( 'RNRD_Admin' ) && ! RNRD_Admin::is_content_post_type( $obj ) ) {
				continue;
			}
			$out[ $slug ] = $obj->labels->name;
		}
		return $out;
	}

	/**
	 * Per-ability gating map. Returns the list of exposure-state keys that
	 * MUST all be true for the ability to be available. Empty array means
	 * the ability is always available (e.g. site-info, content-types).
	 *
	 * @return array<string,string[]>
	 */
	public static function ability_gates(): array {
		return array(
			// Always-on essentials (site identity + content type catalog).
			'get-site-info'      => array(),
			'list-content-types' => array(),

			// RankReady AI value-add data.
			'get-brand-terms'    => array( 'rnrd_ai' ),
			'get-post-summary'   => array( 'rnrd_ai', 'posts' ),
			'get-post-faq'       => array( 'rnrd_ai', 'posts' ),

			// Posts resource.
			'search-posts'       => array( 'posts' ),
			'list-recent-posts'  => array( 'posts' ),
			'get-post'           => array( 'posts' ),
			'get-post-by-url'    => array( 'posts' ),

			// Pages resource.
			'list-pages'         => array( 'pages' ),

			// Taxonomies.
			'list-categories'    => array( 'taxonomies' ),
			'list-tags'          => array( 'taxonomies' ),

			// Authors / EEAT.
			'get-author'         => array( 'authors' ),

			// AI-native primitives.
			'get-llms-txt'       => array( 'llms_txt' ),
			'get-sitemap'        => array( 'sitemap' ),
			'get-fresh-content'  => array( 'freshness' ),
		);
	}

	/**
	 * Returns true when all gates for the named ability are open.
	 *
	 * @param string $name Bare ability name without namespace (e.g. 'get-post').
	 */
	public static function is_ability_enabled( string $name ): bool {
		$gates  = self::ability_gates();
		$needed = isset( $gates[ $name ] ) ? $gates[ $name ] : array();
		if ( empty( $needed ) ) {
			return true;
		}
		$state = self::exposure_state();
		foreach ( $needed as $key ) {
			if ( empty( $state[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Execute-time guard. Returns a WP_Error array shape when the ability
	 * is disabled at the resource level; null when it's allowed to proceed.
	 *
	 * @param string $name Bare ability name.
	 * @return array|null
	 */
	private static function guard( string $name ): ?array {
		if ( self::is_ability_enabled( $name ) ) {
			return null;
		}
		return array(
			'error'   => 'resource_disabled',
			'message' => 'This ability is not exposed on this site. Enable the matching resource in RankReady → AI Visibility → WebMCP to use it.',
		);
	}

	/**
	 * Register RankReady's ability category.
	 *
	 * Guarded on function_exists so an older Abilities API build, or a site
	 * where the API is absent entirely, is a clean no-op rather than a fatal.
	 */
	public static function register_ability_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CAT ) ) {
			return;
		}
		wp_register_ability_category( self::CAT, array(
			'label'       => __( 'RankReady', 'rankready-ai-llm-seo' ),
			'description' => __( 'Read-only abilities that let AI agents discover this site\'s published content, brand identity and AI SEO endpoints.', 'rankready-ai-llm-seo' ),
		) );
	}

	/**
	 * Register one ability, attaching the category only when it is safe to.
	 *
	 * Core rejects an ability whose `category` names an unregistered category,
	 * so passing the key blind would turn a missing category into sixteen
	 * silent failures. One place decides, and every call site inherits it.
	 *
	 * @param string               $name Fully namespaced ability name.
	 * @param array<string, mixed> $args Ability properties.
	 */
	private static function add_ability( string $name, array $args ): void {
		if ( ! function_exists( 'wp_has_ability_category' ) || wp_has_ability_category( self::CAT ) ) {
			$args['category'] = self::CAT;
		}
		wp_register_ability( $name, $args );
	}

	public static function register_abilities(): void {
		// Bound to two hooks (see init()); wp_register_ability() emits a
		// _doing_it_wrong notice on a duplicate name, so run the body once.
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		if ( ! self::is_enabled() ) {
			return; // Master toggle off — skip Abilities registration entirely.
		}
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // Abilities API plugin not active — graceful no-op.
		}

		/*
		 * Every ability is registered whenever the master toggle is on. The
		 * per-resource exposure state is applied where it belongs instead: the
		 * manifest filters on it (build_manifest) and guard() refuses at
		 * execute time. A beta-era line here fetched exposure_state() into a
		 * variable no registration block ever read, with a comment promising a
		 * gating step that was never written; both are gone.
		 */

		self::add_ability( self::NS . '/get-site-info', array(
			'label'               => __( 'Get site info', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Returns site identity: name, description, URL, brand terms, language.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'name'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'url'         => array( 'type' => 'string', 'format' => 'uri' ),
					'language'    => array( 'type' => 'string' ),
					'brand_terms' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
			),
			'execute_callback'    => array( self::class, 'ability_get_site_info' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		self::add_ability( self::NS . '/get-brand-terms', array(
			'label'               => __( 'Get brand terms', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Returns canonical brand names for entity consistency.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array( 'brand_terms' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ),
			),
			'execute_callback'    => array( self::class, 'ability_get_brand_terms' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		self::add_ability( self::NS . '/search-posts', array(
			'label'               => __( 'Search posts', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Keyword search across published posts. Returns title, URL, excerpt, modified date.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'query' => array( 'type' => 'string', 'minLength' => 1, 'description' => 'Search keyword(s).' ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10 ),
				),
				'required'             => array( 'query' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'results' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'       => array( 'type' => 'integer' ),
								'title'    => array( 'type' => 'string' ),
								'url'      => array( 'type' => 'string', 'format' => 'uri' ),
								'excerpt'  => array( 'type' => 'string' ),
								'modified' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( self::class, 'ability_search_posts' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		self::add_ability( self::NS . '/get-post-summary', array(
			'label'               => __( 'Get post AI summary', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Returns RankReady-generated AI summary (key takeaways) for a post.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'required'   => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'title'    => array( 'type' => 'string' ),
					'url'      => array( 'type' => 'string', 'format' => 'uri' ),
					'bullets'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
			),
			'execute_callback'    => array( self::class, 'ability_get_post_summary' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		self::add_ability( self::NS . '/get-post-faq', array(
			'label'               => __( 'Get post FAQ', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Returns RankReady-generated FAQ question/answer pairs for a post.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'required'   => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'title' => array( 'type' => 'string' ),
					'url'   => array( 'type' => 'string', 'format' => 'uri' ),
					'faq'   => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'question' => array( 'type' => 'string' ),
								'answer'   => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( self::class, 'ability_get_post_faq' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		self::add_ability( self::NS . '/list-recent-posts', array(
			'label'               => __( 'List recent posts', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Paginated list of recently modified posts. Keep calling with offset = next_offset while has_more is true. A page can hold fewer posts than limit, or none, when posts in that window are excluded from AI surfaces.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10 ),
					'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'posts' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'       => array( 'type' => 'integer' ),
								'title'    => array( 'type' => 'string' ),
								'url'      => array( 'type' => 'string', 'format' => 'uri' ),
								'modified' => array( 'type' => 'string' ),
							),
						),
					),
					'has_more'    => array( 'type' => 'boolean' ),
					'next_offset' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => array( self::class, 'ability_list_recent_posts' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// ── v1.2.0-beta.5 — Expanded surface (10 new abilities) ─────────

		// 7. get-post — the "view document" primitive. Returns full markdown +
		//    metadata + summary + FAQ + schema in one call.
		self::add_ability( self::NS . '/get-post', array(
			'label'               => __( 'Get post (full content)', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Returns full post content as clean Markdown plus title, URL, modified date, author, AI summary bullets, FAQ Q&A, and JSON-LD schema. The agent\'s primary content retrieval primitive.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				),
				'required'   => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'        => array( 'type' => 'integer' ),
					'title'     => array( 'type' => 'string' ),
					'url'       => array( 'type' => 'string', 'format' => 'uri' ),
					'md_url'    => array( 'type' => 'string', 'format' => 'uri' ),
					'post_type' => array( 'type' => 'string' ),
					'modified'  => array( 'type' => 'string' ),
					'author'    => array( 'type' => 'string' ),
					'excerpt'   => array( 'type' => 'string' ),
					'markdown'  => array( 'type' => 'string' ),
					'summary'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'faq'       => array( 'type' => 'array' ),
					'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
			),
			'execute_callback'    => array( self::class, 'ability_get_post' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 8. get-post-by-url — resolve a URL (including /post-slug.md or
		//    /category/x/) → post payload. Lets an agent follow internal links.
		self::add_ability( self::NS . '/get-post-by-url', array(
			'label'               => __( 'Get post by URL', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Resolve any site URL (incl. .md, category, tag URLs) to a post. Returns the same shape as get-post. Returns 404-like empty payload if the URL does not map to content.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'url' => array( 'type' => 'string', 'format' => 'uri' ),
				),
				'required'   => array( 'url' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),  // same shape as get-post
			'execute_callback'    => array( self::class, 'ability_get_post_by_url' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 9. list-pages — static pages separately from posts.
		self::add_ability( self::NS . '/list-pages', array(
			'label'               => __( 'List pages', 'rankready-ai-llm-seo' ),
			'description'         => __( 'List static pages (About, Pricing, Docs, etc.). Hierarchical: returns parent_id so an agent can rebuild the page tree. Keep calling with offset = next_offset while has_more is true; a page can hold fewer pages than limit, or none, when some are excluded.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
					'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_list_pages' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 10. list-content-types — what post types this site exposes.
		self::add_ability( self::NS . '/list-content-types', array(
			'label'               => __( 'List content types', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Returns every public post type the site exposes (Posts, Pages, Products, Docs, etc.) so the agent can target queries to the right type.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_list_content_types' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 11. list-categories — taxonomy discovery.
		self::add_ability( self::NS . '/list-categories', array(
			'label'               => __( 'List categories', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Browse the site\'s topical hierarchy. Returns category name, slug, parent, post count, and archive URL.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_list_categories' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 12. list-tags — same shape, different taxonomy.
		self::add_ability( self::NS . '/list-tags', array(
			'label'               => __( 'List tags', 'rankready-ai-llm-seo' ),
			'description'         => __( 'List tags ordered by post count. Returns name, slug, post count, archive URL.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_list_tags' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 13. get-llms-txt — return the rendered llms.txt inline.
		self::add_ability( self::NS . '/get-llms-txt', array(
			'label'               => __( 'Get llms.txt', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Returns the rendered /llms.txt content inline so the agent does not have to make a separate HTTP fetch. Same content as the file endpoint.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'full' => array( 'type' => 'boolean', 'default' => false, 'description' => 'When true, returns llms-full.txt content (every page inlined).' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_get_llms_txt' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 14. get-author — EEAT Person schema fields for an author.
		self::add_ability( self::NS . '/get-author', array(
			'label'               => __( 'Get author (EEAT Person schema)', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Returns the Schema.org Person data RankReady publishes for a WordPress author: name, bio, job title, employer and headshot. RankReady Pro adds credentials, education, awards and social profiles.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'author_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				),
				'required'   => array( 'author_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_get_author' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 15. get-sitemap — parsed sitemap index for cold crawls.
		self::add_ability( self::NS . '/get-sitemap', array(
			'label'               => __( 'Get sitemap', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Returns a parsed sitemap (URL + lastmod) of every published post + page. Agent\'s first call when discovering a site cold.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'default' => 500 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_get_sitemap' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 16. get-fresh-content — posts modified in last N days.
		self::add_ability( self::NS . '/get-fresh-content', array(
			'label'               => __( 'Get fresh content', 'rankready-ai-llm-seo' ),
			'description'         => __( 'Posts and pages modified within the last N days. This ability surfaces what changed most recently, so an agent can read it first when context is limited.', 'rankready-ai-llm-seo' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30 ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_get_fresh_content' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );
	}

	// ── Ability implementations ───────────────────────────────────────────

	public static function ability_get_site_info(): array {
		// v1.2.0-beta.4 — return the unified Brand Identity so agents see
		// the same canonical name + summary + about + terms that humans see.
		$brand = class_exists( 'RNRD_Brand_Identity' )
			? RNRD_Brand_Identity::get_brand_identity()
			: array(
				'name'    => (string) get_bloginfo( 'name' ),
				'summary' => (string) get_bloginfo( 'description' ),
				'about'   => '',
				'terms'   => array(),
			);

		return array(
			'name'        => $brand['name'],
			'description' => $brand['summary'],
			'about'       => $brand['about'],
			'url'         => home_url( '/' ),
			'language'    => (string) get_bloginfo( 'language' ),
			'brand_terms' => $brand['terms'],
		);
	}

	public static function ability_get_brand_terms(): array {
		if ( $g = self::guard( 'get-brand-terms' ) ) { return $g; }
		return array(
			'brand_terms' => class_exists( 'RNRD_Brand_Identity' ) ? RNRD_Brand_Identity::get_brand_terms_list() : array(),
		);
	}

	/**
	 * Whether a post may appear on WebMCP surfaces.
	 *
	 * Published, of a post type a visitor could actually open, not
	 * password-protected, and not excluded via RankReady's per-post opt-out or
	 * SEO-plugin noindex.
	 *
	 * The post-type test is the one that carries security weight. The list and
	 * search abilities constrain post_type in the query, but get-post takes a
	 * raw post ID and get-post-by-url resolves one, so without this check any
	 * published row of ANY post type was returned in full. Plenty of post types
	 * are publish + non-public by design: wp_navigation, acf-field-group, form
	 * entries, popup and email-template CPTs. is_post_type_viewable() is the
	 * same boundary core uses for "can a logged-out visitor see this".
	 */
	private static function is_post_exposable( $post ): bool {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return false;
		}
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return false;
		}

		// CPT exposure is a Pro feature — is_post_type_viewable() only proves a
		// post type is publicly viewable, it says nothing about our own Free/Pro
		// policy. Without this check, any public custom post type (WooCommerce
		// products, a public "case-study" CPT, etc.) was reachable via get-post /
		// get-post-by-url on a Free install even though the CPT toggle UI is
		// Pro-only. Free stays to the two built-in types this plugin supports;
		// Pro may go wider but never below the public=>true data-safety floor.
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || empty( $post_type_obj->public ) ) {
			return false;
		}
		$is_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();
		if ( ! $is_pro && ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return false;
		}

		// Honour the per-resource toggles for every ability, not only the one
		// named after the resource: get-post, search-posts and list-recent-posts
		// are gated on 'posts' alone, so without this a page or an unticked CPT
		// was still returned after the admin switched that resource off.
		$state = self::exposure_state();
		if ( 'page' === $post->post_type && empty( $state['pages'] ) ) {
			return false;
		}
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true )
			&& ! in_array( $post->post_type, (array) $state['cpts'], true ) ) {
			return false;
		}

		return ! ( class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) );
	}

	/**
	 * Drop excluded posts from list/search/sitemap results.
	 *
	 * @param WP_Post[] $posts
	 * @return WP_Post[]
	 */
	private static function filter_exposable_posts( array $posts ): array {
		return array_values( array_filter( $posts, array( self::class, 'is_post_exposable' ) ) );
	}

	public static function ability_search_posts( array $input ): array {
		if ( $g = self::guard( 'search-posts' ) ) { return $g; }
		$query = isset( $input['query'] ) ? (string) $input['query'] : '';
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$limit = max( 1, min( 20, $limit ) );

		$post_types = (array) get_option( RNRD_OPT_POST_TYPES, array( 'post' ) );
		if ( self::resource_enabled( RNRD_OPT_MCP_EXPOSE_PAGES ) && ! in_array( 'page', $post_types, true ) ) {
			$post_types[] = 'page';
		}

		// Over-fetch, then trim. filter_exposable_posts() drops posts excluded
		// from llms.txt after the query, so asking for exactly $limit returned
		// fewer results whenever an excluded post ranked in the top $limit.
		// Search takes no offset, so trimming cannot skip anything a later
		// call would have returned.
		$posts = get_posts( array(
			's'              => $query,
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => $limit * 3,
			'orderby'        => 'relevance',
		) );
		$posts = array_slice( self::filter_exposable_posts( $posts ), 0, $limit );

		$results = array();
		foreach ( $posts as $p ) {
			$results[] = array(
				'id'       => (int) $p->ID,
				'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'      => get_permalink( $p ),
				'excerpt'  => wp_strip_all_tags( ! empty( $p->post_excerpt ) ? $p->post_excerpt : wp_trim_words( $p->post_content, 40 ) ),
				'modified' => mysql2date( 'c', $p->post_modified_gmt ),
			);
		}

		return array( 'results' => $results );
	}

	public static function ability_get_post_summary( array $input ): array {
		if ( $g = self::guard( 'get-post-summary' ) ) { return $g; }
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! self::is_post_exposable( $post ) ) {
			return array( 'title' => '', 'url' => '', 'bullets' => array() );
		}

		$bullets = array();
		// Per-post opt-out. is_post_exposable() covers status and password;
		// it does not know about _rnrd_disable_summary.
		if ( class_exists( 'RNRD_Summary' ) && RNRD_Summary::is_enabled() && RNRD_Summary::is_post_type_enabled( $post->post_type )
			&& RNRD_Util::can_emit( (int) $post->ID, RNRD_META_DISABLE ) ) {
			$raw = (string) get_post_meta( $post->ID, RNRD_META_SUMMARY, true );
			if ( '' !== $raw && class_exists( 'RNRD_Generator' ) ) {
				$decoded = RNRD_Generator::decode_summary( $raw );
				if ( 'bullets' === $decoded['type'] ) {
					$bullets = array_values( (array) $decoded['data'] );
				}
			}
		}

		return array(
			'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'     => get_permalink( $post ),
			'bullets' => $bullets,
		);
	}

	public static function ability_get_post_faq( array $input ): array {
		if ( $g = self::guard( 'get-post-faq' ) ) { return $g; }
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! self::is_post_exposable( $post ) ) {
			return array( 'title' => '', 'url' => '', 'faq' => array() );
		}

		$faq = array();
		if ( class_exists( 'RNRD_Faq' ) && RNRD_Faq::is_enabled() && RNRD_Faq::is_post_type_enabled( $post->post_type )
			&& RNRD_Util::can_emit( (int) $post->ID, RNRD_META_FAQ_DISABLE ) ) {
			$faq = RNRD_Faq::get_faq_data( $post->ID );
		}

		return array(
			'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'   => get_permalink( $post ),
			'faq'   => array_values( $faq ),
		);
	}

	public static function ability_list_recent_posts( array $input ): array {
		if ( $g = self::guard( 'list-recent-posts' ) ) { return $g; }
		$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
		$limit  = max( 1, min( 50, $limit ) );
		$offset = max( 0, $offset );

		$post_types = (array) get_option( RNRD_OPT_POST_TYPES, array( 'post' ) );
		if ( self::resource_enabled( RNRD_OPT_MCP_EXPOSE_PAGES ) && ! in_array( 'page', $post_types, true ) ) {
			$post_types[] = 'page';
		}

		$posts = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => $limit,
			'offset'         => $offset,
			// ID breaks ties. Posts saved in the same second share a modified
			// value, and without a tiebreaker MySQL may order them differently
			// on each OFFSET query, so paging skipped some and repeated others.
			'orderby'        => array( 'modified' => 'DESC', 'ID' => 'DESC' ),
		) );
		// Deliberately not over-fetched, unlike search. offset counts raw
		// published posts, so paging with offset += limit covers every post
		// exactly once. Excluded posts are dropped from the window they fall
		// in, so a page can be short or empty without being the last one;
		// has_more says whether the raw window was full. Both fields are
		// additive: a client that ignores them pages exactly as before.
		$raw_count = count( $posts );
		$posts     = self::filter_exposable_posts( $posts );

		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'       => (int) $p->ID,
				'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'      => get_permalink( $p ),
				'modified' => mysql2date( 'c', $p->post_modified_gmt ),
			);
		}

		return array(
			'posts'       => $out,
			'has_more'    => $raw_count === $limit,
			'next_offset' => $offset + $limit,
		);
	}

	// ── v1.2.0-beta.5 — Expanded ability implementations ─────────────────

	/**
	 * Resolve a WP_Post to the canonical "post payload" the agent sees from
	 * get-post and get-post-by-url. Shared helper so both abilities return
	 * the same shape.
	 */
	private static function post_payload( WP_Post $post ): array {
		$post_id   = (int) $post->ID;
		$summary   = array();
		if ( class_exists( 'RNRD_Summary' ) && RNRD_Summary::is_enabled() && RNRD_Summary::is_post_type_enabled( $post->post_type )
			&& RNRD_Util::can_emit( $post_id, RNRD_META_DISABLE ) ) {
			$summary_raw = (string) get_post_meta( $post_id, RNRD_META_SUMMARY, true );
			if ( '' !== $summary_raw && class_exists( 'RNRD_Generator' ) ) {
				$decoded = RNRD_Generator::decode_summary( $summary_raw );
				if ( 'bullets' === $decoded['type'] ) {
					$summary = array_values( (array) $decoded['data'] );
				}
			}
		}

		$faq = array();
		if ( class_exists( 'RNRD_Faq' ) && RNRD_Faq::is_enabled() && RNRD_Faq::is_post_type_enabled( $post->post_type )
			&& RNRD_Util::can_emit( $post_id, RNRD_META_FAQ_DISABLE ) ) {
			$faq = RNRD_Faq::get_faq_data( $post_id );
		}

		$markdown = class_exists( 'RNRD_Markdown' ) ? RNRD_Markdown::post_to_markdown( $post ) : '';
		$md_url   = class_exists( 'RNRD_Markdown' ) ? RNRD_Markdown::get_md_url( $post ) : '';

		$author_name = (string) get_the_author_meta( 'display_name', $post->post_author );

		$cats = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
		$tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );

		return array(
			'id'         => $post_id,
			'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'        => get_permalink( $post ),
			'md_url'     => $md_url,
			'post_type'  => $post->post_type,
			'modified'   => mysql2date( 'c', $post->post_modified_gmt ),
			'author'     => $author_name,
			'author_id'  => (int) $post->post_author,
			'excerpt'    => wp_strip_all_tags( ! empty( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( $post->post_content, 50 ) ),
			'markdown'   => $markdown,
			'summary'    => $summary,
			'faq'        => array_values( $faq ),
			'categories' => is_array( $cats ) && ! is_wp_error( $cats ) ? array_values( $cats ) : array(),
			'tags'       => is_array( $tags ) && ! is_wp_error( $tags ) ? array_values( $tags ) : array(),
		);
	}

	public static function ability_get_post( array $input ): array {
		if ( $g = self::guard( 'get-post' ) ) { return $g; }
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! self::is_post_exposable( $post ) ) {
			return array( 'id' => 0, 'title' => '', 'markdown' => '' );
		}
		return self::post_payload( $post );
	}

	public static function ability_get_post_by_url( array $input ): array {
		if ( $g = self::guard( 'get-post-by-url' ) ) { return $g; }
		$url = isset( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '';
		if ( '' === $url ) {
			return array( 'id' => 0 );
		}

		// Strip trailing .md so the resolver can also be passed a .md URL.
		$probe = preg_replace( '/\.md(\/?)?$/', '$1', $url );

		$post_id = url_to_postid( $probe );
		if ( $post_id <= 0 ) {
			// Try category / tag archive URLs — return empty content + the term info.
			$term_url = trim( wp_parse_url( $probe, PHP_URL_PATH ) ?? '', '/' );
			if ( '' !== $term_url ) {
				$segments = explode( '/', $term_url );
				if ( count( $segments ) >= 2 && in_array( $segments[0], array( 'category', 'tag' ), true ) ) {
					$tax  = 'category' === $segments[0] ? 'category' : 'post_tag';
					$slug = end( $segments );
					$term = get_term_by( 'slug', $slug, $tax );
					if ( $term && ! is_wp_error( $term ) ) {
						return array(
							'id'         => 0,
							'type'       => 'term',
							'taxonomy'   => $tax,
							'term'       => array(
								'name'  => $term->name,
								'slug'  => $term->slug,
								'count' => (int) $term->count,
								'url'   => get_term_link( $term ),
							),
						);
					}
				}
			}
			return array( 'id' => 0 );
		}

		$post = get_post( $post_id );
		if ( ! self::is_post_exposable( $post ) ) {
			return array( 'id' => 0 );
		}
		return self::post_payload( $post );
	}

	public static function ability_list_pages( array $input ): array {
		if ( $g = self::guard( 'list-pages' ) ) { return $g; }
		$limit  = isset( $input['limit'] )  ? (int) $input['limit']  : 50;
		$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => $limit,
			'offset'         => $offset,
			// ID breaks ties between pages with the same order and title, for
			// the same OFFSET-paging reason as list-recent-posts.
			'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC', 'ID' => 'ASC' ),
		) );
		$raw_count = count( $pages );
		$pages     = self::filter_exposable_posts( $pages );

		$out = array();
		foreach ( $pages as $p ) {
			$out[] = array(
				'id'        => (int) $p->ID,
				'title'     => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'       => get_permalink( $p ),
				'parent_id' => (int) $p->post_parent,
				'modified'  => mysql2date( 'c', $p->post_modified_gmt ),
			);
		}
		return array(
			'pages'       => $out,
			'has_more'    => $raw_count === $limit,
			'next_offset' => $offset + $limit,
		);
	}

	public static function ability_list_content_types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$out   = array();
		foreach ( $types as $slug => $obj ) {
			if ( 'attachment' === $slug ) {
				continue;
			}
			// Live post counts so the agent knows what's worth querying.
			$counts = wp_count_posts( $slug );
			$out[] = array(
				'slug'         => $slug,
				'label'        => $obj->labels->name,
				'singular'     => $obj->labels->singular_name,
				'hierarchical' => (bool) $obj->hierarchical,
				'published'    => isset( $counts->publish ) ? (int) $counts->publish : 0,
				'archive_url'  => get_post_type_archive_link( $slug ) ?: '',
			);
		}
		return array( 'content_types' => $out );
	}

	public static function ability_list_categories( array $input ): array {
		if ( $g = self::guard( 'list-categories' ) ) { return $g; }
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
		$limit = max( 1, min( 200, $limit ) );

		$cats = get_categories( array(
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => $limit,
			'hide_empty' => false,
		) );

		$out = array();
		foreach ( $cats as $c ) {
			$out[] = array(
				'id'        => (int) $c->term_id,
				'name'      => $c->name,
				'slug'      => $c->slug,
				'parent_id' => (int) $c->parent,
				'count'     => (int) $c->count,
				'url'       => get_category_link( $c->term_id ),
			);
		}
		return array( 'categories' => $out );
	}

	public static function ability_list_tags( array $input ): array {
		if ( $g = self::guard( 'list-tags' ) ) { return $g; }
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
		$limit = max( 1, min( 200, $limit ) );

		$tags = get_tags( array(
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => $limit,
			'hide_empty' => false,
		) );

		$out = array();
		foreach ( $tags as $t ) {
			$out[] = array(
				'id'    => (int) $t->term_id,
				'name'  => $t->name,
				'slug'  => $t->slug,
				'count' => (int) $t->count,
				'url'   => get_tag_link( $t->term_id ),
			);
		}
		return array( 'tags' => $out );
	}

	public static function ability_get_llms_txt( array $input ): array {
		if ( $g = self::guard( 'get-llms-txt' ) ) { return $g; }
		$full = ! empty( $input['full'] );
		if ( ! class_exists( 'RNRD_Llms_Txt' ) ) {
			return array( 'content' => '', 'enabled' => false );
		}

		// Respect the master toggles — don't return content the file endpoint
		// itself would 404 on.
		$llms_on = 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$full_on = 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' );

		if ( ! $llms_on ) {
			return array( 'content' => '', 'enabled' => false, 'note' => 'llms.txt is disabled on this site.' );
		}
		if ( $full && ! $full_on ) {
			return array( 'content' => '', 'enabled' => false, 'note' => 'llms-full.txt is disabled on this site.' );
		}

		$content = $full ? RNRD_Llms_Txt::generate_full() : RNRD_Llms_Txt::generate();
		return array(
			'content' => $content,
			'enabled' => true,
			'url'     => home_url( $full ? '/llms-full.txt' : '/llms.txt' ),
		);
	}

	public static function ability_get_author( array $input ): array {
		if ( $g = self::guard( 'get-author' ) ) { return $g; }
		$author_id = isset( $input['author_id'] ) ? (int) $input['author_id'] : 0;
		$user      = $author_id ? get_userdata( $author_id ) : null;
		if ( ! $user ) {
			return array( 'id' => 0 );
		}

		// TC-SEC-02: only expose authors who have PUBLISHED public content — mirrors WP
		// core /wp/v2/users (which hides no-post users) and blocks anonymous user
		// enumeration (subscribers, 0-post admins, etc.). Their byline/EEAT is public anyway.
		$rnrd_public_types = array_values( get_post_types( array( 'public' => true ) ) );
		if ( (int) count_user_posts( $user->ID, $rnrd_public_types, true ) < 1 ) {
			return array( 'id' => 0 );
		}

		// Return the standard set of EEAT fields RankReady's Author Box records.
		$fields = array(
			'job_title', 'employer', 'employer_url', 'bio', 'headshot', 'headshot_alt',
			'started_year', 'expertise', 'credentials_suffix', 'education', 'certifications',
			'memberships', 'awards', 'wikidata', 'wikipedia', 'orcid', 'scholar',
			'linkedin', 'github', 'youtube', 'twitter', 'website', 'contact_url',
		);

		$eeat = array();
		foreach ( $fields as $key ) {
			$val = get_user_meta( $user->ID, 'rnrd_author_' . $key, true );
			if ( '' !== (string) $val ) {
				$eeat[ $key ] = is_array( $val ) ? $val : (string) $val;
			}
		}

		return array(
			'id'          => (int) $user->ID,
			'name'        => $user->display_name,
			'url'         => get_author_posts_url( $user->ID ),
			'description' => $user->description,
			'eeat'        => $eeat,
		);
	}

	public static function ability_get_sitemap( array $input ): array {
		if ( $g = self::guard( 'get-sitemap' ) ) { return $g; }
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 500;
		$limit = max( 1, min( 2000, $limit ) );

		$entries = get_posts( array(
			'post_type'              => array( 'post', 'page' ),
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $limit,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		$urls = array();
		foreach ( self::filter_exposable_posts( $entries ) as $p ) {
			$urls[] = array(
				'url'       => get_permalink( $p ),
				'lastmod'   => mysql2date( 'c', $p->post_modified_gmt ),
				'type'      => $p->post_type,
			);
		}

		return array(
			'count'         => count( $urls ),
			'urls'          => $urls,
			'sitemap_xml'   => home_url( '/sitemap.xml' ),
			'wp_sitemap'    => home_url( '/wp-sitemap.xml' ),
		);
	}

	public static function ability_get_fresh_content( array $input ): array {
		if ( $g = self::guard( 'get-fresh-content' ) ) { return $g; }
		$days  = isset( $input['days'] )  ? (int) $input['days']  : 30;
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 25;
		$days  = max( 1, min( 365, $days ) );
		$limit = max( 1, min( 100, $limit ) );

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$posts = get_posts( array(
			'post_type'              => array( 'post', 'page' ),
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $limit,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'date_query'             => array(
				array( 'column' => 'post_modified_gmt', 'after' => $cutoff, 'inclusive' => true ),
			),
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		$out = array();
		foreach ( self::filter_exposable_posts( $posts ) as $p ) {
			$out[] = array(
				'id'        => (int) $p->ID,
				'title'     => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'       => get_permalink( $p ),
				'post_type' => $p->post_type,
				'modified'  => mysql2date( 'c', $p->post_modified_gmt ),
				'age_days'  => (int) max( 0, floor( ( time() - strtotime( $p->post_modified_gmt ) ) / DAY_IN_SECONDS ) ),
			);
		}

		return array(
			'days_window' => $days,
			'count'       => count( $out ),
			'items'       => $out,
		);
	}

	/** Tagline when the site has one, else a plain sentence, so the manifest never ships an empty description. */
	private static function manifest_description(): string {
		$tagline = trim( (string) get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			return $tagline;
		}
		/* translators: %s: site name */
		return sprintf( __( 'Read-only access to the published content of %s for AI agents.', 'rankready-ai-llm-seo' ), get_bloginfo( 'name' ) );
	}
}
