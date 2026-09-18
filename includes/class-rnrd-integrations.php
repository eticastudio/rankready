<?php
/**
 * Third-party integrations — page builders, multilingual plugins, WooCommerce.
 *
 * This class keeps all external-plugin-specific logic out of RNRD_Markdown,
 * hooking into it via filters. Every filter callback checks whether its
 * target plugin is actually active before doing any work, so the class is
 * safe to load unconditionally.
 *
 * Filters consumed:
 *   rankready_should_serve_markdown  — Can markdown be served for this request?
 *   rankready_post_raw_content       — Supply / transform raw HTML before MD conversion.
 *   rankready_translate_post         — Swap a post for its translated counterpart.
 *   rankready_translation_md_urls    — Provide per-language .md hreflang URLs.
 *   rankready_detect_language        — Resolve the visitor's request language.
 *   rankready_resolved_locale        — Resolve the locale string for a post.
 *
 * @package RankReady
 * @since   1.3.2-beta3
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Integrations {

	public static function init(): void {
		// ── Markdown suppression (TranslatePress non-default) ────────────
		add_filter( 'rankready_should_serve_markdown', array( self::class, 'suppress_translatepress_non_default' ) );

		// ── Page builder content filters (rankready_post_raw_content) ────
		// 10–19: builders render content + strip wrappers.
		add_filter( 'rankready_post_raw_content', array( self::class, 'filter_elementor_content' ), 10, 2 );
		add_filter( 'rankready_post_raw_content', array( self::class, 'filter_beaver_builder_content' ), 11, 2 );
		add_filter( 'rankready_post_raw_content', array( self::class, 'filter_divi_content' ), 12, 2 );
		add_filter( 'rankready_post_raw_content', array( self::class, 'filter_oxygen_content' ), 13, 2 );
		add_filter( 'rankready_post_raw_content', array( self::class, 'filter_bricks_content' ), 14, 2 );
		add_filter( 'rankready_post_raw_content', array( self::class, 'filter_wpbakery_content' ), 15, 2 );
		add_filter( 'rankready_post_raw_content', array( self::class, 'filter_avada_content' ), 16, 2 );
		add_filter( 'rankready_post_raw_content', array( self::class, 'filter_muffin_builder_content' ), 17, 2 );

		// 90: shortcode fallback after all builder filters.
		add_filter( 'rankready_post_raw_content', array( self::class, 'filter_run_shortcodes' ), 90, 2 );

		// 95: strip RankReady's own AI Summary/FAQ/Author Box output.
		add_filter( 'rankready_post_raw_content', array( self::class, 'filter_strip_rankready_output' ), 95, 2 );

		// 99: WooCommerce cart/checkout stripping — runs last.
		add_filter( 'rankready_post_raw_content', array( self::class, 'filter_strip_woocommerce_blocks' ), 99, 2 );

		// ── Multilingual: post translation (WPML, Polylang) ─────────────
		add_filter( 'rankready_translate_post', array( self::class, 'translate_post_wpml' ), 10, 2 );
		add_filter( 'rankready_translate_post', array( self::class, 'translate_post_polylang' ), 11, 2 );

		// ── Multilingual: hreflang .md URLs ──────────────────────────────
		add_filter( 'rankready_translation_md_urls', array( self::class, 'hreflang_wpml' ), 10, 2 );
		add_filter( 'rankready_translation_md_urls', array( self::class, 'hreflang_polylang' ), 11, 2 );

		// ── Multilingual: request language detection ─────────────────────
		add_filter( 'rankready_detect_language', array( self::class, 'detect_lang_query_var' ), 10 );
		add_filter( 'rankready_detect_language', array( self::class, 'detect_lang_wpml' ), 11 );
		add_filter( 'rankready_detect_language', array( self::class, 'detect_lang_polylang' ), 12 );
		add_filter( 'rankready_detect_language', array( self::class, 'detect_lang_translatepress' ), 13 );
		add_filter( 'rankready_detect_language', array( self::class, 'detect_lang_accept_header' ), 99 );

		// ── Multilingual: resolved locale for a post ─────────────────────
		add_filter( 'rankready_resolved_locale', array( self::class, 'locale_wpml' ), 10, 2 );
		add_filter( 'rankready_resolved_locale', array( self::class, 'locale_polylang' ), 11, 2 );
		add_filter( 'rankready_resolved_locale', array( self::class, 'locale_translatepress' ), 12, 2 );
	}

	// ═════════════════════════════════════════════════════════════════════════
	// MARKDOWN SUPPRESSION
	// ═════════════════════════════════════════════════════════════════════════

	/**
	 * TranslatePress stores all translations in the same post and translates
	 * at render time — markdown cannot be generated per-language. Return
	 * false to suppress markdown serving for non-default languages.
	 *
	 * @param bool $should_serve Current value.
	 * @return bool
	 */
	public static function suppress_translatepress_non_default( bool $should_serve ): bool {
		if ( ! $should_serve ) {
			return false;
		}

		if ( ! defined( 'TRP_PLUGIN_VERSION' ) ) {
			return true;
		}

		global $TRP_LANGUAGE;
		if ( empty( $TRP_LANGUAGE ) ) {
			return true;
		}

		$settings = (array) get_option( 'trp_settings', array() );
		$default  = (string) ( $settings['default-language'] ?? '' );

		if ( empty( $default ) || $TRP_LANGUAGE === $default ) {
			return true;
		}

		return false;
	}

	// ═════════════════════════════════════════════════════════════════════════
	// PAGE BUILDER CONTENT FILTERS
	// ═════════════════════════════════════════════════════════════════════════

	/**
	 * Elementor: render the Elementor-built content and strip wrappers.
	 */
	public static function filter_elementor_content( string $html, WP_Post $post ): string {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return $html;
		}

		$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			return $html;
		}

		// Force frontend rendering mode — during AJAX/admin save requests
		// Elementor may think it is in edit mode, injecting inline-editing
		// wrappers or extra attributes into the output.
		$elementor = \Elementor\Plugin::instance();
		$was_edit  = false;
		if ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) ) {
			$was_edit = $elementor->editor->is_edit_mode();
			$elementor->editor->set_edit_mode( false );
		}

		$rendered = '';
		if ( isset( $elementor->frontend ) && method_exists( $elementor->frontend, 'get_builder_content' ) ) {
			$rendered = $elementor->frontend->get_builder_content( $post->ID, false );
		}

		if ( isset( $elementor->editor ) && $was_edit ) {
			$elementor->editor->set_edit_mode( true );
		}

		if ( ! empty( $rendered ) ) {
			return self::strip_elementor_wrappers( $rendered );
		}

		return $html;
	}

	private static function strip_elementor_wrappers( string $html ): string {
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:elementor-|e-con|e-child)[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<section[^>]*class="[^"]*elementor-[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<div[^>]*class="[^"]*elementor-inline-editing[^"]*"[^>]*>/si', '', $html );
		return $html;
	}

	/**
	 * Beaver Builder: render the BB-built content and strip wrappers.
	 */
	public static function filter_beaver_builder_content( string $html, WP_Post $post ): string {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return $html;
		}

		$bb_enabled = get_post_meta( $post->ID, '_fl_builder_enabled', true );
		if ( empty( $bb_enabled ) ) {
			return $html;
		}

		if ( method_exists( 'FLBuilderModel', 'set_post_id' ) ) {
			\FLBuilderModel::set_post_id( $post->ID );
		}

		if ( method_exists( 'FLBuilder', 'render_content_by_id' ) ) {
			ob_start();
			\FLBuilder::render_content_by_id( $post->ID );
			$rendered = ob_get_clean();
			if ( ! empty( $rendered ) ) {
				return self::strip_beaver_wrappers( $rendered );
			}
		}

		return $html;
	}

	private static function strip_beaver_wrappers( string $html ): string {
		$html = preg_replace( '/<div[^>]*class="[^"]*fl-[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<div[^>]*class="[^"]*fl-builder-overlay[^"]*"[^>]*>.*?<\/div>/si', '', $html );
		return $html;
	}

	/**
	 * Divi: render the Divi-built content and strip wrappers.
	 */
	public static function filter_divi_content( string $html, WP_Post $post ): string {
		if ( ! defined( 'ET_BUILDER_PLUGIN_DIR' ) && ! defined( 'ET_BUILDER_THEME' ) ) {
			return $html;
		}

		$divi_enabled = get_post_meta( $post->ID, '_et_pb_use_builder', true );
		if ( 'on' !== $divi_enabled ) {
			return $html;
		}

		if ( function_exists( 'et_builder_render_layout' ) ) {
			$rendered = et_builder_render_layout( $post->post_content );
			if ( ! empty( $rendered ) ) {
				return self::strip_divi_wrappers( $rendered );
			}
		}

		return $html;
	}

	private static function strip_divi_wrappers( string $html ): string {
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:et_pb_|et_builder_)[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<div[^>]*(?:id|class)="[^"]*et-fb-[^"]*"[^>]*>.*?<\/div>/si', '', $html );
		return $html;
	}

	/**
	 * Oxygen Builder: render content and strip wrappers.
	 */
	public static function filter_oxygen_content( string $html, WP_Post $post ): string {
		if ( ! defined( 'CT_VERSION' ) ) {
			return $html;
		}

		$oxygen_shortcodes = get_post_meta( $post->ID, 'ct_builder_shortcodes', true );
		if ( empty( $oxygen_shortcodes ) ) {
			return $html;
		}

		$rendered = do_shortcode( $oxygen_shortcodes );
		if ( ! empty( $rendered ) ) {
			return self::strip_oxygen_wrappers( $rendered );
		}

		return $html;
	}

	private static function strip_oxygen_wrappers( string $html ): string {
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:ct-section|ct-inner-content|oxy-)[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:oxygen-toolbar|ct-component-outline|oxy-editor)[^"]*"[^>]*>.*?<\/div>/si', '', $html );
		return $html;
	}

	/**
	 * Bricks Builder: render content and strip wrappers.
	 */
	public static function filter_bricks_content( string $html, WP_Post $post ): string {
		if ( ! defined( 'BRICKS_VERSION' ) ) {
			return $html;
		}

		$bricks_data = get_post_meta( $post->ID, '_bricks_page_content_2', true );
		if ( empty( $bricks_data ) ) {
			return $html;
		}

		if ( class_exists( '\\Bricks\\Frontend' ) && method_exists( '\\Bricks\\Frontend', 'render_data' ) ) {
			ob_start();
			\Bricks\Frontend::render_data( $bricks_data );
			$rendered = ob_get_clean();
			if ( ! empty( $rendered ) ) {
				return self::strip_bricks_wrappers( $rendered );
			}
		}

		return $html;
	}

	private static function strip_bricks_wrappers( string $html ): string {
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:brxe-|bricks-)[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<div[^>]*(?:id|class)="[^"]*(?:bricks-panel|bricks-toolbar|bricks-builder)[^"]*"[^>]*>.*?<\/div>/si', '', $html );
		return $html;
	}

	/**
	 * WPBakery: render content and strip wrappers.
	 */
	public static function filter_wpbakery_content( string $html, WP_Post $post ): string {
		if ( ! defined( 'WPB_VC_VERSION' ) ) {
			return $html;
		}

		$wpb_status = get_post_meta( $post->ID, '_wpb_vc_js_status', true );
		if ( 'true' !== $wpb_status ) {
			return $html;
		}

		if ( function_exists( 'wpb_js_remove_wpautop' ) ) {
			$rendered = wpb_js_remove_wpautop( $post->post_content, true );
			if ( ! empty( $rendered ) ) {
				return self::strip_wpbakery_wrappers( $rendered );
			}
		}

		return $html;
	}

	private static function strip_wpbakery_wrappers( string $html ): string {
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:vc_|wpb_)[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:vc_controls|vc_empty-element)[^"]*"[^>]*>.*?<\/div>/si', '', $html );
		return $html;
	}

	/**
	 * Avada Fusion Builder: render shortcode-based content and strip wrappers.
	 */
	public static function filter_avada_content( string $html, WP_Post $post ): string {
		if ( ! defined( 'FUSION_BUILDER_VERSION' ) ) {
			return $html;
		}

		$fb_status = get_post_meta( $post->ID, 'fusion_builder_status', true );
		if ( 'active' !== $fb_status ) {
			return $html;
		}

		$rendered = do_shortcode( $post->post_content );
		if ( ! empty( $rendered ) ) {
			return self::strip_avada_wrappers( $rendered );
		}

		return $html;
	}

	private static function strip_avada_wrappers( string $html ): string {
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:fusion-builder-|fusion-layout-|fusion-fullwidth|fusion-column-wrapper|fusion-flex-)[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<section[^>]*class="[^"]*fusion-[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:fusion-builder-module-controls|fusion-builder-live-editor)[^"]*"[^>]*>.*?<\/div>/si', '', $html );
		return $html;
	}

	/**
	 * BeTheme Muffin Builder: render content and strip wrappers.
	 */
	public static function filter_muffin_builder_content( string $html, WP_Post $post ): string {
		if ( ! defined( 'MFN_THEME_VERSION' ) ) {
			return $html;
		}

		$mfn_items = get_post_meta( $post->ID, 'mfn-page-items', true );
		if ( empty( $mfn_items ) ) {
			return $html;
		}

		if ( class_exists( 'Mfn_Builder_Front' ) ) {
			$mfn_builder = new \Mfn_Builder_Front( $post->ID );

			// Force frontend rendering mode. The constructor sets
			// $is_bebuilder = true during AJAX requests (save_post via
			// Gutenberg/REST), causing the builder to output drag handles,
			// edit buttons, and other editor UI instead of clean frontend
			// HTML. Save + restore the static property to avoid side effects.
			$was_bebuilder = \Mfn_Builder_Front::$is_bebuilder;
			\Mfn_Builder_Front::$is_bebuilder = false;

			ob_start();
			$mfn_builder->show( false, true );
			$rendered = ob_get_clean();

			\Mfn_Builder_Front::$is_bebuilder = $was_bebuilder;

			if ( ! empty( $rendered ) ) {
				return self::strip_muffin_wrappers( $rendered );
			}
		}

		return $html;
	}

	private static function strip_muffin_wrappers( string $html ): string {
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:mcb-|mfn-)[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<section[^>]*class="[^"]*(?:mcb-|mfn-)[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<a[^>]*class="[^"]*(?:btn-section-add|mfn-option-btn|mfn-element-edit|mfn-element-delete|mfn-element-drag|mfn-module-clone|mfn-section-add)[^"]*"[^>]*>.*?<\/a>/si', '', $html );
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:section-header|mfn-header|wrap-header|item-header|mfn-drag-helper|options-group|mfn-option-dropdown)[^"]*"[^>]*>.*?<\/div>/si', '', $html );
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:mfn-shape-divider|mcb-background-overlay|mcb-wrap-background-overlay)[^"]*"[^>]*>.*?<\/div>/si', '', $html );
		return $html;
	}

	// ═════════════════════════════════════════════════════════════════════════
	// SHORTCODE & CONTENT STRIPPING FILTERS
	// ═════════════════════════════════════════════════════════════════════════

	/**
	 * Shortcode fallback: execute remaining shortcodes for non-builder posts.
	 *
	 * Runs at priority 90 — after all page builder filters (which handle
	 * their own shortcode execution) but before WooCommerce cleanup at 99.
	 */
	public static function filter_run_shortcodes( string $html, WP_Post $post ): string {
		if ( empty( $html ) ) {
			return $html;
		}

		return do_shortcode( $html );
	}

	/**
	 * Strip RankReady's own AI Summary, AI FAQ, and Author Box output.
	 *
	 * These render AI-generated content derived FROM the post itself.
	 * Including them in cached markdown would be self-referencing and circular.
	 */
	public static function filter_strip_rankready_output( string $html, WP_Post $post ): string {
		if ( empty( $html ) ) {
			return $html;
		}

		// Gutenberg block comments.
		$html = preg_replace( '/<!--\s*wp:rankready\/[a-z-]+\s*(?:\/)?-->.*?<!--\s*\/wp:rankready\/[a-z-]+\s*-->/si', '', $html );
		$html = preg_replace( '/<!--\s*wp:rankready\/[a-z-]+\s*\/-->/si', '', $html );

		// Shortcodes.
		$html = preg_replace( '/\[rankready_(?:summary|faq|author)\b[^\]]*\]/si', '', $html );

		// Rendered HTML output.
		$html = preg_replace( '/<div[^>]*class="[^"]*\brnrd-summary\b[^"]*"[^>]*>.*?<\/div>/si', '', $html );
		$html = preg_replace( '/<div[^>]*class="[^"]*\brnrd-faq\b[^"]*"[^>]*>.*?<\/div>/si', '', $html );
		$html = preg_replace( '/<div[^>]*class="[^"]*\brnrd-author-box\b[^"]*"[^>]*>.*?<\/div>/si', '', $html );

		// Elementor widget output.
		$html = preg_replace( '/<div[^>]*class="[^"]*\belementor-widget-rnrd_[a-z_]+\b[^"]*"[^>]*>.*?<\/div>/si', '', $html );

		return $html;
	}

	/**
	 * Strip WooCommerce cart, checkout, and account blocks/shortcode output.
	 */
	public static function filter_strip_woocommerce_blocks( string $html, WP_Post $post ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $html;
		}

		if ( empty( $html ) ) {
			return $html;
		}

		if ( false === stripos( $html, 'woocommerce' ) && false === stripos( $html, 'wc-block' ) ) {
			return $html;
		}

		// WooCommerce Block elements (Gutenberg blocks).
		$wc_block_classes = array(
			'wp-block-woocommerce-cart',
			'wp-block-woocommerce-checkout',
			'wp-block-woocommerce-customer-account',
			'wp-block-woocommerce-mini-cart',
		);

		foreach ( $wc_block_classes as $class ) {
			$html = preg_replace(
				'/<div[^>]*class="[^"]*' . preg_quote( $class, '/' ) . '[^"]*"[^>]*>.*?<\/div>/si',
				'',
				$html
			);
		}

		// WooCommerce classic shortcode output.
		$raw = (string) $post->post_content;
		$is_wc_transactional = (
			false !== strpos( $raw, 'woocommerce_cart' )
			|| false !== strpos( $raw, 'woocommerce_checkout' )
			|| false !== strpos( $raw, 'woocommerce_my_account' )
			|| false !== strpos( $raw, 'wp:woocommerce/cart' )
			|| false !== strpos( $raw, 'wp:woocommerce/checkout' )
			|| false !== strpos( $raw, 'wp:woocommerce/customer-account' )
		);

		if ( $is_wc_transactional ) {
			$html = preg_replace(
				'/<div[^>]*class="[^"]*\bwoocommerce\b[^"]*"[^>]*>.*?<\/div>/si',
				'',
				$html
			);
		}

		// Notices wrapper.
		$html = preg_replace(
			'/<div[^>]*class="[^"]*woocommerce-notices-wrapper[^"]*"[^>]*>.*?<\/div>/si',
			'',
			$html
		);

		return $html;
	}

	// ═════════════════════════════════════════════════════════════════════════
	// MULTILINGUAL — POST TRANSLATION
	// ═════════════════════════════════════════════════════════════════════════

	/**
	 * WPML: swap to translated post via wpml_object_id.
	 *
	 * @param WP_Post $post Resolved post.
	 * @param string  $lang Detected language code.
	 * @return WP_Post Translated or original post.
	 */
	public static function translate_post_wpml( WP_Post $post, string $lang ): WP_Post {
		if ( ! has_filter( 'wpml_object_id' ) ) {
			return $post;
		}

		$translated_id = apply_filters( 'wpml_object_id', $post->ID, $post->post_type, false, $lang ?: null );
		if ( $translated_id && (int) $translated_id !== $post->ID ) {
			$translated = get_post( (int) $translated_id );
			if ( $translated instanceof WP_Post && 'publish' === $translated->post_status ) {
				return $translated;
			}
		}

		return $post;
	}

	/**
	 * Polylang: swap to translated post via pll_get_post.
	 *
	 * @param WP_Post $post Resolved post.
	 * @param string  $lang Detected language code.
	 * @return WP_Post Translated or original post.
	 */
	public static function translate_post_polylang( WP_Post $post, string $lang ): WP_Post {
		if ( empty( $lang ) || ! function_exists( 'pll_get_post' ) ) {
			return $post;
		}

		$translated_id = pll_get_post( $post->ID, $lang );
		if ( $translated_id && (int) $translated_id !== $post->ID ) {
			$translated = get_post( (int) $translated_id );
			if ( $translated instanceof WP_Post && 'publish' === $translated->post_status ) {
				return $translated;
			}
		}

		return $post;
	}

	// ═════════════════════════════════════════════════════════════════════════
	// MULTILINGUAL — HREFLANG .MD URLS
	// ═════════════════════════════════════════════════════════════════════════

	/**
	 * WPML: emit per-language .md alternate URLs.
	 *
	 * @param array<string,string> $urls Existing URLs.
	 * @param WP_Post              $post Source post.
	 * @return array<string,string>
	 */
	public static function hreflang_wpml( array $urls, WP_Post $post ): array {
		if ( ! empty( $urls ) || ! has_filter( 'wpml_active_languages' ) ) {
			return $urls;
		}

		$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 1 ) );
		if ( ! is_array( $langs ) ) {
			return $urls;
		}

		foreach ( $langs as $code => $info ) {
			$translated_id = apply_filters( 'wpml_object_id', $post->ID, $post->post_type, false, $code );
			if ( $translated_id ) {
				$translated = get_post( (int) $translated_id );
				if ( $translated instanceof WP_Post && 'publish' === $translated->post_status ) {
					$urls[ sanitize_key( (string) $code ) ] = RNRD_Markdown::get_md_url( $translated );
				}
			}
		}

		return $urls;
	}

	/**
	 * Polylang: emit per-language .md alternate URLs.
	 *
	 * @param array<string,string> $urls Existing URLs.
	 * @param WP_Post              $post Source post.
	 * @return array<string,string>
	 */
	public static function hreflang_polylang( array $urls, WP_Post $post ): array {
		if ( ! empty( $urls ) || ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_get_post' ) ) {
			return $urls;
		}

		$langs = pll_languages_list();
		if ( ! is_array( $langs ) ) {
			return $urls;
		}

		foreach ( $langs as $code ) {
			$translated_id = pll_get_post( $post->ID, $code );
			if ( $translated_id ) {
				$translated = get_post( (int) $translated_id );
				if ( $translated instanceof WP_Post && 'publish' === $translated->post_status ) {
					$urls[ sanitize_key( (string) $code ) ] = RNRD_Markdown::get_md_url( $translated );
				}
			}
		}

		return $urls;
	}

	// ═════════════════════════════════════════════════════════════════════════
	// MULTILINGUAL — REQUEST LANGUAGE DETECTION
	// ═════════════════════════════════════════════════════════════════════════

	/**
	 * Priority 10: explicit ?lang= query var.
	 */
	public static function detect_lang_query_var( string $lang ): string {
		if ( '' !== $lang ) {
			return $lang;
		}

		if ( ! empty( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( wp_unslash( (string) $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return '';
	}

	/**
	 * Priority 11: WPML current language.
	 */
	public static function detect_lang_wpml( string $lang ): string {
		if ( '' !== $lang ) {
			return $lang;
		}

		if ( has_filter( 'wpml_current_language' ) ) {
			$wpml_lang = apply_filters( 'wpml_current_language', null );
			if ( ! empty( $wpml_lang ) ) {
				return sanitize_key( (string) $wpml_lang );
			}
		}

		return '';
	}

	/**
	 * Priority 12: Polylang current language.
	 */
	public static function detect_lang_polylang( string $lang ): string {
		if ( '' !== $lang ) {
			return $lang;
		}

		if ( function_exists( 'pll_current_language' ) ) {
			$pll_lang = pll_current_language();
			if ( ! empty( $pll_lang ) ) {
				return sanitize_key( (string) $pll_lang );
			}
		}

		return '';
	}

	/**
	 * Priority 13: TranslatePress global language.
	 */
	public static function detect_lang_translatepress( string $lang ): string {
		if ( '' !== $lang ) {
			return $lang;
		}

		if ( defined( 'TRP_PLUGIN_VERSION' ) ) {
			global $TRP_LANGUAGE;
			if ( ! empty( $TRP_LANGUAGE ) ) {
				return sanitize_key( (string) $TRP_LANGUAGE );
			}
		}

		return '';
	}

	/**
	 * Priority 99: Accept-Language header (best q-value) — last resort.
	 */
	public static function detect_lang_accept_header( string $lang ): string {
		if ( '' !== $lang ) {
			return $lang;
		}

		if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			return '';
		}

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
				$best   = strtok( $tag, '-' );
			}
		}

		return '' !== $best ? sanitize_key( $best ) : '';
	}

	// ═════════════════════════════════════════════════════════════════════════
	// MULTILINGUAL — RESOLVED LOCALE
	// ═════════════════════════════════════════════════════════════════════════

	/**
	 * WPML: resolve post language via wpml_post_language_details.
	 *
	 * @param string  $locale Current resolved locale.
	 * @param WP_Post $post   The post.
	 * @return string
	 */
	public static function locale_wpml( string $locale, WP_Post $post ): string {
		if ( '' !== $locale ) {
			return $locale;
		}

		if ( function_exists( 'apply_filters' ) && has_filter( 'wpml_post_language_details' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post->ID );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				return sanitize_key( (string) $details['language_code'] );
			}
		}

		return '';
	}

	/**
	 * Polylang: resolve post language via pll_get_post_language.
	 *
	 * @param string  $locale Current resolved locale.
	 * @param WP_Post $post   The post.
	 * @return string
	 */
	public static function locale_polylang( string $locale, WP_Post $post ): string {
		if ( '' !== $locale ) {
			return $locale;
		}

		if ( function_exists( 'pll_get_post_language' ) ) {
			$pll = pll_get_post_language( $post->ID );
			if ( ! empty( $pll ) ) {
				return sanitize_key( (string) $pll );
			}
		}

		return '';
	}

	/**
	 * TranslatePress: use global $TRP_LANGUAGE.
	 *
	 * @param string  $locale Current resolved locale.
	 * @param WP_Post $post   The post.
	 * @return string
	 */
	public static function locale_translatepress( string $locale, WP_Post $post ): string {
		if ( '' !== $locale ) {
			return $locale;
		}

		if ( defined( 'TRP_PLUGIN_VERSION' ) ) {
			global $TRP_LANGUAGE;
			if ( ! empty( $TRP_LANGUAGE ) ) {
				return sanitize_key( (string) $TRP_LANGUAGE );
			}
		}

		return '';
	}
}
