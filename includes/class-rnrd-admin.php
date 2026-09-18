<?php
/**
 * Admin settings page — tabbed UI using core WordPress styles.
 *
 * Top tabs: Dashboard | AI Visibility | AI Content | Insights | Settings
 * AI Visibility subtabs (slug crawlers): Brand | Robots | LLMs.txt | Markdown | WebMCP | OKF
 * AI Content subtabs (slug content): AI Summary | AI FAQ Generator | Author Box | Schema
 * Settings subtabs: API Keys | Cloudflare | Advanced
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Admin {

	private const SETTINGS_GROUP   = 'rnrd_settings_group';  // Settings tab
	private const CONTENT_GROUP    = 'rnrd_content_group';   // Content AI tab
	// v1.2.0-rc.3 — Brand Identity has its own group so the 4-field "Save Brand
	// Identity" form doesn't trigger options.php to null-out every other
	// LLMS_GROUP option that isn't in this form.
	private const BRAND_GROUP      = 'rnrd_brand_group';     // Brand Identity card only
	// v1.2.0-rc.3 — Data Retention has its own group too, for the same reason:
	// a small isolated form must not null out unrelated options when saved.
	private const DATA_GROUP       = 'rnrd_data_group';      // Data Retention card on Advanced tab
	private const AUTHORITY_GROUP  = 'rnrd_authority_group'; // Authority tab (author + schema)
	private const LLMS_GROUP       = 'rnrd_llms_group';      // AI Visibility tab (shared LLMS settings)
	private const INSIGHTS_GROUP   = 'rnrd_insights_group';  // Insights tracking toggles (training / citation / referral)
	private const HEADLESS_GROUP   = 'rnrd_headless_group';  // Advanced tab
	private const OKF_GROUP        = 'rnrd_okf_group';       // OKF bundle card (Advanced tab) — isolated, cross-null-safe
	// Legacy aliases kept for any saved nonces in flight during upgrade.
	private const FAQ_GROUP        = 'rnrd_faq_group';
	private const SCHEMA_GROUP     = 'rnrd_authority_group';
	private const AUTHOR_GROUP     = 'rnrd_authority_group';
	private const MENU_SLUG        = 'rankready-ai-llm-seo';
	private const NONCE_ACTION     = 'rnrd_test_connection';
	private const NONCE_FIELD      = 'rnrd_test_nonce';

	public static function init(): void {
		add_action( 'admin_menu',            array( self::class, 'register_menu' ) );
		add_action( 'admin_init',            array( self::class, 'register_settings' ) );

		// NOTE — the add_/update_option hooks that derive the two legacy crawler
		// arrays from the Allow/Default/Block map live in RNRD_Llms_Txt::init(),
		// not here. That class is always loaded, so the derive also fires for
		// WP-CLI and REST writes; registering it in this admin-only class left
		// the arrays stale for every non-admin writer.
		add_action( 'admin_init',            array( self::class, 'handle_dismiss_actions' ) );
		add_action( 'admin_init',            array( self::class, 'handle_dash_tips_optin' ) );
		add_action( 'admin_init',            array( self::class, 'track_installed_version' ) );
		// v1.2.0-rc.7 — Quick-enable POST handler for locked-state cards.
		// Runs early on admin_init so the wp_safe_redirect() fires before
		// any output. See render_locked_preview() / handle_quick_enable().
		add_action( 'admin_init',            array( self::class, 'handle_quick_enable' ) );
		add_action( 'admin_notices',         array( self::class, 'connection_notice' ) );
		add_action( 'admin_notices',         array( self::class, 'permalink_notice' ) );
		// v1.2.1 — proactive nginx /.well-known/ 403 notice (WebMCP manifest blocked).
		add_action( 'admin_notices',         array( self::class, 'maybe_nginx_wellknown_notice' ) );
		add_action( 'admin_init',            array( self::class, 'handle_nginx_wk_dismiss' ) );
		// v1.1.5 — on RankReady's OWN settings screen only, strip third-party admin
		// notices (other SEO plugins' rating nags, "deactivate me" warnings, Action
		// Scheduler alerts, etc.) that WordPress dumps onto every admin page. RankReady's
		// own RNRD_ notices are kept. Scoped strictly to our screen — never touches any
		// other admin page. Standard pattern used by Yoast / Rank Math / WooCommerce.
		add_action( 'in_admin_header',       array( self::class, 'declutter_admin_notices' ), 1 );
		// model_migration_notice is rendered inline inside render_tab_settings()
		// (not via admin_notices) so it appears in the Settings tab body where
		// the user actually picks the model — not as a global page hijack.
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . RNRD_BASENAME, array( self::class, 'action_links' ) );

		// Defer column registration to 'wp_loaded' so all CPTs are registered.
		add_action( 'wp_loaded', array( self::class, 'register_status_columns' ) );
	}

	// ── "What's new" banner dismiss handlers ────────────────────────────────────

	/**
	 * Records the most recently installed plugin version. When the running
	 * `RNRD_VERSION` is newer than the stored value, every user gets the
	 * "what's new" banner exactly once until each one dismisses it.
	 *
	 * Also runs an idempotent one-shot migration for stale model IDs that would
	 * silently break against the live API. Every provider's retired IDs are
	 * mapped to the current SAME-TIER model via RNRD_LLM::model_migration_map()
	 * (single source of truth). When a value is rewritten, a one-time dismissible
	 * admin notice is queued so the user knows their model changed. Safe to
	 * re-enter: once rewritten, the new ID is no longer in the map → no-op.
	 */
	public static function track_installed_version(): void {
		$stored = (string) get_option( RNRD_OPT_INSTALLED_VERSION, '' );
		if ( $stored !== RNRD_VERSION ) {
			update_option( RNRD_OPT_INSTALLED_VERSION, RNRD_VERSION, false );
		}

		if ( ! class_exists( 'RNRD_LLM' ) ) {
			return;
		}

		$option_for = array(
			'openai'    => RNRD_OPT_MODEL,
			'anthropic' => RNRD_OPT_ANTHROPIC_MODEL,
			'gemini'    => RNRD_OPT_GEMINI_MODEL,
			'deepseek'  => RNRD_OPT_DEEPSEEK_MODEL,
		);

		$queued = (array) get_option( 'rnrd_model_migrations', array() );

		foreach ( $option_for as $provider => $opt_key ) {
			$saved = (string) get_option( $opt_key, '' );
			if ( '' === $saved ) {
				continue;
			}
			$new = RNRD_LLM::migrate_model( $provider, $saved );
			if ( $new !== $saved ) {
				update_option( $opt_key, $new, false );
				// Record "Provider: old → new" once for the admin notice.
				$line = RNRD_LLM::get_provider_label( $provider ) . ': ' . $saved . ' → ' . $new;
				if ( ! in_array( $line, $queued, true ) ) {
					$queued[] = $line;
				}
			}
		}

		if ( ! empty( $queued ) ) {
			update_option( 'rnrd_model_migrations', $queued, false );
		}
	}

	/**
	 * Back-compat shim for extensions that called this before 1.3.1.
	 *
	 * @deprecated 1.3.1 Use RNRD_LLM::get_models_for() instead.
	 * @return array<string, string> Model ID => label.
	 */
	public static function get_allowed_models( string $provider = 'openai' ): array {
		if ( class_exists( 'RNRD_LLM' ) ) {
			return RNRD_LLM::get_models_for( $provider );
		}
		return array();
	}

	/**
	 * One-time dismissible notice: lists any AI models that were auto-migrated
	 * from a retired ID to the current same-tier equivalent. Cleared when the
	 * user dismisses it (see handle_dismiss_actions). Only shown to users who
	 * can manage the plugin.
	 */
	public static function model_migration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$queued = (array) get_option( 'rnrd_model_migrations', array() );
		if ( empty( $queued ) ) {
			return;
		}
		$dismiss_url = wp_nonce_url(
			admin_url( 'admin.php?page=' . self::MENU_SLUG . '&rnrd_dismiss_model_migration=1' ),
			'rnrd_dismiss_model_migration'
		);
		$settings_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=settings' );
		?>
		<div class="notice notice-info rnrd-inline-notice">
			<p>
				<strong><?php esc_html_e( 'RankReady — AI model updated', 'rankready-ai-llm-seo' ); ?></strong><br />
				<?php esc_html_e( 'A model you had selected was retired by its provider. RankReady switched it to the current same-tier model so generation keeps working:', 'rankready-ai-llm-seo' ); ?>
			</p>
			<ul style="margin:6px 0 6px 18px;list-style:disc;">
				<?php foreach ( $queued as $line ) : ?>
					<li><code><?php echo esc_html( $line ); ?></code></li>
				<?php endforeach; ?>
			</ul>
			<p>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Choose a different model in Settings', 'rankready-ai-llm-seo' ); ?></a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'rankready-ai-llm-seo' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Returns true when this user should see the "what's new" banner for
	 * the running version. Skips users who've already dismissed for this
	 * version. Only relevant on RankReady admin pages — caller should
	 * check screen first.
	 */
	public static function should_show_whatsnew( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$dismissed_for = (string) get_user_meta( $user_id, 'rnrd_whatsnew_dismissed_version', true );
		return $dismissed_for !== RNRD_VERSION;
	}

	/**
	 * Returns true when this user has unread release notes — drives the
	 * red-dot indicator on the "RankReady" menu item. Same gating as the
	 * banner so the dot disappears when the banner is dismissed.
	 */
	public static function has_unread_release_notes( int $user_id ): bool {
		// v1.2.0 — Always false. The menu "1" red-dot is reserved for MAJOR feature
		// releases only; maintenance/patch updates never nag. The dismissible
		// "What's new" sidebar card is the only surface that mentions a new version.
		unset( $user_id );
		return false;
	}

	/**
	 * Handles the "Dismiss" action on the what's new banner.
	 * GET-based with a nonce — single-click, no JS required.
	 */
	public static function handle_dismiss_actions(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( isset( $_GET['rnrd_dismiss_whatsnew'] ) && check_admin_referer( 'rnrd_dismiss_whatsnew' ) ) {
			update_user_meta( $user_id, 'rnrd_whatsnew_dismissed_version', RNRD_VERSION );
			wp_safe_redirect( remove_query_arg( array( 'rnrd_dismiss_whatsnew', '_wpnonce' ) ) );
			exit;
		}

		// Unlike the two dismissals above — which only touch the current user's own
		// meta — this deletes a SITE-WIDE option, so it needs a capability check as
		// well as the nonce. Nonces are per-user and this notice only renders for
		// admins, so this is defence-in-depth rather than a known exploit path.
		if ( isset( $_GET['rnrd_dismiss_model_migration'] )
			&& current_user_can( 'manage_options' )
			&& check_admin_referer( 'rnrd_dismiss_model_migration' ) ) {
			delete_option( 'rnrd_model_migrations' );
			wp_safe_redirect( remove_query_arg( array( 'rnrd_dismiss_model_migration', '_wpnonce' ) ) );
			exit;
		}
	}

	/**
	 * Dashboard "Free AI SEO tips by email" opt-in. POSTs from the sidebar card;
	 * on success it subscribes via the shared RNRD_Welcome webhook path and sets
	 * the per-admin user_meta flag so the card hides for this user only.
	 */
	public static function handle_dash_tips_optin(): void {
		if ( empty( $_POST['rnrd_dash_tips'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['_rnrd_tips_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_rnrd_tips_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'rnrd_dash_tips' ) ) {
			return;
		}

		$email = sanitize_email( wp_unslash( $_POST['rnrd_tips_email'] ?? '' ) );
		$fname = sanitize_text_field( wp_unslash( $_POST['rnrd_tips_first_name'] ?? '' ) );
		if ( class_exists( 'RNRD_Welcome' ) ) {
			RNRD_Welcome::subscribe_email( $email, $fname, 'RankReady Dashboard' );
		}

		wp_safe_redirect( add_query_arg( 'rnrd_tips', 'ok', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	// ── Status columns (deferred to wp_loaded so CPTs exist) ─────────────────

	public static function register_status_columns(): void {
		$public_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $public_types as $pt ) {
			if ( 'attachment' === $pt ) {
				continue;
			}
			add_filter( "manage_{$pt}_posts_columns",       array( self::class, 'add_status_column' ) );
			add_action( "manage_{$pt}_posts_custom_column",  array( self::class, 'render_status_column' ), 10, 2 );
		}
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public static function register_menu(): void {
		// Append a red-dot bubble to the menu label when this user hasn't
		// seen the latest release notes. Same WP-core CSS class the plugin
		// updates counter uses, so it inherits theme styling.
		// v1.2 — No "1" red-dot badge for maintenance releases. Policy: the menu
		// nag is reserved for MAJOR new features only, never basic bug-fix updates.
		// (v1.2 is bug fixes + WebMCP, which counts as basic — so no badge.)
		$menu_label = __( 'RankReady', 'rankready-ai-llm-seo' );

		add_menu_page(
			__( 'RankReady', 'rankready-ai-llm-seo' ),
			$menu_label,
			'manage_options',
			self::MENU_SLUG,
			array( self::class, 'render_page' ),
			self::menu_icon_data_uri(),
			81
		);
	}

	/**
	 * WP sidebar menu icon.
	 *
	 * Returns base64-encoded `data:image/svg+xml` URI from assets/logo-mark.svg.
	 * WP's esc_url() allows `data:image/svg+xml` (it's special-cased in core,
	 * unlike data:image/png which gets stripped). White fills render correctly
	 * against WP's dark sidebar.
	 *
	 * Falls back to dashicons-chart-area if the SVG asset is missing.
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function menu_icon_data_uri(): string {
		$path = RNRD_DIR . 'assets/logo-mark.svg';
		if ( ! is_readable( $path ) ) {
			return 'dashicons-chart-area';
		}
		$svg = (string) file_get_contents( $path );
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/**
	 * Asset version string for `wp_enqueue_*`. Uses filemtime() so every
	 * edit busts the browser cache automatically. Falls back to RNRD_VERSION
	 * if the file is unreadable (shouldn't happen but defends against weird
	 * file-permission setups).
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function asset_ver( string $relative_path ): string {
		$full = RNRD_DIR . ltrim( $relative_path, '/' );
		$mt   = file_exists( $full ) ? filemtime( $full ) : 0;
		return $mt ? RNRD_VERSION . '.' . $mt : RNRD_VERSION;
	}

	public static function enqueue_admin_assets( $hook ): void {
		// Use filemtime() so any CSS/JS edit forces a fresh download. The
		// stable RNRD_VERSION string would otherwise cache stale assets in
		// users' browsers across plugin updates that don't bump the version
		// (e.g. point fixes within the same rc.16 build).
		$tokens_ver = self::asset_ver( 'assets/design-tokens.css' );
		$admin_ver  = self::asset_ver( 'assets/admin.css' );
		$js_ver     = self::asset_ver( 'assets/admin.js' );

		// v1.1 — Per-screen asset scoping for performance. RankReady renders UI
		// on exactly two screen groups; everything else in wp-admin now gets
		// NOTHING (previously design-tokens.css leaked onto EVERY admin page, and
		// the full 147KB admin.css loaded on every post-edit + profile screen).
		$is_settings  = ( 'toplevel_page_' . self::MENU_SLUG === $hook );
		$is_post_edit = in_array( $hook, array( 'post.php', 'post-new.php' ), true );
		$is_profile   = in_array( $hook, array( 'profile.php', 'user-edit.php' ), true );

		// Post-edit screens: load ONLY where the RankReady meta box actually
		// renders — i.e. the current post type is one RankReady targets. Without
		// this gate, design-tokens.css + admin-screens.css loaded on EVERY
		// post.php (EDD `download`, WooCommerce `product`, Elementor templates,
		// any third-party CPT editor) even though no RankReady UI appears there.
		// RankReady chrome and its stylesheet now stay strictly on RankReady's
		// own screens — nothing touches core WP / EDD / other-plugin editors.
		if ( $is_post_edit ) {
			$screen   = get_current_screen();
			$cpt      = $screen ? (string) $screen->post_type : '';
			if ( ! in_array( $cpt, RNRD_Metabox::get_post_types(), true ) ) {
				return; // RankReady has no UI on this post type — enqueue nothing.
			}
		}

		if ( ! $is_settings && ! $is_post_edit && ! $is_profile ) {
			return; // No RankReady UI on this screen — enqueue nothing.
		}

		// Shared CSS-variable layer — only where RankReady chrome actually renders.
		wp_enqueue_style( 'rnrd-design-tokens', RNRD_URL . 'assets/design-tokens.css', array(), $tokens_ver );

		// Post-edit meta box + user-profile Author card need ONLY their own lean
		// stylesheet (meta box + author card sections, ~14KB) — never the 147KB
		// settings stylesheet. This is the "simple CSS on profile" fix.
		if ( $is_post_edit || $is_profile ) {
			wp_enqueue_style( 'rnrd-admin-screens', RNRD_URL . 'assets/admin-screens.css', array( 'rnrd-design-tokens' ), self::asset_ver( 'assets/admin-screens.css' ) );
			if ( $is_post_edit ) {
				wp_enqueue_script(
					'rnrd-metabox',
					RNRD_URL . 'assets/metabox.js',
					array( 'rnrd-i18n' ),
					self::asset_ver( 'assets/metabox.js' ),
					true
				);
				wp_localize_script( 'rnrd-metabox', 'rnrdMetabox', array(
					'restUrl'  => esc_url_raw( rest_url( 'rankready/v1/' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'cooldown' => 60,
					'i18n'     => self::metabox_js_i18n(),
				) );
			}
			return;
		}

		// ── RankReady settings page only, below ──────────────────────────────
		// v1.2.1 — the Google Fonts enqueue for Inter was REMOVED. Two reasons,
		// both non-negotiable:
		//   1. WordPress.org requires plugin assets to be served locally, not
		//      hotlinked from a third-party CDN.
		//   2. Hotlinking a remote font CDN transmits the administrator's IP to
		//      that third party on every admin page load with no consent —
		//      ruled unlawful under GDPR (Munich Regional Court, Jan 2022).
		// The design tokens already declare a full system-font fallback stack
		// (-apple-system, Segoe UI, sans-serif), so the UI renders correctly
		// without it. Do NOT re-add a remote font request.
		wp_enqueue_style( 'rnrd-admin', RNRD_URL . 'assets/admin.css', array( 'rnrd-design-tokens' ), $admin_ver );
		wp_enqueue_script( 'rnrd-admin', RNRD_URL . 'assets/admin.js', array( 'rnrd-i18n' ), $js_ver, true );
		wp_localize_script( 'rnrd-admin', 'rnrdAdmin', array(
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'apiBase' => rest_url( 'rankready/v1' ),
			'i18n'    => self::admin_js_i18n(),
		) );

		// v1.1.0 — Inline Cloudflare card controller. Kept inline so the card's
		// connect / disconnect flow ships in the Free build without bloating
		// admin.js. ~50 lines. Strings come from rnrdAdmin.i18n (admin_js_i18n).
		wp_add_inline_script(
			'rnrd-admin',
			"(function(){\n" .
			"  var t = window.rnrdI18n.bind((window.rnrdAdmin||{}).i18n||{}).t;\n" .
			"  var btnConnect = document.getElementById('rnrd-cf-connect');\n" .
			"  var btnDisco   = document.getElementById('rnrd-cf-disconnect');\n" .
			"  function apiCall(path, body) {\n" .
			"    return fetch(window.rnrdAdmin.apiBase + path, {\n" .
			"      method: 'POST',\n" .
			"      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.rnrdAdmin.nonce },\n" .
			"      body: JSON.stringify(body || {})\n" .
			"    }).then(function(r){ return r.json().then(function(j){ return [r.status, j]; }); });\n" .
			"  }\n" .
			"  function setMsg(text, ok) {\n" .
			"    var el = document.getElementById('rnrd-cf-msg');\n" .
			"    if (!el) return;\n" .
			"    el.textContent = text;\n" .
			"    el.style.color = ok ? '#047857' : '#B91C1C';\n" .
			"  }\n" .
			"  if (btnConnect) {\n" .
			"    btnConnect.addEventListener('click', function(){\n" .
			"      var token = (document.getElementById('rnrd-cf-token') || {}).value || '';\n" .
			"      if (!token) { setMsg(t('cfTokenRequired','A Cloudflare API token is required.'), false); return; }\n" .
			"      var body = { mode: 'token', token: token };\n" .
			"      btnConnect.disabled = true;\n" .
			"      setMsg(t('cfConnecting','Connecting…'), true);\n" .
			"      apiCall('/cloudflare/connect', body)\n" .
			"        .then(function(parts){\n" .
			"          var status = parts[0], data = parts[1];\n" .
			"          btnConnect.disabled = false;\n" .
			"          if (status >= 200 && status < 300 && data.success) { setMsg(t('cfRuleCreated','Rule created. Reloading…'), true); setTimeout(function(){ location.reload(); }, 800); }\n" .
			"          else { setMsg((data && data.error) || t('cfConnectFailed','Failed to connect.'), false); }\n" .
			"        })\n" .
			"        .catch(function(){ btnConnect.disabled = false; setMsg(t('cfNetworkError','Network error.'), false); });\n" .
			"    });\n" .
			"  }\n" .
			"  if (btnDisco) {\n" .
			"    btnDisco.addEventListener('click', function(){\n" .
			"      if (!confirm(t('cfDisconnectConfirm','Remove the Cloudflare cache rule? AI markdown requests will hit APO again.'))) return;\n" .
			"      btnDisco.disabled = true;\n" .
			"      apiCall('/cloudflare/disconnect', {}).then(function(){ location.reload(); });\n" .
			"    });\n" .
			"  }\n" .
			"})();"
		);
	}

	// ── Settings API ──────────────────────────────────────────────────────────

	public static function register_settings(): void {

		// ═══ Settings Tab ═════════════════════════════════════════════════════

		// Active LLM provider — drives which key/model is used by RNRD_LLM.
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_LLM_PROVIDER, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_llm_provider' ),
			'default'           => 'openai',
		) );

		// OpenAI key + model.
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_api_key' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_provider_model' ),
			'default'           => 'gpt-5.4-mini',
		) );

		// Anthropic (Claude) key + model.
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_ANTHROPIC_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_anthropic_key' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_ANTHROPIC_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_provider_model' ),
			'default'           => 'claude-haiku-4-5',
		) );

		// Gemini key + model.
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_GEMINI_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_gemini_key' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_GEMINI_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_provider_model' ),
			'default'           => 'gemini-2.5-flash',
		) );

		// DeepSeek key + model.
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_DEEPSEEK_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_deepseek_key' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_DEEPSEEK_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_provider_model' ),
			'default'           => 'deepseek-v4-flash',
		) );

		// v1.0.1 — These 8 options were previously registered against
		// SETTINGS_GROUP but the Content tab's Summary form (render_content_sub_summary)
		// posts to CONTENT_GROUP. options.php silently dropped them on save.
		// Repointed to CONTENT_GROUP to match the form they're actually in.
		register_setting( self::CONTENT_GROUP, RNRD_OPT_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types_optional' ),
			'default'           => array( 'post' ),
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_CUSTOM_PROMPT, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		// v1.2.0-rc.3 — Product Context kept registered for back-compat reads,
		// but the input field is removed from the UI. The "About" field on
		// the Brand Identity card now serves both llms.txt content AND prompt
		// injection (RNRD_Generator + RNRD_Faq read RNRD_Llms_Txt::get_brand_about()
		// with rnrd_product_context as legacy fallback).
		register_setting( self::CONTENT_GROUP, RNRD_OPT_PRODUCT_CONTEXT, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_AUTO_GENERATE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// v1.2.0-rc.3 — Data Retention moved to its own group so the "Save
		// Data Retention" form on the Advanced tab can save just one toggle
		// without nullifying other Settings options. Same isolation pattern
		// Brand Identity uses.
		register_setting( self::DATA_GROUP, RNRD_OPT_DELETE_ON_UNINSTALL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_SUMMARY_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_AUTO_DISPLAY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_auto_display' ),
			'default'           => 'off',
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_LABEL, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'Key Takeaways',
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_SHOW_LABEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_checkbox_field' ),
			'default'           => '1',
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_HEADING_TAG, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_heading_tag' ),
			'default'           => 'h4',
		) );

		// ═══ LLM Optimization Tab ═════════════════════════════════════════════

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// v1.2.0-rc.3 — Brand Identity options live in their own group so the
		// "Save Brand Identity" form (which only POSTs these 4 fields) does
		// NOT cause options.php to null-out every other LLMS_GROUP setting.
		register_setting( self::BRAND_GROUP, RNRD_OPT_LLMS_SITE_NAME, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( self::BRAND_GROUP, RNRD_OPT_LLMS_SUMMARY, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::BRAND_GROUP, RNRD_OPT_LLMS_ABOUT, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types' ),
			'default'           => array( 'post', 'page' ),
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_MAX_POSTS, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 100,
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_CACHE_TTL, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 3600,
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_FULL_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_EXCLUDE_CATS, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_term_ids' ),
			'default'           => array(),
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_EXCLUDE_TAGS, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_term_ids' ),
			'default'           => array(),
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_SHOW_CATEGORIES, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_USE_MD_URLS, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_ROBOTS_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		// v1.2.1 — The Allow/Default/Block radio posts ONE map; the two legacy
		// arrays are derived from it (see apply_robots_mode).
		//
		// RNRD_OPT_ROBOTS_CRAWLERS and RNRD_OPT_ROBOTS_BLOCKED are deliberately
		// NOT registered in this group any more. options.php calls
		// update_option( $opt, null ) for every registered option missing from
		// POST, and sanitize_crawler_list( null ) returns array() — so leaving
		// them registered while removing their inputs would silently wipe both
		// lists on save. That is trap #1 in the runtime checklist. They remain
		// ordinary options, written only by apply_robots_mode().
		register_setting( self::LLMS_GROUP, RNRD_OPT_ROBOTS_MODE, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_robots_mode' ),
			'default'           => array(),
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_MD_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_MD_HOME_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_MD_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types' ),
			'default'           => array( 'post', 'page' ),
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_MD_INCLUDE_META, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_checkbox_field' ),
			'default'           => '1',
		) );

		// Content Signals.
		register_setting( self::LLMS_GROUP, RNRD_OPT_CONTENT_SIGNALS_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_content_signal' ),
			'default'           => 'allow',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_CONTENT_SIGNALS_SEARCH, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_content_signal' ),
			'default'           => 'allow',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_CONTENT_SIGNALS_AI_INPUT, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_content_signal' ),
			'default'           => 'allow',
		) );

		// v1.2.0-rc.3 — Moved to BRAND_GROUP (see note above on Brand Identity).
		register_setting( self::BRAND_GROUP, RNRD_OPT_BRAND_TERMS, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		// v1.2.0 — Hide "Generated from RankReady" credit line in llms.txt / llms-full.txt (Pro).
		register_setting( self::LLMS_GROUP, RNRD_OPT_HIDE_BRANDING, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// v1.2.0 — AI Snippet preview default (per-post override lives in the meta box).
		register_setting( self::LLMS_GROUP, RNRD_OPT_MAX_SNIPPET_DEFAULT, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		// Insights tracking toggles. Own group so Visibility saves never POST
		// (or wipe) these options.
		register_setting( self::INSIGHTS_GROUP, RNRD_OPT_AI_TRAINING_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );
		register_setting( self::INSIGHTS_GROUP, RNRD_OPT_AI_CITATION_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );
		register_setting( self::INSIGHTS_GROUP, RNRD_OPT_AI_REFERRAL_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		// v1.2.0-beta.3 — WebMCP master toggle.
		// Opt-in: matches onboarding (unchecked until the user ticks WebMCP)
		// and RNRD_MCP::is_enabled(). Existing rows that saved 'on' stay on.
		register_setting( self::LLMS_GROUP, RNRD_OPT_MCP_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// v1.2.0-beta.6 — Per-resource MCP exposure toggles.
		// v1.2.0: safe public resources default ON (exposed out of the box, matching
		// exposure_state + onboarding). Sensitive / PII resources (comments, media, users, plugins,
		// themes, settings) are not exposed by WebMCP — their unwired toggles were removed in 1.2.1 so
		// the settings UI can never advertise a resource the manifest does not actually serve.
		// One default everywhere so the settings UI and the manifest never disagree.
		$rnrd_mcp_all_toggles = array(
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
		// v1.2.0 — safe public resources default ON; sensitive resources default OFF.
		$rnrd_mcp_safe_defaults_on = array(
			RNRD_OPT_MCP_EXPOSE_POSTS, RNRD_OPT_MCP_EXPOSE_PAGES, RNRD_OPT_MCP_EXPOSE_AUTHORS,
			RNRD_OPT_MCP_EXPOSE_TAXONOMIES, RNRD_OPT_MCP_EXPOSE_SITEMAP, RNRD_OPT_MCP_EXPOSE_MENUS,
			RNRD_OPT_MCP_EXPOSE_LLMS_TXT, RNRD_OPT_MCP_EXPOSE_RR_AI, RNRD_OPT_MCP_EXPOSE_FRESHNESS,
		);
		foreach ( $rnrd_mcp_all_toggles as $opt ) {
			register_setting( self::LLMS_GROUP, $opt, array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
				'default'           => in_array( $opt, $rnrd_mcp_safe_defaults_on, true ) ? 'on' : 'off',
			) );
		}
		// CPT opt-in list — array of slugs the user has explicitly enabled.
		register_setting( self::LLMS_GROUP, RNRD_OPT_MCP_EXPOSE_CPTS, array(
			'type'              => 'array',
			'sanitize_callback' => function ( $value ) {
				return is_array( $value ) ? array_values( array_filter( array_map( 'sanitize_key', $value ) ) ) : array();
			},
			'default'           => array(),
		) );

		// v1.2.0-beta.3 — Markdown sub-toggles.
		register_setting( self::LLMS_GROUP, RNRD_OPT_MD_HINT_DIV, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );
		register_setting( self::LLMS_GROUP, RNRD_OPT_MD_BOT_AUTO_SERVE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );
		// v1.1.2 — Same-URL Accept negotiation. DEFAULT OFF (cache-poisoning risk
		// behind Cloudflare APO / Varnish / shared-host caches). Markdown is
		// served at distinct .md URLs regardless of this toggle.
		register_setting( self::LLMS_GROUP, RNRD_OPT_MD_ACCEPT_NEGOTIATION, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on', // v1.2 — same-URL Accept negotiation on by default (auto-guarded off on Cloudflare APO)
		) );

		// ── DataForSEO credentials (Settings tab, same save as OpenAI) ──────
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_DFS_LOGIN, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_dfs_login' ),
			'default'           => '',
		) );

		register_setting( self::SETTINGS_GROUP, RNRD_OPT_DFS_PASSWORD, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_dfs_password' ),
			'default'           => '',
		) );

		// ═══ FAQ Tab (Content AI) ═════════════════════════════════════════════

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types_optional' ),
			'default'           => array( 'post' ),
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_COUNT, array(
			'type'              => 'integer',
			// v1.1.2 — Clamp to [3, 10] on every save. Treat 0 / negative / out-of-range
			// values as "use default" (5). This is also the backward-compat fix for
			// installs where the pre-1.1.0 Settings-API cross-nulling bug stored 0.
			'sanitize_callback' => static function ( $v ) {
				$v = absint( $v );
				if ( $v < 3 || $v > 10 ) {
					return 5;
				}
				return $v;
			},
			'default'           => 5,
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_BRAND_TERMS, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_AUTO_DISPLAY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_auto_display' ),
			'default'           => 'off',
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_HEADING_TAG, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_heading_tag' ),
			'default'           => 'h3',
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_SHOW_REVIEWED, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_AUTO_GENERATE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// ═══ Schema Automation Tab ═══════════════════════════════════════════

		register_setting( self::SCHEMA_GROUP, RNRD_OPT_SCHEMA_ARTICLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RNRD_OPT_SCHEMA_FAQ, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RNRD_OPT_SCHEMA_HOWTO, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RNRD_OPT_SCHEMA_ITEMLIST, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RNRD_OPT_SCHEMA_SPEAKABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RNRD_OPT_SCHEMA_BATCH_SIZE, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 10,
		) );

		// ═══ Headless / Public API Tab ═══════════════════════════════════════════

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_CORS_ORIGINS, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_cors_origins' ),
			'default'           => '',
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_EXPOSE_META, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_CACHE_TTL, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 300,
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_RATE_LIMIT, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 120,
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_REVALIDATE_URL, array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_REVALIDATE_SEC, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_revalidate_secret' ),
			'default'           => '',
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_GRAPHQL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// ═══ Open Knowledge Format (OKF) — isolated group (v1.1.5) ═══════════
		register_setting( self::OKF_GROUP, RNRD_OPT_OKF_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );
		register_setting( self::OKF_GROUP, RNRD_OPT_OKF_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => function ( $v ) {
				if ( ! is_array( $v ) ) {
					return array( 'post', 'page' );
				}
				$clean = array_values( array_filter( array_map( 'sanitize_key', $v ) ) );
				return ! empty( $clean ) ? $clean : array( 'post', 'page' );
			},
			'default'           => array( 'post', 'page' ),
		) );

		// ── Author Box settings ──────────────────────────────────────────
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_AUTO_DISPLAY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_author_auto_display' ),
			'default'           => 'off',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_LAYOUT, array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, array( 'card', 'compact', 'inline' ), true ) ? $v : 'card';
			},
			'default'           => 'card',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_HEADING, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'About the Author',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_HEADING_TAG, array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ), true ) ? $v : 'h3';
			},
			'default'           => 'h3',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_SCHEMA_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_EDITORIAL_URL, array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_FACTCHECK_URL, array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types_optional' ),
			'default'           => array( 'post' ),
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_TRUST_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );
	}

	/**
	 * Sanitize CORS origins — comma-separated list of valid URLs.
	 */
	public static function sanitize_cors_origins( $value ): string {
		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			return '';
		}
		$parts = array_map( 'trim', explode( ',', $value ) );
		$valid = array();
		foreach ( $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			if ( filter_var( $p, FILTER_VALIDATE_URL ) ) {
				$valid[] = rtrim( esc_url_raw( $p ), '/' );
			}
		}
		return implode( ',', array_unique( $valid ) );
	}

	/**
	 * Sanitize revalidate secret. Preserves sentinel (don't change) and mask.
	 */
	public static function sanitize_revalidate_secret( $value ): string {
		$value = (string) $value;
		if ( '__UNCHANGED__' === $value ) {
			return (string) get_option( RNRD_OPT_HEADLESS_REVALIDATE_SEC, '' );
		}
		if ( false !== strpos( $value, "\xE2\x80\xA2" ) ) {
			return (string) get_option( RNRD_OPT_HEADLESS_REVALIDATE_SEC, '' );
		}
		return sanitize_text_field( $value );
	}

	// ── Sanitize callbacks ────────────────────────────────────────────────────

	public static function sanitize_api_key( $value ): string {
		$value = (string) $value;
		// v1.1.2 — On a NEW option, WordPress runs the sanitize filter a SECOND
		// time inside add_option(), AFTER RNRD_Crypto's pre_update_option filter
		// has already encrypted the value. The ciphertext won't match the key
		// regex below, so without this guard the second pass returns the empty
		// stored fallback and the key saves blank — the "API key won't save on a
		// fresh install" bug. Pass already-encrypted values straight through.
		if ( class_exists( 'RNRD_Crypto' ) && RNRD_Crypto::is_encrypted( $value ) ) {
			return $value;
		}
		$value = sanitize_text_field( $value );
		// Sentinel or masked value means "don't change".
		if ( '__UNCHANGED__' === $value || false !== strpos( $value, '••••' ) ) {
			return (string) get_option( RNRD_OPT_KEY, '' );
		}
		// v1.2 — Never DROP the key on a format mismatch. OpenAI now issues
		// sk-proj-/sk-svcacct-/sk-admin- keys and formats keep drifting; the real
		// validation is the live "Verify key" call. Save what the user entered and
		// only hint if it looks unusual (non-blocking 'warning', not an 'error').
		if ( ! empty( $value ) && ! preg_match( '/^sk-[A-Za-z0-9\-_]{20,}$/', $value ) ) {
			add_settings_error( RNRD_OPT_KEY, 'rnrd_key_format',
				__( 'Saved. That doesn\'t look like a typical OpenAI key (sk-…) — use “Verify key” to confirm it works.', 'rankready-ai-llm-seo' ), 'warning' );
		}
		return $value;
	}

	/**
	 * Anthropic keys begin with `sk-ant-`.
	 */
	public static function sanitize_anthropic_key( $value ): string {
		$value = (string) $value;
		// v1.1.2 — pass already-encrypted ciphertext through (WP re-sanitizes on
		// add_option, after RNRD_Crypto has encrypted). See sanitize_api_key().
		if ( class_exists( 'RNRD_Crypto' ) && RNRD_Crypto::is_encrypted( $value ) ) {
			return $value;
		}
		$value = sanitize_text_field( $value );
		if ( '__UNCHANGED__' === $value || false !== strpos( $value, '••••' ) ) {
			return (string) get_option( RNRD_OPT_ANTHROPIC_KEY, '' );
		}
		// v1.2 — Never DROP the key on a format mismatch (see sanitize_api_key).
		// The live "Verify key" call is the real validator; save what was entered.
		if ( ! empty( $value ) && ! preg_match( '/^sk-ant-[A-Za-z0-9\-_]{20,}$/', $value ) ) {
			add_settings_error( RNRD_OPT_ANTHROPIC_KEY, 'rnrd_anthropic_key_format',
				__( 'Saved. That doesn\'t look like a typical Anthropic key (sk-ant-…) — use “Verify key” to confirm it works.', 'rankready-ai-llm-seo' ), 'warning' );
		}
		return $value;
	}

	/**
	 * Google AI Studio keys begin with `AIza`.
	 */
	public static function sanitize_gemini_key( $value ): string {
		$value = (string) $value;
		// v1.1.2 — pass already-encrypted ciphertext through. See sanitize_api_key().
		if ( class_exists( 'RNRD_Crypto' ) && RNRD_Crypto::is_encrypted( $value ) ) {
			return $value;
		}
		$value = sanitize_text_field( $value );
		if ( '__UNCHANGED__' === $value || false !== strpos( $value, '••••' ) ) {
			return (string) get_option( RNRD_OPT_GEMINI_KEY, '' );
		}
		// v1.2 — Never DROP the key on a format mismatch (see sanitize_api_key).
		// Google issues keys that don't all start with AIza; the live "Verify key"
		// call is the real validator. Save what the user entered.
		if ( ! empty( $value ) && ! preg_match( '/^AIza[A-Za-z0-9\-_]{20,}$/', $value ) ) {
			add_settings_error( RNRD_OPT_GEMINI_KEY, 'rnrd_gemini_key_format',
				__( 'Saved. That doesn\'t look like a typical Gemini key (AIza…) — use “Verify key” to confirm it works.', 'rankready-ai-llm-seo' ), 'warning' );
		}
		return $value;
	}

	/**
	 * DeepSeek keys begin with `sk-`. Same prefix as OpenAI but a different
	 * issuer — we only check format and length, not API validation here.
	 */
	public static function sanitize_deepseek_key( $value ): string {
		$value = (string) $value;
		// v1.1.2 — pass already-encrypted ciphertext through. See sanitize_api_key().
		if ( class_exists( 'RNRD_Crypto' ) && RNRD_Crypto::is_encrypted( $value ) ) {
			return $value;
		}
		$value = sanitize_text_field( $value );
		if ( '__UNCHANGED__' === $value || false !== strpos( $value, '••••' ) ) {
			return (string) get_option( RNRD_OPT_DEEPSEEK_KEY, '' );
		}
		// v1.2 — Never DROP the key on a format mismatch (see sanitize_api_key).
		// DeepSeek keys can contain - and _; the live "Verify key" call is the real
		// validator. Save what the user entered.
		if ( ! empty( $value ) && ! preg_match( '/^sk-[A-Za-z0-9\-_]{20,}$/', $value ) ) {
			add_settings_error( RNRD_OPT_DEEPSEEK_KEY, 'rnrd_deepseek_key_format',
				__( 'Saved. That doesn\'t look like a typical DeepSeek key (sk-…) — use “Verify key” to confirm it works.', 'rankready-ai-llm-seo' ), 'warning' );
		}
		return $value;
	}

	/**
	 * Active LLM provider: must be one of the four known IDs.
	 */
	public static function sanitize_llm_provider( $value ): string {
		$value = sanitize_key( (string) $value );
		$valid = array( 'openai', 'anthropic', 'gemini', 'deepseek' );
		return in_array( $value, $valid, true ) ? $value : 'openai';
	}

	/**
	 * Model ID sanitizer for every LLM provider (OpenAI, Anthropic, Gemini, DeepSeek).
	 * Light validation only — no hard allowlist — so curated / future model IDs
	 * save correctly without a plugin update.
	 */
	public static function sanitize_provider_model( $value ): string {
		$value = sanitize_text_field( (string) $value );
		// Strip anything that isn't a safe model-ID character
		// (alphanumerics, dot, dash, underscore, slash).
		$value = preg_replace( '/[^a-zA-Z0-9._\-\/]/', '', $value );
		return (string) $value;
	}

	public static function sanitize_dfs_login( $value ): string {
		$value = sanitize_text_field( (string) $value );
		if ( '__UNCHANGED__' === $value ) {
			return (string) get_option( RNRD_OPT_DFS_LOGIN, '' );
		}
		return $value;
	}

	public static function sanitize_dfs_password( $value ): string {
		$value = (string) $value;
		// v1.1.2 — pass already-encrypted ciphertext through untouched. WP
		// re-sanitizes on add_option() after RNRD_Crypto has encrypted, and the
		// preg_replace() below would otherwise strip base64 chars (+, /, :, =)
		// from the ciphertext and corrupt the stored secret. See sanitize_api_key().
		if ( class_exists( 'RNRD_Crypto' ) && RNRD_Crypto::is_encrypted( $value ) ) {
			return $value;
		}
		// Sentinel from FAQ tab hidden field.
		if ( '__UNCHANGED__' === $value ) {
			return (string) get_option( RNRD_OPT_DFS_PASSWORD, '' );
		}
		// Masked display value — don't overwrite stored password.
		if ( false !== strpos( $value, "\xE2\x80\xA2" ) ) {
			return (string) get_option( RNRD_OPT_DFS_PASSWORD, '' );
		}
		// Empty means user cleared it.
		if ( '' === trim( $value ) ) {
			return '';
		}
		// Real password — store as-is (no sanitize_text_field, it can mangle hex strings).
		return trim( $value );
	}

	public static function sanitize_post_types( $value ): array {
		if ( ! is_array( $value ) ) {
			return array( 'post' );
		}
		$allowed = array_keys( self::get_allowed_post_types() );
		$clean   = array_values( array_intersect( array_map( 'sanitize_key', $value ), $allowed ) );
		return ! empty( $clean ) ? $clean : array( 'post' );
	}

	/**
	 * Post types for Summary, FAQ, and Author Box. Empty is allowed (feature
	 * applies to no types). llms.txt / Markdown keep sanitize_post_types().
	 */
	public static function sanitize_post_types_optional( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$allowed = array_keys( self::get_allowed_post_types() );
		return array_values( array_intersect( array_map( 'sanitize_key', $value ), $allowed ) );
	}

	public static function sanitize_term_ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_map( 'absint', array_filter( $value ) ) );
	}

	public static function sanitize_checkbox_field( $value ): string {
		return ! empty( $value ) ? '1' : '0';
	}

	public static function sanitize_heading_tag( $value ): string {
		$allowed = array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' );
		$value   = sanitize_text_field( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : 'h4';
	}

	public static function sanitize_auto_display( $value ): string {
		return in_array( $value, array( 'off', 'before', 'after' ), true ) ? $value : 'off';
	}

	public static function sanitize_author_auto_display( $value ): string {
		return in_array( $value, array( 'off', 'before', 'after', 'both' ), true ) ? $value : 'off';
	}

	public static function sanitize_display_position( $value ): string {
		return in_array( $value, array( 'before', 'after' ), true ) ? $value : 'before';
	}

	public static function sanitize_on_off( $value ): string {
		return in_array( $value, array( 'on', 'off' ), true ) ? $value : 'off';
	}

	public static function sanitize_content_signal( $value ): string {
		return in_array( $value, array( 'allow', 'deny' ), true ) ? $value : 'allow';
	}

	public static function sanitize_crawler_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$allowed = array_keys( self::get_llm_crawlers() );
		return array_values( array_intersect( array_map( 'sanitize_text_field', $value ), $allowed ) );
	}

	/**
	 * Current Allow / Default / Block state for every known crawler.
	 *
	 * Derived from the two legacy arrays, so installs that have never saved the
	 * radio still render correctly with no migration step and no DB write.
	 * Block wins when a crawler somehow appears in both, matching the precedence
	 * generate_robots_block() already applies.
	 *
	 * @since 1.2.1
	 * @return array<string,string> user-agent => 'allow'|'block'|'default'
	 */
	public static function get_robots_mode(): array {
		return RNRD_Crawler_Access::get_robots_mode();
	}

	/**
	 * Sanitize the posted Allow/Default/Block map.
	 *
	 * A crawler missing from POST keeps its CURRENT state rather than falling
	 * back to a default. That is the safe direction: if markup, JS or a proxy
	 * ever drops a field, the user's saved choice survives instead of silently
	 * resetting. Unknown keys and unknown values are discarded.
	 *
	 * @since 1.2.1
	 * @param mixed $value Raw POST value.
	 * @return array<string,string>
	 */
	public static function sanitize_robots_mode( $value ): array {
		$current = self::get_robots_mode();
		if ( ! is_array( $value ) ) {
			return $current;
		}
		$valid = array( 'allow', 'block', 'default' );
		$out   = array();
		foreach ( $current as $ua => $existing ) {
			$posted     = isset( $value[ $ua ] ) ? sanitize_text_field( (string) $value[ $ua ] ) : '';
			$out[ $ua ] = in_array( $posted, $valid, true ) ? $posted : $existing;
		}
		return $out;
	}

	/**
	 * Write the two legacy arrays from the mode map.
	 *
	 * RNRD_OPT_ROBOTS_CRAWLERS and RNRD_OPT_ROBOTS_BLOCKED remain the source of
	 * truth for robots.txt output — nothing downstream had to change. Writing
	 * them here also fires their existing update_option_ hooks, so the physical
	 * robots.txt re-syncs exactly as before.
	 *
	 * @since 1.2.1
	 * @param array $mode user-agent => 'allow'|'block'|'default'
	 */
	public static function apply_robots_mode( array $mode ): void {
		RNRD_Crawler_Access::apply_robots_mode( $mode );
	}

	/**
	 * Get the full list of known LLM/AI crawlers with metadata.
	 *
	 * @return array Associative array: user-agent => array( company, purpose ).
	 */
	public static function get_llm_crawlers(): array {
		return RNRD_Crawler_Access::get_llm_crawlers();
	}

	// ── Main render ───────────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rankready-ai-llm-seo' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only $_GET[tab] for display routing; no state change.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';

		// Former Advanced top-level tab (and its predecessors) → Settings → Advanced subtab.
		$advanced_legacy = array( 'advanced', 'headless', 'tools', 'info' );
		if ( in_array( $active_tab, $advanced_legacy, true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=settings&sub=advanced' ) );
			exit;
		}

		// Former Content AI / E-E-A-T top-level slugs → Content subtabs.
		$content_legacy = array(
			'authority' => 'author',
			'author'    => 'author',
			'schema'    => 'schema',
			'summary'   => 'summary',
			'faq'       => 'faq',
		);
		if ( isset( $content_legacy[ $active_tab ] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=content&sub=' . $content_legacy[ $active_tab ] ) );
			exit;
		}

		// Redirect old tab slugs to new merged tabs (backward compat for bookmarks / links).
		$legacy_map = array(
			'settings' => 'settings',
			'api'      => 'settings',
			'llm'      => 'crawlers',
		);
		if ( isset( $legacy_map[ $active_tab ] ) ) {
			$active_tab = $legacy_map[ $active_tab ];
		}

		$tabs = array(
			'dashboard' => __( 'Dashboard', 'rankready-ai-llm-seo' ),
			'crawlers'  => __( 'AI Visibility', 'rankready-ai-llm-seo' ), // subtabs: Brand | Robots | LLMs.txt | Markdown | WebMCP | OKF
			'content'   => __( 'AI Content', 'rankready-ai-llm-seo' ), // subtabs: AI Summary | AI FAQ Generator | Author Box | Schema
			'insights'  => __( 'Insights', 'rankready-ai-llm-seo' ),  // v1.2.0-beta.7 — Bot Activity / Citation / Referral / Freshness
			'settings'  => __( 'Settings', 'rankready-ai-llm-seo' ),  // subtabs: API Keys | Cloudflare | Advanced
		);

		if ( ! array_key_exists( $active_tab, $tabs ) ) {
			$active_tab = 'dashboard';
		}
		?>
		<div class="wrap rnrd-wrap">
			<?php
			// v1.2.0 — The wp-header-end marker as the FIRST child of .wrap. WordPress
			// relocates every admin notice to immediately ABOVE this element, so notices
			// land at the very top — above RankReady's branded header — instead of being
			// injected after the <h1> and splitting the header apart. Core hides the marker
			// (visibility:hidden), so it adds no visible line. RankReady's own page never
			// gets broken by another plugin's (or our own) notice again.
			?>
			<hr class="wp-header-end" />
			<div class="rnrd-header">
				<img class="rnrd-header__logo" src="<?php echo esc_url( RNRD_URL . 'assets/logo-source.png' ); ?>" alt="" width="44" height="44" />
				<div class="rnrd-header__text">
					<h1 class="rnrd-title">
						<?php esc_html_e( 'RankReady', 'rankready-ai-llm-seo' ); ?>
						<span class="rnrd-version">v<?php echo esc_html( RNRD_VERSION ); ?></span>
						<a class="rnrd-header__home-link" href="https://hostmy.blog/plugins/rankready/" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Official Website', 'rankready-ai-llm-seo' ); ?>
							<span aria-hidden="true">↗</span>
						</a>
					</h1>
					<p class="rnrd-subtitle"><?php esc_html_e( 'LLM SEO, EEAT &amp; AI Optimization for WordPress', 'rankready-ai-llm-seo' ); ?></p>
				</div>
			</div>

			<nav class="nav-tab-wrapper rnrd-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="rnrd-dash-layout">
				<main class="rnrd-dash-main">
				<?php
				// v1.2.0-rc.7 — Show one-time success notice after a quick-enable POST.
				self::render_quick_enable_banner();
				?>
				<?php
				switch ( $active_tab ) {
					case 'dashboard':
						self::render_tab_dashboard();
						break;
					case 'content':
						self::render_tab_content();
						break;
					case 'crawlers':
						self::render_tab_llm();
						break;
					case 'insights':
						self::render_tab_insights();
						break;
					case 'settings':
						self::render_tab_settings();
						break;
				}
				?>
				</main>
				<?php self::render_dashboard_sidebar(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Shared right-rail sidebar — renders on every tab.
	 *
	 * Order: Tips opt-in (if pending) → What's New (if undismissed) → Connect
	 * with us → Rate widget. Mobile (≤1100px) collapses to single column via
	 * CSS Section 43.
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function render_dashboard_sidebar(): void {
		$user_id       = get_current_user_id();
		$show_whatsnew = self::should_show_whatsnew( $user_id );
		$dismiss_url   = $show_whatsnew ? wp_nonce_url(
			admin_url( 'admin.php?page=' . self::MENU_SLUG . '&rnrd_dismiss_whatsnew=' . RNRD_VERSION ),
			'rnrd_dismiss_whatsnew'
		) : '';
		?>
		<aside class="rnrd-dash-aside">

			<?php
			// Tips opt-in first — primary growth CTA. Hidden after subscribe or
			// when Freemius-registered as the site admin (see should_show_tips_optin).
			if ( class_exists( 'RNRD_Welcome' ) && RNRD_Welcome::should_show_tips_optin() ) :
				$rnrd_dash_user  = wp_get_current_user();
				$rnrd_dash_email = ( $rnrd_dash_user && ! empty( $rnrd_dash_user->user_email ) )
					? $rnrd_dash_user->user_email
					: get_bloginfo( 'admin_email' );
				$rnrd_dash_fname = ( $rnrd_dash_user && ! empty( $rnrd_dash_user->first_name ) )
					? $rnrd_dash_user->first_name
					: '';
			?>
			<div class="rnrd-aside-card rnrd-aside-card--tips">
				<h3 class="rnrd-aside-card__title"><?php esc_html_e( 'Free AI SEO tips by email', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-aside-card__sub"><?php esc_html_e( 'Practical AI SEO tips and tricks, plus RankReady product updates — straight to your inbox. No spam, unsubscribe anytime.', 'rankready-ai-llm-seo' ); ?></p>
				<form method="post" action="" class="rnrd-tips-form">
					<?php wp_nonce_field( 'rnrd_dash_tips', '_rnrd_tips_nonce' ); ?>
					<input type="hidden" name="rnrd_dash_tips" value="1" />
					<input
						type="text"
						name="rnrd_tips_first_name"
						value="<?php echo esc_attr( $rnrd_dash_fname ); ?>"
						class="rnrd-tips-form__input"
						placeholder="<?php esc_attr_e( 'First name', 'rankready-ai-llm-seo' ); ?>"
						maxlength="60"
						autocomplete="off"
						data-1p-ignore="true"
						data-lpignore="true"
					/>
					<input
						type="email"
						name="rnrd_tips_email"
						value="<?php echo esc_attr( $rnrd_dash_email ); ?>"
						class="rnrd-tips-form__input"
						placeholder="<?php esc_attr_e( 'you@example.com', 'rankready-ai-llm-seo' ); ?>"
						autocomplete="off"
						data-1p-ignore="true"
						data-lpignore="true"
						required
					/>
					<button type="submit" class="rnrd-tips-form__btn"><?php esc_html_e( 'Send me tips', 'rankready-ai-llm-seo' ); ?></button>
				</form>
			</div>
			<?php endif; ?>

			<?php if ( $show_whatsnew ) : ?>
			<div class="rnrd-aside-card rnrd-aside-card--whatsnew">
				<div class="rnrd-aside-card__head">
					<h3 class="rnrd-aside-card__title">
						<span class="rnrd-whatsnew__tag"><?php esc_html_e( 'NEW', 'rankready-ai-llm-seo' ); ?></span>
						<?php esc_html_e( "What's new", 'rankready-ai-llm-seo' ); ?>
					</h3>
					<a href="<?php echo esc_url( $dismiss_url ); ?>" class="rnrd-aside-card__close" aria-label="<?php esc_attr_e( 'Dismiss', 'rankready-ai-llm-seo' ); ?>" title="<?php esc_attr_e( 'Dismiss', 'rankready-ai-llm-seo' ); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</a>
				</div>
				<p class="rnrd-aside-card__version">v<?php echo esc_html( RNRD_VERSION ); ?></p>
				<ul class="rnrd-aside-changes">
					<li><strong><?php esc_html_e( 'Cached markdown.', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'Post markdown is now generated on save, version-stamped, and served instantly from cache. Stale caches auto-regenerate on edits or plugin updates.', 'rankready-ai-llm-seo' ); ?></li>
					<li><strong><?php esc_html_e( 'Page builder support.', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'Markdown endpoints, AI Summary, and AI FAQ now use page builder frontend output. Supported: Elementor, Divi, WPBakery, Beaver Builder, Bricks, Oxygen, Avada, BeTheme.', 'rankready-ai-llm-seo' ); ?></li>
					<li><strong><?php esc_html_e( 'Reliability fixes.', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'Concurrent crawler races, cron shortcode crashes, stale caches, and unbounded cold-cache generation are all resolved.', 'rankready-ai-llm-seo' ); ?></li>
				</ul>
			</div>
			<?php endif; ?>

			<div class="rnrd-aside-card">
				<h3 class="rnrd-aside-card__title"><?php esc_html_e( 'Connect with us', 'rankready-ai-llm-seo' ); ?></h3>
				<a href="https://hostmy.blog/plugins/rankready/" target="_blank" rel="noopener" class="rnrd-aside-link">
					<span class="dashicons dashicons-groups" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Join community', 'rankready-ai-llm-seo' ); ?></span>
					<span class="rnrd-aside-link__arrow" aria-hidden="true">→</span>
				</a>
				<a href="https://wordpress.org/support/plugin/rankready-ai-llm-seo/" target="_blank" rel="noopener" class="rnrd-aside-link">
					<span class="dashicons dashicons-sos" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Need help? Raise a ticket', 'rankready-ai-llm-seo' ); ?></span>
					<span class="rnrd-aside-link__arrow" aria-hidden="true">→</span>
				</a>
			</div>

			<div class="rnrd-aside-card rnrd-aside-card--rate">
				<h3 class="rnrd-aside-card__title"><?php esc_html_e( 'Loving RankReady so far?', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-aside-card__sub"><?php esc_html_e( 'A short review on WordPress.org keeps the team motivated to ship the next update.', 'rankready-ai-llm-seo' ); ?></p>
				<div class="rnrd-rate-widget" role="radiogroup" aria-label="<?php esc_attr_e( 'Rate RankReady', 'rankready-ai-llm-seo' ); ?>"
					 data-rate-wp="https://wordpress.org/support/plugin/rankready-ai-llm-seo/reviews/?rate=5#new-post"
					 data-rate-mailto="mailto:support@hostmy.blog?subject=<?php echo rawurlencode( 'Feedback for RankReady' ); ?>">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<button type="button" class="rnrd-rate-star" data-value="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d is star count */ __( 'Rate %d out of 5', 'rankready-ai-llm-seo' ), $i ) ); ?>">
							<span class="dashicons dashicons-star-empty" aria-hidden="true"></span>
						</button>
					<?php endfor; ?>
				</div>
			</div>

		</aside>
		<?php
	}

	// ── Shared UI helpers ─────────────────────────────────────────────────────

	/**
	 * Renders a "Coming Soon" gate block inside any card.
	 * Use in place of unavailable UI to clearly signal what is planned.
	 *
	 * @param string $feature     Short feature name.
	 * @param string $description One sentence describing the benefit.
	 */
	private static function render_pro_gate( string $feature, string $description = '' ): void {
		?>
		<div class="rnrd-soon-row">
			<span class="rnrd-soon-row__label"><?php echo esc_html( $feature ); ?></span>
			<span class="rnrd-soon-tag"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span>
			<?php if ( $description ) : ?>
				<p class="rnrd-soon-row__desc"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Reusable "what is this tab?" intro card. Title + one combined lede —
	 * outcome and context in a single line, no separate goal/desc stack.
	 *
	 * @since 1.2.0-rc.16
	 * @param string $title Tab name (also card title).
	 * @param string $lede  One medium line — what this tab does and why.
	 */
	private static function render_tab_intro( string $title, string $lede ): void {
		?>
		<div class="rnrd-card rnrd-tab-intro">
			<h2 class="rnrd-card-title"><?php echo esc_html( $title ); ?></h2>
			<p class="rnrd-card-goal rnrd-mb-0"><?php echo esc_html( $lede ); ?></p>
		</div>
		<?php
	}

	/**
	 * Inline locked-checkbox indicator for form-table rows where a future
	 * Pro toggle will live. Visual match for render_pro_gate's checkbox-lock
	 * symbol — used inside `<td>` cells in Settings tables so locked rows
	 * read the same as locked Coming-Soon cards.
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function locked_checkbox_indicator(): string {
		// v1.1.0 — flat-tag design. Lock icon removed; the inline [COMING SOON] tag
		// from pro_badge() carries all the visual weight needed.
		return '';
	}

	/**
	 * Inline COMING SOON pill chip — rounded mint badge, no literal brackets.
	 *
	 * Output: <span class="rnrd-soon-tag">COMING SOON</span>
	 * Visual: small uppercase mint pill rendered inline next to a label.
	 */
	private static function pro_badge(): string {
		return '<span class="rnrd-soon-tag">' . esc_html__( 'COMING SOON', 'rankready-ai-llm-seo' ) . '</span>';
	}

	/**
	 * Inline FREE badge span (retained for layout symmetry; no longer paired with PRO).
	 */
	private static function free_badge(): string {
		return '<span class="rnrd-free-badge">' . esc_html__( 'ACTIVE', 'rankready-ai-llm-seo' ) . '</span>';
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Dashboard — at-a-glance overview
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_dashboard(): void {
		global $wpdb;

		// AI Content tile — post types read first so counts can be scoped to them.
		$summary_types = array_values( array_filter( (array) get_option( RNRD_OPT_POST_TYPES, array( 'post' ) ) ) );
		$summary_place = class_exists( 'RNRD_Summary' ) ? RNRD_Summary::get_auto_display() : 'off';

		$faq_types = array_values( array_filter( (array) get_option( RNRD_OPT_FAQ_POST_TYPES, array( 'post' ) ) ) );
		$faq_place = class_exists( 'RNRD_Faq' ) ? RNRD_Faq::get_auto_display() : 'off';

		// Count generated summaries/FAQs scoped to the configured post types only.
		// When no post types are configured the feature is effectively disabled — skip the query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DISTINCT count of own meta_key; no WP-API equivalent.
		if ( ! empty( $summary_types ) ) {
			$summary_in    = implode( ',', array_fill( 0, count( $summary_types ), '%s' ) );
			$summary_args  = array_merge( array( RNRD_META_SUMMARY ), $summary_types );
			$summary_count = (int) $wpdb->get_var( // phpcs:ignore
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_type IN ({$summary_in}) AND p.post_status = 'publish'",
					$summary_args
				)
			);
		} else {
			$summary_count = 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same pattern for FAQ meta.
		if ( ! empty( $faq_types ) ) {
			$faq_in    = implode( ',', array_fill( 0, count( $faq_types ), '%s' ) );
			$faq_args  = array_merge( array( RNRD_META_FAQ ), $faq_types );
			$faq_count = (int) $wpdb->get_var( // phpcs:ignore
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_type IN ({$faq_in}) AND p.post_status = 'publish'",
					$faq_args
				)
			);
		} else {
			$faq_count = 0;
		}

		$author_on  = 'on' === get_option( RNRD_OPT_AUTHOR_ENABLE, 'on' );
		$summary_on = 'on' === get_option( RNRD_OPT_SUMMARY_ENABLE, 'on' );
		$faq_on     = 'on' === get_option( RNRD_OPT_FAQ_ENABLE, 'on' );
		$api_set    = RNRD_LLM::active_provider_ready();

		$author_types = array_values( array_filter( (array) get_option( RNRD_OPT_AUTHOR_POST_TYPES, array( 'post' ) ) ) );
		$author_place = (string) get_option( RNRD_OPT_AUTHOR_AUTO_DISPLAY, 'off' );

		$schema_flags = array(
			'Article'   => 'on' === (string) get_option( RNRD_OPT_SCHEMA_ARTICLE, 'on' ),
			'Speakable' => 'on' === (string) get_option( RNRD_OPT_SCHEMA_SPEAKABLE, 'on' ),
			'FAQ'       => 'on' === (string) get_option( RNRD_OPT_SCHEMA_FAQ, 'on' ),
		);
		if ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) {
			$schema_flags['HowTo']    = 'on' === (string) get_option( RNRD_OPT_SCHEMA_HOWTO, 'on' );
			$schema_flags['ItemList'] = 'on' === (string) get_option( RNRD_OPT_SCHEMA_ITEMLIST, 'on' );
		}
		$schema_on_labels = array();
		foreach ( $schema_flags as $label => $is_on ) {
			if ( $is_on ) {
				$schema_on_labels[] = $label;
			}
		}
		$schema_on_count  = count( $schema_on_labels );
		$schema_seo       = '';
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$schema_seo = 'Rank Math';
		} elseif ( defined( 'WPSEO_VERSION' ) ) {
			$schema_seo = 'Yoast SEO';
		} elseif ( defined( 'AIOSEO_VERSION' ) ) {
			$schema_seo = 'All in One SEO';
		} elseif ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) ) {
			$schema_seo = 'SEOPress';
		} elseif ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$schema_seo = 'The SEO Framework';
		}

		$training_on     = 'on' === get_option( RNRD_OPT_AI_TRAINING_ENABLE, 'on' );
		$citation_on     = 'on' === get_option( RNRD_OPT_AI_CITATION_ENABLE, 'on' );
		$surfaces_on     = class_exists( 'RNRD_Crawler_Log' ) && RNRD_Crawler_Log::has_loggable_endpoints();
		$training_active = $training_on && $surfaces_on;
		$citation_active = $citation_on && $surfaces_on;
		$training_hits   = ( $training_active && class_exists( 'RNRD_Crawler_Log' ) ) ? (int) RNRD_Crawler_Log::get_training_hits_total( 30 ) : 0;
		$citation_hits   = ( $citation_active && class_exists( 'RNRD_Crawler_Log' ) ) ? (int) RNRD_Crawler_Log::get_citation_hits_total( 30 ) : 0;
		$referral_on   = 'on' === get_option( RNRD_OPT_AI_REFERRAL_ENABLE, 'on' );
		$referral_hits = ( $referral_on && class_exists( 'RNRD_AI_Referral' ) ) ? (int) RNRD_AI_Referral::total_last_n_days( 30 ) : 0;
		$stale_count   = 0;
		if ( class_exists( 'RNRD_Freshness' ) && method_exists( 'RNRD_Freshness', 'bucket_counts' ) ) {
			$buckets     = RNRD_Freshness::bucket_counts();
			$stale_count = (int) ( $buckets['stale'] ?? 0 );
		}

		$content_url         = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=content' );
		$content_summary_url = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=content&sub=summary' );
		$content_faq_url     = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=content&sub=faq' );
		$content_author_url  = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=content&sub=author' );
		$content_schema_url  = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=content&sub=schema' );
		$insights_url        = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=insights' );
		$settings_url        = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=settings' );
		$insights_training   = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=insights&sub=bot-activity' );
		$insights_citation   = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=insights&sub=citation' );
		$insights_referral   = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=insights&sub=referral' );
		$insights_freshness  = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=insights&sub=freshness' );

		?>
		<?php
		self::render_tab_intro(
			__( 'Dashboard', 'rankready-ai-llm-seo' ),
			__( 'Your AI readiness at a glance — Visibility, Content, and Insights summaries to jump into.', 'rankready-ai-llm-seo' )
		);
		?>

		<?php self::render_card_agent_visibility(); ?>

		<div class="rnrd-card rnrd-dash-summary" style="margin-bottom:20px;">
			<div class="rnrd-dash-summary__head">
				<div>
					<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Content', 'rankready-ai-llm-seo' ); ?></h2>
					<p class="rnrd-card-goal"><?php esc_html_e( 'AI Summaries, FAQ, Author Box, and Schema. Generated Summary and FAQ also appear in Markdown and OKF.', 'rankready-ai-llm-seo' ); ?></p>
				</div>
				<a class="rnrd-dash-summary__open" href="<?php echo esc_url( $content_url ); ?>"><?php esc_html_e( 'View all →', 'rankready-ai-llm-seo' ); ?></a>
			</div>
			<div class="rnrd-kpi-row" role="group" aria-label="<?php esc_attr_e( 'Content summary', 'rankready-ai-llm-seo' ); ?>">
			<a class="rnrd-kpi rnrd-kpi--link" href="<?php echo esc_url( $content_summary_url ); ?>" aria-label="<?php esc_attr_e( 'AI Summaries — open AI Content', 'rankready-ai-llm-seo' ); ?>">
				<div class="rnrd-kpi__title">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'AI Summaries', 'rankready-ai-llm-seo' ); ?></div>
					<span class="rnrd-kpi__go" aria-hidden="true">→</span>
				</div>
				<?php if ( empty( $summary_types ) ) : ?>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Disabled', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php esc_html_e( 'Off', 'rankready-ai-llm-seo' ); ?></div>
				<?php else : ?>
					<div class="rnrd-kpi__period"><?php echo esc_html( self::dash_post_types_meta( $summary_types ) ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $summary_count ) ); ?></div>
					<div class="rnrd-kpi__foot rnrd-kpi__foot--stack">
						<span class="rnrd-kpi__foot-line"><?php echo esc_html( $summary_on ? self::dash_auto_placement_label( $summary_place ) : __( 'Hidden on frontend', 'rankready-ai-llm-seo' ) ); ?></span>
						<span class="rnrd-kpi__foot-line"><?php echo esc_html( self::dash_md_okf_placement_label( 'summary' ) ); ?></span>
					</div>
				<?php endif; ?>
			</a>
			<a class="rnrd-kpi rnrd-kpi--link" href="<?php echo esc_url( $content_faq_url ); ?>" aria-label="<?php esc_attr_e( 'AI FAQ — open AI Content', 'rankready-ai-llm-seo' ); ?>">
				<div class="rnrd-kpi__title">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'AI FAQ', 'rankready-ai-llm-seo' ); ?></div>
					<span class="rnrd-kpi__go" aria-hidden="true">→</span>
				</div>
				<?php if ( empty( $faq_types ) ) : ?>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Disabled', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php esc_html_e( 'Off', 'rankready-ai-llm-seo' ); ?></div>
				<?php else : ?>
					<div class="rnrd-kpi__period"><?php echo esc_html( self::dash_post_types_meta( $faq_types ) ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $faq_count ) ); ?></div>
					<div class="rnrd-kpi__foot rnrd-kpi__foot--stack">
						<span class="rnrd-kpi__foot-line"><?php echo esc_html( $faq_on ? self::dash_auto_placement_label( $faq_place ) : __( 'Hidden on frontend', 'rankready-ai-llm-seo' ) ); ?></span>
						<span class="rnrd-kpi__foot-line"><?php echo esc_html( self::dash_md_okf_placement_label( 'faq' ) ); ?></span>
					</div>
				<?php endif; ?>
			</a>
				<a class="rnrd-kpi rnrd-kpi--link" href="<?php echo esc_url( $content_author_url ); ?>" aria-label="<?php esc_attr_e( 'Author Box (E-E-A-T) — open AI Content', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Author Box (E-E-A-T)', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true">→</span>
					</div>
					<div class="rnrd-kpi__period"><?php echo $author_on ? esc_html( self::dash_post_types_meta( $author_types ) ) : esc_html__( 'Disabled', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo $author_on ? esc_html__( 'On', 'rankready-ai-llm-seo' ) : esc_html__( 'Off', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__foot"><?php echo esc_html( $author_on ? self::dash_auto_placement_label( $author_place ) : __( 'Hidden on frontend', 'rankready-ai-llm-seo' ) ); ?></div>
				</a>
				<a class="rnrd-kpi rnrd-kpi--link" href="<?php echo esc_url( $content_schema_url ); ?>" aria-label="<?php esc_attr_e( 'Schema — open AI Content', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Schema', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true">→</span>
					</div>
					<div class="rnrd-kpi__period"><?php echo $schema_on_labels ? esc_html( implode( ' · ', $schema_on_labels ) ) : esc_html__( 'None enabled', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo $schema_on_count > 0 ? esc_html__( 'On', 'rankready-ai-llm-seo' ) : esc_html__( 'Off', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__foot"><?php
						echo $schema_seo
							? esc_html( sprintf(
								/* translators: %s: SEO plugin name */
								__( 'Merging with %s', 'rankready-ai-llm-seo' ),
								$schema_seo
							) )
							: esc_html__( 'Standalone', 'rankready-ai-llm-seo' );
					?></div>
				</a>
			</div>
			<?php
			$provider_foot_label = '';
			$model_foot_label    = '';
			if ( $api_set && class_exists( 'RNRD_LLM' ) ) {
				$provider_foot       = RNRD_LLM::get_active_provider();
				$provider_foot_label = RNRD_LLM::get_provider_label( $provider_foot );
				$model_foot_id       = RNRD_LLM::get_model( $provider_foot );
				$models_foot         = RNRD_LLM::get_models_for( $provider_foot );
				$model_foot_label    = isset( $models_foot[ $model_foot_id ] )
					? (string) $models_foot[ $model_foot_id ]
					: (string) $model_foot_id;
			}
			?>
			<p class="rnrd-dash-summary__foot<?php echo $api_set ? '' : ' rnrd-dash-summary__foot--warn'; ?>" role="status">
				<?php if ( $api_set ) : ?>
					<?php
					echo esc_html( sprintf(
						/* translators: 1: provider label, 2: model label */
						__( 'Using %1$s · %2$s', 'rankready-ai-llm-seo' ),
						$provider_foot_label,
						$model_foot_label
					) );
					?>
					<span class="rnrd-dash-summary__foot-sep" aria-hidden="true">·</span>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change in Settings →', 'rankready-ai-llm-seo' ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'AI setup needed for Summaries & FAQ', 'rankready-ai-llm-seo' ); ?>
					<span class="rnrd-dash-summary__foot-sep" aria-hidden="true">·</span>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open Settings →', 'rankready-ai-llm-seo' ); ?></a>
				<?php endif; ?>
			</p>
		</div>

		<div class="rnrd-card rnrd-dash-summary" style="margin-bottom:20px;">
			<div class="rnrd-dash-summary__head">
				<div>
					<h2 class="rnrd-card-title"><?php esc_html_e( 'Insights', 'rankready-ai-llm-seo' ); ?></h2>
					<p class="rnrd-card-goal"><?php esc_html_e( 'Who reads your site, what they cite, what brings them back.', 'rankready-ai-llm-seo' ); ?></p>
				</div>
				<a class="rnrd-dash-summary__open" href="<?php echo esc_url( $insights_url ); ?>"><?php esc_html_e( 'View all →', 'rankready-ai-llm-seo' ); ?></a>
			</div>
			<div class="rnrd-kpi-row" role="group" aria-label="<?php esc_attr_e( 'Insights summary', 'rankready-ai-llm-seo' ); ?>">
				<a class="rnrd-kpi rnrd-kpi--link" data-intent="training" href="<?php echo esc_url( $insights_training ); ?>" aria-label="<?php esc_attr_e( 'Training Bots — open Insights', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Training Bots', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true">→</span>
					</div>
					<?php if ( $training_active ) : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $training_hits ) ); ?></div>
						<div class="rnrd-kpi__foot"><?php esc_html_e( 'AI crawler hits', 'rankready-ai-llm-seo' ); ?></div>
					<?php else : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Disabled', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php esc_html_e( 'Off', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__foot"><?php echo esc_html( $training_on ? __( 'llms.txt and Markdown are off', 'rankready-ai-llm-seo' ) : __( 'Training bot logging paused', 'rankready-ai-llm-seo' ) ); ?></div>
					<?php endif; ?>
				</a>
				<a class="rnrd-kpi rnrd-kpi--link" data-intent="citation" href="<?php echo esc_url( $insights_citation ); ?>" aria-label="<?php esc_attr_e( 'Citation Bots — open Insights', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Citation Bots', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true">→</span>
					</div>
					<?php if ( $citation_active ) : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $citation_hits ) ); ?></div>
						<div class="rnrd-kpi__foot"><?php esc_html_e( 'live answer bots', 'rankready-ai-llm-seo' ); ?></div>
					<?php else : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Disabled', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php esc_html_e( 'Off', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__foot"><?php echo esc_html( $citation_on ? __( 'llms.txt and Markdown are off', 'rankready-ai-llm-seo' ) : __( 'Citation bot logging paused', 'rankready-ai-llm-seo' ) ); ?></div>
					<?php endif; ?>
				</a>
				<a class="rnrd-kpi rnrd-kpi--link" data-intent="referral" href="<?php echo esc_url( $insights_referral ); ?>" aria-label="<?php esc_attr_e( 'Real AI Referrals — open Insights', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Real AI Referrals', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true">→</span>
					</div>
					<?php if ( $referral_on ) : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $referral_hits ) ); ?></div>
						<div class="rnrd-kpi__foot"><?php esc_html_e( 'visitors from AI apps', 'rankready-ai-llm-seo' ); ?></div>
					<?php else : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Disabled', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php esc_html_e( 'Off', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__foot"><?php esc_html_e( 'Referer tracking paused', 'rankready-ai-llm-seo' ); ?></div>
					<?php endif; ?>
				</a>
				<a class="rnrd-kpi rnrd-kpi--link" data-intent="freshness" href="<?php echo esc_url( $insights_freshness ); ?>" aria-label="<?php esc_attr_e( 'Content Fresh — open Insights', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Content Fresh', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true">→</span>
					</div>
					<div class="rnrd-kpi__period"><?php esc_html_e( '60+ days old', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $stale_count ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'posts going stale', 'rankready-ai-llm-seo' ); ?></div>
				</a>
			</div>
		</div>

		<p style="margin:0 0 14px;font-size:12px;color:var(--rnrd-color-text-muted,#646970);">
			<?php esc_html_e( 'Need to start over?', 'rankready-ai-llm-seo' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-welcome' ) ); ?>"><?php esc_html_e( 'Re-run the setup wizard →', 'rankready-ai-llm-seo' ); ?></a>
		</p>

		<?php
	}

	/**
	 * Compact post-type list for Dashboard Content tiles (e.g. "Posts · Pages").
	 *
	 * @param array<int,string> $slugs Post type slugs.
	 */
	private static function dash_post_types_meta( array $slugs ): string {
		$slugs = array_values( array_filter( array_map( 'strval', $slugs ) ) );
		if ( empty( $slugs ) ) {
			return __( 'No post types', 'rankready-ai-llm-seo' );
		}
		$labels = array();
		foreach ( $slugs as $slug ) {
			$obj = get_post_type_object( $slug );
			$labels[] = ( $obj && ! empty( $obj->labels->name ) )
				? (string) $obj->labels->name
				: $slug;
		}
		return implode( ' · ', $labels );
	}

	/**
	 * "Available for: Posts, Pages" line for Visibility feature rows.
	 *
	 * @param array<int,string> $slugs Post type slugs.
	 */
	private static function dash_available_for( array $slugs ): string {
		return self::dash_available_for_with_prefix( $slugs, array() );
	}

	/**
	 * Like dash_available_for() but prepends surface labels (e.g. Homepage, Blog Index)
	 * before the post-type labels.
	 *
	 * @param string[] $slugs   Post type slugs.
	 * @param string[] $prefix  Surface labels to prepend (already translated).
	 */
	private static function dash_available_for_with_prefix( array $slugs, array $prefix ): string {
		$slugs = array_values( array_filter( array_map( 'strval', $slugs ) ) );
		$labels = $prefix;
		foreach ( $slugs as $slug ) {
			$obj = get_post_type_object( $slug );
			$labels[] = ( $obj && ! empty( $obj->labels->name ) )
				? (string) $obj->labels->name
				: $slug;
		}
		if ( empty( $labels ) ) {
			return __( 'Available for: no post types', 'rankready-ai-llm-seo' );
		}
		return sprintf(
			/* translators: %s: comma-separated labels */
			__( 'Available for: %s', 'rankready-ai-llm-seo' ),
			implode( ', ', $labels )
		);
	}

	/**
	 * Auto-placement foot label for Dashboard Content tiles.
	 *
	 * @param string $mode off|before|after|both
	 */
	private static function dash_auto_placement_label( string $mode ): string {
		switch ( $mode ) {
			case 'before':
				return __( 'Auto-display: before content', 'rankready-ai-llm-seo' );
			case 'after':
				return __( 'Auto-display: after content', 'rankready-ai-llm-seo' );
			case 'both':
				return __( 'Auto-display: before and after content', 'rankready-ai-llm-seo' );
			case 'off':
			default:
				return __( 'Manual placement', 'rankready-ai-llm-seo' );
		}
	}

	/**
	 * HTML-page placement for Summary / FAQ Dashboard tiles.
	 *
	 * @param string $mode off|before|after|both
	 */
	private static function dash_html_placement_label( string $mode ): string {
		return sprintf(
			/* translators: %s: auto-display placement like "Auto-display: before content" */
			__( 'HTML: %s', 'rankready-ai-llm-seo' ),
			self::dash_auto_placement_label( $mode )
		);
	}

	/**
	 * Fixed Markdown / OKF injection for Summary / FAQ Dashboard tiles.
	 *
	 * @param string $kind summary|faq
	 */
	private static function dash_md_okf_placement_label( string $kind ): string {
		return 'faq' === $kind
			? __( 'Markdown / OKF: after body', 'rankready-ai-llm-seo' )
			: __( 'Markdown / OKF: before body', 'rankready-ai-llm-seo' );
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// Dashboard: AI Visibility feature list (2-column, data-driven)
	// ═══════════════════════════════════════════════════════════════════════════

	/**
	 * AI Visibility surfaces for the Dashboard card (and WP widget score).
	 * Append new features here — the list layout scales without grid math.
	 *
	 * @return array<int,array{
	 *   label:string,
	 *   on:bool,
	 *   status:string,
	 *   meta_lines:array<int,string>,
	 *   configure:string,
	 *   previews:array<int,array{label:string,url:string}>
	 * }>
	 */
	public static function get_visibility_features(): array {
		$brand_url  = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=crawlers&sub=brand' );
		$robots_url = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=crawlers&sub=robots' );
		$llms_url   = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=crawlers&sub=llms' );
		$md_url     = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=crawlers&sub=markdown' );
		$webmcp_url = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=crawlers&sub=webmcp' );
		$okf_url    = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=crawlers&sub=okf' );

		$off_label = __( 'Off', 'rankready-ai-llm-seo' );

		$brand_name_raw = trim( (string) get_option( RNRD_OPT_LLMS_SITE_NAME, '' ) );
		$brand_summary  = trim( (string) get_option( RNRD_OPT_LLMS_SUMMARY, '' ) );
		$brand_terms    = trim( (string) get_option( RNRD_OPT_BRAND_TERMS, '' ) );
		$brand_ok       = ( '' !== $brand_name_raw || '' !== $brand_summary ) && '' !== $brand_terms;
		$brand_display  = $brand_name_raw;
		if ( '' === $brand_display ) {
			if ( class_exists( 'RNRD_Llms_Txt' ) ) {
				$identity      = RNRD_Brand_Identity::get_brand_identity();
				$brand_display = isset( $identity['name'] ) ? trim( (string) $identity['name'] ) : '';
			}
			if ( '' === $brand_display ) {
				$brand_display = trim( (string) get_bloginfo( 'name' ) );
			}
		}
		$brand_meta_lines = array(
			__( 'Tell ChatGPT, Claude, Perplexity, and Gemini who you are', 'rankready-ai-llm-seo' ),
			sprintf(
				/* translators: %s: site/brand name (custom or WordPress site title fallback) */
				__( 'Site/Brand Name: %s', 'rankready-ai-llm-seo' ),
				'' !== $brand_display ? $brand_display : __( '(not set)', 'rankready-ai-llm-seo' )
			),
		);

		$robots_on      = 'on' === (string) get_option( RNRD_OPT_ROBOTS_ENABLE, 'on' );
		$robots_mode    = self::get_robots_mode();
		$bots_allowed   = count( array_filter( $robots_mode, static function ( string $state ): bool {
			return 'allow' === $state;
		} ) );
		$bots_blocked   = count( array_filter( $robots_mode, static function ( string $state ): bool {
			return 'block' === $state;
		} ) );
		$robots_meta = $robots_on
			? sprintf(
				/* translators: 1: allowed crawler count, 2: blocked crawler count */
				__( '%1$d crawlers allowed · %2$d blocked', 'rankready-ai-llm-seo' ),
				(int) $bots_allowed,
				(int) $bots_blocked
			)
			: __( 'Allow or block named AI crawlers in robots.txt', 'rankready-ai-llm-seo' );

		$robots_previews = array(
			array(
				'label' => 'robots.txt',
				'url'   => home_url( '/robots.txt' ),
			),
		);

		$signals_on      = 'on' === (string) get_option( RNRD_OPT_CONTENT_SIGNALS_ENABLE, 'off' );
		$signal_allowed  = array();
		$signal_denied   = array();
		if ( $signals_on ) {
			if ( 'allow' === (string) get_option( RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN, 'allow' ) ) {
				$signal_allowed[] = __( 'ai-train', 'rankready-ai-llm-seo' );
			} else {
				$signal_denied[] = __( 'ai-train', 'rankready-ai-llm-seo' );
			}
			if ( 'allow' === (string) get_option( RNRD_OPT_CONTENT_SIGNALS_SEARCH, 'allow' ) ) {
				$signal_allowed[] = __( 'search', 'rankready-ai-llm-seo' );
			} else {
				$signal_denied[] = __( 'search', 'rankready-ai-llm-seo' );
			}
			if ( 'allow' === (string) get_option( RNRD_OPT_CONTENT_SIGNALS_AI_INPUT, 'allow' ) ) {
				$signal_allowed[] = __( 'ai-input', 'rankready-ai-llm-seo' );
			} else {
				$signal_denied[] = __( 'ai-input', 'rankready-ai-llm-seo' );
			}
		}
		if ( $signals_on ) {
			$signal_meta_parts = array();
			if ( $signal_allowed ) {
				$signal_meta_parts[] = sprintf(
					/* translators: %s: comma-separated allowed signal directives */
					__( 'Allowed: %s', 'rankready-ai-llm-seo' ),
					implode( ', ', $signal_allowed )
				);
			}
			if ( $signal_denied ) {
				$signal_meta_parts[] = sprintf(
					/* translators: %s: comma-separated denied signal directives */
					__( 'Denied: %s', 'rankready-ai-llm-seo' ),
					implode( ', ', $signal_denied )
				);
			}
			$signals_meta = implode( ' · ', $signal_meta_parts );
		} else {
			$signals_meta = __( 'Tell AI engines how they may use your content for training, search, and input', 'rankready-ai-llm-seo' );
		}
		$signals_previews = $signals_on ? $robots_previews : array();

		$snippet_on = 'on' === (string) get_option( RNRD_OPT_MAX_SNIPPET_DEFAULT, 'on' );
		$snippet_meta = $snippet_on
			? __( 'Full snippet (max-snippet:-1) by default', 'rankready-ai-llm-seo' )
			: __( 'Standard snippet by default — AI quotes stay capped', 'rankready-ai-llm-seo' );

		// llms-full is an extension of llms.txt — only preview when the base endpoint is on.
		$llms_on       = 'on' === (string) get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$full_on       = 'on' === (string) get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' );
		$llms_types    = array_values( array_filter( (array) get_option( RNRD_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) ) ) );
		$llms_previews = array();
		if ( $llms_on ) {
			$llms_previews[] = array(
				'label' => 'llms.txt',
				'url'   => home_url( '/llms.txt' ),
			);
			if ( $full_on ) {
				$llms_previews[] = array(
					'label' => 'llms-full.txt',
					'url'   => home_url( '/llms-full.txt' ),
				);
			}
		}
		$llms_meta_lines = $llms_on
			? array( self::dash_available_for( $llms_types ) )
			: array( __( 'Publish a site index AI engines can discover', 'rankready-ai-llm-seo' ) );

		$md_on      = 'on' === (string) get_option( RNRD_OPT_MD_ENABLE, 'off' );
		$md_home_on = $md_on && 'on' === (string) get_option( RNRD_OPT_MD_HOME_ENABLE, 'on' );
		$md_types   = array_values( array_filter( (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) ) ) );
		$md_auto    = $md_on && 'on' === (string) get_option( RNRD_OPT_MD_BOT_AUTO_SERVE, 'on' );
		$md_hint    = $md_on && 'on' === (string) get_option( RNRD_OPT_MD_HINT_DIV, 'on' );
		if ( $md_on ) {
			$md_opts = array();
			if ( $md_hint ) {
				$md_opts[] = __( 'AI hint in body', 'rankready-ai-llm-seo' );
			}
			if ( $md_auto ) {
				$md_opts[] = __( 'Auto-serve to AI bots', 'rankready-ai-llm-seo' );
			}
			$md_meta_lines = array();
			$md_home_surfaces = array();
			if ( $md_home_on ) {
				$md_home_surfaces[] = __( 'Homepage', 'rankready-ai-llm-seo' );
				$show_on_front_dash = (string) get_option( 'show_on_front', 'posts' );
				if ( 'page' === $show_on_front_dash && get_option( 'page_for_posts', 0 ) > 0 ) {
					$md_home_surfaces[] = __( 'Blog Index', 'rankready-ai-llm-seo' );
				}
			}
			$md_meta_lines[] = self::dash_available_for_with_prefix( $md_types, $md_home_surfaces );
			if ( $md_opts ) {
				$md_meta_lines[] = implode( ' · ', $md_opts );
			}
		} else {
			$md_meta_lines = array(
				__( 'Serve clean Markdown versions of your pages to AI agents', 'rankready-ai-llm-seo' ),
			);
		}

		$mcp_on = 'on' === (string) get_option( RNRD_OPT_MCP_ENABLE, 'off' );
		$mcp_previews = $mcp_on
			? array(
				array(
					'label' => 'mcp.json',
					'url'   => home_url( '/.well-known/mcp.json' ),
				),
			)
			: array();
		$mcp_meta_lines = array(
			$mcp_on
				? __( 'Manifest for Claude, Cursor, and VS Code', 'rankready-ai-llm-seo' )
				: __( 'Expose a WebMCP manifest agents can discover', 'rankready-ai-llm-seo' ),
		);

		$okf_on    = 'on' === (string) get_option( RNRD_OPT_OKF_ENABLE, 'off' );
		$okf_types = array_values( array_filter( (array) get_option( RNRD_OPT_OKF_POST_TYPES, array( 'post', 'page' ) ) ) );
		$okf_meta_lines = $okf_on
			? array( self::dash_available_for( $okf_types ) )
			: array( __( 'Bundle structured knowledge for AI engines', 'rankready-ai-llm-seo' ) );
		$okf_previews = $okf_on
			? array(
				array(
					'label' => 'okf',
					'url'   => home_url( '/okf/' ),
				),
			)
			: array();

		return array(
			array(
				'label'      => __( 'Brand Identity', 'rankready-ai-llm-seo' ),
				'on'         => $brand_ok,
				'status'     => $brand_ok ? '' : __( 'Incomplete', 'rankready-ai-llm-seo' ),
				'meta_lines' => $brand_meta_lines,
				'configure'  => $brand_url,
				'previews'   => array(),
			),
			array(
				'label'      => __( 'LLM Crawler Access', 'rankready-ai-llm-seo' ),
				'on'         => $robots_on,
				'status'     => $robots_on ? '' : $off_label,
				'meta_lines' => array( $robots_meta ),
				'configure'  => $robots_url,
				'previews'   => $robots_on ? $robots_previews : array(),
			),
			array(
				'label'      => __( 'Content Signals', 'rankready-ai-llm-seo' ),
				'on'         => $signals_on,
				'status'     => $signals_on ? '' : $off_label,
				'meta_lines' => array( $signals_meta ),
				'configure'  => $robots_url,
				'previews'   => $signals_previews,
			),
			array(
				'label'      => __( 'AI Snippet', 'rankready-ai-llm-seo' ),
				'on'         => $snippet_on,
				'status'     => $snippet_on ? '' : $off_label,
				'meta_lines' => array( $snippet_meta ),
				'configure'  => $robots_url,
				'previews'   => array(),
			),
			array(
				'label'      => __( 'LLMs.txt Generator', 'rankready-ai-llm-seo' ),
				'on'         => $llms_on,
				'status'     => $llms_on ? '' : $off_label,
				'meta_lines' => $llms_meta_lines,
				'configure'  => $llms_url,
				'previews'   => $llms_previews,
			),
			array(
				'label'      => __( 'Markdown Endpoint', 'rankready-ai-llm-seo' ),
				'on'         => $md_on,
				'status'     => $md_on ? '' : $off_label,
				'meta_lines' => $md_meta_lines,
				'configure'  => $md_url,
				'previews'   => array(),
			),
			array(
				'label'      => __( 'WebMCP', 'rankready-ai-llm-seo' ),
				'on'         => $mcp_on,
				'status'     => $mcp_on ? '' : $off_label,
				'meta_lines' => $mcp_meta_lines,
				'configure'  => $webmcp_url,
				'previews'   => $mcp_previews,
				'badge'      => __( 'NEW', 'rankready-ai-llm-seo' ),
			),
			array(
				'label'      => __( 'Open Knowledge Format (OKF)', 'rankready-ai-llm-seo' ),
				'on'         => $okf_on,
				'status'     => $okf_on ? '' : $off_label,
				'meta_lines' => $okf_meta_lines,
				'configure'  => $okf_url,
				'previews'   => $okf_previews,
			),
		);
	}

	/**
	 * AI Visibility feature list — configure on hover + live preview URLs.
	 */
	private static function render_card_agent_visibility(): void {
		$visibility_url = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=crawlers' );
		$features       = self::get_visibility_features();
		?>
		<div class="rnrd-card rnrd-dash-summary rnrd-visibility-summary" style="margin-bottom:20px;">
			<div class="rnrd-dash-summary__head">
				<div>
					<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Visibility', 'rankready-ai-llm-seo' ); ?></h2>
					<p class="rnrd-card-goal"><?php esc_html_e( 'Surfaces AI engines can read and use.', 'rankready-ai-llm-seo' ); ?></p>
				</div>
				<a class="rnrd-dash-summary__open" href="<?php echo esc_url( $visibility_url ); ?>"><?php esc_html_e( 'View all →', 'rankready-ai-llm-seo' ); ?></a>
			</div>
			<ul class="rnrd-feature-list" aria-label="<?php esc_attr_e( 'AI Visibility features', 'rankready-ai-llm-seo' ); ?>">
				<?php foreach ( $features as $feat ) :
					$active   = ! empty( $feat['on'] );
					$previews = isset( $feat['previews'] ) && is_array( $feat['previews'] ) ? $feat['previews'] : array();
					$config   = (string) $feat['configure'];
					?>
					<li class="rnrd-feature-list__item rnrd-feature-list__item--<?php echo $active ? 'on' : 'off'; ?>">
						<a class="rnrd-feature-list__hit" href="<?php echo esc_url( $config ); ?>">
							<span class="rnrd-feature-list__mark dashicons <?php echo $active ? 'dashicons-yes' : 'dashicons-marker'; ?>" aria-hidden="true"></span>
							<span class="rnrd-feature-list__body">
								<span class="rnrd-feature-list__top">
									<span class="rnrd-feature-list__label"><?php echo esc_html( (string) $feat['label'] ); ?></span>
									<?php if ( ! empty( $feat['badge'] ) ) : ?>
										<span class="rnrd-feature-list__badge"><?php echo esc_html( (string) $feat['badge'] ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== trim( (string) $feat['status'] ) ) : ?>
										<span class="rnrd-feature-list__status"><?php echo esc_html( (string) $feat['status'] ); ?></span>
									<?php endif; ?>
									<span class="rnrd-feature-list__go" aria-hidden="true"><?php esc_html_e( 'Configure →', 'rankready-ai-llm-seo' ); ?></span>
								</span>
								<span class="rnrd-feature-list__meta">
									<?php
									$meta_lines = array();
									if ( ! empty( $feat['meta_lines'] ) && is_array( $feat['meta_lines'] ) ) {
										$meta_lines = $feat['meta_lines'];
									} elseif ( isset( $feat['meta'] ) ) {
										$meta_lines = array( (string) $feat['meta'] );
									}
									foreach ( $meta_lines as $i => $line ) :
										$line = trim( (string) $line );
										if ( '' === $line ) {
											continue;
										}
										if ( $i > 0 ) {
											echo '<br />';
										}
										echo esc_html( $line );
									endforeach;
									?>
								</span>
							</span>
						</a>
						<?php if ( $previews ) : ?>
							<span class="rnrd-feature-list__previews">
								<?php foreach ( $previews as $preview ) :
									$p_label = isset( $preview['label'] ) ? (string) $preview['label'] : '';
									$p_url   = isset( $preview['url'] ) ? (string) $preview['url'] : '';
									if ( '' === $p_label || '' === $p_url ) {
										continue;
									}
									?>
									<a class="rnrd-feature-list__preview" href="<?php echo esc_url( $p_url ); ?>" target="_blank" rel="noopener noreferrer">
										<code><?php echo esc_html( $p_label ); ?></code>
										<span class="dashicons dashicons-external" aria-hidden="true"></span>
										<span class="screen-reader-text"><?php
											echo esc_html( sprintf(
												/* translators: %s: preview path label */
												__( 'Open %s (opens in a new tab)', 'rankready-ai-llm-seo' ),
												$p_label
											) );
										?></span>
									</a>
								<?php endforeach; ?>
							</span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Compact on/off list for the WP dashboard widget — same features as the
	 * Dashboard AI Visibility card (no AI Referral; that lives under Insights).
	 *
	 * @return array<int,array<string,mixed>> each: ['label'=>str,'on'=>bool,'url'?=>str]
	 */
	public static function agent_visibility_signals(): array {
		$out = array();
		foreach ( self::get_visibility_features() as $feat ) {
			$row = array(
				'label' => (string) $feat['label'],
				'on'    => ! empty( $feat['on'] ),
			);
			if ( ! empty( $feat['previews'][0]['url'] ) ) {
				$row['url'] = (string) $feat['previews'][0]['url'];
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * AI Visibility feature count — active/total/pct across the surfaces above.
	 * Used by the WP dashboard widget so it stays in sync with the Dashboard card.
	 *
	 * @return array{active:int,total:int,pct:int}
	 */
	public static function agent_visibility_score(): array {
		$signals = self::agent_visibility_signals();
		$total   = count( $signals );
		$active  = count( array_filter( $signals, static function ( $s ) { return ! empty( $s['on'] ); } ) );
		return array(
			'active' => $active,
			'total'  => $total,
			'pct'    => $total > 0 ? (int) round( ( $active / $total ) * 100 ) : 0,
		);
	}

	private static function get_scorecard_signals(): array {
		global $wpdb;

		// Cached existence checks so this method runs O(few queries) max.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded EXISTS check (LIMIT 1) on own meta_key.
		$has_any_summary = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT 1",
				RNRD_META_SUMMARY
			)
		) > 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same as line 1518 for FAQ meta.
		$has_any_faq = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT 1",
				RNRD_META_FAQ
			)
		) > 0;

		// Crawler-log existence + totals (graceful if table absent).
		$crawler_table  = $wpdb->prefix . 'rnrd_crawler_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same as line 1518.
		$crawler_exists = (bool) $wpdb->get_var( $wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$crawler_table
		) );
		$citation_hits = 0;
		$training_hits = 0;
		$crawler_rows  = 0;
		if ( $crawler_exists && class_exists( 'RNRD_Crawler_Log' ) ) {
			$citation_hits = RNRD_Crawler_Log::get_citation_hits_total( 365 );
			$training_hits = RNRD_Crawler_Log::get_training_hits_total( 365 );
			// $crawler_table = $wpdb->prefix . 'rnrd_crawler_log' — hardcoded suffix on the WP-owned prefix, no user input flows in.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own custom table; safe escape via esc_sql, EXISTS check refreshed per render.
			$crawler_rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `" . esc_sql( $crawler_table ) . "` LIMIT 1" );
		}

		// Freshness ever scanned — option set by RNRD_Freshness once a scan completes.
		$freshness_scanned = (bool) get_option( 'rnrd_freshness_last_run', 0 );

		// Tab deep-links.
		$tab_crawlers_brand  = '?page=rankready-ai-llm-seo&tab=crawlers&sub=brand';
		$tab_crawlers_robots = '?page=rankready-ai-llm-seo&tab=crawlers&sub=robots';
		$tab_crawlers_llms   = '?page=rankready-ai-llm-seo&tab=crawlers&sub=llms';
		$tab_crawlers_md     = '?page=rankready-ai-llm-seo&tab=crawlers&sub=markdown';
		$tab_crawlers_webmcp = '?page=rankready-ai-llm-seo&tab=crawlers&sub=webmcp';
		$tab_summary  = '?page=rankready-ai-llm-seo&tab=content&sub=summary';
		$tab_faq      = '?page=rankready-ai-llm-seo&tab=content&sub=faq';
		$tab_author   = '?page=rankready-ai-llm-seo&tab=content&sub=author';
		$tab_schema   = '?page=rankready-ai-llm-seo&tab=content&sub=schema';
		$tab_settings  = '?page=rankready-ai-llm-seo&tab=settings';
		$tab_insights_freshness = '?page=rankready-ai-llm-seo&tab=insights&sub=freshness';
		$tab_insights_bot       = '?page=rankready-ai-llm-seo&tab=insights&sub=bot-activity';
		$tab_insights_citation  = '?page=rankready-ai-llm-seo&tab=insights&sub=citation';
		$tab_insights_referral  = '?page=rankready-ai-llm-seo&tab=insights&sub=referral';

		return array(
			// ── Discovery (5) ────────────────────────────────────────────────
			array(
				'group'    => __( 'Discovery', 'rankready-ai-llm-seo' ),
				'label'    => __( 'llms.txt', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ),
				'deeplink' => $tab_crawlers_llms,
			),
			array(
				'group'    => __( 'Discovery', 'rankready-ai-llm-seo' ),
				'label'    => __( 'llms-full.txt', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ),
				'deeplink' => $tab_crawlers_llms,
			),
			array(
				'group'    => __( 'Discovery', 'rankready-ai-llm-seo' ),
				'label'    => __( '.md routes', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ),
				'deeplink' => $tab_crawlers_md,
			),
			array(
				'group'    => __( 'Discovery', 'rankready-ai-llm-seo' ),
				'label'    => __( 'robots.txt rules', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_ROBOTS_ENABLE, 'on' ),
				'deeplink' => $tab_crawlers_robots,
			),
			array(
				'group'    => __( 'Discovery', 'rankready-ai-llm-seo' ),
				'label'    => __( 'WebMCP manifest', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_MCP_ENABLE, 'off' ),
				'deeplink' => $tab_crawlers_webmcp,
			),

			// ── Content AI (4) ───────────────────────────────────────────────
			array(
				'group'    => __( 'Content AI', 'rankready-ai-llm-seo' ),
				'label'    => __( 'AI Summary', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_AUTO_GENERATE, 'off' ) || $has_any_summary,
				'deeplink' => $tab_summary,
			),
			array(
				'group'    => __( 'Content AI', 'rankready-ai-llm-seo' ),
				'label'    => __( 'FAQ Generation', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_FAQ_AUTO_GENERATE, 'off' ) || $has_any_faq,
				'deeplink' => $tab_faq,
			),
			array(
				'group'    => __( 'Content AI', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Content Signals', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_CONTENT_SIGNALS_ENABLE, 'off' ),
				'deeplink' => $tab_crawlers_robots,
			),
			array(
				'group'    => __( 'Content AI', 'rankready-ai-llm-seo' ),
				'deeplink' => $tab_crawlers_robots,
			),

			// ── Brand Authority (4) ──────────────────────────────────────────
			array(
				'group'    => __( 'Brand Authority', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Site name set', 'rankready-ai-llm-seo' ),
				'active'   => '' !== trim( (string) get_option( RNRD_OPT_LLMS_SITE_NAME, '' ) ),
				'deeplink' => $tab_crawlers_brand,
			),
			array(
				'group'    => __( 'Brand Authority', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Summary set', 'rankready-ai-llm-seo' ),
				'active'   => '' !== trim( (string) get_option( RNRD_OPT_LLMS_SUMMARY, '' ) ),
				'deeplink' => $tab_crawlers_brand,
			),
			array(
				'group'    => __( 'Brand Authority', 'rankready-ai-llm-seo' ),
				'label'    => __( 'About set', 'rankready-ai-llm-seo' ),
				'active'   => '' !== trim( (string) get_option( RNRD_OPT_LLMS_ABOUT, '' ) ),
				'deeplink' => $tab_crawlers_brand,
			),
			array(
				'group'    => __( 'Brand Authority', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Brand terms set', 'rankready-ai-llm-seo' ),
				'active'   => '' !== trim( (string) get_option( RNRD_OPT_BRAND_TERMS, '' ) ),
				'deeplink' => $tab_crawlers_brand,
			),

			// ── Provider (2) ─────────────────────────────────────────────────
			array(
				'group'    => __( 'Provider', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Active AI provider key', 'rankready-ai-llm-seo' ),
				'active'   => class_exists( 'RNRD_LLM' ) && RNRD_LLM::active_provider_ready(),
				'deeplink' => $tab_settings,
			),
			array(
				'group'    => __( 'Provider', 'rankready-ai-llm-seo' ),
				'label'    => __( 'DataForSEO credentials', 'rankready-ai-llm-seo' ),
				'active'   => '' !== (string) get_option( RNRD_OPT_DFS_LOGIN, '' )
				            && '' !== (string) get_option( RNRD_OPT_DFS_PASSWORD, '' ),
				'deeplink' => $tab_settings,
			),

			// ── Engagement (3) ───────────────────────────────────────────────
			array(
				'group'    => __( 'Engagement', 'rankready-ai-llm-seo' ),
				'label'    => __( 'AI Referral tracking', 'rankready-ai-llm-seo' ),
				// Default must match the runtime gate in RNRD_AI_Referral ('on'); the two
				// Dashboard cards previously disagreed with each other.
				'active'   => 'on' === get_option( RNRD_OPT_AI_REFERRAL_ENABLE, 'on' ),
				'deeplink' => $tab_insights_referral,
			),
			array(
				'group'    => __( 'Engagement', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Author Box', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_AUTHOR_ENABLE, 'on' ),
				'deeplink' => $tab_author,
			),
			array(
				'group'    => __( 'Engagement', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Article schema', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_SCHEMA_ARTICLE, 'on' ),
				'deeplink' => $tab_schema,
			),

			// ── Tracking (4) ─────────────────────────────────────────────────
			array(
				'group'    => __( 'Tracking', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Citation hits > 0', 'rankready-ai-llm-seo' ),
				'active'   => $citation_hits > 0,
				'deeplink' => $tab_insights_citation,
				'cta_kind' => 'tracking',
			),
			array(
				'group'    => __( 'Tracking', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Training hits > 0', 'rankready-ai-llm-seo' ),
				'active'   => $training_hits > 0,
				'deeplink' => $tab_insights_bot,
				'cta_kind' => 'tracking',
			),
			array(
				'group'    => __( 'Tracking', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Crawler log live', 'rankready-ai-llm-seo' ),
				'active'   => $crawler_rows > 0,
				'deeplink' => $tab_insights_bot,
				'cta_kind' => 'tracking',
			),
			array(
				'group'    => __( 'Tracking', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Freshness scanned', 'rankready-ai-llm-seo' ),
				'active'   => $freshness_scanned,
				'deeplink' => $tab_insights_freshness,
				'cta_kind' => 'freshness',
			),
		);
	}

	/**
	 * Render the Agentic Ready Scorecard card (Dashboard tab, rc.6).
	 */
	private static function render_card_scorecard(): void {
		$signals = self::get_scorecard_signals();
		$total   = count( $signals );
		$active  = 0;
		foreach ( $signals as $s ) {
			if ( ! empty( $s['active'] ) ) {
				$active++;
			}
		}
		$pct = $total > 0 ? (int) round( ( $active / $total ) * 100 ) : 0;

		// Group signals.
		$groups = array();
		foreach ( $signals as $s ) {
			$groups[ $s['group'] ][] = $s;
		}
		?>
		<div class="rnrd-card rnrd-scorecard" style="margin-bottom:24px;">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Agentic Ready Scorecard', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( '22 signals across 6 groups — tick = active; click any row to jump to the setting.', 'rankready-ai-llm-seo' ); ?></p>

			<div class="rnrd-scorecard-progress" style="position:relative;height:10px;background:#e5e5e7;border-radius:6px;overflow:hidden;margin:10px 0 6px;">
				<div class="rnrd-scorecard-bar" style="height:100%;width:<?php echo (int) $pct; ?>%;background:linear-gradient(90deg,#2DE3A8 0%,#0F9C70 100%);transition:width 0.4s ease;"></div>
			</div>
			<p class="rnrd-scorecard-meta" style="margin:0 0 16px;font-size:13px;color:#646970;">
				<?php
				echo esc_html( sprintf(
					/* translators: 1: active count, 2: total count, 3: percentage */
					__( '%1$d of %2$d signals active — %3$d%%', 'rankready-ai-llm-seo' ),
					$active,
					$total,
					$pct
				) );
				?>
			</p>

			<div class="rnrd-scorecard-groups" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
				<?php foreach ( $groups as $group_label => $group_signals ) : ?>
					<div class="rnrd-scorecard-group">
						<h3 style="margin:0 0 8px;font-size:13px;font-weight:700;color:#1d2327;text-transform:uppercase;letter-spacing:0.04em;"><?php echo esc_html( $group_label ); ?></h3>
						<ul style="margin:0;padding:0;list-style:none;font-size:13px;line-height:1.7;">
							<?php foreach ( $group_signals as $s ) :
								$is_on    = ! empty( $s['active'] );
								$icon     = $is_on ? '✓' : '○';
								$icon_col = $is_on ? '#00a32a' : '#a7aaad';
								$cta_kind = isset( $s['cta_kind'] ) ? $s['cta_kind'] : 'config';
								if ( 'tracking' === $cta_kind ) {
									$cta_label = $is_on ? __( 'View →', 'rankready-ai-llm-seo' ) : __( 'View →', 'rankready-ai-llm-seo' );
								} elseif ( 'freshness' === $cta_kind ) {
									$cta_label = $is_on ? __( 'View →', 'rankready-ai-llm-seo' ) : __( 'Scan →', 'rankready-ai-llm-seo' );
								} else {
									$cta_label = $is_on ? __( 'Configure →', 'rankready-ai-llm-seo' ) : __( 'Enable →', 'rankready-ai-llm-seo' );
								}
								$href = admin_url( 'admin.php' . $s['deeplink'] );
								?>
								<li style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:4px 0;">
									<span>
										<span class="rnrd-tick" style="display:inline-block;width:18px;color:<?php echo esc_attr( $icon_col ); ?>;font-weight:700;"><?php echo esc_html( $icon ); ?></span>
										<?php echo esc_html( $s['label'] ); ?>
									</span>
									<a href="<?php echo esc_url( $href ); ?>" style="font-size:12px;color:#0F9C70;text-decoration:none;white-space:nowrap;">
										<?php echo esc_html( $cta_label ); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: AI Content — AI Summary | AI FAQ | Author Box | Schema
	// ═══════════════════════════════════════════════════════════════════════════

	/**
	 * AI Content tab — subtabs for Summary, FAQ, Author Box, and Schema.
	 */
	private static function render_tab_content(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only $_GET[sub] for sub-tab display routing.
		$sub = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : 'summary';
		$sub_tabs = array(
			'summary' => __( 'AI Summary', 'rankready-ai-llm-seo' ),
			'faq'     => __( 'AI FAQ', 'rankready-ai-llm-seo' ),
			'author'  => __( 'Author Box (E-E-A-T)', 'rankready-ai-llm-seo' ),
			'schema'  => __( 'Schema', 'rankready-ai-llm-seo' ),
		);
		if ( ! isset( $sub_tabs[ $sub ] ) ) {
			$sub = 'summary';
		}
		?>
		<?php settings_errors(); ?>
		<?php self::render_tab_intro(
			__( 'AI Content', 'rankready-ai-llm-seo' ),
			__( 'Add summaries, FAQs, author trust, and schema so AI engines can quote your pages accurately.', 'rankready-ai-llm-seo' )
		); ?>

		<div class="rnrd-insights-toolbar">
			<nav class="rnrd-insights-subnav" aria-label="<?php esc_attr_e( 'AI Content sections', 'rankready-ai-llm-seo' ); ?>">
				<?php foreach ( $sub_tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'content', 'sub' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
					   class="<?php echo $sub === $slug ? 'is-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<?php
		switch ( $sub ) {
			case 'faq':
				self::render_content_sub_faq();
				break;
			case 'author':
				self::render_content_sub_author();
				break;
			case 'schema':
				self::render_content_sub_schema();
				break;
			case 'summary':
			default:
				self::render_content_sub_summary();
				break;
		}
	}

	/**
	 * Content → AI Summary subtab.
	 */
	private static function render_content_sub_summary(): void {
		?>
		<?php self::render_content_provider_notice(); ?>

		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::CONTENT_GROUP ); ?>

			<?php
			/*
			 * v1.1.5 fix (#4) — preserve rnrd_product_context across "Save AI Summary".
			 * It is registered to CONTENT_GROUP (back-compat read by RNRD_Generator/RNRD_Faq
			 * as a legacy fallback when Brand About is empty) but has NO input since rc.3,
			 * so this form's save would write null → wipe the value for pre-rc.3 users.
			 * Value-preserving hidden input keeps it intact. Never Pro-rendered → unconditional.
			 */
			?>
			<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_PRODUCT_CONTEXT ); ?>" value="<?php echo esc_attr( (string) get_option( RNRD_OPT_PRODUCT_CONTEXT, '' ) ); ?>" />

			<?php self::render_tab_summary(); ?>
		</form>

		<?php
		// Bulk Regenerate — AI Summaries is a PRO engine. Pro renders the real
		// AJAX card via `rnrd_pro_bulk_summary_card`; Free shows Coming Soon.
		if ( has_action( 'rnrd_pro_bulk_summary_card' ) ) {
			do_action( 'rnrd_pro_bulk_summary_card' );
		} else {
			self::render_coming_soon_box(
				__( 'Bulk Regenerate — AI Summaries', 'rankready-ai-llm-seo' ),
				__( 'Run AI summaries across every published post in one resumable job. Manual single-post generation stays unlimited.', 'rankready-ai-llm-seo' )
			);
		}
	}

	/**
	 * Content → AI FAQ subtab.
	 */
	private static function render_content_sub_faq(): void {
		?>
		<?php self::render_content_provider_notice(); ?>

		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::FAQ_GROUP ) /* v1.0.3 — FAQ form posts to dedicated FAQ_GROUP to prevent cross-nulling Summary settings */; ?>

			<?php self::render_tab_faq(); ?>
		</form>

		<?php
		// Bulk Regenerate — FAQ is a PRO engine. Pro renders the real AJAX card
		// via `rnrd_pro_bulk_faq_card`; Free shows Coming Soon.
		if ( has_action( 'rnrd_pro_bulk_faq_card' ) ) {
			do_action( 'rnrd_pro_bulk_faq_card' );
		} else {
			self::render_coming_soon_box(
				__( 'Bulk Regenerate — FAQ', 'rankready-ai-llm-seo' ),
				__( 'Generate FAQ Q&A pairs for every published post in one resumable job. Manual single-post FAQ generation stays unlimited.', 'rankready-ai-llm-seo' )
			);
		}
	}

	/**
	 * Content → Author Box (E-E-A-T) subtab.
	 */
	private static function render_content_sub_author(): void {
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::AUTHORITY_GROUP ); ?>
			<?php self::render_authority_preserve_hiddens( 'author' ); ?>
			<?php self::render_tab_author(); ?>
		</form>

		<?php
		// ── Bulk Author Changer (relocated from Advanced tab in rc.6) ──────
		// AJAX-driven, no form wrapper needed. All field IDs preserved.
		self::render_card_bulk_author();
	}

	/**
	 * Content → Schema subtab.
	 */
	private static function render_content_sub_schema(): void {
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::AUTHORITY_GROUP ); ?>
			<?php self::render_authority_preserve_hiddens( 'schema' ); ?>
			<?php self::render_tab_schema(); ?>
		</form>
		<?php
	}

	/**
	 * Compact AI provider status for AI Content Summary / FAQ subtabs.
	 * Warn banner when no key; info banner with active provider + model when configured.
	 */
	private static function render_content_provider_notice(): void {
		$settings_url = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=settings' );

		if ( ! class_exists( 'RNRD_LLM' ) || ! RNRD_LLM::active_provider_ready() ) {
			?>
			<div class="rnrd-card rnrd-dash-alert rnrd-dash-alert--warn" role="status">
				<p class="rnrd-dash-alert__text">
					<strong><?php esc_html_e( 'AI setup needed.', 'rankready-ai-llm-seo' ); ?></strong>
					<?php esc_html_e( 'Choose a provider and add an API key to generate Summaries and FAQs.', 'rankready-ai-llm-seo' ); ?>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open Settings →', 'rankready-ai-llm-seo' ); ?></a>
				</p>
			</div>
			<?php
			return;
		}

		$provider       = RNRD_LLM::get_active_provider();
		$provider_label = RNRD_LLM::get_provider_label( $provider );
		$model_id       = RNRD_LLM::get_model( $provider );
		$models         = RNRD_LLM::get_models_for( $provider );
		$model_label    = isset( $models[ $model_id ] ) ? (string) $models[ $model_id ] : $model_id;
		?>
		<div class="rnrd-card rnrd-dash-alert rnrd-dash-alert--info" role="status">
			<p class="rnrd-dash-alert__text">
				<strong>
					<?php
					echo esc_html( sprintf(
						/* translators: 1: provider label, 2: model label */
						__( 'Using %1$s · %2$s', 'rankready-ai-llm-seo' ),
						$provider_label,
						$model_label
					) );
					?>
				</strong>
				<?php esc_html_e( 'for Summary and FAQ generation.', 'rankready-ai-llm-seo' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change in Settings →', 'rankready-ai-llm-seo' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Emit hidden inputs that preserve AUTHORITY_GROUP options not present in
	 * the current form. Author and Schema share rnrd_authority_group — saving
	 * one must not null the other (DO-NOT-REINTRODUCE #1).
	 *
	 * @param string $view 'author' or 'schema' — which form is rendering.
	 */
	private static function render_authority_preserve_hiddens( string $view ): void {
		/*
		 * v1.1.5 fix (#3) — value-preserving hidden inputs for AUTHORITY_GROUP
		 * options that have NO rendered input in the current form. Without these,
		 * options.php writes null for every registered option absent from $_POST.
		 * Pro-gated fields are skipped when the matching Pro hook is active so the
		 * real control wins (last occurrence in $_POST).
		 */
		$rnrd_pro_eeat_active = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() && has_action( 'rnrd_pro_eeat_schema' );
		$rnrd_pro_rows_active = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() && has_action( 'rnrd_pro_schema_rows' );

		if ( 'author' === $view ) {
			// Preserve all Schema options (this form only edits Author fields).
			$schema_opts = array(
				RNRD_OPT_SCHEMA_ARTICLE   => 'on',
				RNRD_OPT_SCHEMA_FAQ       => 'on',
				RNRD_OPT_SCHEMA_SPEAKABLE => 'on',
				RNRD_OPT_SCHEMA_HOWTO     => 'on',
				RNRD_OPT_SCHEMA_ITEMLIST  => 'on',
			);
			foreach ( $schema_opts as $opt => $default ) {
				printf(
					'<input type="hidden" name="%1$s" value="%2$s" />' . "\n",
					esc_attr( $opt ),
					esc_attr( (string) get_option( $opt, $default ) )
				);
			}
			/*
			 * SCHEMA_BATCH_SIZE has NO input in Free OR Pro — preserve unconditionally
			 * or options.php resets it to 0 (absint(null)) on every save.
			 */
			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />' . "\n",
				esc_attr( RNRD_OPT_SCHEMA_BATCH_SIZE ),
				esc_attr( (string) get_option( RNRD_OPT_SCHEMA_BATCH_SIZE, 10 ) )
			);
			// Pro EEAT fields: preserve when Pro is not rendering them on this form.
			if ( ! $rnrd_pro_eeat_active ) {
				printf( '<input type="hidden" name="%1$s" value="%2$s" />' . "\n", esc_attr( RNRD_OPT_AUTHOR_SCHEMA_ENABLE ), esc_attr( (string) get_option( RNRD_OPT_AUTHOR_SCHEMA_ENABLE, 'on' ) ) );
				printf( '<input type="hidden" name="%1$s" value="%2$s" />' . "\n", esc_attr( RNRD_OPT_AUTHOR_EDITORIAL_URL ), esc_attr( (string) get_option( RNRD_OPT_AUTHOR_EDITORIAL_URL, '' ) ) );
				printf( '<input type="hidden" name="%1$s" value="%2$s" />' . "\n", esc_attr( RNRD_OPT_AUTHOR_FACTCHECK_URL ), esc_attr( (string) get_option( RNRD_OPT_AUTHOR_FACTCHECK_URL, '' ) ) );
				printf( '<input type="hidden" name="%1$s" value="%2$s" />' . "\n", esc_attr( RNRD_OPT_AUTHOR_TRUST_ENABLE ), esc_attr( (string) get_option( RNRD_OPT_AUTHOR_TRUST_ENABLE, 'off' ) ) );
			}
			return;
		}

		// Schema view — preserve all Author options.
		$author_scalars = array(
			RNRD_OPT_AUTHOR_ENABLE         => 'on',
			RNRD_OPT_AUTHOR_AUTO_DISPLAY   => 'off',
			RNRD_OPT_AUTHOR_LAYOUT         => 'card',
			RNRD_OPT_AUTHOR_HEADING        => 'About the Author',
			RNRD_OPT_AUTHOR_HEADING_TAG    => 'h3',
			RNRD_OPT_AUTHOR_SCHEMA_ENABLE  => 'on',
			RNRD_OPT_AUTHOR_EDITORIAL_URL  => '',
			RNRD_OPT_AUTHOR_FACTCHECK_URL  => '',
			RNRD_OPT_AUTHOR_TRUST_ENABLE   => 'off',
		);
		foreach ( $author_scalars as $opt => $default ) {
			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />' . "\n",
				esc_attr( $opt ),
				esc_attr( (string) get_option( $opt, $default ) )
			);
		}
		$author_types = array_values( array_filter( (array) get_option( RNRD_OPT_AUTHOR_POST_TYPES, array( 'post' ) ) ) );
		printf(
			'<input type="hidden" name="%s[]" value="" />' . "\n",
			esc_attr( RNRD_OPT_AUTHOR_POST_TYPES )
		);
		foreach ( $author_types as $pt ) {
			printf(
				'<input type="hidden" name="%1$s[]" value="%2$s" />' . "\n",
				esc_attr( RNRD_OPT_AUTHOR_POST_TYPES ),
				esc_attr( (string) $pt )
			);
		}
		if ( ! $rnrd_pro_rows_active ) {
			printf( '<input type="hidden" name="%1$s" value="%2$s" />' . "\n", esc_attr( RNRD_OPT_SCHEMA_HOWTO ), esc_attr( (string) get_option( RNRD_OPT_SCHEMA_HOWTO, 'on' ) ) );
			printf( '<input type="hidden" name="%1$s" value="%2$s" />' . "\n", esc_attr( RNRD_OPT_SCHEMA_ITEMLIST ), esc_attr( (string) get_option( RNRD_OPT_SCHEMA_ITEMLIST, 'on' ) ) );
		}
		printf(
			'<input type="hidden" name="%1$s" value="%2$s" />' . "\n",
			esc_attr( RNRD_OPT_SCHEMA_BATCH_SIZE ),
			esc_attr( (string) get_option( RNRD_OPT_SCHEMA_BATCH_SIZE, 10 ) )
		);
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Advanced — Headless + Tools + Info merged
	// ═══════════════════════════════════════════════════════════════════════════

	// ══════════════════════════════════════════════════════════════════════════
	// TAB: Insights — Bot Activity / AI Citation / AI Referral / Freshness
	// ══════════════════════════════════════════════════════════════════════════
	// v1.2.0-beta.7 — Splits the three signals that were conflated on the
	// AI Visibility tab (slug crawlers):
	//   • Training-bot crawl  (inbound, slow — GPTBot/Google-Extended/ClaudeBot
	//     indexing for future model training)
	//   • Citation-bot crawl  (inbound, live — ChatGPT-User/OAI-SearchBot/
	//     PerplexityBot fetching to answer a real user query NOW)
	//   • AI Referral traffic (outbound — users clicking from ChatGPT.com /
	//     Perplexity.ai / etc. back to your site)
	// Each has its own sub-section header explaining the stage of the funnel.

	private static function render_tab_insights(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only $_GET[sub] for sub-tab display routing.
		$sub = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : 'bot-activity';
		$sub_tabs = array(
			'bot-activity' => __( 'Training Bots', 'rankready-ai-llm-seo' ),
			'citation'     => __( 'Citation Bots', 'rankready-ai-llm-seo' ),
			'referral'     => __( 'Real AI Referrals', 'rankready-ai-llm-seo' ),
			'freshness'    => __( 'Content Fresh', 'rankready-ai-llm-seo' ),
		);
		if ( ! isset( $sub_tabs[ $sub ] ) ) {
			$sub = 'bot-activity';
		}
		?>
		<?php settings_errors(); ?>
		<?php self::render_tab_intro(
			__( 'Insights', 'rankready-ai-llm-seo' ),
			__( 'Who trains on you, what they cite, and who clicks back — counts start at zero on a fresh install.', 'rankready-ai-llm-seo' )
		); ?>

		<!-- Sub-tab navigation + Demo toggle button -->
		<div class="rnrd-insights-toolbar">
			<nav class="rnrd-insights-subnav">
				<?php foreach ( $sub_tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'insights', 'sub' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
					   class="<?php echo $sub === $slug ? 'is-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php // v1.1.2 — "Preview with sample data" toggle removed from Free build. ?>
		</div>

		<?php
		switch ( $sub ) {
			case 'bot-activity':   self::render_insights_bot_activity();   break;
			case 'citation':       self::render_insights_citation();        break;
			case 'referral':       self::render_insights_referral();        break;
			case 'freshness':      self::render_insights_freshness();       break;
		}
	}

	/**
	 * Insights → Bot Activity sub-tab.
	 *
	 * Splits the existing Crawler Log into TWO clearly-labelled panels:
	 *   • Training (inbound, slow, model-training value)
	 *   • Citation (inbound, live, ~1:1 with AI answer citations)
	 * so users can finally tell which signal matters for which goal.
	 */

	/**
	 * Render Prev / N of M / Next pagination strip below tables that can
	 * grow unbounded (bot activity, citation candidates, freshness scans).
	 *
	 * @param int    $current Current page (1-indexed).
	 * @param int    $pages   Total pages.
	 * @param string $param   GET parameter name (e.g. 'bot_p', 'cite_p').
	 * @since FREE-100
	 */
	private static function render_pagination( int $current, int $pages, string $param ): void {
		if ( $pages <= 1 ) {
			return;
		}
		$prev = max( 1, $current - 1 );
		$next = min( $pages, $current + 1 );
		?>
		<nav class="rnrd-pagination" aria-label="<?php esc_attr_e( 'Pagination', 'rankready-ai-llm-seo' ); ?>">
			<a class="rnrd-pagination__btn <?php echo $current <= 1 ? 'is-disabled' : ''; ?>"
			   href="<?php echo esc_url( add_query_arg( $param, $prev ) ); ?>"
			   aria-disabled="<?php echo $current <= 1 ? 'true' : 'false'; ?>">
				<?php esc_html_e( '← Prev', 'rankready-ai-llm-seo' ); ?>
			</a>
			<span class="rnrd-pagination__info">
				<?php
				/* translators: 1: current page, 2: total pages */
				echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'rankready-ai-llm-seo' ), (int) $current, (int) $pages ) );
				?>
			</span>
			<a class="rnrd-pagination__btn <?php echo $current >= $pages ? 'is-disabled' : ''; ?>"
			   href="<?php echo esc_url( add_query_arg( $param, $next ) ); ?>"
			   aria-disabled="<?php echo $current >= $pages ? 'true' : 'false'; ?>">
				<?php esc_html_e( 'Next →', 'rankready-ai-llm-seo' ); ?>
			</a>
		</nav>
		<?php
	}

	/**
	 * Emit hidden inputs preserving INSIGHTS_GROUP options absent from the
	 * current Insights subtab form. Prevents options.php from nulling sibling toggles.
	 *
	 * @param string $except Option key being edited in this form.
	 */
	private static function render_insights_preserve_hiddens( string $except ): void {
		$opts = array(
			RNRD_OPT_AI_TRAINING_ENABLE => 'on',
			RNRD_OPT_AI_CITATION_ENABLE => 'on',
			RNRD_OPT_AI_REFERRAL_ENABLE => 'on',
		);
		foreach ( $opts as $key => $default ) {
			if ( $key === $except ) {
				continue;
			}
			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />' . "\n",
				esc_attr( $key ),
				esc_attr( (string) get_option( $key, $default ) )
			);
		}
	}

	/**
	 * Warning when bot-endpoint tracking cannot record new hits.
	 *
	 * @param string $enable Current toggle value ('on'|'off').
	 * @param string $kind   'training'|'citation'.
	 */
	private static function render_bot_endpoint_tracking_notice( string $enable, string $kind ): void {
		$llms_on = 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$md_on   = 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' );
		if ( 'on' === $enable && $llms_on && $md_on ) {
			return;
		}

		$llms_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=crawlers&sub=llms' );
		$md_url   = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=crawlers&sub=markdown' );
		$allowed  = array( 'a' => array( 'href' => true ) );
		$training = 'training' === $kind;

		echo '<p class="rnrd-notice rnrd-notice--warn" role="status">';
		if ( 'on' !== $enable ) {
			echo esc_html(
				$training
					? __( 'Tracking is currently off. Turn it on above to resume counting new training-bot activity.', 'rankready-ai-llm-seo' )
					: __( 'Tracking is currently off. Turn it on above to resume counting new citation-bot activity.', 'rankready-ai-llm-seo' )
			);
		} elseif ( ! $llms_on && ! $md_on ) {
			if ( $training ) {
				echo wp_kses(
					sprintf(
						/* translators: 1: llms.txt settings URL, 2: Markdown settings URL */
						__( 'Tracking is on, but both llms.txt and Markdown are disabled, so no new training-bot hits can be recorded. Enable <a href="%1$s">llms.txt</a> or <a href="%2$s">Markdown</a> in AI Visibility.', 'rankready-ai-llm-seo' ),
						esc_url( $llms_url ),
						esc_url( $md_url )
					),
					$allowed
				);
			} else {
				echo wp_kses(
					sprintf(
						/* translators: 1: llms.txt settings URL, 2: Markdown settings URL */
						__( 'Tracking is on, but both llms.txt and Markdown are disabled, so no new citation-bot hits can be recorded. Enable <a href="%1$s">llms.txt</a> or <a href="%2$s">Markdown</a> in AI Visibility.', 'rankready-ai-llm-seo' ),
						esc_url( $llms_url ),
						esc_url( $md_url )
					),
					$allowed
				);
			}
		} elseif ( ! $llms_on ) {
			echo wp_kses(
				sprintf(
					/* translators: %s: llms.txt settings URL */
					__( 'llms.txt is disabled, so only Markdown endpoint hits are counted. <a href="%s">Enable llms.txt</a> to also track those fetches.', 'rankready-ai-llm-seo' ),
					esc_url( $llms_url )
				),
				$allowed
			);
		} else {
			echo wp_kses(
				sprintf(
					/* translators: %s: Markdown settings URL */
					__( 'Markdown is disabled, so only llms.txt hits are counted. <a href="%s">Enable Markdown</a> to also track .md fetches.', 'rankready-ai-llm-seo' ),
					esc_url( $md_url )
				),
				$allowed
			);
		}
		echo '</p>';
	}

	private static function render_insights_bot_activity(): void {
		// v1.1.6 — demo-mode helpers removed. Real data only.
		$training_enable = (string) get_option( RNRD_OPT_AI_TRAINING_ENABLE, 'on' );
		$citation_hits = (int) RNRD_Crawler_Log::get_citation_hits_total( 30 );
		$training_hits = (int) RNRD_Crawler_Log::get_training_hits_total( 30 );
		$total_30d     = (int) RNRD_Crawler_Log::get_total( 30 );
		$unique_pages  = (int) RNRD_Crawler_Log::get_unique_pages( 30 );
		$bot_stats     = RNRD_Crawler_Log::get_bot_stats( 30 );


		// Split bot list by intent for footnote counts.
		$citation_bots_seen = 0;
		$training_bots_seen = 0;
		$max_hits           = 0;
		foreach ( $bot_stats as $row ) {
			$intent = RNRD_Crawler_Log::bot_intent( $row['bot_name'] );
			if ( 'citation' === $intent ) {
				$citation_bots_seen++;
			} elseif ( 'training' === $intent ) {
				$training_bots_seen++;
			}
			$max_hits = max( $max_hits, (int) $row['total'] );
		}

		// Reference counts — how many bots of each type RankReady CAN detect.
		// These match the bot pattern lists in RNRD_Crawler_Log.
		$citation_bots_tracked = 5; // ChatGPT-User, OAI-SearchBot, PerplexityBot, Claude-Web, DuckAssistBot
		$training_bots_tracked = 8; // GPTBot, ClaudeBot, Google-Extended, Bytespider, CCBot, Applebot-Extended, AI2Bot, Diffbot

		// Total published posts for "pages read of X" context.
		$total_posts = (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'page' )->publish;

		$has_data = $total_30d > 0;
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::INSIGHTS_GROUP ); ?>
			<?php self::render_insights_preserve_hiddens( RNRD_OPT_AI_TRAINING_ENABLE ); ?>
			<div class="rnrd-card" style="margin-bottom:16px;">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Training Bot Logging', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Count AI training crawlers that fetch your llms.txt and Markdown endpoints. Data stays on your site.', 'rankready-ai-llm-seo' ); ?></p>
				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable tracking', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_AI_TRAINING_ENABLE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_AI_TRAINING_ENABLE ); ?>"
									   value="on" <?php checked( $training_enable, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Track training-bot visits on AI endpoints', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'When off, no new training-bot hits are recorded. Existing totals below stay available. Default is on.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save tracking setting', 'rankready-ai-llm-seo' ), 'primary', 'submit', false ); ?>
			</div>
		</form>

		<div class="rnrd-card">
			<?php /* v1.1.15 — Restored card title to match Citation Bots /
			   Real AI Referrals / Content Freshness pattern. */ ?>
			<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Training Activity', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Training bots ingesting your content for future models — hits and pages read in the last 30 days.', 'rankready-ai-llm-seo' ); ?></p>

			<?php self::render_bot_endpoint_tracking_notice( $training_enable, 'training' ); ?>

			<div class="rnrd-kpi-row" role="group" aria-label="<?php esc_attr_e( 'Training bot activity', 'rankready-ai-llm-seo' ); ?>">
				<!-- KPI 1: Training hits — primary metric for this tab -->
				<div class="rnrd-kpi" data-intent="training">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Training hits', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $training_hits ) ); ?></div>
					<div class="rnrd-kpi__foot">
						<?php if ( $has_data && $training_hits > 0 ) :
							/* translators: 1: bots seen, 2: total training bots tracked */
							echo esc_html( sprintf( __( 'ingested by %1$d of %2$d training bots', 'rankready-ai-llm-seo' ), (int) $training_bots_seen, (int) $training_bots_tracked ) );
						else :
							esc_html_e( 'No AI crawler hits recorded yet — this fills in as bots discover and fetch your pages', 'rankready-ai-llm-seo' );
						endif; ?>
					</div>
				</div>

				<!-- KPI 2: Pages read — coverage of your library by training bots -->
				<div class="rnrd-kpi" data-intent="training">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Pages read', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $unique_pages ) ); ?></div>
					<div class="rnrd-kpi__foot">
						<?php if ( $has_data ) :
							/* translators: %s: total posts + pages count */
							echo esc_html( sprintf( __( 'of %s posts + pages published', 'rankready-ai-llm-seo' ), number_format_i18n( $total_posts ) ) );
						else :
							esc_html_e( 'How much of your library training bots have seen', 'rankready-ai-llm-seo' );
						endif; ?>
					</div>
				</div>

				<!-- KPI 3: Training bots seen — how many distinct training bots have read the site -->
				<div class="rnrd-kpi" data-intent="training">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Training bots seen', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( (int) $training_bots_seen ) ); ?></div>
					<div class="rnrd-kpi__foot">
						<?php if ( $has_data && $training_bots_seen > 0 ) :
							echo esc_html( sprintf(
								/* translators: %d: total training bots tracked */
								__( 'of %d training bots tracked (GPTBot, ClaudeBot, Google-Extended, CCBot, …)', 'rankready-ai-llm-seo' ),
								(int) $training_bots_tracked
							) );
						else :
							esc_html_e( '20+ training bots tracked across all endpoints', 'rankready-ai-llm-seo' );
						endif; ?>
					</div>
				</div>
			</div>

			<?php if ( ! $has_data ) : ?>
				<p class="rnrd-kpi-empty">
					<?php esc_html_e( 'No AI bot visits recorded yet. Counts populate automatically once any tracked AI crawler hits your llms.txt, /*.md, or homepage endpoints — no setup required.', 'rankready-ai-llm-seo' ); ?>
				</p>
			<?php else :
				// FREE-100 — Training Bots tab shows TRAINING-intent rows only.
				// Citation bots live on the dedicated Citation Bots sub-tab so
				// the two intents never mix in one table.
				$training_rows = array();
				foreach ( $bot_stats as $row ) {
					if ( 'training' === RNRD_Crawler_Log::bot_intent( $row['bot_name'] ) ) {
						$training_rows[] = $row;
					}
				}
				$training_max = ! empty( $training_rows ) ? max( array_column( $training_rows, 'total' ) ) : 1;
				// FREE-100 — pagination: page-size 10, GET param `bot_p`.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only $_GET param for display routing.
				$bot_page  = isset( $_GET['bot_p'] ) ? max( 1, (int) $_GET['bot_p'] ) : 1;
				$per_page  = 10;
				$total_rows = count( $training_rows );
				$pages      = (int) max( 1, ceil( $total_rows / $per_page ) );
				$bot_page   = min( $bot_page, $pages );
				$slice      = array_slice( $training_rows, ( $bot_page - 1 ) * $per_page, $per_page );
				?>
				<h3 class="rnrd-subsection-title" style="margin-top:24px;"><?php esc_html_e( 'Per-bot breakdown', 'rankready-ai-llm-seo' ); ?></h3>
				<?php if ( empty( $training_rows ) ) : ?>
					<p class="rnrd-kpi-empty"><?php esc_html_e( 'No training bot activity yet. Hits from crawlers such as GPTBot, ClaudeBot and Google-Extended will be listed here once they fetch your pages.', 'rankready-ai-llm-seo' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat striped rnrd-bot-table">
						<thead>
							<tr>
								<th style="width:40%;"><?php esc_html_e( 'Bot', 'rankready-ai-llm-seo' ); ?></th>
								<th><?php esc_html_e( 'Share of hits', 'rankready-ai-llm-seo' ); ?></th>
								<th style="width:12%;text-align:right;"><?php esc_html_e( 'Hits', 'rankready-ai-llm-seo' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $slice as $row ) :
								$hits = (int) $row['total'];
								$pct  = $training_max > 0 ? min( 100, round( ( $hits / $training_max ) * 100 ) ) : 0;
								?>
								<tr>
									<td><strong><?php echo esc_html( $row['bot_name'] ); ?></strong></td>
									<td>
										<div class="rnrd-bar-track" aria-hidden="true">
											<div class="rnrd-bar-fill rnrd-bar-fill--training" style="width:<?php echo (int) $pct; ?>%;"></div>
										</div>
									</td>
									<td style="text-align:right;"><strong><?php echo esc_html( number_format_i18n( $hits ) ); ?></strong></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php self::render_pagination( $bot_page, $pages, 'bot_p' ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_insights_citation(): void {
		// v1.1.6 — demo-mode helpers removed. Real data only.
		$citation_enable = (string) get_option( RNRD_OPT_AI_CITATION_ENABLE, 'on' );
		$citation_pages = RNRD_Crawler_Log::get_citation_top_pages( 30, 25 );

		$total_pages_cited = count( $citation_pages );
		$total_hits        = (int) array_sum( array_column( $citation_pages, 'hits' ) );
		$unique_bots       = array_sum( array_column( $citation_pages, 'unique_bots' ) ) > 0
			? (int) max( array_column( $citation_pages, 'unique_bots' ) )
			: 0;
		$max_hits          = $total_pages_cited > 0 ? max( array_column( $citation_pages, 'hits' ) ) : 1;
		$has_data          = $total_pages_cited > 0;
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::INSIGHTS_GROUP ); ?>
			<?php self::render_insights_preserve_hiddens( RNRD_OPT_AI_CITATION_ENABLE ); ?>
			<div class="rnrd-card" style="margin-bottom:16px;">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Citation Bot Logging', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Count citation-intent crawlers that fetch your llms.txt and Markdown endpoints. Data stays on your site.', 'rankready-ai-llm-seo' ); ?></p>
				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable tracking', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_AI_CITATION_ENABLE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_AI_CITATION_ENABLE ); ?>"
									   value="on" <?php checked( $citation_enable, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Track citation-bot visits on AI endpoints', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'When off, no new citation-bot hits are recorded. Existing totals below stay available. Default is on.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save tracking setting', 'rankready-ai-llm-seo' ), 'primary', 'submit', false ); ?>
			</div>
		</form>

		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Citation Candidates', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Pages citation bots already fetch as live answer sources — refresh these first.', 'rankready-ai-llm-seo' ); ?></p>

			<?php self::render_bot_endpoint_tracking_notice( $citation_enable, 'citation' ); ?>

			<div class="rnrd-kpi-row" role="group" aria-label="<?php esc_attr_e( 'Citation candidates summary', 'rankready-ai-llm-seo' ); ?>">
				<div class="rnrd-kpi" data-intent="citation">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Pages cited', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total_pages_cited ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php
						echo $has_data
							? esc_html__( 'unique URLs fetched by citation bots', 'rankready-ai-llm-seo' )
							: esc_html__( 'No citations recorded yet — this fills in as AI engines fetch and reference your pages', 'rankready-ai-llm-seo' );
					?></div>
				</div>
				<div class="rnrd-kpi" data-intent="citation">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Total citation hits', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total_hits ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'each hit = one AI answer using your content', 'rankready-ai-llm-seo' ); ?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Top page hits', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $has_data ? $max_hits : 0 ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php
						echo $has_data
							? esc_html__( 'on your single best-cited URL', 'rankready-ai-llm-seo' )
							: esc_html__( 'How concentrated your AI traffic is', 'rankready-ai-llm-seo' );
					?></div>
				</div>
			</div>

			<?php if ( ! $has_data ) : ?>
				<p class="rnrd-kpi-empty">
					<?php esc_html_e( 'No citation bot hits yet. ChatGPT-User, OAI-SearchBot, PerplexityBot, Claude-Web, and DuckAssistBot only fetch your pages when a real user asks the AI something your content might answer. Speed it up by adding FAQs to your top 10 posts and keeping content fresh.', 'rankready-ai-llm-seo' ); ?>
				</p>
			<?php else :
				// FREE-100 — pagination: 10 rows per page, GET param `cite_p`.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only $_GET param for display routing.
				$cite_page  = isset( $_GET['cite_p'] ) ? max( 1, (int) $_GET['cite_p'] ) : 1;
				$per_page   = 10;
				$total_rows = count( $citation_pages );
				$pages      = (int) max( 1, ceil( $total_rows / $per_page ) );
				$cite_page  = min( $cite_page, $pages );
				$slice      = array_slice( $citation_pages, ( $cite_page - 1 ) * $per_page, $per_page );
				?>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Top cited pages', 'rankready-ai-llm-seo' ); ?></h3>
				<table class="wp-list-table widefat striped rnrd-bot-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:18%;"><?php esc_html_e( 'Share of hits', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;text-align:right;"><?php esc_html_e( 'Hits', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;text-align:right;"><?php esc_html_e( 'Bots', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:14%;"><?php esc_html_e( 'Last fetched', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:8%;"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $slice as $cp ) :
							$cp_post_id = (int) ( $cp['post_id'] ?? 0 );
							$cp_title   = (string) ( $cp['post_title'] ?? '(no title)' );
							$cp_type    = (string) ( $cp['post_type'] ?? '' );
							$cp_path    = (string) ( $cp['url_path'] ?? '' );
							$cp_hits    = (int) ( $cp['hits'] ?? 0 );
							$cp_bots    = (int) ( $cp['unique_bots'] ?? 0 );
							$cp_seen    = (string) ( $cp['last_seen'] ?? '' );
							$cp_seen_ts = $cp_seen ? strtotime( $cp_seen ) : 0;
							$pct        = $max_hits > 0 ? min( 100, round( ( $cp_hits / $max_hits ) * 100 ) ) : 0;
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $cp_title ); ?></strong>
									<?php if ( $cp_type ) : ?>
										<span class="rnrd-badge rnrd-badge--neutral" style="margin-left:8px;font-size:10px;padding:1px 6px;"><?php echo esc_html( $cp_type ); ?></span>
									<?php endif; ?>
									<div><code><?php echo esc_html( $cp_path ); ?></code></div>
								</td>
								<td>
									<div class="rnrd-bar-track" aria-hidden="true">
										<div class="rnrd-bar-fill rnrd-bar-fill--citation" style="width:<?php echo (int) $pct; ?>%;"></div>
									</div>
								</td>
								<td style="text-align:right;"><strong><?php echo esc_html( number_format_i18n( $cp_hits ) ); ?></strong></td>
								<td style="text-align:right;"><?php echo esc_html( number_format_i18n( $cp_bots ) ); ?></td>
								<td><?php echo $cp_seen_ts ? esc_html( human_time_diff( $cp_seen_ts ) . ' ' . __( 'ago', 'rankready-ai-llm-seo' ) ) : '—'; ?></td>
								<td>
									<?php if ( $cp_post_id > 0 ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $cp_post_id ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'rankready-ai-llm-seo' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php self::render_pagination( $cite_page, $pages, 'cite_p' ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_insights_referral(): void {
		// v1.1.6 — demo-mode helpers removed. Real data only.
		$referral_enable = (string) get_option( RNRD_OPT_AI_REFERRAL_ENABLE, 'on' );
		$counts = class_exists( 'RNRD_AI_Referral' ) ? RNRD_AI_Referral::aggregate_last_n_days( 30 ) : array();

		$total       = (int) array_sum( $counts );
		$top_source  = '';
		$top_count   = 0;
		foreach ( $counts as $source => $count ) {
			if ( (int) $count > $top_count ) {
				$top_count  = (int) $count;
				$top_source = $source;
			}
		}
		$unique_sources = (int) count( array_filter( $counts ) );
		$max_count      = $total > 0 ? max( $counts ) : 1;
		$source_labels  = array(
			'chatgpt'    => 'ChatGPT',
			'perplexity' => 'Perplexity',
			'gemini'     => 'Gemini',
			'claude'     => 'Claude',
			'copilot'    => 'Copilot',
		);
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::INSIGHTS_GROUP ); ?>
			<?php self::render_insights_preserve_hiddens( RNRD_OPT_AI_REFERRAL_ENABLE ); ?>
			<div class="rnrd-card" style="margin-bottom:16px;">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Referral Tracking', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Count real visitors who arrive from ChatGPT, Perplexity, Claude, Gemini, or Copilot via the HTTP Referer header. Data stays on your site. Honours Sec-GPC and Do Not Track.', 'rankready-ai-llm-seo' ); ?></p>
				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable tracking', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_AI_REFERRAL_ENABLE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_AI_REFERRAL_ENABLE ); ?>"
									   value="on" <?php checked( $referral_enable, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Track AI referral visits on public pages', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'When off, no new Referer hits are recorded. Existing totals below stay available. Default is on.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save tracking setting', 'rankready-ai-llm-seo' ), 'primary', 'submit', false ); ?>
			</div>
		</form>

		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Referral Traffic', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Real humans who clicked through from ChatGPT, Perplexity, Claude, Gemini, or Copilot — tracked via HTTP Referer.', 'rankready-ai-llm-seo' ); ?></p>

			<?php if ( 'on' !== $referral_enable ) : ?>
				<p class="rnrd-notice rnrd-notice--warn" role="status">
					<?php esc_html_e( 'Tracking is currently off. Turn it on above to resume counting new AI referrals.', 'rankready-ai-llm-seo' ); ?>
				</p>
			<?php endif; ?>

			<div class="rnrd-kpi-row" role="group" aria-label="<?php esc_attr_e( 'AI referral summary', 'rankready-ai-llm-seo' ); ?>">
				<div class="rnrd-kpi" data-intent="citation">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'AI-sourced visits', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php
						echo $total > 0
							? esc_html__( 'across ChatGPT, Perplexity, Claude, Gemini, Copilot', 'rankready-ai-llm-seo' )
							: esc_html__( 'No AI referrals recorded yet — this fills in when someone clicks through from an AI engine', 'rankready-ai-llm-seo' );
					?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Top source', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value">
						<?php echo $total > 0 ? esc_html( $source_labels[ $top_source ] ?? ucfirst( $top_source ) ) : '—'; ?>
					</div>
					<div class="rnrd-kpi__foot"><?php
						if ( $total > 0 ) {
							/* translators: 1: top-source visit count, 2: total visit count */
							echo esc_html( sprintf( __( '%1$s of %2$s referrals', 'rankready-ai-llm-seo' ), number_format_i18n( $top_count ), number_format_i18n( $total ) ) );
						} else {
							esc_html_e( 'Which AI sends the most readers', 'rankready-ai-llm-seo' );
						}
					?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Sources reached', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $unique_sources ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'of 5 tracked AI engines', 'rankready-ai-llm-seo' ); ?></div>
				</div>
			</div>

			<?php if ( $total > 0 ) : ?>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Per-source breakdown', 'rankready-ai-llm-seo' ); ?></h3>
				<table class="wp-list-table widefat striped rnrd-bot-table">
					<thead>
						<tr>
							<th style="width:30%;"><?php esc_html_e( 'AI engine', 'rankready-ai-llm-seo' ); ?></th>
							<th><?php esc_html_e( 'Share of referrals', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:12%;text-align:right;"><?php esc_html_e( 'Visits', 'rankready-ai-llm-seo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $counts as $source => $count ) :
							if ( (int) $count < 1 ) { continue; }
							$pct = $max_count > 0 ? min( 100, round( ( $count / $max_count ) * 100 ) ) : 0;
							?>
							<tr>
								<td><strong><?php echo esc_html( $source_labels[ $source ] ?? ucfirst( $source ) ); ?></strong></td>
								<td>
									<div class="rnrd-bar-track" aria-hidden="true">
										<div class="rnrd-bar-fill rnrd-bar-fill--citation" style="width:<?php echo (int) $pct; ?>%;"></div>
									</div>
								</td>
								<td style="text-align:right;"><strong><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="rnrd-kpi-empty">
					<?php esc_html_e( 'No AI-sourced visits yet. Tracking is already live — counters fill in as ChatGPT, Perplexity, Claude, Gemini, or Copilot send their first visitor through to your site.', 'rankready-ai-llm-seo' ); ?>
				</p>
			<?php endif; ?>

			<details style="margin-top:16px;">
				<summary><?php esc_html_e( 'How is this different from Bot Activity?', 'rankready-ai-llm-seo' ); ?></summary>
				<p style="margin:10px 0 0;">
					<strong><?php esc_html_e( 'Bot Activity', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'measures AI crawlers reading your site (no human involved) — it answers "is RankReady in the AI retrieval pool?"', 'rankready-ai-llm-seo' ); ?>
				</p>
				<p style="margin:6px 0 0;">
					<strong><?php esc_html_e( 'AI Referral Traffic', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'measures real humans who read an AI answer and clicked through — it answers "is AI sending users to my site?"', 'rankready-ai-llm-seo' ); ?>
				</p>
			</details>
		</div>
		<?php
	}

	private static function render_insights_freshness(): void {
		// Merged in rc.16 — single card carries the intro, scan tool, and
		// the segmented widget (render_card_freshness_alerts now renders
		// RNRD_Freshness::render_widget() inside its closing </div>).
		self::render_card_freshness_alerts();
	}

	/**
	 * Settings tab — API Keys | Cloudflare | Advanced subtabs (mirrors Insights subnav pattern).
	 */
	private static function render_tab_settings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only $_GET[sub] for sub-tab display routing.
		$sub = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : 'api-keys';
		$sub_tabs = array(
			'api-keys'    => __( 'API Keys', 'rankready-ai-llm-seo' ),
			'cloudflare' => __( 'Cloudflare', 'rankready-ai-llm-seo' ),
			'advanced'    => __( 'Advanced', 'rankready-ai-llm-seo' ),
		);
		if ( ! isset( $sub_tabs[ $sub ] ) ) {
			$sub = 'api-keys';
		}
		?>
		<?php settings_errors(); ?>
		<?php self::render_tab_intro(
			__( 'Settings', 'rankready-ai-llm-seo' ),
			__( 'Connect your AI provider and run diagnostics, usage, and maintenance tools.', 'rankready-ai-llm-seo' )
		); ?>

		<div class="rnrd-insights-toolbar">
			<nav class="rnrd-insights-subnav" aria-label="<?php esc_attr_e( 'Settings sections', 'rankready-ai-llm-seo' ); ?>">
				<?php foreach ( $sub_tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'settings', 'sub' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
					   class="<?php echo $sub === $slug ? 'is-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<?php
		switch ( $sub ) {
			case 'cloudflare':
				self::render_tab_cloudflare();
				break;
			case 'advanced':
				self::render_tab_advanced();
				break;
			case 'api-keys':
			default:
				self::render_tab_api();
				break;
		}
	}

	/**
	 * Settings → Cloudflare — always visible; connect form works even when CF
	 * is not auto-detected (staging / DNS-only false negatives).
	 */
	private static function render_tab_cloudflare(): void {
		if ( class_exists( 'RNRD_Cloudflare' ) ) {
			RNRD_Cloudflare::render_card();
		}
	}

	private static function render_tab_advanced(): void {
		// v1.2.0-rc.5 — Advanced tab simplified.
		// REMOVED: Headless / Public API section (Dashboard explains plugin scope).
		// REMOVED: How It Works + Quick Stats cards (Dashboard already shows tab purposes).
		// REPLACED: Health Check card → new live 22-probe Diagnostics card.
		// KEPT IN PLACE FOR rc.5: Bulk Summary, Bulk FAQ, Bulk Author, API Usage,
		// Freshness Alerts — these will relocate to their proper tabs in rc.6
		// alongside the card-merge refactor (preserving form field names + data).
		?>
		<?php self::render_tab_tools(); ?>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: API Keys
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_api(): void {
		$active_provider = RNRD_LLM::get_active_provider();

		// Helper closure to mask saved keys for display.
		$mask = static function( $val ) {
			return ! empty( $val ) ? substr( (string) $val, 0, 7 ) . str_repeat( '••••', 6 ) : '';
		};

		$openai_disp    = $mask( get_option( RNRD_OPT_KEY, '' ) );
		$anthropic_disp = $mask( get_option( RNRD_OPT_ANTHROPIC_KEY, '' ) );
		$gemini_disp    = $mask( get_option( RNRD_OPT_GEMINI_KEY, '' ) );
		$deepseek_disp  = $mask( get_option( RNRD_OPT_DEEPSEEK_KEY, '' ) );
		?>
		<?php self::model_migration_notice(); ?>

		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::SETTINGS_GROUP ); ?>

			<!-- LLM Provider Picker -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Provider', 'rankready-ai-llm-seo' ); ?></h2>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Active provider', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<fieldset id="rnrd-llm-provider-picker" class="rnrd-radio-list">
								<?php
								$providers = array(
									'openai'    => array( 'OpenAI',    __( 'GPT-5.4 nano, mini, GPT-5.4, GPT-5.5', 'rankready-ai-llm-seo' ) ),
									'anthropic' => array( 'Claude',    __( 'Haiku 4.5, Sonnet 4.6, Opus 4.7', 'rankready-ai-llm-seo' ) ),
									'gemini'    => array( 'Gemini',    __( '3.1 Flash Lite, 2.5 Flash, 3.5 Flash, 2.5 Pro', 'rankready-ai-llm-seo' ) ),
									'deepseek'  => array( 'DeepSeek',  __( 'V4 Flash, V4 Pro', 'rankready-ai-llm-seo' ) ),
								);
								foreach ( $providers as $id => $info ) :
									?>
									<label class="rnrd-radio-list__item">
										<input type="radio" name="<?php echo esc_attr( RNRD_OPT_LLM_PROVIDER ); ?>" value="<?php echo esc_attr( $id ); ?>" <?php checked( $active_provider, $id ); ?> data-rnrd-provider-radio />
										<span class="rnrd-radio-list__label">
											<strong><?php echo esc_html( $info[0] ); ?></strong>
											<span class="rnrd-radio-list__meta">— <?php echo esc_html( $info[1] ); ?></span>
										</span>
									</label>
									<?php
								endforeach;
								?>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php
				$render_key_field = function ( $provider_id, $input_id, $option_name, $display_value ) {
					?>
					<div class="rnrd-input-action-row">
						<input type="password" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $option_name ); ?>"
							   value="<?php echo esc_attr( $display_value ); ?>" class="regular-text"
							   autocomplete="new-password" spellcheck="false"
							   data-rnrd-key-for="<?php echo esc_attr( $provider_id ); ?>" />
						<button type="button" class="button button-secondary" data-rnrd-verify-provider="<?php echo esc_attr( $provider_id ); ?>">
							<?php esc_html_e( 'Verify Key', 'rankready-ai-llm-seo' ); ?>
						</button>
					</div>
					<span class="rnrd-input-action-row__status" data-rnrd-verify-status="<?php echo esc_attr( $provider_id ); ?>"></span>
					<?php
				};

				$render_model_field = function ( $provider_id, $select_id, $option_name ) {
					$cur     = RNRD_LLM::get_model( $provider_id );
					$models  = RNRD_LLM::get_models_for( $provider_id );
					?>
					<div class="rnrd-input-action-row">
						<select name="<?php echo esc_attr( $option_name ); ?>" id="<?php echo esc_attr( $select_id ); ?>" data-rnrd-model-for="<?php echo esc_attr( $provider_id ); ?>">
							<?php foreach ( $models as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $cur, $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button button-secondary" data-rnrd-refresh-models="<?php echo esc_attr( $provider_id ); ?>">
							<?php esc_html_e( 'Refresh list', 'rankready-ai-llm-seo' ); ?>
						</button>
					</div>
					<span class="rnrd-input-action-row__status" data-rnrd-models-status="<?php echo esc_attr( $provider_id ); ?>"></span>
					<?php
				};
				?>

			<!-- OpenAI -->
			<div class="rnrd-provider-card-inner" data-rnrd-provider="openai" <?php echo 'openai' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'OpenAI', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Powers AI Summary generation and FAQ answer writing.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_api_key"><?php esc_html_e( 'API Key', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $render_key_field( 'openai', 'rnrd_api_key', RNRD_OPT_KEY, $openai_disp ); ?>
							<p class="description"><?php
								printf(
									/* translators: %s: link to the OpenAI API keys page */
									esc_html__( 'Your OpenAI secret key (sk-...). Stored server-side only. Get one at %s.', 'rankready-ai-llm-seo' ),
									'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a>'
								);
							?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_model"><?php esc_html_e( 'Model', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $render_model_field( 'openai', 'rnrd_model', RNRD_OPT_MODEL ); ?>
							<p class="description"><?php esc_html_e( 'gpt-5.4-nano is the cheapest and fastest. gpt-5.4-mini is recommended for most sites. gpt-5.4 is balanced. gpt-5.5 is highest quality.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Anthropic (Claude) -->
			<div class="rnrd-provider-card-inner" data-rnrd-provider="anthropic" <?php echo 'anthropic' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Claude (Anthropic)', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Claude is exceptional at following content rules and producing factual, citation-quality output for AI summaries and FAQs.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_anthropic_key"><?php esc_html_e( 'API Key', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $render_key_field( 'anthropic', 'rnrd_anthropic_key', RNRD_OPT_ANTHROPIC_KEY, $anthropic_disp ); ?>
							<p class="description"><?php
								printf(
									/* translators: %s: link to the Anthropic Console API keys page */
									esc_html__( 'Your Anthropic API key (sk-ant-...). Get one at %s.', 'rankready-ai-llm-seo' ),
									'<a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener noreferrer">console.anthropic.com</a>'
								);
							?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_anthropic_model"><?php esc_html_e( 'Model', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $render_model_field( 'anthropic', 'rnrd_anthropic_model', RNRD_OPT_ANTHROPIC_MODEL ); ?>
							<p class="description"><?php esc_html_e( 'claude-haiku-4-5 is the cheapest and fastest. claude-sonnet-4-6 is the balanced pick. claude-opus-4-7 is highest quality (most expensive).', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Gemini -->
			<div class="rnrd-provider-card-inner" data-rnrd-provider="gemini" <?php echo 'gemini' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Gemini (Google)', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Gemini is the cheapest of the major providers and ships native JSON output. Great default for high-volume sites.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_gemini_key"><?php esc_html_e( 'API Key', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $render_key_field( 'gemini', 'rnrd_gemini_key', RNRD_OPT_GEMINI_KEY, $gemini_disp ); ?>
							<p class="description"><?php
								printf(
									/* translators: %s: link to the Google AI Studio API key page */
									esc_html__( 'Your Google AI Studio API key (AIza...). Get one at %s.', 'rankready-ai-llm-seo' ),
									'<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">aistudio.google.com/apikey</a>'
								);
							?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_gemini_model"><?php esc_html_e( 'Model', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $render_model_field( 'gemini', 'rnrd_gemini_model', RNRD_OPT_GEMINI_MODEL ); ?>
							<p class="description"><?php esc_html_e( 'gemini-2.5-flash is recommended for both summaries and FAQ.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- DeepSeek -->
			<div class="rnrd-provider-card-inner" data-rnrd-provider="deepseek" <?php echo 'deepseek' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'DeepSeek', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Cost-efficient open-source models. V4 Flash for everyday generation, V4 Pro when you need higher quality. (The legacy `deepseek-chat` and `deepseek-reasoner` aliases are being retired by DeepSeek — switch to V4 IDs.)', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_deepseek_key"><?php esc_html_e( 'API Key', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $render_key_field( 'deepseek', 'rnrd_deepseek_key', RNRD_OPT_DEEPSEEK_KEY, $deepseek_disp ); ?>
							<p class="description"><?php
								printf(
									/* translators: %s: link to the DeepSeek API keys page */
									esc_html__( 'Your DeepSeek API key (sk-...). Get one at %s.', 'rankready-ai-llm-seo' ),
									'<a href="https://platform.deepseek.com/api_keys" target="_blank" rel="noopener noreferrer">platform.deepseek.com/api_keys</a>'
								);
							?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_deepseek_model"><?php esc_html_e( 'Model', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $render_model_field( 'deepseek', 'rnrd_deepseek_model', RNRD_OPT_DEEPSEEK_MODEL ); ?>
						</td>
					</tr>
				</table>
			</div>
				<?php submit_button( __( 'Save AI', 'rankready-ai-llm-seo' ), 'primary', 'submit_ai', false ); ?>
			</div><!-- /.rnrd-card "AI" (merged: Provider picker + Provider config) -->

			<?php // Provider visibility toggler moved into assets/admin.js (rc.16, WP.org Rule #3 — no inline <script> in PHP). ?>

			<!-- Product Context — REMOVED in rc.3.
			     The "About" field on the Brand Identity card (AI Crawlers tab) now serves
			     this purpose. RNRD_Generator + RNRD_Faq inject Brand Identity About into the
			     AI prompts, so admins only fill in one place. -->

			<!-- DataForSEO -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'DataForSEO', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Optional question discovery for FAQ generation via DataForSEO keyword suggestions.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_dfs_login"><?php esc_html_e( 'API Login', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="text" id="rnrd_dfs_login" name="<?php echo esc_attr( RNRD_OPT_DFS_LOGIN ); ?>"
							       value="<?php echo esc_attr( (string) get_option( RNRD_OPT_DFS_LOGIN, '' ) ); ?>"
							       class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Your DataForSEO API login email.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_dfs_password"><?php esc_html_e( 'API Password', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $dfs_pw = (string) get_option( RNRD_OPT_DFS_PASSWORD, '' ); ?>
							<?php $dfs_pw_display = ! empty( $dfs_pw ) ? str_repeat( '••••', 4 ) : ''; ?>
							<div class="rnrd-input-action-row">
								<input type="password" id="rnrd_dfs_password" name="<?php echo esc_attr( RNRD_OPT_DFS_PASSWORD ); ?>"
								       value="<?php echo esc_attr( $dfs_pw_display ); ?>"
								       class="regular-text" autocomplete="new-password" />
								<button type="button" id="rnrd-verify-dfs" class="button button-secondary">
									<?php esc_html_e( 'Verify DataForSEO', 'rankready-ai-llm-seo' ); ?>
								</button>
							</div>
							<span id="rnrd-verify-dfs-status" class="rnrd-input-action-row__status"></span>
							<p class="description"><?php esc_html_e( 'Your DataForSEO API password. Enter a new value to change.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save DataForSEO', 'rankready-ai-llm-seo' ), 'primary', 'submit_dfs', false ); ?>
			</div>

			<!-- Data Retention card — MOVED to Advanced tab in rc.3 (renders inside render_tab_advanced). -->
		</form>

		<!-- Connection Status card removed in rc.16 — was duplicate of state
		     already shown inside the AI card (Verify Key buttons + provider
		     selector). User opted to keep only the actionable form. -->

		<?php
		// ── API Usage card (relocated from Advanced tab in rc.6) ───────────
		// Read-only stats panel — no settings form needed. All option keys
		// (rnrd_token_usage, rnrd_dfs_usage) and JS hooks (#rr-tokens-load,
		// #rr-tokens-tbody, etc.) preserved verbatim from rc.5.
		self::render_card_api_usage();
		?>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: AI Summary
	// ═══════════════════════════════════════════════════════════════════════════

	/**
	 * Post-type picker that allows an empty selection (Summary, FAQ, Author Box).
	 */
	private static function render_optional_post_types_section( string $option, string $help ): void {
		$selected = array_values( array_filter( (array) get_option( $option, array( 'post' ) ) ) );
		?>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Post Types', 'rankready-ai-llm-seo' ); ?></h3>
				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Apply to', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<fieldset class="rnrd-checkboxes-inline">
								<input type="hidden" name="<?php echo esc_attr( $option ); ?>[]" value="" />
								<?php foreach ( self::get_allowed_post_types() as $slug => $label ) : ?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[]"
											   value="<?php echo esc_attr( $slug ); ?>"
											   <?php checked( in_array( $slug, $selected, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<?php if ( ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) ) : ?>
								<p class="rnrd-cpt-hint"><?php esc_html_e( 'Want to include Custom Post Types?', 'rankready-ai-llm-seo' ); ?> <span class="rnrd-soon-tag"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span></p>
							<?php endif; ?>
							<p class="description"><?php echo esc_html( $help ); ?></p>
						</td>
					</tr>
				</table>
		<?php
	}

	/**
	 * Compact Auto-display radios. Author Box may include "both".
	 */
	private static function render_auto_display_radios( string $option, string $current, bool $allow_both = false ): void {
		$allowed = $allow_both
			? array( 'off', 'before', 'after', 'both' )
			: array( 'off', 'before', 'after' );
		if ( ! in_array( $current, $allowed, true ) ) {
			$current = 'off';
		}
		$choices = array(
			'off'    => __( 'Off — use Gutenberg block, Elementor widget, or shortcode', 'rankready-ai-llm-seo' ),
			'before' => __( 'Before content', 'rankready-ai-llm-seo' ),
			'after'  => __( 'After content', 'rankready-ai-llm-seo' ),
		);
		if ( $allow_both ) {
			$choices['both'] = __( 'Before & after content (both)', 'rankready-ai-llm-seo' );
		}
		?>
							<fieldset class="rnrd-radios-stack">
								<?php foreach ( $choices as $value => $label ) : ?>
									<label>
										<input type="radio" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
		<?php
	}

	private static function render_tab_summary(): void {
		?>
			<!-- Post Types, AI Generation, Display. -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Summary', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Key Takeaways — the lines ChatGPT and Perplexity quote directly.', 'rankready-ai-llm-seo' ); ?></p>

				<?php
				self::render_optional_post_types_section(
					RNRD_OPT_POST_TYPES,
					__( 'Uncheck all to disable summaries feature. Existing summaries are kept.', 'rankready-ai-llm-seo' )
				);
				?>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'AI Generation', 'rankready-ai-llm-seo' ); ?></h3>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_custom_prompt"><?php esc_html_e( 'Custom Prompt', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( RNRD_OPT_CUSTOM_PROMPT ); ?>" id="rnrd_custom_prompt"
									  rows="4" class="large-text"
									  placeholder="<?php esc_attr_e( 'Leave empty to use the default optimized prompt.', 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( (string) get_option( RNRD_OPT_CUSTOM_PROMPT, '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. Extra instructions appended to the AI prompt.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<?php $rnrd_autogen_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro(); ?>
					<tr<?php echo $rnrd_autogen_pro ? '' : ' class="rnrd-row-locked"'; ?>>
						<th scope="row"><?php esc_html_e( 'Auto-Generate on Publish', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php if ( $rnrd_autogen_pro ) : ?>
								<label class="rnrd-toggle">
									<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_AUTO_GENERATE ); ?>" value="off" />
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_AUTO_GENERATE ); ?>" value="on" <?php checked( get_option( RNRD_OPT_AUTO_GENERATE, 'off' ), 'on' ); ?> />
									<span class="rnrd-toggle-label"><?php esc_html_e( 'Automatically generate Key Takeaways when a post is published or updated', 'rankready-ai-llm-seo' ); ?></span>
								</label>
							<?php else : ?>
								<label style="display:inline-flex;align-items:center;gap:8px;color:#1d2327;">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_AUTO_GENERATE ); ?>" value="<?php echo esc_attr( get_option( RNRD_OPT_AUTO_GENERATE, 'off' ) ); ?>" />
									<?php echo wp_kses_post( self::locked_checkbox_indicator() ); ?>
									<?php esc_html_e( 'Automatically generate Key Takeaways when a post is published or updated', 'rankready-ai-llm-seo' ); ?>
									<span class="rnrd-soon-tag"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span>
								</label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Off by default. When off, summaries are only generated via the Regenerate button, Gutenberg block, or Bulk Generate.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Display', 'rankready-ai-llm-seo' ); ?></h3>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable AI Summary', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $summary_enable = (string) get_option( RNRD_OPT_SUMMARY_ENABLE, 'on' ); ?>
							<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_SUMMARY_ENABLE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_SUMMARY_ENABLE ); ?>" value="on" <?php checked( $summary_enable, 'on' ); ?> data-toggle-target="rnrd-summary-auto-display" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Show summaries on the frontend (single post/page views)', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Markdown and OKF always include generated summaries before the body.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr id="rnrd-summary-auto-display" <?php echo 'on' !== $summary_enable ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><?php esc_html_e( 'Auto-display', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php
							self::render_auto_display_radios(
								RNRD_OPT_AUTO_DISPLAY,
								class_exists( 'RNRD_Summary' ) ? RNRD_Summary::get_auto_display() : 'off'
							);
							?>
							<p class="description"><?php esc_html_e( 'Before/after content will be skipped if a Gutenberg block, Elementor widget, or [rankready_summary] shortcode is already in the post.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Heading Tag', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $current_tag = (string) get_option( RNRD_OPT_HEADING_TAG, 'h4' ); ?>
							<select name="<?php echo esc_attr( RNRD_OPT_HEADING_TAG ); ?>">
								<?php foreach ( array( 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6', 'p' => 'P' ) as $tag => $label ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $current_tag, $tag ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show Label', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_SHOW_LABEL ); ?>" value="1"
									   <?php checked( get_option( RNRD_OPT_SHOW_LABEL, '1' ), '1' ); ?> />
								<?php esc_html_e( 'Show the label heading above the summary bullets', 'rankready-ai-llm-seo' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Label Text', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="text" name="<?php echo esc_attr( RNRD_OPT_LABEL ); ?>"
								   value="<?php echo esc_attr( (string) get_option( RNRD_OPT_LABEL, __( 'Key Takeaways', 'rankready-ai-llm-seo' ) ) ); ?>"
								   class="regular-text" />
							<p class="description"><?php esc_html_e( 'e.g. "Key Takeaways", "Article Summary", "TL;DR"', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save AI Summary Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_summary', false ); ?>
			</div>

		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Schema Automation
	// ═══════════════════════════════════════════════════════════════════════════

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Author Box
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_author(): void {
		$enable        = (string) get_option( RNRD_OPT_AUTHOR_ENABLE, 'on' );
		$auto_display  = (string) get_option( RNRD_OPT_AUTHOR_AUTO_DISPLAY, 'off' );
		$layout        = (string) get_option( RNRD_OPT_AUTHOR_LAYOUT, 'card' );
		$heading       = (string) get_option( RNRD_OPT_AUTHOR_HEADING, 'About the Author' );
		$heading_tag   = (string) get_option( RNRD_OPT_AUTHOR_HEADING_TAG, 'h3' );
		$schema_enable = (string) get_option( RNRD_OPT_AUTHOR_SCHEMA_ENABLE, 'on' );
		$editorial     = (string) get_option( RNRD_OPT_AUTHOR_EDITORIAL_URL, '' );
		$factcheck     = (string) get_option( RNRD_OPT_AUTHOR_FACTCHECK_URL, '' );
		$trust_enable  = (string) get_option( RNRD_OPT_AUTHOR_TRUST_ENABLE, 'off' );

		$has_rankmath = defined( 'RANK_MATH_VERSION' );
		$has_yoast    = defined( 'WPSEO_VERSION' );
		$has_aioseo   = defined( 'AIOSEO_VERSION' );
		$seo_plugin   = $has_rankmath ? 'Rank Math' : ( $has_yoast ? 'Yoast SEO' : ( $has_aioseo ? 'AIOSEO' : '' ) );
		?>
			<!-- Merged in rc.6: single "Author Box (E-E-A-T)" card with 4 H3 subsections.
			     Intro card prose → .rnrd-card-desc. All option keys preserved verbatim. -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Author Box (E-E-A-T)', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Show the real people behind your content — the trust check ChatGPT, Claude, and Perplexity run before citing you.', 'rankready-ai-llm-seo' ); ?></p>
				<?php if ( $seo_plugin ) : ?>
					<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin-top:12px;">
						<strong><?php echo esc_html( $seo_plugin ); ?></strong> <?php esc_html_e( 'is active. RankReady will not emit a duplicate Person node. Instead, it enhances the existing Person schema in', 'rankready-ai-llm-seo' ); ?> <?php echo esc_html( $seo_plugin ); ?> <?php esc_html_e( 'with RankReady data via the plugin\'s filter hooks. Zero conflict.', 'rankready-ai-llm-seo' ); ?>
					</div>
				<?php endif; ?>

				<?php
				self::render_optional_post_types_section(
					RNRD_OPT_AUTHOR_POST_TYPES,
					__( 'Uncheck all to disable Author Box feature. Profile data is kept.', 'rankready-ai-llm-seo' )
				);
				?>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Display', 'rankready-ai-llm-seo' ); ?></h3>
				<table class="form-table rnrd-form-table">
					<tr>
						<th><?php esc_html_e( 'Enable Author Box', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_AUTHOR_ENABLE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_AUTHOR_ENABLE ); ?>" value="on" <?php checked( $enable, 'on' ); ?> data-toggle-target="rnrd-author-auto-display" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Show the author box on the frontend (single post/page views)', 'rankready-ai-llm-seo' ); ?></span>
							</label>
						</td>
					</tr>
					<tr id="rnrd-author-auto-display" <?php echo 'on' !== $enable ? 'style="display:none;"' : ''; ?>>
						<th><?php esc_html_e( 'Auto-display', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php self::render_auto_display_radios( RNRD_OPT_AUTHOR_AUTO_DISPLAY, $auto_display, true ); ?>
							<p class="description"><?php esc_html_e( 'Before/after content will be skipped if a Gutenberg block, Elementor widget, or [rankready_author] shortcode is already in the post.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rnrd_author_layout"><?php esc_html_e( 'Default Layout', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RNRD_OPT_AUTHOR_LAYOUT ); ?>" id="rnrd_author_layout">
								<option value="card"    <?php selected( $layout, 'card' ); ?>><?php esc_html_e( 'Card (full end-of-article box)', 'rankready-ai-llm-seo' ); ?></option>
								<option value="compact" <?php selected( $layout, 'compact' ); ?>><?php esc_html_e( 'Compact (small, sidebar-friendly)', 'rankready-ai-llm-seo' ); ?></option>
								<option value="inline"  <?php selected( $layout, 'inline' ); ?>><?php esc_html_e( 'Inline byline (headline-style)', 'rankready-ai-llm-seo' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Default layout for auto-display and new blocks, widgets, and shortcodes. Individual blocks/widgets can override this.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rnrd_author_heading"><?php esc_html_e( 'Default Heading', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="text" name="<?php echo esc_attr( RNRD_OPT_AUTHOR_HEADING ); ?>" id="rnrd_author_heading" value="<?php echo esc_attr( $heading ); ?>" class="regular-text" />
							<select name="<?php echo esc_attr( RNRD_OPT_AUTHOR_HEADING_TAG ); ?>">
								<?php foreach ( array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ) as $tag ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $heading_tag, $tag ); ?>><?php echo esc_html( strtoupper( $tag ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Heading text shown above the box in Card and Compact layouts. Individual blocks/widgets can override.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<?php
				// EEAT schema controls (Person schema, Editorial / Fact-Check URLs,
				// Author Trust Panel) are Pro — surfaced here as Coming Soon. The
				// functional code lives in the separate Pro plugin.
				?>
				<?php // Pro renders the real EEAT schema controls via this hook; Free shows Coming Soon. ?>
				<?php if ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() && has_action( 'rnrd_pro_eeat_schema' ) ) : ?>
					<?php do_action( 'rnrd_pro_eeat_schema' ); ?>
				<?php else : ?>
				<div class="rnrd-card__soon-block">
					<?php
						self::render_pro_gate(
							__( 'Person Schema (EEAT)', 'rankready-ai-llm-seo' ),
							__( 'Emit Person JSON-LD with sameAs, knowsAbout, credentials, memberOf, and awards — the schema fields AI systems use to verify authorship and increase citation probability.', 'rankready-ai-llm-seo' )
						);
						self::render_pro_gate(
							__( 'Editorial & Fact-Check Policy URLs', 'rankready-ai-llm-seo' ),
							__( 'Link your editorial standards and fact-check policy pages into the schema graph. The Healthline / WebMD EEAT pattern — signals editorial integrity to Google and LLMs.', 'rankready-ai-llm-seo' )
						);
						self::render_pro_gate(
							__( 'Author Trust Panel (Reviewed By)', 'rankready-ai-llm-seo' ),
							__( 'Add "Fact-checked by" and "Reviewed by" fields to every post editor. Emits as Article.reviewedBy[] and Article.lastReviewed — the full medical/legal EEAT pattern.', 'rankready-ai-llm-seo' )
						);
					?>
				</div>
				<?php endif; ?>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'How to use', 'rankready-ai-llm-seo' ); ?></h3>
				<ol style="margin-left:18px;">
					<li><?php esc_html_e( 'Go to Users → your profile and fill in the "RankReady Author Box" section — Bio, headshot, job title, and year started are free.', 'rankready-ai-llm-seo' ); ?></li>
					<li><?php
						printf(
							wp_kses(
								/* translators: %s: HTML code element containing the [rankready_author] shortcode */
								__( 'Add the RankReady Author Box Gutenberg block, the Elementor widget, the %s shortcode, or enable auto-display above.', 'rankready-ai-llm-seo' ),
								array( 'code' => array() )
							),
							'<code>' . esc_html( RNRD_Shortcode::tag( RNRD_Shortcode::AUTHOR ) ) . '</code>'
						);
					?></li>
				</ol>
				<?php submit_button( __( 'Save Author Box Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_author', false ); ?>
			</div>

		<?php
	}

	private static function render_tab_schema(): void {
		$article   = (string) get_option( RNRD_OPT_SCHEMA_ARTICLE, 'on' );
		$faq       = (string) get_option( RNRD_OPT_SCHEMA_FAQ, 'on' );
		$howto     = (string) get_option( RNRD_OPT_SCHEMA_HOWTO, 'on' );
		$itemlist  = (string) get_option( RNRD_OPT_SCHEMA_ITEMLIST, 'on' );
		$speakable = (string) get_option( RNRD_OPT_SCHEMA_SPEAKABLE, 'on' );

		// Detect active SEO plugins.
		// Detect SEO plugin (expanded list per rc.7 — also covers SEOPress + TSF).
		$has_rankmath  = defined( 'RANK_MATH_VERSION' );
		$has_yoast     = defined( 'WPSEO_VERSION' );
		$has_aioseo    = defined( 'AIOSEO_VERSION' );
		$has_seopress  = ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) );
		$has_tsf       = defined( 'THE_SEO_FRAMEWORK_VERSION' );
		$seo_plugin    = '';
		if ( $has_rankmath )     $seo_plugin = 'Rank Math';
		elseif ( $has_yoast )    $seo_plugin = 'Yoast SEO';
		elseif ( $has_aioseo )   $seo_plugin = 'All in One SEO';
		elseif ( $has_seopress ) $seo_plugin = 'SEOPress';
		elseif ( $has_tsf )      $seo_plugin = 'The SEO Framework';
		?>
			<!-- Schema Toggles -->
			<?php
			// v1.2.0-rc.7 — "All-off" master gate: if every schema toggle is OFF
			// the card body becomes a locked preview prompting the user to
			// enable at least Article. Otherwise the full schema-types table
			// renders unchanged.
			$rnrd_any_schema_on = ( 'on' === $article )
				|| ( 'on' === $faq )
				|| ( 'on' === $howto )
				|| ( 'on' === $itemlist )
				|| ( 'on' === $speakable );
			?>
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Schema Types', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Auto-emit Article + Speakable + HowTo + ItemList JSON-LD. No manual schema work.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">

					<!-- Article + Speakable -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Article Schema', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_SCHEMA_ARTICLE ); ?>"
									   value="on" <?php checked( $article, 'on' ); ?>
									   <?php echo ! empty( $seo_plugin ) ? 'disabled' : ''; ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Article/BlogPosting JSON-LD with author, publisher, dateModified', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<?php if ( ! empty( $seo_plugin ) ) : ?>
								<p class="description" style="margin-top:4px;color:#666;">
									<?php
									/* translators: %s: detected SEO plugin name */
									echo esc_html( sprintf( __( 'Disabled — %s handles Article schema.', 'rankready-ai-llm-seo' ), $seo_plugin ) );
									?>
								</p>
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_SCHEMA_ARTICLE ); ?>" value="<?php echo esc_attr( $article ); ?>" />
							<?php else : ?>
								<p class="description" style="margin-top:4px;">
									<?php esc_html_e( 'Injects Article JSON-LD on all published posts/pages. Includes headline, author, publisher, image, description, about (categories), mentions (tags).', 'rankready-ai-llm-seo' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<!-- Speakable -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Speakable', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_SCHEMA_SPEAKABLE ); ?>"
									   value="on" <?php checked( $speakable, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Add speakable markup for voice search and AI assistants', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'Marks the title and excerpt as speakable content. Helps Google Assistant, Alexa, and AI voice queries read your content aloud. Works with both RankReady and SEO plugin Article schema.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>

					<!-- FAQPage -->
					<tr>
						<th scope="row"><?php esc_html_e( 'FAQPage Schema', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_SCHEMA_FAQ ); ?>"
									   value="on" <?php checked( $faq, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Inject FAQPage JSON-LD when FAQ data exists', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'When RankReady FAQ data exists for a post, FAQPage schema is injected automatically. Pages with FAQPage schema are 3.2x more likely to appear in AI Overviews.', 'rankready-ai-llm-seo' ); ?>
							</p>
							<?php if ( ! empty( $seo_plugin ) ) : ?>
								<p class="description" style="color:#666;">
									<?php
									/* translators: %s: detected SEO plugin name */
									echo esc_html( sprintf( __( 'Auto-skips when a %s FAQ block is present in the post content to prevent duplicates.', 'rankready-ai-llm-seo' ), $seo_plugin ) );
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<?php
					// HowTo + ItemList schema are Pro — surfaced as Coming Soon below.
					// Their functional generators live in the separate Pro plugin.
					?>
				</table>

				<?php // Pro renders the real HowTo + ItemList toggles via this hook; Free shows Coming Soon. ?>
				<?php if ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() && has_action( 'rnrd_pro_schema_rows' ) ) : ?>
					<?php do_action( 'rnrd_pro_schema_rows', $howto, $itemlist, $seo_plugin ); ?>
				<?php else : ?>
				<div class="rnrd-card__soon-block">
					<?php self::render_pro_gate(
						__( 'HowTo Schema', 'rankready-ai-llm-seo' ),
						__( 'Auto-detect step-by-step posts and inject HowTo JSON-LD. Triggered by "How to", "Tutorial", "Step by Step", or "Guide to" in the title — steps extracted from your existing headings.', 'rankready-ai-llm-seo' )
					); ?>
					<?php self::render_pro_gate(
						__( 'ItemList Schema', 'rankready-ai-llm-seo' ),
						__( 'Auto-detect "Best N / Top N" listicle posts and inject ItemList JSON-LD. No SEO plugin does this automatically — it\'s what makes your recommendation posts AI-readable.', 'rankready-ai-llm-seo' )
					); ?>
				</div>
				<?php endif; ?>

				<?php submit_button( __( 'Save Schema Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_schema', false ); ?>
			</div>

			<!-- SEO Plugin Detection -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'SEO Plugin Compatibility', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'RankReady detects your active SEO plugin and merges schema — never duplicate tags.', 'rankready-ai-llm-seo' ); ?></p>

				<?php if ( ! empty( $seo_plugin ) ) : ?>
					<div class="rnrd-callout rnrd-callout--info">
						<strong><?php echo esc_html( $seo_plugin ); ?></strong> <?php esc_html_e( 'is active.', 'rankready-ai-llm-seo' ); ?>
						<?php esc_html_e( 'RankReady automatically adjusts schema output to avoid duplicates:', 'rankready-ai-llm-seo' ); ?>
						<ul class="rnrd-callout__list">
							<li><?php esc_html_e( 'Article schema — Handled by', 'rankready-ai-llm-seo' ); ?> <?php echo esc_html( $seo_plugin ); ?>. <?php esc_html_e( 'RankReady skips it automatically.', 'rankready-ai-llm-seo' ); ?></li>
							<li><?php esc_html_e( 'FAQPage schema — RankReady injects only when no', 'rankready-ai-llm-seo' ); ?> <?php echo esc_html( $seo_plugin ); ?> <?php esc_html_e( 'FAQ block exists in the post.', 'rankready-ai-llm-seo' ); ?></li>
							<li><?php esc_html_e( 'HowTo schema — RankReady injects only when no', 'rankready-ai-llm-seo' ); ?> <?php echo esc_html( $seo_plugin ); ?> <?php esc_html_e( 'HowTo block exists in the post.', 'rankready-ai-llm-seo' ); ?></li>
							<li><?php esc_html_e( 'ItemList schema — Always handled by RankReady (no SEO plugin does this).', 'rankready-ai-llm-seo' ); ?></li>
						</ul>
					</div>
				<?php else : ?>
					<p class="rnrd-info-callout"><span class="dashicons dashicons-info"></span>
						<?php esc_html_e( 'No SEO plugin detected — RankReady emits Article, Speakable, FAQ, HowTo, and ItemList schema.', 'rankready-ai-llm-seo' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<?php
			// SEO Plugin Compatibility sits below Schema Types so toggles stay
			// primary; compatibility is supporting context.
			?>

		<?php
	}


	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: AI Visibility (slug crawlers) — Brand / Robots / LLMs / Markdown / WebMCP / OKF
	// ═══════════════════════════════════════════════════════════════════════════

	/**
	 * AI Visibility tab router — subtabs for machine-readable AI surfaces.
	 */
	private static function render_tab_llm(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only $_GET[sub] for sub-tab display routing.
		$sub = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : 'brand';
		$sub_tabs = array(
			'brand'    => __( 'Brand Identity', 'rankready-ai-llm-seo' ),
			'robots'   => __( 'Robots.txt', 'rankready-ai-llm-seo' ),
			'llms'     => __( 'LLMs.txt', 'rankready-ai-llm-seo' ),
			'markdown' => __( 'Markdown', 'rankready-ai-llm-seo' ),
			'webmcp'   => __( 'WebMCP', 'rankready-ai-llm-seo' ),
			'okf'      => __( 'OKF', 'rankready-ai-llm-seo' ),
		);
		if ( ! isset( $sub_tabs[ $sub ] ) ) {
			$sub = 'brand';
		}
		?>
		<?php settings_errors(); ?>
		<?php self::render_tab_intro(
			__( 'AI Visibility', 'rankready-ai-llm-seo' ),
			__( 'Make your site readable to ChatGPT, Claude, Perplexity, and Gemini via Brand, robots, llms.txt, Markdown, WebMCP, and OKF.', 'rankready-ai-llm-seo' )
		); ?>

		<div class="rnrd-insights-toolbar">
			<nav class="rnrd-insights-subnav" aria-label="<?php esc_attr_e( 'AI Visibility sections', 'rankready-ai-llm-seo' ); ?>">
				<?php foreach ( $sub_tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'crawlers', 'sub' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
					   class="<?php echo $sub === $slug ? 'is-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<?php
		switch ( $sub ) {
			case 'robots':
				self::render_visibility_sub_robots();
				break;
			case 'llms':
				self::render_visibility_sub_llms();
				break;
			case 'markdown':
				self::render_visibility_sub_markdown();
				break;
			case 'webmcp':
				self::render_visibility_sub_webmcp();
				break;
			case 'okf':
				self::render_visibility_sub_okf();
				break;
			case 'brand':
			default:
				self::render_visibility_sub_brand();
				break;
		}
	}

	/**
	 * Emit hidden inputs preserving LLMS_GROUP options absent from the current
	 * AI Visibility subtab form. Prevents options.php from nulling shared settings.
	 *
	 * @param string $view 'robots'|'llms'|'markdown'|'webmcp'
	 */
	private static function render_llms_preserve_hiddens( string $view ): void {
		$view_opts = array(
			'robots'   => array(
				RNRD_OPT_ROBOTS_ENABLE,
				RNRD_OPT_CONTENT_SIGNALS_ENABLE,
				RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN,
				RNRD_OPT_CONTENT_SIGNALS_SEARCH,
				RNRD_OPT_CONTENT_SIGNALS_AI_INPUT,
				RNRD_OPT_MAX_SNIPPET_DEFAULT,
			),
			'llms'     => array(
				RNRD_OPT_LLMS_ENABLE,
				RNRD_OPT_LLMS_MAX_POSTS,
				RNRD_OPT_LLMS_CACHE_TTL,
				RNRD_OPT_LLMS_FULL_ENABLE,
				RNRD_OPT_LLMS_SHOW_CATEGORIES,
				RNRD_OPT_LLMS_USE_MD_URLS,
				RNRD_OPT_HIDE_BRANDING,
			),
			'markdown' => array(
				RNRD_OPT_MD_ENABLE,
				RNRD_OPT_MD_HOME_ENABLE,
				RNRD_OPT_MD_INCLUDE_META,
				RNRD_OPT_MD_HINT_DIV,
				RNRD_OPT_MD_ACCEPT_NEGOTIATION,
				RNRD_OPT_MD_BOT_AUTO_SERVE,
			),
			// Expose toggles render only when WebMCP is already on; when off, preserve-hiddens must carry them so the first "Enable" save does not null them.
			'webmcp'   => array_merge(
				array( RNRD_OPT_MCP_ENABLE ),
				'on' === (string) get_option( RNRD_OPT_MCP_ENABLE, 'off' )
					? array(
						RNRD_OPT_MCP_EXPOSE_POSTS,
						RNRD_OPT_MCP_EXPOSE_PAGES,
						RNRD_OPT_MCP_EXPOSE_AUTHORS,
						RNRD_OPT_MCP_EXPOSE_TAXONOMIES,
						RNRD_OPT_MCP_EXPOSE_SITEMAP,
						RNRD_OPT_MCP_EXPOSE_MENUS,
						RNRD_OPT_MCP_EXPOSE_LLMS_TXT,
						RNRD_OPT_MCP_EXPOSE_RR_AI,
						RNRD_OPT_MCP_EXPOSE_FRESHNESS,
					)
					: array()
			),
		);

		$scalar_defaults = array(
			RNRD_OPT_LLMS_ENABLE                 => 'off',
			RNRD_OPT_LLMS_MAX_POSTS              => 100,
			RNRD_OPT_LLMS_CACHE_TTL              => 3600,
			RNRD_OPT_LLMS_FULL_ENABLE            => 'off',
			RNRD_OPT_LLMS_SHOW_CATEGORIES        => 'on',
			RNRD_OPT_LLMS_USE_MD_URLS            => 'on',
			RNRD_OPT_HIDE_BRANDING               => 'off',
			RNRD_OPT_ROBOTS_ENABLE               => 'on',
			RNRD_OPT_MD_ENABLE                   => 'off',
			RNRD_OPT_MD_HOME_ENABLE              => 'on',
			RNRD_OPT_MD_INCLUDE_META             => '1',
			RNRD_OPT_MD_HINT_DIV                 => 'on',
			RNRD_OPT_MD_ACCEPT_NEGOTIATION       => 'on',
			RNRD_OPT_MD_BOT_AUTO_SERVE           => 'on',
			RNRD_OPT_CONTENT_SIGNALS_ENABLE      => 'off',
			RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN    => 'allow',
			RNRD_OPT_CONTENT_SIGNALS_SEARCH      => 'allow',
			RNRD_OPT_CONTENT_SIGNALS_AI_INPUT    => 'allow',
			RNRD_OPT_MAX_SNIPPET_DEFAULT         => 'on',
			RNRD_OPT_MCP_ENABLE                  => 'off',
			RNRD_OPT_MCP_EXPOSE_POSTS            => 'on',
			RNRD_OPT_MCP_EXPOSE_PAGES            => 'on',
			RNRD_OPT_MCP_EXPOSE_AUTHORS          => 'on',
			RNRD_OPT_MCP_EXPOSE_TAXONOMIES       => 'on',
			RNRD_OPT_MCP_EXPOSE_SITEMAP          => 'on',
			RNRD_OPT_MCP_EXPOSE_MENUS            => 'on',
			RNRD_OPT_MCP_EXPOSE_LLMS_TXT         => 'on',
			RNRD_OPT_MCP_EXPOSE_RR_AI            => 'on',
			RNRD_OPT_MCP_EXPOSE_FRESHNESS        => 'on',
		);

		$edited = isset( $view_opts[ $view ] ) ? $view_opts[ $view ] : array();
		foreach ( $scalar_defaults as $opt => $default ) {
			if ( in_array( $opt, $edited, true ) ) {
				continue;
			}
			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />' . "\n",
				esc_attr( $opt ),
				esc_attr( (string) get_option( $opt, $default ) )
			);
		}

		// Array options — preserve when not edited on this subtab.
		if ( 'llms' !== $view ) {
			$llms_types = (array) get_option( RNRD_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) );
			foreach ( $llms_types as $pt ) {
				printf(
					'<input type="hidden" name="%1$s[]" value="%2$s" />' . "\n",
					esc_attr( RNRD_OPT_LLMS_POST_TYPES ),
					esc_attr( (string) $pt )
				);
			}
			$exclude_cats = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_CATS, array() );
			foreach ( $exclude_cats as $cat_id ) {
				printf(
					'<input type="hidden" name="%1$s[]" value="%2$s" />' . "\n",
					esc_attr( RNRD_OPT_LLMS_EXCLUDE_CATS ),
					esc_attr( (string) $cat_id )
				);
			}
			$exclude_tags = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_TAGS, array() );
			foreach ( $exclude_tags as $tag_id ) {
				printf(
					'<input type="hidden" name="%1$s[]" value="%2$s" />' . "\n",
					esc_attr( RNRD_OPT_LLMS_EXCLUDE_TAGS ),
					esc_attr( (string) $tag_id )
				);
			}
		}

		if ( 'markdown' !== $view ) {
			$md_types = (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
			foreach ( $md_types as $pt ) {
				printf(
					'<input type="hidden" name="%1$s[]" value="%2$s" />' . "\n",
					esc_attr( RNRD_OPT_MD_POST_TYPES ),
					esc_attr( (string) $pt )
				);
			}
		}

		if ( 'webmcp' !== $view ) {
			$mcp_cpts = (array) get_option( RNRD_OPT_MCP_EXPOSE_CPTS, array() );
			if ( empty( $mcp_cpts ) ) {
				printf(
					'<input type="hidden" name="%1$s[]" value="" />' . "\n",
					esc_attr( RNRD_OPT_MCP_EXPOSE_CPTS )
				);
			} else {
				foreach ( $mcp_cpts as $cpt ) {
					printf(
						'<input type="hidden" name="%1$s[]" value="%2$s" />' . "\n",
						esc_attr( RNRD_OPT_MCP_EXPOSE_CPTS ),
						esc_attr( (string) $cpt )
					);
				}
			}
		}

		// robots_mode assoc array — preserve when not on robots subtab.
		if ( 'robots' !== $view ) {
			$rnrd_mode = self::get_robots_mode();
			foreach ( $rnrd_mode as $ua => $state ) {
				printf(
					'<input type="hidden" name="%1$s[%2$s]" value="%3$s" />' . "\n",
					esc_attr( RNRD_OPT_ROBOTS_MODE ),
					esc_attr( (string) $ua ),
					esc_attr( (string) $state )
				);
			}
		}
	}



	/** AI Visibility → Brand Identity. */
	private static function render_visibility_sub_brand(): void {
		?>
		<!-- ── Brand Identity (v1.2.0-beta.4 — unified) ───────────────────── -->
		<?php
		$rnrd_brand           = RNRD_Brand_Identity::get_brand_identity();
		$rnrd_brand_name      = (string) get_option( RNRD_OPT_LLMS_SITE_NAME, '' );  // raw value, not the fallback
		$rnrd_brand_summary   = (string) get_option( RNRD_OPT_LLMS_SUMMARY, '' );
		$rnrd_brand_about     = (string) get_option( RNRD_OPT_LLMS_ABOUT, '' );
		$rnrd_brand_terms_raw = (string) get_option( RNRD_OPT_BRAND_TERMS, '' );
		$rnrd_brand_complete  = ( '' !== trim( $rnrd_brand_name ) || '' !== trim( $rnrd_brand_summary ) )
			&& '' !== trim( $rnrd_brand_terms_raw );
		?>
		<form method="post" action="options.php" novalidate="novalidate" class="rnrd-brand-form">
			<?php settings_fields( self::BRAND_GROUP ); /* v1.2.0-rc.3 — isolated group prevents LLMS settings from being null'd on save. */ ?>

			<div class="rnrd-card">
				<h2 class="rnrd-card-title">
					<?php esc_html_e( 'Brand Identity', 'rankready-ai-llm-seo' ); ?>
					<span class="rnrd-badge rnrd-badge--neutral"><?php esc_html_e( 'Single source of truth', 'rankready-ai-llm-seo' ); ?></span>
					<?php if ( $rnrd_brand_complete ) : ?>
						<span class="rnrd-badge rnrd-badge--ok"><?php esc_html_e( 'Complete', 'rankready-ai-llm-seo' ); ?></span>
					<?php else : ?>
						<span class="rnrd-badge rnrd-badge--warn"><?php esc_html_e( 'Incomplete', 'rankready-ai-llm-seo' ); ?></span>
					<?php endif; ?>
				</h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Tell ChatGPT, Claude, Perplexity, and Gemini who you are once — powers llms.txt, prompts, MCP, and homepage Markdown.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_llms_site_name"><?php esc_html_e( 'Site / brand name', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="text" id="rnrd_llms_site_name" name="<?php echo esc_attr( RNRD_OPT_LLMS_SITE_NAME ); ?>"
								   value="<?php echo esc_attr( $rnrd_brand_name ); ?>"
								   class="regular-text"
								   placeholder="<?php esc_attr_e( 'Your Brand Name', 'rankready-ai-llm-seo' ); ?>" />
							<p class="description"><?php esc_html_e( 'Canonical name. Used as the H1 in llms.txt and in the Brand line everywhere. Falls back to your WordPress site title when empty.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="rnrd_llms_summary"><?php esc_html_e( 'One-line summary', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<textarea id="rnrd_llms_summary" name="<?php echo esc_attr( RNRD_OPT_LLMS_SUMMARY ); ?>"
									  rows="2" class="large-text"
									  maxlength="160"
									  placeholder="<?php esc_attr_e( 'The elevator pitch an AI engine quotes in 1 sentence. Max 160 chars.', 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( $rnrd_brand_summary ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Renders as the blockquote under the H1 in llms.txt + llms-full.txt. Keep it tight — this is the line AI engines repeat.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="rnrd_llms_about"><?php esc_html_e( 'About', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<textarea id="rnrd_llms_about" name="<?php echo esc_attr( RNRD_OPT_LLMS_ABOUT ); ?>"
									  rows="4" class="large-text"
									  maxlength="500"
									  placeholder="<?php esc_attr_e( 'Longer description (≤ 500 chars). What is this site about? Who is it for? Markdown supported.', 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( $rnrd_brand_about ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Detailed context. Appears below the summary in llms.txt and llms-full.txt. Used by AI engines for deeper site comprehension.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="rnrd_brand_terms"><?php esc_html_e( 'Canonical brand terms', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<textarea id="rnrd_brand_terms"
									  name="<?php echo esc_attr( RNRD_OPT_BRAND_TERMS ); ?>"
									  rows="4"
									  class="large-text"
									  placeholder="<?php esc_attr_e( "One name per line. Examples:\nYour Brand Name\nYour Product Name\nA Common Misspelling To Catch", 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( $rnrd_brand_terms_raw ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Use the exact capitalisation and spacing you want AI engines to use. One canonical name per line — any variants you list get unified into a single recognised entity.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<div style="margin-top:14px;padding:12px 14px;background:var(--rnrd-color-surface-2,#f6f7f7);border-radius:var(--rnrd-radius-md,6px);font-size:12px;line-height:1.7;color:var(--rnrd-color-ink-soft,#3c434a);">
					<strong style="display:block;margin-bottom:6px;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;color:var(--rnrd-color-text-muted,#646970;">
						<?php esc_html_e( 'Where these 4 fields are used', 'rankready-ai-llm-seo' ); ?>
					</strong>
					<ul style="margin:0;padding-left:18px;list-style:disc;">
						<li><code>/llms.txt</code> &mdash; <?php esc_html_e( 'H1 (name), blockquote (summary), about paragraph, Brand line (terms)', 'rankready-ai-llm-seo' ); ?></li>
						<li><code>/llms-full.txt</code> &mdash; <?php esc_html_e( 'same header block + every page below', 'rankready-ai-llm-seo' ); ?></li>
						<li><code>/robots.txt</code> &mdash; <?php esc_html_e( 'Brand comment inside RankReady AI block', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'AI Summary system prompt', 'rankready-ai-llm-seo' ); ?> &mdash; <?php esc_html_e( 'canonical naming forced into every summary', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'FAQ generation prompt', 'rankready-ai-llm-seo' ); ?> &mdash; <?php esc_html_e( 'brand context, deduped against per-FAQ legacy field', 'rankready-ai-llm-seo' ); ?></li>
						<li><code>rankready/get-site-info</code> &mdash; <?php esc_html_e( 'WebMCP ability returns name + brand_terms to Claude Desktop / Cursor / VS Code', 'rankready-ai-llm-seo' ); ?></li>
						<li><code>rankready/get-brand-terms</code> &mdash; <?php esc_html_e( 'WebMCP ability returns terms array directly', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'Homepage Markdown (Accept: text/markdown)', 'rankready-ai-llm-seo' ); ?> &mdash; <?php esc_html_e( 'site overview uses name + summary', 'rankready-ai-llm-seo' ); ?></li>
					</ul>
				</div>

				<?php submit_button( __( 'Save Brand Identity', 'rankready-ai-llm-seo' ) ); ?>
			</div>
		</form>

		<?php
	}

	/** AI Visibility → LLM Crawler Access (robots.txt) + Content Signals. */
	private static function render_visibility_sub_robots(): void {
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::LLMS_GROUP ); ?>
			<?php self::render_llms_preserve_hiddens( 'robots' ); ?>
			<!-- LLM Crawler Access (robots.txt) -->
			<?php $robots_enable = (string) get_option( RNRD_OPT_ROBOTS_ENABLE, 'on' ); ?>
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'LLM Crawler Access (robots.txt)', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Allow or block 29 named AI crawlers. Auto-syncs to your physical /robots.txt.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Crawler Rules', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_ROBOTS_ENABLE ); ?>"
									   value="on" <?php checked( $robots_enable, 'on' ); ?>
									   data-toggle-target="rnrd-robots-fields" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Append LLM crawler rules to robots.txt', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Adds per-crawler User-agent blocks with Allow or Disallow directives. Safe — appends only, never touches existing rules.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<div id="rnrd-robots-fields" class="rnrd-conditional-fields" <?php echo 'on' !== $robots_enable ? 'style="display:none;"' : ''; ?>>
					<table class="form-table rnrd-form-table">
																		<tr>
							<th scope="row"><?php esc_html_e( 'AI Crawlers', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php
								$rnrd_mode      = self::get_robots_mode();
								$all_crawlers   = self::get_llm_crawlers();
								$current_company = '';
								$rnrd_states    = array(
									'allow'   => __( 'Allow', 'rankready-ai-llm-seo' ),
									'default' => __( 'Default', 'rankready-ai-llm-seo' ),
									'block'   => __( 'Block', 'rankready-ai-llm-seo' ),
								);
								?>
								<fieldset style="max-height:460px;overflow-y:auto;border:1px solid #ddd;padding:12px 16px;border-radius:4px;">
									<div style="display:flex;align-items:center;gap:6px;margin:0 0 6px;">
										<?php foreach ( $rnrd_states as $rnrd_key => $rnrd_label ) : ?>
											<span style="flex:0 0 62px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#1d2327;"><?php echo esc_html( $rnrd_label ); ?></span>
										<?php endforeach; ?>
										<span style="margin-left:8px;font-size:12px;color:#666;">
											<?php esc_html_e( 'Set every crawler to:', 'rankready-ai-llm-seo' ); ?>
											<?php foreach ( $rnrd_states as $rnrd_key => $rnrd_label ) : ?>
												<button type="button" class="button-link rnrd-crawler-setall" data-rnrd-state="<?php echo esc_attr( $rnrd_key ); ?>" style="margin-left:6px;"><?php echo esc_html( $rnrd_label ); ?></button>
											<?php endforeach; ?>
										</span>
									</div>
									<hr style="margin:8px 0;" />
									<?php foreach ( $all_crawlers as $ua => $info ) : ?>
										<?php if ( $info[0] !== $current_company ) :
											$current_company = $info[0];
											if ( 'OpenAI' !== $current_company ) : ?>
												<hr style="margin:8px 0;border:none;border-top:1px solid #eee;" />
											<?php endif; ?>
											<p style="margin:4px 0 2px;font-weight:600;color:#1d2327;font-size:13px;"><?php echo esc_html( $current_company ); ?></p>
										<?php endif; ?>
										<?php $rnrd_state = isset( $rnrd_mode[ $ua ] ) ? $rnrd_mode[ $ua ] : 'default'; ?>
										<div style="display:flex;align-items:center;gap:6px;margin:0 0 4px;">
											<?php foreach ( $rnrd_states as $rnrd_key => $rnrd_label ) : ?>
												<span style="flex:0 0 62px;text-align:center;">
													<input type="radio"
														   name="<?php echo esc_attr( RNRD_OPT_ROBOTS_MODE ); ?>[<?php echo esc_attr( $ua ); ?>]"
														   value="<?php echo esc_attr( $rnrd_key ); ?>"
														   class="rnrd-crawler-state"
														   style="margin:0;"
														   <?php /* translators: 1: state name, 2: crawler user-agent */ ?>
														   aria-label="<?php echo esc_attr( sprintf( __( '%1$s %2$s', 'rankready-ai-llm-seo' ), $rnrd_label, $ua ) ); ?>"
														   <?php checked( $rnrd_state, $rnrd_key ); ?> />
												</span>
											<?php endforeach; ?>
											<code style="font-size:12px;"><?php echo esc_html( $ua ); ?></code>
											<span style="color:#666;font-size:12px;">— <?php echo esc_html( $info[1] ); ?></span>
										</div>
									<?php endforeach; ?>
								</fieldset>
								<p class="description" style="margin-top:8px;">
									<strong><?php esc_html_e( 'Allow', 'rankready-ai-llm-seo' ); ?></strong> — <?php esc_html_e( 'writes "User-agent: X" with "Allow: /", inviting that crawler explicitly.', 'rankready-ai-llm-seo' ); ?><br />
									<strong><?php esc_html_e( 'Default', 'rankready-ai-llm-seo' ); ?></strong> — <?php esc_html_e( 'RankReady writes nothing for that crawler, so it follows the rules your site already has. This is not a block.', 'rankready-ai-llm-seo' ); ?><br />
									<strong><?php esc_html_e( 'Block', 'rankready-ai-llm-seo' ); ?></strong> — <?php esc_html_e( 'writes "Disallow: /", telling it to stop crawling. Use it for bots overwhelming your server.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
				<?php submit_button( __( 'Save Robots Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_robots', false ); ?>
			</div>
			<!-- Content Signals -->
			<?php $signals_enable = (string) get_option( RNRD_OPT_CONTENT_SIGNALS_ENABLE, 'off' ); ?>
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Content Signals', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Tell AI engines what your content may be used for (ai-train / search / ai-input).', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Content Signals', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_CONTENT_SIGNALS_ENABLE ); ?>"
									   value="on" <?php checked( $signals_enable, 'on' ); ?>
									   data-toggle-target="rnrd-content-signals-fields" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Add Content Signals directives to robots.txt', 'rankready-ai-llm-seo' ); ?></span>
							</label>
						</td>
					</tr>
				</table>

				<div id="rnrd-content-signals-fields" class="rnrd-conditional-fields" <?php echo 'on' !== $signals_enable ? 'style="display:none;"' : ''; ?>>
					<table class="form-table rnrd-form-table">
						<?php
						$signal_options = array(
							RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN => array(
								'label' => __( 'ai-train', 'rankready-ai-llm-seo' ),
								'desc'  => __( 'May AI systems use this content to train models?', 'rankready-ai-llm-seo' ),
							),
							RNRD_OPT_CONTENT_SIGNALS_SEARCH   => array(
								'label' => __( 'search', 'rankready-ai-llm-seo' ),
								'desc'  => __( 'May AI systems use this content in search results?', 'rankready-ai-llm-seo' ),
							),
							RNRD_OPT_CONTENT_SIGNALS_AI_INPUT => array(
								'label' => __( 'ai-input', 'rankready-ai-llm-seo' ),
								'desc'  => __( 'May AI systems use this content as RAG/context input?', 'rankready-ai-llm-seo' ),
							),
						);
						foreach ( $signal_options as $opt_key => $info ) :
							$val = (string) get_option( $opt_key, 'allow' );
							?>
							<tr>
								<th scope="row"><code><?php echo esc_html( $info['label'] ); ?></code></th>
								<td>
									<select name="<?php echo esc_attr( $opt_key ); ?>">
										<option value="allow" <?php selected( $val, 'allow' ); ?>><?php esc_html_e( 'allow', 'rankready-ai-llm-seo' ); ?></option>
										<option value="deny"  <?php selected( $val, 'deny' ); ?>><?php esc_html_e( 'deny', 'rankready-ai-llm-seo' ); ?></option>
									</select>
									<p class="description"><?php echo esc_html( $info['desc'] ); ?></p>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>
				<?php submit_button( __( 'Save Content Signals', 'rankready-ai-llm-seo' ), 'primary', 'submit_signals', false ); ?>
			</div>
			<!-- AI Snippet (site-wide default; per-post override is the Visibility metabox) -->
			<?php $snippet_default = (string) get_option( RNRD_OPT_MAX_SNIPPET_DEFAULT, 'on' ); ?>
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Snippet', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Allow AI engines to quote the full passage (max-snippet:-1) instead of the ~160-character cap.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Default for new posts', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MAX_SNIPPET_DEFAULT ); ?>"
									   value="on" <?php checked( $snippet_default, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Allow full snippet (max-snippet:-1)', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description">
								<?php esc_html_e( 'When on, RankReady adds max-snippet:-1, max-image-preview:large, and max-video-preview:-1 to the page robots meta. Posts marked noindex are skipped. Each post can still override this under RankReady: AI Visibility (Use default / Allow full snippet / Standard snippet only).', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save AI Snippet', 'rankready-ai-llm-seo' ), 'primary', 'submit_snippet', false ); ?>
			</div>

		</form>
		<?php
	}

	/** AI Visibility → LLMs.txt Generator. */
	private static function render_visibility_sub_llms(): void {
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::LLMS_GROUP ); ?>
			<?php self::render_llms_preserve_hiddens( 'llms' ); ?>
			<!-- LLMs.txt -->
			<?php $llms_enable = (string) get_option( RNRD_OPT_LLMS_ENABLE, 'off' ); ?>
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'LLMs.txt Generator', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Serve the llmstxt.org site index at /llms.txt and /llms-full.txt for AI engines.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable LLMs.txt', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_ENABLE ); ?>"
									   value="on" <?php checked( $llms_enable, 'on' ); ?>
									   data-toggle-target="rnrd-llms-fields" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Serve /llms.txt on your site', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<?php if ( 'on' === $llms_enable ) : ?>
								<p class="description" style="margin-top:8px;">
									<?php esc_html_e( 'Live at:', 'rankready-ai-llm-seo' ); ?>
									<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank"><code><?php echo esc_html( home_url( '/llms.txt' ) ); ?></code></a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<div id="rnrd-llms-fields" class="rnrd-conditional-fields" <?php echo 'on' !== $llms_enable ? 'style="display:none;"' : ''; ?>>
					<table class="form-table rnrd-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Include Post Types', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php $llms_types = (array) get_option( RNRD_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) ); ?>
								<fieldset data-rnrd-min-one-checkboxes>
								<?php foreach ( self::get_allowed_post_types() as $slug => $label ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_POST_TYPES ); ?>[]"
											   value="<?php echo esc_attr( $slug ); ?>"
											   <?php checked( in_array( $slug, $llms_types, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								</fieldset>
								<?php if ( ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) ) : ?><p class="rnrd-cpt-hint"><?php esc_html_e( 'Want to include Custom Post Types?', 'rankready-ai-llm-seo' ); ?> <span class="rnrd-soon-tag"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span></p><?php endif; ?>
								<p class="description"><?php esc_html_e( 'Which post types to list in llms.txt as file lists. At least one post type is required.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Markdown URLs in links', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php
								$md_enable   = (string) get_option( RNRD_OPT_MD_ENABLE, 'off' );
								$use_md_urls = (string) get_option( RNRD_OPT_LLMS_USE_MD_URLS, 'on' );
								?>
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_LLMS_USE_MD_URLS ); ?>" value="<?php echo esc_attr( 'on' === $md_enable ? 'off' : $use_md_urls ); ?>" />
								<label>
									<input type="checkbox"
										   name="<?php echo esc_attr( RNRD_OPT_LLMS_USE_MD_URLS ); ?>"
										   value="on"
										   <?php checked( $use_md_urls, 'on' ); ?>
										   <?php disabled( 'on' !== $md_enable ); ?> />
									<?php esc_html_e( 'Use .md URLs in post links', 'rankready-ai-llm-seo' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'List /post-slug.md instead of the HTML page URL in llms.txt. Posts without a Markdown endpoint keep their normal URL.', 'rankready-ai-llm-seo' ); ?>
								</p>
								<?php if ( 'on' !== $md_enable ) : ?>
									<p class="description">
										<?php
										echo wp_kses_post(
											sprintf(
												/* translators: %s: Markdown settings subtab URL */
												__( 'Enable <a href="%s">Markdown Endpoints</a> first to use Markdown URLs in llms.txt links.', 'rankready-ai-llm-seo' ),
												esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=crawlers&sub=markdown' ) )
											)
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rnrd_llms_max"><?php esc_html_e( 'Max Posts per Type', 'rankready-ai-llm-seo' ); ?></label></th>
							<td>
								<input type="number" id="rnrd_llms_max" name="<?php echo esc_attr( RNRD_OPT_LLMS_MAX_POSTS ); ?>"
									   value="<?php echo esc_attr( (string) get_option( RNRD_OPT_LLMS_MAX_POSTS, 100 ) ); ?>"
									   min="10" max="500" step="10" class="small-text" />
								<p class="description"><?php esc_html_e( 'Maximum number of posts per post type to include.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Exclude Categories', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php
								$exclude_cats = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_CATS, array() );
								$all_cats     = get_categories( array( 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
								?>
								<?php if ( ! empty( $all_cats ) && ! is_wp_error( $all_cats ) ) : ?>
									<fieldset style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:8px 12px;border-radius:4px;">
										<?php foreach ( $all_cats as $cat ) : ?>
											<label style="display:block;margin-bottom:4px;">
												<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_EXCLUDE_CATS ); ?>[]"
													   value="<?php echo esc_attr( $cat->term_id ); ?>"
													   <?php checked( in_array( (int) $cat->term_id, $exclude_cats, true ) ); ?> />
												<?php echo esc_html( $cat->name ); ?> <span style="color:#999;">(<?php echo esc_html( $cat->count ); ?>)</span>
											</label>
										<?php endforeach; ?>
									</fieldset>
								<?php else : ?>
									<?php foreach ( $exclude_cats as $cat_id ) : ?>
										<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_LLMS_EXCLUDE_CATS ); ?>[]" value="<?php echo esc_attr( (string) (int) $cat_id ); ?>" />
									<?php endforeach; ?>
									<p class="description"><?php esc_html_e( 'No categories found.', 'rankready-ai-llm-seo' ); ?></p>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Posts in checked categories will be excluded from llms.txt. Useful for filtering out demo, test, or irrelevant content.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Exclude Tags', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php
								$exclude_tags = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_TAGS, array() );
								$all_tags     = get_tags( array( 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
								?>
								<?php if ( ! empty( $all_tags ) && ! is_wp_error( $all_tags ) ) : ?>
									<fieldset style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:8px 12px;border-radius:4px;">
										<?php foreach ( $all_tags as $tag ) : ?>
											<label style="display:block;margin-bottom:4px;">
												<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_EXCLUDE_TAGS ); ?>[]"
													   value="<?php echo esc_attr( $tag->term_id ); ?>"
													   <?php checked( in_array( (int) $tag->term_id, $exclude_tags, true ) ); ?> />
												<?php echo esc_html( $tag->name ); ?> <span style="color:#999;">(<?php echo esc_html( $tag->count ); ?>)</span>
											</label>
										<?php endforeach; ?>
									</fieldset>
								<?php else : ?>
									<?php foreach ( $exclude_tags as $tag_id ) : ?>
										<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_LLMS_EXCLUDE_TAGS ); ?>[]" value="<?php echo esc_attr( (string) (int) $tag_id ); ?>" />
									<?php endforeach; ?>
									<p class="description"><?php esc_html_e( 'No tags found.', 'rankready-ai-llm-seo' ); ?></p>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Posts with checked tags will be excluded from llms.txt.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Show Categories Section', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php $show_cats = (string) get_option( RNRD_OPT_LLMS_SHOW_CATEGORIES, 'on' ); ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_SHOW_CATEGORIES ); ?>"
										   value="on" <?php checked( $show_cats, 'on' ); ?> />
									<?php esc_html_e( 'Show "Optional" categories section at the bottom of llms.txt', 'rankready-ai-llm-seo' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Cache Duration', 'rankready-ai-llm-seo' ); ?></label></th>
							<td>
								<div class="rnrd-input-action-row">
									<select name="<?php echo esc_attr( RNRD_OPT_LLMS_CACHE_TTL ); ?>">
										<?php $current_ttl = (int) get_option( RNRD_OPT_LLMS_CACHE_TTL, 3600 ); ?>
										<?php foreach ( array(
											900   => __( '15 minutes', 'rankready-ai-llm-seo' ),
											3600  => __( '1 hour', 'rankready-ai-llm-seo' ),
											21600 => __( '6 hours', 'rankready-ai-llm-seo' ),
											86400 => __( '24 hours', 'rankready-ai-llm-seo' ),
										) as $seconds => $label ) : ?>
											<option value="<?php echo esc_attr( $seconds ); ?>" <?php selected( $current_ttl, $seconds ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<button
										type="button"
										class="button button-secondary"
										id="rnrd-flush-llms-cache"
										data-label-default="<?php echo esc_attr__( 'Clear cache', 'rankready-ai-llm-seo' ); ?>"
										data-label-busy="<?php echo esc_attr__( 'Clearing…', 'rankready-ai-llm-seo' ); ?>"
									><?php esc_html_e( 'Clear cache', 'rankready-ai-llm-seo' ); ?></button>
								</div>
								<span
									id="rnrd-flush-status"
									class="rnrd-input-action-row__status"
									data-success-msg="<?php echo esc_attr__( 'Cache cleared.', 'rankready-ai-llm-seo' ); ?>"
									aria-live="polite"
								></span>
								<p class="description"><?php esc_html_e( 'How long to cache the generated llms.txt output. Use Clear cache to rebuild /llms.txt and /llms-full.txt immediately.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Full Version', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php $llms_full = (string) get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ); ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_FULL_ENABLE ); ?>"
										   value="on" <?php checked( $llms_full, 'on' ); ?> />
									<?php esc_html_e( 'Also serve /llms-full.txt with full post content inlined', 'rankready-ai-llm-seo' ); ?>
								</label>
								<?php if ( 'on' === $llms_full && 'on' === $llms_enable ) : ?>
									<p class="description" style="margin-top:4px;">
										<a href="<?php echo esc_url( home_url( '/llms-full.txt' ) ); ?>" target="_blank"><code><?php echo esc_html( home_url( '/llms-full.txt' ) ); ?></code></a>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>
				<!-- Hide RankReady credit — Pro (locked + Coming Soon in Free) -->
				<table class="form-table rnrd-form-table" style="margin-top:8px;">
					<tr>
						<th scope="row"><?php esc_html_e( 'Hide RankReady credit', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php if ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) : ?>
								<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_HIDE_BRANDING ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_HIDE_BRANDING ); ?>" value="on" <?php checked( get_option( RNRD_OPT_HIDE_BRANDING, 'off' ), 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Remove "Generated from RankReady" line from /llms.txt and /llms-full.txt', 'rankready-ai-llm-seo' ); ?></span>
								</label>
							<?php else : ?>
							<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_HIDE_BRANDING ); ?>" value="<?php echo esc_attr( get_option( RNRD_OPT_HIDE_BRANDING, 'off' ) ); ?>" />
							<label style="opacity:0.5;display:inline-flex;align-items:center;gap:8px;">
								<?php echo wp_kses_post( self::locked_checkbox_indicator() ); ?>
								<?php esc_html_e( 'Remove "Generated from RankReady" line from /llms.txt and /llms-full.txt', 'rankready-ai-llm-seo' ); ?>
								<span class="rnrd-soon-tag"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save LLMs.txt Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_llms', false ); ?>
			</div>

		</form>
		<?php
	}

	/** AI Visibility → Markdown Endpoints. */
	private static function render_visibility_sub_markdown(): void {
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::LLMS_GROUP ); ?>
			<?php self::render_llms_preserve_hiddens( 'markdown' ); ?>
			<!-- Markdown Endpoints -->
			<?php
			$md_enable       = (string) get_option( RNRD_OPT_MD_ENABLE, 'off' );
			$md_home_enable  = (string) get_option( RNRD_OPT_MD_HOME_ENABLE, 'on' );
			$show_on_front   = (string) get_option( 'show_on_front', 'posts' );
			$posts_page_id   = 'page' === $show_on_front ? (int) get_option( 'page_for_posts', 0 ) : 0;
			$posts_page      = $posts_page_id > 0 ? get_post( $posts_page_id ) : null;
			$has_posts_page  = $posts_page instanceof WP_Post;
			$home_scope_name = $has_posts_page ? __( 'Homepage & Blog Index', 'rankready-ai-llm-seo' ) : __( 'Homepage', 'rankready-ai-llm-seo' );
			$home_md_url     = home_url( '/index.md' );
			$posts_md_url    = $has_posts_page ? ( class_exists( 'RNRD_Markdown' ) ? RNRD_Markdown::get_md_url( $posts_page ) : '' ) : '';
			?>
			<?php
			$blog_index_active = $has_posts_page && 'on' === $md_home_enable;
			$goal_desc = $blog_index_active
				? __( 'Expose your homepage, blog index, and supported posts as clean Markdown for AI bots — via .md URLs or Accept: text/markdown.', 'rankready-ai-llm-seo' )
				: __( 'Expose your homepage and supported posts as clean Markdown for AI bots — via .md URLs or Accept: text/markdown.', 'rankready-ai-llm-seo' );
			$toggle_label = $blog_index_active
				? __( 'Add .md endpoints to homepage, blog index, and supported post URLs', 'rankready-ai-llm-seo' )
				: __( 'Add .md endpoints to homepage and supported post URLs', 'rankready-ai-llm-seo' );
			?>
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Markdown Endpoints', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php echo esc_html( $goal_desc ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable .md Endpoints', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MD_ENABLE ); ?>"
									   value="on" <?php checked( $md_enable, 'on' ); ?>
									   data-toggle-target="rnrd-md-fields" />
								<span class="rnrd-toggle-label"><?php echo esc_html( $toggle_label ); ?></span>
							</label>
							<?php if ( 'on' === $md_enable ) : ?>
								<p class="description" style="margin-top:8px;">
									<?php esc_html_e( 'Example:', 'rankready-ai-llm-seo' ); ?>
									<code>yoursite.com/sample-post.md</code>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<div id="rnrd-md-fields" class="rnrd-conditional-fields" <?php echo 'on' !== $md_enable ? 'style="display:none;"' : ''; ?>>
					<table class="form-table rnrd-form-table">
						<tr>
							<th scope="row"><?php echo esc_html( $home_scope_name ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MD_HOME_ENABLE ); ?>"
										   value="on" <?php checked( $md_home_enable, 'on' ); ?> />
									<?php
									echo esc_html(
										$has_posts_page
											? __( 'Enable Markdown for your homepage and blog/posts page', 'rankready-ai-llm-seo' )
											: __( 'Enable Markdown for your homepage', 'rankready-ai-llm-seo' )
									);
									?>
								</label>
							<p class="description" style="font-size:11px;">
								<?php esc_html_e( 'Homepage:', 'rankready-ai-llm-seo' ); ?>
								<a href="<?php echo esc_url( $home_md_url ); ?>" target="_blank"><code><?php echo esc_html( $home_md_url ); ?></code></a>
								<?php if ( $has_posts_page && ! empty( $posts_md_url ) ) : ?><br />
									<?php esc_html_e( 'Posts page:', 'rankready-ai-llm-seo' ); ?>
									<a href="<?php echo esc_url( $posts_md_url ); ?>" target="_blank"><code><?php echo esc_html( $posts_md_url ); ?></code></a>
								<?php endif; ?>
							</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Post Types', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php $md_types = (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) ); ?>
								<fieldset data-rnrd-min-one-checkboxes>
								<?php foreach ( self::get_allowed_post_types() as $slug => $label ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MD_POST_TYPES ); ?>[]"
											   value="<?php echo esc_attr( $slug ); ?>"
											   <?php checked( in_array( $slug, $md_types, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								</fieldset>
								<?php if ( ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) ) : ?><p class="rnrd-cpt-hint"><?php esc_html_e( 'Want to include Custom Post Types?', 'rankready-ai-llm-seo' ); ?> <span class="rnrd-soon-tag"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span></p><?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Include Metadata', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MD_INCLUDE_META ); ?>"
										   value="1" <?php checked( get_option( RNRD_OPT_MD_INCLUDE_META, '1' ), '1' ); ?> />
									<?php esc_html_e( 'Add YAML frontmatter (title, date, author, excerpt, tags)', 'rankready-ai-llm-seo' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'AI hint in body', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MD_HINT_DIV ); ?>"
										   value="on" <?php checked( get_option( RNRD_OPT_MD_HINT_DIV, 'on' ), 'on' ); ?> />
									<?php esc_html_e( 'Inject a hidden div pointing AI agents at the .md version', 'rankready-ai-llm-seo' ); ?>
								</label>
								<p class="description" style="font-size:11px;">
									<?php esc_html_e( 'Visually invisible (clip-path + aria-hidden). Raw-HTML scrapers see "AI agents: a clean Markdown version is at URL.md". A documented technique for pointing agents at the Markdown version.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Same-URL content negotiation', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MD_ACCEPT_NEGOTIATION ); ?>"
										   value="on" <?php checked( get_option( RNRD_OPT_MD_ACCEPT_NEGOTIATION, 'on' ), 'on' ); ?> />
									<?php esc_html_e( 'Serve Markdown on the normal page URL when a request sends Accept: text/markdown (or is a known AI bot)', 'rankready-ai-llm-seo' ); ?>
								</label>
								<p class="description" style="font-size:11px;">
									<strong><?php esc_html_e( 'Leave OFF unless you control your CDN cache key.', 'rankready-ai-llm-seo' ); ?></strong>
									<?php esc_html_e( 'Caches that ignore Vary: Accept (Cloudflare APO, Varnish, Fastly, most shared hosts) will store the Markdown response under your normal page URL and then serve it to every visitor — blanking your page. Your Markdown is always available at the distinct .md URL (e.g. /page.md) regardless of this setting; AI agents discover it via the Link header and llms.txt. This same-URL path is OFF by default and only safe behind a cache that keys on the Accept header.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Auto-serve to AI bots', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MD_BOT_AUTO_SERVE ); ?>"
										   value="on" <?php checked( get_option( RNRD_OPT_MD_BOT_AUTO_SERVE, 'on' ), 'on' ); ?> />
									<?php esc_html_e( 'Serve Markdown to GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google-Extended (12 bots total)', 'rankready-ai-llm-seo' ); ?>
								</label>
								<p class="description" style="font-size:11px;">
									<?php esc_html_e( 'Detected via User-Agent header. Only applies when "Same-URL content negotiation" above is ON — it shares that same cache-poisoning risk on the normal page URL. The cache-safe .md URLs do not need this.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
				<?php submit_button( __( 'Save Markdown Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_md', false ); ?>
			</div>

		</form>
		<?php
	}

	/** AI Visibility → WebMCP. */
	private static function render_visibility_sub_webmcp(): void {
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::LLMS_GROUP ); ?>
			<?php self::render_llms_preserve_hiddens( 'webmcp' ); ?>
			<!-- ── WebMCP (v1.2.0) ─────────────────────────────────────────────── -->
			<?php
			$rnrd_mcp_enable     = (string) get_option( RNRD_OPT_MCP_ENABLE, 'off' );
			$rnrd_abilities_api  = function_exists( 'wp_register_ability' );
			$rnrd_manifest_url   = home_url( '/.well-known/mcp.json' );
			?>
			<div class="rnrd-card" style="margin-bottom:24px;">
				<h2 class="rnrd-card-title">
					<?php esc_html_e( 'WebMCP — Agent Tooling', 'rankready-ai-llm-seo' ); ?>
					<span style="font-size:11px;background:var(--rnrd-color-info-bg,#e5f1f9);color:var(--rnrd-color-info-text,#135e96);padding:2px 8px;border-radius:9999px;margin-left:6px;vertical-align:middle;font-weight:600;">NEW</span>
				</h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Expose 16 read-only abilities at /.well-known/mcp.json — Claude Desktop, Cursor, VS Code read your site.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable WebMCP', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MCP_ENABLE ); ?>" value="on" <?php checked( $rnrd_mcp_enable, 'on' ); ?> />
								<span><?php esc_html_e( 'Serve /.well-known/mcp.json + register WordPress Abilities', 'rankready-ai-llm-seo' ); ?></span>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php if ( 'on' === $rnrd_mcp_enable ) : ?>
								<p style="margin:0 0 6px;">
									<span style="display:inline-block;padding:2px 8px;border-radius:9999px;background:var(--rnrd-color-success-bg,#d1ecdf);color:var(--rnrd-color-success-text,#0a6c39);font-size:11px;font-weight:600;">✓ <?php esc_html_e( 'Manifest live', 'rankready-ai-llm-seo' ); ?></span>
								</p>
								<p style="margin:6px 0 0;">
									<?php if ( $rnrd_abilities_api ) : ?>
										<span style="display:inline-block;padding:2px 8px;border-radius:9999px;background:var(--rnrd-color-success-bg,#d1ecdf);color:var(--rnrd-color-success-text,#0a6c39);font-size:11px;font-weight:600;">✓ <?php esc_html_e( 'WordPress Abilities API detected — 16 abilities registered', 'rankready-ai-llm-seo' ); ?></span>
									<?php else : ?>
										<span style="display:inline-block;padding:2px 8px;border-radius:9999px;background:var(--rnrd-color-warning-bg,#fcf9e8);color:var(--rnrd-color-warning-text,#674c00);font-size:11px;font-weight:600;">⚠ <?php esc_html_e( 'Abilities API plugin not active — manifest still works for raw MCP discovery', 'rankready-ai-llm-seo' ); ?></span>
									<?php endif; ?>
								</p>
							<?php else : ?>
								<p style="margin:0;">
									<span style="display:inline-block;padding:2px 8px;border-radius:9999px;background:var(--rnrd-color-surface-3,#f0f0f1);color:var(--rnrd-color-text-muted,#646970);font-size:11px;font-weight:600;">○ <?php esc_html_e( 'Disabled', 'rankready-ai-llm-seo' ); ?></span>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<?php if ( 'on' === $rnrd_mcp_enable ) :
						$rnrd_exposure   = class_exists( 'RNRD_MCP' ) ? RNRD_MCP::exposure_state() : array();
						$rnrd_detected_cpts = class_exists( 'RNRD_MCP' ) ? RNRD_MCP::detected_cpts() : array();
						$rnrd_enabled_cpts  = (array) get_option( RNRD_OPT_MCP_EXPOSE_CPTS, array() );

						// Helper closure for rendering a single resource toggle row.
						$render_toggle = function ( $opt, $label, $desc, $tone, $current ) {
							$tones = array(
								'safe'    => array( 'bg' => 'var(--rnrd-color-success-bg,#d1ecdf)', 'fg' => 'var(--rnrd-color-success-text,#0a6c39)', 'pill' => 'Safe' ),
								'caution' => array( 'bg' => 'var(--rnrd-color-warning-bg,#fcf9e8)', 'fg' => 'var(--rnrd-color-warning-text,#674c00)', 'pill' => 'Caution' ),
								'risky'   => array( 'bg' => 'var(--rnrd-color-danger-bg,#fcebe6)', 'fg' => 'var(--rnrd-color-danger-text,#a72e1f)', 'pill' => 'Risky' ),
							);
							$t = $tones[ $tone ] ?? $tones['safe'];
							?>
							<div style="display:grid;grid-template-columns:32px 1fr;gap:10px;padding:10px 12px;border-radius:var(--rnrd-radius-md,6px);background:var(--rnrd-color-surface-2,#f6f7f7);">
								<label style="display:flex;align-items:center;cursor:pointer;">
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>" value="on" <?php checked( 'on', $current ); ?> />
								</label>
								<div>
									<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:2px;">
										<strong style="font-size:13px;"><?php echo esc_html( $label ); ?></strong>
										<span style="display:inline-block;padding:1px 7px;border-radius:9999px;background:<?php echo esc_attr( $t['bg'] ); ?>;color:<?php echo esc_attr( $t['fg'] ); ?>;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;"><?php echo esc_html( $t['pill'] ); ?></span>
									</div>
									<p style="margin:0;font-size:12px;color:var(--rnrd-color-text-muted,#646970);line-height:1.5;"><?php echo esc_html( $desc ); ?></p>
								</div>
							</div>
							<?php
						};
						?>
						<tr>
							<th scope="row"><?php esc_html_e( 'MCP manifest URL', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<input type="text" readonly value="<?php echo esc_attr( $rnrd_manifest_url ); ?>" onclick="this.select();" style="width:100%;max-width:520px;font-family:monospace;font-size:12px;" />
								<p class="description">
									<?php esc_html_e( 'Paste this URL into Claude Desktop / Cursor / VS Code MCP settings.', 'rankready-ai-llm-seo' ); ?>
									<a href="<?php echo esc_url( $rnrd_manifest_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open manifest →', 'rankready-ai-llm-seo' ); ?></a>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row" style="vertical-align:top;">
								<?php esc_html_e( 'Resources exposed', 'rankready-ai-llm-seo' ); ?>
								<br /><span style="font-weight:400;font-size:11px;color:var(--rnrd-color-text-muted,#646970);text-transform:uppercase;letter-spacing:0.04em;"><?php esc_html_e( 'Per-resource toggle', 'rankready-ai-llm-seo' ); ?></span>
							</th>
							<td>
								<p class="description" style="margin:0 0 12px;">
									<?php esc_html_e( 'Choose what AI agents can see. Public content (posts, pages, authors, taxonomies) is safe to expose. PII / stack-revealing resources are OFF by default — opt in only if your use case requires it.', 'rankready-ai-llm-seo' ); ?>
								</p>

								<?php // v1.2.0 — 'menus' has no UI control but is exposed by default (public data). Emit a hidden input carrying its current value so saving this tab never force-nulls it (null-on-save trap). ?>
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_MCP_EXPOSE_MENUS ); ?>" value="<?php echo esc_attr( get_option( RNRD_OPT_MCP_EXPOSE_MENUS, 'on' ) ); ?>" />
								<details style="margin-bottom:14px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:var(--rnrd-color-success-text,#0a6c39);margin-bottom:8px;"><?php esc_html_e( 'Public content (safe defaults)', 'rankready-ai-llm-seo' ); ?></summary>
									<div style="display:flex;flex-direction:column;gap:6px;">
										<?php
										$render_toggle( RNRD_OPT_MCP_EXPOSE_POSTS,      __( 'Posts', 'rankready-ai-llm-seo' ),         __( 'list-recent-posts, search-posts, get-post, get-post-by-url — full Markdown content + AI summary + FAQ.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_POSTS, 'on' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_PAGES,      __( 'Pages', 'rankready-ai-llm-seo' ),         __( 'list-pages — static pages with parent hierarchy.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_PAGES, 'on' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_AUTHORS,    __( 'Authors (EEAT)', 'rankready-ai-llm-seo' ), __( 'get-author — Person schema fields (bio, credentials, awards, socials). No emails or login info.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_AUTHORS, 'on' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_TAXONOMIES, __( 'Categories & tags', 'rankready-ai-llm-seo' ), __( 'list-categories, list-tags — topical graph for agent navigation.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_TAXONOMIES, 'on' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_SITEMAP,    __( 'Sitemap', 'rankready-ai-llm-seo' ),       __( 'get-sitemap — parsed URL + lastmod for cold crawls.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_SITEMAP, 'on' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_LLMS_TXT,   __( 'llms.txt inline', 'rankready-ai-llm-seo' ), __( 'get-llms-txt — rendered llms.txt content without HTTP round-trip.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_LLMS_TXT, 'on' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_FRESHNESS,  __( 'Freshness signal', 'rankready-ai-llm-seo' ), __( 'get-fresh-content — posts/pages modified in last N days.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_FRESHNESS, 'on' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_RR_AI,      __( 'RankReady AI data', 'rankready-ai-llm-seo' ), __( 'get-post-summary, get-post-faq, get-brand-terms — the AI-citation surface RankReady generates.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_RR_AI, 'on' ) );
										?>
									</div>
								</details>

								<?php if ( ! empty( $rnrd_detected_cpts ) ) : ?>
								<?php $rnrd_cpt_pro = ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ); ?>
								<?php /* v1.2.1 — collapsed by default, always. It used to auto-open
								   whenever Pro was active, which left the opt-in, PII-sensitive
								   section expanded while "Public content (safe defaults)" stayed
								   folded — backwards. Every resource here is off unless the user
								   ticks it, so the panel does not need to demand attention. */ ?>
								<details style="margin-bottom:14px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:var(--rnrd-color-info-text,#135e96);margin-bottom:8px;">
										<?php esc_html_e( 'Custom post types', 'rankready-ai-llm-seo' ); ?>
										<?php if ( ! $rnrd_cpt_pro ) : ?>
											<span class="rnrd-soon-tag" style="margin-left:6px;"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span>
										<?php endif; ?>
									</summary>
									<?php if ( $rnrd_cpt_pro ) : ?>
										<p class="description" style="margin:6px 0 8px;font-size:12px;">
											<?php esc_html_e( 'Expose specific custom post types to AI agents over MCP. Off by default — enable only the types you want agents to read. Each uses the same safe content rules as Posts (published, non-password-protected only).', 'rankready-ai-llm-seo' ); ?>
										</p>
										<div style="display:flex;flex-direction:column;gap:6px;">
											<?php // Empty sentinel so un-checking every box clears the array on save. ?>
											<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_MCP_EXPOSE_CPTS ); ?>[]" value="" />
											<?php foreach ( $rnrd_detected_cpts as $rnrd_cpt_slug => $rnrd_cpt_label ) : ?>
												<div style="display:grid;grid-template-columns:32px 1fr;gap:10px;padding:10px 12px;border-radius:var(--rnrd-radius-md,6px);background:var(--rnrd-color-surface-2,#f6f7f7);">
													<label style="display:flex;align-items:center;cursor:pointer;">
														<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MCP_EXPOSE_CPTS ); ?>[]" value="<?php echo esc_attr( $rnrd_cpt_slug ); ?>" <?php checked( in_array( $rnrd_cpt_slug, $rnrd_enabled_cpts, true ) ); ?> />
													</label>
													<div>
														<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:2px;">
															<strong style="font-size:13px;"><?php echo esc_html( $rnrd_cpt_label ); ?></strong>
															<code style="font-size:11px;color:var(--rnrd-color-text-muted,#646970);"><?php echo esc_html( $rnrd_cpt_slug ); ?></code>
														</div>
														<p style="margin:0;font-size:12px;color:var(--rnrd-color-text-muted,#646970);line-height:1.5;"><?php esc_html_e( 'Adds list + get abilities for this post type to the MCP manifest.', 'rankready-ai-llm-seo' ); ?></p>
													</div>
												</div>
											<?php endforeach; ?>
										</div>
									<?php else : ?>
										<p class="description" style="margin:6px 0 8px;font-size:12px;">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: number of custom post types detected */
													_n(
														'%d custom post type detected on this site. Exposing custom post types to AI agents is coming soon — Posts and Pages are supported today.',
														'%d custom post types detected on this site. Exposing custom post types to AI agents is coming soon — Posts and Pages are supported today.',
														count( $rnrd_detected_cpts ),
														'rankready-ai-llm-seo'
													),
													count( $rnrd_detected_cpts )
												)
											);
											?>
										</p>
									<?php endif; ?>
								</details>
								<?php endif; ?>
								<?php if ( empty( $rnrd_detected_cpts ) || ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) ) : ?>
									<?php foreach ( $rnrd_enabled_cpts as $rnrd_preserved_cpt ) : ?>
										<?php if ( '' === (string) $rnrd_preserved_cpt ) { continue; } ?>
										<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_MCP_EXPOSE_CPTS ); ?>[]" value="<?php echo esc_attr( (string) $rnrd_preserved_cpt ); ?>" />
									<?php endforeach; ?>
								<?php endif; ?>

								<p style="margin:0;padding:10px 12px;background:var(--rnrd-color-brand-soft,#f0f6fc);border-left:3px solid var(--rnrd-color-brand,#2271b1);border-radius:0 var(--rnrd-radius-md,6px) var(--rnrd-radius-md,6px) 0;font-size:11px;color:var(--rnrd-color-info-text,#135e96);line-height:1.5;">
									<strong><?php esc_html_e( 'Write abilities', 'rankready-ai-llm-seo' ); ?></strong> &mdash; <?php esc_html_e( 'create / update / delete operations are not yet exposed. v1.4 will add governed write abilities (draft-faq, refresh-post) with capability checks, nonce verification, audit log, and rate limiting per action.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Active abilities', 'rankready-ai-llm-seo' ); ?>
								<br /><span style="font-weight:400;font-size:11px;color:var(--rnrd-color-text-muted,#646970);text-transform:uppercase;letter-spacing:0.04em;"><?php esc_html_e( 'live in manifest', 'rankready-ai-llm-seo' ); ?></span>
							</th>
							<td>
								<details style="margin-bottom:8px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);margin-bottom:6px;"><?php esc_html_e( 'Site & metadata (3)', 'rankready-ai-llm-seo' ); ?></summary>
									<ul style="margin:6px 0 12px;padding-left:18px;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);line-height:1.7;">
										<li><code>rankready/get-site-info</code> &mdash; <?php esc_html_e( 'site name, description, about, brand terms, language', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-brand-terms</code> &mdash; <?php esc_html_e( 'canonical brand names array', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/list-content-types</code> &mdash; <?php esc_html_e( 'every public post type + published count + archive URL', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
								</details>

								<details style="margin-bottom:8px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);margin-bottom:6px;"><?php esc_html_e( 'Content retrieval (4)', 'rankready-ai-llm-seo' ); ?></summary>
									<ul style="margin:6px 0 12px;padding-left:18px;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);line-height:1.7;">
										<li><code>rankready/get-post</code> &mdash; <strong><?php esc_html_e( 'full Markdown content', 'rankready-ai-llm-seo' ); ?></strong> + <?php esc_html_e( 'title, URL, author, summary, FAQ, schema in one call', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-post-by-url</code> &mdash; <?php esc_html_e( 'resolve any permalink (incl. .md / /category/ / /tag/) to content', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-post-summary</code> &mdash; <?php esc_html_e( 'AI summary bullets only', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-post-faq</code> &mdash; <?php esc_html_e( 'FAQ Q&amp;A pairs only', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
								</details>

								<details style="margin-bottom:8px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);margin-bottom:6px;"><?php esc_html_e( 'Discovery & navigation (5)', 'rankready-ai-llm-seo' ); ?></summary>
									<ul style="margin:6px 0 12px;padding-left:18px;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);line-height:1.7;">
										<li><code>rankready/search-posts</code> &mdash; <?php esc_html_e( 'keyword search across published posts', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/list-pages</code> &mdash; <?php esc_html_e( 'static pages + parent_id hierarchy', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/list-recent-posts</code> &mdash; <?php esc_html_e( 'paginated recent-posts feed', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/list-categories</code> &mdash; <?php esc_html_e( 'topical hierarchy: name, slug, parent, count, URL', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/list-tags</code> &mdash; <?php esc_html_e( 'tags ordered by post count', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
								</details>

								<details style="margin-bottom:8px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);margin-bottom:6px;"><?php esc_html_e( 'AI-native (4)', 'rankready-ai-llm-seo' ); ?></summary>
									<ul style="margin:6px 0 12px;padding-left:18px;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);line-height:1.7;">
										<li><code>rankready/get-llms-txt</code> &mdash; <?php esc_html_e( 'rendered llms.txt or llms-full.txt content inline', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-sitemap</code> &mdash; <?php esc_html_e( 'parsed sitemap (URL + lastmod) for cold crawls', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-fresh-content</code> &mdash; <?php esc_html_e( 'posts/pages modified in last N days', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-author</code> &mdash; <?php esc_html_e( 'EEAT Person schema fields for an author (credentials, awards, socials)', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
								</details>

								<p class="description" style="margin-top:8px;"><?php esc_html_e( 'All abilities are read-only. No write access exposed.', 'rankready-ai-llm-seo' ); ?>
								<a href="<?php echo esc_url( $rnrd_manifest_url ); ?>" target="_blank" rel="noopener" style="margin-left:6px;"><?php esc_html_e( 'View manifest JSON →', 'rankready-ai-llm-seo' ); ?></a></p>
							</td>
						</tr>
					<?php endif; ?>
				</table>

				<?php submit_button( __( 'Save WebMCP Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_mcp', false ); ?>
			</div>

		</form>
		<?php
	}

	/** AI Visibility → Open Knowledge Format (OKF). */
	private static function render_visibility_sub_okf(): void {
		self::render_card_okf();
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: FAQ Generator
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_faq(): void {
		?>
			<!-- Post Types, AI Generation, Display. -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'AI FAQ', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Discover real user questions and answer them with AI — FAQPage schema that AI Overviews and Perplexity prefer to cite.', 'rankready-ai-llm-seo' ); ?></p>

				<?php
				self::render_optional_post_types_section(
					RNRD_OPT_FAQ_POST_TYPES,
					__( 'Uncheck all to disable FAQ feature. Existing FAQs are kept.', 'rankready-ai-llm-seo' )
				);
				?>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'AI Generation', 'rankready-ai-llm-seo' ); ?></h3>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_faq_count"><?php esc_html_e( 'FAQ Count', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="number" id="rnrd_faq_count" name="<?php echo esc_attr( RNRD_OPT_FAQ_COUNT ); ?>"
							       value="<?php echo esc_attr( (string) get_option( RNRD_OPT_FAQ_COUNT, 5 ) ); ?>"
							       min="3" max="10" step="1" class="small-text" />
							<p class="description"><?php esc_html_e( 'Number of FAQ items to generate per post (3-10). More FAQs = more API calls.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_faq_brand_terms"><?php esc_html_e( 'Brand Terms', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<textarea id="rnrd_faq_brand_terms" name="<?php echo esc_attr( RNRD_OPT_FAQ_BRAND_TERMS ); ?>"
							          rows="3" class="large-text"
							          placeholder="<?php esc_attr_e( 'Your Brand Name, Your Product Name (one per line or comma-separated)', 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( (string) get_option( RNRD_OPT_FAQ_BRAND_TERMS, '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Brand and product names to reinforce in FAQ answers, so AI engines associate the answer with your brand rather than with "this site".', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
						<?php $rnrd_faq_autogen_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro(); ?>
					<tr<?php echo $rnrd_faq_autogen_pro ? '' : ' class="rnrd-row-locked"'; ?>>
						<th scope="row"><?php esc_html_e( 'Auto-Generate on Publish', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php if ( $rnrd_faq_autogen_pro ) : ?>
								<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_FAQ_AUTO_GENERATE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_FAQ_AUTO_GENERATE ); ?>" value="on" <?php checked( get_option( RNRD_OPT_FAQ_AUTO_GENERATE, 'off' ), 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Automatically generate FAQs when a post is published or updated', 'rankready-ai-llm-seo' ); ?></span>
								</label>
							<?php else : ?>
							<label style="display:inline-flex;align-items:center;gap:8px;color:#1d2327;">
							<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_FAQ_AUTO_GENERATE ); ?>" value="<?php echo esc_attr( get_option( RNRD_OPT_FAQ_AUTO_GENERATE, 'off' ) ); ?>" />
								<?php echo wp_kses_post( self::locked_checkbox_indicator() ); ?>
								<?php esc_html_e( 'Automatically generate FAQs when a post is published or updated', 'rankready-ai-llm-seo' ); ?>
								<span class="rnrd-soon-tag"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Off by default. When off, FAQs are only generated via the Gutenberg block, Elementor widget, or Bulk Generate.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Display', 'rankready-ai-llm-seo' ); ?></h3>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable AI FAQ', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $faq_enable = (string) get_option( RNRD_OPT_FAQ_ENABLE, 'on' ); ?>
							<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_FAQ_ENABLE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_FAQ_ENABLE ); ?>" value="on" <?php checked( $faq_enable, 'on' ); ?> data-toggle-target="rnrd-faq-auto-display" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Show FAQs on the frontend (single post/page views)', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Markdown and OKF always include generated FAQs after the body.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr id="rnrd-faq-auto-display" <?php echo 'on' !== $faq_enable ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><?php esc_html_e( 'Auto-display', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php
							self::render_auto_display_radios(
								RNRD_OPT_FAQ_AUTO_DISPLAY,
								class_exists( 'RNRD_Faq' ) ? RNRD_Faq::get_auto_display() : 'off'
							);
							?>
							<p class="description"><?php esc_html_e( 'Before/after content will be skipped if a Gutenberg block, Elementor widget, or [rankready_faq] shortcode is already in the post.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Heading Tag', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $faq_tag = (string) get_option( RNRD_OPT_FAQ_HEADING_TAG, 'h3' ); ?>
							<select name="<?php echo esc_attr( RNRD_OPT_FAQ_HEADING_TAG ); ?>">
								<?php foreach ( array( 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6' ) as $tag => $label ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $faq_tag, $tag ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show "Reviewed" Date', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $show_reviewed = (string) get_option( RNRD_OPT_FAQ_SHOW_REVIEWED, 'on' ); ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_FAQ_SHOW_REVIEWED ); ?>"
								       value="on" <?php checked( $show_reviewed, 'on' ); ?> />
								<?php esc_html_e( 'Show "Last reviewed: [date]" below the FAQ section', 'rankready-ai-llm-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Signals content freshness to LLMs and users.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save FAQ Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_faq', false ); ?>
			</div>

		<?php
	}

	// NOTE: The standalone "Headless / Public API" tab was merged into the Advanced
	// tab and its (never-dispatched) render_tab_headless() method was removed as dead
	// code in 1.0.16. The headless options stay registered under HEADLESS_GROUP and
	// the Pro RNRD_Headless REST class consumes them; there is no dedicated settings
	// UI surface today. Re-add a render method + a dispatch call (and a HEADLESS_GROUP
	// settings_fields form) if/when the headless config UI is reinstated.

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Tools
	// ═══════════════════════════════════════════════════════════════════════════

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Advanced — Tools (refactored into named card helpers in rc.6).
	// Each card is now an independent render_card_*() method so it can be
	// relocated to its proper tab (Content AI / E-E-A-T / Settings / Insights)
	// without duplicating HTML. Field names, JS hook IDs, and option keys
	// are preserved verbatim from rc.5 — no data migration required.
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_tools(): void {
		self::render_card_diagnostics();
		self::render_card_error_log();
		// Branding placeholder card removed in rc.16 — empty Coming Soon card not useful for users.
		// self::render_card_branding();
		self::render_card_data_retention();
	}

	/**
	 * Reusable minimal "Coming Soon" box. Shown in the Free build wherever a Pro
	 * feature's UI used to live. The functional Pro code is NOT in the Free zip —
	 * it ships in the RankReady Pro add-on, which replaces these boxes with real
	 * controls via its do_action() / has_action() extension hooks. Deliberately
	 * tiny: a titled card + COMING SOON tag + one optional line. No placeholder
	 * form fields, no upsell CTA (WP.org-safe).
	 *
	 * @param string $title Feature name.
	 * @param string $note  Optional one-line description.
	 */
	public static function render_coming_soon_box( string $title, string $note = '' ): void {
		?>
		<div class="rnrd-card rnrd-coming-soon-card">
			<h2 class="rnrd-card-title" style="display:flex;align-items:center;gap:8px;">
				<?php echo esc_html( $title ); ?>
				<span class="rnrd-soon-tag"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span>
			</h2>
			<?php if ( '' !== $note ) : ?>
				<p class="rnrd-card-goal" style="margin:0;"><?php echo esc_html( $note ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Branding placeholder (added in rc.11) ───────────────────────────────
	private static function render_card_branding(): void {
		$hide_value = (string) get_option( RNRD_OPT_HIDE_BRANDING, 'off' );
		?>
		<div class="rnrd-card" id="rnrd-branding-card">
			<h2 class="rnrd-card-title" style="display:flex;align-items:center;gap:8px;">
				<?php esc_html_e( 'Branding', 'rankready-ai-llm-seo' ); ?>
				<span style="background:#2271b1;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:3px;letter-spacing:.5px;">
					<?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?>
				</span>
			</h2>
			<p class="rnrd-card-goal">
				<?php esc_html_e( 'Hide the “Generated from RankReady” credit on /llms.txt and /llms-full.txt.', 'rankready-ai-llm-seo' ); ?>
			</p>

			<table class="form-table rnrd-form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Hide RankReady credit', 'rankready-ai-llm-seo' ); ?></th>
					<td>
						<label style="opacity:0.5;">
							<input type="checkbox"
							       name="<?php echo esc_attr( RNRD_OPT_HIDE_BRANDING ); ?>"
							       value="on"
							       <?php checked( $hide_value, 'on' ); ?>
							       disabled />
							<?php esc_html_e( 'Remove "Generated from RankReady" line from llms.txt + llms-full.txt', 'rankready-ai-llm-seo' ); ?>
						</label>
						<p class="description" style="margin-top:6px;">
							<?php esc_html_e( 'This toggle is planned for a future release. Today the credit line always shows.', 'rankready-ai-llm-seo' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<div style="background:#f0f6fc;border-left:3px solid #2271b1;padding:10px 14px;margin-top:8px;font-size:13px;color:#1d2327;border-radius:3px;">
				<strong><?php esc_html_e( 'Note:', 'rankready-ai-llm-seo' ); ?></strong>
				<?php esc_html_e( 'The credit line ("Generated from RankReady") contains no URL, version number, or marketing.', 'rankready-ai-llm-seo' ); ?>
			</div>
		</div>
		<?php
	}

	// ── Bulk Regenerate cards — PRO ENGINE ─────────────────────────────────
	// render_card_bulk_summary() and render_card_bulk_faq() moved to the Pro
	// add-on (RNRD_Pro_Admin), rendered via the `rnrd_pro_bulk_summary_card`
	// and `rnrd_pro_bulk_faq_card` actions that the Content AI tab fires. In
	// the Free build those actions have no listener, so a Coming Soon box shows
	// instead. The bulk REST engine is also Pro-only (RNRD_Pro_Rest).

	// ── Bulk Author Changer (E-E-A-T tab in rc.6) ──────────────────────────
	private static function render_card_bulk_author(): void {
		$users = self::get_authors();
		?>

		<!-- Bulk Author Changer -->
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Bulk Author Changer', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Reassign authors across any post type — preview the affected count before executing.', 'rankready-ai-llm-seo' ); ?></p>

			<table class="form-table rnrd-form-table">
				<!-- Post Types -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Post Types', 'rankready-ai-llm-seo' ); ?></th>
					<td>
						<div class="rnrd-checkboxes-inline">
							<?php foreach ( self::get_allowed_post_types() as $slug => $label ) : ?>
								<label>
									<input type="checkbox" class="rnrd-bac-pt" value="<?php echo esc_attr( $slug ); ?>" />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'Select one or more post types to affect.', 'rankready-ai-llm-seo' ); ?></p>
							<?php if ( ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) ) : ?>
								<p class="rnrd-cpt-hint"><?php esc_html_e( 'Want to include Custom Post Types?', 'rankready-ai-llm-seo' ); ?> <span class="rnrd-soon-tag"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span></p>
							<?php endif; ?>
					</td>
				</tr>
				<!-- From Author -->
				<tr>
					<th scope="row"><label for="rnrd-bac-from"><?php esc_html_e( 'Current Author (From)', 'rankready-ai-llm-seo' ); ?></label></th>
					<td>
						<select id="rnrd-bac-from" class="regular-text">
							<option value=""><?php esc_html_e( '-- All authors --', 'rankready-ai-llm-seo' ); ?></option>
							<?php foreach ( $users as $user ) : ?>
								<option value="<?php echo esc_attr( $user->ID ); ?>">
									<?php echo esc_html( $user->display_name . ' (@' . $user->user_login . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Leave blank to reassign posts regardless of current author.', 'rankready-ai-llm-seo' ); ?></p>
					</td>
				</tr>
				<!-- To Author -->
				<tr>
					<th scope="row">
						<label for="rnrd-bac-to"><?php esc_html_e( 'New Author (To)', 'rankready-ai-llm-seo' ); ?> <span style="color:#d63638;">*</span></label>
					</th>
					<td>
						<select id="rnrd-bac-to" class="regular-text">
							<option value=""><?php esc_html_e( '-- Select new author --', 'rankready-ai-llm-seo' ); ?></option>
							<?php foreach ( $users as $user ) : ?>
								<option value="<?php echo esc_attr( $user->ID ); ?>">
									<?php echo esc_html( $user->display_name . ' (@' . $user->user_login . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<!-- Date Range -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Date Range', 'rankready-ai-llm-seo' ); ?></th>
					<td>
						<div class="rnrd-date-range">
							<div>
								<label for="rnrd-bac-date-from"><?php esc_html_e( 'After', 'rankready-ai-llm-seo' ); ?></label>
								<input type="date" id="rnrd-bac-date-from" />
							</div>
							<div>
								<label for="rnrd-bac-date-to"><?php esc_html_e( 'Before', 'rankready-ai-llm-seo' ); ?></label>
								<input type="date" id="rnrd-bac-date-to" />
							</div>
						</div>
						<p class="description"><?php esc_html_e( 'Optional — leave blank to include all dates.', 'rankready-ai-llm-seo' ); ?></p>
					</td>
				</tr>
				<!-- Actions -->
				<tr>
					<th></th>
					<td>
						<div class="rnrd-tool-actions">
							<button id="rnrd-bac-preview" class="button button-secondary"><?php esc_html_e( 'Preview Count', 'rankready-ai-llm-seo' ); ?></button>
							<button id="rnrd-bac-execute" class="button button-primary" disabled><?php esc_html_e( 'Execute', 'rankready-ai-llm-seo' ); ?></button>
							<button id="rnrd-bac-stop" class="button" style="display:none;"><?php esc_html_e( 'Stop', 'rankready-ai-llm-seo' ); ?></button>
						</div>
					</td>
				</tr>
			</table>

			<!-- Preview result -->
			<div id="rnrd-bac-preview-result" style="display:none;" class="rnrd-notice rnrd-notice--info"></div>

			<!-- Progress -->
			<div id="rnrd-bac-progress" style="display:none;margin-top:16px;">
				<div class="rnrd-progress-track">
					<div id="rnrd-bac-bar" class="rnrd-progress-fill"></div>
				</div>
				<p id="rnrd-bac-status" class="rnrd-progress-label"></p>
			</div>

			<!-- Done -->
			<div id="rnrd-bac-done" style="display:none;" class="rnrd-notice rnrd-notice--success"></div>
		</div>
		<?php
	}

	// ── API Usage (Settings tab in rc.6) ───────────────────────────────────
	private static function render_card_api_usage(): void {
		// Token Usage data
		$token_usage = (array) get_option( 'rnrd_token_usage', array(
			'summary_tokens' => 0,
			'faq_tokens'     => 0,
			'total_calls'    => 0,
		) );
		$summary_tokens = isset( $token_usage['summary_tokens'] ) ? (int) $token_usage['summary_tokens'] : 0;
		$faq_tokens     = isset( $token_usage['faq_tokens'] ) ? (int) $token_usage['faq_tokens'] : 0;
		$total_calls    = isset( $token_usage['total_calls'] ) ? (int) $token_usage['total_calls'] : 0;
		$total_tokens   = $summary_tokens + $faq_tokens;

		// Cost-per-token varies by provider (and by model within a provider).
		// We display a blended estimate using a conservative average across
		// the four provider defaults — actual cost depends on the provider
		// you have active. See RNRD_LLM for the per-model price reference.
		$est_cost = ( $total_tokens / 1000000 ) * 0.30;

		// DataForSEO usage.
		$dfs_usage = (array) get_option( 'rnrd_dfs_usage', array(
			'total_calls' => 0,
			'total_cost'  => 0,
		) );
		$dfs_calls = isset( $dfs_usage['total_calls'] ) ? (int) $dfs_usage['total_calls'] : 0;
		$dfs_cost  = isset( $dfs_usage['total_cost'] ) ? (float) $dfs_usage['total_cost'] : 0;
		?>
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'API Usage', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Cumulative tokens, calls, and estimated cost since tracking began.', 'rankready-ai-llm-seo' ); ?></p>

			<?php
			$active_provider_label = RNRD_LLM::get_provider_label( RNRD_LLM::get_active_provider() );
			$total_blended         = (float) $est_cost + (float) $dfs_cost;
			?>

			<div class="rnrd-kpi-row" style="margin-top:14px;">
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Total cost', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'AI + DataForSEO', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value">$<?php echo esc_html( number_format( $total_blended, 4 ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'Blended estimate', 'rankready-ai-llm-seo' ); ?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Total tokens', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php echo esc_html( $active_provider_label ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total_tokens ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php
						printf(
							/* translators: 1: summary token count, 2: FAQ token count */
							esc_html__( '%1$s summary · %2$s FAQ', 'rankready-ai-llm-seo' ),
							esc_html( number_format_i18n( $summary_tokens ) ),
							esc_html( number_format_i18n( $faq_tokens ) )
						);
					?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'AI API calls', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php echo esc_html( $active_provider_label ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total_calls ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'Cumulative', 'rankready-ai-llm-seo' ); ?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'DataForSEO calls', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'FAQ question discovery', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $dfs_calls ) ); ?></div>
					<div class="rnrd-kpi__foot">$<?php echo esc_html( number_format( $dfs_cost, 4 ) ); ?> <?php esc_html_e( 'spent', 'rankready-ai-llm-seo' ); ?></div>
				</div>
			</div>

			<p style="margin-top:16px;">
				<button type="button" id="rnrd-tokens-load" class="button button-secondary"><?php esc_html_e( 'Load Per-Post Details', 'rankready-ai-llm-seo' ); ?></button>
				<span id="rnrd-tokens-count" style="margin-left:10px;font-size:13px;display:none;"></span>
			</p>
			<div id="rnrd-tokens-list" style="display:none;margin-top:12px;max-height:400px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;">
				<table class="widefat striped" style="margin:0;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Type', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Tokens Used', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:15%;"><?php esc_html_e( 'Actions', 'rankready-ai-llm-seo' ); ?></th>
						</tr>
					</thead>
					<tbody id="rnrd-tokens-tbody"></tbody>
				</table>
			</div>
		</div>
		<?php
	}

	// ── Content Freshness Alerts (Insights → Freshness sub-tab in rc.6) ────
	private static function render_card_freshness_alerts(): void {
		?>

		<!-- Content Freshness Alerts (merged with intro paragraph in rc.16) -->
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Content Freshness', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Pages refreshed within 60 days are prioritised by ChatGPT, Perplexity, and Gemini — scan to find stale posts.', 'rankready-ai-llm-seo' ); ?></p>
			<!-- v1.1.12 — Stale Threshold + Scan button live on a single row.
			     Threshold left, button + status pushed to the right. -->
			<div class="rnrd-fw-controlbar">
				<div class="rnrd-fw-controlbar__left">
					<label for="rnrd-freshness-days" class="rnrd-fw-controlbar__label"><?php esc_html_e( 'Stale Threshold', 'rankready-ai-llm-seo' ); ?></label>
					<select id="rnrd-freshness-days">
						<option value="60"><?php esc_html_e( '60 days', 'rankready-ai-llm-seo' ); ?></option>
						<option value="90" selected><?php esc_html_e( '90 days (recommended)', 'rankready-ai-llm-seo' ); ?></option>
						<option value="180"><?php esc_html_e( '180 days', 'rankready-ai-llm-seo' ); ?></option>
						<option value="365"><?php esc_html_e( '1 year', 'rankready-ai-llm-seo' ); ?></option>
					</select>
				</div>
				<div class="rnrd-fw-controlbar__right">
					<span id="rnrd-freshness-status" class="rnrd-fw-status"></span>
					<button type="button" id="rnrd-freshness-scan" class="button button-primary"><?php esc_html_e( 'Scan Content Freshness', 'rankready-ai-llm-seo' ); ?></button>
				</div>
			</div>
			<div id="rnrd-freshness-summary" style="display:none;margin-top:12px;"></div>
			<div id="rnrd-freshness-results" style="display:none;margin-top:16px;max-height:500px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;">
				<table class="widefat striped" style="margin:0;">
					<thead>
						<tr>
							<th style="width:5%;"><?php esc_html_e( 'Urgency', 'rankready-ai-llm-seo' ); ?></th>
							<th><?php esc_html_e( 'Post', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Type', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Last Updated', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Days Stale', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Summary', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'FAQ', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:8%;"><?php esc_html_e( 'Actions', 'rankready-ai-llm-seo' ); ?></th>
						</tr>
					</thead>
					<tbody id="rnrd-freshness-tbody"></tbody>
				</table>
			</div>

			<?php
			// Freshness widget — segmented Stale/Going stale/Fresh tabs +
			// per-post checklist. Lives INSIDE the same card so the design
			// reads as one consolidated panel, not two stacked boxes.
			if ( class_exists( 'RNRD_Freshness' ) ) {
				RNRD_Freshness::render_widget();
			}
			?>
		</div>
		<?php
	}

	// ── Diagnostics (stays in Advanced) ────────────────────────────────────
	private static function render_card_diagnostics(): void {
		?>

		<?php
		// Short pointer when Cloudflare is in front — full connect UI lives on
		// Settings → Cloudflare so Advanced stays diagnostics-focused.
		$rnrd_cf = class_exists( 'RNRD_Cloudflare' ) ? RNRD_Cloudflare::detect() : array( 'detected' => false );
		if ( ! empty( $rnrd_cf['detected'] ) ) :
			$rnrd_cf_url = add_query_arg(
				array(
					'page' => self::MENU_SLUG,
					'tab'  => 'settings',
					'sub'  => 'cloudflare',
				),
				admin_url( 'admin.php' )
			);
			?>
			<div class="rnrd-card" id="rnrd-cf-advisory">
				<h2 class="rnrd-card-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
					<?php esc_html_e( 'Cloudflare detected', 'rankready-ai-llm-seo' ); ?>
					<span style="display:inline-block;background:var(--rnrd-color-success-bg,#d1ecdf);color:var(--rnrd-color-success-text,#0a6c39);font-size:10px;font-weight:700;padding:2px 9px;border-radius:9999px;text-transform:uppercase;letter-spacing:.04em;"><?php esc_html_e( 'Optional setup', 'rankready-ai-llm-seo' ); ?></span>
				</h2>
				<p class="rnrd-card-desc" style="margin:0;">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: Cloudflare ray id, 2: opening anchor, 3: closing anchor */
							__( 'Your site is served through Cloudflare (ray %1$s). Manage the Markdown cache-bypass rule under %2$sSettings → Cloudflare%3$s.', 'rankready-ai-llm-seo' ),
							esc_html( (string) ( $rnrd_cf['ray'] ?? '—' ) ),
							'<a href="' . esc_url( $rnrd_cf_url ) . '">',
							'</a>'
						),
						array(
							'a' => array(
								'href' => true,
							),
						)
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<!-- Diagnostics — replaced legacy Health Check in v1.2.0-rc.5.
		     22 real endpoint probes + conflict detection + 1-click copy report
		     for support. JS handler lives in assets/admin.js. -->
		<div class="rnrd-card" id="rnrd-diagnostics-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Diagnostics', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Live probes for /llms.txt, robots, Markdown, and WebMCP — plus cache/builder/SEO conflict detection with one-line fixes.', 'rankready-ai-llm-seo' ); ?></p>

			<p>
				<button type="button" id="rnrd-diag-run" class="button button-primary">
					<?php esc_html_e( 'Run Diagnostics', 'rankready-ai-llm-seo' ); ?>
				</button>
				<label style="margin-left:14px;font-size:13px;color:#646970;">
					<input type="checkbox" id="rnrd-diag-include-api" />
					<?php esc_html_e( 'Also test LLM provider keys (uses 1 API call per provider)', 'rankready-ai-llm-seo' ); ?>
				</label>
				<span id="rnrd-diag-status" style="margin-left:10px;font-size:13px;display:none;"></span>
			</p>

			<!-- Summary chips (filled after first run) -->
			<div id="rnrd-diag-summary" style="display:none;margin-top:12px;font-size:13px;"></div>

			<!-- Results table -->
			<div id="rnrd-diag-results" style="display:none;margin-top:16px;">
				<table class="widefat striped" style="margin:0;">
					<thead>
						<tr>
							<th style="width:5%;"></th>
							<th style="width:28%;"><?php esc_html_e( 'Check', 'rankready-ai-llm-seo' ); ?></th>
							<th><?php esc_html_e( 'Result + Fix', 'rankready-ai-llm-seo' ); ?></th>
						</tr>
					</thead>
					<tbody id="rnrd-diag-tbody"></tbody>
				</table>
			</div>

			<?php
			// FREE-99 — Server-level cache bypass snippets.
			// Surfaced because the probe_edge_cache_hit check can detect server
			// caches (LSWS, nginx FastCGI, Varnish) that runtime PHP cannot fix.
			// Hidden in a collapsed accordion so it stays out of the way when
			// not needed — appears below the run-diagnostics results.
			if ( class_exists( 'RNRD_Cache' ) ) :
				$htaccess_snippet   = RNRD_Cache::apache_htaccess_snippet();
				$nginx_snippet      = RNRD_Cache::nginx_snippet();
				$nginx_wk_snippet   = RNRD_Cache::nginx_well_known_snippet();
				$cloudflare_snippet = RNRD_Cache::cloudflare_cache_rule_snippet();
			?>
			<details class="rnrd-diag-snippet" style="margin-top:16px;padding:12px 14px;border:1px solid #E5E7E0;border-radius:8px;background:#fafbfa;">
				<summary style="cursor:pointer;font-weight:600;font-size:13px;color:#1d2327;">
					<?php esc_html_e( 'Server / CDN cache bypass snippets (advanced)', 'rankready-ai-llm-seo' ); ?>
				</summary>
				<p style="margin:10px 0 6px;font-size:12px;color:#646970;line-height:1.5;">
					<?php esc_html_e( 'Apply these only when a cache layer is serving stale or wrong-type responses to AI crawlers (e.g. Cloudflare returning HTML to Accept: text/markdown requests, or LSWS caching /llms.txt before PHP runs).', 'rankready-ai-llm-seo' ); ?>
				</p>
				<p style="margin:14px 0 4px;font-size:12px;font-weight:600;color:#1d2327;">
					<?php esc_html_e( 'Cloudflare — paste into Cache Rules → Custom filter expression', 'rankready-ai-llm-seo' ); ?>
				</p>
				<textarea readonly class="rnrd-diag-snippet-text" style="width:100%;height:140px;font-family:Menlo,Consolas,monospace;font-size:11px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px;"><?php echo esc_textarea( $cloudflare_snippet ); ?></textarea>
				<p style="margin:14px 0 4px;font-size:12px;font-weight:600;color:#1d2327;">
					<?php esc_html_e( 'Apache / LiteSpeed — paste at top of .htaccess', 'rankready-ai-llm-seo' ); ?>
				</p>
				<textarea readonly class="rnrd-diag-snippet-text" style="width:100%;height:140px;font-family:Menlo,Consolas,monospace;font-size:11px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px;"><?php echo esc_textarea( $htaccess_snippet ); ?></textarea>
				<p style="margin:14px 0 4px;font-size:12px;font-weight:600;color:#1d2327;">
					<?php esc_html_e( 'nginx — add inside your server { } block', 'rankready-ai-llm-seo' ); ?>
				</p>
				<textarea readonly class="rnrd-diag-snippet-text" style="width:100%;height:140px;font-family:Menlo,Consolas,monospace;font-size:11px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px;"><?php echo esc_textarea( $nginx_snippet ); ?></textarea>
				<p style="margin:14px 0 4px;font-size:12px;font-weight:600;color:#1d2327;">
					<?php esc_html_e( 'nginx — if /.well-known/mcp.json returns 403 (this ALLOWS access; it is not a cache setting)', 'rankready-ai-llm-seo' ); ?>
				</p>
				<textarea readonly class="rnrd-diag-snippet-text" style="width:100%;height:120px;font-family:Menlo,Consolas,monospace;font-size:11px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px;"><?php echo esc_textarea( $nginx_wk_snippet ); ?></textarea>
			</details>
			<?php endif; ?>

			<!-- Copy support report -->
			<div id="rnrd-diag-copy-row" style="display:none;margin-top:14px;padding-top:14px;border-top:1px solid #e5e5e5;">
				<p style="margin:0 0 6px;font-size:13px;color:#1d2327;">
					<strong><?php esc_html_e( 'Need help?', 'rankready-ai-llm-seo' ); ?></strong>
					<?php esc_html_e( 'Click below to copy a full diagnostic report with active plugins, versions, and conflict details — paste into any support channel or include with a bug report.', 'rankready-ai-llm-seo' ); ?>
				</p>
				<button type="button" id="rnrd-diag-copy" class="button button-secondary">
					<span class="dashicons dashicons-clipboard" style="vertical-align:middle;margin-top:-2px;"></span>
					<?php esc_html_e( 'Copy Diagnostic Report', 'rankready-ai-llm-seo' ); ?>
				</button>
				<span id="rnrd-diag-copy-status" style="margin-left:10px;font-size:13px;display:none;"></span>
				<details style="margin-top:10px;">
					<summary style="cursor:pointer;font-size:12px;color:#646970;">
						<?php esc_html_e( 'Preview report (plaintext)', 'rankready-ai-llm-seo' ); ?>
					</summary>
					<textarea id="rnrd-diag-report-preview" readonly style="width:100%;height:220px;font-family:Menlo,Consolas,monospace;font-size:11px;margin-top:8px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;padding:10px;" placeholder="<?php esc_attr_e( 'Run diagnostics, then click Copy to see report here.', 'rankready-ai-llm-seo' ); ?>"></textarea>
				</details>
			</div>
		</div>
		<?php
	}

	// ── Error Log (stays in Advanced) ──────────────────────────────────────
	private static function render_card_error_log(): void {
		?>

		<!-- Error Log -->
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Error Log', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Recent API errors from OpenAI, Anthropic, Gemini, DeepSeek, and DataForSEO — last 50 entries.', 'rankready-ai-llm-seo' ); ?></p>
			<p>
				<button type="button" id="rnrd-errors-load" class="button button-secondary"><?php esc_html_e( 'Load Error Log', 'rankready-ai-llm-seo' ); ?></button>
				<button type="button" id="rnrd-errors-clear" class="button" style="margin-left:8px;"><?php esc_html_e( 'Clear Log', 'rankready-ai-llm-seo' ); ?></button>
				<span id="rnrd-errors-status" style="margin-left:10px;font-size:13px;display:none;"></span>
			</p>
			<div id="rnrd-errors-list" style="display:none;margin-top:16px;max-height:400px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;">
				<table class="widefat striped" style="margin:0;">
					<thead>
						<tr>
							<th style="width:15%;"><?php esc_html_e( 'When', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Source', 'rankready-ai-llm-seo' ); ?></th>
							<th><?php esc_html_e( 'Error', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Post', 'rankready-ai-llm-seo' ); ?></th>
						</tr>
					</thead>
					<tbody id="rnrd-errors-tbody"></tbody>
				</table>
			</div>
		</div>
		<?php
	}

	// ── Data Retention (stays in Advanced — isolated DATA_GROUP form) ──────
	private static function render_card_okf(): void {
		$enabled   = 'on' === get_option( RNRD_OPT_OKF_ENABLE, 'off' );
		$sel_types = (array) get_option( RNRD_OPT_OKF_POST_TYPES, array( 'post', 'page' ) );
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::OKF_GROUP ); /* Isolated group — only the two OKF options, cross-null-safe. */ ?>
			<div class="rnrd-card" style="margin-bottom:24px;">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Open Knowledge Format (OKF)', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Publish a machine-readable OKF bundle so AI agents can ingest your content without scraping.', 'rankready-ai-llm-seo' ); ?></p>
				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable OKF bundle', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_OKF_ENABLE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_OKF_ENABLE ); ?>" value="on" <?php checked( $enabled ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Serve the bundle at /okf/ and refresh it automatically on publish or edit.', 'rankready-ai-llm-seo' ); ?></span>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Include post types', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_OKF_POST_TYPES ); ?>[]" value="" />
							<fieldset data-rnrd-min-one-checkboxes>
							<?php foreach ( self::get_allowed_post_types() as $okf_pt_slug => $okf_pt_label ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_OKF_POST_TYPES ); ?>[]" value="<?php echo esc_attr( $okf_pt_slug ); ?>" <?php checked( in_array( $okf_pt_slug, $sel_types, true ) ); ?> />
									<?php echo esc_html( $okf_pt_label ); ?>
								</label>
							<?php endforeach; ?>
							</fieldset>
							<?php if ( ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) ) : ?><p class="rnrd-cpt-hint"><?php esc_html_e( 'Want to include Custom Post Types?', 'rankready-ai-llm-seo' ); ?> <span class="rnrd-soon-tag"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span></p><?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save OKF Settings', 'rankready-ai-llm-seo' ) ); ?>

				<?php if ( $enabled ) : /* v1.2.0 — "OKF bundle" merged into this card; it was a redundant second card for the same feature. */ ?>
					<div class="rnrd-okf-bundle" style="margin-top:24px;">
						<h3 class="rnrd-card-title" style="font-size:14px;"><?php esc_html_e( 'Your OKF bundle', 'rankready-ai-llm-seo' ); ?></h3>
					<table class="form-table rnrd-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Bundle URL', 'rankready-ai-llm-seo' ); ?></th>
							<td><a href="<?php echo esc_url( home_url( '/okf/' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/okf/' ) ); ?></a></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Download', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<a class="button" href="<?php echo esc_url( RNRD_OKF::export_url() ); ?>"><?php esc_html_e( 'Download .zip bundle', 'rankready-ai-llm-seo' ); ?></a>
								<p class="description"><?php esc_html_e( 'A ZIP of the full bundle (index.md, log.md and every concept file) for upload to Google Cloud Knowledge Catalog or a Git repo.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
					</table>
					</div>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}

	private static function render_card_data_retention(): void {
		?>

		<!-- ── Data Retention (moved here from Settings tab in rc.3) ──────── -->
		<form method="post" action="options.php" novalidate="novalidate" class="rnrd-data-form">
			<?php settings_fields( self::DATA_GROUP ); /* Isolated group — saves only the uninstall toggle. */ ?>

			<div class="rnrd-card" style="margin-bottom:24px;">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Data Retention', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Choose what happens to RankReady data when the plugin is uninstalled — default keeps everything.', 'rankready-ai-llm-seo' ); ?></p>
				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'On Deactivate', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<p style="margin:0;">
								<span class="dashicons dashicons-shield" style="color:#46b450;"></span>
								<strong><?php esc_html_e( 'Nothing is deleted on deactivation.', 'rankready-ai-llm-seo' ); ?></strong>
							</p>
							<p class="description" style="margin-top:6px;">
								<?php esc_html_e( 'Deactivating RankReady only pauses its hooks and clears scheduled cron jobs. All settings, API keys, AI summaries, FAQ data, Author Box profiles, freshness history, and post meta stay exactly where they are. You can reactivate any time and pick up where you left off.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On Uninstall (Delete)', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $delete_on_uninstall = (string) get_option( RNRD_OPT_DELETE_ON_UNINSTALL, 'off' ); ?>
							<label>
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_DELETE_ON_UNINSTALL ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_DELETE_ON_UNINSTALL ); ?>" value="on" <?php checked( $delete_on_uninstall, 'on' ); ?> />
								<?php esc_html_e( 'Delete all RankReady data when the plugin is uninstalled', 'rankready-ai-llm-seo' ); ?>
							</label>
							<p class="description" style="margin-top:6px;">
								<?php esc_html_e( 'OFF by default. Uninstalling preserves all your data — API keys, settings, every AI Summary, every FAQ, every Author Box profile, all post meta. Reinstalling RankReady brings everything back automatically.', 'rankready-ai-llm-seo' ); ?>
							</p>
							<p class="description" style="margin-top:6px;color:var(--rnrd-color-danger,#d63638);">
								<strong><?php esc_html_e( 'Warning:', 'rankready-ai-llm-seo' ); ?></strong>
								<?php esc_html_e( 'When ON, uninstall permanently removes every RankReady option, post meta, and user meta. Cannot be undone. Leave OFF unless you need a completely clean slate.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Data Retention', 'rankready-ai-llm-seo' ) ); ?>
			</div>
		</form>
		<?php
	}

	// (TAB: Info removed in rc.15 — "How It Works" + "Quick Stats" cards were
	// moved to Dashboard scorecard in rc.5/rc.6. The old render_tab_info()
	// method was orphan code with no callers — full removal here.)

	// ── Test connection ───────────────────────────────────────────────────────

	private static function test_connection_url(): string {
		return add_query_arg( array(
			'page'           => self::MENU_SLUG,
			'tab'            => 'api',
			self::NONCE_FIELD => wp_create_nonce( self::NONCE_ACTION ),
			'rnrd_action'      => 'test',
		), admin_url( 'admin.php' ) );
	}

	/**
	 * Warn when plain permalinks are active.
	 *
	 * RankReady's virtual endpoints (llms.txt, llms-full.txt, *.md) rely on
	 * WordPress rewrite rules which only work with pretty permalinks. When the
	 * site uses the default ?p=123 structure, those endpoints return 404 and
	 * LLM crawlers cannot access the content.
	 */
	/**
	 * Remove third-party admin notices on RankReady's own settings screen only.
	 *
	 * WordPress fires admin_notices / all_admin_notices / user_admin_notices on every
	 * admin page, so other plugins' rating nags, "deactivate me" warnings, and Action
	 * Scheduler alerts hijack the top of RankReady's settings page. We keep RankReady's
	 * own RNRD_ notices and drop everyone else's — strictly on our screen. Other admin
	 * pages are never touched.
	 */
	public static function declutter_admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}

		global $wp_filter;
		foreach ( array( 'admin_notices', 'all_admin_notices', 'user_admin_notices' ) as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $callback ) {
					if ( ! self::is_own_notice( $callback['function'] ) ) {
						unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $id ] );
					}
				}
			}
		}
	}

	/**
	 * Is an admin_notices callback one of RankReady's own (RNRD_ class / rnrd_ function)?
	 * Closures and third-party callbacks return false so they are stripped on our screen.
	 *
	 * @param mixed $callback The registered callback.
	 */
	private static function is_own_notice( $callback ): bool {
		if ( is_array( $callback ) && isset( $callback[0] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return 0 === stripos( $class, 'RNRD_' );
		}
		if ( is_string( $callback ) ) {
			return 0 === stripos( $callback, 'rnrd_' );
		}
		return false;
	}

	/**
	 * Proactive, dismissible notice: if WebMCP is on but /.well-known/mcp.json
	 * returns 403 (the server denies dot-paths before WordPress runs, almost
	 * always nginx / RunCloud), show the exact one-block fix. The probe result
	 * is cached 12h, so this costs at most one HTTP request per half-day and
	 * only on RankReady screens. Fail-safe: a blocked or errored probe shows nothing.
	 */
	public static function maybe_nginx_wellknown_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'rankready' ) ) {
			return;
		}
		if ( 'on' !== get_option( RNRD_OPT_MCP_ENABLE, 'off' ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), '_rnrd_nginx_wk_dismissed', true ) ) {
			return;
		}

		$status = get_transient( 'rnrd_wk_probe_v1' );
		if ( false === $status ) {
			$resp   = wp_remote_get(
				home_url( '/.well-known/mcp.json' ),
				array( 'timeout' => 4, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/json' ) )
			);
			$status = is_wp_error( $resp ) ? -1 : (int) wp_remote_retrieve_response_code( $resp );
			set_transient( 'rnrd_wk_probe_v1', 0 === $status ? -1 : $status, 12 * HOUR_IN_SECONDS );
		}
		if ( 403 !== (int) $status ) {
			return;
		}

		$sw       = isset( $_SERVER['SERVER_SOFTWARE'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) )
			: '';
		$is_nginx = ( false !== strpos( $sw, 'nginx' ) );
		$snippet  = class_exists( 'RNRD_Cache' ) ? RNRD_Cache::nginx_well_known_snippet() : '';
		$headline = $is_nginx
			? __( 'We detected nginx and /.well-known/mcp.json is blocked (HTTP 403).', 'rankready-ai-llm-seo' )
			: __( 'Your server is blocking /.well-known/mcp.json (HTTP 403).', 'rankready-ai-llm-seo' );

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'rnrd_dismiss_nginx_wk', '1', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
			'rnrd_dismiss_nginx_wk',
			'_rnrd_nonce'
		);
		?>
		<div class="notice notice-warning is-dismissible">
			<p><strong><?php echo esc_html( $headline ); ?></strong></p>
			<p><?php esc_html_e( 'The WebMCP manifest cannot be reached because the server denies /.well-known/ before WordPress runs. Add this one block to your nginx server config (above any dotfile deny rule), then reload nginx. On RunCloud: open Web App, then NGINX Config. Apache and LiteSpeed need no change.', 'rankready-ai-llm-seo' ); ?></p>
			<textarea readonly rows="4" style="width:100%;max-width:560px;font-family:Menlo,Consolas,monospace;font-size:12px;padding:8px;"><?php echo esc_textarea( $snippet ); ?></textarea>
			<p><a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss this notice', 'rankready-ai-llm-seo' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Dismiss handler for the nginx /.well-known/ notice (per-user, forever).
	 */
	public static function handle_nginx_wk_dismiss(): void {
		if ( ! isset( $_GET['rnrd_dismiss_nginx_wk'] ) ) {
			return;
		}
		$nonce = isset( $_GET['_rnrd_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_rnrd_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'rnrd_dismiss_nginx_wk' ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), '_rnrd_nginx_wk_dismissed', 1 );
		wp_safe_redirect( remove_query_arg( array( 'rnrd_dismiss_nginx_wk', '_rnrd_nonce' ) ) );
		exit;
	}

	public static function permalink_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}
		if ( get_option( 'permalink_structure' ) ) {
			return; // Pretty permalinks are active — all good.
		}
		$permalink_url = admin_url( 'options-permalink.php' );
		echo '<div class="notice notice-warning is-dismissible"><p>'
			. '<strong>' . esc_html__( 'RankReady: Pretty Permalinks required', 'rankready-ai-llm-seo' ) . '</strong> — '
			. esc_html__( 'Your site is using plain permalinks (?p=123). RankReady\'s LLM endpoints (llms.txt, llms-full.txt, and per-post .md files) will return 404 until you enable pretty permalinks.', 'rankready-ai-llm-seo' )
			. ' <a href="' . esc_url( $permalink_url ) . '">'
			. esc_html__( 'Fix it in Settings → Permalinks →', 'rankready-ai-llm-seo' )
			. '</a>'
			. '</p></div>';
	}

	public static function connection_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}
		if ( empty( $_GET['rnrd_action'] ) || 'test' !== $_GET['rnrd_action'] ) {
			return;
		}
		if ( ! isset( $_GET[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Security check failed.', 'rankready-ai-llm-seo' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'rankready-ai-llm-seo' ) );
		}
		$result = RNRD_Generator::test_api_connection();
		if ( true === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Connection successful. The OpenAI API key is valid.', 'rankready-ai-llm-seo' )
				. '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'Connection failed: ', 'rankready-ai-llm-seo' )
				. esc_html( $result )
				. '</p></div>';
		}
	}

	// ── Plugin action links ───────────────────────────────────────────────────

	public static function action_links( array $links ): array {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">'
			. esc_html__( 'Settings', 'rankready-ai-llm-seo' )
			. '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	// ── Post list status column ───────────────────────────────────────────

	public static function add_status_column( array $columns ): array {
		$columns['rnrd_status'] = __( 'RankReady', 'rankready-ai-llm-seo' );
		return $columns;
	}

	public static function render_status_column( string $column, int $post_id ): void {
		if ( 'rnrd_status' !== $column ) {
			return;
		}

		$post_type = get_post_type( $post_id );

		$summary_types = array_values( array_filter( (array) get_option( RNRD_OPT_POST_TYPES, array( 'post' ) ) ) );
		$faq_types     = array_values( array_filter( (array) get_option( RNRD_OPT_FAQ_POST_TYPES, array( 'post' ) ) ) );

		$show_summary = ! empty( $summary_types ) && in_array( $post_type, $summary_types, true );
		$show_faq     = ! empty( $faq_types ) && in_array( $post_type, $faq_types, true );

		if ( ! $show_summary && ! $show_faq ) {
			echo '<span style="color:#999;">—</span>';
			return;
		}

		$summary  = get_post_meta( $post_id, RNRD_META_SUMMARY, true );
		$faq      = get_post_meta( $post_id, RNRD_META_FAQ, true );
		$disabled = get_post_meta( $post_id, RNRD_META_DISABLE, true );
		$faq_off  = get_post_meta( $post_id, RNRD_META_FAQ_DISABLE, true );

		$lines = array();

		if ( $show_summary ) {
			if ( $disabled ) {
				$lines[] = '<span style="color:#d63638;" title="' . esc_attr__( 'Disabled for this post', 'rankready-ai-llm-seo' ) . '">' . esc_html__( 'Summary: off', 'rankready-ai-llm-seo' ) . '</span>';
			} elseif ( ! empty( $summary ) ) {
				$lines[] = '<span style="color:#00a32a;" title="' . esc_attr__( 'Summary generated', 'rankready-ai-llm-seo' ) . '">' . esc_html__( 'Summary:', 'rankready-ai-llm-seo' ) . ' &#10003;</span>';
			} else {
				$lines[] = '<span style="color:#999;" title="' . esc_attr__( 'No summary yet', 'rankready-ai-llm-seo' ) . '">' . esc_html__( 'Summary: —', 'rankready-ai-llm-seo' ) . '</span>';
			}
		}

		if ( $show_faq ) {
			if ( $faq_off ) {
				$lines[] = '<span style="color:#d63638;" title="' . esc_attr__( 'Disabled for this post', 'rankready-ai-llm-seo' ) . '">' . esc_html__( 'FAQ: off', 'rankready-ai-llm-seo' ) . '</span>';
			} elseif ( ! empty( $faq ) ) {
				$lines[] = '<span style="color:#00a32a;" title="' . esc_attr__( 'FAQ generated', 'rankready-ai-llm-seo' ) . '">' . esc_html__( 'FAQ:', 'rankready-ai-llm-seo' ) . ' &#10003;</span>';
			} else {
				$lines[] = '<span style="color:#999;" title="' . esc_attr__( 'No FAQ yet', 'rankready-ai-llm-seo' ) . '">' . esc_html__( 'FAQ: —', 'rankready-ai-llm-seo' ) . '</span>';
			}
		}

		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
			$lines[] = '<span style="color:#dba617;" title="' . esc_attr__( 'Excluded from llms.txt, Markdown, OKF, and MCP', 'rankready-ai-llm-seo' ) . '">⚠ ' . esc_html__( 'Excluded from AI', 'rankready-ai-llm-seo' ) . '</span>';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- all values escaped above
		echo implode( '<br>', $lines );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Hard-exclude list shared by all post-type pickers in the plugin.
	 *
	 * These are built-in / system CPTs that should never appear in user-facing
	 * tabs (FAQ, Summary, Bulk Author Changer, etc.) regardless of their
	 * public / show_ui flags.
	 */
	private static function get_excluded_post_types(): array {
		return array(
			'attachment',           // Media — never used as content
			'nav_menu_item',        // Menu items
			'wp_block',             // Reusable blocks
			'wp_template',          // FSE templates
			'wp_template_part',     // FSE template parts
			'wp_navigation',        // FSE navigation
			'wp_global_styles',     // FSE global styles
			'revision',             // Post revisions
			'custom_css',           // Customizer CSS
			'customize_changeset',  // Customizer changesets
			'oembed_cache',         // oEmbed cache
			'user_request',         // Privacy requests
		);
	}

	/**
	 * Return all post types that should appear in user-facing pickers.
	 *
	 * Catches both `public => true` CPTs (front-end visible) AND private CPTs
	 * with `show_ui => true` (admin-visible only — common pattern for plugin
	 * CPTs like LearnDash quizzes, WooCommerce orders, custom internal types).
	 *
	 * Result format: `[ 'slug' => 'Label (slug)' ]`, sorted alphabetically by
	 * label so plugin CPTs don't get buried after `post`/`page`.
	 */
	public static function get_allowed_post_types(): array {
		// Free supports only the two baseline post types: post and page.
		// Custom Post Type support (including WooCommerce product) is a Pro
		// feature surfaced as Coming Soon. The Pro plugin unlocks it by hooking
		// the `rankready_allowed_post_types` filter and returning every public
		// CPT registered on the site.
		$excluded         = self::get_excluded_post_types();
		$free_allowlist   = array( 'post', 'page' );

		$result = array();
		$types  = get_post_types( array(), 'objects' );
		foreach ( $types as $slug => $obj ) {
			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}
			if ( empty( $obj->public ) && empty( $obj->show_ui ) ) {
				continue;
			}
			// FREE gate: only include the 3 baseline CPTs.
			// Pro overrides this entire return via the filter below.
			if ( ! in_array( $slug, $free_allowlist, true ) ) {
				continue;
			}
			$result[ $slug ] = $obj->labels->singular_name . ' (' . $slug . ')';
		}

		// Stable alphabetical sort by label so post/page/product
		// surface in a consistent order regardless of registration order.
		asort( $result, SORT_NATURAL | SORT_FLAG_CASE );

		/**
		 * Filter the post-type list shown in RankReady tabs.
		 *
		 * @since 1.1.0 — Free returns post/page/product only. Pro replaces
		 *   the entire array with every public CPT on the site. Third-party
		 *   integrations can also add to the list (e.g. a plugin that wants
		 *   its CPT included regardless of Pro status).
		 *
		 * @param array<string,string> $result slug => "Label (slug)"
		 */
		return apply_filters( 'rankready_allowed_post_types', $result );
	}

	/**
	 * Return all post types eligible for the Bulk Author Changer.
	 *
	 * Same broad detection as get_allowed_post_types() but additionally
	 * requires the type to support the `author` feature — without that,
	 * wp_update_post() can't reassign authors on it.
	 */
	public static function get_author_post_types(): array {
		$excluded = self::get_excluded_post_types();
		$result   = array();

		$types = get_post_types( array(), 'objects' );
		foreach ( $types as $slug => $obj ) {
			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}
			if ( empty( $obj->public ) && empty( $obj->show_ui ) ) {
				continue;
			}
			if ( ! post_type_supports( $slug, 'author' ) ) {
				continue;
			}
			$result[ $slug ] = $obj->labels->singular_name . ' (' . $slug . ')';
		}

		asort( $result, SORT_NATURAL | SORT_FLAG_CASE );

		/**
		 * Filter the post-type list shown in the Bulk Author Changer.
		 *
		 * @param array<string,string> $result slug => "Label (slug)"
		 */
		return apply_filters( 'rankready_author_post_types', $result );
	}

	public static function get_authors(): array {
		return get_users( array(
			'role__in' => array( 'administrator', 'editor', 'author', 'contributor' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'fields'   => array( 'ID', 'display_name', 'user_login' ),
		) );
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// v1.2.0-rc.7 — Universal locked-state pattern.
	// Every togglable card uses the same UX: header + toggle + goal line always
	// visible. When the master toggle is OFF, the card body shows a "locked
	// preview" — bullet list of what the feature delivers + an Enable button
	// that POSTs the toggle to 'on' via handle_quick_enable().
	// ═══════════════════════════════════════════════════════════════════════════

	/**
	 * v1.0.1 — Locked-preview pattern removed.
	 *
	 * Previously this rendered a "What you get when enabled" bullet list with
	 * a separate Enable button. The pattern added a redundant intermediate
	 * step: user had to click Enable, get redirected, then come back and click
	 * Save. Users repeatedly asked for the simpler flow — tick the master
	 * checkbox, hit the Save button at the bottom, done.
	 *
	 * Kept as a no-op so existing call sites (`if (!$enable) render_locked_preview(...)`)
	 * remain valid without me having to touch every callsite. The actual
	 * settings table that used to be in the `else` branch now always renders
	 * once the user is on the page. The master-toggle row at the top of every
	 * card still controls whether the rest of the settings collapse on save.
	 *
	 * @param array $config Unused. Kept for back-compat of existing callers.
	 */
	private static function render_locked_preview( array $config ): void {
		// Intentionally empty — no Enable button, no bullets, no preview card.
		// The card's master toggle checkbox + Save button is the entire flow now.
		unset( $config ); // Silence "unused parameter" linters.
	}

	/**
	 * Handles the Enable click from a locked-preview card.
	 *
	 * GET-based: the locked preview emits a nonce-protected link rather than
	 * a nested <form> (which would be invalid HTML inside the tab's outer
	 * settings form). Wired to admin_init so wp_safe_redirect() fires before
	 * any output. Validates: capability, per-option nonce, whitelisted option
	 * key. Then writes the option and redirects to ?rnrd_enabled=<key> for a
	 * one-time success notice.
	 */
	public static function handle_quick_enable(): void {
		if ( empty( $_GET['rnrd_enable_action'] ) || empty( $_GET['_rnrd_enable_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$option_key = sanitize_key( wp_unslash( $_GET['rnrd_enable_action'] ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce passed straight to wp_verify_nonce() with wp_unslash; rejected if invalid.
		$nonce_raw  = isset( $_GET['_rnrd_enable_nonce'] ) ? wp_unslash( $_GET['_rnrd_enable_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce_raw, 'rnrd_enable_' . $option_key ) ) {
			return;
		}
		// Whitelist — only these options can be flipped via the Enable buttons.
		$allowed = array(
			'rnrd_llms_enable',
			'rnrd_md_enable',
			'rnrd_robots_enable',
			'rnrd_content_signals_enable',
			'rnrd_mcp_enable',
			'rnrd_auto_generate',
			'rnrd_faq_auto_generate',
			'rnrd_schema_article',
			'rnrd_schema_faq',
			'rnrd_schema_howto',
			'rnrd_schema_itemlist',
			'rnrd_schema_speakable',
			'rnrd_ai_referral_enable',
			'rnrd_max_snippet_default',
			'rnrd_author_enable',
		);
		if ( ! in_array( $option_key, $allowed, true ) ) {
			return;
		}
		$value = isset( $_GET['rnrd_enable_value'] )
			? sanitize_text_field( wp_unslash( $_GET['rnrd_enable_value'] ) )
			: 'on';
		update_option( $option_key, $value );

		// Redirect back to the current admin page (drops nonce + action args),
		// keeping the active tab/sub and adding ?rnrd_enabled=<key> for the banner.
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
		$sub      = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : '';
		$args     = array(
			'page'         => self::MENU_SLUG,
			'tab'          => $tab,
			'rnrd_enabled' => $option_key,
		);
		if ( '' !== $sub ) {
			$args['sub'] = $sub;
		}
		$redirect = add_query_arg( $args, admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Human-readable labels for the quick-enable success banner.
	 * Maps whitelisted option key → translated feature name.
	 */
	private static function get_quick_enable_labels(): array {
		return array(
			'rnrd_llms_enable'             => __( 'LLMs.txt', 'rankready-ai-llm-seo' ),
			'rnrd_md_enable'               => __( 'Markdown Endpoints', 'rankready-ai-llm-seo' ),
			'rnrd_robots_enable'           => __( 'LLM Crawler Access (robots.txt)', 'rankready-ai-llm-seo' ),
			'rnrd_content_signals_enable'  => __( 'Content Signals', 'rankready-ai-llm-seo' ),
			'rnrd_mcp_enable'              => __( 'WebMCP Manifest', 'rankready-ai-llm-seo' ),
			'rnrd_auto_generate'           => __( 'AI Summary auto-generation', 'rankready-ai-llm-seo' ),
			'rnrd_faq_auto_generate'       => __( 'FAQ auto-generation', 'rankready-ai-llm-seo' ),
			'rnrd_schema_article'          => __( 'Article schema', 'rankready-ai-llm-seo' ),
			'rnrd_schema_faq'              => __( 'FAQPage schema', 'rankready-ai-llm-seo' ),
			'rnrd_schema_howto'            => __( 'HowTo schema', 'rankready-ai-llm-seo' ),
			'rnrd_schema_itemlist'         => __( 'ItemList schema', 'rankready-ai-llm-seo' ),
			'rnrd_schema_speakable'        => __( 'Speakable schema', 'rankready-ai-llm-seo' ),
			'rnrd_ai_referral_enable'      => __( 'AI Referral Tracking', 'rankready-ai-llm-seo' ),
			'rnrd_max_snippet_default'     => __( 'max-snippet:-1 default', 'rankready-ai-llm-seo' ),
			'rnrd_author_enable'           => __( 'Author Box (E-E-A-T)', 'rankready-ai-llm-seo' ),
		);
	}

	/**
	 * Renders the one-time success banner after a quick-enable redirect.
	 * Called from render_page() right after the tab nav.
	 */
	private static function render_quick_enable_banner(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only banner trigger based on quick-enable redirect query arg.
		if ( empty( $_GET['rnrd_enabled'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only banner trigger; not processing form data.
		$enabled_key = sanitize_key( wp_unslash( $_GET['rnrd_enabled'] ) );
		$labels      = self::get_quick_enable_labels();
		if ( ! isset( $labels[ $enabled_key ] ) ) {
			return;
		}
		$label = $labels[ $enabled_key ];
		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html( $label ),
			esc_html__( 'enabled. Scroll down to configure.', 'rankready-ai-llm-seo' )
		);
	}

	/**
	 * Translatable strings for assets/metabox.js.
	 *
	 * @return array<string, string>
	 */
	private static function metabox_js_i18n(): array {
		return array(
			'generate'         => __( 'Generate Summary', 'rankready-ai-llm-seo' ),
			'regenerate'       => __( 'Regenerate Summary', 'rankready-ai-llm-seo' ),
			'generating'       => __( 'Generating Summary…', 'rankready-ai-llm-seo' ),
			'regenerating'     => __( 'Regenerating Summary…', 'rankready-ai-llm-seo' ),
			'generateFaq'      => __( 'Generate FAQ', 'rankready-ai-llm-seo' ),
			'regenerateFaq'    => __( 'Regenerate FAQ', 'rankready-ai-llm-seo' ),
			'generatingFaq'    => __( 'Generating FAQ…', 'rankready-ai-llm-seo' ),
			'regeneratingFaq'  => __( 'Regenerating FAQ…', 'rankready-ai-llm-seo' ),
			/* translators: %d: seconds until regeneration is allowed */
			'regenIn'          => __( 'Regenerate available in %ds.', 'rankready-ai-llm-seo' ),
			/* translators: %d: seconds until regeneration is allowed */
			'regenInShort'     => __( 'You can regenerate again in %ds.', 'rankready-ai-llm-seo' ),
			'failed'           => __( 'Generation failed.', 'rankready-ai-llm-seo' ),
			'saveFirst'        => __( 'Save the post first, then generate.', 'rankready-ai-llm-seo' ),
			'generatedJust'    => __( 'Summary generated just now.', 'rankready-ai-llm-seo' ),
			'generatedFaqJust' => __( 'FAQ generated just now.', 'rankready-ai-llm-seo' ),
			'deleteSummary'    => __( 'Delete summary', 'rankready-ai-llm-seo' ),
			'deleteFaq'        => __( 'Delete FAQ', 'rankready-ai-llm-seo' ),
			'deleting'         => __( 'Deleting…', 'rankready-ai-llm-seo' ),
			'deletedSummary'   => __( 'Summary removed.', 'rankready-ai-llm-seo' ),
			'deletedFaq'       => __( 'FAQ removed.', 'rankready-ai-llm-seo' ),
			'deleteFailed'     => __( 'Could not delete. Try again.', 'rankready-ai-llm-seo' ),
			'confirmSummary'   => __( 'Remove the generated summary for this post? It will no longer appear on the frontend, in Markdown, or in schema.', 'rankready-ai-llm-seo' ),
			'confirmFaq'       => __( 'Remove the generated FAQ for this post? It will no longer appear on the frontend, in Markdown, or in schema.', 'rankready-ai-llm-seo' ),
		);
	}

	/**
	 * Translatable strings for assets/admin.js.
	 *
	 * @return array<string, string>
	 */
	private static function admin_js_i18n(): array {
		return array(
		'modelsUpdatedOne'              => __( '1 model updated', 'rankready-ai-llm-seo' ),
		/* translators: %d: number of models loaded */
		'modelsUpdatedMany'             => __( '%d models updated', 'rankready-ai-llm-seo' ),
		'minOnePostType'                => __( 'Select at least one post type.', 'rankready-ai-llm-seo' ),
		'selectTargetAuthor'            => __( 'Select a target author (To).', 'rankready-ai-llm-seo' ),
		'checking'                      => __( 'Checking…', 'rankready-ai-llm-seo' ),
		'previewCount'                  => __( 'Preview Count', 'rankready-ai-llm-seo' ),
		'running'                       => __( 'Running…', 'rankready-ai-llm-seo' ),
		'starting'                      => __( 'Starting…', 'rankready-ai-llm-seo' ),
		'resuming'                      => __( 'Resuming…', 'rankready-ai-llm-seo' ),
		'execute'                       => __( 'Execute', 'rankready-ai-llm-seo' ),
		'stopped'                       => __( 'Stopped.', 'rankready-ai-llm-seo' ),
		'unknownError'                  => __( 'Unknown error', 'rankready-ai-llm-seo' ),
		'previewRequestFailed'          => __( 'Preview request failed.', 'rankready-ai-llm-seo' ),
		'failedToStart'                 => __( 'Failed to start.', 'rankready-ai-llm-seo' ),
		'requestFailed'                 => __( 'Request failed.', 'rankready-ai-llm-seo' ),
		'noMatchingPosts'               => __( 'No matching posts found.', 'rankready-ai-llm-seo' ),
		'noPublishedPosts'              => __( 'No published posts found.', 'rankready-ai-llm-seo' ),
		'loading'                       => __( 'Loading…', 'rankready-ai-llm-seo' ),
		'refreshing'                    => __( 'Refreshing…', 'rankready-ai-llm-seo' ),
		'verifying'                     => __( 'Verifying…', 'rankready-ai-llm-seo' ),
		'verifyKey'                     => __( 'Verify Key', 'rankready-ai-llm-seo' ),
		'verifyDfs'                     => __( 'Verify DataForSEO', 'rankready-ai-llm-seo' ),
		'refreshList'                   => __( 'Refresh list', 'rankready-ai-llm-seo' ),
		'couldNotRefreshModels'         => __( 'Could not refresh models.', 'rankready-ai-llm-seo' ),
		'saving'                        => __( 'Saving…', 'rankready-ai-llm-seo' ),
		'saved'                         => __( 'Saved ✓', 'rankready-ai-llm-seo' ),
		'saveFailed'                    => __( 'Save failed', 'rankready-ai-llm-seo' ),
		'notSavedReload'                => __( 'Not saved — reload page', 'rankready-ai-llm-seo' ),
		'clearCache'                    => __( 'Clear cache', 'rankready-ai-llm-seo' ),
		'clearing'                      => __( 'Clearing…', 'rankready-ai-llm-seo' ),
		'cacheCleared'                  => __( 'Cache cleared.', 'rankready-ai-llm-seo' ),
		/* translators: %1$d: completed count, %2$d: total count, %3$d: percent complete */
		'postsUpdatedProgress'          => __( '%1$d / %2$d posts updated (%3$d%%)', 'rankready-ai-llm-seo' ),
		'postsReassignedOne'            => __( 'Done! 1 post reassigned.', 'rankready-ai-llm-seo' ),
		/* translators: %d: number of posts reassigned */
		'postsReassignedMany'           => __( 'Done! %d posts reassigned.', 'rankready-ai-llm-seo' ),
		'retryIn3s'                     => __( 'Request failed — retrying in 3s…', 'rankready-ai-llm-seo' ),
		'retryIn5s'                     => __( 'Request failed — retrying in 5s…', 'rankready-ai-llm-seo' ),
		'confirmReassignAuthors'        => __( 'This will permanently reassign post authors. Continue?', 'rankready-ai-llm-seo' ),
		'errorPrefix'                   => __( 'Error:', 'rankready-ai-llm-seo' ),
		'refreshListBtn'                => __( 'Refresh List', 'rankready-ai-llm-seo' ),
		'loadFaqPosts'                  => __( 'Load FAQ Posts', 'rankready-ai-llm-seo' ),
		'noFaqPosts'                    => __( 'No posts with FAQ found.', 'rankready-ai-llm-seo' ),
		'faqPostsOne'                   => __( '1 post with FAQ', 'rankready-ai-llm-seo' ),
		/* translators: %d: number of posts */
		'faqPostsMany'                  => __( '%d posts with FAQ', 'rankready-ai-llm-seo' ),
		'failedToLoad'                  => __( 'Failed to load.', 'rankready-ai-llm-seo' ),
		'editFaq'                       => __( 'Edit FAQ', 'rankready-ai-llm-seo' ),
		'editPost'                      => __( 'Edit Post', 'rankready-ai-llm-seo' ),
		'edit'                          => __( 'Edit', 'rankready-ai-llm-seo' ),
		'view'                          => __( 'View', 'rankready-ai-llm-seo' ),
		/* translators: %s: post title */
		'editFaqTitle'                  => __( 'Edit FAQ — %s', 'rankready-ai-llm-seo' ),
		'saveChanges'                   => __( 'Save Changes', 'rankready-ai-llm-seo' ),
		'remove'                        => __( 'Remove', 'rankready-ai-llm-seo' ),
		'noFaqData'                     => __( 'No FAQ data found.', 'rankready-ai-llm-seo' ),
		'savedExclaim'                  => __( 'Saved!', 'rankready-ai-llm-seo' ),
		'refreshDetails'                => __( 'Refresh Details', 'rankready-ai-llm-seo' ),
		'loadPerPostDetails'            => __( 'Load Per-Post Details', 'rankready-ai-llm-seo' ),
		'noTokenUsage'                  => __( 'No token usage recorded yet.', 'rankready-ai-llm-seo' ),
		/* translators: %1$d: post count, %2$s: formatted token total */
		'tokenUsageSummary'             => __( '%1$d posts | %2$s total tokens', 'rankready-ai-llm-seo' ),
		/* translators: %s: formatted token total */
		'tokenUsageOne'                 => __( '1 post | %s total tokens', 'rankready-ai-llm-seo' ),
		'refreshLog'                    => __( 'Refresh Log', 'rankready-ai-llm-seo' ),
		'loadErrorLog'                  => __( 'Load Error Log', 'rankready-ai-llm-seo' ),
		'noErrorsLogged'                => __( 'No errors logged.', 'rankready-ai-llm-seo' ),
		'logCleared'                    => __( 'Log cleared.', 'rankready-ai-llm-seo' ),
		'errorsOne'                     => __( '1 error', 'rankready-ai-llm-seo' ),
		/* translators: %d: error count */
		'errorsMany'                    => __( '%d errors', 'rankready-ai-llm-seo' ),
		'startOverBulk'                 => __( 'Start Over — Bulk Regenerate', 'rankready-ai-llm-seo' ),
		/* translators: %1$d: done count, %2$d: total count */
		'bulkRegenDone'                 => __( 'Done! %1$d/%2$d posts regenerated.', 'rankready-ai-llm-seo' ),
		/* translators: %1$d: done count, %2$d: total count, %3$d: percent */
		'bulkProgress'                  => __( '%1$d / %2$d (%3$d%%)', 'rankready-ai-llm-seo' ),
		'freshnessStaleEmpty'           => __( 'No stale posts. Every published post has been touched within the last 60 days.', 'rankready-ai-llm-seo' ),
		'freshnessGoingStaleEmpty'      => __( 'Nothing in the 30–60 day window. Plenty of time before any post goes stale.', 'rankready-ai-llm-seo' ),
		'freshnessFreshEmpty'           => __( 'Newly published or refreshed content shows up here.', 'rankready-ai-llm-seo' ),
		'noTitle'                       => __( '(no title)', 'rankready-ai-llm-seo' ),
		/* translators: %d: number of posts */
		'freshnessRefreshing'           => __( 'Refreshing %d post(s)…', 'rankready-ai-llm-seo' ),
		/* translators: %d: number of posts refreshed */
		'freshnessRefreshed'            => __( 'Refreshed %d post(s). Reloading…', 'rankready-ai-llm-seo' ),
		'refreshFailed'                 => __( 'Refresh failed.', 'rankready-ai-llm-seo' ),
		'cfTokenRequired'               => __( 'A Cloudflare API token is required.', 'rankready-ai-llm-seo' ),
		'cfConnecting'                  => __( 'Connecting…', 'rankready-ai-llm-seo' ),
		'cfRuleCreated'                 => __( 'Rule created. Reloading…', 'rankready-ai-llm-seo' ),
		'cfConnectFailed'               => __( 'Failed to connect.', 'rankready-ai-llm-seo' ),
		'cfNetworkError'                => __( 'Network error.', 'rankready-ai-llm-seo' ),
		'cfDisconnectConfirm'           => __( 'Remove the Cloudflare cache rule? AI markdown requests will hit APO again.', 'rankready-ai-llm-seo' ),
		'runDiagnostics'                => __( 'Run Diagnostics', 'rankready-ai-llm-seo' ),
		'probingEndpoints'              => __( 'Probing endpoints…', 'rankready-ai-llm-seo' ),
		'noResults'                     => __( 'No results.', 'rankready-ai-llm-seo' ),
		'diagnosticsCompleted'          => __( 'Completed.', 'rankready-ai-llm-seo' ),
		'copiedToClipboard'             => __( 'Copied to clipboard', 'rankready-ai-llm-seo' ),
		'copyFailedManual'              => __( 'Copy failed — select text manually from preview below.', 'rankready-ai-llm-seo' ),
		'reportGenerationFailed'        => __( 'Report generation failed. Please try again.', 'rankready-ai-llm-seo' ),
		'fixLabel'                      => __( 'Fix:', 'rankready-ai-llm-seo' ),
		'scanContentFreshness'          => __( 'Scan Content Freshness', 'rankready-ai-llm-seo' ),
		'scanning'                      => __( 'Scanning…', 'rankready-ai-llm-seo' ),
		'allContentFresh'               => __( 'All content is fresh.', 'rankready-ai-llm-seo' ),
		/* translators: %1$d: completed count, %2$d: total count */
		'stoppedAtProgress'             => __( 'Stopped at %1$d / %2$d.', 'rankready-ai-llm-seo' ),
		/* translators: %d: remaining queue count */
		'queueRemainingResume'          => __( '%d remaining — click Resume to continue.', 'rankready-ai-llm-seo' ),
		'freshnessKpiLabel'             => __( 'Content fresh', 'rankready-ai-llm-seo' ),
		'freshnessKpiPeriod'            => __( 'Share of catalog', 'rankready-ai-llm-seo' ),
		/* translators: %d: day threshold */
		'freshnessKpiFoot'              => __( 'last modified within %d days', 'rankready-ai-llm-seo' ),
		'stalePostsLabel'               => __( 'Stale posts', 'rankready-ai-llm-seo' ),
		/* translators: %d: day threshold */
		'stalePostsPeriod'              => __( 'Over %d days old', 'rankready-ai-llm-seo' ),
		'stalePostsFoot'                => __( 'needs a refresh for AI citations', 'rankready-ai-llm-seo' ),
		'totalPublishedLabel'           => __( 'Total published', 'rankready-ai-llm-seo' ),
		'totalPublishedPeriod'          => __( 'All post types', 'rankready-ai-llm-seo' ),
		'totalPublishedFoot'            => __( 'indexed for freshness scan', 'rankready-ai-llm-seo' ),
		/* translators: %d: number of stale posts */
		'stalePostsFound'               => __( '%d stale posts found (showing top 50)', 'rankready-ai-llm-seo' ),
	);
	}
}
