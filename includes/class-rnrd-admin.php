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
		add_action( 'admin_init',            array( self::class, 'maybe_redirect_legacy_tab' ) );
		add_action( 'admin_init',            array( self::class, 'register_settings' ) );
		add_filter( 'admin_title',           array( self::class, 'filter_admin_title' ), 10, 2 );

		// NOTE — the add_/update_option hooks that derive the two legacy crawler
		// arrays from the Allow/Default/Block map live in RNRD_Llms_Txt::init(),
		// not here. That class is always loaded, so the derive also fires for
		// WP-CLI and REST writes; registering it in this admin-only class left
		// the arrays stale for every non-admin writer.
		add_action( 'admin_init',            array( self::class, 'handle_dismiss_actions' ) );
		add_action( 'admin_init',            array( self::class, 'handle_dash_tips_optin' ) );
		add_action( 'admin_init',            array( self::class, 'track_installed_version' ) );
		add_action( 'admin_notices',         array( self::class, 'permalink_notice' ) );
		add_action( 'admin_notices',         array( self::class, 'tips_result_notice' ) );
		// v1.2.1 — proactive nginx /.well-known/ 403 notice (WebMCP manifest blocked).
		add_action( 'admin_notices',         array( self::class, 'maybe_nginx_wellknown_notice' ) );
		add_action( 'admin_init',            array( self::class, 'handle_nginx_wk_dismiss' ) );
		// v1.1.5 — strip third-party admin notices (other SEO plugins' rating nags,
		// "deactivate me" warnings, Action Scheduler alerts, etc.) that WordPress
		// dumps onto every admin page. Scoped strictly to two of our own screens,
		// never any other admin page. Standard pattern used by Yoast / Rank Math /
		// WooCommerce. On the settings screen RankReady's own RNRD_ notices are
		// kept; on the onboarding wizard everything goes, including ours, because
		// that screen does not load admin.css. Reasons in the method.
		add_action( 'in_admin_header',       array( self::class, 'declutter_admin_notices' ), 1 );
		// model_migration_notice is rendered inline inside render_tab_settings()
		// (not via admin_notices) so it appears in the Settings tab body where
		// the user actually picks the model — not as a global page hijack.
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin_assets' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_deactivation_assets' ) );
		add_filter( 'plugin_action_links_' . RNRD_BASENAME, array( self::class, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( self::class, 'row_meta' ), 10, 2 );

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
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		/*
		 * Gated on this method's own marker, NOT on rnrd_installed_version.
		 * rnrd_maybe_upgrade() runs on plugins_loaded and stamps that option
		 * first, so by the time admin_init reaches here on a normal update it
		 * already reads as current, and gating on it meant the saved-model
		 * rewrite and its notice never ran for a site that updated in one
		 * request. The marker below is written only after the loop has run.
		 *
		 * Everything below is upgrade work, so a site whose marker is already
		 * on this version has nothing left to do and takes no further cost:
		 * the marker is autoloaded, so this is a read from the options cache.
		 * RNRD_LLM::get_model() applies migrate_model() at call time as well,
		 * which is what actually protects a request in the meantime.
		 */
		if ( RNRD_VERSION === (string) get_option( 'rnrd_model_migration_ver', '' ) ) {
			return;
		}

		if ( ! class_exists( 'RNRD_LLM' ) ) {
			/*
			 * Return WITHOUT stamping. RNRD_LLM is autoloaded (see the
			 * spl_autoload_register in the main plugin file), so class_exists()
			 * only fails when includes/class-rnrd-llm.php is genuinely absent:
			 * a half-finished upload or a partially restored backup. Stamping
			 * here would record "already migrated" for a version whose
			 * migration never ran, and the guard above then skips it forever
			 * once the file comes back. Retrying costs one autoloaded
			 * get_option() and one class_exists() per admin page load, which
			 * is cheaper than a saved model silently left on a retired ID.
			 */
			return;
		}

		$option_for = array(
			'openai'    => RNRD_OPT_MODEL,
			'anthropic' => RNRD_OPT_ANTHROPIC_MODEL,
			'gemini'    => RNRD_OPT_GEMINI_MODEL,
			'deepseek'  => RNRD_OPT_DEEPSEEK_MODEL,
		);

		/*
		 * Locked, and returning without stamping on failure for the same
		 * reason as the two guards above. The notice queue is a
		 * read-modify-write: two requests arriving on the same upgrade each
		 * read it, each append their own lines, and the second write drops the
		 * first set, so the admin is told about some of the model changes made
		 * under them and not the rest. The migration beside it also rewrites
		 * four provider options, so running it twice is wasted work on top.
		 *
		 * A request that cannot take the lock leaves the work to the next page
		 * load rather than racing.
		 */
		$mig_token = null;
		if ( class_exists( 'RNRD_Atomic' )
			&& ! RNRD_Atomic::acquire_lock( 'rnrd_model_migration_lock', 60, $mig_token ) ) {
			return;
		}

		// The other holder's queue landed in the database, not in this
		// request's option cache.
		wp_cache_delete( 'rnrd_model_migrations', 'options' );

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

		/*
		 * Stamped last, not first: a fatal anywhere in the loop above leaves
		 * the marker old, so the next admin load retries. rnrd_installed_version
		 * is deliberately left alone. It is the upgrade routine's "done"
		 * signal (rnrd_maybe_upgrade() in rankready.php, under
		 * rnrd_upgrade_lock), and this method holds a different lock, so a
		 * stamp from here could land while another request was still part-way
		 * through that routine and the rest of its migrations would never run.
		 */
		update_option( 'rnrd_model_migration_ver', RNRD_VERSION, true );

		if ( null !== $mig_token ) {
			RNRD_Atomic::release_lock( 'rnrd_model_migration_lock', $mig_token );
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
		// The webhook can fail (offline host, bad email, endpoint down). Report what
		// actually happened instead of always saying it worked.
		// A stale tab or a double submit after opting in elsewhere is already a success.
		$sent = class_exists( 'RNRD_Welcome' )
			&& ( RNRD_Welcome::tips_optin_done() || RNRD_Welcome::subscribe_email( $email, $fname, 'RankReady Dashboard' ) );

		// F15: the result is stored for this user and read once, so a hand-typed
		// ?rnrd_tips=ok URL can no longer paint a success notice.
		set_transient( 'rnrd_tips_result_' . get_current_user_id(), $sent ? 'ok' : 'fail', 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * Result of the dashboard tips opt-in, shown once on the dashboard after the
	 * redirect. Read-only display of a fixed two-value flag, so no nonce is needed.
	 */
	public static function tips_result_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}
		$result_key = 'rnrd_tips_result_' . get_current_user_id();
		$result     = get_transient( $result_key );
		$result     = is_string( $result ) ? $result : '';
		if ( '' !== $result ) {
			delete_transient( $result_key );
		}
		if ( 'ok' === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'You are on the list. The first tips email will reach you soon.', 'rankready-ai-llm-seo' )
				. '</p></div>';
		} elseif ( 'fail' === $result ) {
			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'We could not add that email just now. Check the address and try again in a minute.', 'rankready-ai-llm-seo' )
				. '</p></div>';
		}
	}

	// ── Status columns (deferred to wp_loaded so CPTs exist) ─────────────────

	public static function register_status_columns(): void {
		/*
		 * GitHub #54. Only the types RankReady can act on get the column: post
		 * and page in Free, plus the content types Pro adds through
		 * `rankready_allowed_post_types`. Every public type used to get it, so
		 * WooCommerce products and Elementor templates showed a RankReady
		 * column on a site that could never generate for them.
		 */
		foreach ( array_keys( self::get_allowed_post_types() ) as $pt ) {
			add_filter( "manage_{$pt}_posts_columns",       array( self::class, 'add_status_column' ) );
			add_action( "manage_{$pt}_posts_custom_column",  array( self::class, 'render_status_column' ), 10, 2 );
		}
		add_action( 'admin_head-edit.php', array( self::class, 'print_status_column_css' ) );
	}

	/**
	 * Give the list column a width (GitHub #55).
	 *
	 * List tables are `table-layout: fixed`, so a column with no declared width
	 * gets whatever is left over. With WooCommerce's product columns that was
	 * close to zero and the status text collapsed. admin.css does not load on
	 * edit.php (see enqueue_admin_assets()), so the rule is printed here, and
	 * only on list screens that actually carry the column.
	 */
	public static function print_status_column_css(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! array_key_exists( (string) $screen->post_type, self::get_allowed_post_types() ) ) {
			return;
		}
		wp_register_style( 'rnrd-status-column', false, array(), RNRD_VERSION );
		wp_add_inline_style( 'rnrd-status-column', '.wp-list-table .column-rnrd_status{width:9.5em;}' );
		wp_print_styles( 'rnrd-status-column' );
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

	/**
	 * The deactivation modal, on plugins.php only.
	 *
	 * Deliberately NOT folded into enqueue_admin_assets(). That method scopes
	 * the 147KB admin.css to two screen groups on purpose, and plugins.php
	 * shows no RankReady UI at all. This pair is a few KB and loads only where
	 * the modal can actually open, and only for users who will actually see it:
	 * the survey is free-only (see the show_deactivation_feedback_form filter
	 * in rankready.php), so a paying user gets neither the form nor its assets.
	 */
	public static function enqueue_deactivation_assets( $hook ): void {
		if ( 'plugins.php' !== $hook ) {
			return;
		}

		// Mirror the survey's own condition. If these ever disagree, the
		// stylesheet is the harmless side of the mismatch, but the JS would
		// inject a note into a form nobody is being shown.
		if ( function_exists( 'rnrd_fs' ) ) {
			$fs = rnrd_fs();
			if ( is_object( $fs ) && method_exists( $fs, 'is_paying' ) &&
				( $fs->is_paying() || $fs->is_trial() ) ) {
				return;
			}
		}

		wp_enqueue_style(
			'rnrd-deactivate',
			RNRD_URL . 'assets/deactivate.css',
			array(),
			self::asset_ver( 'assets/deactivate.css' )
		);

		wp_enqueue_script(
			'rnrd-deactivate',
			RNRD_URL . 'assets/deactivate.js',
			array(),
			self::asset_ver( 'assets/deactivate.js' ),
			true
		);

		/*
		 * Says what actually happens, in this order: the answer is optional,
		 * it goes to us, and an email address is only attached if they leave
		 * the anonymous box unticked. No promise about what we do next beyond
		 * "we may reply", because a deactivation is not consent to a mailing
		 * list and saying otherwise would be the kind of thing that earns a
		 * one-star review.
		 */
		wp_localize_script(
			'rnrd-deactivate',
			'rnrdDeactivate',
			array(
				'note' => wp_kses(
					__( '<strong>Answering is optional.</strong> Your reason goes to the RankReady team. If you leave "Anonymous feedback" unticked your email comes with it, and we may reply to ask what went wrong. We will not add you to any mailing list.', 'rankready-ai-llm-seo' ),
					array( 'strong' => array() )
				),
			)
		);
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
		// The Upgrade Pro screen is registered with a null parent, so its hook is
		// `admin_page_rankready-pro-upgrade`, not the toplevel one. Named
		// explicitly rather than matched on a loose "rankready" substring, which
		// would start loading a 147KB stylesheet on any future screen that happens
		// to contain the word.
		$is_settings  = ( 'toplevel_page_' . self::MENU_SLUG === $hook )
			|| ( 'admin_page_rankready-pro-upgrade' === $hook );
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
			"      function done(parts){\n" .
			"        var data = parts[1] || {};\n" .
			"        if (parts[0] >= 200 && parts[0] < 300 && data.success) { location.reload(); return; }\n" .
			"        btnDisco.disabled = false;\n" .
			"        setMsg(data.error || t('cfDisconnectFailed','Cloudflare did not remove the rule.'), false);\n" .
			"        if (confirm(t('cfDisconnectForce','Disconnect RankReady anyway? The cache rule stays in Cloudflare until you delete it there.'))) {\n" .
			"          btnDisco.disabled = true;\n" .
			"          apiCall('/cloudflare/disconnect', { force: true }).then(function(p){\n" .
			"            if (p[0] >= 200 && p[0] < 300 && p[1] && p[1].success) { location.reload(); return; }\n" .
			"            btnDisco.disabled = false;\n" .
			"            setMsg((p[1] && p[1].error) || t('cfDisconnectFailed','Cloudflare did not remove the rule.'), false);\n" .
			"          }).catch(function(){ btnDisco.disabled = false; setMsg(t('cfNetworkError','Network error.'), false); });\n" .
			"        }\n" .
			"      }\n" .
			"      apiCall('/cloudflare/disconnect', {}).then(done).catch(function(){ btnDisco.disabled = false; setMsg(t('cfNetworkError','Network error.'), false); });\n" .
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
			'default'           => 'claude-haiku-5-5',
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

		/*
		 * Auto-latest. Registered in SETTINGS_GROUP because the checkbox sits in
		 * the AI card, which posts that group. Stored as '1'/'0' rather than a
		 * bool so an unchecked box (which posts nothing) is distinguishable from
		 * an install that has never seen the setting: the paired hidden input in
		 * the markup always posts a value.
		 */
		register_setting( self::SETTINGS_GROUP, RNRD_LLM::OPT_AUTO_LATEST, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_bool_flag' ),
			'default'           => '1',
		) );

		// DeepSeek key + model.
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_DEEPSEEK_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_deepseek_key' ),
			'default'           => '',
		) );
		// 'deepseek-flash' not 'deepseek-v4-flash': the latter is the legacy name
		// and this default had drifted away from RNRD_LLM::get_model()'s, so the
		// two disagreed about what an unsaved install is running.
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_DEEPSEEK_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_provider_model' ),
			'default'           => 'deepseek-flash',
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
			'sanitize_callback' => array( self::class, 'sanitize_pro_on_off' ),
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
			// F9: a heading label, not a paragraph. Cap at 60 characters.
			'sanitize_callback' => static function ( $v ) {
				$v = sanitize_text_field( (string) $v );
				return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 60 ) : substr( $v, 0, 60 );
			},
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
			// Site name is a short label: 100 characters, enforced server-side.
			'sanitize_callback' => static function ( $v ) {
				$v = sanitize_text_field( (string) $v );
				return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 100 ) : substr( $v, 0, 100 );
			},
			'default'           => '',
		) );

		register_setting( self::BRAND_GROUP, RNRD_OPT_LLMS_SUMMARY, array(
			'type'              => 'string',
			// F9: the field is documented as one short line. Enforce 160 characters server-side.
			'sanitize_callback' => static function ( $v ) {
				// One line: it is printed inside a Markdown blockquote, where a line
				// break would end the quote and leak the rest as body text.
				$v = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_textarea_field( (string) $v ) ) );
				return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 160 ) : substr( $v, 0, 160 );
			},
			'default'           => '',
		) );

		register_setting( self::BRAND_GROUP, RNRD_OPT_LLMS_ABOUT, array(
			'type'              => 'string',
			// F9: cap at 500 characters server-side.
			'sanitize_callback' => static function ( $v ) {
				$v = sanitize_textarea_field( (string) $v );
				return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 500 ) : substr( $v, 0, 500 );
			},
			'default'           => '',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => function ( $v ) { return self::sanitize_post_types_keep_stored( $v, RNRD_OPT_LLMS_POST_TYPES ); },
			'default'           => array( 'post', 'page' ),
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_MAX_POSTS, array(
			'type'              => 'integer',
			// F9: clamp to the 10-500 range the field advertises. A negative value is
			// clamped, never turned positive; an empty or non-numeric value keeps the default.
			'sanitize_callback' => function ( $v ) {
				if ( '' === trim( (string) $v ) || ! is_numeric( $v ) ) {
					return 100;
				}
				return max( 10, min( 500, (int) $v ) );
			},
			'default'           => 100,
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_CACHE_TTL, array(
			'type'              => 'integer',
			'sanitize_callback' => function ( $v ) { return min( 10000, absint( $v ) ); }, // 0 = limit off, as the field help says.
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
			'sanitize_callback' => function ( $v ) { return self::sanitize_post_types_keep_stored( $v, RNRD_OPT_MD_POST_TYPES ); },
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
			// Brand terms list: 200 characters, enforced server-side.
			'sanitize_callback' => static function ( $v ) {
				$v = sanitize_textarea_field( (string) $v );
				return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 200 ) : substr( $v, 0, 200 );
			},
			'default'           => '',
		) );

		// v1.2.0 — Hide "Generated from RankReady" credit line in llms.txt / llms-full.txt (Pro).
		register_setting( self::LLMS_GROUP, RNRD_OPT_HIDE_BRANDING, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_pro_on_off' ),
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
				// F9: empty / 0 / non-numeric (the pre-1.1.0 cross-nulling bug) means "use
				// default". Anything else is clamped into 3-10; a negative value is no
				// longer folded to its absolute value.
				// 1.4.1: only empty / non-numeric input falls back to 5; any number (0 and 99 included) clamps into 3-10, as the label says.
				if ( ! is_numeric( $v ) ) {
					return 5;
				}
				return max( 3, min( 10, (int) $v ) );
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
			'sanitize_callback' => array( self::class, 'sanitize_pro_on_off' ),
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
			'sanitize_callback' => function ( $v ) { return min( 10000, absint( $v ) ); }, // 0 = limit off, as the field help says.
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
			'sanitize_callback' => function ( $v ) { return min( 10000, absint( $v ) ); }, // 0 = limit off, as the field help says.
			'default'           => 120,
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_REVALIDATE_URL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_revalidate_url' ),
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
		// The control is a textarea, so accept commas, spaces and new lines.
		$parts    = preg_split( '/[\s,]+/', trim( $value ) );
		$valid    = array();
		$rejected = array();
		foreach ( $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			// An origin is scheme://host[:port] and nothing else: no path, query,
			// credentials or fragment. Garbage and non-http schemes are dropped.
			$parts = wp_parse_url( $p );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] )
				|| isset( $parts['user'] ) || isset( $parts['pass'] )
				|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
				|| ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $parts['host'] ) ) {
				$rejected[] = $p;
				continue;
			}
			$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
			if ( ! empty( $parts['port'] ) ) {
				$origin .= ':' . (int) $parts['port'];
			}
			$valid[] = $origin;
		}
		if ( $rejected ) {
			add_settings_error(
				RNRD_OPT_HEADLESS_CORS_ORIGINS,
				'rnrd_cors_rejected',
				sprintf(
					/* translators: %s: comma-separated list of rejected values. */
					__( 'Ignored these allowed origins because they are not in the form https://example.com: %s', 'rankready-ai-llm-seo' ),
					esc_html( implode( ', ', array_map( 'sanitize_text_field', $rejected ) ) )
				),
				'warning'
			);
			if ( ! $valid ) {
				add_settings_error(
					RNRD_OPT_HEADLESS_CORS_ORIGINS,
					'rnrd_cors_empty',
					__( 'No valid origin was left, so the list is empty and every origin is allowed. Add at least one origin such as https://www.example.com to restrict access.', 'rankready-ai-llm-seo' ),
					'warning'
				);
			}
		}
		return implode( ',', array_unique( $valid ) );
	}

	/** 1.4.1 — refuse private, loopback and link-local webhook targets, with a visible message. */
	public static function sanitize_revalidate_url( $value ): string {
		$url = esc_url_raw( trim( (string) $value ) );
		if ( '' === $url ) {
			return '';
		}
		if ( ! RNRD_Util::is_public_webhook_url( $url ) ) {
			add_settings_error(
				RNRD_OPT_HEADLESS_REVALIDATE_URL,
				'rnrd_webhook_private',
				__( 'Revalidation URL rejected: it points at a private, loopback or link-local address, or a host that does not resolve. Use a public https URL. The previous value was kept.', 'rankready-ai-llm-seo' ),
				'error'
			);
			return (string) get_option( RNRD_OPT_HEADLESS_REVALIDATE_URL, '' );
		}
		return $url;
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
		if ( '__UNCHANGED__' === $value ) {
			return (string) get_option( RNRD_OPT_KEY, '' );
		}
		if ( false !== strpos( $value, '••••' ) ) {
			// Only the exact, untouched mask means "unchanged". Anything typed
			// after the bullets is a replacement key and is saved.
			$tail = trim( (string) preg_replace( '/^.*•{4,}/us', '', $value ) );
			if ( '' === $tail ) {
				return (string) get_option( RNRD_OPT_KEY, '' );
			}
			$value = $tail;
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
		if ( '__UNCHANGED__' === $value ) {
			return (string) get_option( RNRD_OPT_ANTHROPIC_KEY, '' );
		}
		if ( false !== strpos( $value, '••••' ) ) {
			// Only the exact, untouched mask means "unchanged". Anything typed
			// after the bullets is a replacement key and is saved.
			$tail = trim( (string) preg_replace( '/^.*•{4,}/us', '', $value ) );
			if ( '' === $tail ) {
				return (string) get_option( RNRD_OPT_ANTHROPIC_KEY, '' );
			}
			$value = $tail;
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
		if ( '__UNCHANGED__' === $value ) {
			return (string) get_option( RNRD_OPT_GEMINI_KEY, '' );
		}
		if ( false !== strpos( $value, '••••' ) ) {
			// Only the exact, untouched mask means "unchanged". Anything typed
			// after the bullets is a replacement key and is saved.
			$tail = trim( (string) preg_replace( '/^.*•{4,}/us', '', $value ) );
			if ( '' === $tail ) {
				return (string) get_option( RNRD_OPT_GEMINI_KEY, '' );
			}
			$value = $tail;
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
		if ( '__UNCHANGED__' === $value ) {
			return (string) get_option( RNRD_OPT_DEEPSEEK_KEY, '' );
		}
		if ( false !== strpos( $value, '••••' ) ) {
			// Only the exact, untouched mask means "unchanged". Anything typed
			// after the bullets is a replacement key and is saved.
			$tail = trim( (string) preg_replace( '/^.*•{4,}/us', '', $value ) );
			if ( '' === $tail ) {
				return (string) get_option( RNRD_OPT_DEEPSEEK_KEY, '' );
			}
			$value = $tail;
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
	 * Checkbox flag sanitizer: anything truthy becomes '1', everything else '0'.
	 */
	public static function sanitize_bool_flag( $value ): string {
		return ( '1' === (string) $value || 'on' === (string) $value || 'true' === (string) $value ) ? '1' : '0';
	}

	/**
	 * Model ID sanitizer for every LLM provider (OpenAI, Anthropic, Gemini, DeepSeek).
	 * Light validation only, no hard allowlist, so curated and future model IDs
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
		$clean   = self::gate_pro_post_types( $clean );
		return ! empty( $clean ) ? $clean : array( 'post' );
	}

	/**
	 * Option name behind the sanitize_option_{$option} filter currently running.
	 * Lets one shared callback know which stored value it is guarding.
	 */
	private static function option_from_filter(): string {
		$filter = current_filter();
		return 0 === strpos( $filter, 'sanitize_option_' ) ? substr( $filter, strlen( 'sanitize_option_' ) ) : '';
	}

	/**
	 * While unlicensed, refuse to ACCEPT a custom post type that is not already
	 * stored for this option. Anything already stored is left alone, so a licensed
	 * user whose licence lapses loses nothing, and a forged form field cannot
	 * pre-load a Pro value that switches on when a licence is activated later.
	 *
	 * @param string[] $clean Types that passed the allowed-list intersect.
	 * @return string[]
	 */
	private static function gate_pro_post_types( array $clean ): array {
		if ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) {
			return $clean;
		}
		$option = self::option_from_filter();
		$stored = '' !== $option ? array_map( 'sanitize_key', (array) get_option( $option, array() ) ) : array();
		$keep   = array_merge( array( 'post', 'page' ), $stored );
		return array_values( array_intersect( $clean, $keep ) );
	}

	/**
	 * on/off for a Pro-only switch. While unlicensed, a submitted "on" is refused
	 * and the stored value stays as it is; "off" is always accepted so a lapsed
	 * licence can still be switched off.
	 */
	public static function sanitize_pro_on_off( $value ): string {
		$value = self::sanitize_on_off( $value );
		if ( 'on' === $value && ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) ) {
			$option = self::option_from_filter();
			return ( '' !== $option && 'on' === get_option( $option, 'off' ) ) ? 'on' : 'off';
		}
		return $value;
	}

	/**
	 * Like sanitize_post_types(), but never drops a post type that is already
	 * stored for this option.
	 *
	 * Free only offers post and page, so a plain intersect with that list threw
	 * away every custom post type the site had already been publishing (for
	 * example Products in llms.txt) the first time the form was saved. Types
	 * that are stored and still registered are kept; a type can only be added
	 * through the allowed list, so Free gains no new reach.
	 *
	 * @param mixed  $value  Submitted value.
	 * @param string $option Option name holding the stored list.
	 * @return string[]
	 */
	public static function sanitize_post_types_keep_stored( $value, string $option ): array {
		$clean = self::sanitize_post_types( $value );
		if ( ! is_array( $value ) ) {
			return $clean;
		}
		$allowed = array_keys( self::get_allowed_post_types() );
		$stored  = array_map( 'sanitize_key', (array) get_option( $option, array() ) );
		foreach ( $stored as $slug ) {
			if ( '' !== $slug && ! in_array( $slug, $allowed, true ) && ! in_array( $slug, $clean, true ) && post_type_exists( $slug ) ) {
				$clean[] = $slug;
			}
		}
		return array_values( array_unique( $clean ) );
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
		return self::gate_pro_post_types( array_values( array_intersect( array_map( 'sanitize_key', $value ), $allowed ) ) );
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

	/**
	 * Sub-tab forced by legacy slug translation, or null when the URL decides.
	 *
	 * @since 1.4.2
	 * @var string|null
	 */
	private static $forced_sub = null;

	/**
	 * The sub-tab to render.
	 *
	 * Replaces four copies of a raw sub-tab query read. render_page() used to
	 * translate a legacy tab slug by WRITING into the query superglobal so the render
	 * methods downstream would pick the value up. The value was always a
	 * literal from legacy_tab_target()'s hardcoded map, so nothing unsafe ever
	 * reached it, but mutating a superglobal makes that a property of today's
	 * map rather than a guarantee, it leaves the request array disagreeing with the actual
	 * request for every later reader including other plugins, and it is a
	 * pattern wp.org reviewers flag on sight. Routing it through one accessor
	 * keeps the URL read-only.
	 *
	 * @since 1.4.2
	 * @param string $default Sub-tab to use when neither a forced value nor a
	 *                        query argument is present.
	 * @return string Sanitized sub-tab slug.
	 */
	private static function current_sub( string $default ): string {
		if ( null !== self::$forced_sub ) {
			return self::$forced_sub;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display routing; no state change.
		return isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : $default;
	}

	/**
	 * Map a legacy top-level tab slug to its current tab + subtab.
	 *
	 * These slugs were real top-level tabs in earlier versions and are kept
	 * working because people bookmark admin screens. Returning null means the
	 * slug is not a legacy one and needs no translation.
	 *
	 * @param string $tab Requested tab slug.
	 * @return array{tab:string,sub:string}|null
	 */
	private static function legacy_tab_target( string $tab ): ?array {
		$map = array(
			// Former Advanced top-level tab and its predecessors.
			'advanced'  => array( 'tab' => 'settings', 'sub' => 'advanced' ),
			'headless'  => array( 'tab' => 'settings', 'sub' => 'headless' ),
			'tools'     => array( 'tab' => 'settings', 'sub' => 'advanced' ),
			'info'      => array( 'tab' => 'settings', 'sub' => 'advanced' ),
			// Former Content AI / E-E-A-T top-level slugs.
			'authority' => array( 'tab' => 'content', 'sub' => 'author' ),
			'author'    => array( 'tab' => 'content', 'sub' => 'author' ),
			'schema'    => array( 'tab' => 'content', 'sub' => 'schema' ),
			'summary'   => array( 'tab' => 'content', 'sub' => 'summary' ),
			'faq'       => array( 'tab' => 'content', 'sub' => 'faq' ),
		);

		return $map[ $tab ] ?? null;
	}

	/**
	 * Redirect legacy tab slugs before any output is sent.
	 *
	 * Runs on admin_init precisely because the previous version of this did
	 * not: a redirect issued from the page callback is too late to send a
	 * Location header.
	 *
	 * @since 1.4.0
	 */
	public static function maybe_redirect_legacy_tab(): void {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display routing; no state change.
		if ( ! isset( $_GET['page'], $_GET['tab'] ) || self::MENU_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display routing; no state change.
		$target = self::legacy_tab_target( sanitize_key( wp_unslash( $_GET['tab'] ) ) );
		if ( null === $target ) {
			return;
		}

		wp_safe_redirect(
			admin_url(
				'admin.php?page=' . self::MENU_SLUG . '&tab=' . $target['tab'] . '&sub=' . $target['sub']
			)
		);
		exit;
	}

	// ── Main render ───────────────────────────────────────────────────────────

	/**
	 * Put the current tab in the browser title, so several RankReady tabs open
	 * at once are told apart in the tab strip and in history.
	 *
	 * @param string $admin_title Full title, e.g. "RankReady < Site - WordPress".
	 * @param string $title       Page title alone.
	 * @return string
	 */
	public static function filter_admin_title( $admin_title, $title ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::MENU_SLUG ) ) {
			return $admin_title;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$map = self::legacy_tab_target( $tab );
		if ( null !== $map ) {
			$tab = $map['tab'];
		}
		$labels = array(
			'dashboard' => __( 'Dashboard', 'rankready-ai-llm-seo' ),
			'crawlers'  => __( 'AI Visibility', 'rankready-ai-llm-seo' ),
			'content'   => __( 'AI Content', 'rankready-ai-llm-seo' ),
			'insights'  => __( 'Insights', 'rankready-ai-llm-seo' ),
			'settings'  => __( 'Settings', 'rankready-ai-llm-seo' ),
			'api'       => __( 'Settings', 'rankready-ai-llm-seo' ),
			'llm'       => __( 'AI Visibility', 'rankready-ai-llm-seo' ),
		);
		if ( ! isset( $labels[ $tab ] ) ) {
			return $admin_title;
		}
		return sprintf( '%1$s - %2$s', $labels[ $tab ], (string) $admin_title );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rankready-ai-llm-seo' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only $_GET[tab] for display routing; no state change.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		/*
		 * The nine legacy tab slugs used to be redirected from right here, and
		 * the redirect could never work: render_page() runs long after
		 * WordPress has printed the admin header, so wp_safe_redirect() hit
		 * "headers already sent", logged two PHP warnings, and the exit killed
		 * the page halfway through, leaving an admin screen with no plugin UI
		 * on it at all. The slugs exist for old bookmarks, so the one path
		 * meant to protect them was the one path that broke. The redirect now
		 * happens on admin_init, before any output, in
		 * maybe_redirect_legacy_tab().
		 *
		 * This remap stays as the in-page fallback for the case where the
		 * redirect could not run (output already started by another plugin, or
		 * a filter short-circuiting it): the requested screen is rendered
		 * directly instead of exiting, so the worst case is a correct page with
		 * the old URL rather than a blank one.
		 */
		$legacy_sub = self::legacy_tab_target( $active_tab );
		if ( null !== $legacy_sub ) {
			$active_tab        = $legacy_sub['tab'];
			self::$forced_sub  = $legacy_sub['sub'];
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
							<span class="dashicons dashicons-external rnrd-extlink" aria-hidden="true"></span>
						</a>
						<?php // Docs are a separate surface from the sales page, so they get their own permanent link rather than being buried behind it. ?>
						<a class="rnrd-header__home-link" href="<?php echo esc_url( rnrd_doc_url( '', 'header' ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Docs', 'rankready-ai-llm-seo' ); ?>
							<span class="dashicons dashicons-external rnrd-extlink" aria-hidden="true"></span>
						</a>
					</h1>
					<p class="rnrd-subtitle"><?php esc_html_e( 'LLM SEO, EEAT & AI Optimization for WordPress', 'rankready-ai-llm-seo' ); ?></p>
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
				<p class="rnrd-aside-card__sub"><?php esc_html_e( 'Practical AI SEO tips plus RankReady product updates, straight to your inbox. No spam, unsubscribe anytime.', 'rankready-ai-llm-seo' ); ?></p>
				<form method="post" action="" class="rnrd-tips-form">
					<?php wp_nonce_field( 'rnrd_dash_tips', '_rnrd_tips_nonce' ); ?>
					<input type="hidden" name="rnrd_dash_tips" value="1" />
					<input
						type="text"
						name="rnrd_tips_first_name"
						aria-label="<?php esc_attr_e( 'First name', 'rankready-ai-llm-seo' ); ?>"
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
						aria-label="<?php esc_attr_e( 'Email address', 'rankready-ai-llm-seo' ); ?>"
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
					<?php
					/*
					 * These three describe 1.4.0. They described 1.3.x while the
					 * card printed v1.4.0 above them, which is the worst version
					 * of a changelog: it looks maintained and is not.
					 *
					 * Rule 6 applies here as much as on the landing page. Every
					 * line says what changed in the admin, and none of them
					 * promise a citation, a ranking or any visibility outcome.
					 * Rule 7: the Pro tie-in is stated plainly in the third
					 * bullet rather than implied, and it names what stays free.
					 */
					?>
					<li><strong><?php esc_html_e( 'Help where the question is.', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'Every option now links to the article that explains it, so you can check what a setting actually does without leaving the screen.', 'rankready-ai-llm-seo' ); ?></li>
					<li><strong><?php esc_html_e( 'Readable admin notices.', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'Notices are now scannable, the ones you can dismiss close with one click, and an unfinished Brand Identity names the exact field still missing and what leaving it empty costs you.', 'rankready-ai-llm-seo' ); ?></li>
					<li><strong><?php esc_html_e( 'Clearer about what is paid.', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'You can see exactly what every Pro setting does before you pay for it, instead of an empty space where the option should be. Generating summaries and FAQs a post at a time stays free and uncapped.', 'rankready-ai-llm-seo' ); ?></li>
				</ul>
			</div>
			<?php endif; ?>

			<div class="rnrd-aside-card">
				<h3 class="rnrd-aside-card__title"><?php esc_html_e( 'Connect with us', 'rankready-ai-llm-seo' ); ?></h3>
				<a href="https://www.facebook.com/groups/hostmyblogcommunity" target="_blank" rel="noopener" class="rnrd-aside-link">
					<span class="dashicons dashicons-groups" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Join community', 'rankready-ai-llm-seo' ); ?></span>
					<span class="rnrd-aside-link__arrow" aria-hidden="true"></span>
				</a>
				<a href="https://hostmy.blog/docs/rankready/changelog/" target="_blank" rel="noopener" class="rnrd-aside-link">
					<span class="dashicons dashicons-media-text" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Pro changelog', 'rankready-ai-llm-seo' ); ?></span>
					<span class="rnrd-aside-link__arrow" aria-hidden="true"></span>
				</a>
				<?php if ( rnrd_is_pro() ) : ?>
				<?php // Licensed sites get support by email; the wp.org forum is the Free channel. ?>
				<a href="mailto:rankready@hostmy.blog" class="rnrd-aside-link">
					<span class="dashicons dashicons-sos" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Need help? Email support', 'rankready-ai-llm-seo' ); ?></span>
					<span class="rnrd-aside-link__arrow" aria-hidden="true"></span>
				</a>
				<?php else : ?>
				<a href="https://wordpress.org/support/plugin/rankready-ai-llm-seo/" target="_blank" rel="noopener" class="rnrd-aside-link">
					<span class="dashicons dashicons-sos" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Need help? Raise a ticket', 'rankready-ai-llm-seo' ); ?></span>
					<span class="rnrd-aside-link__arrow" aria-hidden="true"></span>
				</a>
				<a href="mailto:rankready@hostmy.blog" class="rnrd-aside-link">
					<span class="dashicons dashicons-email" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Email support', 'rankready-ai-llm-seo' ); ?></span>
					<span class="rnrd-aside-link__arrow" aria-hidden="true"></span>
				</a>
				<?php endif; ?>
			</div>

			<?php
			/*
			 * Not while the review notice is up. Both ask for the same
			 * WordPress.org review, and on an aged install they rendered
			 * together on one screen. See RNRD_Notices::review_ask_active().
			 */
			if ( ! class_exists( 'RNRD_Notices' ) || ! RNRD_Notices::review_ask_active() ) :
				?>
			<div class="rnrd-aside-card rnrd-aside-card--rate">
				<h3 class="rnrd-aside-card__title"><?php esc_html_e( 'Loving RankReady so far?', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-aside-card__sub"><?php esc_html_e( 'A short review on WordPress.org helps the next person decide whether to try it.', 'rankready-ai-llm-seo' ); ?></p>
				<div class="rnrd-rate-widget" role="radiogroup" aria-label="<?php esc_attr_e( 'Rate RankReady', 'rankready-ai-llm-seo' ); ?>"
					 data-rate-wp="https://wordpress.org/support/plugin/rankready-ai-llm-seo/reviews/?rate=5#new-post"
					 data-rate-mailto="mailto:rankready@hostmy.blog?subject=<?php echo rawurlencode( 'Feedback for RankReady' ); ?>">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<button type="button" role="radio" aria-checked="false" class="rnrd-rate-star" data-value="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d is star count */ __( 'Rate %d out of 5', 'rankready-ai-llm-seo' ), $i ) ); ?>">
							<span class="dashicons dashicons-star-empty" aria-hidden="true"></span>
						</button>
					<?php endfor; ?>
				</div>
			</div>
			<?php endif; ?>

		</aside>
		<?php
	}

	// ── Shared UI helpers ─────────────────────────────────────────────────────

	/*
	 * render_pro_gate() lived here. It drew a Pro-only setting as a name, a
	 * pill and a paragraph, with no control at all, and every one of its rows
	 * pointed at the same 'pro-gate' upgrade source, so a click told us nothing
	 * about which feature sold it. All seven call sites were individual fields
	 * sitting alongside free fields, which is field-level scope, and they now
	 * render the real control disabled with one marker beside it. Nothing calls
	 * this any more, so it is gone rather than left as a second way to do the
	 * thing we stopped doing.
	 *
	 * The `.rnrd-soon-row*` and `.rnrd-card__soon-block` CSS rules stay in
	 * admin.css on purpose: a shipped public class name is a permanent
	 * contract, and a site with a custom admin stylesheet must not lose styling
	 * on update.
	 */

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
			<p class="rnrd-card-goal rnrd-mb-0">
				<?php echo esc_html( $lede ); ?>
				<?php
				/*
				 * The doc for THIS screen, inline at the end of the lede rather
				 * than in a bar of its own. A separate "read the docs for this
				 * screen" strip is not how a real product does it: the link
				 * belongs in the sentence that raised the question.
				 */
				if ( function_exists( 'rnrd_doc' ) ) {
					rnrd_doc( rnrd_doc_for_screen(), __( 'See how it works', 'rankready-ai-llm-seo' ) );
				}
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Inline locked-checkbox indicator for form-table rows where a future
	 * Pro toggle will live.
	 *
	 * Emits a real checkbox, disabled.
	 *
	 * It used to return an empty string, which meant a locked row rendered as a
	 * sentence and a PRO tag with NO control at all: there was nothing on screen
	 * to recognise as the thing being bought. Showing the actual checkbox, inert,
	 * is how every WordPress plugin signals "this exists, you just cannot use it
	 * yet", and it is far more legible than prose about a setting.
	 *
	 * Never emit a padlock as well. The PRO tag is the single marker of why; two
	 * markers on one row is the clutter that got removed earlier.
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function locked_checkbox_indicator(): void {
		/*
		 * Echoed, not returned as a string.
		 *
		 * Every call site wrapped this in wp_kses_post(), which strips <input>
		 * outright: it allows post content, and a form control is not post
		 * content. The markup was being produced and then silently discarded, so
		 * the row rendered with no checkbox and the helper looked broken when it
		 * was the sanitiser doing exactly its job. Printing it here keeps the
		 * escaping honest, since there is no dynamic data in it at all.
		 */
		?>
		<input type="checkbox" disabled aria-disabled="true" tabindex="-1" />
		<?php
	}

	/**
	 * The standard Pro pill, as a link to the upgrade screen.
	 *
	 * Output: <a class="rnrd-pro-tag" href="...">Pro</a>
	 *
	 * This docblock previously described a mint "COMING SOON" chip emitting
	 * `<span class="rnrd-soon-tag">`. None of that had been true since the
	 * COMING SOON labels were removed, and a comment describing a design that no
	 * longer exists is how the old one gets reintroduced.
	 *
	 * `.rnrd-pro-tag` is styled once in admin.css alongside `.rnrd-gate__tag`,
	 * so every Pro pill in the plugin is identical by construction.
	 */
	private static function pro_badge(): string {
		return '<a class="rnrd-pro-tag" href="' . esc_url( rnrd_upgrade_url( 'badge' ) ) . '">'
			. esc_html__( 'Pro', 'rankready-ai-llm-seo' ) . '</a>';
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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DISTINCT count of own meta_key, no WP-API equivalent; $summary_in holds only %s placeholders.
		if ( ! empty( $summary_types ) ) {
			$summary_in    = implode( ',', array_fill( 0, count( $summary_types ), '%s' ) );
			$summary_args  = array_merge( array( RNRD_META_SUMMARY ), $summary_types );
			$summary_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_type IN ({$summary_in}) AND p.post_status = 'publish'",
					$summary_args
				)
			);
		} else {
			$summary_count = 0;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Same pattern for FAQ meta; $faq_in holds only %s placeholders.
		if ( ! empty( $faq_types ) ) {
			$faq_in    = implode( ',', array_fill( 0, count( $faq_types ), '%s' ) );
			$faq_args  = array_merge( array( RNRD_META_FAQ ), $faq_types );
			$faq_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_type IN ({$faq_in}) AND p.post_status = 'publish'",
					$faq_args
				)
			);
		} else {
			$faq_count = 0;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
					<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Content', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'make-post-ai-ready', __( 'How to start', 'rankready-ai-llm-seo' ) ); } ?></h2>
					<p class="rnrd-card-goal"><?php esc_html_e( 'AI Summaries, FAQ, Author Box, and Schema. Generated Summary and FAQ also appear in Markdown and OKF.', 'rankready-ai-llm-seo' ); ?></p>
				</div>
				<a class="rnrd-dash-summary__open rnrd-arrow-link" href="<?php echo esc_url( $content_url ); ?>"><?php esc_html_e( 'View all', 'rankready-ai-llm-seo' ); ?></a>
			</div>
			<div class="rnrd-kpi-row" role="group" aria-label="<?php esc_attr_e( 'Content summary', 'rankready-ai-llm-seo' ); ?>">
			<a class="rnrd-kpi rnrd-kpi--link" href="<?php echo esc_url( $content_summary_url ); ?>" aria-label="<?php esc_attr_e( 'AI Summaries — open AI Content', 'rankready-ai-llm-seo' ); ?>">
				<div class="rnrd-kpi__title">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'AI Summaries', 'rankready-ai-llm-seo' ); ?></div>
					<span class="rnrd-kpi__go" aria-hidden="true"></span>
				</div>
				<?php if ( empty( $summary_types ) ) : ?>
					<?php
					/*
					 * The eyebrow above the big number has one job on every
					 * other tile: it names the tile's SCOPE, either the post
					 * types ("POSTS", "HOWTO / ITEMLIST") or the window
					 * ("LAST 30 DAYS"). In the off branch it used to print
					 * "Disabled" directly above a large "Off", which said the
					 * same thing twice and gave the slot a second, conflicting
					 * meaning. What is actually true when this branch runs is
					 * that no post types are selected, which is both the reason
					 * it is off and the thing to change, so the slot keeps its
					 * one job and the value below still carries the state.
					 */
					?>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Not set', 'rankready-ai-llm-seo' ); ?></div>
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
					<span class="rnrd-kpi__go" aria-hidden="true"></span>
				</div>
				<?php if ( empty( $faq_types ) ) : ?>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Not set', 'rankready-ai-llm-seo' ); ?></div>
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
						<span class="rnrd-kpi__go" aria-hidden="true"></span>
					</div>
					<div class="rnrd-kpi__period"><?php echo $author_on ? esc_html( self::dash_post_types_meta( $author_types ) ) : esc_html__( 'Not set', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo $author_on ? esc_html__( 'On', 'rankready-ai-llm-seo' ) : esc_html__( 'Off', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__foot"><?php echo esc_html( $author_on ? self::dash_auto_placement_label( $author_place ) : __( 'Hidden on frontend', 'rankready-ai-llm-seo' ) ); ?></div>
				</a>
				<a class="rnrd-kpi rnrd-kpi--link" href="<?php echo esc_url( $content_schema_url ); ?>" aria-label="<?php esc_attr_e( 'Schema — open AI Content', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Schema', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true"></span>
					</div>
					<div class="rnrd-kpi__period"><?php echo $schema_on_labels ? esc_html( implode( ' · ', $schema_on_labels ) ) : esc_html__( 'Not set', 'rankready-ai-llm-seo' ); ?></div>
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
					<a class="rnrd-arrow-link" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change in Settings', 'rankready-ai-llm-seo' ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'AI setup needed for Summaries & FAQ', 'rankready-ai-llm-seo' ); ?>
					<span class="rnrd-dash-summary__foot-sep" aria-hidden="true">·</span>
					<a class="rnrd-arrow-link" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open Settings', 'rankready-ai-llm-seo' ); ?></a>
				<?php endif; ?>
			</p>
		</div>

		<div class="rnrd-card rnrd-dash-summary" style="margin-bottom:20px;">
			<div class="rnrd-dash-summary__head">
				<div>
					<h2 class="rnrd-card-title"><?php esc_html_e( 'Insights', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'check-rankready-is-working', __( 'What these reports show', 'rankready-ai-llm-seo' ) ); } ?></h2>
					<?php
					/*
					 * "what they cite" is the same Rule 6 slip as the KPI
					 * labels below: it promises the panel can tell you what an
					 * AI answer quoted, and it cannot. What the three reports
					 * under this card actually hold is who fetched the site,
					 * which pages they fetched, and who arrived from an AI app
					 * afterwards. Naming the middle one honestly costs the
					 * sentence nothing and stops the panel being read as a
					 * citation report that then fails to match anything the
					 * user sees in ChatGPT.
					 */
					?>
					<p class="rnrd-card-goal"><?php esc_html_e( 'Who reads your site, which pages they fetch, what brings them back.', 'rankready-ai-llm-seo' ); ?></p>
				</div>
				<a class="rnrd-dash-summary__open rnrd-arrow-link" href="<?php echo esc_url( $insights_url ); ?>"><?php esc_html_e( 'View all', 'rankready-ai-llm-seo' ); ?></a>
			</div>
			<div class="rnrd-kpi-row" role="group" aria-label="<?php esc_attr_e( 'Insights summary', 'rankready-ai-llm-seo' ); ?>">
				<a class="rnrd-kpi rnrd-kpi--link" data-intent="training" href="<?php echo esc_url( $insights_training ); ?>" aria-label="<?php esc_attr_e( 'Training Bots — open Insights', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Training Bots', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true"></span>
					</div>
					<?php if ( $training_active ) : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $training_hits ) ); ?></div>
						<div class="rnrd-kpi__foot"><?php esc_html_e( 'AI crawler hits', 'rankready-ai-llm-seo' ); ?></div>
					<?php else : ?>
					<?php
					/*
					 * Same rule as the AI Content tiles above: this slot names
					 * what the big number is measured over. In the off branch
					 * there is no number to measure, so printing a 30-day window
					 * above the word "Off" put a period on a state and read as
					 * "no hits in 30 days" when in fact nothing was being
					 * recorded at all. The foot below already says which of the
					 * two reasons applies.
					 */
					?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Not counting', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php esc_html_e( 'Off', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__foot"><?php echo esc_html( $training_on ? __( 'llms.txt and Markdown are off', 'rankready-ai-llm-seo' ) : __( 'Training bot logging paused', 'rankready-ai-llm-seo' ) ); ?></div>
					<?php endif; ?>
				</a>
				<a class="rnrd-kpi rnrd-kpi--link" data-intent="citation" href="<?php echo esc_url( $insights_citation ); ?>" aria-label="<?php esc_attr_e( 'Answer-engine bots — open Insights', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Answer-engine bots', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true"></span>
					</div>
					<?php if ( $citation_active ) : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $citation_hits ) ); ?></div>
						<div class="rnrd-kpi__foot"><?php esc_html_e( 'live answer bots', 'rankready-ai-llm-seo' ); ?></div>
					<?php else : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Not counting', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php esc_html_e( 'Off', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__foot"><?php echo esc_html( $citation_on ? __( 'llms.txt and Markdown are off', 'rankready-ai-llm-seo' ) : __( 'Answer-engine bot logging paused', 'rankready-ai-llm-seo' ) ); ?></div>
					<?php endif; ?>
				</a>
				<a class="rnrd-kpi rnrd-kpi--link" data-intent="referral" href="<?php echo esc_url( $insights_referral ); ?>" aria-label="<?php esc_attr_e( 'Real AI Referrals — open Insights', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Real AI Referrals', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true"></span>
					</div>
					<?php if ( $referral_on ) : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $referral_hits ) ); ?></div>
						<div class="rnrd-kpi__foot"><?php esc_html_e( 'visitors from AI apps', 'rankready-ai-llm-seo' ); ?></div>
					<?php else : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Not counting', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php esc_html_e( 'Off', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__foot"><?php esc_html_e( 'Referer tracking paused', 'rankready-ai-llm-seo' ); ?></div>
					<?php endif; ?>
				</a>
				<a class="rnrd-kpi rnrd-kpi--link" data-intent="freshness" href="<?php echo esc_url( $insights_freshness ); ?>" aria-label="<?php esc_attr_e( 'Content Fresh — open Insights', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Content Fresh', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true"></span>
					</div>
					<?php
					/*
					 * This said "60+ days old" and "posts going stale", and both
					 * were wrong about the number printed between them.
					 * $stale_count is the `stale` bucket from
					 * RNRD_Freshness::bucket_counts(), whose cutoff is
					 * stale_days(), default 90 and user-settable on the screen
					 * this tile links to. So the window was off by thirty days,
					 * and the foot named the `going_stale` bucket, which that
					 * screen lists separately as its own band. Clicking through
					 * therefore showed a count that matched neither band. The
					 * window now comes from the same function the count does, so
					 * changing the Stale Threshold moves both together, and the
					 * foot names the band the number is actually in. Same fix the
					 * freshness widget's own JS strings already got.
					 */
					$stale_days_label = class_exists( 'RNRD_Freshness' ) ? RNRD_Freshness::stale_days() : 90;
					?>
					<div class="rnrd-kpi__period"><?php
						echo esc_html( sprintf(
							/* translators: %d: stale threshold in days. */
							__( '%d+ days old', 'rankready-ai-llm-seo' ),
							(int) $stale_days_label
						) );
					?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $stale_count ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'posts already stale', 'rankready-ai-llm-seo' ); ?></div>
				</a>
			</div>
		</div>

		<p style="margin:0 0 14px;font-size:12px;color:var(--rnrd-color-text-muted,#646970);">
			<?php esc_html_e( 'Need to start over?', 'rankready-ai-llm-seo' ); ?>
			<a class="rnrd-arrow-link" href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-welcome' ) ); ?>"><?php esc_html_e( 'Re-run the setup wizard', 'rankready-ai-llm-seo' ); ?></a>
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
			if ( class_exists( 'RNRD_Brand_Identity' ) ) {
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
			: __( 'Standard snippet length by default (max-snippet is capped)', 'rankready-ai-llm-seo' );

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
					<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Visibility', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'supported-ai-crawlers', __( 'What AI engines read', 'rankready-ai-llm-seo' ) ); } ?></h2>
					<p class="rnrd-card-goal"><?php esc_html_e( 'Surfaces AI engines can read and use.', 'rankready-ai-llm-seo' ); ?></p>
				</div>
				<a class="rnrd-dash-summary__open rnrd-arrow-link" href="<?php echo esc_url( $visibility_url ); ?>"><?php esc_html_e( 'View all', 'rankready-ai-llm-seo' ); ?></a>
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
									<span class="rnrd-feature-list__go rnrd-arrow-link" aria-hidden="true"><?php esc_html_e( 'Configure', 'rankready-ai-llm-seo' ); ?></span>
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

	/*
	 * REMOVED in 1.4.0: get_scorecard_signals() + render_card_scorecard(),
	 * ~285 lines of dead code.
	 *
	 * The "Agentic Ready Scorecard" card was superseded by the AI Visibility
	 * card (render_card_agent_visibility, above), which is what the Dashboard
	 * actually renders. Both scorecard methods were private and had ZERO
	 * callers anywhere in Free or Pro, so they could not run at all: the code
	 * shipped inside every Free zip and got read by WP.org reviewers while
	 * rendering nowhere. It also still drew its on/off state from literal
	 * tick and circle characters, so it was the last place a glyph could
	 * regress in output nobody could look at.
	 *
	 * If a scorecard ever comes back, build it on agent_visibility_signals().
	 * Do not restore this one: it ran its own uncached $wpdb queries.
	 */


	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: AI Content — AI Summary | AI FAQ | Author Box | Schema
	// ═══════════════════════════════════════════════════════════════════════════

	/**
	 * AI Content tab — subtabs for Summary, FAQ, Author Box, and Schema.
	 */
	private static function render_tab_content(): void {
		$sub = self::current_sub( 'summary' );
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
			__( 'Add summaries, FAQs, author trust, and schema so AI engines can read your pages accurately.', 'rankready-ai-llm-seo' )
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
		// AJAX card via `rnrd_pro_bulk_summary_card`; Free renders the upsell
		// panel (render_pro_panel), which names the feature and links to Pro.
		// It has not said "Coming Soon" since rc.16 — a shipped Pro feature
		// labelled Coming Soon breaks the Pro promise and kills the upsell.
		if ( has_action( 'rnrd_pro_bulk_summary_card' ) ) {
			do_action( 'rnrd_pro_bulk_summary_card' );
		} else {
			self::render_pro_panel(
				__( 'Bulk Regenerate, AI Summaries', 'rankready-ai-llm-seo' ),
				__( 'One post at a time is fine for ten posts and impossible for four hundred. Pro runs the whole archive as one resumable job, skips posts that already have a summary, and uses your own API key.', 'rankready-ai-llm-seo' ),
				'bulk-summary'
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
		// via `rnrd_pro_bulk_faq_card`; Free renders render_pro_panel() with the
		// real feature described and a link to Pro. It has NOT said "Coming
		// Soon" since rc.16, because bulk FAQ is shipped and labelling a shipped
		// paid feature Coming Soon breaks the Pro promise and kills the upsell.
		if ( has_action( 'rnrd_pro_bulk_faq_card' ) ) {
			do_action( 'rnrd_pro_bulk_faq_card' );
		} else {
			self::render_pro_panel(
				__( 'Bulk Regenerate, FAQ', 'rankready-ai-llm-seo' ),
				__( 'Pro writes the question and answer pairs across your whole archive in one resumable job, and leaves any FAQ you wrote yourself untouched.', 'rankready-ai-llm-seo' ),
				'bulk-faq'
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
					<a class="rnrd-arrow-link" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open Settings', 'rankready-ai-llm-seo' ); ?></a>
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
				<a class="rnrd-arrow-link" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change in Settings', 'rankready-ai-llm-seo' ); ?></a>
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
		$sub = self::current_sub( 'bot-activity' );
		$sub_tabs = array(
			'bot-activity' => __( 'Training Bots', 'rankready-ai-llm-seo' ),
			'citation'     => __( 'Answer-engine bots', 'rankready-ai-llm-seo' ),
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
			// "what they cite" replaced for the same reason as the dashboard
			// card lede above: the reports record fetches, never quotes.
			__( 'Who trains on you, which pages they fetch, and who clicks back — counts start at zero on a fresh install.', 'rankready-ai-llm-seo' )
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
	 *   • Citation (inbound, live, citation INTENT — not confirmed citations)
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
			<a class="rnrd-pagination__btn rnrd-arrow-link--back <?php echo $current <= 1 ? 'is-disabled' : ''; ?>"
			   href="<?php echo esc_url( add_query_arg( $param, $prev ) ); ?>"
			   aria-disabled="<?php echo $current <= 1 ? 'true' : 'false'; ?>">
				<?php esc_html_e( 'Prev', 'rankready-ai-llm-seo' ); ?>
			</a>
			<span class="rnrd-pagination__info">
				<?php
				/* translators: 1: current page, 2: total pages */
				echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'rankready-ai-llm-seo' ), (int) $current, (int) $pages ) );
				?>
			</span>
			<a class="rnrd-pagination__btn rnrd-arrow-link <?php echo $current >= $pages ? 'is-disabled' : ''; ?>"
			   href="<?php echo esc_url( add_query_arg( $param, $next ) ); ?>"
			   aria-disabled="<?php echo $current >= $pages ? 'true' : 'false'; ?>">
				<?php esc_html_e( 'Next', 'rankready-ai-llm-seo' ); ?>
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

		echo '<p class="rnrd-notice rnrd-notice--inline rnrd-notice--warn" role="status">';
		if ( 'on' !== $enable ) {
			echo esc_html(
				$training
					? __( 'Tracking is currently off. Turn it on above to resume counting new training-bot activity.', 'rankready-ai-llm-seo' )
					: __( 'Tracking is currently off. Turn it on above to resume counting new answer-engine bot activity.', 'rankready-ai-llm-seo' )
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
						__( 'Tracking is on, but both llms.txt and Markdown are disabled, so no new bot fetches can be recorded. Enable <a href="%1$s">llms.txt</a> or <a href="%2$s">Markdown</a> in AI Visibility.', 'rankready-ai-llm-seo' ),
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
		// Counted from the lists themselves, never typed in. The hardcoded pair
		// that used to live here said 5 and 8 while the real lists held 5 and
		// 11, and the comment beside the 8 named Diffbot, which is not in the
		// list at all. A count copied by hand is a number that goes stale the
		// next time someone adds a bot.
		$citation_bots_tracked = count( RNRD_Crawler_Log::BOTS_CITATION );
		$training_bots_tracked = count( RNRD_Crawler_Log::BOTS_TRAINING );

		// Total published posts for "pages read of X" context.
		$total_posts = (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'page' )->publish;

		$has_data = $total_30d > 0;
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::INSIGHTS_GROUP ); ?>
			<?php self::render_insights_preserve_hiddens( RNRD_OPT_AI_TRAINING_ENABLE ); ?>
			<div class="rnrd-card" style="margin-bottom:16px;">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Training Bot Logging', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'see-which-ai-crawlers-visit', __( 'What gets logged', 'rankready-ai-llm-seo' ) ); } ?></h2>
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
			<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Training Activity', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'see-which-ai-crawlers-visit', __( 'Reading this report', 'rankready-ai-llm-seo' ) ); } ?></h2>
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
							echo esc_html( sprintf(
								/*
								 * This branch runs when the value above is 0, so
								 * "%d training bots tracked" read as a flat
								 * contradiction of the number it sat under. The
								 * count is the watch list, not a count of hits,
								 * so the wording now says so. The translators
								 * comment stays directly on the _n() call: any
								 * comment between the two stops make-pot
								 * attaching it to the string.
								 */
								/* translators: %d: total training bots tracked */
								_n(
									'Watching for %d training bot across all endpoints',
									'Watching for %d training bots across all endpoints',
									$training_bots_tracked,
									'rankready-ai-llm-seo'
								),
								(int) $training_bots_tracked
							) );
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
		$max_hits          = $total_pages_cited > 0 ? max( array_column( $citation_pages, 'hits' ) ) : 1;
		$has_data          = $total_pages_cited > 0;
		?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::INSIGHTS_GROUP ); ?>
			<?php self::render_insights_preserve_hiddens( RNRD_OPT_AI_CITATION_ENABLE ); ?>
			<div class="rnrd-card" style="margin-bottom:16px;">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Answer-bot logging', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'what-citation-candidate-means', __( 'What a bot fetch means', 'rankready-ai-llm-seo' ) ); } ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Count answer-engine crawlers that fetch your llms.txt and Markdown endpoints. Data stays on your site.', 'rankready-ai-llm-seo' ); ?></p>
				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable tracking', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_AI_CITATION_ENABLE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_AI_CITATION_ENABLE ); ?>"
									   value="on" <?php checked( $citation_enable, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Track answer-engine bot fetches on AI endpoints', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'When off, no new bot fetches are recorded. Existing totals below stay available. Default is on.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save tracking setting', 'rankready-ai-llm-seo' ), 'primary', 'submit', false ); ?>
			</div>
		</form>

		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Most-fetched pages', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'what-citation-candidate-means', __( 'How this list is built', 'rankready-ai-llm-seo' ) ); } ?></h2>
			<?php
			/*
			 * "fetch as live answer sources" claimed the page was used as a
			 * source. What we know is that a citation bot fetched it while
			 * answering a live query. Same rule as the KPI labels below.
			 */
			?>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Pages answer-engine bots already fetch while answering live queries. Keep these current first.', 'rankready-ai-llm-seo' ); ?></p>

			<?php self::render_bot_endpoint_tracking_notice( $citation_enable, 'citation' ); ?>

			<div class="rnrd-kpi-row" role="group" aria-label="<?php esc_attr_e( 'Most-fetched pages summary', 'rankready-ai-llm-seo' ); ?>">
				<div class="rnrd-kpi" data-intent="citation">
					<?php
					/*
					 * "Pages cited" asserted the one thing this panel cannot
					 * see. The underlying number is a count of distinct URLs a
					 * citation-intent bot fetched, which is what the label now
					 * says. Same rule as the hit-count foot below.
					 */
					?>
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Pages fetched', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total_pages_cited ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php
						echo $has_data
							? esc_html__( 'unique URLs fetched by answer-engine bots', 'rankready-ai-llm-seo' )
							: esc_html__( 'No answer-engine bot fetches yet — this fills in as AI engines fetch your pages', 'rankready-ai-llm-seo' );
					?></div>
				</div>
				<div class="rnrd-kpi" data-intent="citation">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Total bot fetches', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total_hits ) ); ?></div>
					<?php
					/*
					 * Copy-honesty rule: claim crawlability, never citations.
					 * This line used to read "each hit = one AI answer using
					 * your content", which asserts the outcome we have no way
					 * to observe. What the log actually records is an inbound
					 * fetch by a bot whose user agent is the one those engines
					 * use when a live user query is in flight. That makes it a
					 * strong signal of citation INTENT and nothing more: the
					 * answer may never have quoted the page, and we cannot see
					 * whether it did. Saying so also makes the number more
					 * useful, because it stops it being read as a citation
					 * count that then fails to match anything the user sees in
					 * ChatGPT.
					 */
					?>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'Each hit is a live fetch by an answer-engine bot', 'rankready-ai-llm-seo' ); ?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Top page hits', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $has_data ? $max_hits : 0 ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php
						echo $has_data
							? esc_html__( 'on your most-fetched URL', 'rankready-ai-llm-seo' )
							: esc_html__( 'How concentrated your AI traffic is', 'rankready-ai-llm-seo' );
					?></div>
				</div>
			</div>

			<?php if ( ! $has_data ) : ?>
				<p class="rnrd-kpi-empty">
					<?php esc_html_e( 'No answer-engine bot fetches yet. ChatGPT-User, OAI-SearchBot, PerplexityBot, Claude-Web, and DuckAssistBot only fetch your pages when a real user asks the AI something your content might answer.', 'rankready-ai-llm-seo' ); ?>
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
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Most-fetched pages', 'rankready-ai-llm-seo' ); ?></h3>
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
								<td><?php echo $cp_seen_ts ? esc_html( sprintf( /* translators: %s: time span such as 3 hours */ __( '%s ago', 'rankready-ai-llm-seo' ), human_time_diff( $cp_seen_ts ) ) ) : '—'; ?></td>
								<td>
									<?php $cp_edit_link = $cp_post_id > 0 ? get_edit_post_link( $cp_post_id ) : ''; ?>
									<?php if ( $cp_edit_link ) : ?>
										<a href="<?php echo esc_url( $cp_edit_link ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'rankready-ai-llm-seo' ); ?></a>
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
				<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Referral Tracking', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'track-chatgpt-perplexity-traffic', __( 'How referrals are tracked', 'rankready-ai-llm-seo' ) ); } ?></h2>
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
			<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Referral Traffic', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'track-chatgpt-perplexity-traffic', __( 'Reading this report', 'rankready-ai-llm-seo' ) ); } ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Real humans who clicked through from ChatGPT, Perplexity, Claude, Gemini, or Copilot — tracked via HTTP Referer.', 'rankready-ai-llm-seo' ); ?></p>

			<?php if ( 'on' !== $referral_enable ) : ?>
				<p class="rnrd-notice rnrd-notice--inline rnrd-notice--warn" role="status">
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
					<strong><?php esc_html_e( 'Bot Activity', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'measures AI crawlers reading your site (no human involved). It shows which AI crawlers fetched your pages.', 'rankready-ai-llm-seo' ); ?>
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
		$sub = self::current_sub( 'api-keys' );
		$sub_tabs = array(
			'api-keys'    => __( 'API Keys', 'rankready-ai-llm-seo' ),
			'cloudflare' => __( 'Cloudflare', 'rankready-ai-llm-seo' ),
			'headless'    => __( 'Headless API', 'rankready-ai-llm-seo' ),
			'advanced'    => __( 'Advanced', 'rankready-ai-llm-seo' ),
		);
		/**
		 * Lets RankReady Pro add its own Settings section (License).
		 *
		 * Free renders nothing for a slug it does not own: the section body is
		 * printed by `rnrd_settings_subtab_{slug}` from whoever added the slug.
		 *
		 * @param array<string,string> $sub_tabs slug => label.
		 */
		$sub_tabs = (array) apply_filters( 'rnrd_settings_subtabs', $sub_tabs );
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
			case 'headless':
				self::render_tab_headless();
				break;
			case 'advanced':
				self::render_tab_advanced();
				break;
			case 'api-keys':
				self::render_tab_api();
				break;
			default:
				if ( has_action( 'rnrd_settings_subtab_' . $sub ) ) {
					do_action( 'rnrd_settings_subtab_' . $sub );
				} else {
					self::render_tab_api();
				}
				break;
		}
	}

	/**
	 * Settings → Cloudflare — always visible; connect form works even when CF
	 * is not auto-detected (staging / DNS-only false negatives).
	 */
	private static function render_tab_cloudflare(): void {
		// The same storage disclosure as the API Keys tab. This tab takes an
		// API token and, for some accounts, the Cloudflare Global API Key,
		// which is account-wide. Somebody pasting one here may never open the
		// API Keys tab, so telling them there only is telling nobody.
		self::api_key_encryption_notice();
		if ( class_exists( 'RNRD_Cloudflare' ) ) {
			RNRD_Cloudflare::render_card();
		}
	}

	private static function render_tab_advanced(): void {
		// v1.2.0-rc.5 — Advanced tab simplified.
		// MOVED: Headless API settings have their own subtab (render_tab_headless, #58).
		// REMOVED: How It Works + Quick Stats cards (Dashboard already shows tab purposes).
		// REPLACED: Health Check card → the live Diagnostics card. No probe count here:
		// the old "22-probe" label went stale the first time a probe was added.
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

	/**
	 * Tell the truth about how API keys are stored on THIS site.
	 *
	 * RNRD_Crypto::encrypt() falls back to storing the raw value when openssl is
	 * unavailable or the cipher call fails. Falling back is right — losing
	 * somebody's key is worse than storing it like any other WordPress option —
	 * but shipping a docblock that promises encryption at rest while silently
	 * doing something else on some hosts is not. The user is the one who decides
	 * whether a plaintext key is acceptable on their server, and they can only
	 * decide that if we say so.
	 *
	 * Shown only when it is actually true, so a normal host sees nothing.
	 */
	private static function api_key_encryption_notice(): void {
		if ( ! class_exists( 'RNRD_Crypto' ) || ! method_exists( 'RNRD_Crypto', 'is_plaintext_fallback' ) ) {
			return;
		}

		/*
		 * Ask RNRD_Crypto what it considers a secret rather than keeping a
		 * second list here. The second list had drifted to the four LLM keys
		 * and missed `rnrd_cf_global_key`, which is an account-wide Cloudflare
		 * credential, and `rnrd_dfs_password`. So the one site that most needed
		 * to be told its worst key was sitting in the clear was the one site
		 * this notice stayed silent on. Driving it off SECRET_OPTIONS means a
		 * secret added there is disclosed here without anyone remembering to.
		 */
		$stored = method_exists( 'RNRD_Crypto', 'secret_options' )
			? RNRD_Crypto::secret_options()
			: array( RNRD_OPT_KEY, RNRD_OPT_ANTHROPIC_KEY, RNRD_OPT_GEMINI_KEY, RNRD_OPT_DEEPSEEK_KEY );

		/*
		 * Read the STORED bytes, not what get_option() hands back. RNRD_Crypto
		 * registers decrypt_on_read() on `option_$opt`, so a correctly encrypted
		 * key arrives here as plaintext and is_plaintext_fallback() then calls it
		 * unencrypted. That inverted the notice: every site with a working cipher
		 * was told its keys sit in the clear, which is a false security warning,
		 * and the one host that really cannot encrypt read the same sentence as
		 * everybody else. raw_stored_value() stands the filter down for the read.
		 * Without that method there is no honest way to test this, so say nothing
		 * rather than guess.
		 */
		$plain = false;
		if ( method_exists( 'RNRD_Crypto', 'raw_stored_value' ) ) {
			foreach ( $stored as $opt ) {
				if ( RNRD_Crypto::is_plaintext_fallback( RNRD_Crypto::raw_stored_value( $opt ) ) ) {
					$plain = true;
					break;
				}
			}
		}

		$can = method_exists( 'RNRD_Crypto', 'can_encrypt' ) ? RNRD_Crypto::can_encrypt() : true;

		if ( ! $plain && $can ) {
			return;
		}
		?>
		<div class="notice notice-warning inline rnrd-notice">
			<p>
				<strong><?php esc_html_e( 'Keys are stored without encryption on this server.', 'rankready-ai-llm-seo' ); ?></strong>
				<?php
				if ( ! $can ) {
					esc_html_e( 'RankReady encrypts saved API keys using PHP\'s OpenSSL extension, which is not available here, so keys are stored as plain text in the database like any other WordPress setting.', 'rankready-ai-llm-seo' );
				} else {
					esc_html_e( 'One or more saved keys were written before encryption was available on this server, so they are stored as plain text. Re-saving a key now stores it encrypted.', 'rankready-ai-llm-seo' );
				}
				?>
				<?php esc_html_e( 'Anyone with database access can read them. Ask your host to enable the OpenSSL extension, or use a key scoped only to what RankReady needs.', 'rankready-ai-llm-seo' ); ?>
			</p>
		</div>
		<?php
	}

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
		<?php self::api_key_encryption_notice(); ?>
		<?php self::model_migration_notice(); ?>

		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::SETTINGS_GROUP ); ?>

			<!-- LLM Provider Picker -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Provider', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'choose-ai-provider-cost', __( 'Which provider to pick', 'rankready-ai-llm-seo' ) ); } ?></h2>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Active provider', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<fieldset id="rnrd-llm-provider-picker" class="rnrd-radio-list">
								<?php
								/*
								 * Tier names, not version numbers. These four
								 * strings used to enumerate specific models,
								 * and three of the Claude ones named models
								 * that had never existed. An enumerated list
								 * here is also the wrong job for this control:
								 * it picks a PROVIDER, and the Model dropdown
								 * below it already shows that provider's real
								 * IDs, live from its API once a key is set. The
								 * tier names have been stable for years and
								 * survive every model release, so the hint
								 * cannot rot into a false claim again.
								 */
								$providers = array(
									'openai'    => array( 'OpenAI',    __( 'Nano, mini, standard and pro tiers', 'rankready-ai-llm-seo' ) ),
									'anthropic' => array( 'Claude',    __( 'Haiku, Sonnet and Opus tiers', 'rankready-ai-llm-seo' ) ),
									'gemini'    => array( 'Gemini',    __( 'Flash Lite, Flash and Pro tiers', 'rankready-ai-llm-seo' ) ),
									'deepseek'  => array( 'DeepSeek',  __( 'Flash and Pro tiers', 'rankready-ai-llm-seo' ) ),
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
					/*
					 * The SAVED pick, never the resolved one. This select's
					 * name is the real option, so whatever renders as selected
					 * is what the next "Save AI" writes. Rendering the
					 * auto-latest result here turned a runtime resolution into
					 * a silent one-way rewrite of the user's choice on any
					 * save of this screen, for all four providers at once,
					 * since every card is rendered and merely hidden. The
					 * resolution is shown as text under the checkbox instead.
					 */
					$cur     = RNRD_LLM::get_saved_model( $provider_id );
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
							<?php
							/*
							 * Describes the tiers, not four hardcoded IDs. The
							 * dropdown above is ordered cheapest first and is
							 * populated live from the provider once a key is
							 * set, so naming IDs here guarantees the sentence
							 * eventually describes models the list no longer
							 * offers. Same change as the Claude description
							 * below, which had been naming invented models.
							 */
							?>
							<p class="description"><?php esc_html_e( 'The list is ordered cheapest first. A nano or mini tier is enough for summaries and FAQs on most sites; the larger tiers cost more per word for a small quality gain.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Anthropic (Claude) -->
			<div class="rnrd-provider-card-inner" data-rnrd-provider="anthropic" <?php echo 'anthropic' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Claude (Anthropic)', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Claude follows content rules closely, which suits factual AI summaries and FAQs.', 'rankready-ai-llm-seo' ); ?></p>

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
							<p class="description"><?php esc_html_e( 'Haiku is the cheapest and fastest and is the default. Sonnet is the balanced pick. Opus and Fable cost the most per word. The list is ordered cheapest first.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Gemini -->
			<div class="rnrd-provider-card-inner" data-rnrd-provider="gemini" <?php echo 'gemini' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Gemini (Google)', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Gemini ships native JSON output, which suits high-volume sites.', 'rankready-ai-llm-seo' ); ?></p>

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

			<?php
			/*
			 * One checkbox for all four providers, deliberately outside the
			 * provider cards. The cards are all rendered and merely hidden with
			 * inline CSS, so a checkbox inside each would put four inputs of
			 * the same name in one form and the last one in the DOM would win
			 * regardless of which card the user was looking at.
			 */
			$rnrd_auto_latest   = RNRD_LLM::auto_latest_enabled();
			/*
			 * Read this through the same accessor the select uses, so the two
			 * can never disagree. A raw get_option() here also went blank on a
			 * fresh install, where the option row does not exist yet and the
			 * default lives in the accessor, which suppressed the line below
			 * exactly when a new user would most want to see it.
			 */
			$rnrd_saved_model   = RNRD_LLM::get_saved_model( $active_provider );
			$rnrd_resolved      = RNRD_LLM::get_model( $active_provider );
			?>
			<table class="form-table rnrd-form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Model updates', 'rankready-ai-llm-seo' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( RNRD_LLM::OPT_AUTO_LATEST ); ?>" value="0" />
						<label for="rnrd_llm_auto_latest">
							<input type="checkbox" id="rnrd_llm_auto_latest"
							       name="<?php echo esc_attr( RNRD_LLM::OPT_AUTO_LATEST ); ?>"
							       value="1" <?php checked( $rnrd_auto_latest ); ?> />
							<?php esc_html_e( 'Always use the newest model in the family selected above', 'rankready-ai-llm-seo' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Providers retire models on their own schedule. With this on, RankReady follows the provider\'s own model list and moves to the newest model in the same family, so generation keeps working without waiting for a plugin update. It never changes family, so it cannot move you from a cheaper tier onto a more expensive one, and it never picks a model the provider has already flagged for retirement. Turn it off to stay on exactly the model selected above.', 'rankready-ai-llm-seo' ); ?></p>
						<?php if ( $rnrd_auto_latest && '' !== $rnrd_resolved && $rnrd_resolved !== $rnrd_saved_model && '' !== $rnrd_saved_model ) : ?>
							<p class="description"><strong><?php
								printf(
									/* translators: 1: saved model ID, 2: model ID actually used */
									esc_html__( 'Currently resolving %1$s to %2$s.', 'rankready-ai-llm-seo' ),
									esc_html( $rnrd_saved_model ),
									esc_html( $rnrd_resolved )
								);
							?></strong></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
				<?php submit_button( __( 'Save AI', 'rankready-ai-llm-seo' ), 'primary', 'submit_ai', false ); ?>
			</div><!-- /.rnrd-card "AI" (merged: Provider picker + Provider config) -->

			<?php // Provider visibility toggler moved into assets/admin.js (rc.16, WP.org Rule #3 — no inline <script> in PHP). ?>

			<!-- Product Context — REMOVED in rc.3.
			     The "About" field on the Brand Identity card (AI Crawlers tab) now serves
			     this purpose. RNRD_Generator + RNRD_Faq inject Brand Identity About into the
			     AI prompts, so admins only fill in one place. -->

			<!-- DataForSEO -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'DataForSEO', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'dataforseo-setup', __( 'What DataForSEO adds', 'rankready-ai-llm-seo' ) ); } ?></h2>
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
								<?php rnrd_gate( esc_html__( 'Also apply to products, docs and any other custom post type', 'rankready-ai-llm-seo' ), 'cpt-picker' ); ?>
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
				<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Summary', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'generate-ai-summaries-wordpress', __( 'How summaries work', 'rankready-ai-llm-seo' ) ); } ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Key Takeaways, the short standalone facts an assistant can read without the article around them.', 'rankready-ai-llm-seo' ); ?></p>

				<?php
				self::render_optional_post_types_section(
					RNRD_OPT_POST_TYPES,
					__( 'Uncheck all to turn summaries off. Existing summaries are kept.', 'rankready-ai-llm-seo' )
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
									<?php self::locked_checkbox_indicator(); ?>
									<span class="rnrd-locked__label"><?php esc_html_e( 'Automatically generate Key Takeaways when a post is published or updated', 'rankready-ai-llm-seo' ); ?></span>
									<a class="rnrd-pro-tag" href="<?php echo esc_url( rnrd_upgrade_url( 'autogen-summary' ) ); ?>"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></a>
								</label>
							<?php endif; ?>
							<p class="description"><?php
								/*
								 * This listed Bulk Generate as one of the ways a
								 * summary gets made, on a screen that is shown to
								 * everyone. Bulk Generate is Pro, so on a Free
								 * install the sentence named a route the reader
								 * does not have and cannot find. Each side now
								 * lists only the routes that side actually has.
								 */
								if ( $rnrd_autogen_pro ) {
									esc_html_e( 'Off by default. When off, summaries are only generated by the Regenerate button, the Gutenberg block, or Bulk Regenerate.', 'rankready-ai-llm-seo' );
								} else {
									esc_html_e( 'Summaries are generated by the Regenerate button on a post, or by the Gutenberg block. Both stay free and uncapped.', 'rankready-ai-llm-seo' );
								}
							?></p>
						</td>
					</tr>
				</table>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Display', 'rankready-ai-llm-seo' ); ?></h3>

				<table class="form-table rnrd-form-table">
					<tr>
<?php
						/*
						 * Was "Enable AI Summary", which read as the master switch for
						 * the whole feature and contradicted its own description
						 * one line below. The option only gates FRONTEND
						 * rendering: generation keeps running, and Markdown and
						 * OKF keep serving summaries either way. Turning it off to stop
						 * generating did nothing, and reading it as off implied
						 * summaries existed nowhere while the machine-readable
						 * endpoints still served them.
						 *
						 * The option name is untouched (rule 2, RNRD_OPT_SUMMARY_ENABLE is a
						 * permanent contract). Only the label it wears changed.
						 */
						?>
						<th scope="row"><?php esc_html_e( 'Show on frontend', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $summary_enable = (string) get_option( RNRD_OPT_SUMMARY_ENABLE, 'on' ); ?>
							<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_SUMMARY_ENABLE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_SUMMARY_ENABLE ); ?>" value="on" <?php checked( $summary_enable, 'on' ); ?> data-toggle-target="rnrd-summary-auto-display" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Show summaries on the frontend (single post/page views)', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Only affects what visitors see. Summaries are still generated, and Markdown and OKF still serve them before the body, with this off.', 'rankready-ai-llm-seo' ); ?></p>
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
						<th scope="row"><label for="rnrd-summary-heading-tag"><?php esc_html_e( 'Heading Tag', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $current_tag = (string) get_option( RNRD_OPT_HEADING_TAG, 'h4' ); ?>
							<select id="rnrd-summary-heading-tag" name="<?php echo esc_attr( RNRD_OPT_HEADING_TAG ); ?>">
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
						<th scope="row"><label for="rnrd-summary-label"><?php esc_html_e( 'Label Text', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="text" id="rnrd-summary-label" name="<?php echo esc_attr( RNRD_OPT_LABEL ); ?>"
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
		// Prefill only what the admin typed. The translated default is the
		// placeholder, so saving the form never stores one admin's language.
		$heading       = trim( (string) get_option( RNRD_OPT_AUTHOR_HEADING, '' ) );
		if ( 'About the Author' === $heading ) {
			$heading = '';
		}
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
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Author Box (E-E-A-T)', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'how-to-add-eeat-author-box', __( 'Why E-E-A-T matters', 'rankready-ai-llm-seo' ) ); } ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Show the real people behind your content, with the credentials, links and affiliations that E-E-A-T markup exists to carry.', 'rankready-ai-llm-seo' ); ?></p>
				<?php if ( $seo_plugin ) : ?>
					<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin-top:12px;">
						<strong><?php echo esc_html( $seo_plugin ); ?></strong> <?php esc_html_e( 'is active. With RankReady Pro, no duplicate Person node is emitted. Instead, the existing Person schema in', 'rankready-ai-llm-seo' ); ?> <?php echo esc_html( $seo_plugin ); ?> <?php esc_html_e( 'is enhanced with RankReady data via the plugin\'s filter hooks instead of being duplicated.', 'rankready-ai-llm-seo' ); ?>
					</div>
				<?php else : ?>
					<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin-top:12px;">
						<?php echo function_exists( 'rnrd_is_pro' ) && rnrd_is_pro()
							? esc_html__( 'No SEO plugin detected, so RankReady Pro emits the Person node itself. Install Yoast SEO, Rank Math, AIOSEO or SEOPress later and RankReady Pro stops emitting its own node and enhances theirs through their filters instead. Either way, one Person node is emitted.', 'rankready-ai-llm-seo' )
							: esc_html__( 'No SEO plugin detected, so RankReady emits a basic Person node itself on author archives.', 'rankready-ai-llm-seo' ); ?>
					</div>
				<?php endif; ?>

				<?php
				self::render_optional_post_types_section(
					RNRD_OPT_AUTHOR_POST_TYPES,
					__( 'Uncheck all to turn the author box off. Profile data is kept.', 'rankready-ai-llm-seo' )
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
							<input type="text" name="<?php echo esc_attr( RNRD_OPT_AUTHOR_HEADING ); ?>" id="rnrd_author_heading" value="<?php echo esc_attr( $heading ); ?>" placeholder="<?php esc_attr_e( 'About the Author', 'rankready-ai-llm-seo' ); ?>" class="regular-text" />
							<select name="<?php echo esc_attr( RNRD_OPT_AUTHOR_HEADING_TAG ); ?>"
								aria-label="<?php esc_attr_e( 'Default Heading tag', 'rankready-ai-llm-seo' ); ?>">
								<?php foreach ( array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ) as $tag ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $heading_tag, $tag ); ?>><?php echo esc_html( strtoupper( $tag ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Heading text shown above the box in Card and Compact layouts. Individual blocks/widgets can override.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<?php
				/*
				 * The EEAT controls are Pro. Free used to describe them in three
				 * bordered blocks with no control of any kind, which is the
				 * feature-level upsell treatment applied to four individual
				 * fields, and the shape 1.4.0 set out to remove. Free now draws
				 * the same four rows Pro draws, in the same order, with the real
				 * control disabled and one marker on the row. A reader can see
				 * exactly what they would be buying: a switch, two URL boxes and
				 * a switch, not three paragraphs.
				 *
				 * None of the disabled controls carry a name, so the form
				 * submits nothing for them and no stored Pro value is touched.
				 */
				?>
				<?php if ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() && has_action( 'rnrd_pro_eeat_schema' ) ) : ?>
					<?php do_action( 'rnrd_pro_eeat_schema' ); ?>
				<?php else : ?>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Schema', 'rankready-ai-llm-seo' ); ?></h3>
				<table class="form-table rnrd-form-table">
					<tr class="rnrd-row-locked">
						<th scope="row"><?php esc_html_e( 'Emit Person Schema', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label style="display:inline-flex;align-items:center;gap:8px;color:#1d2327;">
								<?php self::locked_checkbox_indicator(); ?>
								<span class="rnrd-locked__label"><?php esc_html_e( 'Include RankReady Person data (sameAs, knowsAbout, credentials, memberOf, awards) in schema output.', 'rankready-ai-llm-seo' ); ?></span>
								<a class="rnrd-pro-tag" href="<?php echo esc_url( rnrd_upgrade_url( 'author-person-schema' ) ); ?>"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></a>
							</label>
							<p class="description"><?php esc_html_e( 'Emits a Person node on author archives and inline in Article.author. Merges into your SEO plugin\'s graph when one is active.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr class="rnrd-row-locked">
						<th scope="row"><?php esc_html_e( 'Editorial Policy URL', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<span style="display:inline-flex;align-items:center;gap:8px;">
								<input type="url" class="regular-text" placeholder="https://" disabled aria-disabled="true" tabindex="-1" />
								<a class="rnrd-pro-tag" href="<?php echo esc_url( rnrd_upgrade_url( 'author-editorial-url' ) ); ?>"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></a>
							</span>
							<p class="description"><?php esc_html_e( 'Emits as Person.publishingPrinciples on every author.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr class="rnrd-row-locked">
						<th scope="row"><?php esc_html_e( 'Fact-Check Policy URL', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<span style="display:inline-flex;align-items:center;gap:8px;">
								<input type="url" class="regular-text" placeholder="https://" disabled aria-disabled="true" tabindex="-1" />
								<a class="rnrd-pro-tag" href="<?php echo esc_url( rnrd_upgrade_url( 'author-factcheck-url' ) ); ?>"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></a>
							</span>
							<p class="description"><?php esc_html_e( 'Optional "How we fact-check" page, shown as a footer link in the Card layout.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Trust Panel (optional)', 'rankready-ai-llm-seo' ); ?></h3>
				<table class="form-table rnrd-form-table">
					<tr class="rnrd-row-locked">
						<th scope="row"><?php esc_html_e( 'Enable Trust Panel', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label style="display:inline-flex;align-items:center;gap:8px;color:#1d2327;">
								<?php self::locked_checkbox_indicator(); ?>
								<span class="rnrd-locked__label"><?php esc_html_e( 'Add per-post "Fact-checked by", "Reviewed by", and "Last reviewed" fields to the editor.', 'rankready-ai-llm-seo' ); ?></span>
								<a class="rnrd-pro-tag" href="<?php echo esc_url( rnrd_upgrade_url( 'author-trust-panel' ) ); ?>"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></a>
							</label>
							<p class="description"><?php esc_html_e( 'Emits as Article.reviewedBy[] and Article.lastReviewed, the reviewed-content markup health and medical publishers use. Off by default.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
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
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Schema Types', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'add-article-speakable-schema', __( 'Which schema to use', 'rankready-ai-llm-seo' ) ); } ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Emit Article, Speakable and FAQPage JSON-LD from your AI summaries and FAQs. HowTo and ItemList are part of RankReady Pro.', 'rankready-ai-llm-seo' ); ?></p>

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
									<?php esc_html_e( 'Injects Article JSON-LD on published posts and pages that have an AI summary (the summary is used as the description), so a post without a summary gets no Article block. Includes headline, author, publisher, image, about (categories), mentions (tags).', 'rankready-ai-llm-seo' ); ?>
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
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Add speakable markup', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'Marks the title and excerpt with speakable markup (cssSelector) that voice assistants can read. Works with both RankReady and SEO plugin Article schema.', 'rankready-ai-llm-seo' ); ?>
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
								<?php esc_html_e( 'When RankReady FAQ data exists for a post, FAQPage schema is injected automatically. That gives AI engines structured question-and-answer pairs they can parse directly, instead of leaving them to infer the Q&amp;A from prose.', 'rankready-ai-llm-seo' ); ?>
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
					/*
					 * HowTo and ItemList are Pro, and they used to be drawn
					 * BELOW the table as bordered gate blocks: a heading, a
					 * pill and a paragraph, with no control at all. That is the
					 * feature-level treatment, and these two are fields, sitting
					 * in the same list as Article, Speakable and FAQPage. It is
					 * also the exact pattern 1.4.0 set out to remove, so the
					 * screen was shipping the old shape and the changelog entry
					 * saying it was gone at the same time. They are rows in the
					 * table now, with the real control disabled and one marker
					 * beside it, matching Auto-Generate on Publish.
					 *
					 * The disabled checkbox carries no name, so it submits
					 * nothing: the stored values are preserved by the hidden
					 * inputs already emitted for this option group.
					 */
					?>
					<?php if ( ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() && has_action( 'rnrd_pro_schema_rows' ) ) ) : ?>
					<!-- HowTo (Pro) -->
					<tr class="rnrd-row-locked">
						<th scope="row"><?php esc_html_e( 'HowTo Schema', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label style="display:inline-flex;align-items:center;gap:8px;color:#1d2327;">
								<?php self::locked_checkbox_indicator(); ?>
								<span class="rnrd-locked__label"><?php esc_html_e( 'Inject HowTo JSON-LD on step-by-step posts', 'rankready-ai-llm-seo' ); ?></span>
								<a class="rnrd-pro-tag" href="<?php echo esc_url( rnrd_upgrade_url( 'schema-howto' ) ); ?>"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></a>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'Detects "How to", "Tutorial", "Step by Step" or "Guide to" in the title and builds the steps from the headings you already wrote.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>

					<!-- ItemList (Pro) -->
					<tr class="rnrd-row-locked">
						<th scope="row"><?php esc_html_e( 'ItemList Schema', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label style="display:inline-flex;align-items:center;gap:8px;color:#1d2327;">
								<?php self::locked_checkbox_indicator(); ?>
								<span class="rnrd-locked__label"><?php esc_html_e( 'Inject ItemList JSON-LD on listicle posts', 'rankready-ai-llm-seo' ); ?></span>
								<a class="rnrd-pro-tag" href="<?php echo esc_url( rnrd_upgrade_url( 'schema-itemlist' ) ); ?>"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></a>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'Detects "Best N" and "Top N" posts and publishes every entry as a structured item instead of prose.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>
					<?php endif; ?>
				</table>

				<?php // Pro renders the real HowTo + ItemList toggles via this hook. ?>
				<?php if ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() && has_action( 'rnrd_pro_schema_rows' ) ) : ?>
					<?php do_action( 'rnrd_pro_schema_rows', $howto, $itemlist, $seo_plugin ); ?>
				<?php endif; ?>

				<?php submit_button( __( 'Save Schema Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_schema', false ); ?>
			</div>

			<!-- SEO Plugin Detection -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'SEO Plugin Compatibility', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'rankready-with-yoast-rankmath-aioseo', __( 'Running alongside Yoast', 'rankready-ai-llm-seo' ) ); } ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'RankReady detects your active SEO plugin and merges schema — never duplicate tags.', 'rankready-ai-llm-seo' ); ?></p>

				<?php if ( ! empty( $seo_plugin ) ) : ?>
					<div class="rnrd-callout rnrd-callout--info">
						<strong><?php echo esc_html( $seo_plugin ); ?></strong> <?php esc_html_e( 'is active.', 'rankready-ai-llm-seo' ); ?>
						<?php esc_html_e( 'RankReady automatically adjusts schema output to avoid duplicates:', 'rankready-ai-llm-seo' ); ?>
						<ul class="rnrd-callout__list">
							<li><?php esc_html_e( 'Article schema — Handled by', 'rankready-ai-llm-seo' ); ?> <?php echo esc_html( $seo_plugin ); ?>. <?php esc_html_e( 'RankReady skips it automatically.', 'rankready-ai-llm-seo' ); ?></li>
							<li><?php esc_html_e( 'FAQPage schema — RankReady injects only when no', 'rankready-ai-llm-seo' ); ?> <?php echo esc_html( $seo_plugin ); ?> <?php esc_html_e( 'FAQ block exists in the post.', 'rankready-ai-llm-seo' ); ?></li>
							<?php
							/*
							 * HowTo and ItemList are Pro-only, and they carry a
							 * PRO pill a few rows above this notice. Listing
							 * them unconditionally told a Free user that the
							 * plugin was already injecting two schema types it
							 * never emits, which is a capability claim the Free
							 * build cannot honour. Both lines are now gated on
							 * the same license check that gates the features.
							 */
							?>
							<?php if ( rnrd_is_pro() ) : ?>
								<li><?php esc_html_e( 'HowTo schema — RankReady injects only when no', 'rankready-ai-llm-seo' ); ?> <?php echo esc_html( $seo_plugin ); ?> <?php esc_html_e( 'HowTo block exists in the post.', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'ItemList schema — Always handled by RankReady (no SEO plugin does this).', 'rankready-ai-llm-seo' ); ?></li>
							<?php endif; ?>
						</ul>
					</div>
				<?php else : ?>
					<p class="rnrd-info-callout"><span class="dashicons dashicons-info"></span>
						<?php
						/*
						 * Same correction as the list above: name only the
						 * schema types this build actually emits. HowTo and
						 * ItemList are Pro, so a Free install claiming them
						 * here contradicted the PRO pills on the same screen.
						 */
						?>
						<?php if ( rnrd_is_pro() ) : ?>
							<?php esc_html_e( 'No SEO plugin detected — RankReady emits Article, Speakable, FAQ, HowTo and ItemList schema.', 'rankready-ai-llm-seo' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'No SEO plugin detected — RankReady emits Article, Speakable and FAQ schema.', 'rankready-ai-llm-seo' ); ?>
						<?php endif; ?>
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
		$sub = self::current_sub( 'brand' );
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
		$mcp_enable_now = 'on' === (string) get_option( RNRD_OPT_MCP_ENABLE, 'off' );
		$view_opts      = array(
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
				$mcp_enable_now
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
			RNRD_OPT_MD_ACCEPT_NEGOTIATION       => 'off',
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

		// When the webmcp tab itself is being saved while MCP is off, render_visibility_sub_webmcp()
		// never reaches the nested block that emits the CPT sentinel/checkboxes (it lives inside
		// `if ( 'on' === $rnrd_mcp_enable )`), so that save must preserve the option here too — same
		// null-on-save trap as the RNRD_OPT_MCP_EXPOSE_* toggles above (issue #13.6a).
		if ( 'webmcp' !== $view || ! $mcp_enable_now ) {
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
		// Saved / error notices. options.php sends the browser back here with
		// settings-updated, and core prints those notices itself only on its
		// own Settings screens, so without this the save was silent.
		settings_errors();
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
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'brand-terms-setup', __( 'How brand terms help', 'rankready-ai-llm-seo' ) ); } ?>
					<span class="rnrd-badge rnrd-badge--neutral"><?php esc_html_e( 'Single source of truth', 'rankready-ai-llm-seo' ); ?></span>
					<?php if ( $rnrd_brand_complete ) : ?>
						<span class="rnrd-badge rnrd-badge--ok"><?php esc_html_e( 'Complete', 'rankready-ai-llm-seo' ); ?></span>
					<?php else : ?>
						<span class="rnrd-badge rnrd-badge--warn"><?php esc_html_e( 'Incomplete', 'rankready-ai-llm-seo' ); ?></span>
					<?php endif; ?>
				</h2>
				<?php
				/*
				 * "Incomplete" on its own is a state, not a reason. It told you a
				 * box was unticked and left you to guess whether that mattered,
				 * which is why nobody finished the card.
				 *
				 * So the badge now names what is missing and what that costs, in
				 * the surfaces the reader can go and check for themselves. Every
				 * claim below is traced to a real consumer:
				 *   brand terms -> RNRD_Robots (the `# Brand:` line),
				 *                  RNRD_LLMS_Txt, RNRD_FAQ's prompt,
				 *                  RNRD_MCP::ability_get_brand_terms()
				 *   name/summary -> RNRD_LLMS_Txt heading and Brand line
				 * Nothing here promises a ranking or a citation.
				 */
				if ( ! $rnrd_brand_complete ) :
					$rnrd_brand_needs_terms = ( '' === trim( $rnrd_brand_terms_raw ) );
					$rnrd_brand_needs_who   = ( '' === trim( $rnrd_brand_name ) && '' === trim( $rnrd_brand_summary ) );

					if ( $rnrd_brand_needs_terms && $rnrd_brand_needs_who ) {
						$rnrd_brand_why = __( 'Nothing is filled in yet, so llms.txt falls back to your WordPress site title and robots.txt ships with no Brand line. An engine reading this site has no confirmed spelling of your name.', 'rankready-ai-llm-seo' );
					} elseif ( $rnrd_brand_needs_terms ) {
						$rnrd_brand_why = __( 'Add at least one brand term to finish. Until you do, robots.txt has no Brand line, llms.txt lists no brand terms, and the MCP brand-terms ability returns an empty list, so nothing on this site tells an engine which spelling is yours.', 'rankready-ai-llm-seo' );
					} else {
						$rnrd_brand_why = __( 'Add a name or a one-line summary to finish. Until you do, llms.txt uses your WordPress site title as the heading, which is usually the theme default rather than how you describe yourself.', 'rankready-ai-llm-seo' );
					}
					?>
					<p class="rnrd-card-why"><?php echo esc_html( $rnrd_brand_why ); ?></p>
					<?php
				endif;
				?>
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
									  placeholder="<?php esc_attr_e( 'A one-sentence summary of the site. Max 160 chars.', 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( $rnrd_brand_summary ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Renders as the blockquote under the H1 in llms.txt + llms-full.txt. Keep it tight.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="rnrd_llms_about"><?php esc_html_e( 'About', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<textarea id="rnrd_llms_about" name="<?php echo esc_attr( RNRD_OPT_LLMS_ABOUT ); ?>"
									  rows="4" class="large-text"
									  maxlength="500"
									  placeholder="<?php esc_attr_e( 'Longer description. Max 500 chars. What is this site about? Who is it for? Markdown supported.', 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( $rnrd_brand_about ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Detailed context. Appears below the summary in llms.txt and llms-full.txt.', 'rankready-ai-llm-seo' ); ?></p>
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
							<p class="description"><?php esc_html_e( 'Use the exact capitalisation and spacing you want AI engines to use. One canonical name per line — every variant you list is published as yours in llms.txt, in robots.txt and to the WebMCP brand-terms ability.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<div style="margin-top:14px;padding:12px 14px;background:var(--rnrd-color-surface-2,#f6f7f7);border-radius:var(--rnrd-radius-md,6px);font-size:12px;line-height:1.7;color:var(--rnrd-color-ink-soft,#3c434a);">
					<strong style="display:block;margin-bottom:6px;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;color:var(--rnrd-color-text-muted,#646970);">
						<?php esc_html_e( 'Where these fields are used', 'rankready-ai-llm-seo' ); ?>
					</strong>
					<ul style="margin:0;padding-left:18px;list-style:disc;">
						<li><code>/llms.txt</code> &mdash; <?php esc_html_e( 'H1 (name), blockquote (summary), about paragraph, Brand line (terms)', 'rankready-ai-llm-seo' ); ?></li>
						<li><code>/llms-full.txt</code> &mdash; <?php esc_html_e( 'same header block + every page below', 'rankready-ai-llm-seo' ); ?></li>
						<li><code>/robots.txt</code> &mdash; <?php esc_html_e( 'Brand comment inside RankReady AI block', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'AI Summary system prompt', 'rankready-ai-llm-seo' ); ?> &mdash; <?php esc_html_e( 'canonical naming forced into every summary', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'FAQ generation prompt', 'rankready-ai-llm-seo' ); ?> &mdash; <?php esc_html_e( 'brand context, deduped against per-FAQ legacy field', 'rankready-ai-llm-seo' ); ?></li>
						<li><code>rankready-ai-llm-seo/get-site-info</code> &mdash; <?php esc_html_e( 'WebMCP ability returns name + brand_terms to Claude Desktop / Cursor / VS Code', 'rankready-ai-llm-seo' ); ?></li>
						<li><code>rankready-ai-llm-seo/get-brand-terms</code> &mdash; <?php esc_html_e( 'WebMCP ability returns terms array directly', 'rankready-ai-llm-seo' ); ?></li>
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
				<p class="rnrd-card-goal"><?php
					/*
					 * The count was hardcoded at 29, which was right on the day
					 * it was typed and silently wrong the first time a crawler
					 * is added to or dropped from the list below. Read it from
					 * the list instead so the two cannot disagree.
					 */
					printf(
						/* translators: %d: number of AI crawlers the plugin can allow or block */
						esc_html( _n(
							'Allow or block %d named AI crawler. Auto-syncs to your physical /robots.txt.',
							'Allow or block %d named AI crawlers. Auto-syncs to your physical /robots.txt.',
							count( RNRD_Crawler_Access::get_llm_crawlers() ),
							'rankready-ai-llm-seo'
						) ),
						(int) count( RNRD_Crawler_Access::get_llm_crawlers() )
					);
					?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'supported-ai-crawlers', __( 'Which crawlers', 'rankready-ai-llm-seo' ) ); } ?></p>

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
								<fieldset tabindex="0" role="region" aria-label="<?php echo esc_attr__( 'AI crawler access list', 'rankready-ai-llm-seo' ); ?>" style="max-height:460px;overflow-y:auto;overflow-x:auto;min-width:0;max-width:100%;box-sizing:border-box;border:1px solid #ddd;padding:12px 16px;border-radius:4px;">
									<div class="rnrd-crawler-row rnrd-crawler-head" style="display:flex;align-items:center;gap:6px;margin:0 0 6px;position:sticky;top:-12px;z-index:2;background:#fff;padding:6px 0;">
										<?php foreach ( $rnrd_states as $rnrd_key => $rnrd_label ) : ?>
											<span style="flex:0 0 62px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#1d2327;"><?php echo esc_html( $rnrd_label ); ?></span>
										<?php endforeach; ?>
										<span style="margin-left:8px;font-size:12px;color:#666;">
											<?php esc_html_e( 'Set every crawler to:', 'rankready-ai-llm-seo' ); ?>
											<?php foreach ( $rnrd_states as $rnrd_key => $rnrd_label ) : ?>
												<button type="button" class="button-link rnrd-crawler-setall" data-rnrd-state="<?php echo esc_attr( $rnrd_key ); ?>"<?php if ( 'block' === $rnrd_key ) : ?> data-rnrd-confirm="<?php echo esc_attr( __( 'Set every crawler to Block? This includes Bingbot, so Bing Search is blocked as well as Copilot, and FacebookBot. You can still change any row before you save.', 'rankready-ai-llm-seo' ) ); ?>"<?php endif; ?> style="margin-left:6px;"><?php echo esc_html( $rnrd_label ); ?></button>
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
										<div class="rnrd-crawler-row" style="display:flex;align-items:center;gap:6px;margin:0 0 4px;">
											<?php foreach ( $rnrd_states as $rnrd_key => $rnrd_label ) : ?>
												<span style="flex:0 0 62px;text-align:center;">
													<label>
														<?php /* translators: 1: state name, 2: crawler user-agent */ ?>
														<span class="screen-reader-text"><?php echo esc_html( sprintf( __( '%1$s %2$s', 'rankready-ai-llm-seo' ), $rnrd_label, $ua ) ); ?></span>
														<input type="radio"
															   name="<?php echo esc_attr( RNRD_OPT_ROBOTS_MODE ); ?>[<?php echo esc_attr( $ua ); ?>]"
															   value="<?php echo esc_attr( $rnrd_key ); ?>"
															   class="rnrd-crawler-state"
															   style="margin:0;"
															   <?php checked( $rnrd_state, $rnrd_key ); ?> />
													</label>
												</span>
											<?php endforeach; ?>
											<code style="font-size:12px;"><?php echo esc_html( $ua ); ?></code>
											<span class="rnrd-crawler-desc" style="color:#666;font-size:12px;">— <?php echo esc_html( $info[1] ); ?></span>
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
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Content Signals', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'content-signals-robots-txt', __( 'How signals work', 'rankready-ai-llm-seo' ) ); } ?></h2>
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
								<th scope="row"><label for="<?php echo esc_attr( $opt_key ); ?>"><code><?php echo esc_html( $info['label'] ); ?></code></label></th>
								<td>
									<select id="<?php echo esc_attr( $opt_key ); ?>" name="<?php echo esc_attr( $opt_key ); ?>">
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
				<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Snippet', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'ai-snippet-robots-txt', __( 'What this does', 'rankready-ai-llm-seo' ) ); } ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Let crawlers use the full passage in snippets (max-snippet:-1) instead of the ~160-character cap.', 'rankready-ai-llm-seo' ); ?></p>

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
				<h2 class="rnrd-card-title"><?php esc_html_e( 'LLMs.txt Generator', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'how-to-add-llms-txt-wordpress', __( 'What llms.txt does', 'rankready-ai-llm-seo' ) ); } ?></h2>
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

				<?php
				/*
				 * RNRD_Llms_Txt::detect_multilingual() was written for exactly
				 * this warning: its docblock says it exists "so the admin can be
				 * warned that /llms.txt currently serves only the default
				 * language". Nothing ever called it. Instead rc.16 used it to
				 * emit hreflang Link headers pointing at /<lang>/llms.txt, a
				 * route neither plugin registers, so the one thing it did was
				 * advertise a 404 to AI agents. That block is gone; this is the
				 * honest half. Telling the site owner the truth is useful.
				 * Telling agents a URL exists when it does not is a defect.
				 */
				$rnrd_ml = class_exists( 'RNRD_Llms_Txt' ) ? RNRD_Llms_Txt::detect_multilingual() : array();
				if ( ! empty( $rnrd_ml ) ) :
					$rnrd_ml_names = array();
					foreach ( $rnrd_ml as $rnrd_ml_set ) {
						if ( ! empty( $rnrd_ml_set['plugin'] ) ) {
							$rnrd_ml_names[] = (string) $rnrd_ml_set['plugin'];
						}
					}
					?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: comma-separated list of detected multilingual plugin names and versions. */
							esc_html__( 'Detected %s. RankReady serves one /llms.txt, built from your default language. There is no per-language /llms.txt in Free or in Pro, so translated posts are not listed separately here. Your .md endpoints do follow translations.', 'rankready-ai-llm-seo' ),
							esc_html( wp_sprintf_l( '%l', $rnrd_ml_names ) )
						);
						?>
					</p>
					<?php
				endif;
				?>

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
								<?php if ( ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) ) : ?><?php rnrd_gate( esc_html__( 'Also apply to products, docs and any other custom post type', 'rankready-ai-llm-seo' ), 'cpt-picker' ); ?><?php endif; ?>
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
									<fieldset style="max-height:200px;overflow-y:auto;overflow-x:auto;min-width:0;max-width:100%;box-sizing:border-box;border:1px solid #ddd;padding:8px 12px;border-radius:4px;">
										<?php foreach ( $all_cats as $cat ) : ?>
											<label style="display:block;margin-bottom:4px;">
												<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_EXCLUDE_CATS ); ?>[]"
													   value="<?php echo esc_attr( $cat->term_id ); ?>"
													   <?php checked( in_array( (int) $cat->term_id, $exclude_cats, true ) ); ?> />
												<?php echo esc_html( $cat->name ); ?> <span style="color:#646970;">(<?php echo esc_html( $cat->count ); ?>)</span>
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
									<fieldset style="max-height:200px;overflow-y:auto;overflow-x:auto;min-width:0;max-width:100%;box-sizing:border-box;border:1px solid #ddd;padding:8px 12px;border-radius:4px;">
										<?php foreach ( $all_tags as $tag ) : ?>
											<label style="display:block;margin-bottom:4px;">
												<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_EXCLUDE_TAGS ); ?>[]"
													   value="<?php echo esc_attr( $tag->term_id ); ?>"
													   <?php checked( in_array( (int) $tag->term_id, $exclude_tags, true ) ); ?> />
												<?php echo esc_html( $tag->name ); ?> <span style="color:#646970;">(<?php echo esc_html( $tag->count ); ?>)</span>
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
							<th scope="row"><label for="rnrd-llms-cache-ttl"><?php esc_html_e( 'Cache Duration', 'rankready-ai-llm-seo' ); ?></label></th>
							<td>
								<div class="rnrd-input-action-row">
									<select id="rnrd-llms-cache-ttl" name="<?php echo esc_attr( RNRD_OPT_LLMS_CACHE_TTL ); ?>">
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
				<!-- Hide RankReady credit: shipped in Pro, rendered locked with a Pro pill in Free. -->
				<table class="form-table rnrd-form-table" style="margin-top:8px;">
					<tr>
						<th scope="row"><?php esc_html_e( 'Hide RankReady credit', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php if ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) : ?>
								<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_HIDE_BRANDING ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_HIDE_BRANDING ); ?>" value="on" <?php checked( get_option( RNRD_OPT_HIDE_BRANDING, 'off' ), 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Remove "Generated from RankReady" line from /llms.txt and /llms-full.txt', 'rankready-ai-llm-seo' ); ?></span>
								<a class="rnrd-pro-tag" href="<?php echo esc_url( rnrd_upgrade_url( 'white-label' ) ); ?>"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></a>
								</label>
							<?php else : ?>
							<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_HIDE_BRANDING ); ?>" value="<?php echo esc_attr( get_option( RNRD_OPT_HIDE_BRANDING, 'off' ) ); ?>" />
							<label class="rnrd-locked">
								<?php self::locked_checkbox_indicator(); ?>
								<span class="rnrd-locked__label"><?php esc_html_e( 'Remove "Generated from RankReady" line from /llms.txt and /llms-full.txt', 'rankready-ai-llm-seo' ); ?></span>
								<a class="rnrd-pro-tag" href="<?php echo esc_url( rnrd_upgrade_url( 'white-label' ) ); ?>"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></a>
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
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Markdown Endpoints', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'wordpress-markdown-for-ai', __( 'Why Markdown', 'rankready-ai-llm-seo' ) ); } ?></h2>
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
								<?php if ( ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) ) : ?><?php rnrd_gate( esc_html__( 'Also apply to products, docs and any other custom post type', 'rankready-ai-llm-seo' ), 'cpt-picker' ); ?><?php endif; ?>
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
									<?php esc_html_e( 'Visually invisible (clip-path + aria-hidden). Raw-HTML scrapers see "AI agents: a clean Markdown version is at URL.md". It points agents at the Markdown version.', 'rankready-ai-llm-seo' ); ?>
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
									<strong><?php esc_html_e( 'Off for new installs. Leave it off behind Cloudflare, Varnish, Fastly or LiteSpeed caches.', 'rankready-ai-llm-seo' ); ?></strong>
									<?php esc_html_e( 'Caches that ignore Vary: Accept can store the Markdown under your normal page URL and show it to every visitor. Markdown is always available at the .md URL (for example /page.md) whatever this setting is.', 'rankready-ai-llm-seo' ); ?>
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
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'how-to-add-webmcp-wordpress', __( 'What WebMCP is', 'rankready-ai-llm-seo' ) ); } ?>
					<span style="font-size:11px;background:var(--rnrd-color-info-bg,#e5f1f9);color:var(--rnrd-color-info-text,#135e96);padding:2px 8px;border-radius:9999px;margin-left:6px;vertical-align:middle;font-weight:600;">NEW</span>
				</h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Expose 16 read-only abilities at /.well-known/mcp.json — Claude Desktop, Cursor, VS Code read your site.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable WebMCP', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MCP_ENABLE ); ?>" value="on" <?php checked( $rnrd_mcp_enable, 'on' ); ?> />
								<span><?php esc_html_e( 'Serve /.well-known/mcp.json and register the WordPress Abilities', 'rankready-ai-llm-seo' ); ?></span>
							</label>
						</td>
					</tr>

					<?php
					/*
					 * The Status row only carries information once the feature
					 * is on: which manifest is being served and whether the
					 * Abilities API was found. In the off branch it printed a
					 * "Disabled" pill directly under an unchecked toggle, which
					 * said the same thing twice and made an empty feature look
					 * like it had a fault. Off state is now carried by the
					 * toggle alone, the way every other card on this screen
					 * does it.
					 */
					?>
					<?php if ( 'on' === $rnrd_mcp_enable ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'rankready-ai-llm-seo' ); ?></th>
						<td>
								<p style="margin:0 0 6px;">
									<?php // "Enabled", not "live". Nothing here fetches the manifest, and on
									// nginx/RunCloud a /.well-known/ request 403s at the server before
									// WordPress is reached, so a pill claiming "live" was wrong on exactly
									// the installs where the user needed to be told otherwise. The
									// server-config warning is raised separately by maybe_nginx_wellknown_notice(). ?>
									<span class="rnrd-status-pill rnrd-status-pill--ok rnrd-status-pill--block"><span class="rnrd-mark rnrd-mark--ok" aria-hidden="true"></span><?php esc_html_e( 'Manifest enabled', 'rankready-ai-llm-seo' ); ?></span>
								</p>
								<p style="margin:6px 0 0;">
									<?php if ( $rnrd_abilities_api ) : ?>
										<span class="rnrd-status-pill rnrd-status-pill--ok rnrd-status-pill--block"><span class="rnrd-mark rnrd-mark--ok" aria-hidden="true"></span><?php
										/*
										 * This said "16 abilities registered",
										 * a number typed by hand that rots the
										 * moment an ability is added, and that
										 * overstated what a client can reach:
										 * every ability is registered, but the
										 * manifest only exposes the ones whose
										 * resource is switched on below. The
										 * exposed count is the one that answers
										 * "what will Claude Desktop actually
										 * see", so both are shown.
										 */
										$rnrd_ability_total = count( RNRD_MCP::ability_gates() );
										$rnrd_ability_on    = 0;
										foreach ( array_keys( RNRD_MCP::ability_gates() ) as $rnrd_ability_name ) {
											if ( RNRD_MCP::is_ability_enabled( $rnrd_ability_name ) ) {
												++$rnrd_ability_on;
											}
										}
										printf(
											/* translators: 1: number of abilities exposed in the manifest, 2: total abilities the plugin registers */
											esc_html__( 'WordPress Abilities API detected — %1$d of %2$d abilities exposed', 'rankready-ai-llm-seo' ),
											(int) $rnrd_ability_on,
											(int) $rnrd_ability_total
										);
									?></span>
									<?php else : ?>
										<span class="rnrd-status-pill rnrd-status-pill--warn rnrd-status-pill--block"><span class="rnrd-mark rnrd-mark--warn" aria-hidden="true"></span><?php esc_html_e( 'Abilities API plugin not active — manifest still works for raw MCP discovery', 'rankready-ai-llm-seo' ); ?></span>
									<?php endif; ?>
								</p>
						</td>
					</tr>

					<?php
						$rnrd_exposure   = class_exists( 'RNRD_MCP' ) ? RNRD_MCP::exposure_state() : array();
						// Custom Post Type exposure moved fully to Pro (issue #7, 2026-09) — the
						// picker itself now lives in the Pro add-on, rendered via the
						// `rnrd_pro_webmcp_cpt_picker` action below. Free still needs the
						// detected list (to decide whether to fire the hook at all) and the
						// currently-enabled list (to preserve it on save when Pro isn't active).
						$rnrd_detected_cpts = class_exists( 'RNRD_MCP' ) ? RNRD_MCP::detected_cpts() : array();
						$rnrd_enabled_cpts  = (array) get_option( RNRD_OPT_MCP_EXPOSE_CPTS, array() );

						// Helper closure for rendering a single resource toggle row.
						$render_toggle = function ( $opt, $label, $desc, $tone, $current ) {
							$tones = array(
								'safe'    => array( 'bg' => 'var(--rnrd-color-success-bg,#d1ecdf)', 'fg' => 'var(--rnrd-color-success-text,#0a6c39)', 'pill' => __( 'Safe', 'rankready-ai-llm-seo' ) ),
								'caution' => array( 'bg' => 'var(--rnrd-color-warning-bg,#fcf9e8)', 'fg' => 'var(--rnrd-color-warning-text,#674c00)', 'pill' => __( 'Caution', 'rankready-ai-llm-seo' ) ),
								'risky'   => array( 'bg' => 'var(--rnrd-color-danger-bg,#fcebe6)', 'fg' => 'var(--rnrd-color-danger-text,#a72e1f)', 'pill' => __( 'Risky', 'rankready-ai-llm-seo' ) ),
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
							<th scope="row"><label for="rnrd-mcp-manifest-url"><?php esc_html_e( 'MCP manifest URL', 'rankready-ai-llm-seo' ); ?></label></th>
							<td>
								<input type="text" id="rnrd-mcp-manifest-url" readonly value="<?php echo esc_attr( $rnrd_manifest_url ); ?>" onclick="this.select();" style="width:100%;max-width:520px;font-family:monospace;font-size:12px;" />
								<p class="description">
									<?php esc_html_e( 'Paste this URL into Claude Desktop / Cursor / VS Code MCP settings.', 'rankready-ai-llm-seo' ); ?>
									<a class="rnrd-arrow-link" href="<?php echo esc_url( $rnrd_manifest_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open manifest', 'rankready-ai-llm-seo' ); ?></a>
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
										$render_toggle( RNRD_OPT_MCP_EXPOSE_AUTHORS,    __( 'Authors (EEAT)', 'rankready-ai-llm-seo' ), __( 'get-author — author profile fields (name, bio, job title, employer; credentials, awards and socials with RankReady Pro). No emails or login info.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_AUTHORS, 'on' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_TAXONOMIES, __( 'Categories & tags', 'rankready-ai-llm-seo' ), __( 'list-categories, list-tags — topical graph for agent navigation.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_TAXONOMIES, 'on' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_SITEMAP,    __( 'Sitemap', 'rankready-ai-llm-seo' ),       __( 'get-sitemap — parsed URL + lastmod for cold crawls.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_SITEMAP, 'on' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_LLMS_TXT,   __( 'llms.txt inline', 'rankready-ai-llm-seo' ), __( 'get-llms-txt — rendered llms.txt content without HTTP round-trip.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_LLMS_TXT, 'on' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_FRESHNESS,  __( 'Freshness signal', 'rankready-ai-llm-seo' ), __( 'get-fresh-content — posts/pages modified in last N days.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_FRESHNESS, 'on' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_RR_AI,      __( 'RankReady AI data', 'rankready-ai-llm-seo' ), __( 'get-post-summary, get-post-faq, get-brand-terms — the summary, FAQ and brand data RankReady generates, readable by AI agents.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_RR_AI, 'on' ) );
										?>
									</div>
								</details>

								<?php
								/*
								 * Custom post type exposure over MCP is Pro-only (issue #7,
								 * 2026-09-01 product decision — "hook and settings UI both,
								 * fully out of the free zip"). Free no longer ships any picker
								 * markup, checkbox inputs, or a locked/gated copy of the UI —
								 * only the detection call above and this extension hook, which
								 * the Pro add-on's RNRD_Pro_Admin class listens on to render the
								 * real controls in place. See rankready-pro/includes for the
								 * listener.
								 */
								?>
								<?php if ( ! empty( $rnrd_detected_cpts ) && has_action( 'rnrd_pro_webmcp_cpt_picker' ) ) : ?>
									<?php do_action( 'rnrd_pro_webmcp_cpt_picker', $rnrd_detected_cpts, $rnrd_enabled_cpts ); ?>
								<?php endif; ?>
								<?php if ( empty( $rnrd_detected_cpts ) || ! has_action( 'rnrd_pro_webmcp_cpt_picker' ) ) : ?>
									<?php // No picker rendered this request — preserve the option so saving this tab never nulls a value Pro (or a prior Pro install) already set. ?>
									<?php foreach ( $rnrd_enabled_cpts as $rnrd_preserved_cpt ) : ?>
										<?php if ( '' === (string) $rnrd_preserved_cpt ) { continue; } ?>
										<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_MCP_EXPOSE_CPTS ); ?>[]" value="<?php echo esc_attr( (string) $rnrd_preserved_cpt ); ?>" />
									<?php endforeach; ?>
								<?php endif; ?>

								<p style="margin:0;padding:10px 12px;background:var(--rnrd-color-brand-soft,#f0f6fc);border-left:3px solid var(--rnrd-color-brand,#2271b1);border-radius:0 var(--rnrd-radius-md,6px) var(--rnrd-radius-md,6px) 0;font-size:11px;color:var(--rnrd-color-info-text,#135e96);line-height:1.5;">
									<strong><?php esc_html_e( 'Write abilities', 'rankready-ai-llm-seo' ); ?></strong> &mdash; <?php esc_html_e( 'create, update and delete operations are not exposed. RankReady\'s MCP surface is read-only, so a connected AI agent can read what you publish but cannot change anything on your site.', 'rankready-ai-llm-seo' ); ?>
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
										<li><code>rankready-ai-llm-seo/get-site-info</code> &mdash; <?php esc_html_e( 'site name, description, about, brand terms, language', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready-ai-llm-seo/get-brand-terms</code> &mdash; <?php esc_html_e( 'canonical brand names array', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready-ai-llm-seo/list-content-types</code> &mdash; <?php esc_html_e( 'every public post type + published count + archive URL', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
								</details>

								<details style="margin-bottom:8px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);margin-bottom:6px;"><?php esc_html_e( 'Content retrieval (4)', 'rankready-ai-llm-seo' ); ?></summary>
									<ul style="margin:6px 0 12px;padding-left:18px;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);line-height:1.7;">
										<li><code>rankready-ai-llm-seo/get-post</code> &mdash; <strong><?php esc_html_e( 'full Markdown content', 'rankready-ai-llm-seo' ); ?></strong> + <?php esc_html_e( 'title, URL, author, summary, FAQ, schema in one call', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready-ai-llm-seo/get-post-by-url</code> &mdash; <?php esc_html_e( 'resolve any permalink (incl. .md / /category/ / /tag/) to content', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready-ai-llm-seo/get-post-summary</code> &mdash; <?php esc_html_e( 'AI summary bullets only', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready-ai-llm-seo/get-post-faq</code> &mdash; <?php esc_html_e( 'FAQ Q&A pairs only', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
								</details>

								<details style="margin-bottom:8px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);margin-bottom:6px;"><?php esc_html_e( 'Discovery & navigation (5)', 'rankready-ai-llm-seo' ); ?></summary>
									<ul style="margin:6px 0 12px;padding-left:18px;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);line-height:1.7;">
										<li><code>rankready-ai-llm-seo/search-posts</code> &mdash; <?php esc_html_e( 'keyword search across published posts', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready-ai-llm-seo/list-pages</code> &mdash; <?php esc_html_e( 'static pages + parent_id hierarchy', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready-ai-llm-seo/list-recent-posts</code> &mdash; <?php esc_html_e( 'paginated recent-posts feed', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready-ai-llm-seo/list-categories</code> &mdash; <?php esc_html_e( 'topical hierarchy: name, slug, parent, count, URL', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready-ai-llm-seo/list-tags</code> &mdash; <?php esc_html_e( 'tags ordered by post count', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
								</details>

								<details style="margin-bottom:8px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);margin-bottom:6px;"><?php esc_html_e( 'AI-native (4)', 'rankready-ai-llm-seo' ); ?></summary>
									<ul style="margin:6px 0 12px;padding-left:18px;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);line-height:1.7;">
										<li><code>rankready-ai-llm-seo/get-llms-txt</code> &mdash; <?php esc_html_e( 'rendered llms.txt or llms-full.txt content inline', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready-ai-llm-seo/get-sitemap</code> &mdash; <?php esc_html_e( 'parsed sitemap (URL + lastmod) for cold crawls', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready-ai-llm-seo/get-fresh-content</code> &mdash; <?php esc_html_e( 'posts/pages modified in last N days', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready-ai-llm-seo/get-author</code> &mdash; <?php esc_html_e( 'Author profile fields for an author (name, bio, job title, employer; credentials, awards and socials with RankReady Pro)', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
								</details>

								<p class="description" style="margin-top:8px;"><?php esc_html_e( 'All abilities are read-only. No write access exposed.', 'rankready-ai-llm-seo' ); ?>
								<a class="rnrd-arrow-link" href="<?php echo esc_url( $rnrd_manifest_url ); ?>" target="_blank" rel="noopener" style="margin-left:6px;"><?php esc_html_e( 'View manifest JSON', 'rankready-ai-llm-seo' ); ?></a></p>
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
				<h2 class="rnrd-card-title"><?php esc_html_e( 'AI FAQ', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'how-to-add-faq-schema-wordpress', __( 'How FAQs work', 'rankready-ai-llm-seo' ) ); } ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Discover real user questions and answer them with AI, then ship FAQPage schema an answer engine can parse directly.', 'rankready-ai-llm-seo' ); ?></p>

				<?php
				self::render_optional_post_types_section(
					RNRD_OPT_FAQ_POST_TYPES,
					__( 'Uncheck all to turn FAQs off. Existing FAQs are kept.', 'rankready-ai-llm-seo' )
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
							<p class="description"><?php esc_html_e( 'Number of FAQ items to generate per post (3-10). More FAQs means more API calls.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_faq_brand_terms"><?php esc_html_e( 'Brand Terms', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<textarea id="rnrd_faq_brand_terms" name="<?php echo esc_attr( RNRD_OPT_FAQ_BRAND_TERMS ); ?>"
							          rows="3" class="large-text"
							          placeholder="<?php esc_attr_e( 'Your Brand Name, Your Product Name (one per line or comma-separated)', 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( (string) get_option( RNRD_OPT_FAQ_BRAND_TERMS, '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Brand and product names to carry into the generated answers, so an answer names your brand instead of referring to "this site".', 'rankready-ai-llm-seo' ); ?></p>
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
								<?php self::locked_checkbox_indicator(); ?>
								<span class="rnrd-locked__label"><?php esc_html_e( 'Automatically generate FAQs when a post is published or updated', 'rankready-ai-llm-seo' ); ?></span>
								<a class="rnrd-pro-tag" href="<?php echo esc_url( rnrd_upgrade_url( 'autogen-faq' ) ); ?>"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></a>
							</label>
							<?php endif; ?>
							<p class="description"><?php
								if ( $rnrd_faq_autogen_pro ) {
									esc_html_e( 'Off by default. When off, FAQs are only generated via the Gutenberg block, Elementor widget, or Bulk Generate.', 'rankready-ai-llm-seo' );
								} else {
									esc_html_e( 'FAQs are generated by the Gutenberg block or the Elementor widget. Both stay free and uncapped.', 'rankready-ai-llm-seo' );
								}
							?></p>
						</td>
					</tr>
				</table>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Display', 'rankready-ai-llm-seo' ); ?></h3>

				<table class="form-table rnrd-form-table">
					<tr>
<?php
						/*
						 * Was "Enable AI FAQ", which read as the master switch for
						 * the whole feature and contradicted its own description
						 * one line below. The option only gates FRONTEND
						 * rendering: generation keeps running, and Markdown and
						 * OKF keep serving FAQs either way. Turning it off to stop
						 * generating did nothing, and reading it as off implied
						 * FAQs existed nowhere while the machine-readable
						 * endpoints still served them.
						 *
						 * The option name is untouched (rule 2, RNRD_OPT_FAQ_ENABLE is a
						 * permanent contract). Only the label it wears changed.
						 */
						?>
						<th scope="row"><?php esc_html_e( 'Show on frontend', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $faq_enable = (string) get_option( RNRD_OPT_FAQ_ENABLE, 'on' ); ?>
							<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_FAQ_ENABLE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_FAQ_ENABLE ); ?>" value="on" <?php checked( $faq_enable, 'on' ); ?> data-toggle-target="rnrd-faq-auto-display" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Show FAQs on the frontend (single post/page views)', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Only affects what visitors see. FAQs are still generated, and Markdown and OKF still serve them after the body, with this off.', 'rankready-ai-llm-seo' ); ?></p>
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
						<th scope="row"><label for="rnrd-faq-heading-tag"><?php esc_html_e( 'Heading Tag', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $faq_tag = (string) get_option( RNRD_OPT_FAQ_HEADING_TAG, 'h3' ); ?>
							<select id="rnrd-faq-heading-tag" name="<?php echo esc_attr( RNRD_OPT_FAQ_HEADING_TAG ); ?>">
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
							<p class="description"><?php esc_html_e( 'Shows a visible review date.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save FAQ Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_faq', false ); ?>
			</div>

		<?php
	}

	/**
	 * Settings → Headless API (GitHub #58).
	 *
	 * The headless REST routes, the core REST fields and the WPGraphQL fields
	 * all sit behind RNRD_OPT_HEADLESS_ENABLE, which defaults to off. 1.0.16
	 * removed this screen as dead code, which left a Pro feature that nobody
	 * could switch on. The options were never unregistered, so saved values
	 * from 1.0.x come back as they were.
	 */
	private static function render_tab_headless(): void {
		if ( ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) ) {
			self::render_pro_panel(
				__( 'Headless API', 'rankready-ai-llm-seo' ),
				__( 'Serve summaries, FAQ and JSON-LD to a Next.js, Nuxt or Astro frontend through a read-only REST API, with CORS, CDN cache headers, revalidation webhooks and WPGraphQL fields.', 'rankready-ai-llm-seo' ),
				'headless-api',
				''
			);
			return;
		}

		$enabled        = 'on' === get_option( RNRD_OPT_HEADLESS_ENABLE, 'off' );
		$cors_origins   = (string) get_option( RNRD_OPT_HEADLESS_CORS_ORIGINS, '' );
		$expose_meta    = 'on' === get_option( RNRD_OPT_HEADLESS_EXPOSE_META, 'on' );
		$cache_ttl      = (int) get_option( RNRD_OPT_HEADLESS_CACHE_TTL, 300 );
		$rate_limit     = (int) get_option( RNRD_OPT_HEADLESS_RATE_LIMIT, 120 );
		$revalidate_url = (string) get_option( RNRD_OPT_HEADLESS_REVALIDATE_URL, '' );
		$has_secret     = '' !== (string) get_option( RNRD_OPT_HEADLESS_REVALIDATE_SEC, '' );
		$graphql        = 'on' === get_option( RNRD_OPT_HEADLESS_GRAPHQL, 'off' );
		$graphql_active = function_exists( 'register_graphql_field' );
		$base_url       = rest_url( 'rankready/v1/public/' );
		?>
		<?php settings_errors( RNRD_OPT_HEADLESS_CORS_ORIGINS ); ?>
		<form method="post" action="options.php" class="rnrd-form">
			<?php settings_fields( self::HEADLESS_GROUP ); ?>

			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Headless API', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'A read-only REST API that serves each post\'s summary, FAQ and JSON-LD to a separate frontend such as Next.js, Nuxt or Astro.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Public API', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_ENABLE ); ?>" value="on" <?php checked( $enabled ); ?> data-toggle-target="rnrd-headless-inner" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Turn on the public endpoints', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. The endpoints only return content that is already public on your site.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<div id="rnrd-headless-inner" <?php echo $enabled ? '' : 'style="display:none;"'; ?>>
					<table class="form-table rnrd-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Core REST fields', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<label class="rnrd-toggle">
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_EXPOSE_META ); ?>" value="on" <?php checked( $expose_meta ); ?> />
									<span class="rnrd-toggle-label"><?php esc_html_e( 'Add rankready_faq, rankready_summary and rankready_schema to /wp/v2 post responses', 'rankready-ai-llm-seo' ); ?></span>
								</label>
								<p class="description"><?php esc_html_e( 'Useful when your frontend already reads the core WordPress REST API.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rnrd-headless-cors"><?php esc_html_e( 'Allowed origins', 'rankready-ai-llm-seo' ); ?></label></th>
							<td>
								<textarea id="rnrd-headless-cors" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_CORS_ORIGINS ); ?>" rows="3" class="large-text code" placeholder="https://www.example.com, https://staging.example.com"><?php echo esc_textarea( $cors_origins ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Comma-separated. Leave empty to allow any origin. List your frontend domains on a live site.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rnrd-headless-ttl"><?php esc_html_e( 'CDN cache (seconds)', 'rankready-ai-llm-seo' ); ?></label></th>
							<td>
								<input id="rnrd-headless-ttl" type="number" min="0" max="31536000" step="1" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_CACHE_TTL ); ?>" value="<?php echo esc_attr( (string) $cache_ttl ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Sent as s-maxage in Cache-Control on single-post routes. Default 300. The list route uses a fifth of this value (minimum 30 seconds).', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rnrd-headless-rate"><?php esc_html_e( 'Rate limit (requests per minute per IP)', 'rankready-ai-llm-seo' ); ?></label></th>
							<td>
								<input id="rnrd-headless-rate" type="number" min="0" max="10000" step="1" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_RATE_LIMIT ); ?>" value="<?php echo esc_attr( (string) $rate_limit ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Default 120. Set 0 to turn the limit off. Logged-in editors are never limited.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
					</table>

					<h3 class="rnrd-card-title"><?php esc_html_e( 'Revalidation webhook', 'rankready-ai-llm-seo' ); ?></h3>
					<p class="rnrd-card-goal"><?php esc_html_e( 'When a summary or FAQ changes, RankReady sends a POST to your frontend so it can rebuild that page. The editor never waits for it.', 'rankready-ai-llm-seo' ); ?></p>
					<table class="form-table rnrd-form-table">
						<tr>
							<th scope="row"><label for="rnrd-headless-hook"><?php esc_html_e( 'Webhook URL', 'rankready-ai-llm-seo' ); ?></label></th>
							<td>
								<input id="rnrd-headless-hook" type="url" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_REVALIDATE_URL ); ?>" value="<?php echo esc_attr( $revalidate_url ); ?>" class="large-text" placeholder="https://www.example.com/api/revalidate" />
								<p class="description"><?php esc_html_e( 'Receives JSON with post_id, slug, reason, ts and site.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rnrd-headless-secret"><?php esc_html_e( 'Shared secret', 'rankready-ai-llm-seo' ); ?></label></th>
							<td>
								<input id="rnrd-headless-secret" type="password" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_REVALIDATE_SEC ); ?>" value="<?php echo $has_secret ? '__UNCHANGED__' : ''; ?>" class="regular-text" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'Sent in the X-RR-Secret header. A saved secret stays as it is unless you type a new one. Clear the field to remove it.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
					</table>

					<h3 class="rnrd-card-title"><?php esc_html_e( 'WPGraphQL', 'rankready-ai-llm-seo' ); ?></h3>
					<table class="form-table rnrd-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'GraphQL fields', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php if ( $graphql_active ) : ?>
									<label class="rnrd-toggle">
										<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_GRAPHQL ); ?>" value="on" <?php checked( $graphql ); ?> />
										<span class="rnrd-toggle-label"><?php esc_html_e( 'Add rankReadyFaq, rankReadySummary and rankReadySchema', 'rankready-ai-llm-seo' ); ?></span>
									</label>
								<?php else : ?>
									<?php /* Keep the saved value: a missing field would save it as off. */ ?>
									<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_GRAPHQL ); ?>" value="<?php echo $graphql ? 'on' : 'off'; ?>" />
									<p class="description"><?php esc_html_e( 'Activate the WPGraphQL plugin to use this.', 'rankready-ai-llm-seo' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					</table>

					<?php if ( $enabled ) : ?>
						<h3 class="rnrd-card-title"><?php esc_html_e( 'Endpoints', 'rankready-ai-llm-seo' ); ?></h3>
						<p class="rnrd-card-goal"><?php esc_html_e( 'Base URL:', 'rankready-ai-llm-seo' ); ?> <code><?php echo esc_html( $base_url ); ?></code></p>
						<ul class="rnrd-endpoint-list">
							<li><code>GET faq/{id}</code></li>
							<li><code>GET summary/{id}</code></li>
							<li><code>GET schema/{id}</code></li>
							<li><code>GET post/{id}</code></li>
							<li><code>GET post-by-slug/{slug}?post_type=post&amp;lang=en</code></li>
							<li><code>GET list?post_type=post&amp;per_page=20&amp;page=1&amp;since=ISO8601</code></li>
							<li><code>POST revalidate</code></li>
						</ul>
					<?php endif; ?>
				</div>

				<?php submit_button( __( 'Save headless settings', 'rankready-ai-llm-seo' ), 'primary', 'submit', false ); ?>
			</div>
		</form>
		<?php
	}

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
		// The branding / white-label card was removed in rc.16 and its dead
		// renderer deleted in 1.4.0: it described the Pro hide-credit toggle as
		// "planned for a future release" after that toggle had shipped.
		self::render_card_data_retention();
	}

	/**
	 * The panel shown in Free where a Pro feature's UI would be.
	 *
	 * One title, one sentence of why THIS feature is worth paying for, one
	 * action. The earlier version had an eyebrow, a radial glow and three
	 * bullets, which is a landing page dropped into a settings screen. The
	 * bullets in particular were the tell: if the why needs three bullets to
	 * land, the why is not sharp enough yet.
	 *
	 * @param string $title   Feature name, as the user would say it.
	 * @param string $why     One sentence. Concrete, specific, no adjectives.
	 * @param string      $context Slug for upgrade attribution.
	 * @param string|null $fine    Fine print under the actions. Null keeps the
	 *                             default, '' prints none.
	 */
	public static function render_pro_panel( string $title, string $why, string $context = 'pro-panel', ?string $fine = null ): void {
		// The default fine print is about generation. Pass '' for a feature
		// where it would be off topic, such as the Headless API.
		if ( null === $fine ) {
			$fine = __( 'Generating one post at a time stays free and uncapped.', 'rankready-ai-llm-seo' );
		}
		?>
		<div class="rnrd-pro-panel">
			<div class="rnrd-pro-panel__head">
				<h3><?php echo esc_html( $title ); ?></h3>
				<span class="rnrd-pro-tag"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></span>
			</div>
			<div class="rnrd-pro-panel__body">
				<p class="rnrd-pro-panel__why"><?php echo esc_html( $why ); ?></p>
				<div class="rnrd-pro-panel__foot">
					<a class="rnrd-gate__btn" href="<?php echo esc_url( rnrd_upgrade_url( $context ) ); ?>"><?php esc_html_e( 'Upgrade', 'rankready-ai-llm-seo' ); ?></a>
					<a class="rnrd-gate__compare" href="<?php echo esc_url( rnrd_compare_url( $context ) ); ?>"><?php esc_html_e( 'Free vs Pro', 'rankready-ai-llm-seo' ); ?></a>
				</div>
				<?php if ( '' !== $fine ) : ?>
					<p class="rnrd-pro-panel__fine"><?php echo esc_html( $fine ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Back-compat shims. Removing a public static method is a contract break.
	 *
	 * @param string $title Feature name.
	 * @param string $note  Optional description, used as the why.
	 */
	public static function render_coming_soon_box( string $title, string $note = '' ): void {
		self::render_pro_panel( $title, $note );
	}

	/**
	 * @param string $title   Feature name.
	 * @param string $note    Why sentence.
	 * @param array  $bullets Ignored. Kept so older calls do not fatal.
	 * @param string $context Attribution slug.
	 */
	public static function render_pro_box( string $title, string $note = '', array $bullets = array(), string $context = 'pro-box' ): void {
		self::render_pro_panel( $title, $note, $context );
	}


	// ── Bulk Regenerate cards — PRO ENGINE ─────────────────────────────────
	// render_card_bulk_summary() and render_card_bulk_faq() moved to the Pro
	// add-on (RNRD_Pro_Admin), rendered via the `rnrd_pro_bulk_summary_card`
	// and `rnrd_pro_bulk_faq_card` actions that the Content AI tab fires. In
	// the Free build those actions have no listener, so render_pro_panel()
	// describes the shipped Pro feature and links to it instead. Not a Coming
	// Soon box: both engines exist, and saying otherwise in the one place a
	// reader checks is how this class of defect keeps coming back.
	// The bulk REST engine is also Pro-only (RNRD_Pro_Rest).

	// ── Bulk Author Changer (E-E-A-T tab in rc.6) ──────────────────────────
	private static function render_card_bulk_author(): void {
		$users = self::get_authors();
		?>

		<!-- Bulk Author Changer -->
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Bulk Author Changer', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'bulk-author-changer', __( 'How bulk reassign works', 'rankready-ai-llm-seo' ) ); } ?></h2>
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
								<?php rnrd_gate( esc_html__( 'Also apply to products, docs and any other custom post type', 'rankready-ai-llm-seo' ), 'cpt-picker' ); ?>
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
						<p class="description"><?php esc_html_e( 'Leave on "All authors" to reassign posts regardless of current author.', 'rankready-ai-llm-seo' ); ?></p>
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
			<div id="rnrd-bac-preview-result" style="display:none;" class="rnrd-notice rnrd-notice--inline rnrd-notice--info"></div>

			<!-- Progress -->
			<div id="rnrd-bac-progress" style="display:none;margin-top:16px;">
				<div class="rnrd-progress-track">
					<div id="rnrd-bac-bar" class="rnrd-progress-fill"></div>
				</div>
				<p id="rnrd-bac-status" class="rnrd-progress-label"></p>
			</div>

			<!-- Done -->
			<div id="rnrd-bac-done" style="display:none;" class="rnrd-notice rnrd-notice--inline rnrd-notice--success"></div>
		</div>
		<?php
	}

	// ── API Usage (Settings tab in rc.6) ───────────────────────────────────
	private static function render_card_api_usage(): void {
		// Token Usage data
		// One reader for both the legacy serialised option and the atomic counters
		// that replaced it, so this screen never shows a total that reset to zero
		// on update. See RNRD_Generator::get_token_usage().
		$token_usage    = RNRD_Generator::get_token_usage();
		$summary_tokens = (int) $token_usage['summary_tokens'];
		$faq_tokens     = (int) $token_usage['faq_tokens'];
		$total_calls    = (int) $token_usage['total_calls'];
		$total_tokens   = $summary_tokens + $faq_tokens;

		/*
		 * There is deliberately no dollar figure for tokens here any more.
		 *
		 * Until 2026-09-26 this screen showed `($total_tokens / 1e6) * 0.30` as
		 * "~$x.xxxx". One flat rate stood in for four providers and roughly a
		 * dozen models whose published per-token prices differ by more than an
		 * order of magnitude, and the rate was not sourced from any of them. The
		 * label said "approx.", which does not rescue a number that is wrong by a
		 * multiple: a user reading "~$0.04" budgets differently than one reading
		 * the real figure, and this plugin has no way to know which model actually
		 * served a given call, nor what that model cost on the day it ran.
		 *
		 * A price table inside a plugin would also go stale between releases and
		 * be wrong silently, which is worse than absent. So the card reports what
		 * it genuinely measured, tokens and calls, and sends the user to the one
		 * place that knows the price. The DataForSEO figure below stays, because
		 * that IS a real cost reported by the API for each call.
		 */

		// DataForSEO usage.
		$dfs_usage = RNRD_Faq::get_dfs_usage();
		$dfs_calls = (int) $dfs_usage['total_calls'];
		$dfs_cost  = (float) $dfs_usage['total_cost'];
		?>
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'API Usage', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'api-usage-and-costs', __( 'What this costs', 'rankready-ai-llm-seo' ) ); } ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Cumulative tokens and calls since tracking began. Token prices differ by provider and model, so your bill lives in your provider dashboard, not here.', 'rankready-ai-llm-seo' ); ?></p>

			<?php
			$active_provider_label = RNRD_LLM::get_provider_label( RNRD_LLM::get_active_provider() );
			?>

			<div class="rnrd-kpi-row" style="margin-top:14px;">
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
					<div class="rnrd-kpi__foot"><?php
						/*
						 * The foot on the other two tiles carries something the
						 * number above it does not: a breakdown, a cost. This one
						 * said "Cumulative", which the card description already
						 * says about all three tiles, so it spent the slot saying
						 * nothing. The average below is arithmetic on the two
						 * figures we actually measured, not a price estimate, and
						 * it is the one thing a reader budgeting for the next
						 * hundred posts still does not know.
						 */
						if ( $total_calls > 0 ) {
							printf(
								/* translators: %s: average token count per API call */
								esc_html__( '%s tokens per call on average', 'rankready-ai-llm-seo' ),
								esc_html( number_format_i18n( (int) round( $total_tokens / $total_calls ) ) )
							);
						} else {
							esc_html_e( 'No calls yet', 'rankready-ai-llm-seo' );
						}
					?></div>
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
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Content Freshness', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'content-freshness-for-ai', __( 'Why freshness matters', 'rankready-ai-llm-seo' ) ); } ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Recently updated pages present a current last-modified date to AI crawlers — scan to find stale posts.', 'rankready-ai-llm-seo' ); ?></p>
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
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Diagnostics', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'diagnostics-report-guide', __( 'Reading the report', 'rankready-ai-llm-seo' ) ); } ?></h2>
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
				<textarea readonly aria-label="<?php esc_attr_e( 'Cloudflare cache rule snippet', 'rankready-ai-llm-seo' ); ?>" class="rnrd-diag-snippet-text" style="width:100%;height:140px;font-family:Menlo,Consolas,monospace;font-size:11px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px;"><?php echo esc_textarea( $cloudflare_snippet ); ?></textarea>
				<p style="margin:14px 0 4px;font-size:12px;font-weight:600;color:#1d2327;">
					<?php esc_html_e( 'Apache / LiteSpeed — paste at top of .htaccess', 'rankready-ai-llm-seo' ); ?>
				</p>
				<textarea readonly aria-label="<?php esc_attr_e( 'Apache and LiteSpeed .htaccess snippet', 'rankready-ai-llm-seo' ); ?>" class="rnrd-diag-snippet-text" style="width:100%;height:140px;font-family:Menlo,Consolas,monospace;font-size:11px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px;"><?php echo esc_textarea( $htaccess_snippet ); ?></textarea>
				<p style="margin:14px 0 4px;font-size:12px;font-weight:600;color:#1d2327;">
					<?php esc_html_e( 'nginx — add inside your server { } block', 'rankready-ai-llm-seo' ); ?>
				</p>
				<textarea readonly aria-label="<?php esc_attr_e( 'nginx server block snippet', 'rankready-ai-llm-seo' ); ?>" class="rnrd-diag-snippet-text" style="width:100%;height:140px;font-family:Menlo,Consolas,monospace;font-size:11px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px;"><?php echo esc_textarea( $nginx_snippet ); ?></textarea>
				<p style="margin:14px 0 4px;font-size:12px;font-weight:600;color:#1d2327;">
					<?php esc_html_e( 'nginx — if /.well-known/mcp.json returns 403 (this ALLOWS access; it is not a cache setting)', 'rankready-ai-llm-seo' ); ?>
				</p>
				<textarea readonly aria-label="<?php esc_attr_e( 'nginx snippet allowing access to /.well-known/', 'rankready-ai-llm-seo' ); ?>" class="rnrd-diag-snippet-text" style="width:100%;height:120px;font-family:Menlo,Consolas,monospace;font-size:11px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px;"><?php echo esc_textarea( $nginx_wk_snippet ); ?></textarea>
			</details>
			<?php endif; ?>

			<!-- Copy support report -->
			<div id="rnrd-diag-copy-row" style="display:none;margin-top:14px;padding-top:14px;border-top:1px solid #e5e5e5;">
				<p style="margin:0 0 6px;font-size:13px;color:#1d2327;">
					<strong><?php esc_html_e( 'Need help?', 'rankready-ai-llm-seo' ); ?></strong>
					<?php esc_html_e( 'Click below to copy a diagnostic report — safe to paste into the WordPress.org support forum or any public thread. It leaves out your plugin/mu-plugin inventory, server versions, and debug flags, and keeps only the RankReady version, WP/PHP majors, and probe results.', 'rankready-ai-llm-seo' ); ?>
				</p>
				<button type="button" id="rnrd-diag-copy" class="button button-secondary">
					<span class="dashicons dashicons-clipboard" style="vertical-align:middle;margin-top:-2px;"></span>
					<?php esc_html_e( 'Copy Diagnostic Report', 'rankready-ai-llm-seo' ); ?>
				</button>
				<span id="rnrd-diag-copy-status" role="status" aria-live="polite" style="margin-left:10px;font-size:13px;display:none;"></span>
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
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Error Log', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'generation-fails-api-error', __( 'Common errors', 'rankready-ai-llm-seo' ) ); } ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Recent API errors from OpenAI, Claude, Gemini, DeepSeek, and DataForSEO — last 50 entries.', 'rankready-ai-llm-seo' ); ?></p>
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
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Open Knowledge Format (OKF)', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'what-is-open-knowledge-format', __( 'What OKF is', 'rankready-ai-llm-seo' ) ); } ?></h2>
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
							<?php if ( ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) ) : ?><?php rnrd_gate( esc_html__( 'Also apply to products, docs and any other custom post type', 'rankready-ai-llm-seo' ), 'cpt-picker' ); ?><?php endif; ?>
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
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Data Retention', 'rankready-ai-llm-seo' ); ?>
					<?php if ( function_exists( 'rnrd_doc' ) ) { rnrd_doc( 'privacy-and-third-party-services', __( 'What is stored', 'rankready-ai-llm-seo' ) ); } ?></h2>
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
								<?php esc_html_e( 'Deactivating RankReady stops it running and clears its scheduled tasks. Settings, API keys, AI summaries, FAQs, Author Box profiles and freshness history all stay where they are. Reactivate any time and pick up where you left off.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On Delete', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $delete_on_uninstall = (string) get_option( RNRD_OPT_DELETE_ON_UNINSTALL, 'off' ); ?>
							<label>
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_DELETE_ON_UNINSTALL ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_DELETE_ON_UNINSTALL ); ?>" value="on" <?php checked( $delete_on_uninstall, 'on' ); ?> />
								<?php esc_html_e( 'Delete all RankReady data when the plugin is deleted', 'rankready-ai-llm-seo' ); ?>
							</label>
							<p class="description" style="margin-top:6px;">
								<?php esc_html_e( 'Off by default. Deleting the plugin keeps your data: API keys, settings, every AI summary, every FAQ and every Author Box profile. Install RankReady again and it all comes back.', 'rankready-ai-llm-seo' ); ?>
							</p>
							<p class="description" style="margin-top:6px;color:var(--rnrd-color-danger,#d63638);">
								<strong><?php esc_html_e( 'Warning:', 'rankready-ai-llm-seo' ); ?></strong>
								<?php esc_html_e( 'When on, deleting RankReady permanently removes its settings, API keys, summaries, FAQs, Author Box profiles and crawler log, and deleting RankReady Pro also removes its bulk queues. This cannot be undone. On a multisite network, each site follows its own setting. Leave it off unless you want a clean slate.', 'rankready-ai-llm-seo' ); ?>
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
		if ( ! $screen ) {
			return;
		}

		/*
		 * Two screens, two different sweeps.
		 *
		 * On RankReady's own settings screen the rule is "keep ours, drop
		 * everybody else's", which is what is_own_notice() decides below.
		 *
		 * On the onboarding wizard the rule is "drop all of them, including
		 * ours". The wizard is a three-step linear flow and, unlike every other
		 * RankReady screen, it does not enqueue admin.css: RNRD_Welcome prints
		 * its own <style> block. So a `.rnrd-notice` card rendered there has no
		 * stylesheet and comes out as a stack of unstyled divs with a bare 17px
		 * dashicon for a dismiss control, on the first screen a new user ever
		 * sees. The visual matrix caught it at free-aged/onboarding-1..3, where
		 * the review notice becomes eligible.
		 *
		 * RNRD_Notices and Pro's activation notice already stand down on this
		 * page at source, so what this catches is the rest: the permalink
		 * warning, the nginx .well-known warning, the SWIS and Cloudflare APO
		 * warnings, the duplicate-copy and minimum-version notices, plus every
		 * other plugin on the site. None of them are wrong, none of them belong
		 * in front of a setup flow, and nothing is lost: an unrendered notice is
		 * still registered and shows on the very next screen the person opens.
		 *
		 * The wizard slug is matched as a literal because RNRD_Welcome::MENU_SLUG
		 * is private and this class must not reach into it. It is a hidden page
		 * under options.php, so its screen id carries the slug.
		 *
		 * The Upgrade Pro screen gets the settings-style sweep too (keep ours,
		 * drop everyone else's), added 2026-09-29 for GitHub issue #46: it is a
		 * hidden submenu with a null parent (see add_submenu_page( '', ...,
		 * 'rankready-pro-upgrade', ... ) in rankready.php), so its screen id is
		 * "admin_page_rankready-pro-upgrade" rather than a toplevel_page_ one and
		 * was never covered here. The position fix (notices landing inside
		 * .rnrd-pricing after its h2) is the .wp-header-end marker printed by
		 * the page callback; this sweep only keeps other plugins' notices off
		 * a checkout screen.
		 */
		$is_settings = ( 'toplevel_page_' . self::MENU_SLUG === $screen->id );
		$is_wizard   = ( false !== strpos( (string) $screen->id, 'rankready-welcome' ) );
		$is_pricing  = ( 'admin_page_rankready-pro-upgrade' === $screen->id );
		if ( ! $is_settings && ! $is_wizard && ! $is_pricing ) {
			return;
		}

		global $wp_filter;
		foreach ( array( 'admin_notices', 'all_admin_notices', 'user_admin_notices' ) as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $callback ) {
					if ( ! self::is_core_notice( $callback['function'] ) && ( $is_wizard || ! self::is_own_notice( $callback['function'] ) ) ) {
						unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $id ] );
					}
				}
			}
		}
	}

	/**
	 * Is an admin_notices callback one of RankReady's own?
	 *
	 * Classes prefixed RNRD_ and functions prefixed rnrd_ are ours by naming.
	 * Closures are ours when they were DECLARED in one of our own plugin
	 * directories, which is the only thing a closure can be identified by: it
	 * has no name to match on.
	 *
	 * Treating every closure as third-party, which this did until 2026-09-26,
	 * silently swallowed the five most important messages either plugin can
	 * print, on the one screen where the reader is looking for them: "another
	 * copy of RankReady is already active", "multiple RankReady folders are
	 * active", "RankReady requires WordPress 6.9 or higher", "RankReady Pro
	 * requires the RankReady plugin", and "RankReady Pro is installed but not
	 * activated". All five are registered as closures. A buyer who had pasted
	 * nothing yet opened RankReady, saw a working plugin with no Pro and no
	 * explanation, and the notice that explained it was being removed by us.
	 *
	 * @param mixed $callback The registered callback.
	 */
	private static function is_core_notice( $callback ): bool {
		if ( is_string( $callback ) ) {
			if ( in_array( $callback, array( 'update_nag', 'maintenance_nag', 'site_admin_notice', 'wp_admin_notice', '_admin_notice_post_locked', 'paused_plugins_notice', 'paused_themes_notice', 'wp_recovery_mode_nag' ), true ) ) {
				return true;
			}
			try {
				$file = (string) ( new \ReflectionFunction( $callback ) )->getFileName();
			} catch ( \Throwable $e ) {
				return false;
			}
		} elseif ( $callback instanceof \Closure ) {
			try {
				$file = (string) ( new \ReflectionFunction( $callback ) )->getFileName();
			} catch ( \Throwable $e ) {
				return false;
			}
		} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && is_string( $callback[1] ) ) {
			try {
				$file = (string) ( new \ReflectionMethod( $callback[0], $callback[1] ) )->getFileName();
			} catch ( \Throwable $e ) {
				return false;
			}
		} else {
			return false;
		}
		$file = wp_normalize_path( $file );
		return 0 === strpos( $file, wp_normalize_path( ABSPATH . 'wp-admin/' ) ) || 0 === strpos( $file, wp_normalize_path( ABSPATH . WPINC . '/' ) );
	}

	private static function is_own_notice( $callback ): bool {
		/**
		 * Lets RankReady Pro keep a license notice that is not one of ours.
		 *
		 * The Freemius SDK prints its "check your email to confirm" notice from
		 * its own notice manager. Stripping it left a buyer who had just pasted a
		 * key with a silent redirect and no reason (GitHub #59).
		 *
		 * @param bool  $keep     Whether to keep the callback. Default false.
		 * @param mixed $callback The registered admin_notices callback.
		 */
		if ( apply_filters( 'rnrd_keep_admin_notice', false, $callback ) ) {
			return true;
		}
		if ( is_array( $callback ) && isset( $callback[0] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return 0 === stripos( $class, 'RNRD_' );
		}
		if ( is_string( $callback ) ) {
			return 0 === stripos( $callback, 'rnrd_' );
		}
		if ( $callback instanceof \Closure ) {
			return self::callback_is_ours( $callback );
		}
		return false;
	}

	/**
	 * Was this closure declared inside RankReady Free or RankReady Pro?
	 *
	 * Reflection can throw, and a notice sweep must never be the thing that
	 * fatals an admin page, so anything unexpected answers "not ours" and the
	 * closure is stripped exactly as before.
	 *
	 * @param \Closure $callback The closure to place.
	 */
	private static function callback_is_ours( \Closure $callback ): bool {
		$dirs = array();
		if ( defined( 'RNRD_DIR' ) ) {
			$dirs[] = wp_normalize_path( (string) RNRD_DIR );
		}
		if ( defined( 'RNRD_PRO_DIR' ) ) {
			$dirs[] = wp_normalize_path( (string) RNRD_PRO_DIR );
		}
		if ( ! $dirs ) {
			return false;
		}

		try {
			$file = wp_normalize_path( (string) ( new \ReflectionFunction( $callback ) )->getFileName() );
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( '' === $file ) {
			return false;
		}

		foreach ( $dirs as $dir ) {
			if ( '' !== $dir && 0 === strpos( $file, $dir ) ) {
				return true;
			}
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

		// "Check again" link: drop the cached answer so a server fix shows up now, not in 12 hours.
		if ( isset( $_GET['rnrd_recheck_wk'], $_GET['_rnrd_nonce'] )
			&& current_user_can( 'manage_options' )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_rnrd_nonce'] ) ), 'rnrd_recheck_wk' ) ) {
			delete_transient( 'rnrd_wk_probe_v1' );
		}

		$status = get_transient( 'rnrd_wk_probe_v1' );
		if ( false === $status ) {
			// Park a neutral answer first so a slow or failing probe is never
			// repeated on the next page load, then replace it with the result.
			set_transient( 'rnrd_wk_probe_v1', -1, 10 * MINUTE_IN_SECONDS );
			$resp   = wp_remote_get(
				home_url( '/.well-known/mcp.json' ),
				array( 'timeout' => 1, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/json' ) )
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
		$recheck_url = wp_nonce_url(
			add_query_arg( 'rnrd_recheck_wk', '1', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
			'rnrd_recheck_wk',
			'_rnrd_nonce'
		);
		?>
		<div class="notice notice-warning is-dismissible">
			<p><strong><?php echo esc_html( $headline ); ?></strong></p>
			<p><?php esc_html_e( 'The WebMCP manifest cannot be reached because the server denies /.well-known/ before WordPress runs. Add this one block to your nginx server config (above any dotfile deny rule), then reload nginx. On RunCloud: open Web App, then NGINX Config. Apache and LiteSpeed need no change.', 'rankready-ai-llm-seo' ); ?></p>
			<textarea readonly rows="4" style="width:100%;max-width:560px;font-family:Menlo,Consolas,monospace;font-size:12px;padding:8px;"><?php echo esc_textarea( $snippet ); ?></textarea>
			<p><a href="<?php echo esc_url( $recheck_url ); ?>"><?php esc_html_e( 'I added it, check again', 'rankready-ai-llm-seo' ); ?></a> &middot; <a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss this notice', 'rankready-ai-llm-seo' ); ?></a></p>
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
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), '_rnrd_nginx_wk_dismissed', 1 );
		wp_safe_redirect( remove_query_arg( array( 'rnrd_dismiss_nginx_wk', '_rnrd_nonce' ) ) );
		exit;
	}

	/**
	 * Warn when plain permalinks are active.
	 *
	 * RankReady's virtual endpoints (llms.txt, llms-full.txt, *.md) rely on
	 * WordPress rewrite rules which only work with pretty permalinks. When the
	 * site uses the default ?p=123 structure, those endpoints return 404 and
	 * LLM crawlers cannot access the content.
	 */
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
			. esc_html__( 'Fix it in Settings → Permalinks', 'rankready-ai-llm-seo' )
			. '</a>'
			. '</p></div>';
	}

	// ── Plugin action links ───────────────────────────────────────────────────

	public static function action_links( array $links ): array {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">'
			. esc_html__( 'Settings', 'rankready-ai-llm-seo' )
			. '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Extra links in the plugin's row on the Plugins screen.
	 *
	 * These go in the RIGHT-hand meta column (beside "Visit plugin site"),
	 * not the left action column. The left column is for acting on the plugin
	 * itself (Settings, Deactivate) and stuffing reference links in there is
	 * what makes a plugin row look like an advert.
	 *
	 * Kept to three, all first-party, all directly useful to someone running
	 * this plugin. WP.org guideline 11 asks for upsell to be limited in scope;
	 * none of these is an upsell, and there is deliberately no upgrade link
	 * here because the Pro surface already lives inside the plugin's own admin
	 * where the guideline says it belongs.
	 *
	 * The HostMyBlog site itself is NOT repeated here. WordPress already prints
	 * it as "By HostMyBlog" from the Author URI header on this same row, and a
	 * second link to the same place is the kind of padding that reads as spam.
	 *
	 * Every URL was checked to return 200 before being hardcoded.
	 *
	 * @param string[] $links Existing row meta.
	 * @param string   $file  Plugin file the row belongs to.
	 * @return string[]
	 */
	public static function row_meta( array $links, string $file ): array {
		if ( RNRD_BASENAME !== $file ) {
			return $links;
		}

		$extra = array(
			'https://hostmy.blog/docs/rankready/' => __( 'Docs', 'rankready-ai-llm-seo' ),
			// Own channel. Linked plainly, with no subscribe pop-up forced from
			// inside wp-admin: the link is an offer, not a demand.
			'https://www.youtube.com/@hostmyblog' => __( 'Video guides', 'rankready-ai-llm-seo' ),
			'https://hostmy.blog/blog/'           => __( 'Blog', 'rankready-ai-llm-seo' ),
		);

		foreach ( $extra as $url => $label ) {
			$links[] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $url ),
				esc_html( $label )
			);
		}

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
			echo '<span style="color:#646970;">—</span>';
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
				$lines[] = '<span style="color:#007a1f;white-space:nowrap;" title="' . esc_attr__( 'Summary generated', 'rankready-ai-llm-seo' ) . '">' . esc_html__( 'Summary:', 'rankready-ai-llm-seo' ) . ' ' . '<span class="dashicons dashicons-yes" style="font-size:17px;width:17px;height:17px;line-height:1.1;vertical-align:text-bottom;" aria-hidden="true"></span></span>';
			} else {
				$lines[] = '<span style="color:#646970;" title="' . esc_attr__( 'No summary yet', 'rankready-ai-llm-seo' ) . '">' . esc_html__( 'Summary: —', 'rankready-ai-llm-seo' ) . '</span>';
			}
		}

		if ( $show_faq ) {
			if ( $faq_off ) {
				$lines[] = '<span style="color:#d63638;" title="' . esc_attr__( 'Disabled for this post', 'rankready-ai-llm-seo' ) . '">' . esc_html__( 'FAQ: off', 'rankready-ai-llm-seo' ) . '</span>';
			} elseif ( ! empty( $faq ) ) {
				$lines[] = '<span style="color:#007a1f;white-space:nowrap;" title="' . esc_attr__( 'FAQ generated', 'rankready-ai-llm-seo' ) . '">' . esc_html__( 'FAQ:', 'rankready-ai-llm-seo' ) . ' ' . '<span class="dashicons dashicons-yes" style="font-size:17px;width:17px;height:17px;line-height:1.1;vertical-align:text-bottom;" aria-hidden="true"></span></span>';
			} else {
				$lines[] = '<span style="color:#646970;" title="' . esc_attr__( 'No FAQ yet', 'rankready-ai-llm-seo' ) . '">' . esc_html__( 'FAQ: —', 'rankready-ai-llm-seo' ) . '</span>';
			}
		}

		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
			/*
			 * Dashicon, NOT `.rnrd-mark` and NOT the old `⚠` glyph.
			 *
			 * This column renders on edit.php, and enqueue_admin_assets() returns
			 * early on every screen that is not the settings page, a targeted
			 * post editor or a profile — so NONE of RankReady's stylesheets load
			 * here. A CSS-drawn mark would be an empty 14px box. Dashicons is
			 * enqueued by core on every admin screen, costs this plugin nothing,
			 * and is explicitly allowed by the no-emoji rule.
			 */
			$lines[] = '<span style="color:#8a5a00;white-space:nowrap;" title="' . esc_attr__( 'Excluded from llms.txt, Markdown, OKF, and MCP', 'rankready-ai-llm-seo' ) . '"><span class="dashicons dashicons-warning" style="font-size:15px;width:15px;height:15px;line-height:1.3;vertical-align:text-bottom;" aria-hidden="true"></span> ' . esc_html__( 'Excluded from AI', 'rankready-ai-llm-seo' ) . '</span>';
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
	 * Is this public post type real frontend content RankReady should offer?
	 *
	 * GitHub #53. `public => true` alone is not enough: plugins register
	 * internal types as public. Elementor's `elementor_library` is public with
	 * rewrite => false (templates, no permalink of their own), and its
	 * `e-floating-buttons` is public WITH a rewrite, so it needs the explicit
	 * exclusion list below. Built-in post and page register rewrite => false
	 * too, because core routes them through the permastruct, so the rewrite
	 * test applies to custom types only.
	 *
	 * Used by Pro's post type list and by RNRD_MCP::detected_cpts().
	 *
	 * @param WP_Post_Type $obj Post type object.
	 */
	public static function is_content_post_type( $obj ): bool {
		if ( ! ( $obj instanceof WP_Post_Type ) || empty( $obj->public ) ) {
			return false;
		}
		if ( empty( $obj->_builtin ) && empty( $obj->rewrite ) ) {
			return false;
		}
		/**
		 * Public post types RankReady never offers as content.
		 *
		 * @since 1.4.0
		 *
		 * @param string[] $slugs Post type slugs.
		 */
		$excluded = (array) apply_filters(
			'rnrd_excluded_post_types',
			array( 'attachment', 'elementor_library', 'e-floating-buttons' )
		);
		return ! in_array( $obj->name, $excluded, true );
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
		// feature, surfaced in Free through the upsell panel. The Pro plugin
		// unlocks it by hooking the `rankready_allowed_post_types` filter and
		// returning every public CPT registered on the site.
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
			// FREE gate: post and page only, per $free_allowlist above.
			// Pro overrides this entire return via the filter below.
			if ( ! in_array( $slug, $free_allowlist, true ) ) {
				continue;
			}
			$result[ $slug ] = $obj->labels->singular_name . ' (' . $slug . ')';
		}

		// Stable alphabetical sort by label so the types surface in a
		// consistent order regardless of registration order.
		asort( $result, SORT_NATURAL | SORT_FLAG_CASE );

		/**
		 * Filter the post-type list shown in RankReady tabs.
		 *
		 * @since 1.1.0 — Free returns post and page only. Pro replaces
		 *   the entire array with every public CPT on the site. Third-party
		 *   integrations can also add to the list (e.g. a plugin that wants
		 *   its CPT included regardless of Pro status).
		 *
		 * @param array<string,string> $result slug => "Label (slug)"
		 */
		return apply_filters( 'rankready_allowed_post_types', $result );
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
	// that used to POST the toggle to 'on'; the flow was removed in 1.0.1.
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
			'autogenTimeout'   => __( 'This is taking longer than expected. Reload the page in a minute, or use the Generate button to run it now.', 'rankready-ai-llm-seo' ),
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
		'failedToStart'                 => __( 'Could not reach your site to start. Check your connection and try again.', 'rankready-ai-llm-seo' ),
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
		/*
		 * No glyph in the string. The tick is drawn by CSS on .rnrd-saved
		 * (rule 8: no Unicode icons in user-facing strings, SVG or Dashicons
		 * via CSS only). A literal check mark here also travels into every
		 * translation, where a translator cannot remove it.
		 */
		'saved'                         => __( 'Saved', 'rankready-ai-llm-seo' ),
		'saveFailed'                    => __( 'Save failed', 'rankready-ai-llm-seo' ),
		'settingsSaved'                 => __( 'Settings saved.', 'rankready-ai-llm-seo' ),
		'notSavedReload'                => __( 'Not saved — reload page', 'rankready-ai-llm-seo' ),
		'clearCache'                    => __( 'Clear cache', 'rankready-ai-llm-seo' ),
		'clearing'                      => __( 'Clearing…', 'rankready-ai-llm-seo' ),
		'cacheCleared'                  => __( 'Cache cleared.', 'rankready-ai-llm-seo' ),
		/* translators: %1$d: completed count, %2$d: total count, %3$d: percent complete */
		'postsUpdatedProgress'          => __( '%1$d / %2$d posts updated (%3$d%%)', 'rankready-ai-llm-seo' ),
		'postsReassignedOne'            => __( 'Finished. 1 post reassigned.', 'rankready-ai-llm-seo' ),
		/* translators: %d: number of posts reassigned */
		'postsReassignedMany'           => __( 'Finished. %d posts reassigned.', 'rankready-ai-llm-seo' ),
		'bulkGaveUp'                    => __( 'Could not reach your site after several tries, so the run was paused. Reload the page and try again.', 'rankready-ai-llm-seo' ),
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
		'bulkRegenDone'                 => __( 'Finished. %1$d of %2$d posts regenerated.', 'rankready-ai-llm-seo' ),
		/* translators: %1$d: done count, %2$d: total count, %3$d: percent */
		'bulkProgress'                  => __( '%1$d / %2$d (%3$d%%)', 'rankready-ai-llm-seo' ),
		/*
		 * These two used to hardcode 60 and "30–60" while the Stale Threshold
		 * control on the same card defaulted to 90, so the empty state asserted
		 * a window the widget was not using. Both now take the live threshold.
		 */
		/* translators: %d: stale threshold in days. */
		'freshnessStaleEmpty'           => __( 'No stale posts. Every published post has been touched within the last %d days.', 'rankready-ai-llm-seo' ),
		/* translators: 1: going-stale threshold in days, 2: stale threshold in days. */
		'freshnessGoingStaleEmpty'      => __( 'Nothing in the %1$d–%2$d day window. Plenty of time before any post goes stale.', 'rankready-ai-llm-seo' ),
		/* translators: %d: stale threshold in days. */
		'freshnessPeriodStale'          => __( '%d+ days', 'rankready-ai-llm-seo' ),
		/* translators: 1: going-stale threshold in days, 2: last day before stale. */
		'freshnessPeriodGoingStale'     => __( '%1$d–%2$d days', 'rankready-ai-llm-seo' ),
		/* translators: %d: going-stale threshold in days. */
		'freshnessPeriodFresh'          => __( 'under %d days', 'rankready-ai-llm-seo' ),
		/* translators: %d: stale threshold in days. */
		'freshnessFootGoingStale'       => __( 'Approaching the %d-day mark. Plan a refresh.', 'rankready-ai-llm-seo' ),
		'freshnessFreshEmpty'           => __( 'Newly published or refreshed content shows up here.', 'rankready-ai-llm-seo' ),
		/* translators: %1$d: posts shown in the list, %2$d: total posts in this bucket */
		'freshnessListCapped'           => __( 'Showing the %1$d oldest of %2$d. Refresh these, then scan again for the rest.', 'rankready-ai-llm-seo' ),
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
		'cfDisconnectFailed'            => __( 'Cloudflare did not remove the rule.', 'rankready-ai-llm-seo' ),
		'cfDisconnectForce'             => __( 'Disconnect RankReady anyway? The cache rule stays in Cloudflare until you delete it there.', 'rankready-ai-llm-seo' ),
		'runDiagnostics'                => __( 'Run Diagnostics', 'rankready-ai-llm-seo' ),
		'probingEndpoints'              => __( 'Probing endpoints…', 'rankready-ai-llm-seo' ),
		'noResults'                     => __( 'No results.', 'rankready-ai-llm-seo' ),
		'diagnosticsCompleted'          => __( 'Completed.', 'rankready-ai-llm-seo' ),
		'copiedToClipboard'             => __( 'Copied to clipboard', 'rankready-ai-llm-seo' ),
		'copied'                        => __( 'Copied', 'rankready-ai-llm-seo' ),
		/* translators: short status word in the diagnostics summary, as in "12 pass". */
		'diagPass'                      => __( 'pass', 'rankready-ai-llm-seo' ),
		/* translators: short status word in the diagnostics summary, as in "3 warn". */
		'diagWarn'                      => __( 'warn', 'rankready-ai-llm-seo' ),
		/* translators: short status word in the diagnostics summary, as in "1 fail". */
		'diagFail'                      => __( 'fail', 'rankready-ai-llm-seo' ),
		/* translators: short status word in the diagnostics summary, as in "4 info". */
		'diagInfo'                      => __( 'info', 'rankready-ai-llm-seo' ),
		/* translators: accessible label on the freshness table's urgency column. */
		'urgencyCritical'               => __( 'Critical', 'rankready-ai-llm-seo' ),
		'urgencyHigh'                   => __( 'High', 'rankready-ai-llm-seo' ),
		'urgencyModerate'               => __( 'Moderate', 'rankready-ai-llm-seo' ),
		'colSummary'                    => __( 'Summary', 'rankready-ai-llm-seo' ),
		'colFaq'                        => __( 'FAQ', 'rankready-ai-llm-seo' ),
		'yes'                           => __( 'Yes', 'rankready-ai-llm-seo' ),
		'no'                            => __( 'No', 'rankready-ai-llm-seo' ),
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
		'stalePostsFoot'                => __( 'stale last-modified date', 'rankready-ai-llm-seo' ),
		'totalPublishedLabel'           => __( 'Total published', 'rankready-ai-llm-seo' ),
		'totalPublishedPeriod'          => __( 'All post types', 'rankready-ai-llm-seo' ),
		'totalPublishedFoot'            => __( 'indexed for freshness scan', 'rankready-ai-llm-seo' ),
		/* translators: %d: number of stale posts */
		'stalePostsFound'               => __( '%d stale posts found (showing top 50)', 'rankready-ai-llm-seo' ),
	);
	}
}
