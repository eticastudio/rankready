<?php
/**
 * RankReady — Onboarding Wizard (3-step, progress bar).
 *
 * Step 1 — Brand Identity: site name, one-line summary, about paragraph.
 * Step 2 — AI features:     enable/disable Visibility + Content toggles.
 * Step 3 — Done:           confirmation + redirect to Dashboard.
 *
 * Triggers once on first activation via a transient flag. Power users
 * can re-run it from the Dashboard via the "Re-run the setup wizard" link.
 *
 * @package RankReady
 * @since   1.0.3
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Welcome {

	private const MENU_SLUG     = 'rankready-welcome';
	private const SETTINGS_SLUG = 'rankready-ai-llm-seo';
	private const FLAG_OPTION   = 'rnrd_welcome_completed';
	/**
	 * Set the first time the wizard is shown automatically. Until 1.4.0 the
	 * main page bounced to the wizard on EVERY visit before completion or Skip,
	 * so someone who left the wizard through the sidebar could not reach a
	 * single settings tab. It now takes over the page once, as documented.
	 */
	private const PROMPTED_OPTION = 'rnrd_welcome_prompted';

	private const REDIRECT_KEY  = 'rnrd_welcome_redirect';

	/** Hook suffix of the wizard screen, set in register_page(). */
	private static string $hook_suffix = '';

	/**
	 * HostMyBlog subscribe endpoint for the RankReady tips list. Contacted ONLY
	 * when the admin opts in — see subscribe_email(). Overridable via the
	 * `rnrd_tips_webhook_url` filter. Disclosed in readme.txt "External services".
	 */
	private const TIPS_WEBHOOK_URL = 'https://webhook.hostmy.blog/subscribe.php';

	/**
	 * Per-admin "already subscribed" flag (user_meta). Replaces the old site-wide
	 * option `rnrd_tips_optin_sent` so each admin can opt in independently.
	 */
	private const TIPS_FLAG_META = 'rnrd_tips_optin_sent';

	// ── Lifecycle ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_menu',  array( self::class, 'register_page' ) );
		add_action( 'admin_init',  array( self::class, 'maybe_redirect' ) );
		add_action( 'admin_init',  array( self::class, 'maybe_handle_submit' ) );
		add_action( 'admin_init',  array( self::class, 'maybe_migrate_tips_flag' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * One-shot: drop the legacy site-wide tips flag so other admins can still
	 * see the opt-in. Tracking is now per logged-in admin via user_meta.
	 */
	public static function maybe_migrate_tips_flag(): void {
		// admin_init fires for every logged-in role; only an admin should
		// trigger this one-shot option write, same as the handlers beside it.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( false === get_option( 'rnrd_tips_optin_sent', false ) ) {
			return;
		}
		delete_option( 'rnrd_tips_optin_sent' );
	}

	/**
	 * Called from the activation hook in rankready.php.
	 * Sets a one-shot transient that triggers a redirect on the next admin load:
	 * fresh installs → onboarding wizard; re-activations → main settings page.
	 */
	public static function flag_activation(): void {
		$target = get_option( self::FLAG_OPTION ) ? 'settings' : 'welcome';
		set_transient( self::REDIRECT_KEY, $target, 30 );
	}

	/**
	 * Onboarding gate. Runs on admin_init. Three triggers, all one-time and
	 * gated by the FLAG_OPTION so the wizard is shown exactly once per site:
	 *
	 *   0. Freemius connect first — while Freemius is still in activation mode
	 *      (not opted-in and not skipped), do not redirect away from the main
	 *      RankReady page. Freemius only renders connect.php there; sending
	 *      users to rankready-welcome makes the opt-in unreachable. After
	 *      Allow/Skip, first-path lands on the main page and trigger 2 runs.
	 *   1. Activation → immediate redirect (one-shot transient): onboarding
	 *      when the wizard has never been completed/skipped, otherwise the
	 *      main RankReady settings page.
	 *   2. Existing/updated users who never onboarded → redirected the FIRST
	 *      time they OPEN a RankReady admin page (page=rankready-ai-llm-seo).
	 *      NOT forced on the update itself or on any other admin screen.
	 *   3. "Skip" action → marks onboarding seen (sets the flag) so it never
	 *      reappears, then lands the user on the Dashboard.
	 *
	 * Once FLAG_OPTION is in the DB (completed OR skipped) the wizard never
	 * shows again. Mirrors the setup-wizard pattern of Yoast / Rank Math.
	 */
	public static function maybe_redirect(): void {
		if ( ! self::can_redirect() ) {
			// Consume a lingering activation transient even when we can't act,
			// so it never fires later in the wrong context.
			delete_transient( self::REDIRECT_KEY );
			return;
		}

		// Freemius owns admin.php?page=rankready-ai-llm-seo until the site
		// opts in or skips. Diverting to rankready-welcome makes the connect
		// UI unreachable (it only renders on the main plugin page).
		if ( self::freemius_needs_connect() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			if ( self::MENU_SLUG === $page ) {
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) );
				exit;
			}
			return;
		}

		// 3. Skip — mark seen, then continue to the Dashboard. Nonce-protected.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['rnrd_onboard_skip'], $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rnrd_onboard_skip' ) ) {
			if ( ! get_option( self::FLAG_OPTION ) ) {
				update_option( self::FLAG_OPTION, time(), false );
			}
			delete_transient( self::REDIRECT_KEY );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) );
			exit;
		}

		// 1. Activation — one-shot transient → onboarding or settings page.
		$activation_target = get_transient( self::REDIRECT_KEY );
		if ( $activation_target ) {
			delete_transient( self::REDIRECT_KEY );
			if ( 'settings' === $activation_target ) {
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) );
			} else {
				update_option( self::PROMPTED_OPTION, time(), false );
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			}
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 1;
		$step = max( 1, min( 3, $step ) );

		// Congratulations is only valid after step 2 wrote FLAG_OPTION.
		// A bookmark or guessed ?step=3 must not claim the site is set up.
		if ( self::MENU_SLUG === $page && 3 === $step && ! get_option( self::FLAG_OPTION ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&step=2' ) );
			exit;
		}

		// Opening the wizard by hand (menu, bookmark) counts as being shown it.
		// Without this, a user who reaches the wizard that way and follows its
		// own "AI Content" link is bounced straight back by trigger 2 below.
		if ( self::MENU_SLUG === $page && ! get_option( self::PROMPTED_OPTION ) ) {
			update_option( self::PROMPTED_OPTION, time(), false );
		}

		// Already onboarded — no further automatic redirects.
		if ( get_option( self::FLAG_OPTION ) ) {
			return;
		}

		// 2. Existing/updated user opening RankReady for the first time without
		//    having onboarded — send them through the wizard once. Only when
		//    they land on the main RankReady page (never the wizard page itself,
		//    never any unrelated admin screen, never during the update process).
		//    Once means once: after that the wizard stays one click away in the
		//    dashboard, and the settings are reachable without finishing it.
		if ( self::SETTINGS_SLUG === $page && ! get_option( self::PROMPTED_OPTION ) ) {
			update_option( self::PROMPTED_OPTION, time(), false );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}
	}

	/**
	 * Whether Freemius still needs the connect / skip screen.
	 *
	 * While true, Freemius overrides the main plugin menu with connect.php.
	 * RankReady must not redirect that page (or activation) to the welcome
	 * wizard, or the opt-in becomes unreachable.
	 */
	private static function freemius_needs_connect(): bool {
		if ( ! function_exists( 'rnrd_fs' ) ) {
			return false;
		}
		$fs = rnrd_fs();
		if ( ! is_object( $fs ) || ! method_exists( $fs, 'is_activation_mode' ) ) {
			return false;
		}
		return (bool) $fs->is_activation_mode();
	}

	/**
	 * Shared guard — never redirect during AJAX/cron/REST, in network admin,
	 * for users who can't manage options, or during bulk plugin activation.
	 */
	private static function can_redirect(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) ) {
			return false; // Don't hijack bulk-activation.
		}
		return true;
	}

	public static function register_page(): void {
		// Hidden admin page under options.php — not shown in the sidebar
		self::$hook_suffix = (string) add_submenu_page(
			'options.php',
			__( 'RankReady Setup', 'rankready-ai-llm-seo' ),
			__( 'RankReady Setup', 'rankready-ai-llm-seo' ),
			'manage_options',
			self::MENU_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Wizard stylesheet and script, on the wizard screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( '' === self::$hook_suffix || $hook !== self::$hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'rnrd-onboarding', RNRD_URL . 'assets/onboarding.css', array(), RNRD_VERSION );
		wp_enqueue_script( 'rnrd-onboarding', RNRD_URL . 'assets/onboarding.js', array(), RNRD_VERSION, true );
		wp_localize_script(
			'rnrd-onboarding',
			'rnrdOnboard',
			array(
				'home'     => home_url( '/' ),
				'siteName' => get_bloginfo( 'name' ),
			)
		);
	}

	// ── Form handler (admin_init, before any output) ───────────────────────────

	public static function maybe_handle_submit(): void {
		if ( empty( $_POST['rnrd_onboard_step'] ) ) {
			return;
		}
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$posted_step = absint( wp_unslash( $_POST['rnrd_onboard_step'] ) );
		$nonce_raw   = isset( $_POST['_rnrd_onboard_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_rnrd_onboard_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce_raw, 'rnrd_onboard_' . $posted_step ) ) {
			return;
		}

		if ( 1 === $posted_step ) {
			self::handle_step_1();
		} elseif ( 2 === $posted_step ) {
			self::handle_step_2();
		}
	}

	private static function handle_step_1(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in maybe_handle_submit() before dispatch.
		$name    = sanitize_text_field( wp_unslash( $_POST['rnrd_llms_site_name'] ?? '' ) );
		$summary = sanitize_textarea_field( wp_unslash( $_POST['rnrd_llms_summary'] ?? '' ) );
		$about   = sanitize_textarea_field( wp_unslash( $_POST['rnrd_llms_about'] ?? '' ) );
		$terms   = sanitize_textarea_field( wp_unslash( $_POST['rnrd_brand_terms'] ?? '' ) );

		// Only write non-empty values so a blank skip doesn't wipe existing data.
		if ( '' !== $name )    update_option( RNRD_OPT_LLMS_SITE_NAME, $name );
		if ( '' !== $summary ) update_option( RNRD_OPT_LLMS_SUMMARY, $summary );
		if ( '' !== $about )   update_option( RNRD_OPT_LLMS_ABOUT, $about );
		if ( '' !== $terms )   update_option( RNRD_OPT_BRAND_TERMS, $terms );

		// Tips opt-in — only fires when the user ticked the box. Explicit consent only.
		$tips = self::maybe_subscribe_tips();

		$next = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&step=2' );
		if ( 'fail' === $tips ) {
			$next = add_query_arg( 'tips', 'fail', $next );
		}
		wp_safe_redirect( $next );
		exit;
	}

	private static function handle_step_2(): void {
		self::apply_feature_toggles_from_post();

		self::seed_cpt_defaults_if_unset();
		self::apply_feature_dependents();

		delete_transient( 'rnrd_rewrite_ok' );

		update_option( self::FLAG_OPTION, time() );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&step=3' ) );
		exit;
	}

	/**
	 * Feature toggle definitions for step 2 (Visibility + Content).
	 *
	 * @return array<string, array{label:string, items:array<int, array{option:string, label:string, hint:string, default:bool, nested?:bool, depends?:string}>}>
	 */
	private static function get_feature_groups(): array {
		return array(
			'visibility' => array(
				'label' => __( 'AI Visibility', 'rankready-ai-llm-seo' ),
				'items' => array(
					array(
						'option'  => RNRD_OPT_ROBOTS_ENABLE,
						'label'   => __( 'LLM Crawler Access', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Allow or block named AI crawlers in robots.txt', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_CONTENT_SIGNALS_ENABLE,
						'label'   => __( 'Content Signals', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Tell AI engines how they may use your content for training, search, and input', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_LLMS_ENABLE,
						'label'   => __( 'LLMs.txt Generator', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Publish a site index AI engines can discover at /llms.txt', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_LLMS_FULL_ENABLE,
						'label'   => __( 'LLMs-full.txt (extended index)', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'The fuller site corpus at /llms-full.txt. Needs LLMs.txt on. Turn it on when you want the full index', 'rankready-ai-llm-seo' ),
						'default' => false,
						'nested'  => true,
						'depends' => RNRD_OPT_LLMS_ENABLE,
						'opt_in'  => true,
					),
					array(
						'option'  => RNRD_OPT_MD_ENABLE,
						'label'   => __( 'Markdown Endpoint', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Serve clean Markdown versions of your pages to AI agents', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_MCP_ENABLE,
						'label'   => __( 'WebMCP', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Publishes /.well-known/mcp.json for Claude, Cursor and VS Code. Opt in when you are ready', 'rankready-ai-llm-seo' ),
						'default' => false,
						'opt_in'  => true,
					),
					array(
						'option'  => RNRD_OPT_OKF_ENABLE,
						'label'   => __( 'Open Knowledge Format', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Bundle structured knowledge for AI engines at /okf/', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
				),
			),
			'content'    => array(
				'label' => __( 'AI Content', 'rankready-ai-llm-seo' ),
				'items' => array(
					array(
						'option'  => RNRD_OPT_AUTHOR_ENABLE,
						'label'   => __( 'Author Box (E-E-A-T)', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Show author credentials and trust signals on posts', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_SCHEMA_ARTICLE,
						'label'   => __( 'Article schema', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Structured Article markup AI crawlers can parse', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_SCHEMA_FAQ,
						'label'   => __( 'FAQ schema', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'FAQPage markup when FAQs are present', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_SCHEMA_SPEAKABLE,
						'label'   => __( 'Speakable schema', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Voice-assistant friendly speakable sections', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
				),
			),
		);
	}

	/** @return list<string> */
	private static function get_feature_option_keys(): array {
		$keys = array();
		foreach ( self::get_feature_groups() as $group ) {
			foreach ( $group['items'] as $item ) {
				$keys[] = $item['option'];
			}
		}
		return $keys;
	}

	private static function is_option_on( string $option, bool $default_on = true ): bool {
		$current = get_option( $option, $default_on ? 'on' : 'off' );
		if ( false === $current || '__rnrd_unset__' === $current ) {
			return $default_on;
		}
		return 'on' === (string) $current;
	}

	/** True while the site has not completed or skipped the welcome wizard. */
	private static function is_first_wizard_pass(): bool {
		return ! get_option( self::FLAG_OPTION );
	}

	/**
	 * Step 2 checkbox state: recommended preset on first pass, saved values on re-run.
	 *
	 * @param array{option:string, default?:bool, opt_in?:bool} $item Feature toggle definition.
	 */
	private static function is_feature_checked_for_step2( array $item ): bool {
		if ( self::is_first_wizard_pass() ) {
			return empty( $item['opt_in'] ) && ! empty( $item['default'] );
		}
		return self::is_option_on( $item['option'], ! empty( $item['default'] ) );
	}

	private static function apply_feature_toggles_from_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in maybe_handle_submit() before dispatch.
		/*
		 * Only presence matters here: a checkbox is either in the POST or not.
		 *
		 * So rather than unslash the whole array and index it by keys that came
		 * from the request, the known option list is walked and each key is
		 * looked up individually. Nothing from $_POST is ever used as a key, a
		 * value, or an option name, which is what the earlier version got wrong:
		 * it sanitised the values while still trusting the keys.
		 */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- presence only; no key or value from this array is ever used, see above.
		$raw = ( isset( $_POST['rnrd_feat'] ) && is_array( $_POST['rnrd_feat'] ) ) ? wp_unslash( $_POST['rnrd_feat'] ) : array();

		foreach ( self::get_feature_option_keys() as $option ) {
			$on = ! empty( $raw[ $option ] );
			update_option( $option, $on ? 'on' : 'off' );
		}

		if ( ! self::is_option_on( RNRD_OPT_LLMS_ENABLE, false ) ) {
			update_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' );
		}
	}

	private static function seed_cpt_defaults_if_unset(): void {
		$cpt_defaults = array( 'post', 'page' );
		if ( post_type_exists( 'product' ) ) {
			$cpt_defaults[] = 'product';
		}

		$cpt_option_keys = array(
			RNRD_OPT_LLMS_POST_TYPES,
			RNRD_OPT_MD_POST_TYPES,
			RNRD_OPT_POST_TYPES,
			RNRD_OPT_FAQ_POST_TYPES,
			RNRD_OPT_OKF_POST_TYPES,
			RNRD_OPT_AUTHOR_POST_TYPES,
		);

		foreach ( $cpt_option_keys as $opt ) {
			$current = get_option( $opt, '__rnrd_unset__' );
			if ( '__rnrd_unset__' === $current ) {
				update_option( $opt, $cpt_defaults );
			}
		}
	}

	private static function apply_feature_dependents(): void {
		if ( self::is_option_on( RNRD_OPT_MD_ENABLE, false ) ) {
			update_option( RNRD_OPT_MD_HINT_DIV, 'on' );
			update_option( RNRD_OPT_MD_BOT_AUTO_SERVE, 'on' );
		}

		if ( self::is_option_on( RNRD_OPT_MCP_ENABLE, false ) ) {
			$mcp_safe = array(
				RNRD_OPT_MCP_EXPOSE_POSTS,
				RNRD_OPT_MCP_EXPOSE_PAGES,
				RNRD_OPT_MCP_EXPOSE_AUTHORS,
				RNRD_OPT_MCP_EXPOSE_TAXONOMIES,
				RNRD_OPT_MCP_EXPOSE_SITEMAP,
				RNRD_OPT_MCP_EXPOSE_MENUS,
				RNRD_OPT_MCP_EXPOSE_LLMS_TXT,
				RNRD_OPT_MCP_EXPOSE_RR_AI,
				RNRD_OPT_MCP_EXPOSE_FRESHNESS,
			);
			foreach ( $mcp_safe as $opt ) {
				update_option( $opt, 'on' );
			}
		}
	}

	/**
	 * Labels for the step 3 completion tick list (reflects saved options).
	 *
	 * @return list<string>
	 */
	private static function get_completion_items(): array {
		$items     = array();
		$wc_active = post_type_exists( 'product' );

		// Issue #17: this used to be added unconditionally, so a fresh install
		// with every brand field left blank still ticked "Brand Identity saved".
		// Only claim it if the admin actually entered at least one of the three.
		$brand_name  = (string) get_option( 'rnrd_llms_site_name', '' );
		$brand_sum   = (string) get_option( 'rnrd_llms_summary', '' );
		$brand_about = (string) get_option( 'rnrd_llms_about', '' );
		if ( '' !== trim( $brand_name ) || '' !== trim( $brand_sum ) || '' !== trim( $brand_about ) ) {
			$items[] = __( 'Brand Identity saved', 'rankready-ai-llm-seo' );
		}

		if ( self::is_option_on( RNRD_OPT_LLMS_ENABLE, false ) ) {
			$items[] = __( '/llms.txt site index turned on', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_LLMS_FULL_ENABLE, false ) ) {
			$items[] = __( '/llms-full.txt extended index turned on', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_MD_ENABLE, false ) ) {
			$items[] = __( 'Per-post /post-slug.md endpoints turned on', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_ROBOTS_ENABLE, false ) ) {
			// Counted from the canonical list, never hardcoded. The literal it
			// replaced said "30+" against an actual 29, and readme.txt and the
			// pricing page both already said 29 — three numbers, one list.
			$crawler_count = class_exists( 'RNRD_Crawler_Access' )
				? count( RNRD_Crawler_Access::get_llm_crawlers() )
				: 0;
			$items[] = $crawler_count > 0
				? sprintf(
					/* translators: %d: number of AI crawler user-agents named in robots.txt */
					__( 'robots.txt naming %d AI crawlers', 'rankready-ai-llm-seo' ),
					$crawler_count
				)
				: __( 'robots.txt AI crawler rules', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_CONTENT_SIGNALS_ENABLE, false ) ) {
			$items[] = __( 'Content Signals in robots.txt', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_MCP_ENABLE, false ) ) {
			$items[] = __( '/.well-known/mcp.json (WebMCP manifest)', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_OKF_ENABLE, false ) ) {
			$items[] = __( '/okf/ Open Knowledge Format bundle', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_AUTHOR_ENABLE, false ) ) {
			$items[] = __( 'Author Box (E-E-A-T) enabled', 'rankready-ai-llm-seo' );
		}

		$schema_on = array();
		if ( self::is_option_on( RNRD_OPT_SCHEMA_ARTICLE, false ) ) {
			$schema_on[] = __( 'Article', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_SCHEMA_FAQ, false ) ) {
			$schema_on[] = __( 'FAQ', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_SCHEMA_SPEAKABLE, false ) ) {
			$schema_on[] = __( 'Speakable', 'rankready-ai-llm-seo' );
		}
		if ( $schema_on ) {
			$items[] = sprintf(
				/* translators: %s: comma-separated schema types */
				__( 'Schema signals: %s', 'rankready-ai-llm-seo' ),
				implode( ', ', $schema_on )
			);
		}

		return $items;
	}

	/**
	 * Subscribe the current admin to the RankReady AI-SEO-tips list — ONLY when they
	 * ticked the onboarding opt-in box and supplied a valid email. Guarded by
	 * per-user meta so re-running the wizard never re-subscribes that admin.
	 * See readme.txt "External services".
	 */
	private static function maybe_subscribe_tips(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in maybe_handle_submit() before dispatch.
		if ( empty( $_POST['rnrd_onboard_tips_optin'] ) ) {
			return '';
		}
		$email = sanitize_email( wp_unslash( $_POST['rnrd_onboard_email'] ?? '' ) );
		$fname = sanitize_text_field( wp_unslash( $_POST['rnrd_onboard_first_name'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return self::subscribe_email( $email, $fname, 'RankReady Plugin Onboarding' ) ? 'ok' : 'fail';
	}

	/**
	 * Has this admin already subscribed to the tips list? Once true for the
	 * current user, every opt-in surface (onboarding + dashboard) hides for them.
	 * Other admins on the same site are unaffected.
	 *
	 * @param int|null $user_id User ID; defaults to the current user.
	 */
	public static function tips_optin_done( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		return (bool) get_user_meta( $user_id, self::TIPS_FLAG_META, true );
	}

	/**
	 * Whether to show tips opt-in UI (onboarding + dashboard sidebar).
	 * Hidden when this admin already subscribed, or when Freemius is registered
	 * and the current user is the site admin (Settings → General email match) —
	 * they already shared an email via Freemius connect, so don't ask again.
	 * Does not set the tips subscription flag; Freemius ≠ HostMyBlog tips list.
	 */
	public static function should_show_tips_optin(): bool {
		if ( self::tips_optin_done() ) {
			return false;
		}

		if ( ! function_exists( 'rnrd_fs' ) ) {
			return true;
		}

		$fs = rnrd_fs();
		if ( ! $fs || ! $fs->is_registered() ) {
			return true;
		}

		$current = wp_get_current_user();
		$admin_email = (string) get_option( 'admin_email' );
		if (
			$current instanceof WP_User
			&& ! empty( $current->user_email )
			&& '' !== $admin_email
			&& strtolower( $current->user_email ) === strtolower( $admin_email )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Subscribe one admin to the RankReady tips list via the HostMyBlog webhook.
	 * The single place the plugin transmits opt-in data externally. One-shot per
	 * admin (user_meta) so the form disappears for that user after a confirmed
	 * {"ok":true} response. Does not send `site`. Returns false on invalid input,
	 * when already subscribed, or when the endpoint does not confirm success.
	 *
	 * @param string $email      Address to subscribe (sanitised here).
	 * @param string $first_name Optional first name (FIRSTNAME, max 60 chars).
	 * @param string $source     Attribution stored as SOURCE (defaults to RankReady).
	 */
	public static function subscribe_email( string $email, string $first_name = '', string $source = 'RankReady' ): bool {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) || self::tips_optin_done() ) {
			return false;
		}

		$webhook = (string) apply_filters( 'rnrd_tips_webhook_url', self::TIPS_WEBHOOK_URL );
		if ( '' === $webhook ) {
			return false;
		}

		$first_name = sanitize_text_field( $first_name );
		if ( function_exists( 'mb_substr' ) ) {
			$first_name = mb_substr( $first_name, 0, 60 );
		} else {
			$first_name = substr( $first_name, 0, 60 );
		}

		$body = array(
			'email'  => $email,
			'source' => $source !== '' ? $source : 'RankReady',
		);
		if ( '' !== $first_name ) {
			$body['first_name'] = $first_name;
		}

		// Blocking so we only set the per-admin flag after a confirmed send.
		$response = wp_remote_post(
			$webhook,
			array(
				'timeout'     => 5,
				'blocking'    => true,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			RNRD_Generator::log_error( 'TipsOptIn', 'Subscription request failed: ' . $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			RNRD_Generator::log_error( 'TipsOptIn', 'Subscription endpoint returned HTTP ' . $code . '.' );
			return false;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['ok'] ) ) {
			RNRD_Generator::log_error( 'TipsOptIn', 'Subscription endpoint did not return {"ok":true}.' );
			return false;
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::TIPS_FLAG_META, time() );
		}
		return true;
	}

	// ── Render ─────────────────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'rankready-ai-llm-seo' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 1;
		$step = max( 1, min( 3, $step ) );

		// Defensive: maybe_redirect() already bounces this on admin_init.
		// If we still reach here with no completion flag, do not render Done.
		if ( 3 === $step && ! get_option( self::FLAG_OPTION ) ) {
			$step = 2;
		}

		switch ( $step ) {
			case 3:
				self::render_step_3();
				break;
			case 2:
				self::render_step_2();
				break;
			default:
				self::render_step_1();
				break;
		}
	}

	// ── Shared chrome ─────────────────────────────────────────────────────────

	/**
	 * Opens the wizard wrapper and prints the progress bar.
	 *
	 * @param int $step      Current step (1–3).
	 * @param int $total     Total steps.
	 */
	private static function render_header( int $step, int $total = 3 ): void {
		$pct = (int) round( ( $step / $total ) * 100 );
		?>
		<div class="rnrd-onboard">

		<!-- Logo + step counter -->
		<div class="rnrd-onboard__brand">
			<span class="rnrd-onboard__dot" aria-hidden="true"></span>
			<span>RankReady</span>
			<span class="rnrd-onboard__step-count" id="rnrd-onboard-stepcount">
				<?php
				/* translators: 1: current step number, 2: total steps */
				printf( esc_html__( 'Step %1$d of %2$d', 'rankready-ai-llm-seo' ), absint( $step ), absint( $total ) );
				?>
			</span>
		</div>

		<?php
		/*
		 * The progressbar role carried a value and no name, so a screen reader
		 * announced "33 percent" with nothing saying what was a third done.
		 * The visible "Step 1 of 3" beside it is the name, and it is already
		 * written; aria-labelledby points at it rather than duplicating the
		 * string into a second translatable. aria-valuetext makes the
		 * announcement the step count instead of a percentage nobody counted
		 * in, since the steps are what the reader is moving through.
		 */
		$step_label = sprintf(
			/* translators: 1: current step number, 2: total steps */
			__( 'Step %1$d of %2$d', 'rankready-ai-llm-seo' ),
			absint( $step ),
			absint( $total )
		);
		?>
		<!-- Progress bar -->
		<div class="rnrd-onboard__progress-track" role="progressbar" aria-labelledby="rnrd-onboard-stepcount" aria-valuetext="<?php echo esc_attr( $step_label ); ?>" aria-valuenow="<?php echo esc_attr( $pct ); ?>" aria-valuemin="0" aria-valuemax="100">
			<div class="rnrd-onboard__progress-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
		</div>

		<div class="rnrd-onboard__card">
		<?php
	}

	/** Closes the card and wrapper divs. */
	private static function render_footer(): void {
		?>
		</div><!-- /.rnrd-onboard__card -->
		</div><!-- /.rnrd-onboard -->
		<?php
	}

	// ── Step 1: Brand Identity ─────────────────────────────────────────────────

	private static function render_step_1(): void {
		$site_name = get_bloginfo( 'name' );
		$cur_name  = (string) get_option( 'rnrd_llms_site_name', '' );
		$cur_sum   = (string) get_option( 'rnrd_llms_summary',   '' );
		$cur_about = (string) get_option( 'rnrd_llms_about',     '' );
		$cur_terms = (string) get_option( 'rnrd_brand_terms',    '' );

		self::render_header( 1 );
		?>

		<h1 class="rnrd-onboard__title">
			<?php esc_html_e( 'Welcome to RankReady', 'rankready-ai-llm-seo' ); ?>
		</h1>
		<p class="rnrd-onboard__lede">
			<?php esc_html_e( 'Make your site readable by ChatGPT, Claude, Perplexity and Google AI. Start with your brand: these four fields feed every AI surface RankReady controls. The panel alongside shows where each one is used and previews your /llms.txt as you type.', 'rankready-ai-llm-seo' ); ?>
		</p>

		<div class="rnrd-onboard__split">
			<form method="post" action="" class="rnrd-onboard__form">
				<?php wp_nonce_field( 'rnrd_onboard_1', '_rnrd_onboard_nonce' ); ?>
				<input type="hidden" name="rnrd_onboard_step" value="1" />

				<div class="rnrd-onboard__field">
					<label class="rnrd-onboard__label" for="rnrd_llms_site_name">
						<?php esc_html_e( 'Site / brand name', 'rankready-ai-llm-seo' ); ?>
					</label>
					<p class="rnrd-onboard__hint">
						<?php esc_html_e( 'The canonical brand name. Used as the Organization entity name in schema, llms.txt and every generated summary. Leave blank to fall back to the WordPress site title.', 'rankready-ai-llm-seo' ); ?>
					</p>
					<input
						type="text"
						id="rnrd_llms_site_name"
						name="rnrd_llms_site_name"
						value="<?php echo esc_attr( $cur_name ); ?>"
						placeholder="<?php echo esc_attr( $site_name ); ?>"
						class="rnrd-onboard__input"
						data-preview-key="name"
					/>
				</div>

				<div class="rnrd-onboard__field">
					<label class="rnrd-onboard__label" for="rnrd_llms_summary">
						<?php esc_html_e( 'One-line summary', 'rankready-ai-llm-seo' ); ?>
					</label>
					<p class="rnrd-onboard__hint">
						<?php esc_html_e( 'A single sentence that describes the brand. Shows up under the brand name in /llms.txt and in every summary RankReady generates. Max 160 characters.', 'rankready-ai-llm-seo' ); ?>
					</p>
					<textarea
						id="rnrd_llms_summary"
						name="rnrd_llms_summary"
						class="rnrd-onboard__textarea rnrd-onboard__textarea--short"
						maxlength="160"
						data-preview-key="summary"
						placeholder="<?php esc_attr_e( 'Example: A 5-minute Pomodoro timer for Mac that hides your distracting apps automatically.', 'rankready-ai-llm-seo' ); ?>"
					><?php echo esc_textarea( $cur_sum ); ?></textarea>
				</div>

				<div class="rnrd-onboard__field">
					<label class="rnrd-onboard__label" for="rnrd_llms_about">
						<?php esc_html_e( 'About (optional)', 'rankready-ai-llm-seo' ); ?>
					</label>
					<p class="rnrd-onboard__hint">
						<?php esc_html_e( 'Detailed context that goes into your llms.txt, so an AI crawler reads who the site is for: audience, differentiators, proof. Markdown supported. Max 500 characters.', 'rankready-ai-llm-seo' ); ?>
					</p>
					<textarea
						id="rnrd_llms_about"
						name="rnrd_llms_about"
						class="rnrd-onboard__textarea"
						maxlength="500"
						data-preview-key="about"
						placeholder="<?php esc_attr_e( 'Example: We help solo Mac users stay focused without forcing them into a calendar. Built since 2022, used by 12,000+ freelancers and indie devs. Featured in The Verge and Lifehacker.', 'rankready-ai-llm-seo' ); ?>"
					><?php echo esc_textarea( $cur_about ); ?></textarea>
				</div>

				<div class="rnrd-onboard__field">
					<label class="rnrd-onboard__label" for="rnrd_brand_terms">
						<?php esc_html_e( 'Canonical brand terms', 'rankready-ai-llm-seo' ); ?>
					</label>
					<p class="rnrd-onboard__hint">
						<?php esc_html_e( 'One canonical name per line, exact capitalisation and spacing. Include product names, frequent misspellings, abbreviations. RankReady then writes the canonical form everywhere it publishes your name, so a crawler sees one spelling instead of five.', 'rankready-ai-llm-seo' ); ?>
					</p>
					<textarea
						id="rnrd_brand_terms"
						name="rnrd_brand_terms"
						class="rnrd-onboard__textarea"
						rows="4"
						data-preview-key="terms"
						placeholder="<?php esc_attr_e( "Acme Studio\nAcme\nAcmeStudio (one word)", 'rankready-ai-llm-seo' ); ?>"
					><?php echo esc_textarea( $cur_terms ); ?></textarea>
				</div>

				<?php
				$rnrd_user       = wp_get_current_user();
				$rnrd_prefill_em = ( $rnrd_user && ! empty( $rnrd_user->user_email ) )
					? $rnrd_user->user_email
					: get_bloginfo( 'admin_email' );
				$rnrd_prefill_fn = ( $rnrd_user && ! empty( $rnrd_user->first_name ) )
					? $rnrd_user->first_name
					: '';
				$rnrd_show_tips  = self::should_show_tips_optin();
				?>
				<?php if ( $rnrd_show_tips ) : ?>
				<div class="rnrd-onboard__field rnrd-onboard__optin-field">
					<label class="rnrd-onboard__optin">
						<input type="checkbox" name="rnrd_onboard_tips_optin" value="1" class="rnrd-onboard__optin-check" />
						<span class="rnrd-onboard__optin-copy">
							<?php esc_html_e( 'Email me free AI SEO tips and RankReady updates', 'rankready-ai-llm-seo' ); ?>
						</span>
					</label>
					<div class="rnrd-onboard__optin-fields">
						<input
							type="text"
							name="rnrd_onboard_first_name"
							value="<?php echo esc_attr( $rnrd_prefill_fn ); ?>"
							class="rnrd-onboard__input rnrd-onboard__optin-input"
							placeholder="<?php esc_attr_e( 'First name', 'rankready-ai-llm-seo' ); ?>"
							maxlength="60"
							autocomplete="off"
							data-1p-ignore="true"
							data-lpignore="true"
						/>
						<input
							type="email"
							name="rnrd_onboard_email"
							value="<?php echo esc_attr( $rnrd_prefill_em ); ?>"
							class="rnrd-onboard__input rnrd-onboard__optin-input"
							placeholder="<?php esc_attr_e( 'you@example.com', 'rankready-ai-llm-seo' ); ?>"
							autocomplete="off"
							data-1p-ignore="true"
							data-lpignore="true"
						/>
					</div>
					<p class="rnrd-onboard__hint rnrd-onboard__optin-hint">
						<?php esc_html_e( 'Practical AI SEO tips plus RankReady product updates, straight to your inbox. No spam, unsubscribe anytime.', 'rankready-ai-llm-seo' ); ?>
					</p>
				</div>
				<?php endif; ?>

				<div class="rnrd-onboard__actions">
					<button type="submit" class="rnrd-onboard__btn rnrd-onboard__btn--primary">
						<?php esc_html_e( 'Save and continue', 'rankready-ai-llm-seo' ); ?>
					</button>
					<a class="rnrd-onboard__skip" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rankready-ai-llm-seo&rnrd_onboard_skip=1' ), 'rnrd_onboard_skip' ) ); ?>">
						<?php esc_html_e( 'Skip for now', 'rankready-ai-llm-seo' ); ?>
					</a>
				</div>
			</form>

			<aside class="rnrd-onboard__sidebar" aria-label="<?php esc_attr_e( 'Where this goes', 'rankready-ai-llm-seo' ); ?>">
				<div class="rnrd-onboard__sidebar-inner">
					<h2 class="rnrd-onboard__sidebar-title"><?php esc_html_e( 'Where this goes', 'rankready-ai-llm-seo' ); ?></h2>
					<p class="rnrd-onboard__sidebar-lede">
						<?php esc_html_e( 'These exact fields are read by every AI surface RankReady controls. One place to write, seven places it shows up.', 'rankready-ai-llm-seo' ); ?>
					</p>

					<ul class="rnrd-onboard__sidebar-list">
						<li>
							<code>/llms.txt</code>
							<span><?php esc_html_e( 'Brand name = H1. One-liner = blockquote. About = body. Canonical terms = Brand line.', 'rankready-ai-llm-seo' ); ?></span>
						</li>
						<li>
							<code>/llms-full.txt</code>
							<span><?php esc_html_e( 'Same header block at the top of every page below. AI engines crawl the full corpus.', 'rankready-ai-llm-seo' ); ?></span>
						</li>
						<li>
							<code>/robots.txt</code>
							<span><?php esc_html_e( 'Brand name appears in the comment block above the RankReady AI-crawler rules.', 'rankready-ai-llm-seo' ); ?></span>
						</li>
						<li>
							<?php esc_html_e( 'AI Summary prompt', 'rankready-ai-llm-seo' ); ?>
							<span><?php esc_html_e( 'Brand name is written into every per-post summary, so the text names you rather than "this site".', 'rankready-ai-llm-seo' ); ?></span>
						</li>
						<li>
							<?php esc_html_e( 'FAQ generation prompt', 'rankready-ai-llm-seo' ); ?>
							<span><?php esc_html_e( 'Brand context anchors every FAQ answer, without duplicating FAQs you already wrote by hand.', 'rankready-ai-llm-seo' ); ?></span>
						</li>
						<li>
							<code>/.well-known/mcp.json</code>
							<span><?php esc_html_e( 'WebMCP ability "rankready/get-site-info" returns brand name + canonical terms to Claude Desktop, Cursor, VS Code.', 'rankready-ai-llm-seo' ); ?></span>
						</li>
						<li>
							<?php esc_html_e( 'Homepage Markdown', 'rankready-ai-llm-seo' ); ?>
							<span><?php esc_html_e( 'Sent when an agent requests text/markdown for /. Uses brand name + one-liner as the header.', 'rankready-ai-llm-seo' ); ?></span>
						</li>
					</ul>

					<div class="rnrd-onboard__preview" aria-live="polite">
						<p class="rnrd-onboard__preview-title">
							<?php esc_html_e( 'Your /llms.txt, as you type', 'rankready-ai-llm-seo' ); ?>
						</p>
						<pre class="rnrd-onboard__preview-code"><code id="rnrd-llms-preview"></code></pre>
						<p class="rnrd-onboard__preview-note">
							<?php
							printf(
								/* translators: %s is the site's llms.txt URL. */
								esc_html__( 'This is the top of the file agents fetch at %s. Your published pages are listed below it.', 'rankready-ai-llm-seo' ),
								'<code>' . esc_html( home_url( '/llms.txt' ) ) . '</code>'
							);
							?>
						</p>
					</div>
				</div>
			</aside>
		</div>

		<?php
		self::render_footer();
	}

	// ── Step 2: AI feature toggles ─────────────────────────────────────────────

	private static function render_step_2(): void {
		self::render_header( 2 );
		?>
		<h1 class="rnrd-onboard__title">
			<?php esc_html_e( 'Choose your AI surfaces', 'rankready-ai-llm-seo' ); ?>
		</h1>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag, no state change.
		if ( isset( $_GET['tips'] ) && 'fail' === sanitize_key( wp_unslash( $_GET['tips'] ) ) ) :
			?>
			<div class="rnrd-onboard__notice" role="status">
				<?php esc_html_e( 'Your email was not added to the tips list. Your settings are saved. You can try again from the dashboard whenever you like.', 'rankready-ai-llm-seo' ); ?>
			</div>
			<?php
		endif;
		/*
		 * The opt-in names are DERIVED from get_feature_groups(), not typed in.
		 * This sentence used to hardcode "LLMs-full.txt and WebMCP", which was
		 * true on the day it was written and would have silently become a lie
		 * the first time an `opt_in` flag moved. Same defect class as the
		 * "six places" count on step 1.
		 */
		$opt_in_labels = array();
		foreach ( self::get_feature_groups() as $group ) {
			foreach ( $group['items'] as $item ) {
				if ( ! empty( $item['opt_in'] ) ) {
					$opt_in_labels[] = (string) $item['label'];
				}
			}
		}
		?>
		<p class="rnrd-onboard__lede">
			<?php
			if ( $opt_in_labels ) {
				printf(
					/* translators: %s: comma-separated list of the features that stay off until opted into. */
					esc_html__( 'Turn on the endpoints and signals you want live today. Recommended enables the core set; %s stay off until you opt in.', 'rankready-ai-llm-seo' ),
					esc_html( wp_sprintf_l( '%l', $opt_in_labels ) )
				);
			} else {
				esc_html_e( 'Turn on the endpoints and signals you want live today. Recommended enables the core set.', 'rankready-ai-llm-seo' );
			}
			?>
		</p>

		<form method="post" action="" class="rnrd-onboard__form" id="rnrd-onboard-features">
			<?php wp_nonce_field( 'rnrd_onboard_2', '_rnrd_onboard_nonce' ); ?>
			<input type="hidden" name="rnrd_onboard_step" value="2" />

			<div class="rnrd-onboard__feat-bulk">
				<button type="button" class="rnrd-onboard__feat-bulk-btn" data-rnrd-feat-preset="recommended">
					<?php esc_html_e( 'Enable recommended', 'rankready-ai-llm-seo' ); ?>
				</button>
				<span class="rnrd-onboard__feat-bulk-sep" aria-hidden="true">·</span>
				<button type="button" class="rnrd-onboard__feat-bulk-btn" data-rnrd-feat-preset="all-on">
					<?php esc_html_e( 'Turn all on', 'rankready-ai-llm-seo' ); ?>
				</button>
				<span class="rnrd-onboard__feat-bulk-sep" aria-hidden="true">·</span>
				<button type="button" class="rnrd-onboard__feat-bulk-btn" data-rnrd-feat-preset="all-off">
					<?php esc_html_e( 'Turn all off', 'rankready-ai-llm-seo' ); ?>
				</button>
			</div>

			<?php foreach ( self::get_feature_groups() as $group_key => $group ) : ?>
				<section class="rnrd-onboard__feat-group" aria-labelledby="rnrd-feat-<?php echo esc_attr( $group_key ); ?>">
					<h2 class="rnrd-onboard__feat-group-title" id="rnrd-feat-<?php echo esc_attr( $group_key ); ?>">
						<?php echo esc_html( $group['label'] ); ?>
					</h2>
					<ul class="rnrd-onboard__feat-list">
						<?php
						foreach ( $group['items'] as $item ) :
							$option   = $item['option'];
							$checked  = self::is_feature_checked_for_step2( $item );
							$nested   = ! empty( $item['nested'] );
							$depends  = isset( $item['depends'] ) ? (string) $item['depends'] : '';
							$opt_in   = ! empty( $item['opt_in'] );
							$row_class = 'rnrd-onboard__feat-row';
							if ( $nested ) {
								$row_class .= ' rnrd-onboard__feat-row--nested';
							}
							?>
							<li class="<?php echo esc_attr( $row_class ); ?>"
								<?php if ( $depends ) : ?>
									data-rnrd-feat-depends="<?php echo esc_attr( $depends ); ?>"
								<?php endif; ?>>
								<label class="rnrd-onboard__feat-label">
									<input
										type="checkbox"
										class="rnrd-onboard__feat-check"
										name="rnrd_feat[<?php echo esc_attr( $option ); ?>]"
										value="1"
										<?php checked( $checked ); ?>
										<?php if ( $depends ) : ?>
											data-rnrd-feat-parent="<?php echo esc_attr( $depends ); ?>"
										<?php endif; ?>
										<?php if ( ! empty( $item['default'] ) ) : ?>
											data-rnrd-feat-default="1"
										<?php endif; ?>
										<?php if ( $opt_in ) : ?>
											data-rnrd-feat-optin="1"
										<?php endif; ?>
									/>
									<span class="rnrd-onboard__feat-copy">
										<strong><?php echo esc_html( $item['label'] ); ?></strong>
										<span class="rnrd-onboard__feat-hint"><?php echo esc_html( $item['hint'] ); ?></span>
									</span>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>

			<?php
			/*
			 * This note names the screen that unblocks generation and used to
			 * name it as plain text, so the one prerequisite the wizard does not
			 * collect was also the one thing it gave no way to reach. Linking it
			 * costs nothing and removes a hunt.
			 */
			?>
			<p class="rnrd-onboard__feat-note">
				<?php
				printf(
					/* translators: %s: link to the AI Content settings screen */
					esc_html__( 'AI Summary and AI FAQ Generator need an API provider. Set that up any time under %s.', 'rankready-ai-llm-seo' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&tab=content&sub=summary' ) ) . '">' . esc_html__( 'AI Content', 'rankready-ai-llm-seo' ) . '</a>'
				);
				?>
			</p>

			<div class="rnrd-onboard__actions">
				<button type="submit" class="rnrd-onboard__btn rnrd-onboard__btn--primary">
					<?php esc_html_e( 'Apply & finish', 'rankready-ai-llm-seo' ); ?>
				</button>
				<a class="rnrd-onboard__skip rnrd-onboard__skip--back" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&step=1' ) ); ?>">
					<?php esc_html_e( 'Back to Brand Identity', 'rankready-ai-llm-seo' ); ?>
				</a>
				<?php
				/*
				 * Step 2 had no way out. "Apply & finish" commits eleven toggles
				 * and "Back to Brand Identity" only retreats one screen, so
				 * somebody who got here and did not want to decide yet had to go
				 * back to step 1 and skip from there, two steps, to escape a
				 * wizard that otherwise keeps intercepting because
				 * rnrd_welcome_completed is never set. An escape hatch belongs on
				 * every screen of a linear flow, not only the first. It reuses
				 * step 1's nonced handler, which is step-agnostic, so this adds
				 * no new plumbing. The actions row is flex with wrap, so the
				 * third item wraps rather than crowding at 375px.
				 */
				?>
				<a class="rnrd-onboard__skip" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rankready-ai-llm-seo&rnrd_onboard_skip=1' ), 'rnrd_onboard_skip' ) ); ?>">
					<?php esc_html_e( 'Skip for now', 'rankready-ai-llm-seo' ); ?>
				</a>
			</div>
		</form>

		<?php
		self::render_footer();
	}

	// ── Step 3: Done (rotating ring → tick reveal) ───────────────────────────

	private static function render_step_3(): void {
		self::render_header( 3 );
		$site_name = (string) get_option( RNRD_OPT_LLMS_SITE_NAME, '' );
		if ( '' === $site_name ) {
			$site_name = get_bloginfo( 'name' );
		}
		$completion = self::get_completion_items();
		?>

		<div class="rnrd-onboard__done">
		<div class="rnrd-onboard__progress-ring" aria-hidden="true">
			<svg class="rnrd-onboard__ring-svg" viewBox="0 0 56 56">
				<circle class="rnrd-onboard__ring-track"  cx="28" cy="28" r="24"></circle>
				<circle class="rnrd-onboard__ring-stroke" cx="28" cy="28" r="24"></circle>
			</svg>
			<span class="rnrd-onboard__ring-tick" aria-hidden="true"></span>
		</div>

		<h1 class="rnrd-onboard__title">
			<?php
			/* translators: %s: site name */
			echo esc_html( sprintf( __( 'RankReady is set up on %s', 'rankready-ai-llm-seo' ), $site_name ) );
			?>
		</h1>
		<p class="rnrd-onboard__lede">
			<?php
			/*
			 * Issue #17 was fixed in get_completion_items() but not here. The
			 * test was "more than one item", so a run that turned nothing on at
			 * all fell through to a sentence asserting the brand identity had
			 * been saved, on a screen whose own list had just declined to tick
			 * it: the list told the truth and the line above it did not. The
			 * one-item case landed in the same branch and was wrong just as
			 * often, because that single item is frequently not the brand
			 * fields. One item is still something you turned on, so it belongs
			 * with the many-item line; nothing turned on gets its own sentence
			 * that says that plainly and points at the screen that fixes it.
			 */
			if ( $completion ) {
				esc_html_e( 'Here is what you turned on. You can change any setting anytime in RankReady.', 'rankready-ai-llm-seo' );
			} else {
				esc_html_e( 'Nothing is switched on yet. Turn on AI surfaces any time from the RankReady dashboard.', 'rankready-ai-llm-seo' );
			}
			?>
		</p>

		<?php
		/*
		 * get_completion_items() is honest: it only ticks what is actually on,
		 * so somebody who skipped step 2 outright and left the brand fields
		 * blank gets an empty array. The list was rendered unconditionally, and
		 * an empty <ul> still carries its own 28px bottom margin, so that case
		 * drew a silent void between the subhead and the buttons. Nothing to
		 * tick is a real state, and the right thing to show for it is the
		 * subhead alone, which already says what to do next.
		 */
		if ( $completion ) :
			?>
		<ul class="rnrd-onboard__ticks">
			<?php
			foreach ( $completion as $i => $label ) :
				/*
				 * 70ms, not 140ms. get_completion_items() can return eleven
				 * rows, and at 140ms the eleventh tick landed 3.2 seconds after
				 * load: three seconds of a mostly empty confirmation screen at
				 * the highest-intent moment in the product, and a blank list in
				 * any screenshot taken before then.
				 *
				 * 500ms, not 1800ms. The 1800 was chosen to let the ring finish
				 * first (it fills in 1500ms, its own tick lands at 1700ms), on
				 * the theory that an earlier cascade would collide with it. It
				 * does not: the ring sits at the top of the card and the list
				 * sits below the subhead, so nothing overlaps. What the delay
				 * actually bought was a measured 1.8 seconds where the list
				 * container already reserves its full height and every row in
				 * it is invisible, which renders as a blank band in the middle
				 * of the confirmation screen. Starting at 500ms fills that band
				 * while the ring is still filling, which reads as one thing
				 * completing rather than two things waiting their turn, and the
				 * last of eleven rows still lands inside 1.3s.
				 */
				$delay_ms = 500 + ( (int) $i * 70 );
				?>
				<li style="animation-delay:<?php echo esc_attr( (string) $delay_ms ); ?>ms">
					<span class="rnrd-onboard__tick" aria-hidden="true"></span>
					<?php echo esc_html( $label ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
			<?php
		endif;
		?>

		<?php
		/*
		 * The primary action on a finish screen should be whatever is still
		 * standing between the user and the first real result, and until an API
		 * provider has a key nothing generates at all. This screen used to end
		 * on "Go to Dashboard" in every case, which is a destination rather than
		 * an action and drops somebody who cannot yet generate onto a hub that
		 * will not tell them why. So when no key is set the primary button goes
		 * straight to AI Content and says what it is for, and "Go to Dashboard"
		 * moves to the secondary slot beside the /llms.txt link. When a key IS
		 * set there is nothing to unblock and the original button stands.
		 *
		 * Same conditional shape as the /llms.txt link below: read the real
		 * state, then offer the useful next step rather than a fixed one.
		 */
		$rnrd_llm_ready = ! class_exists( 'RNRD_LLM' ) || RNRD_LLM::active_provider_ready();
		?>
		<div class="rnrd-onboard__actions rnrd-onboard__actions--centered">
			<?php if ( $rnrd_llm_ready ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo' ) ); ?>" class="rnrd-onboard__btn rnrd-onboard__btn--primary">
					<?php esc_html_e( 'Go to Dashboard', 'rankready-ai-llm-seo' ); ?>
				</a>
			<?php else : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&tab=content&sub=summary' ) ); ?>" class="rnrd-onboard__btn rnrd-onboard__btn--primary">
					<?php esc_html_e( 'Connect an AI provider', 'rankready-ai-llm-seo' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo' ) ); ?>" class="rnrd-onboard__skip">
					<?php esc_html_e( 'Go to Dashboard', 'rankready-ai-llm-seo' ); ?>
				</a>
			<?php endif; ?>
			<?php
			/*
			 * Only link the endpoint when it is actually switched on.
			 * RNRD_OPT_LLMS_ENABLE defaults to 'off' and step 2 lets somebody
			 * turn it off outright, so an unconditional link handed a fresh user
			 * a 404 as the last thing the setup wizard did. When it is off the
			 * slot points at the switch instead, which is the useful next step
			 * rather than an empty footer.
			 */
			if ( 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) :
				?>
				<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" class="rnrd-onboard__skip" target="_blank" rel="noopener">
					<?php esc_html_e( 'View your /llms.txt', 'rankready-ai-llm-seo' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'rankready-ai-llm-seo' ); ?></span>
				</a>
			<?php else : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=crawlers&sub=llms' ) ); ?>" class="rnrd-onboard__skip">
					<?php esc_html_e( 'Turn on /llms.txt', 'rankready-ai-llm-seo' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<?php
		/*
		 * These items report what was SAVED, which is all this screen can
		 * honestly know. Whether each endpoint actually serves depends on the
		 * host: nginx commonly 403s /.well-known/, some stacks need a permalink
		 * flush, a CDN can cache the old response. Diagnostics is the screen
		 * that really fetches them, so send the user there rather than letting
		 * a saved option masquerade as a working endpoint.
		 *
		 * Guarded on the same list as everything else on this screen: "each
		 * one" has no referent when nothing was turned on, and sending someone
		 * to Diagnostics to verify an empty set is a dead end.
		 */
		if ( $completion ) :
			?>
		<p class="rnrd-onboard__lede rnrd-onboard__lede--foot">
			<?php
			printf(
				/* translators: %s: link to the Diagnostics screen */
				esc_html__( 'To confirm each one is serving on your host, run %s.', 'rankready-ai-llm-seo' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=settings&sub=advanced' ) ) . '">' . esc_html__( 'Diagnostics', 'rankready-ai-llm-seo' ) . '</a>'
			);
			?>
		</p>
			<?php
		endif;
		?>
		<?php
		/*
		 * Craft rule 7: every customer-facing pass states the Pro tie-in,
		 * unprompted and honestly. This screen is the highest-intent moment in
		 * the product and had no tie-in at all, so a person who had just
		 * finished setup was never told the paid tier exists.
		 *
		 * The gate named here is the real one and nothing more. Generating a
		 * summary or an FAQ by hand is unlimited in Free and always will be
		 * (rule 5); what Pro adds is doing it without being asked, across an
		 * archive. No outcome is promised, no number is invented, and it is
		 * hidden from someone who already paid.
		 */
		if ( ! apply_filters( 'rnrd_is_pro', false ) ) :
			?>
			<aside class="rnrd-onboard__proline">
				<p class="rnrd-onboard__proline-text">
					<?php esc_html_e( 'Generating summaries and FAQs by hand is unlimited in Free. Pro does it for you: bulk across the whole archive, and automatically on publish.', 'rankready-ai-llm-seo' ); ?>
				</p>
				<a class="rnrd-onboard__proline-link" href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-pro-upgrade' ) ); ?>">
					<?php esc_html_e( 'See Pro, $49 once', 'rankready-ai-llm-seo' ); ?>
				</a>
			</aside>
			<?php
		endif;
		?>

		</div><!-- /.rnrd-onboard__done -->

		<?php
		self::render_footer();
	}

}
