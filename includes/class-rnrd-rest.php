<?php
/**
 * REST API endpoints — authenticated, rate-limited.
 *
 * Endpoints:
 *   GET  /rankready/v1/summary/{id}
 *   POST /rankready/v1/regenerate/{id}
 *   DELETE /rankready/v1/summary/{id}
 *   POST /rankready/v1/bulk/start
 *   POST /rankready/v1/bulk/process
 *   POST /rankready/v1/bulk/stop
 *   GET  /rankready/v1/bulk/status
 *   POST /rankready/v1/author/preview
 *   POST /rankready/v1/author/execute
 *   POST /rankready/v1/author/process
 *   POST /rankready/v1/author/stop
 *   POST /rankready/v1/llms/flush-cache
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Rest {

	private const NS             = 'rankready/v1';
	private const REGEN_COOLDOWN = 60;
	private const BULK_BATCH     = 5;
	private const AUTHOR_BATCH   = 20;

	/**
	 * Option name of the Start Over queue lock, and how long a held lock is
	 * believed before it is treated as abandoned.
	 *
	 * Regenerating one post is two provider API calls, so a worker that has held
	 * the lock for five minutes did not finish: it fataled, timed out, or was
	 * killed mid-request. Without a breaker that lock parks the queue forever and
	 * the only recovery is deleting an option by hand.
	 */
	private const STARTOVER_LOCK     = 'rnrd_so_lock';
	private const STARTOVER_LOCK_TTL = 300;

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );

		// WP-Cron hook for the Start Over bulk operation (Free). The Bulk
		// Summary + Bulk FAQ cron ticks (RNRD_CRON_BULK_SUMMARY / RNRD_CRON_BULK_FAQ)
		// are a PRO engine — registered by RNRD_Pro_Rest, not here.
		add_action( RNRD_CRON_BULK_STARTOVER, array( self::class, 'cron_startover_tick' ) );
	}

	public static function register_routes(): void {

		// ── Summary endpoints ─────────────────────────────────────────────────

		register_rest_route( self::NS, '/summary/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_summary' ),
				'permission_callback' => array( self::class, 'can_edit_post' ),
				'args'                => self::post_id_arg(),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'delete_summary' ),
				'permission_callback' => array( self::class, 'can_edit_post' ),
				'args'                => self::post_id_arg(),
			),
		) );

		register_rest_route( self::NS, '/regenerate/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'regenerate_summary' ),
			'permission_callback' => array( self::class, 'can_edit_post' ),
			'args'                => self::post_id_arg(),
		) );

		// ── Bulk summary endpoints (PRO) ──────────────────────────────────────
		// Routes bulk/start, bulk/process, bulk/stop, bulk/status are registered
		// by the Pro add-on (RNRD_Pro_Rest), not in the Free build.

		// ── Bulk Author Changer endpoints ─────────────────────────────────────

		register_rest_route( self::NS, '/author/preview', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'author_preview' ),
			'permission_callback' => array( self::class, 'can_edit_others' ),
			'args'                => self::author_args(),
		) );

		register_rest_route( self::NS, '/author/execute', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'author_execute' ),
			'permission_callback' => array( self::class, 'can_edit_others' ),
			'args'                => self::author_args(),
		) );

		register_rest_route( self::NS, '/author/process', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'author_process' ),
			'permission_callback' => array( self::class, 'can_edit_others' ),
		) );

		register_rest_route( self::NS, '/author/stop', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'author_stop' ),
			'permission_callback' => array( self::class, 'can_edit_others' ),
		) );

		// ── LLMs.txt cache flush ──────────────────────────────────────────────

		register_rest_route( self::NS, '/llms/flush-cache', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'llms_flush_cache' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		// ── API Key Verification ──────────────────────────────────────────────

		register_rest_route( self::NS, '/verify-key', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'verify_api_key' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
			'args'                => array(
				'key' => array(
					'required' => true,
					'type'     => 'string',
					'sanitize_callback' => array( self::class, 'sanitize_secret' ),
				),
				'provider' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => 'openai',
					'enum'              => array( 'openai', 'anthropic', 'gemini', 'deepseek' ),
					'sanitize_callback' => 'sanitize_key',
				),
				'model' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NS, '/models/refresh', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'refresh_models' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
			'args'                => array(
				'provider' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'openai', 'anthropic', 'gemini', 'deepseek' ),
					'sanitize_callback' => 'sanitize_key',
				),
				'key' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => array( self::class, 'sanitize_secret' ),
				),
			),
		) );

		register_rest_route( self::NS, '/verify-dfs', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'verify_dfs_key' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
			'args'                => array(
				'login'    => array( 'type' => 'string', 'sanitize_callback' => array( self::class, 'sanitize_secret' ) ),
				'password' => array( 'type' => 'string', 'sanitize_callback' => array( self::class, 'sanitize_secret' ) ),
			),
		) );

		// ── FAQ endpoints ─────────────────────────────────────────────────────

		register_rest_route( self::NS, '/faq/generate/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'faq_generate' ),
			'permission_callback' => array( self::class, 'can_edit_post' ),
			'args'                => array_merge( self::post_id_arg(), array(
				'keyword' => array( 'type' => 'string', 'default' => '' ),
				'count'   => array( 'type' => 'integer', 'default' => 0 ),
			) ),
		) );

		register_rest_route( self::NS, '/faq/get/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'faq_get' ),
			'permission_callback' => array( self::class, 'can_edit_post' ),
			'args'                => self::post_id_arg(),
		) );

		register_rest_route( self::NS, '/faq/save/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'faq_save' ),
			'permission_callback' => array( self::class, 'can_edit_post' ),
			'args'                => self::post_id_arg(),
		) );

		register_rest_route( self::NS, '/faq/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( self::class, 'delete_faq' ),
			'permission_callback' => array( self::class, 'can_edit_post' ),
			'args'                => self::post_id_arg(),
		) );

		// ── FAQ bulk endpoints (PRO) ──────────────────────────────────────────
		// Routes faq-bulk/start, faq-bulk/process, faq-bulk/stop are registered
		// by the Pro add-on (RNRD_Pro_Rest), not in the Free build.

		// ── FAQ Posts List ────────────────────────────────────────────────────

		register_rest_route( self::NS, '/faq/posts', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'faq_posts_list' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		// ── Error Log ────────────────────────────────────────────────────────

		register_rest_route( self::NS, '/errors', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_errors' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		register_rest_route( self::NS, '/errors/clear', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'clear_errors' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		// ── Token Usage per post ────────────────────────────────────────────
		register_rest_route( self::NS, '/token-usage', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_token_usage' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		// ── Start Over — clear + regenerate both summary and FAQ ────────
		register_rest_route( self::NS, '/start-over/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'start_over' ),
			'permission_callback' => array( self::class, 'can_edit_post' ),
			'args'                => array_merge( self::post_id_arg(), array(
				'keyword' => array( 'type' => 'string', 'default' => '' ),
			) ),
		) );

		// ── Start Over Bulk — clear + regenerate all posts ──────────────
		register_rest_route( self::NS, '/startover-bulk/start', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'startover_bulk_start' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
			'args'                => array(
				'post_types' => array(
					'required' => false, 'type' => 'array',
					'items'    => array( 'type' => 'string' ),
					'default'  => array(),
					'sanitize_callback' => function ( $v ) { return is_array( $v ) ? array_map( 'sanitize_key', $v ) : array(); },
				),
				'resume' => array( 'type' => 'boolean', 'default' => false ),
			),
		) );

		register_rest_route( self::NS, '/startover-bulk/process', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'startover_bulk_process' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		register_rest_route( self::NS, '/startover-bulk/stop', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'startover_bulk_stop' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		// ── Content Freshness Alerts ─────────────────────────────────────────
		register_rest_route( self::NS, '/freshness', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'content_freshness' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
			'args'                => array(
				'days' => array(
					'type'    => 'integer',
					'default' => 90,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		// (Old /health-check route removed in rc.15 — replaced by the live
		// Diagnostics endpoint registered by RNRD_Diagnostics class (probe count lives
		// in run(), never in a comment that cannot be kept true):
		//   GET /rankready/v1/diagnostics?include_api=0|1
		//   GET /rankready/v1/diagnostics/report?include_api=0|1
		// See class-rnrd-diagnostics.php for the new implementation.)

		// ── Schema Scanner endpoints (PRO) ───────────────────────────────────
		// /schema/status and /schema/recommendation belong to the HowTo/ItemList
		// scanner engine and are registered by the Pro add-on (RNRD_Pro_Rest).
	}

	// ── Permission callbacks ──────────────────────────────────────────────────

	public static function can_edit_post( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'rankready-ai-llm-seo' ), array( 'status' => 401 ) );
		}
		$post_id = (int) $request->get_param( 'id' );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You do not have permission to edit this post.', 'rankready-ai-llm-seo' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function is_admin_user() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'rankready-ai-llm-seo' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Admin access required.', 'rankready-ai-llm-seo' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function can_edit_others() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'rankready-ai-llm-seo' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Insufficient permissions.', 'rankready-ai-llm-seo' ), array( 'status' => 403 ) );
		}
		return true;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SUMMARY ENDPOINTS
	// ══════════════════════════════════════════════════════════════════════════

	public static function get_summary( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		return new WP_REST_Response( array(
			'success' => true,
			'summary' => (string) get_post_meta( $post_id, RNRD_META_SUMMARY, true ),
			'has_key' => RNRD_LLM::active_provider_ready(),
			'autogen_pending' => RNRD_Generator::autogen_pending( 'summary', $post_id ),
			'autogen_error'   => RNRD_Generator::autogen_error( 'summary', $post_id ),
		), 200 );
	}

	public static function regenerate_summary( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		// 1. Post still exists.
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'rnrd_invalid_post', __( 'This post no longer exists. Save the page, then try again.', 'rankready-ai-llm-seo' ), array( 'status' => 404 ) );
		}

		// 2. AI provider key present.
		if ( ! RNRD_LLM::active_provider_ready() ) {
			$rnrd_prov = RNRD_LLM::get_provider_label( RNRD_LLM::get_active_provider() );
			/* translators: %s: AI provider name */
			return new WP_Error( 'rnrd_no_api_key', sprintf( __( 'No %s API key yet. Add it under RankReady → Settings → API Keys, then generate.', 'rankready-ai-llm-seo' ), $rnrd_prov ), array( 'status' => 400 ) );
		}

		// 3. AI Summary enabled for this post type.
		if ( ! class_exists( 'RNRD_Summary' ) || ! RNRD_Summary::is_post_type_enabled( $post->post_type ) ) {
			$pt_obj   = get_post_type_object( $post->post_type );
			$pt_label = $pt_obj && isset( $pt_obj->labels->singular_name ) ? $pt_obj->labels->singular_name : $post->post_type;
			return new WP_Error(
				'rnrd_type_disabled',
				/* translators: %s: post type label, e.g. "Page". */
				sprintf( __( 'AI Summary is not enabled for the “%s” type. Turn it on in AI Content → AI Summary → Post types.', 'rankready-ai-llm-seo' ), $pt_label ),
				array( 'status' => 400 )
			);
		}

		// 4. Enough content to summarise.
		$content = RNRD_Generator::get_content_string( $post );
		$words   = (int) preg_match_all( '/\S+/', (string) $content );
		if ( $words < 25 ) {
			// Issue #9: resolve_post_content() already tries a builder-specific
			// render path, but if that still comes back thin (unsupported
			// builder, or the render path failed) the stock "add a few more
			// sentences" message sends the user to edit the wrong field —
			// post_content is empty because the real copy lives in a builder's
			// own meta, not because the post is genuinely short.
			$builder_meta = array(
				'_elementor_data'          => 'Elementor',
				'_bricks_page_content_2'   => 'Bricks',
				'ct_builder_shortcodes'    => 'Oxygen',
			);
			$builder_name = '';
			foreach ( $builder_meta as $meta_key => $label ) {
				if ( '' !== (string) get_post_meta( $post_id, $meta_key, true ) ) {
					$builder_name = $label;
					break;
				}
			}
			if ( '' !== $builder_name ) {
				return new WP_Error(
					'rnrd_thin_content_builder',
					/* translators: 1: page builder name, e.g. "Elementor". 2: the same page builder name. */
					sprintf( __( 'This page’s content lives in %1$s, and RankReady could not read it. Open the page in %2$s and confirm it has real content, or contact support.', 'rankready-ai-llm-seo' ), $builder_name, $builder_name ),
					array( 'status' => 400 )
				);
			}
			return new WP_Error( 'rnrd_thin_content', __( 'Not enough content to summarise yet. Add a few more sentences to the post, then generate.', 'rankready-ai-llm-seo' ), array( 'status' => 400 ) );
		}

		// 5. Cooldown. Read both stamps and take the later one: Delete clears
		// RNRD_META_GENERATED but leaves RNRD_META_COOLDOWN behind, so
		// "Delete, then Generate" can no longer skip the throttle.
		$last  = max(
			(int) get_post_meta( $post_id, RNRD_META_GENERATED, true ),
			(int) get_post_meta( $post_id, RNRD_META_COOLDOWN, true )
		);
		$since = time() - $last;
		if ( $last > 0 && $since < self::REGEN_COOLDOWN ) {
			return new WP_Error(
				'rnrd_rate_limited',
				/* translators: %d: remaining seconds before regeneration is allowed */
				sprintf( __( 'Please wait %d more seconds before regenerating.', 'rankready-ai-llm-seo' ), self::REGEN_COOLDOWN - $since ),
				array( 'status' => 429 )
			);
		}

		$summary = RNRD_Generator::force_generate( $post_id );
		if ( false === $summary || is_wp_error( $summary ) ) {
			$raw = is_wp_error( $summary ) ? $summary->get_error_message() : self::last_generation_error();
			return new WP_Error( 'rnrd_generation_failed', self::friendly_generation_error( $raw ), array( 'status' => 502 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'summary' => $summary ), 200 );
	}

	/**
	 * Remove generated summary meta for a single post (no regeneration).
	 */
	public static function delete_summary( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'rnrd_invalid_post', __( 'This post no longer exists.', 'rankready-ai-llm-seo' ), array( 'status' => 404 ) );
		}

		self::clear_summary_meta( $post_id );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Delete summary post meta keys. Shared by Start Over and per-post delete.
	 */
	private static function clear_summary_meta( int $post_id ): void {
		self::carry_cooldown_forward( $post_id, RNRD_META_GENERATED, RNRD_META_COOLDOWN );
		delete_post_meta( $post_id, RNRD_META_SUMMARY );
		delete_post_meta( $post_id, RNRD_META_HASH );
		delete_post_meta( $post_id, RNRD_META_GENERATED );
		self::purge_derived_output( $post_id );
	}

	/**
	 * Delete FAQ content meta (rows, hash, timestamp). Keeps saved keyword.
	 */
	private static function clear_faq_content_meta( int $post_id ): void {
		self::carry_cooldown_forward( $post_id, RNRD_META_FAQ_GENERATED, RNRD_META_FAQ_COOLDOWN );
		delete_post_meta( $post_id, RNRD_META_FAQ );
		delete_post_meta( $post_id, RNRD_META_FAQ_HASH );
		delete_post_meta( $post_id, RNRD_META_FAQ_GENERATED );
		self::purge_derived_output( $post_id );
	}

	/**
	 * Copy a generation timestamp onto its own cooldown key before the caller
	 * deletes it, so the throttle outlives the thing that was generated.
	 *
	 * Only ever moves the clock forward. A post cleared twice keeps the newer
	 * stamp rather than resetting to whatever the second delete happened to
	 * find, which by then is nothing.
	 */
	private static function carry_cooldown_forward( int $post_id, string $from_key, string $to_key ): void {
		$stamp = (int) get_post_meta( $post_id, $from_key, true );
		if ( $stamp <= 0 ) {
			return;
		}
		if ( $stamp > (int) get_post_meta( $post_id, $to_key, true ) ) {
			update_post_meta( $post_id, $to_key, $stamp );
		}
	}

	/**
	 * Invalidate everything downstream of a post's generated meta.
	 *
	 * Markdown output embeds the summary and the FAQ, and its URL cache is
	 * only busted on save_post / status change / delete. Clearing the meta
	 * through REST fires none of those, so before this the admin said the
	 * summary was gone while /post.md kept serving it to crawlers until the
	 * next unrelated edit.
	 */
	public static function purge_derived_output( int $post_id ): void {
		if ( class_exists( 'RNRD_Markdown' ) && method_exists( 'RNRD_Markdown', 'purge_post_md_url' ) ) {
			RNRD_Markdown::purge_post_md_url( $post_id );
		}
		/**
		 * Fires after a post's generated summary or FAQ has been cleared.
		 *
		 * @since 1.4.0
		 * @param int $post_id Post whose derived output is now stale.
		 */
		do_action( 'rnrd_post_derived_output_purged', $post_id );
	}

	/**
	 * Sanitise a credential from a request: trim only. sanitize_text_field() strips
	 * tags and percent-encoded octets, which silently corrupts a password or key
	 * that legitimately contains them and turns a valid credential into a 401.
	 *
	 * @param mixed $value Raw request value.
	 * @return string
	 */
	public static function sanitize_secret( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Delete FAQ post meta keys. Shared by Start Over and per-post delete.
	 */
	private static function clear_faq_meta( int $post_id ): void {
		self::clear_faq_content_meta( $post_id );
		delete_post_meta( $post_id, RNRD_META_FAQ_KEYWORD );
	}

	/**
	 * Most recent generation error message logged by RNRD_Generator, or ''.
	 * Lets the summary endpoint surface WHY a generation failed (provider quota,
	 * bad key, timeout) instead of a flat "Failed to generate".
	 */
	public static function last_generation_error(): string {
		if ( ! class_exists( 'RNRD_Generator' ) || ! method_exists( 'RNRD_Generator', 'get_error_log' ) ) {
			return '';
		}
		$log  = (array) RNRD_Generator::get_error_log();
		$last = end( $log );
		return is_array( $last ) && isset( $last['message'] ) ? (string) $last['message'] : '';
	}

	/**
	 * Translate a raw provider/error string into a short, friendly, actionable
	 * message. Shared by the AI Summary AND FAQ generate endpoints so the
	 * Gutenberg block and the Elementor widget show identical guided errors.
	 * Public since 1.4.0 so Pro's bulk runner can show the same reason per post.
	 */
	public static function friendly_generation_error( string $raw ): string {
		$low      = strtolower( $raw );
		$provider = class_exists( 'RNRD_LLM' )
			? RNRD_LLM::get_provider_label( RNRD_LLM::get_active_provider() )
			: __( 'the AI provider', 'rankready-ai-llm-seo' );

		$has = function ( $needles ) use ( $low ) {
			foreach ( (array) $needles as $n ) {
				// HTTP status codes match as whole numbers only, so "1401 tokens" or
				// "request 4290" is not read as a 401 or a 429.
				if ( ctype_digit( $n ) ? 1 === preg_match( '/(?<!\d)' . $n . '(?!\d)/', $low ) : false !== strpos( $low, $n ) ) {
					return true;
				}
			}
			return false;
		};

		// DataForSEO (FAQ keyword research) credentials.
		if ( $has( array( 'dataforseo' ) ) && $has( array( 'auth', 'credential', 'login', 'password', '401', 'unauthorized' ) ) ) {
			return __( 'DataForSEO credentials are missing or invalid. Add them in Settings → FAQ, or generate without keyword research.', 'rankready-ai-llm-seo' );
		}

		// Quota / billing / rate limit.
		if ( $has( array( 'quota', 'insufficient_quota', 'billing', 'exceeded your current', 'rate limit', 'rate_limit', 'too many requests', '429' ) ) ) {
			/* translators: %s: AI provider name. */
			return sprintf( __( '%s says the account is out of credits or rate-limited. Check your usage/billing on the provider dashboard, then try again.', 'rankready-ai-llm-seo' ), $provider );
		}

		// Auth / invalid key.
		if ( $has( array( 'invalid api key', 'invalid_api_key', 'invalid key', 'incorrect api key', 'unauthorized', 'authentication', 'permission', '401', '403' ) ) ) {
			/* translators: %s: AI provider name. */
			return sprintf( __( '%s rejected the API key. Re-check it under RankReady → Settings → API Keys.', 'rankready-ai-llm-seo' ), $provider );
		}

		// Model unavailable / retired.
		if ( $has( array( 'model' ) ) && $has( array( 'not found', 'does not exist', 'deprecat', 'unavailable', 'not supported' ) ) ) {
			/* translators: %s: AI provider name. */
			return sprintf( __( 'The selected %s model is unavailable. Pick a current model under RankReady → Settings → API Keys.', 'rankready-ai-llm-seo' ), $provider );
		}

		// Network / timeout.
		if ( $has( array( 'timeout', 'timed out', 'could not resolve', 'curl', 'connection', 'network' ) ) ) {
			/* translators: %s: AI provider name. */
			return sprintf( __( 'Could not reach %s (network/timeout). Make sure the server can make outbound requests, then try again.', 'rankready-ai-llm-seo' ), $provider );
		}

		// Empty / malformed AI response.
		if ( $has( array( 'empty content', 'unexpected json', 'unexpected response', 'parse', 'no valid' ) ) ) {
			/* translators: %s: AI provider name. */
			return sprintf( __( '%s returned an unexpected response. Try again in a moment.', 'rankready-ai-llm-seo' ), $provider );
		}

		// Fallback — show the raw provider error if we have one, else generic.
		if ( '' !== trim( $raw ) ) {
			/* translators: %s: the raw error returned by the AI provider. */
			return sprintf( __( 'AI generation failed: %s', 'rankready-ai-llm-seo' ), $raw );
		}
		return __( 'AI generation failed. Please try again.', 'rankready-ai-llm-seo' );
	}

	// ── Start Over — clear all data and regenerate both summary + FAQ ────────

	/**
	 * Start Over for one post: wipe summary + FAQ, regenerate both.
	 *
	 * This used to carry its own inline copy of the wipe-then-regenerate
	 * sequence, and that copy had every bug the bulk path had already been
	 * fixed for:
	 *
	 * 1. It tested `false !== $summary`. A WP_Error is not false, so a failed
	 *    generation reported 'generated' inside an HTTP 200. The user was told
	 *    the summary had been rebuilt when it had been deleted and not replaced.
	 * 2. It deleted the meta with no snapshot, so any provider timeout, quota
	 *    error or bad key permanently destroyed a hand-edited FAQ. The safe
	 *    implementation sat in this same file, unused.
	 * 3. It gated FAQ regeneration on DataForSEO credentials AFTER wiping the
	 *    FAQ, so a site without a paid DataForSEO account lost its FAQ and got
	 *    'skipped_no_dfs' back. DataForSEO only supplies optional keyword hints
	 *    (see regenerate_with_rollback()'s note), so it was never a real gate.
	 * 4. It enforced no cooldown at all, while regenerate_summary() and
	 *    faq_generate() are both throttled at REGEN_COOLDOWN.
	 *
	 * All four are gone because the body is now the shared twin. Two callers,
	 * one implementation: a fix to the bulk path can no longer skip this one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start_over( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$keyword = sanitize_text_field( (string) $request->get_param( 'keyword' ) );

		if ( ! RNRD_LLM::active_provider_ready() ) {
			$rnrd_prov = RNRD_LLM::get_provider_label( RNRD_LLM::get_active_provider() );
			/* translators: %s: the active AI provider name, e.g. "Gemini (Google)". */
			return new WP_Error( 'rnrd_no_api_key', sprintf( __( '%s has no API key yet. Add one under RankReady → Settings → API Keys, then start the run.', 'rankready-ai-llm-seo' ), $rnrd_prov ), array( 'status' => 400 ) );
		}

		// Same throttle as Regenerate and Generate FAQ. Start Over is the most
		// expensive button in the plugin (two LLM calls), so leaving it as the
		// one unthrottled path meant a held-down click could burn a quota.
		$last  = max(
			(int) get_post_meta( $post_id, RNRD_META_GENERATED, true ),
			(int) get_post_meta( $post_id, RNRD_META_COOLDOWN, true )
		);
		$since = time() - $last;
		if ( $last > 0 && $since < self::REGEN_COOLDOWN ) {
			return new WP_Error(
				'rnrd_rate_limited',
				/* translators: %d: remaining seconds before regeneration is allowed */
				sprintf( __( 'Please wait %d more seconds before regenerating.', 'rankready-ai-llm-seo' ), self::REGEN_COOLDOWN - $since ),
				array( 'status' => 429 )
			);
		}

		$status = self::regenerate_with_rollback( $post_id, $keyword );

		/*
		 * `'success' => true` used to be hardcoded here. The body underneath it
		 * already said 'failed' or 'restored' per half, so the envelope and its
		 * own payload could contradict each other, and a caller that reads the
		 * one field a success envelope exists for was told the rebuild worked
		 * when neither half had been rebuilt. Nothing in the plugin calls this
		 * route (the UI drives the bulk path), which is exactly why it went
		 * unnoticed: the only consumers are other people's integrations, and
		 * they have no UI to notice the contradiction in.
		 *
		 * 'restored' is not success either. It means the regeneration failed and
		 * the previous content was put back, so the caller's request did not
		 * happen. Reporting it as success is how an integration ends up looping
		 * over a catalogue, getting 200 every time, and changing nothing.
		 */
		$generated = array_keys( array_filter( $status, static function ( $v ) { return 'generated' === $v; } ) );

		if ( ! $generated ) {
			return new WP_Error(
				'rnrd_start_over_failed',
				__( 'Neither the summary nor the FAQ could be regenerated. Previous content, where there was any, has been restored. The provider error is in Diagnostics → Error Log.', 'rankready-ai-llm-seo' ),
				array( 'status' => 502, 'result' => $status )
			);
		}

		$all = count( $generated ) === count( $status );

		return new WP_REST_Response(
			array(
				'success' => $all,
				'partial' => ! $all,
				'result'  => $status,
			),
			200
		);
	}

	// ══════════════════════════════════════════════════════════════════════════
	// BULK START OVER (clear + regenerate all)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Queue every published post of the chosen types for clear + regenerate.
	 *
	 * The response carries `total` (queued) and, on a fresh start, `available`
	 * (published posts matched, which exceeds `total` only when the
	 * RNRD_BULK_MAX_POSTS cap applied). `available` is additive: existing
	 * callers that read `total` are unaffected. No Free screen calls this route;
	 * it stays registered as a shipped contract.
	 *
	 * @param WP_REST_Request $request Request with `post_types` and `resume`.
	 */
	public static function startover_bulk_start( $request ) {
		$resume = (bool) $request->get_param( 'resume' );

		// Resume: pick up existing queue if one exists.
		if ( $resume ) {
			$queue = (array) get_option( RNRD_SO_QUEUE, array() );
			if ( ! empty( $queue ) ) {
				$done  = (int) get_option( RNRD_SO_DONE, 0 );
				$total = (int) get_option( RNRD_SO_TOTAL, 0 );
				update_option( RNRD_SO_RUNNING, true, false );
				// Re-schedule cron for background processing.
				if ( ! wp_next_scheduled( RNRD_CRON_BULK_STARTOVER ) ) {
					wp_schedule_event( time() + 10, 'rnrd_one_minute', RNRD_CRON_BULK_STARTOVER );
				}
				return new WP_REST_Response( array(
					'total'   => $total,
					'done'    => $done,
					'running' => true,
					'resumed' => true,
				), 200 );
			}
			// No queue to resume — fall through to error or start fresh.
			return new WP_Error( 'rnrd_nothing_to_resume', __( 'No pending start-over queue found. Start a new operation instead.', 'rankready-ai-llm-seo' ), array( 'status' => 400 ) );
		}

		if ( get_option( RNRD_SO_RUNNING ) ) {
			return new WP_Error( 'rnrd_already_running', __( 'A start-over operation is already running. Stop it first.', 'rankready-ai-llm-seo' ), array( 'status' => 409 ) );
		}

		if ( ! RNRD_LLM::active_provider_ready() ) {
			$rnrd_prov = RNRD_LLM::get_provider_label( RNRD_LLM::get_active_provider() );
			/* translators: %s: the active AI provider name, e.g. "Gemini (Google)". */
			return new WP_Error( 'rnrd_no_api_key', sprintf( __( '%s has no API key yet. Add one under RankReady → Settings → API Keys, then start the run.', 'rankready-ai-llm-seo' ), $rnrd_prov ), array( 'status' => 400 ) );
		}

		$raw_types  = (array) $request->get_param( 'post_types' );
		$allowed    = array_keys( RNRD_Admin::get_allowed_post_types() );
		$post_types = array_values( array_intersect( $raw_types, $allowed ) );

		if ( empty( $post_types ) ) {
			return new WP_Error( 'rnrd_invalid_types', __( 'Pick at least one post type to run.', 'rankready-ai-llm-seo' ), array( 'status' => 400 ) );
		}

		$ids = array_map( 'intval', get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => RNRD_BULK_MAX_POSTS,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) ) );
		$available = self::count_published( $post_types, count( $ids ), RNRD_BULK_MAX_POSTS );

		$total = count( $ids );

		update_option( RNRD_SO_QUEUE,   $ids,   false );
		update_option( RNRD_SO_TOTAL,   $total, false );
		update_option( RNRD_SO_DONE,    0,      false );
		update_option( RNRD_SO_RUNNING, true,   false );

		// A fresh run starts with the claim lock released. Without this, a lock
		// left behind by a worker that died in the previous run would stall the
		// first tick for the whole STARTOVER_LOCK_TTL window for no reason.
		delete_option( self::STARTOVER_LOCK );

		// Schedule WP-Cron to process queue even if browser closes.
		if ( ! wp_next_scheduled( RNRD_CRON_BULK_STARTOVER ) ) {
			wp_schedule_event( time() + 10, 'rnrd_one_minute', RNRD_CRON_BULK_STARTOVER );
		}

		return new WP_REST_Response( array(
			'total'     => $total,
			'available' => $available,
			'done'      => 0,
			'running'   => $total > 0,
		), 200 );
	}

	/**
	 * How many published posts a capped bulk query matched, for the
	 * "Queued X of Y" note.
	 *
	 * get_posts() forces no_found_rows, so it cannot report a total, and a
	 * WP_Query with suppress_filters => true is a Plugin Check ERROR. Below the
	 * cap the queue length already is the total. At the cap, wp_count_posts()
	 * gives the same unfiltered count get_posts() matched, from core's cache.
	 *
	 * @param string[] $post_types Post types the query ran over.
	 * @param int      $queued     IDs the capped query returned.
	 * @param int      $cap        The posts_per_page cap.
	 */
	public static function count_published( array $post_types, int $queued, int $cap ): int {
		if ( $queued < $cap ) {
			return $queued;
		}
		$n = 0;
		foreach ( $post_types as $pt ) {
			$c  = wp_count_posts( $pt );
			$n += isset( $c->publish ) ? (int) $c->publish : 0;
		}
		return max( $n, $queued );
	}

	/**
	 * Claim exactly one post id from the Start Over queue, atomically.
	 *
	 * Two workers drain this queue at the same time by design: the browser polls
	 * the REST tick while RNRD_CRON_BULK_STARTOVER runs every minute so the run
	 * survives the tab closing. Both used to do their own
	 * `get_option() -> array_shift() -> update_option()` with no lock at all, so
	 * the interleaving was: both read [A, B, C], both shift A, both write
	 * [B, C]. A is regenerated twice, burning two provider calls on one post, and
	 * `done` is incremented twice for one post of progress. The same shape with
	 * the writes reordered loses a pop instead, and the queue never empties.
	 *
	 * RNRD_Atomic::claim_option() is the fix because it is a single INSERT IGNORE
	 * against a UNIQUE `option_name`: whichever worker loses the race gets false back, rather than
	 * the read-then-write pattern that a lock written with get_option() would
	 * still have (that is exactly the broken lock that produced 29 duplicate rows
	 * from 5 parallel requests in the crawler log).
	 *
	 * @return int The claimed post id, or 0 when the queue is empty or another
	 *             worker holds the claim right now. 0 is not an error: the caller
	 *             reports current progress and the next tick tries again.
	 */
	private static function startover_claim_next(): int {
		// Returns false while another worker holds a live claim. A claim older than
		// the TTL is taken over atomically, so a crashed worker cannot wedge the run.
		if ( ! RNRD_Atomic::acquire_lock( self::STARTOVER_LOCK, self::STARTOVER_LOCK_TTL ) ) {
			return 0;
		}

		// Read the committed row, not whatever this process cached earlier in the
		// request. Holding the lock is pointless if the read behind it is stale.
		wp_cache_delete( RNRD_SO_QUEUE, 'options' );

		$queue   = (array) get_option( RNRD_SO_QUEUE, array() );
		$post_id = $queue ? (int) array_shift( $queue ) : 0;

		if ( $post_id > 0 ) {
			update_option( RNRD_SO_QUEUE, $queue, false );
		}

		RNRD_Atomic::release_lock( self::STARTOVER_LOCK );

		return $post_id;
	}

	/**
	 * Increment the Start Over progress counter and return the new value.
	 *
	 * Deliberately NOT `get_option() + 1 -> update_option()`: that is the lost
	 * update the claim lock above exists to prevent, and it would reintroduce it
	 * one line after the claim was made safe. MySQL resolves the arithmetic, so
	 * two concurrent workers produce two increments.
	 *
	 * @return int The progress count after this increment.
	 */
	private static function startover_bump_done(): int {
		global $wpdb;

		$name = RNRD_SO_DONE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic increment; the Options API cannot express one. Cache is invalidated below.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s",
			$name
		) );

		if ( ! $updated ) {
			// Either the row is missing, or it exists and the UPDATE was a no-op.
			// claim_option() tells the two apart: it fails when the row is there.
			if ( ! RNRD_Atomic::claim_option( $name, '1' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s",
					$name
				) );
			}
		}

		// The direct UPDATE bypassed the Options API, so its cached copy is stale.
		wp_cache_delete( $name, 'options' );

		return (int) get_option( $name, 0 );
	}

	/**
	 * Is the Start Over queue empty right now, reading the committed row?
	 */
	private static function startover_queue_is_empty(): bool {
		wp_cache_delete( RNRD_SO_QUEUE, 'options' );
		return empty( (array) get_option( RNRD_SO_QUEUE, array() ) );
	}

	public static function startover_bulk_process() {
		if ( ! get_option( RNRD_SO_RUNNING ) ) {
			return new WP_REST_Response( array(
				'total' => (int) get_option( RNRD_SO_TOTAL, 0 ),
				'done'  => (int) get_option( RNRD_SO_DONE, 0 ),
				'running' => false,
			), 200 );
		}

		$done  = (int) get_option( RNRD_SO_DONE, 0 );
		$total = (int) get_option( RNRD_SO_TOTAL, 0 );

		if ( self::startover_queue_is_empty() ) {
			update_option( RNRD_SO_RUNNING, false );
			return new WP_REST_Response( array( 'total' => $total, 'done' => $done, 'running' => false ), 200 );
		}

		// Process 1 post at a time (both summary + FAQ are heavy). The claim is
		// atomic, so the every-minute cron cannot hand us the same post.
		$post_id = self::startover_claim_next();
		$log     = array();

		if ( $post_id > 0 ) {
			$status         = self::regenerate_with_rollback( $post_id );
			$summary_status = $status['summary'];
			$faq_status     = $status['faq'];

			$done = self::startover_bump_done();

			$post  = get_post( $post_id );
			$title = $post ? $post->post_title : '#' . $post_id;

			$log[] = array(
				'id'        => $post_id,
				'title'     => $title,
				'edit_link' => get_edit_post_link( $post_id, 'raw' ),
				'summary'   => $summary_status,
				'faq'       => $faq_status,
			);
		}

		$empty = self::startover_queue_is_empty();

		if ( $empty ) {
			update_option( RNRD_SO_RUNNING, false );
		}

		return new WP_REST_Response( array(
			'total'   => $total,
			'done'    => $done,
			'running' => ! $empty,
			'log'     => $log,
		), 200 );
	}

	/**
	 * Regenerate one post's summary + FAQ, rolling back on failure.
	 *
	 * Start Over used to delete all seven `_rnrd_*` meta keys and then call the
	 * generators without inspecting either return value. A provider outage,
	 * exhausted quota or rate-limit mid-run therefore destroyed the user's
	 * existing summary and FAQ permanently while the UI still reported success.
	 * We now snapshot before deleting and restore whatever failed to regenerate,
	 * so a failed run is a no-op for that post rather than data loss.
	 *
	 * FAQ generation is NOT gated on DataForSEO credentials. DataForSEO only
	 * supplies optional keyword hints for the prompt (see RNRD_Faq::generate_faq,
	 * which gates that fetch on a non-empty keyword); the generator runs fine
	 * without it, so gating the whole feature withheld a working free feature
	 * from every user without a paid DataForSEO account.
	 *
	 * @param int    $post_id Post to regenerate.
	 * @param string $keyword Optional focus keyword for the FAQ prompt. The bulk
	 *                        path has none to offer; the single-post endpoint
	 *                        accepts one from the editor sidebar.
	 * @return array{summary:string,faq:string} Per-item status: generated|failed|restored.
	 */
	private static function regenerate_with_rollback( int $post_id, string $keyword = '' ): array {
		$meta_keys = array(
			RNRD_META_SUMMARY,
			RNRD_META_HASH,
			RNRD_META_GENERATED,
			RNRD_META_FAQ,
			RNRD_META_FAQ_HASH,
			RNRD_META_FAQ_GENERATED,
			RNRD_META_FAQ_KEYWORD,
		);

		// Snapshot before destroying anything.
		$snapshot = array();
		foreach ( $meta_keys as $key ) {
			$snapshot[ $key ] = get_post_meta( $post_id, $key, true );
		}

		// Start Over keeps the keyword the author saved for this post's FAQ; the
		// delete below would otherwise turn the regenerated FAQ into a keyword-less one.
		if ( '' === $keyword && is_string( $snapshot[ RNRD_META_FAQ_KEYWORD ] ) ) {
			$keyword = $snapshot[ RNRD_META_FAQ_KEYWORD ];
		}

		foreach ( $meta_keys as $key ) {
			delete_post_meta( $post_id, $key );
		}

		$restore = function ( array $keys ) use ( $post_id, $snapshot ) {
			foreach ( $keys as $key ) {
				if ( '' !== $snapshot[ $key ] && null !== $snapshot[ $key ] ) {
					update_post_meta( $post_id, $key, wp_slash( $snapshot[ $key ] ) );
				}
			}
		};

		// ── Summary ───────────────────────────────────────────────────────────
		$summary_result = RNRD_Generator::force_generate( $post_id );
		$summary_ok     = ( false !== $summary_result && ! is_wp_error( $summary_result ) );

		if ( ! $summary_ok ) {
			$had_summary = ( '' !== $snapshot[ RNRD_META_SUMMARY ] );
			$restore( array( RNRD_META_SUMMARY, RNRD_META_HASH, RNRD_META_GENERATED ) );
			RNRD_Generator::log_error(
				'StartOver',
				'Summary regeneration failed' . ( $had_summary ? ' — previous summary restored.' : '.' )
					. ( is_wp_error( $summary_result ) ? ' ' . $summary_result->get_error_message() : '' ),
				$post_id
			);
			$summary_status = $had_summary ? 'restored' : 'failed';
		} else {
			$summary_status = 'generated';
		}

		// ── FAQ ───────────────────────────────────────────────────────────────
		$faq    = RNRD_Faq::generate_faq( $post_id, $keyword );
		$faq_ok = ( is_array( $faq ) && ! is_wp_error( $faq ) );

		if ( ! $faq_ok ) {
			$had_faq = ( '' !== $snapshot[ RNRD_META_FAQ ] );
			$restore( array( RNRD_META_FAQ, RNRD_META_FAQ_HASH, RNRD_META_FAQ_GENERATED, RNRD_META_FAQ_KEYWORD ) );
			RNRD_Generator::log_error(
				'StartOver',
				'FAQ regeneration failed' . ( $had_faq ? ' — previous FAQ restored.' : '.' )
					. ( is_wp_error( $faq ) ? ' ' . $faq->get_error_message() : '' ),
				$post_id
			);
			$faq_status = $had_faq ? 'restored' : 'failed';
		} else {
			$faq_status = 'generated';
		}

		// Start Over rewrites the summary and the FAQ without firing save_post,
		// so /post.md would keep serving the previous pair. Same reason
		// clear_summary_meta() purges.
		self::purge_derived_output( $post_id );

		return array( 'summary' => $summary_status, 'faq' => $faq_status );
	}

	public static function startover_bulk_stop() {
		$done  = (int) get_option( RNRD_SO_DONE, 0 );
		$total = (int) get_option( RNRD_SO_TOTAL, 0 );
		$queue = (array) get_option( RNRD_SO_QUEUE, array() );
		$queue_remaining = count( $queue );

		update_option( RNRD_SO_RUNNING, false, false );
		// Keep queue intact so Resume can pick it up.

		// Clear WP-Cron schedule.
		wp_clear_scheduled_hook( RNRD_CRON_BULK_STARTOVER );

		return new WP_REST_Response( array(
			'stopped'         => true,
			'done'            => $done,
			'total'           => $total,
			'queue_remaining' => $queue_remaining,
		), 200 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// BULK SUMMARY ENDPOINTS — PRO ENGINE
	//
	// Moved to the Pro add-on (RNRD_Pro_Rest): bulk_start, bulk_process,
	// bulk_stop, bulk_status, bulk_state, plus the RNRD_CRON_BULK_SUMMARY tick.
	// The Free build keeps only single-post manual generation
	// (RNRD_Generator::force_generate) which the Pro handlers reuse.
	// ══════════════════════════════════════════════════════════════════════════

	// ══════════════════════════════════════════════════════════════════════════
	// BULK AUTHOR CHANGER ENDPOINTS
	// ══════════════════════════════════════════════════════════════════════════

	public static function author_preview( $request ) {
		$params = self::extract_author_params( $request );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$ids   = self::get_author_matching_ids( $params );
		$count = count( $ids );

		$to_user = get_userdata( $params['to_author'] );

		return new WP_REST_Response( array(
			'count'   => $count,
			'message' => sprintf(
				/* translators: 1: post count, 2: target author display name */
				_n(
					'%1$d post will be reassigned to %2$s.',
					'%1$d posts will be reassigned to %2$s.',
					$count,
					'rankready-ai-llm-seo'
				),
				$count,
				$to_user ? $to_user->display_name : '?'
			),
		), 200 );
	}

	public static function author_execute( $request ) {
		if ( get_option( RNRD_BAC_RUNNING ) ) {
			return new WP_Error( 'rnrd_already_running', __( 'An author change is already in progress. Stop it first.', 'rankready-ai-llm-seo' ), array( 'status' => 409 ) );
		}

		$params = self::extract_author_params( $request );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$ids   = self::get_author_matching_ids( $params );
		$total = count( $ids );

		if ( 0 === $total ) {
			return new WP_REST_Response( array(
				'total' => 0, 'done' => 0, 'running' => false,
				'message' => __( 'No matching posts found.', 'rankready-ai-llm-seo' ),
			), 200 );
		}

		update_option( RNRD_BAC_QUEUE,   $ids,                  false );
		update_option( RNRD_BAC_TOTAL,   $total,                false );
		update_option( RNRD_BAC_DONE,    0,                     false );
		update_option( RNRD_BAC_RUNNING, true,                  false );
		update_option( RNRD_BAC_TO,      $params['to_author'],  false );

		return new WP_REST_Response( array( 'total' => $total, 'done' => 0, 'running' => true ), 200 );
	}

	public static function author_process() {
		if ( ! get_option( RNRD_BAC_RUNNING ) ) {
			return new WP_REST_Response( self::author_state(), 200 );
		}

		$queue     = (array) get_option( RNRD_BAC_QUEUE, array() );
		$done      = (int)   get_option( RNRD_BAC_DONE,  0 );
		$total     = (int)   get_option( RNRD_BAC_TOTAL, 0 );
		$to_author = (int)   get_option( RNRD_BAC_TO,    0 );

		if ( empty( $queue ) || ! $to_author ) {
			update_option( RNRD_BAC_RUNNING, false );
			return new WP_REST_Response( array( 'total' => $total, 'done' => $done, 'running' => false ), 200 );
		}

		$batch = array_splice( $queue, 0, self::AUTHOR_BATCH );

		// Temporarily unhook summary generation to prevent cascading API calls
		// during bulk author changes (author change doesn't change content).
		// The auto-gen hook is a Pro engine — only present when the Pro add-on
		// is active. Capture whether it was hooked so we restore it only then;
		// in the Free build it is never registered, so this is a clean no-op.
		$rnrd_autogen_was_hooked = false !== has_action( 'wp_after_insert_post', array( 'RNRD_Generator', 'schedule_generation' ) );
		if ( $rnrd_autogen_was_hooked ) {
			remove_action( 'wp_after_insert_post', array( 'RNRD_Generator', 'schedule_generation' ), 10 );
		}

		$skipped = 0;
		foreach ( $batch as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id < 1 ) {
				continue;
			}
			// The route gate is the blanket edit_others_posts, but the queue
			// spans every status and every allowed post type. A CPT with its
			// own capability_type, or a custom role, can hold edit_others_posts
			// without being allowed to edit this post, so re-check per object
			// (same pattern as RNRD_Freshness::rest_refresh()). Skipped posts
			// still advance $done so the progress bar completes.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$skipped++;
				$done++;
				continue;
			}
			RNRD_Util::update_post_fields( array(
				'ID'          => $post_id,
				'post_author' => $to_author,
			) );
			$done++;
		}

		// Re-hook summary generation only if it was hooked before (Pro active).
		if ( $rnrd_autogen_was_hooked ) {
			add_action( 'wp_after_insert_post', array( 'RNRD_Generator', 'schedule_generation' ), 10, 4 );
		}

		update_option( RNRD_BAC_QUEUE, $queue, false );
		update_option( RNRD_BAC_DONE,  $done,  false );

		$still_running = ! empty( $queue );
		if ( ! $still_running ) {
			update_option( RNRD_BAC_RUNNING, false );
		}

		return new WP_REST_Response( array( 'total' => $total, 'done' => $done, 'running' => $still_running, 'skipped' => $skipped ), 200 );
	}

	public static function author_stop() {
		update_option( RNRD_BAC_RUNNING, false );
		update_option( RNRD_BAC_QUEUE,   array() );
		return new WP_REST_Response( array_merge( array( 'stopped' => true ), self::author_state() ), 200 );
	}

	private static function author_state(): array {
		return array(
			'total'   => (int)  get_option( RNRD_BAC_TOTAL,   0 ),
			'done'    => (int)  get_option( RNRD_BAC_DONE,    0 ),
			'running' => (bool) get_option( RNRD_BAC_RUNNING, false ),
		);
	}

	private static function extract_author_params( $request ) {
		$raw_types   = (array) $request->get_param( 'post_types' );
		$to_author   = (int)   $request->get_param( 'to_author' );
		$from_author = (int)   $request->get_param( 'from_author' );
		$date_from   = sanitize_text_field( (string) $request->get_param( 'date_from' ) );
		$date_to     = sanitize_text_field( (string) $request->get_param( 'date_to' ) );

		$allowed    = array_keys( RNRD_Admin::get_allowed_post_types() );
		$post_types = array_values( array_intersect( array_map( 'sanitize_key', $raw_types ), $allowed ) );

		if ( empty( $post_types ) ) {
			return new WP_Error( 'rnrd_no_post_types', __( 'Select at least one post type.', 'rankready-ai-llm-seo' ), array( 'status' => 400 ) );
		}

		// A subscriber as post author is a valid user id and an invalid author:
		// the posts would show a byline (and Person schema) for someone who cannot write.
		if ( ! $to_author || ! user_can( $to_author, 'edit_posts' ) ) {
			return new WP_Error( 'rnrd_invalid_author', __( 'Invalid target author. Choose a user who can write posts.', 'rankready-ai-llm-seo' ), array( 'status' => 400 ) );
		}

		if ( $from_author && ! get_userdata( $from_author ) ) {
			return new WP_Error( 'rnrd_invalid_from_author', __( 'Invalid source author.', 'rankready-ai-llm-seo' ), array( 'status' => 400 ) );
		}

		if ( $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = '';
		}
		if ( $date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = '';
		}

		return compact( 'post_types', 'to_author', 'from_author', 'date_from', 'date_to' );
	}

	private static function get_author_matching_ids( array $params ): array {
		$query_args = array(
			'post_type'              => $params['post_types'],
			'post_status'            => 'any',
			'posts_per_page'         => 10000, // Cap to prevent memory exhaustion on large sites.
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		);

		if ( ! empty( $params['from_author'] ) ) {
			$query_args['author'] = $params['from_author'];
		}

		if ( ! empty( $params['date_from'] ) || ! empty( $params['date_to'] ) ) {
			$date_query = array( 'inclusive' => true );
			if ( ! empty( $params['date_from'] ) ) {
				$date_query['after'] = $params['date_from'];
			}
			if ( ! empty( $params['date_to'] ) ) {
				$date_query['before'] = $params['date_to'];
			}
			$query_args['date_query'] = array( $date_query );
		}

		return (array) get_posts( $query_args );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// LLMS CACHE FLUSH
	// ══════════════════════════════════════════════════════════════════════════

	public static function llms_flush_cache() {
		if ( class_exists( 'RNRD_Llms_Txt' ) ) {
			RNRD_Llms_Txt::bust_cache_and_purge_cdn();
		} else {
			delete_transient( RNRD_LLMS_CACHE_KEY );
			delete_transient( RNRD_LLMS_FULL_CACHE_KEY );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Cache cleared.', 'rankready-ai-llm-seo' ),
			),
			200
		);
	}

	// ══════════════════════════════════════════════════════════════════════════
	// API KEY VERIFICATION (OpenAI-specific)
	// ──────────────────────────────────────────────────────────────────────────
	// Bound to the "Verify Key" button on the OpenAI card in Settings. Hits
	// the OpenAI `/v1/models` list endpoint to confirm the key. For other
	// providers (Claude / Gemini / DeepSeek), users save the key and use the
	// connection test pathway, which routes through RNRD_LLM::generate() and
	// is provider-agnostic. Adding a generic per-provider verify is a v1.1.2
	// follow-up — kept narrow here to not reshape working UI.
	// ══════════════════════════════════════════════════════════════════════════

	public static function verify_api_key( $request ) {
		$key      = (string) $request->get_param( 'key' );
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		if ( '' === $provider ) {
			$provider = 'openai'; // back-compat with the original endpoint
		}

		// Provider → (stored option key, validation endpoint, header builder).
		$providers = array(
			'openai'    => array(
				'verify'  => function ( $key, $model = '' ) {
					// TC-KEY-04: probe the REAL generation path (chat/completions with the same
					// 'max_completion_tokens' parameter generation uses) so Verify fails loudly when
					// generation would fail. A /v1/models auth ping passes even when the model
					// rejects the request body — that was the false positive QA caught.
					$model = sanitize_text_field( (string) $model );
					if ( '' === $model && class_exists( 'RNRD_LLM' ) && method_exists( 'RNRD_LLM', 'get_model' ) ) {
						$model = RNRD_LLM::get_model( 'openai' );
					}
					if ( '' === $model ) {
						$model = 'gpt-5.4-mini';
					}
					return wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
						'timeout' => 15,
						'headers' => array(
							'Authorization' => 'Bearer ' . $key,
							'Content-Type'  => 'application/json',
						),
						'body' => wp_json_encode( array(
							'model'                 => $model,
							// GPT-5.x reasoning counts against this budget; 1 is too
							// low and returns "max_tokens or model output limit".
							'max_completion_tokens' => 64,
							'messages'              => array( array( 'role' => 'user', 'content' => 'hi' ) ),
						) ),
					) );
				},
			),
			'anthropic' => array(
				'verify'  => function ( $key, $model = '' ) {
					// Tiny messages call — `model` is required, 1-token output keeps cost trivial.
					return wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
						'timeout' => 15,
						'headers' => array(
							'x-api-key'         => $key,
							'anthropic-version' => '2023-06-01',
							'content-type'      => 'application/json',
						),
						// Cheapest valid Anthropic ID for an auth probe. Update
						// this when Haiku ships a new generation — Anthropic
						// has no evergreen alias, every ID is a pinned snapshot.
						'body' => wp_json_encode( array(
							'model'      => 'claude-haiku-4-5',
							'max_tokens' => 1,
							'messages'   => array( array( 'role' => 'user', 'content' => 'hi' ) ),
						) ),
					) );
				},
			),
			'gemini'    => array(
				'verify'  => function ( $key, $model = '' ) {
					// `models` list endpoint — cheapest valid auth check for AI Studio keys.
					// Key in a header, never in the query string — see RNRD_LLM_Gemini.
					return wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models', array(
						'timeout' => 15,
						'headers' => array( 'x-goog-api-key' => $key ),
					) );
				},
			),
			'deepseek'  => array(
				'verify'  => function ( $key, $model = '' ) {
					return wp_remote_get( 'https://api.deepseek.com/v1/models', array(
						'headers' => array( 'Authorization' => 'Bearer ' . $key ),
						'timeout' => 15,
					) );
				},
			),
		);

		if ( ! isset( $providers[ $provider ] ) ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => __( 'Unknown provider.', 'rankready-ai-llm-seo' ),
			), 200 );
		}

		$cfg = $providers[ $provider ];

		$key = class_exists( 'RNRD_LLM' )
			? RNRD_LLM::resolve_api_key( $provider, (string) $request->get_param( 'key' ) )
			: (string) $request->get_param( 'key' );

		if ( empty( $key ) ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => __( 'No API key stored for this provider.', 'rankready-ai-llm-seo' ),
			), 200 );
		}

		$response = call_user_func( $cfg['verify'], $key, sanitize_text_field( (string) $request->get_param( 'model' ) ) );

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => $response->get_error_message(),
			), 200 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( 200 === $code ) {
			// A 200 alone is not proof. Corporate proxies, captive portals and
			// WAF interstitials all answer 200 with an HTML page (or plain
			// text), and a couple of providers return 200 with an error object
			// in the body. Any of these would have been reported to the user
			// as "valid and working" and then failed on the first real
			// generation, so the body must both parse as JSON AND contain the
			// field this provider's endpoint is actually documented to return
			// — not just "some JSON came back".
			$ok_body = json_decode( $raw, true );

			if ( is_array( $ok_body ) && isset( $ok_body['error'] ) ) {
				$msg = '';
				if ( isset( $ok_body['error']['message'] ) ) {
					$msg = (string) $ok_body['error']['message'];
				} elseif ( is_string( $ok_body['error'] ) ) {
					$msg = (string) $ok_body['error'];
				}
				return new WP_REST_Response( array(
					'valid'   => false,
					'message' => '' !== $msg ? $msg : __( 'The provider returned an error.', 'rankready-ai-llm-seo' ),
				), 200 );
			}

			if ( ! is_array( $ok_body ) || ! self::verify_response_has_expected_shape( $provider, $ok_body ) ) {
				return new WP_REST_Response( array(
					'valid'   => false,
					'message' => __( 'The provider replied with an unexpected response instead of API data. A proxy, firewall or captive portal may be intercepting the request.', 'rankready-ai-llm-seo' ),
				), 200 );
			}

			return new WP_REST_Response( array(
				'valid'   => true,
				'message' => __( 'API key is valid and working.', 'rankready-ai-llm-seo' ),
			), 200 );
		}

		$body = json_decode( $raw, true );
		// Each provider nests its error message differently — try the common
		// shapes before falling back to a generic HTTP status.
		$err = '';
		if ( isset( $body['error']['message'] ) )      { $err = (string) $body['error']['message']; }       // OpenAI / DeepSeek / Gemini
		elseif ( isset( $body['error'] ) && is_string( $body['error'] ) ) { $err = (string) $body['error']; }
		elseif ( isset( $body['message'] ) )           { $err = (string) $body['message']; }                 // Anthropic uses { type, message }
		if ( '' === $err ) {
			$err = sprintf( /* translators: %d: HTTP status code */ __( 'Verification failed (HTTP %d).', 'rankready-ai-llm-seo' ), $code );
		}

		return new WP_REST_Response( array(
			'valid'   => false,
			'message' => $err,
		), 200 );
	}

	/**
	 * Whether a 200-OK verify-key response body actually has the shape this
	 * provider's endpoint is documented to return, not just "some JSON".
	 *
	 * A captive portal / corporate proxy / WAF can answer 200 with a JSON body
	 * that isn't the provider's real payload (a status page, a login-required
	 * object, an empty `{}`) just as easily as with an HTML interstitial — the
	 * HTML-sniffing check above catches only the latter. This checks for the
	 * field each endpoint is documented to always include on success:
	 *   - OpenAI chat/completions  -> `choices` (non-empty array)
	 *   - Anthropic messages       -> `content` (array)
	 *   - Gemini  models list      -> `models`  (array) — same field
	 *     RNRD_LLM::extract_model_ids() reads from the same endpoint.
	 *   - DeepSeek models list     -> `data`    (array) — same field
	 *     RNRD_LLM::extract_model_ids() reads from the same endpoint.
	 *
	 * @param string               $provider Provider ID.
	 * @param array<string,mixed>  $body     Decoded JSON response body.
	 */
	private static function verify_response_has_expected_shape( string $provider, array $body ): bool {
		switch ( $provider ) {
			case 'openai':
				return isset( $body['choices'] ) && is_array( $body['choices'] ) && ! empty( $body['choices'] );
			case 'anthropic':
				return isset( $body['content'] ) && is_array( $body['content'] );
			case 'gemini':
				return isset( $body['models'] ) && is_array( $body['models'] );
			case 'deepseek':
				return isset( $body['data'] ) && is_array( $body['data'] );
			default:
				return false;
		}
	}

	/**
	 * Force-refresh the model dropdown list for a provider (bypasses transient cache).
	 */
	public static function refresh_models( $request ) {
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$key      = (string) $request->get_param( 'key' );

		if ( ! class_exists( 'RNRD_LLM' ) ) {
			return new WP_REST_Response( array(
				'ok'      => false,
				'models'  => array(),
				'source'  => 'fallback',
				'count'   => 0,
				'message' => __( 'LLM module not available.', 'rankready-ai-llm-seo' ),
			), 200 );
		}

		$result = RNRD_LLM::refresh_models_for( $provider, $key );

		return new WP_REST_Response( array(
			'ok'      => (bool) $result['ok'],
			'models'  => $result['models'],
			'source'  => (string) $result['source'],
			'count'   => (int) $result['count'],
			'message' => (string) $result['message'],
		), 200 );
	}

	// ── DataForSEO Key Verification ──────────────────────────────────────────

	public static function verify_dfs_key( $request = null ) {
		$login    = (string) get_option( RNRD_OPT_DFS_LOGIN, '' );
		$password = (string) get_option( RNRD_OPT_DFS_PASSWORD, '' );

		// Allow passing password from the form for testing before save.
		if ( $request && $request->get_param( 'password' ) ) {
			$password = (string) $request->get_param( 'password' );
		}
		if ( $request && $request->get_param( 'login' ) ) {
			$login = (string) $request->get_param( 'login' );
		}

		if ( empty( $login ) || empty( $password ) ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => __( 'DataForSEO login or password not configured.', 'rankready-ai-llm-seo' ),
			), 200 );
		}

		// Use a lightweight endpoint to test credentials.
		$response = wp_remote_get( 'https://api.dataforseo.com/v3/appendix/user_data', array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $login . ':' . $password ),
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => $response->get_error_message(),
			), 200 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Guard the leaf key, not just `money` — a shape change would otherwise warn
		// (or throw on a string offset) inside the REST callback.
		if ( 200 === $code && isset( $body['tasks'][0]['result'][0]['money']['balance'] ) ) {
			$balance = $body['tasks'][0]['result'][0]['money']['balance'];

			/*
			 * Verify does not write. Until 2026-09-26 a successful verify called
			 * update_option() on both credentials, so a button labelled "Verify
			 * DataForSEO" silently replaced the stored login and password with
			 * whatever happened to be in the form — including when the admin was
			 * only testing a colleague's credentials, or typed a value and then
			 * navigated away without saving. There was no undo, because the old
			 * value was gone.
			 *
			 * The fields are real registered settings (see
			 * RNRD_Admin::register_settings, sanitize_dfs_login /
			 * sanitize_dfs_password), so the form's own Save is what persists
			 * them, and sanitize_dfs_password() already leaves the stored secret
			 * alone when the masked bullet value is posted back.
			 */
			return new WP_REST_Response( array(
				'valid'   => true,
				/* translators: %s: DataForSEO account balance in USD, e.g. "12.34" */
				'message' => sprintf( __( 'Credentials valid. Balance: $%s. Save changes to store them.', 'rankready-ai-llm-seo' ), number_format( (float) $balance, 2 ) ),
			), 200 );
		}

		$err = isset( $body['status_message'] ) ? $body['status_message'] : __( 'Invalid credentials.', 'rankready-ai-llm-seo' );
		return new WP_REST_Response( array(
			'valid'   => false,
			'message' => $err,
		), 200 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// FAQ ENDPOINTS
	// ══════════════════════════════════════════════════════════════════════════

	public static function faq_generate( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$keyword = sanitize_text_field( $request->get_param( 'keyword' ) );
		$count   = (int) $request->get_param( 'count' );

		// Cooldown: prevent rapid-fire FAQ generation for the same post. Same
		// two-stamp read as the summary endpoint, for the same reason.
		$last  = max(
			(int) get_post_meta( $post_id, RNRD_META_FAQ_GENERATED, true ),
			(int) get_post_meta( $post_id, RNRD_META_FAQ_COOLDOWN, true )
		);
		$since = time() - $last;
		if ( $last && $since < self::REGEN_COOLDOWN ) {
			return new WP_Error(
				'rnrd_rate_limited',
				/* translators: %d: remaining seconds before regeneration is allowed */
				sprintf( __( 'Please wait %d more seconds before regenerating.', 'rankready-ai-llm-seo' ), self::REGEN_COOLDOWN - $since ),
				array( 'status' => 429 )
			);
		}

		$result = RNRD_Faq::generate_faq( $post_id, $keyword, $count );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( 'no_api_key' === $code ) {
				$rnrd_prov = RNRD_LLM::get_provider_label( RNRD_LLM::get_active_provider() );
				/* translators: %s: AI provider name */
				$message = sprintf( __( 'No %s API key yet. Add it under RankReady → Settings → API Keys, then generate.', 'rankready-ai-llm-seo' ), $rnrd_prov );
			} elseif ( 'invalid_post' === $code ) {
				$message = __( 'This post no longer exists. Save the page, then try again.', 'rankready-ai-llm-seo' );
			} elseif ( 'type_disabled' === $code ) {
				$message = (string) $result->get_error_message();
			} else {
				$message = self::friendly_generation_error( (string) $result->get_error_message() );
			}
			return new WP_REST_Response( array(
				'success' => false,
				'message' => $message,
			), 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'faq'     => $result,
			'count'   => count( $result ),
		), 200 );
	}

	/**
	 * Remove generated FAQ meta for a single post (no regeneration).
	 */
	public static function delete_faq( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'rnrd_invalid_post', __( 'This post no longer exists.', 'rankready-ai-llm-seo' ), array( 'status' => 404 ) );
		}

		self::clear_faq_meta( $post_id );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	public static function faq_get( $request ) {
		$post_id  = (int) $request->get_param( 'id' );
		$faq_data = RNRD_Faq::get_faq_data( $post_id );

		$generated_ts  = (int) get_post_meta( $post_id, RNRD_META_FAQ_GENERATED, true );
		$generated_str = '';
		if ( $generated_ts > 0 ) {
			$generated_str = wp_date( get_option( 'date_format' ), $generated_ts );
		}

		return new WP_REST_Response( array(
			'success'   => true,
			'faq'       => $faq_data,
			'keyword'   => (string) get_post_meta( $post_id, RNRD_META_FAQ_KEYWORD, true ),
			'generated' => $generated_str,
			'disabled'  => (bool) get_post_meta( $post_id, RNRD_META_FAQ_DISABLE, true ),
			'autogen_pending' => RNRD_Generator::autogen_pending( 'faq', $post_id ),
			'autogen_error'   => RNRD_Generator::autogen_error( 'faq', $post_id ),
		), 200 );
	}

	public static function faq_save( $request ) {
		$post_id  = (int) $request->get_param( 'id' );
		$faq_data = $request->get_json_params();

		if ( ! isset( $faq_data['faq'] ) || ! is_array( $faq_data['faq'] ) ) {
			return new WP_Error( 'rnrd_invalid_faq', __( 'Invalid FAQ data.', 'rankready-ai-llm-seo' ), array( 'status' => 400 ) );
		}

		$clean = array();
		foreach ( $faq_data['faq'] as $item ) {
			if ( ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
				$clean[] = array(
					'question' => sanitize_text_field( $item['question'] ),
					'answer'   => wp_kses_post( $item['answer'] ),
				);
			}
		}

		// A non-empty payload where no row has both a question and an answer is a
		// malformed request, not "clear the FAQ": refuse it rather than wipe a good FAQ.
		if ( empty( $clean ) && ! empty( $faq_data['faq'] ) ) {
			return new WP_Error( 'rnrd_invalid_faq', __( 'Every FAQ row needs a question and an answer. Nothing was changed.', 'rankready-ai-llm-seo' ), array( 'status' => 400 ) );
		}

		if ( empty( $clean ) ) {
			// Clearing every row must REMOVE the meta, not store "[]".
			// wp_json_encode( array() ) is the two-character string "[]", which is
			// not empty() — so every presence check (posts-list column, meta-box
			// banner, block enqueue, Insights) would keep reporting "FAQ exists"
			// for a post with no FAQ. Worse, RNRD_Faq::generate_faq() short-circuits
			// on `hash matches && ! empty( meta )`, so leaving "[]" plus a stale hash
			// made "Generate FAQ" silently return nothing until the content changed.
			self::clear_faq_content_meta( $post_id );

			return new WP_REST_Response( array( 'success' => true, 'count' => 0 ), 200 );
		}

		// JSON_UNESCAPED_UNICODE preserves non-Latin characters as UTF-8 in
		// post meta — see class-rnrd-generator.php for full rationale.
		// wp_slash: update_post_meta() unslashes, which would strip the escapes out of the JSON and store it invalid.
		update_post_meta( $post_id, RNRD_META_FAQ, wp_slash( wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		update_post_meta( $post_id, RNRD_META_FAQ_GENERATED, time() );
		// Editing rows changes what /post.md serves, and this endpoint fires no
		// save_post, so the URL cache has to be busted here as well.
		self::purge_derived_output( $post_id );

		return new WP_REST_Response( array( 'success' => true, 'count' => count( $clean ) ), 200 );
	}

	// ── FAQ Bulk — PRO ENGINE ──────────────────────────────────────────────────
	// faq_bulk_start / faq_bulk_process / faq_bulk_stop and the RNRD_CRON_BULK_FAQ
	// tick moved to the Pro add-on (RNRD_Pro_Rest). The Free build keeps only
	// single-post manual FAQ generation (RNRD_Faq::generate_faq), which the Pro
	// handlers reuse.

	// ══════════════════════════════════════════════════════════════════════════
	// FAQ POSTS LIST
	// ══════════════════════════════════════════════════════════════════════════

	public static function faq_posts_list() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live count for fresh page render; cache invalidation cost exceeds query cost.
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type, pm2.meta_value AS faq_generated
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value != ''
			 LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = %s
			 WHERE p.post_status = 'publish'
			 ORDER BY pm2.meta_value DESC
			 LIMIT 200",
			RNRD_META_FAQ,
			RNRD_META_FAQ_GENERATED
		) );

		$posts = array();
		foreach ( $results as $row ) {
			$generated = ! empty( $row->faq_generated ) ? wp_date( get_option( 'date_format' ), (int) $row->faq_generated ) : '';
			$posts[]   = array(
				'id'        => (int) $row->ID,
				'title'     => $row->post_title,
				'type'      => $row->post_type,
				'generated' => $generated,
				'edit_url'  => get_edit_post_link( $row->ID, 'raw' ),
				'view_url'  => get_permalink( $row->ID ),
			);
		}

		return new WP_REST_Response( array(
			'posts' => $posts,
			'total' => count( $posts ),
		), 200 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// ERROR LOG ENDPOINTS
	// ══════════════════════════════════════════════════════════════════════════

	public static function get_errors() {
		$log = RNRD_Generator::get_error_log();
		// Reverse so newest first.
		$log = array_reverse( $log );

		// Add human-readable time.
		foreach ( $log as &$entry ) {
			/* translators: %s: human-readable time difference, e.g. "3 hours". */
			$entry['time_ago'] = sprintf( __( '%s ago', 'rankready-ai-llm-seo' ), human_time_diff( $entry['time'] ) );
			$entry['date']     = wp_date( 'Y-m-d H:i:s', $entry['time'] );
		}
		unset( $entry );

		return new WP_REST_Response( array( 'errors' => $log ), 200 );
	}

	public static function clear_errors() {
		RNRD_Generator::clear_error_log();
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	// ── Token Usage per post ─────────────────────────────────────────────────

	public static function get_token_usage() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same as line 1475 — bulk meta_key read on own keys.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value AS tokens, p.post_title, p.post_type
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND pm.meta_value > 0
			 ORDER BY CAST(pm.meta_value AS UNSIGNED) DESC
			 LIMIT 100",
			'_rnrd_tokens_used'
		) );

		$posts = array();
		foreach ( $rows as $row ) {
			$posts[] = array(
				'id'     => (int) $row->post_id,
				'title'  => $row->post_title,
				'type'   => $row->post_type,
				'tokens' => (int) $row->tokens,
				'link'   => get_permalink( (int) $row->post_id ),
				'edit'   => get_edit_post_link( (int) $row->post_id, 'raw' ),
			);
		}

		// Legacy option plus atomic counters, via the one reader that knows about
		// both. Reading the raw option here would under-report every generation
		// since 1.4.0.
		$totals = RNRD_Generator::get_token_usage();

		return new WP_REST_Response( array(
			'posts'          => $posts,
			'summary_tokens' => (int) $totals['summary_tokens'],
			'faq_tokens'     => (int) $totals['faq_tokens'],
			'total_calls'    => (int) $totals['total_calls'],
		), 200 );
	}

	// ── Content Freshness Alerts ─────────────────────────────────────────────

	public static function content_freshness( $request ) {
		$days        = max( 30, (int) $request->get_param( 'days' ) );
		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Get post types that have summaries or FAQs enabled.
		$summary_types = (array) get_option( RNRD_OPT_POST_TYPES, array( 'post' ) );
		$faq_types     = (array) get_option( RNRD_OPT_FAQ_POST_TYPES, array( 'post' ) );
		$all_types     = array_unique( array_merge( $summary_types, $faq_types ) );

		if ( empty( $all_types ) ) {
			return new WP_REST_Response( array( 'stale' => array(), 'summary' => array() ), 200 );
		}

		$posts = get_posts( array(
			'post_type'      => $all_types,
			'post_status'    => 'publish',
			'date_query'     => array(
				array( 'column' => 'post_modified', 'before' => $cutoff_date ),
			),
			'orderby'        => 'modified',
			'order'          => 'ASC',
			'posts_per_page' => 50,
			'fields'         => 'ids',
		) );

		$stale = array();
		foreach ( $posts as $pid ) {
			$post       = get_post( $pid );
			$modified   = strtotime( $post->post_modified );
			$days_ago   = (int) floor( ( time() - $modified ) / DAY_IN_SECONDS );
			$has_summary = ! empty( get_post_meta( $pid, RNRD_META_SUMMARY, true ) );
			$has_faq     = ! empty( get_post_meta( $pid, RNRD_META_FAQ, true ) );

			$urgency = 'moderate';
			if ( $days_ago > 365 ) {
				$urgency = 'critical';
			} elseif ( $days_ago > 180 ) {
				$urgency = 'high';
			}

			$stale[] = array(
				'id'          => $pid,
				'title'       => get_the_title( $pid ),
				'type'        => $post->post_type,
				'modified'    => $post->post_modified,
				'days_ago'    => $days_ago,
				'urgency'     => $urgency,
				'has_summary' => $has_summary,
				'has_faq'     => $has_faq,
				'edit_url'    => get_edit_post_link( $pid, 'raw' ),
				'view_url'    => get_permalink( $pid ),
			);
		}

		// Summary stats. $type_placeholders contains only "%s,%s,%s" tokens
		// generated from array_fill — never user input. Actual slugs are
		// passed as prepare() args. Safe IN() clause pattern.
		global $wpdb;
		$type_placeholders = implode( ',', array_fill( 0, count( $all_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded COUNT(*) across $wpdb->posts with prepared placeholders.
		$total_published = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status = %s",
			array_merge( $all_types, array( 'publish' ) )
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic IN() placeholders from array_fill; count matches array_merge args at runtime.
		$total_stale = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status = %s AND post_modified < %s",
			array_merge( $all_types, array( 'publish', $cutoff_date ) )
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return new WP_REST_Response( array(
			'stale'   => $stale,
			'summary' => array(
				'total_published' => $total_published,
				'total_stale'     => $total_stale,
				'threshold_days'  => $days,
				'fresh_pct'       => $total_published > 0 ? round( ( ( $total_published - $total_stale ) / $total_published ) * 100, 1 ) : 100,
			),
		), 200 );
	}

	// ── Health Check Diagnostic ──────────────────────────────────────────────


	// ── Common arg schemas ────────────────────────────────────────────────────

	private static function post_id_arg(): array {
		return array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
		);
	}

	private static function author_args(): array {
		return array(
			'post_types' => array(
				'required'          => true,
				'type'              => 'array',
				'items'             => array( 'type' => 'string' ),
				'sanitize_callback' => function ( $v ) { return array_map( 'sanitize_key', (array) $v ); },
			),
			'to_author' => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'from_author' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'date_from' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_to' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SCHEMA SCANNER ENDPOINTS — PRO ENGINE
	//
	// schema_status / schema_recommendation (which call the moved
	// RNRD_Pro_Schema::get_server_recommendation) live in the Pro add-on
	// (RNRD_Pro_Rest).
	// ══════════════════════════════════════════════════════════════════════════

	// ══════════════════════════════════════════════════════════════════════════
	// WP-CRON BULK TICKS — process queue items even after browser close
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * WP-Cron tick for Start Over bulk — processes 1 post per tick.
	 * Runs every minute. Clears itself when queue is empty or stopped.
	 */
	public static function cron_startover_tick(): void {
		if ( ! get_option( RNRD_SO_RUNNING ) ) {
			wp_clear_scheduled_hook( RNRD_CRON_BULK_STARTOVER );
			return;
		}

		if ( self::startover_queue_is_empty() ) {
			update_option( RNRD_SO_RUNNING, false, false );
			wp_clear_scheduled_hook( RNRD_CRON_BULK_STARTOVER );
			return;
		}

		// Process 1 post per cron tick (summary + FAQ are heavy API calls). Same
		// atomic claim the REST tick uses, so whichever of the two gets here
		// first takes the post and the other one sees the queue already moved on.
		$post_id = self::startover_claim_next();

		if ( $post_id > 0 ) {
			// Same rollback-protected path the REST tick uses. This runs after the
			// user has closed the browser, so a silent failure here is the worst
			// case: nobody is watching. Failures are logged and data is restored.
			self::regenerate_with_rollback( $post_id );
			self::startover_bump_done();
		}

		if ( self::startover_queue_is_empty() ) {
			update_option( RNRD_SO_RUNNING, false, false );
			wp_clear_scheduled_hook( RNRD_CRON_BULK_STARTOVER );
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// cron_faq_tick() and cron_summary_tick() are PRO ENGINE cron handlers —
	// moved to the Pro add-on (RNRD_Pro_Rest). Only cron_startover_tick() (the
	// Start Over bulk operation) remains in the Free build above.
	// ──────────────────────────────────────────────────────────────────────────
}
