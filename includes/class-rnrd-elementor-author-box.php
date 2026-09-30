<?php
/**
 * Elementor Widget — RankReady Author Box.
 *
 * Mirrors the Gutenberg block 1:1 using native Elementor controls so that
 * Elementor Global Fonts and Global Colors work out of the box.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Elementor_Author_Box_Widget extends \Elementor\Widget_Base {

	public function get_name(): string      { return 'rnrd_author_box'; }
	public function get_title(): string     { return esc_html__( 'Author Box — RankReady', 'rankready-ai-llm-seo' ); }
	public function get_icon(): string      { return 'eicon-person'; }
	public function get_categories(): array { return array( 'rankready' ); }
	public function get_keywords(): array   { return array( 'author', 'box', 'bio', 'rankready', 'eeat', 'e-e-a-t', 'person', 'schema', 'ai seo', 'llm', 'geo' ); }
	// Elementor enqueues the scoped CSS only when this widget is on the page.
	public function get_style_depends(): array { return array( 'rankready-style' ); }

	protected function register_controls(): void {

		// ═══ CONTENT TAB ══════════════════════════════════════════════════════
		$this->start_controls_section( 'rnrd_ab_content', array(
			'label' => esc_html__( 'Content', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		) );

		$this->add_control( 'author_source', array(
			'label'   => esc_html__( 'Author Source', 'rankready-ai-llm-seo' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'post',
			'options' => array(
				'post'     => esc_html__( 'Current post author', 'rankready-ai-llm-seo' ),
				'specific' => esc_html__( 'Specific author', 'rankready-ai-llm-seo' ),
			),
			'description' => esc_html__( 'Defaults to the post author. Use "Specific" to pin a specific user on a landing page.', 'rankready-ai-llm-seo' ),
		) );

		$this->add_control( 'author_id', array(
			'label'       => esc_html__( 'Author (User ID)', 'rankready-ai-llm-seo' ),
			'type'        => \Elementor\Controls_Manager::NUMBER,
			'min'         => 1,
			'condition'   => array( 'author_source' => 'specific' ),
			'description' => esc_html__( 'WordPress user ID. Find it at Users → All Users → hover an author.', 'rankready-ai-llm-seo' ),
		) );

		$this->add_control( 'layout', array(
			'label'   => esc_html__( 'Layout', 'rankready-ai-llm-seo' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'card',
			'options' => array(
				'card'    => esc_html__( 'Card (full box)', 'rankready-ai-llm-seo' ),
				'compact' => esc_html__( 'Compact (small)', 'rankready-ai-llm-seo' ),
				'inline'  => esc_html__( 'Inline byline', 'rankready-ai-llm-seo' ),
			),
			'description' => esc_html__( 'Card = full end-of-article box. Compact = sidebar-ready. Inline = minimal byline row.', 'rankready-ai-llm-seo' ),
		) );

		$this->add_control( 'show_heading', array(
			'label'        => esc_html__( 'Show Heading', 'rankready-ai-llm-seo' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => 'yes',
			'condition'    => array( 'layout!' => 'inline' ),
		) );

		$this->add_control( 'heading_text', array(
			'label'       => esc_html__( 'Heading Text', 'rankready-ai-llm-seo' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'default'     => esc_html__( 'About the Author', 'rankready-ai-llm-seo' ),
			'condition'   => array( 'show_heading' => 'yes', 'layout!' => 'inline' ),
			'description' => esc_html__( 'Override the site-wide default from RankReady → Author Box settings.', 'rankready-ai-llm-seo' ),
		) );

		$this->add_control( 'heading_tag', array(
			'label'     => esc_html__( 'Heading HTML Tag', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => 'h3',
			'options'   => array( 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6', 'p' => 'P' ),
			'condition' => array( 'show_heading' => 'yes', 'layout!' => 'inline' ),
		) );

		$this->add_control( 'fields_heading', array(
			'label' => esc_html__( 'Visible Fields', 'rankready-ai-llm-seo' ),
			'type'  => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		) );

		$toggles = array(
			'show_headshot'    => esc_html__( 'Headshot', 'rankready-ai-llm-seo' ),
			'show_job_title'   => esc_html__( 'Job Title', 'rankready-ai-llm-seo' ),
			'show_employer'    => esc_html__( 'Employer', 'rankready-ai-llm-seo' ),
			'show_years_exp'   => esc_html__( 'Years of Experience', 'rankready-ai-llm-seo' ),
			'show_bio'         => esc_html__( 'Bio', 'rankready-ai-llm-seo' ),
			'show_expertise'   => esc_html__( 'Topics of Expertise', 'rankready-ai-llm-seo' ),
			'show_credentials' => esc_html__( 'Credentials (Education + Certs)', 'rankready-ai-llm-seo' ),
			'show_socials'     => esc_html__( 'Social Links', 'rankready-ai-llm-seo' ),
			'show_reviewed'    => esc_html__( 'Reviewed-By / Last Reviewed', 'rankready-ai-llm-seo' ),
		);
		foreach ( $toggles as $key => $label ) {
			$this->add_control( $key, array(
				'label'        => $label,
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			) );
		}

		$this->end_controls_section();

		// ═══ BOX STYLE ════════════════════════════════════════════════════════
		$this->start_controls_section( 'rnrd_ab_box_style', array(
			'label' => esc_html__( 'Box Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_control( 'box_bg', array(
			'label'     => esc_html__( 'Background Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-author-box' => 'background-color: {{VALUE}};' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'box_border',
			'selector' => '{{WRAPPER}} .rnrd-author-box',
		) );

		$this->add_responsive_control( 'box_radius', array(
			'label'      => esc_html__( 'Border Radius', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', '%' ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-author-box' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->add_responsive_control( 'box_padding', array(
			'label'      => esc_html__( 'Padding', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em', '%' ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-author-box' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array(
			'name'     => 'box_shadow',
			'selector' => '{{WRAPPER}} .rnrd-author-box',
		) );

		$this->end_controls_section();

		// ═══ HEADING STYLE ════════════════════════════════════════════════════
		$this->start_controls_section( 'rnrd_ab_heading_style', array(
			'label' => esc_html__( 'Heading Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_heading' => 'yes', 'layout!' => 'inline' ),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'heading_typography',
			'selector' => '{{WRAPPER}} .rnrd-ab-heading',
		) );
		$this->add_control( 'heading_color', array(
			'label'     => esc_html__( 'Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-ab-heading' => 'color: {{VALUE}};' ),
		) );

		$this->end_controls_section();

		// ═══ NAME STYLE ═══════════════════════════════════════════════════════
		$this->start_controls_section( 'rnrd_ab_name_style', array(
			'label' => esc_html__( 'Name Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'name_typography',
			'selector' => '{{WRAPPER}} .rnrd-ab-name',
		) );
		$this->add_control( 'name_color', array(
			'label'     => esc_html__( 'Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-ab-name' => 'color: {{VALUE}};' ),
		) );
		$this->add_control( 'name_hover_color', array(
			'label'     => esc_html__( 'Hover Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-ab-name:hover' => 'color: {{VALUE}};' ),
		) );

		$this->end_controls_section();

		// ═══ META STYLE (job title / employer / years) ════════════════════════
		$this->start_controls_section( 'rnrd_ab_meta_style', array(
			'label' => esc_html__( 'Meta Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'meta_typography',
			'selector' => '{{WRAPPER}} .rnrd-ab-meta',
		) );
		$this->add_control( 'meta_color', array(
			'label'     => esc_html__( 'Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-ab-meta' => 'color: {{VALUE}};' ),
		) );
		$this->end_controls_section();

		// ═══ BIO STYLE ════════════════════════════════════════════════════════
		$this->start_controls_section( 'rnrd_ab_bio_style', array(
			'label' => esc_html__( 'Bio Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_bio' => 'yes' ),
		) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'bio_typography',
			'selector' => '{{WRAPPER}} .rnrd-ab-bio',
		) );
		$this->add_control( 'bio_color', array(
			'label'     => esc_html__( 'Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-ab-bio' => 'color: {{VALUE}};' ),
		) );
		$this->end_controls_section();

		// ═══ IMAGE STYLE ══════════════════════════════════════════════════════
		$this->start_controls_section( 'rnrd_ab_image_style', array(
			'label' => esc_html__( 'Image Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_headshot' => 'yes' ),
		) );
		$this->add_responsive_control( 'image_size', array(
			'label'      => esc_html__( 'Size', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 32, 'max' => 200 ) ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-ab-headshot img' => 'width:{{SIZE}}{{UNIT}};height:{{SIZE}}{{UNIT}};object-fit:cover;' ),
		) );
		$this->add_responsive_control( 'image_radius', array(
			'label'      => esc_html__( 'Border Radius', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', '%' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 200 ), '%' => array( 'min' => 0, 'max' => 50 ) ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-ab-headshot img' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			'description' => esc_html__( '50% = perfect circle.', 'rankready-ai-llm-seo' ),
		) );
		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'image_border',
			'selector' => '{{WRAPPER}} .rnrd-ab-headshot img',
		) );
		$this->end_controls_section();

		// ═══ SOCIAL ICONS STYLE ═══════════════════════════════════════════════
		$this->start_controls_section( 'rnrd_ab_social_style', array(
			'label' => esc_html__( 'Social Style', 'rankready-ai-llm-seo' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_socials' => 'yes' ),
		) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'social_typography',
			'selector' => '{{WRAPPER}} .rnrd-ab-social',
		) );
		$this->add_control( 'social_color', array(
			'label'     => esc_html__( 'Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-ab-social' => 'color: {{VALUE}};border-color:{{VALUE}};' ),
		) );
		$this->add_control( 'social_hover_color', array(
			'label'     => esc_html__( 'Hover Color', 'rankready-ai-llm-seo' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array( '{{WRAPPER}} .rnrd-ab-social:hover' => 'color: {{VALUE}};border-color:{{VALUE}};' ),
		) );
		$this->add_responsive_control( 'social_gap', array(
			'label'      => esc_html__( 'Gap', 'rankready-ai-llm-seo' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 30 ) ),
			'selectors'  => array( '{{WRAPPER}} .rnrd-ab-socials' => 'gap:{{SIZE}}{{UNIT}};' ),
		) );
		$this->end_controls_section();
	}

	protected function render(): void {
		if ( class_exists( 'RNRD_Author_Box' ) && ! RNRD_Author_Box::is_enabled() ) {
			return;
		}
		$settings = $this->get_settings_for_display();

		$source  = isset( $settings['author_source'] ) ? $settings['author_source'] : 'post';
		$post_id = (int) get_the_ID();
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || ! RNRD_Author_Box::is_post_type_enabled( $post->post_type ) ) {
				return;
			}
			// Same opt-out and password rules the automatic path applies.
			// Guarded inside the $post_id branch because this widget also
			// supports an explicit author with no post bound at all.
			if ( ! RNRD_Util::can_emit( (int) $post_id, RNRD_META_AUTHOR_DISABLE ) ) {
				return;
			}
		}
		$user_id = 'specific' === $source && ! empty( $settings['author_id'] )
			? (int) $settings['author_id']
			: (int) get_post_field( 'post_author', $post_id );

		if ( $user_id <= 0 ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="rnrd-author-box rnrd-ab-placeholder">'
					. esc_html__( 'No author resolved for this post. Pick "Specific" and enter a user ID.', 'rankready-ai-llm-seo' )
					. '</div>';
			}
			return;
		}

		$attrs = array(
			'layout'          => isset( $settings['layout'] ) ? $settings['layout'] : 'card',
			'showHeading'     => 'yes' === ( $settings['show_heading']    ?? 'yes' ),
			'headingText'     => $settings['heading_text'] ?? '',
			'headingTag'      => $settings['heading_tag']  ?? 'h3',
			'showHeadshot'    => 'yes' === ( $settings['show_headshot']    ?? 'yes' ),
			'showJobTitle'    => 'yes' === ( $settings['show_job_title']   ?? 'yes' ),
			'showEmployer'    => 'yes' === ( $settings['show_employer']    ?? 'yes' ),
			'showYearsExp'    => 'yes' === ( $settings['show_years_exp']   ?? 'yes' ),
			'showBio'         => 'yes' === ( $settings['show_bio']         ?? 'yes' ),
			'showExpertise'   => 'yes' === ( $settings['show_expertise']   ?? 'yes' ),
			'showCredentials' => 'yes' === ( $settings['show_credentials'] ?? 'yes' ),
			'showSocials'     => 'yes' === ( $settings['show_socials']     ?? 'yes' ),
			'showReviewed'    => 'yes' === ( $settings['show_reviewed']    ?? 'yes' ),
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo RNRD_Author_Box::render_html( $user_id, $attrs, $post_id );
	}
}
