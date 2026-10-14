<?php
/**
 * Gutenberg block registration and shared editor/frontend assets.
 *
 * Feature runtime (render, auto-display, schema) lives in RNRD_Summary,
 * RNRD_Faq, and RNRD_Author_Box. Shared helpers live in RNRD_Util.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Block {

	public static function init(): void {
		add_action( 'init',                        array( self::class, 'register_summary_block' ) );
		add_action( 'init',                        array( self::class, 'register_faq_block' ) );
		add_action( 'init',                        array( self::class, 'register_author_box_block' ) );

		// Register (not enqueue) the one scoped stylesheet early so BOTH the
		// Gutenberg conditional enqueue AND the Elementor widgets' get_style_depends()
		// can reference it. This is what makes the CSS load ONLY on pages that
		// actually render a RankReady block/widget — no global asset weight.
		add_action( 'wp_enqueue_scripts',                 array( self::class, 'register_style_handle' ), 1 );
		add_action( 'elementor/frontend/after_register_styles', array( self::class, 'register_style_handle' ) );

		// Dedicated "RankReady" block-inserter category (mirrors the Elementor
		// panel category — keep both in sync).
		add_filter( 'block_categories_all',        array( self::class, 'register_block_category' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
		add_action( 'wp_enqueue_scripts',          array( self::class, 'enqueue_frontend_assets' ) );
		// NOTE: HowTo/ItemList auto-detection is a PRO engine. Its emitters and
		// scanners are registered by the Pro add-on, not here. Summary runtime
		// behavior (auto-display + schema merge) now lives in RNRD_Summary / RNRD_Schema.
	}

	/**
	 * Add a "RankReady" category to the block inserter so the AI Summary, FAQ,
	 * and Author Box blocks group together (mirrors the Elementor panel
	 * category). Appended so it sits BELOW the core categories, not pinned to
	 * the top of the inserter.
	 *
	 * @param array $categories Existing block categories.
	 * @return array
	 */
	public static function register_block_category( array $categories ): array {
		foreach ( $categories as $cat ) {
			if ( isset( $cat['slug'] ) && 'rankready' === $cat['slug'] ) {
				return $categories; // already registered
			}
		}
		$categories[] = array(
			'slug'  => 'rankready',
			'title' => __( 'RankReady', 'rankready-ai-llm-seo' ),
			'icon'  => null,
		);
		return $categories;
	}

	// ── Block registration ────────────────────────────────────────────────────

	public static function register_summary_block(): void {
		register_block_type( 'rankready/ai-summary', array(
			'api_version'     => 3,
			'render_callback' => array( 'RNRD_Summary', 'render_block' ),
			'attributes'      => RNRD_Summary::block_attributes(),
		) );
	}

	// ── Author Box Block registration ────────────────────────────────────────

	public static function register_author_box_block(): void {
		register_block_type( 'rankready/author-box', array(
			'api_version'     => 3,
			'render_callback' => array( 'RNRD_Author_Box', 'render_block' ),
			'attributes'      => RNRD_Author_Box::block_attributes(),
		) );
	}

	// ── FAQ Block registration ────────────────────────────────────────────────

	public static function register_faq_block(): void {
		register_block_type( 'rankready/faq', array(
			'api_version'     => 3,
			'render_callback' => array( 'RNRD_Faq', 'render_block' ),
			'attributes'      => RNRD_Faq::block_attributes(),
		) );
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public static function enqueue_editor_assets(): void {
		$deps = array(
			'rnrd-i18n',
			'wp-blocks', 'wp-element', 'wp-block-editor',
			'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n',
		);

		wp_enqueue_script(
			'rnrd-block-editor',
			RNRD_URL . 'assets/block.js',
			$deps,
			rnrd_asset_ver( 'assets/block.js' ),
			true
		);

		wp_enqueue_script(
			'rnrd-faq-block-editor',
			RNRD_URL . 'assets/faq-block.js',
			$deps,
			rnrd_asset_ver( 'assets/faq-block.js' ),
			true
		);

		wp_enqueue_script(
			'rnrd-author-box-block-editor',
			RNRD_URL . 'assets/author-box-block.js',
			$deps,
			rnrd_asset_ver( 'assets/author-box-block.js' ),
			true
		);

		// Light user list for the block author picker.
		$users_data = array();
		$users      = get_users( array(
			'number'  => 100,
			'fields'  => array( 'ID', 'display_name' ),
			'orderby' => 'display_name',
		) );
		foreach ( $users as $u ) {
			$users_data[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name );
		}

		wp_localize_script( 'rnrd-block-editor', 'rnrdBlockData', array(
			'i18n'     => self::editor_js_i18n(),
			'defaults' => array(
				'label'         => (string) get_option( RNRD_OPT_LABEL, __( 'Key Takeaways', 'rankready-ai-llm-seo' ) ),
				'showLabel'     => (bool) get_option( RNRD_OPT_SHOW_LABEL, '1' ),
				'headingTag'    => (string) get_option( RNRD_OPT_HEADING_TAG, 'h4' ),
				'authorHeading' => RNRD_Author_Box::heading_text(),
				'authorTag'     => (string) get_option( RNRD_OPT_AUTHOR_HEADING_TAG, 'h3' ),
			),
			'users' => $users_data,
			// Editor-only hints: lets a block say why it renders nothing on the front end.
			'features' => array(
				'summary' => self::feature_state( 'RNRD_Summary' ),
				'faq'     => self::feature_state( 'RNRD_Faq' ),
				'author'  => self::feature_state( 'RNRD_Author_Box' ),
			),
		) );
	}

	/**
	 * Whether a feature is on and for which public post types. Used only to word an editor placeholder.
	 *
	 * @param string $class_name RNRD_Summary, RNRD_Faq or RNRD_Author_Box.
	 * @return array{on: bool, types: string[]}
	 */
	private static function feature_state( string $class_name ): array {
		$state = array( 'on' => true, 'types' => array() );
		if ( ! class_exists( $class_name ) || ! method_exists( $class_name, 'is_enabled' ) ) {
			return $state; // Unknown: say nothing rather than a wrong hint.
		}
		$state['on'] = (bool) call_user_func( array( $class_name, 'is_enabled' ) );
		foreach ( array_keys( get_post_types( array( 'public' => true ) ) ) as $pt ) {
			if ( call_user_func( array( $class_name, 'is_post_type_enabled' ), (string) $pt ) ) {
				$state['types'][] = (string) $pt;
			}
		}
		return $state;
	}

	/**
	 * Register the single scoped stylesheet under the shared handle. Idempotent
	 * (wp_register_style is a no-op if already registered). Never enqueues here.
	 */
	public static function register_style_handle(): void {
		if ( ! wp_style_is( 'rankready-style', 'registered' ) ) {
			wp_register_style( 'rankready-style', RNRD_URL . 'assets/style.css', array(), rnrd_asset_ver( 'assets/style.css' ) );
		}
	}

	public static function enqueue_frontend_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		// Use get_queried_object_id() — reliable even when a theme builder template
		// overrides the layout (get_the_ID() can return the template post ID instead).
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		// Always load styles when summary, FAQ, or author-box data may render.
		// Display can come from: Gutenberg block, Elementor widget, shortcode, or auto-display.
		$has_summary    = ! empty( get_post_meta( $post_id, RNRD_META_SUMMARY, true ) );
		$has_faq        = ! empty( get_post_meta( $post_id, RNRD_META_FAQ, true ) );
		$has_author_box = RNRD_Shortcode::post_has_manual( $post_id, 'rankready/author-box', RNRD_Shortcode::AUTHOR )
			|| 'off' !== (string) get_option( RNRD_OPT_AUTHOR_AUTO_DISPLAY, 'off' );

		if ( $has_summary || $has_faq || $has_author_box ) {
			self::register_style_handle();
			wp_enqueue_style( 'rankready-style' );
		}
	}


	/**
	 * Translatable strings for Gutenberg block editor scripts.
	 *
	 * @return array<string, string>
	 */
	private static function editor_js_i18n(): array {
		/*
		 * Auto-generate-on-publish is a PRO engine: RNRD_Pro_Autogen is the only
		 * thing that hooks RNRD_Generator::schedule_generation to
		 * wp_after_insert_post (class-rnrd-generator.php:31 says so, and the only
		 * add_action in Free is the temporary re-hook in class-rnrd-rest.php:1004,
		 * guarded by whether it was hooked in the first place). Free shipped
		 * "Summary auto-generates on publish/update." to every user anyway, so a
		 * Free user published and waited for a summary that was never coming, and
		 * the sentence that should have sold Pro instead described Free wrongly.
		 * Both strings now depend on whether Pro is actually licensed.
		 */
		$is_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();

		/*
		 * The FAQ progress string named two fixed vendors ("DataForSEO + OpenAI").
		 * OpenAI is one of four providers, and FAQ generation is not DataForSEO
		 * gated at all (class-rnrd-rest.php says so explicitly). Name the provider
		 * the site is actually configured to use.
		 */
		$provider = class_exists( 'RNRD_LLM' )
			? RNRD_LLM::get_provider_label( RNRD_LLM::get_active_provider() )
			: __( 'your AI provider', 'rankready-ai-llm-seo' );

		$strings = array(
		/* translators: %d: seconds until regeneration is allowed */
		'regenIn'                       => __( 'Regenerate available in %ds.', 'rankready-ai-llm-seo' ),
		'generate'                      => __( 'Generate', 'rankready-ai-llm-seo' ),
		'regenerate'                    => __( 'Regenerate', 'rankready-ai-llm-seo' ),
		'generating'                    => __( 'Generating…', 'rankready-ai-llm-seo' ),
		'regenerating'                  => __( 'Regenerating…', 'rankready-ai-llm-seo' ),
		'generationFailed'              => __( 'Generation failed.', 'rankready-ai-llm-seo' ),
		'apiKeyMissing'                 => __( 'No API key yet. Add one under RankReady → Settings → API Keys.', 'rankready-ai-llm-seo' ),
		'addApiKey'                     => __( 'Add an API key under RankReady → Settings → API Keys.', 'rankready-ai-llm-seo' ),
		'summaryAutoGenerate'           => $is_pro
			? __( 'Summary auto-generates on publish/update.', 'rankready-ai-llm-seo' )
			: __( 'Click Generate in the RankReady panel to write the summary. RankReady Pro generates it automatically on publish.', 'rankready-ai-llm-seo' ),
		'summaryAfterPublish'           => $is_pro
			? __( 'Summary will appear here after you publish this post.', 'rankready-ai-llm-seo' )
			: __( 'No summary yet. Use Generate in the RankReady panel, as often as you like. Pro generates it for you on publish.', 'rankready-ai-llm-seo' ),
		'summaryBlockTitle'             => __( 'AI Summary — RankReady', 'rankready-ai-llm-seo' ),
		'summaryBlockDescription'       => $is_pro
			? __( 'Displays AI-generated key takeaways. Auto-generates on publish/update.', 'rankready-ai-llm-seo' )
			: __( 'Displays AI-generated key takeaways. Generate them from the RankReady panel.', 'rankready-ai-llm-seo' ),
		'generatingEllipsis'            => __( 'Generating…', 'rankready-ai-llm-seo' ),
		'generateFaq'                   => __( 'Generate FAQ', 'rankready-ai-llm-seo' ),
		'regenerateFaq'                 => __( 'Regenerate FAQ', 'rankready-ai-llm-seo' ),
		'generatingFaq'                 => __( 'Generating FAQ…', 'rankready-ai-llm-seo' ),
		'faqGenerationFailed'           => __( 'FAQ generation failed.', 'rankready-ai-llm-seo' ),
		'justNow'                       => __( 'Just now', 'rankready-ai-llm-seo' ),
		/* translators: %s: relative or absolute time */
		'lastGenerated'                 => __( 'Last generated: %s', 'rankready-ai-llm-seo' ),
		/* translators: %s: the configured AI provider, e.g. "OpenAI" or "Claude (Anthropic)". */
		'generatingFaqLong'             => sprintf( __( 'Generating FAQ with %s…', 'rankready-ai-llm-seo' ), $provider ),
		/* translators: %s: the configured AI provider, e.g. "OpenAI" or "Claude (Anthropic)". */
		'generateFaqWith'               => sprintf( __( 'Generate with %s', 'rankready-ai-llm-seo' ), $provider ),
		'clickGenerateFaq'              => __( 'Click "Generate FAQ" in the sidebar to create FAQ items for this post.', 'rankready-ai-llm-seo' ),
		'faqBlockTitle'                 => __( 'FAQ — RankReady', 'rankready-ai-llm-seo' ),
		'faqBlockDescription'           => __( 'Display AI-generated FAQ with brand entity injection. Generate from the RankReady panel.', 'rankready-ai-llm-seo' ),
		'faqMoveUp'                     => __( 'Move question up', 'rankready-ai-llm-seo' ),
		'faqMoveDown'                   => __( 'Move question down', 'rankready-ai-llm-seo' ),
		'faqReorderFailed'              => __( 'Could not save the new order. The previous order has been restored.', 'rankready-ai-llm-seo' ),
		/* translators: %s: relative or absolute time */
		'lastReviewed'                  => __( 'Last reviewed: %s', 'rankready-ai-llm-seo' ),
		'faqTitleDefault'               => __( 'Frequently Asked Questions', 'rankready-ai-llm-seo' ),
		'loadingAuthor'                 => __( 'Loading author…', 'rankready-ai-llm-seo' ),
		'authorPreviewNote'             => __( 'Live render (socials, credentials, reviewed-by) appears on the front end.', 'rankready-ai-llm-seo' ),
		'aboutTheAuthor'                => __( 'About the Author', 'rankready-ai-llm-seo' ),
		'authorBlockTitle'              => __( 'Author Box — RankReady', 'rankready-ai-llm-seo' ),
		'authorBlockDescription'        => __( 'Author box with name, job title, employer, bio and headshot from the user profile fields added by RankReady. A basic Person JSON-LD node for the same fields is published on author archive pages. Profile links, topics, credentials, memberships, awards and the extended Person schema are part of RankReady Pro.', 'rankready-ai-llm-seo' ),
		// Shared inspector / typography (summary + FAQ + author box).
		'themeDefault'                  => __( '— Theme default —', 'rankready-ai-llm-seo' ),
		'inherit'                       => __( '— Inherit —', 'rankready-ai-llm-seo' ),
		'usingGlobalDefault'            => __( '(Using global default)', 'rankready-ai-llm-seo' ),
		/* translators: %s: default HTML heading tag, e.g. H2 */
		'globalDefaultTag'              => __( '(Global default: %s)', 'rankready-ai-llm-seo' ),
		'zeroInherit'                   => __( '0 = inherit', 'rankready-ai-llm-seo' ),
		'zeroInheritTheme'              => __( '0 = inherit from theme', 'rankready-ai-llm-seo' ),
		'zeroUseDefault'                => __( '0 = use default CSS', 'rankready-ai-llm-seo' ),
		'zeroNoPadding'                 => __( '0 = no extra padding', 'rankready-ai-llm-seo' ),
		'panelSummarySettings'          => __( 'Summary Settings', 'rankready-ai-llm-seo' ),
		'panelFaqSettings'              => __( 'FAQ Settings', 'rankready-ai-llm-seo' ),
		'panelBoxStyle'                 => __( 'Box Style', 'rankready-ai-llm-seo' ),
		'panelLabelStyle'               => __( 'Label Style', 'rankready-ai-llm-seo' ),
		'panelBulletsStyle'             => __( 'Bullets Style', 'rankready-ai-llm-seo' ),
		'panelQuestionStyle'            => __( 'Question Style', 'rankready-ai-llm-seo' ),
		'panelAnswerStyle'              => __( 'Answer Style', 'rankready-ai-llm-seo' ),
		'panelContent'                  => __( 'Content', 'rankready-ai-llm-seo' ),
		'panelVisibleFields'            => __( 'Visible Fields', 'rankready-ai-llm-seo' ),
		'panelHeadshotStyle'            => __( 'Headshot Style', 'rankready-ai-llm-seo' ),
		'panelSocialStyle'              => __( 'Social Style', 'rankready-ai-llm-seo' ),
		'showLabel'                     => __( 'Show label', 'rankready-ai-llm-seo' ),
		'labelText'                     => __( 'Label text', 'rankready-ai-llm-seo' ),
		'labelTag'                      => __( 'Label tag', 'rankready-ai-llm-seo' ),
		'focusKeyword'                  => __( 'Focus Keyword', 'rankready-ai-llm-seo' ),
		'keywordPlaceholder'            => __( 'Auto-detected from Rank Math/Yoast', 'rankready-ai-llm-seo' ),
		'keywordHelp'                   => __( 'Leave empty to use SEO plugin focus keyword.', 'rankready-ai-llm-seo' ),
		'showTitle'                     => __( 'Show title', 'rankready-ai-llm-seo' ),
		'titleText'                     => __( 'Title text', 'rankready-ai-llm-seo' ),
		'titleTag'                      => __( 'Title tag', 'rankready-ai-llm-seo' ),
		'showLastReviewed'              => __( 'Show "Last reviewed" date', 'rankready-ai-llm-seo' ),
		'authorSource'                  => __( 'Author Source', 'rankready-ai-llm-seo' ),
		'authorSourceHelp'              => __( 'Defaults to the post author. Use "Specific" to pin a specific user (e.g. on a landing page).', 'rankready-ai-llm-seo' ),
		'authorPost'                    => __( 'Current post author', 'rankready-ai-llm-seo' ),
		'authorSpecific'                => __( 'Specific author', 'rankready-ai-llm-seo' ),
		'authorPick'                    => __( 'Author', 'rankready-ai-llm-seo' ),
		'authorPickHelp'                => __( 'Pick the user whose RankReady Author Box data should render here.', 'rankready-ai-llm-seo' ),
		'layout'                        => __( 'Layout', 'rankready-ai-llm-seo' ),
		'layoutHelp'                    => __( 'Card = full end-of-article box. Compact = sidebar-ready. Inline = minimal byline row.', 'rankready-ai-llm-seo' ),
		'layoutCard'                    => __( 'Card (full box)', 'rankready-ai-llm-seo' ),
		'layoutCompact'                 => __( 'Compact', 'rankready-ai-llm-seo' ),
		'layoutInline'                  => __( 'Inline byline', 'rankready-ai-llm-seo' ),
		'showHeading'                   => __( 'Show heading', 'rankready-ai-llm-seo' ),
		'showHeadingHelp'               => __( 'Headline above the box. Hidden automatically in inline layout.', 'rankready-ai-llm-seo' ),
		'headingText'                   => __( 'Heading text', 'rankready-ai-llm-seo' ),
		'headingBlankDefault'           => __( 'Leave blank to use site default.', 'rankready-ai-llm-seo' ),
		'headingTag'                    => __( 'Heading tag', 'rankready-ai-llm-seo' ),
		'bgColor'                       => __( 'Background Color', 'rankready-ai-llm-seo' ),
		'borderColor'                   => __( 'Border Color', 'rankready-ai-llm-seo' ),
		'borderPosition'                => __( 'Border Position', 'rankready-ai-llm-seo' ),
		'borderLeft'                    => __( 'Left only', 'rankready-ai-llm-seo' ),
		'borderAll'                     => __( 'All sides', 'rankready-ai-llm-seo' ),
		'borderNone'                    => __( 'None', 'rankready-ai-llm-seo' ),
		'borderWidth'                   => __( 'Border Width', 'rankready-ai-llm-seo' ),
		'borderRadius'                  => __( 'Border Radius', 'rankready-ai-llm-seo' ),
		'padding'                       => __( 'Padding', 'rankready-ai-llm-seo' ),
		'fontFamily'                    => __( 'Font Family', 'rankready-ai-llm-seo' ),
		'fontFamilyHelp'                => __( 'Pulls from your theme.json fonts (any block theme). Leave blank to inherit from theme.', 'rankready-ai-llm-seo' ),
		'fontFamilyHelpShort'           => __( 'Pulls from your theme.json fonts. Leave blank to inherit from theme.', 'rankready-ai-llm-seo' ),
		'fontWeight'                    => __( 'Font Weight', 'rankready-ai-llm-seo' ),
		'fontWeightHelp'                => __( 'Leave blank to inherit.', 'rankready-ai-llm-seo' ),
		'fontSizePx'                    => __( 'Font Size (px)', 'rankready-ai-llm-seo' ),
		'lineHeight'                    => __( 'Line Height', 'rankready-ai-llm-seo' ),
		'letterSpacingPx'               => __( 'Letter Spacing (px)', 'rankready-ai-llm-seo' ),
		'textTransform'                 => __( 'Text Transform', 'rankready-ai-llm-seo' ),
		'textColor'                     => __( 'Text Color', 'rankready-ai-llm-seo' ),
		'markerColor'                   => __( 'Marker Color', 'rankready-ai-llm-seo' ),
		'spaceBetweenPx'                => __( 'Space Between (px)', 'rankready-ai-llm-seo' ),
		'dividerColor'                  => __( 'Divider Color', 'rankready-ai-llm-seo' ),
		'color'                         => __( 'Color', 'rankready-ai-llm-seo' ),
		'background'                    => __( 'Background', 'rankready-ai-llm-seo' ),
		'imageSizePx'                   => __( 'Size (px)', 'rankready-ai-llm-seo' ),
		'imageSizeHelp'                 => __( '0 = use layout default (card: 96px, compact: 64px)', 'rankready-ai-llm-seo' ),
		'imageRadiusHelp'               => __( '100 = perfect circle', 'rankready-ai-llm-seo' ),
		'fieldHeadshot'                 => __( 'Headshot', 'rankready-ai-llm-seo' ),
		'fieldJobTitle'                 => __( 'Job Title', 'rankready-ai-llm-seo' ),
		'fieldEmployer'                 => __( 'Employer', 'rankready-ai-llm-seo' ),
		'fieldYearsExp'                 => __( 'Years of Experience', 'rankready-ai-llm-seo' ),
		'fieldBio'                      => __( 'Bio', 'rankready-ai-llm-seo' ),
		'fieldExpertise'                => __( 'Topics of Expertise', 'rankready-ai-llm-seo' ),
		'fieldCredentials'              => __( 'Credentials (Education + Certs)', 'rankready-ai-llm-seo' ),
		'fieldSocials'                  => __( 'Social Links', 'rankready-ai-llm-seo' ),
		'fieldReviewed'                 => __( 'Reviewed-By / Last Reviewed', 'rankready-ai-llm-seo' ),
		'typographyHeading'             => __( 'Heading Style', 'rankready-ai-llm-seo' ),
		'typographyName'                => __( 'Name Style', 'rankready-ai-llm-seo' ),
		'typographyMeta'                => __( 'Meta Style (job title / employer)', 'rankready-ai-llm-seo' ),
		'typographyBio'                 => __( 'Bio Style', 'rankready-ai-llm-seo' ),
		'authorPostOption'              => __( '— Current post author —', 'rankready-ai-llm-seo' ),
		'labelColor'                    => __( 'Label Color', 'rankready-ai-llm-seo' ),
		'borderRadiusPx'                => __( 'Border Radius (px)', 'rankready-ai-llm-seo' ),
		'paddingPx'                     => __( 'Padding (px)', 'rankready-ai-llm-seo' ),
		'zeroUseDefaultBullet'          => __( '0 = use default', 'rankready-ai-llm-seo' ),
		);

		return $strings;
	}
}
