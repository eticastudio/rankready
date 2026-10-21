<?php
/**
 * Elementor Widget — RankReady AI Summary.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name(): string        { return 'rnrd_ai_summary'; }
	public function get_title(): string       { return esc_html__( 'AI Summary — RankReady', 'rankready-ai-llm-seo' ); }
	public function get_icon(): string        { return 'eicon-bullet-list'; }
	public function get_categories(): array   { return array( 'rankready' ); }
	public function get_keywords(): array     { return array( 'summary', 'takeaways', 'key points', 'ai', 'ai seo', 'llm', 'geo', 'answer engine', 'seo', 'rankready' ); }
	// Elementor enqueues the scoped CSS only when this widget is on the page.
	public function get_style_depends(): array { return array( 'rankready-style' ); }

	/**
	 * Editor-only assets. The Generate/Regenerate button now lives in the
	 * Elementor CONTROL PANEL (not painted into the canvas), so the handler +
	 * styles load in the EDITOR frame (parent window) rather than the preview
	 * iframe. Hooked once (Elementor calls register() per widget, so the static
	 * guard keeps the enqueue hook from registering 3x for our 3 widgets).
	 *
	 * @since 1.0.30 — moved from preview frame to editor panel.
	 */
	public static function register_editor_assets(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_action( 'elementor/editor/after_enqueue_scripts', array( __CLASS__, 'enqueue_editor_assets' ) );
	}

	/**
	 * Enqueue the editor-panel script + CSS. Public so the plugin boot can hook it
	 * directly: hooking it only from a widget constructor meant it was added after
	 * Elementor 4.x had already fired the enqueue action, so the script never loaded.
	 */
	public static function enqueue_editor_assets(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		{
			$ver = file_exists( RNRD_DIR . 'assets/elementor-editor.js' )
				? RNRD_VERSION . '.' . filemtime( RNRD_DIR . 'assets/elementor-editor.js' )
				: RNRD_VERSION;

			// A script whose dependency handle is unregistered is silently never
			// printed, so make sure the shared i18n helper exists first.
			if ( ! wp_script_is( 'rnrd-i18n', 'registered' ) ) {
				wp_register_script( 'rnrd-i18n', RNRD_URL . 'assets/rnrd-i18n.js', array(), RNRD_VERSION, false );
			}
			wp_register_script( 'rnrd-elementor-editor', RNRD_URL . 'assets/elementor-editor.js', array( 'rnrd-i18n' ), $ver, false );
			wp_localize_script( 'rnrd-elementor-editor', 'rnrdElEditor', array(
				'restUrl' => esc_url_raw( rest_url( 'rankready/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => self::elementor_editor_js_i18n(),
			) );
			wp_enqueue_script( 'rnrd-elementor-editor' );

			// Panel CSS for the regen control. Scoped to .rnrd-el-regen so it
			// can't leak onto the live frontend (this only loads in the editor).
			// Native Elementor button look (uses the editor app's own button CSS
			// vars with safe fallbacks) + forced full width.
			$css = '.rnrd-el-regen{margin:0 0 4px}'
				. '.rnrd-el-regen .rnrd-el-regen__btn.elementor-button{display:flex;width:100%;box-sizing:border-box;justify-content:center;align-items:center;text-align:center;padding:12px 16px;font-size:13px;font-weight:500;line-height:1.2;border:0;border-radius:3px;cursor:pointer;background:var(--e-a-btn-bg,#515962);color:var(--e-a-btn-color-invert,#fff)}'
				. '.rnrd-el-regen .rnrd-el-regen__btn.elementor-button:hover{background:var(--e-a-btn-bg-hover,#0c0d0e)}'
				. '.rnrd-el-regen__btn[disabled]{opacity:.6;cursor:wait}'
				. '.rnrd-el-regen__hint{font-size:11px;color:#69727D;margin:8px 0 0;line-height:1.5}'
				. '.rnrd-el-regen__status{font-size:12px;margin:8px 0 0;line-height:1.5;font-weight:600}'
				. '.rnrd-el-regen__status[data-kind="success"]{color:#0F9C70}'
				. '.rnrd-el-regen__status[data-kind="error"]{color:#B91C1C}';
			// Print on our OWN registered handle (src=false) so the CSS reliably
			// loads in the editor panel regardless of Elementor's style handles.
			wp_register_style( 'rnrd-elementor-editor', false, array(), $ver );
			wp_enqueue_style( 'rnrd-elementor-editor' );
			wp_add_inline_style( 'rnrd-elementor-editor', $css );
		}
	}

	/**
	 * Build the panel regen control HTML (button + hint + status).
	 *
	 * NOTE: Elementor caches a RAW_HTML control's `raw` at the widget-TYPE level
	 * (shared across posts/instances), so the label can NOT be resolved per-post
	 * here — it would freeze to whatever post was current at first build. The
	 * default is "Generate Summary"; the editor JS fetches the actual per-post
	 * summary state at runtime and flips it to "Regenerate" when one exists
	 * (mirrors how the Gutenberg block decides its label).
	 *
	 * @since 1.0.30
	 */
	private static function regen_panel_html(): string {
		return self::regen_control_html(
			'summary',
			esc_html__( 'Generate Summary', 'rankready-ai-llm-seo' ),
			esc_html__( 'Use this to refresh manually after editing the post body.', 'rankready-ai-llm-seo' )
		);
	}

	/**
	 * Editor-only placeholder for a widget that would otherwise render nothing, so it can be seen and
	 * selected. Prints nothing on the front end and nothing in a preview that is not the Elementor editor.
	 *
	 * @param string $message Already translated, escaped here.
	 */
	public static function editor_notice( string $message ): void {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return;
		}
		echo '<div class="rnrd-el-placeholder" style="padding:12px 14px;border:1px dashed #8c8f94;color:#50575e;font-size:13px;line-height:1.5">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Shared builder for the editor-panel "generate" control used by BOTH the
	 * AI Summary and FAQ widgets — one markup, one JS handler, one CSS block, so
	 * the two stay standardised. `$kind` ("summary"|"faq") tells the editor JS
	 * which REST endpoints + labels to use; the label is resolved per-post at
	 * runtime (Elementor caches RAW_HTML, so it can't be set per-post here).
	 *
	 * @since 1.0.30
	 * @param string $kind          'summary' | 'faq'.
	 * @param string $default_label Initial button text before JS resolves state.
	 * @param string $hint          Small helper line under the button.
	 */
	public static function regen_control_html( string $kind, string $default_label, string $hint ): string {
		return '<div class="rnrd-el-regen" data-rnrd-kind="' . esc_attr( $kind ) . '">'
			. '<button type="button" class="elementor-button rnrd-el-regen__btn" data-idle-label="' . esc_attr( $default_label ) . '">' . esc_html( $default_label ) . '</button>'
			. '<p class="rnrd-el-regen__status" data-kind=""></p>'
			. '<p class="rnrd-el-regen__hint">' . esc_html( $hint ) . '</p>'
			. '</div>';
	}

	public function __construct( $data = array(), $args = null ) {
		parent::__construct( $data, $args );
		self::register_editor_assets();
	}

	protected function register_controls(): void {

		$global_label = (string) get_option( RNRD_OPT_LABEL, __( 'Key Takeaways', 'rankready-ai-llm-seo' ) );
		$global_show  = (bool) get_option( RNRD_OPT_SHOW_LABEL, '1' );
		$global_tag   = (string) get_option( RNRD_OPT_HEADING_TAG, 'h4' );

		// ── Content tab ───────────────────────────────────────────────────────
		$this->start_controls_section( 'section_content', array(
			'label' => esc_html__( 'Content', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		) );

		// 1.0.30 — Manual Generate/Regenerate lives HERE in the panel (not in
		// the canvas). Context-aware label, inline status, errors shown below
		// the button — never painted into the preview.
		$this->add_control( 'rnrd_regen', array(
			'type'            => \Elementor\Controls_Manager::RAW_HTML,
			'raw'             => self::regen_panel_html(),
			'content_classes' => 'rnrd-el-regen-control',
		) );

		// Editor-only explainer. Makes the widget behaviour obvious:
		//   • Single post / page  → renders that post's auto-generated summary
		//   • Theme Builder archive loop → each post renders its own summary
		$this->add_control( 'rnrd_usage_info', array(
			'type' => \Elementor\Controls_Manager::RAW_HTML,
			'raw'  =>
				'<div style="background:#ECFDF6;border-left:3px solid #59F7C2;padding:10px 12px;border-radius:6px;font-size:11px;line-height:1.55;color:#053427;">'
				. '<strong style="display:block;margin-bottom:4px;font-size:11px;text-transform:uppercase;letter-spacing:0.06em;">' . esc_html__( 'How this widget works', 'rankready-ai-llm-seo' ) . '</strong>'
				. esc_html__( 'On a single post or page, this widget shows that post\'s AI summary. On a Theme Builder Archive or Single template, every post in the loop renders its own summary — no separate archive-level summary is generated.', 'rankready-ai-llm-seo' )
				. '<br><br>'
				. esc_html__( 'Use the button above to refresh one manually — you do not need it for the summary to appear.', 'rankready-ai-llm-seo' )
				. '</div>',
			'content_classes' => 'rnrd-el-info',
		) );

		$this->add_control( 'show_label', array(
			'label'        => esc_html__( 'Show Label', 'rankready-ai-llm-seo' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => esc_html__( 'Yes', 'rankready-ai-llm-seo' ),
			'label_off'    => esc_html__( 'No', 'rankready-ai-llm-seo' ),
			'return_value' => 'yes',
			'default'      => $global_show ? 'yes' : '',
		) );

		$this->add_control( 'label_text', array(
			'label'       => esc_html__( 'Label Text', 'rankready-ai-llm-seo' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'default'     => $global_label,
			'placeholder' => $global_label,
			'condition'   => array( 'show_label' => 'yes' ),
		) );

		$this->add_control( 'heading_tag', array(
			'label'     => esc_html__( 'Label HTML Tag', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => $global_tag,
			'options'   => array(
				'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4',
				'h5' => 'H5', 'h6' => 'H6', 'p'  => 'P',
			),
			'condition' => array( 'show_label' => 'yes' ),
		) );

		$this->end_controls_section();

		// ── Box Style tab ─────────────────────────────────────────────────────
		$this->start_controls_section( 'section_box_style', array(
			'label' => esc_html__( 'Box Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_control( 'border_color', array(
			'label'     => esc_html__( 'Border Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-summary' => 'border-left-color: {{VALUE}};' ),
		) );

		$this->add_control( 'bg_color', array(
			'label'     => esc_html__( 'Background Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-summary' => 'background-color: {{VALUE}};' ),
		) );

		$this->add_responsive_control( 'padding', array(
			'label'      => esc_html__( 'Padding', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em', '%' ),
			'selectors'  => array(
				'{{WRAPPER}} .rnrd-summary' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			),
		) );

		$this->add_responsive_control( 'border_width', array(
			'label'      => esc_html__( 'Border Width', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 10 ) ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-summary' => 'border-left-width: {{SIZE}}{{UNIT}};' ),
		) );

		$this->add_responsive_control( 'border_radius', array(
			'label'      => esc_html__( 'Border Radius', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 30 ) ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-summary' => 'border-radius: {{SIZE}}{{UNIT}};' ),
		) );

		$this->end_controls_section();

		// ── Label Style ───────────────────────────────────────────────────────
		$this->start_controls_section( 'section_label_style', array(
			'label'     => esc_html__( 'Label Style', 'rankready-ai-llm-seo' ),
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_label' => 'yes' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'label_typography',
			'selector' => '{{WRAPPER}} .rnrd-label',
		) );

		$this->add_control( 'label_color', array(
			'label'     => esc_html__( 'Label Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-label' => 'color: {{VALUE}};' ),
		) );

		$this->end_controls_section();

		// ── Bullets Style ─────────────────────────────────────────────────────
		$this->start_controls_section( 'section_bullets_style', array(
			'label' => esc_html__( 'Bullets Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'bullets_typography',
			'selector' => '{{WRAPPER}} .rnrd-bullet',
		) );

		$this->add_control( 'bullets_color', array(
			'label'     => esc_html__( 'Text Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-bullet' => 'color: {{VALUE}};' ),
		) );

		$this->add_control( 'marker_color', array(
			'label'     => esc_html__( 'Marker Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-bullet::marker' => 'color: {{VALUE}};' ),
		) );

		$this->add_responsive_control( 'bullet_gap', array(
			'label'      => esc_html__( 'Space Between', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', 'em' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 30 ) ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-bullet' => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
		) );

		$this->end_controls_section();
	}

	protected function render(): void {
		if ( class_exists( 'RNRD_Summary' ) && ! RNRD_Summary::is_enabled() ) {
			self::editor_notice( __( 'AI Summary is off. Turn it on in AI Content, then AI Summary.', 'rankready-ai-llm-seo' ) );
			return;
		}
		$settings = $this->get_settings_for_display();
		$post_id  = get_the_ID();

		// Canvas shows the SUMMARY ONLY. With no post bound (Theme Builder
		// archive/loop) or no summary yet, render nothing — the manual
		// Generate/Regenerate control lives in the panel, not the preview.
		if ( ! $post_id ) {
			self::editor_notice( __( 'AI Summary shows the summary of the post this page belongs to.', 'rankready-ai-llm-seo' ) );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! RNRD_Summary::is_post_type_enabled( $post->post_type ) ) {
			self::editor_notice( __( 'AI Summary is not enabled for this post type. Turn it on in AI Content, then AI Summary, Post types.', 'rankready-ai-llm-seo' ) );
			return;
		}
		// Same opt-out and password rules the automatic path applies. A widget
		// dropped into a Theme Builder template is a third emitter of the same
		// stored text, and it used to ignore both.
		if ( ! RNRD_Util::can_emit( (int) $post_id, RNRD_META_DISABLE ) ) {
			self::editor_notice( __( 'AI Summary is switched off for this post.', 'rankready-ai-llm-seo' ) );
			return;
		}

		$raw = (string) get_post_meta( $post_id, RNRD_META_SUMMARY, true );
		if ( empty( $raw ) ) {
			self::editor_notice( __( 'No summary yet. Use Generate Summary in the panel.', 'rankready-ai-llm-seo' ) );
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- render_html() escapes every value it interpolates.
		/*
		 * RNRD_Summary::render_html() returns markup it has already escaped: every
		 * attribute goes through esc_attr(), colours through
		 * RNRD_Util::sanitize_color(), sizes are cast to int, the heading
		 * tag through validate_heading_tag() and the title through
		 * sanitize_text_field(). Wrapping the result in wp_kses_post()
		 * here would strip valid structural markup without adding safety.
		 * Annotated so a reviewer can see this is deliberate rather than
		 * an unescaped echo. Release gate check #11 flags the shape.
		 */
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by the renderer, see above.
		echo RNRD_Summary::render_html( $raw, array(
			'showLabel'  => 'yes' === ( isset( $settings['show_label'] ) ? $settings['show_label'] : ( get_option( RNRD_OPT_SHOW_LABEL, '1' ) ? 'yes' : '' ) ),
			'label'      => ! empty( $settings['label_text'] )
				? sanitize_text_field( $settings['label_text'] )
				: (string) get_option( RNRD_OPT_LABEL, __( 'Key Takeaways', 'rankready-ai-llm-seo' ) ),
			'headingTag' => ! empty( $settings['heading_tag'] )
				? $settings['heading_tag']
				: (string) get_option( RNRD_OPT_HEADING_TAG, 'h4' ),
		) );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Translatable strings for assets/elementor-editor.js.
	 *
	 * @return array<string, string>
	 */
	private static function elementor_editor_js_i18n(): array {
		return array(
			'generate'         => __( 'Generate Summary', 'rankready-ai-llm-seo' ),
			'regenerate'       => __( 'Regenerate Summary', 'rankready-ai-llm-seo' ),
			'generating'       => __( 'Generating…', 'rankready-ai-llm-seo' ),
			'generateFaq'      => __( 'Generate FAQ', 'rankready-ai-llm-seo' ),
			'regenerateFaq'    => __( 'Regenerate FAQ', 'rankready-ai-llm-seo' ),
			'generatingFaq'    => __( 'Generating FAQ…', 'rankready-ai-llm-seo' ),
			/* translators: %d: seconds until regeneration is allowed */
			'regenIn'          => __( 'Regenerate available in %ds.', 'rankready-ai-llm-seo' ),
			'restUnavailable'  => __( 'REST URL unavailable.', 'rankready-ai-llm-seo' ),
			'updatedRefresh'   => __( 'Updated. Refresh the preview to see the result.', 'rankready-ai-llm-seo' ),
			'generationFailed' => __( 'Generation failed.', 'rankready-ai-llm-seo' ),
			'networkError'     => __( 'Network error. Try again.', 'rankready-ai-llm-seo' ),
			'configMissing'    => __( 'RankReady editor settings did not load. Reload the editor and try again.', 'rankready-ai-llm-seo' ),
			'noPostId'         => __( 'Save the page once, then try again.', 'rankready-ai-llm-seo' ),
		);
	}
}
