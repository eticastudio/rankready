<?php
/**
 * Elementor Widget — RankReady FAQ.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Elementor_Faq_Widget extends \Elementor\Widget_Base {

	public function get_name(): string        { return 'rnrd_faq'; }
	public function get_title(): string       { return esc_html__( 'FAQ — RankReady', 'rankready-ai-llm-seo' ); }
	public function get_icon(): string        { return 'eicon-help-o'; }
	public function get_categories(): array   { return array( 'rankready' ); }
	public function get_keywords(): array     { return array( 'faq', 'questions', 'qa', 'ai', 'ai seo', 'llm', 'geo', 'answer engine', 'seo', 'rankready', 'schema' ); }
	// Elementor enqueues the scoped CSS only when this widget is on the page.
	public function get_style_depends(): array { return array( 'rankready-style' ); }

	protected function register_controls(): void {

		// ── Content tab ───────────────────────────────────────────────────────
		$this->start_controls_section( 'section_content', array(
			'label' => esc_html__( 'Content', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		) );

		// 1.0.30 — Manual Generate/Regenerate FAQ button in the panel (mirrors
		// the Gutenberg FAQ block + the AI Summary widget). Shared builder/JS.
		$this->add_control( 'rnrd_faq_regen', array(
			'type'            => \Elementor\Controls_Manager::RAW_HTML,
			'raw'             => class_exists( 'RNRD_Elementor_Widget' )
				? RNRD_Elementor_Widget::regen_control_html(
					'faq',
					esc_html__( 'Generate FAQ', 'rankready-ai-llm-seo' ),
					esc_html__( 'Use this button to (re)generate the FAQ manually.', 'rankready-ai-llm-seo' )
				)
				: '',
			'content_classes' => 'rnrd-el-regen-control',
		) );

		$this->add_control( 'keyword', array(
			'label'       => esc_html__( 'Focus Keyword', 'rankready-ai-llm-seo' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'placeholder' => esc_html__( 'Auto-detected from Rank Math / Yoast', 'rankready-ai-llm-seo' ),
			'description' => esc_html__( 'Leave empty to use your SEO plugin focus keyword.', 'rankready-ai-llm-seo' ),
		) );

		$this->add_control( 'show_title', array(
			'label'        => esc_html__( 'Show Title', 'rankready-ai-llm-seo' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => esc_html__( 'Yes', 'rankready-ai-llm-seo' ),
			'label_off'    => esc_html__( 'No', 'rankready-ai-llm-seo' ),
			'return_value' => 'yes',
			'default'      => 'yes',
		) );

		$this->add_control( 'title_text', array(
			'label'       => esc_html__( 'Title Text', 'rankready-ai-llm-seo' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'default'     => __( 'Frequently Asked Questions', 'rankready-ai-llm-seo' ),
			'placeholder' => __( 'Frequently Asked Questions', 'rankready-ai-llm-seo' ),
			'condition'   => array( 'show_title' => 'yes' ),
		) );

		$this->add_control( 'heading_tag', array(
			'label'     => esc_html__( 'Title Tag', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => 'h3',
			'options'   => array(
				'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4',
				'h5' => 'H5', 'h6' => 'H6',
			),
			'condition' => array( 'show_title' => 'yes' ),
		) );

		$this->add_control( 'show_reviewed', array(
			'label'        => esc_html__( 'Show "Last Reviewed" Date', 'rankready-ai-llm-seo' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => esc_html__( 'Yes', 'rankready-ai-llm-seo' ),
			'label_off'    => esc_html__( 'No', 'rankready-ai-llm-seo' ),
			'return_value' => 'yes',
			'default'      => 'yes',
		) );

		$this->end_controls_section();

		// ── Box Style tab ─────────────────────────────────────────────────────
		$this->start_controls_section( 'section_box_style', array(
			'label' => esc_html__( 'Box Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_control( 'bg_color', array(
			'label'     => esc_html__( 'Background Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-faq-wrapper' => 'background-color: {{VALUE}};' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'box_border',
			'selector' => '{{WRAPPER}} .rnrd-faq-wrapper',
		) );

		$this->add_responsive_control( 'border_radius', array(
			'label'      => esc_html__( 'Border Radius', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 20 ) ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-faq-wrapper' => 'border-radius: {{SIZE}}{{UNIT}};' ),
		) );

		$this->add_responsive_control( 'padding', array(
			'label'      => esc_html__( 'Padding', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em', '%' ),
			'selectors'  => array(
				'{{WRAPPER}} .rnrd-faq-wrapper' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			),
		) );

		$this->end_controls_section();

		// ── Title Style ───────────────────────────────────────────────────────
		$this->start_controls_section( 'section_title_style', array(
			'label'     => esc_html__( 'Title Style', 'rankready-ai-llm-seo' ),
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_title' => 'yes' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'title_typography',
			'selector' => '{{WRAPPER}} .rnrd-faq-title',
		) );

		$this->add_control( 'title_color', array(
			'label'     => esc_html__( 'Title Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-faq-title' => 'color: {{VALUE}};' ),
		) );

		$this->end_controls_section();

		// ── Question Style ────────────────────────────────────────────────────
		$this->start_controls_section( 'section_question_style', array(
			'label' => esc_html__( 'Question Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'question_typography',
			'selector' => '{{WRAPPER}} .rnrd-faq-question',
		) );

		$this->add_control( 'question_color', array(
			'label'     => esc_html__( 'Question Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-faq-question' => 'color: {{VALUE}};' ),
		) );

		$this->end_controls_section();

		// ── Answer Style ──────────────────────────────────────────────────────
		$this->start_controls_section( 'section_answer_style', array(
			'label' => esc_html__( 'Answer Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'answer_typography',
			'selector' => '{{WRAPPER}} .rnrd-faq-answer',
		) );

		$this->add_control( 'answer_color', array(
			'label'     => esc_html__( 'Answer Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-faq-answer' => 'color: {{VALUE}};' ),
		) );

		$this->add_control( 'divider_color', array(
			'label'     => esc_html__( 'Divider Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-faq-item' => 'border-bottom-color: {{VALUE}};' ),
		) );

		$this->add_responsive_control( 'answer_spacing', array(
			'label'      => esc_html__( 'Item Spacing', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', 'em' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-faq-item' => 'padding-top: {{SIZE}}{{UNIT}}; padding-bottom: {{SIZE}}{{UNIT}};' ),
		) );

		$this->end_controls_section();
	}

	protected function render(): void {
		if ( class_exists( 'RNRD_Faq' ) && ! RNRD_Faq::is_enabled() ) {
			RNRD_Elementor_Widget::editor_notice( __( 'AI FAQ is off. Turn it on in AI Content, then AI FAQ.', 'rankready-ai-llm-seo' ) );
			return;
		}
		$settings = $this->get_settings_for_display();
		$post_id  = get_the_ID();

		// Canvas shows the FAQ ONLY. No post bound or no FAQ yet → render
		// nothing; the manual Generate/Regenerate control lives in the panel.
		if ( ! $post_id ) {
			RNRD_Elementor_Widget::editor_notice( __( 'AI FAQ shows the FAQ of the post this page belongs to.', 'rankready-ai-llm-seo' ) );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! RNRD_Faq::is_post_type_enabled( $post->post_type ) ) {
			RNRD_Elementor_Widget::editor_notice( __( 'AI FAQ is not enabled for this post type. Turn it on in AI Content, then AI FAQ, Post types.', 'rankready-ai-llm-seo' ) );
			return;
		}
		// Same opt-out and password rules the automatic path applies. A widget
		// dropped into a Theme Builder template is a third emitter of the same
		// stored text, and it used to ignore both.
		if ( ! RNRD_Util::can_emit( (int) $post_id, RNRD_META_FAQ_DISABLE ) ) {
			RNRD_Elementor_Widget::editor_notice( __( 'AI FAQ is switched off for this post.', 'rankready-ai-llm-seo' ) );
			return;
		}

		$faq_data = RNRD_Faq::get_faq_data( $post_id );
		if ( empty( $faq_data ) ) {
			RNRD_Elementor_Widget::editor_notice( __( 'No FAQ yet. Use Generate FAQ in the panel.', 'rankready-ai-llm-seo' ) );
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- render_html() escapes every value it interpolates.
		/*
		 * RNRD_Faq::render_html() returns markup it has already escaped: every
		 * attribute goes through esc_attr(), colours through
		 * RNRD_Util::sanitize_color(), sizes are cast to int, the heading
		 * tag through validate_heading_tag() and the title through
		 * sanitize_text_field(). Wrapping the result in wp_kses_post()
		 * here would strip valid structural markup without adding safety.
		 * Annotated so a reviewer can see this is deliberate rather than
		 * an unescaped echo. Release gate check #11 flags the shape.
		 */
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by the renderer, see above.
		echo RNRD_Faq::render_html( $faq_data, array(
			'showTitle'    => 'yes' === ( $settings['show_title'] ?? 'yes' ),
			'titleText'    => ! empty( $settings['title_text'] ) ? sanitize_text_field( $settings['title_text'] ) : __( 'Frequently Asked Questions', 'rankready-ai-llm-seo' ),
			'headingTag'   => ! empty( $settings['heading_tag'] ) ? $settings['heading_tag'] : 'h3',
			'showReviewed' => 'yes' === ( $settings['show_reviewed'] ?? 'yes' ),
		), $post_id );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
