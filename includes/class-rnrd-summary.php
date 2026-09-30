<?php
/**
 * AI Summary feature runtime: block render, HTML builder, and auto-display.
 *
 * Gutenberg registration stays in class-rnrd-block.php; schema emission in
 * class-rnrd-schema.php.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Summary {

	/**
	 * Frontend output for AI Summary (block, widget, shortcode, auto-display).
	 * Generated summaries still feed llms.txt / Markdown / WebMCP when this is off.
	 */
	public static function is_enabled(): bool {
		return 'on' === get_option( RNRD_OPT_SUMMARY_ENABLE, 'on' );
	}

	/**
	 * Whether AI Summary applies to this post type (metabox, generate, auto-display).
	 */
	public static function is_post_type_enabled( string $post_type ): bool {
		$types = array_values( array_filter( (array) get_option( RNRD_OPT_POST_TYPES, array( 'post' ) ) ) );
		return in_array( $post_type, $types, true );
	}

	/**
	 * HTML auto-display: off | before | after.
	 */
	public static function get_auto_display(): string {
		return rnrd_auto_display_mode( RNRD_OPT_AUTO_DISPLAY, RNRD_OPT_DISPLAY_POSITION, 'before' );
	}

	public static function init(): void {
		add_filter( 'the_content', array( self::class, 'maybe_auto_display' ), 99 );
	}

	public static function render_block( $attrs, $content = '', $block = null ): string {
		if ( ! self::is_enabled() ) {
			return '';
		}
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}
		$post = get_post( $post_id );
		if ( ! $post || ! self::is_post_type_enabled( $post->post_type ) ) {
			return '';
		}
		// Same opt-out and password rules as the_content, the shortcode, the
		// Elementor widget and the author block. Without this a user who ticked
		// "disable" on a post still got the summary wherever the block sat.
		if ( ! RNRD_Util::can_emit( (int) $post_id, RNRD_META_DISABLE ) ) {
			return '';
		}

		$raw = (string) get_post_meta( $post_id, RNRD_META_SUMMARY, true );
		if ( empty( $raw ) ) {
			return '';
		}

		return self::render_html( $raw, $attrs, true );
	}

	/**
	 * Block attributes array — exposed for register_block_type in class-rnrd-block.php.
	 */
	public static function block_attributes(): array {
		return array(
			// Content.
			'label'               => array( 'type' => 'string',  'default' => '' ),
			'showLabel'           => array( 'type' => 'boolean', 'default' => true ),
			'headingTag'          => array( 'type' => 'string',  'default' => '' ),
			// Box style.
			'boxBgColor'          => array( 'type' => 'string',  'default' => '' ),
			'boxBorderColor'      => array( 'type' => 'string',  'default' => '' ),
			'boxBorderWidth'      => array( 'type' => 'number',  'default' => 3 ),
			'boxBorderRadius'     => array( 'type' => 'number',  'default' => 0 ),
			'boxBorderPosition'   => array( 'type' => 'string',  'default' => 'left' ),
			'boxPadding'          => array( 'type' => 'number',  'default' => 0 ),
			// Label style.
			'labelColor'          => array( 'type' => 'string',  'default' => '' ),
			'labelFontSize'       => array( 'type' => 'number',  'default' => 0 ),
			'labelFontFamily'     => array( 'type' => 'string',  'default' => '' ),
			'labelFontWeight'     => array( 'type' => 'string',  'default' => '' ),
			'labelLineHeight'     => array( 'type' => 'number',  'default' => 0 ),
			'labelLetterSpacing'  => array( 'type' => 'number',  'default' => 0 ),
			'labelTextTransform'  => array( 'type' => 'string',  'default' => '' ),
			// Bullet style.
			'bulletColor'         => array( 'type' => 'string',  'default' => '' ),
			'bulletFontSize'      => array( 'type' => 'number',  'default' => 0 ),
			'bulletFontFamily'    => array( 'type' => 'string',  'default' => '' ),
			'bulletFontWeight'    => array( 'type' => 'string',  'default' => '' ),
			'bulletLineHeight'    => array( 'type' => 'number',  'default' => 0 ),
			'bulletLetterSpacing' => array( 'type' => 'number',  'default' => 0 ),
			'bulletSpacing'       => array( 'type' => 'number',  'default' => 0 ),
			'bulletMarkerColor'   => array( 'type' => 'string',  'default' => '' ),
		);
	}

	public static function render_html( $raw, $attrs = array(), $is_block = false ): string {
		$summary = RNRD_Generator::decode_summary( $raw );
		if ( 'empty' === $summary['type'] ) {
			return '';
		}

		$show_label  = isset( $attrs['showLabel'] ) ? (bool) $attrs['showLabel'] : (bool) get_option( RNRD_OPT_SHOW_LABEL, '1' );
		$label_text  = ! empty( $attrs['label'] )
			? sanitize_text_field( $attrs['label'] )
			: (string) get_option( RNRD_OPT_LABEL, __( 'Key Takeaways', 'rankready-ai-llm-seo' ) );
		$heading_tag = RNRD_Util::validate_heading_tag(
			! empty( $attrs['headingTag'] )
				? $attrs['headingTag']
				: (string) get_option( RNRD_OPT_HEADING_TAG, 'h4' )
		);

		$box_styles = array();
		if ( ! empty( $attrs['boxBgColor'] ) ) {
			$box_styles[] = 'background-color:' . RNRD_Util::sanitize_color( $attrs['boxBgColor'] );
		}

		$border_pos   = isset( $attrs['boxBorderPosition'] ) ? $attrs['boxBorderPosition'] : 'left';
		$border_width = isset( $attrs['boxBorderWidth'] ) ? (int) $attrs['boxBorderWidth'] : 3;
		$border_color = ! empty( $attrs['boxBorderColor'] ) ? RNRD_Util::sanitize_color( $attrs['boxBorderColor'] ) : '';

		if ( 'none' === $border_pos ) {
			$box_styles[] = 'border:none';
		} elseif ( 'all' === $border_pos ) {
			$box_styles[] = 'border-left:none';
			if ( $border_width ) {
				$box_styles[] = 'border:' . $border_width . 'px solid ' . ( $border_color ? $border_color : 'currentColor' );
			}
		} else {
			if ( $border_width ) {
				$box_styles[] = 'border-left-width:' . $border_width . 'px';
			}
			if ( $border_color ) {
				$box_styles[] = 'border-left-color:' . $border_color;
			}
		}

		if ( ! empty( $attrs['boxBorderRadius'] ) ) {
			$box_styles[] = 'border-radius:' . (int) $attrs['boxBorderRadius'] . 'px';
		}
		if ( ! empty( $attrs['boxPadding'] ) ) {
			$box_styles[] = 'padding:' . (int) $attrs['boxPadding'] . 'px';
		}
		if ( ! empty( $attrs['bulletMarkerColor'] ) ) {
			$box_styles[] = '--rnrd-marker-color:' . RNRD_Util::sanitize_color( $attrs['bulletMarkerColor'] );
		}

		$box_style_attr = ! empty( $box_styles ) ? ' style="' . esc_attr( implode( ';', $box_styles ) ) . '"' : '';

		$label_styles = array();
		if ( ! empty( $attrs['labelColor'] ) ) {
			$label_styles[] = 'color:' . RNRD_Util::sanitize_color( $attrs['labelColor'] );
		}
		if ( ! empty( $attrs['labelFontSize'] ) ) {
			$label_styles[] = 'font-size:' . (int) $attrs['labelFontSize'] . 'px';
		}
		if ( ! empty( $attrs['labelFontFamily'] ) ) {
			$rnrd_css = RNRD_Util::sanitize_style_value( 'labelFontFamily', (string) $attrs['labelFontFamily'] );
			if ( '' !== $rnrd_css ) {
				$label_styles[] = 'font-family:' . $rnrd_css;
			}
		}
		if ( ! empty( $attrs['labelFontWeight'] ) ) {
			$rnrd_css = RNRD_Util::sanitize_style_value( 'labelFontWeight', (string) $attrs['labelFontWeight'] );
			if ( '' !== $rnrd_css ) {
				$label_styles[] = 'font-weight:' . $rnrd_css;
			}
		}
		if ( ! empty( $attrs['labelLineHeight'] ) ) {
			$label_styles[] = 'line-height:' . number_format( (float) $attrs['labelLineHeight'], 2 );
		}
		if ( ! empty( $attrs['labelLetterSpacing'] ) ) {
			$label_styles[] = 'letter-spacing:' . number_format( (float) $attrs['labelLetterSpacing'], 2 ) . 'px';
		}
		if ( ! empty( $attrs['labelTextTransform'] ) ) {
			$rnrd_css = RNRD_Util::sanitize_style_value( 'labelTextTransform', (string) $attrs['labelTextTransform'] );
			if ( '' !== $rnrd_css ) {
				$label_styles[] = 'text-transform:' . $rnrd_css;
			}
		}
		$label_style_attr = ! empty( $label_styles ) ? ' style="' . esc_attr( implode( ';', $label_styles ) ) . '"' : '';

		$bullet_styles = array();
		if ( ! empty( $attrs['bulletColor'] ) ) {
			$bullet_styles[] = 'color:' . RNRD_Util::sanitize_color( $attrs['bulletColor'] );
		}
		if ( ! empty( $attrs['bulletFontSize'] ) ) {
			$bullet_styles[] = 'font-size:' . (int) $attrs['bulletFontSize'] . 'px';
		}
		if ( ! empty( $attrs['bulletFontFamily'] ) ) {
			$rnrd_css = RNRD_Util::sanitize_style_value( 'bulletFontFamily', (string) $attrs['bulletFontFamily'] );
			if ( '' !== $rnrd_css ) {
				$bullet_styles[] = 'font-family:' . $rnrd_css;
			}
		}
		if ( ! empty( $attrs['bulletFontWeight'] ) ) {
			$rnrd_css = RNRD_Util::sanitize_style_value( 'bulletFontWeight', (string) $attrs['bulletFontWeight'] );
			if ( '' !== $rnrd_css ) {
				$bullet_styles[] = 'font-weight:' . $rnrd_css;
			}
		}
		if ( ! empty( $attrs['bulletLineHeight'] ) ) {
			$bullet_styles[] = 'line-height:' . number_format( (float) $attrs['bulletLineHeight'], 2 );
		}
		if ( ! empty( $attrs['bulletLetterSpacing'] ) ) {
			$bullet_styles[] = 'letter-spacing:' . number_format( (float) $attrs['bulletLetterSpacing'], 2 ) . 'px';
		}
		if ( ! empty( $attrs['bulletSpacing'] ) ) {
			$bullet_styles[] = 'margin-bottom:' . (int) $attrs['bulletSpacing'] . 'px';
		}
		$bullet_style_attr = ! empty( $bullet_styles ) ? ' style="' . esc_attr( implode( ';', $bullet_styles ) ) . '"' : '';

		$class = 'rnrd-summary';
		if ( $is_block ) {
			$rnrd_wrap_args = array( 'class' => $class );
			if ( ! empty( $box_styles ) ) {
				$rnrd_wrap_args['style'] = implode( ';', $box_styles );
			}
			$wrapper = get_block_wrapper_attributes( $rnrd_wrap_args );
			$out     = '<div ' . $wrapper . '>';
		} else {
			$out = '<div class="' . esc_attr( $class ) . '"' . $box_style_attr . '>';
		}

		if ( $show_label && ! empty( $label_text ) ) {
			$out .= '<' . $heading_tag . ' class="rnrd-label"' . $label_style_attr . '>'
				. esc_html( $label_text )
				. '</' . $heading_tag . '>';
		}

		if ( 'bullets' === $summary['type'] ) {
			$out .= '<ul class="rnrd-bullets">';
			foreach ( (array) $summary['data'] as $bullet ) {
				$out .= '<li class="rnrd-bullet"' . $bullet_style_attr . '>' . esc_html( $bullet ) . '</li>';
			}
			$out .= '</ul>';
		} else {
			$out .= '<p class="rnrd-text"' . $bullet_style_attr . '>' . esc_html( $summary['data'] ) . '</p>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Back-compat wrapper for the old Summary HTML builder name.
	 *
	 * @param mixed               $raw Raw stored summary payload.
	 * @param array<string,mixed> $attrs Render attributes.
	 * @param bool                $is_block Whether this is a Gutenberg block render.
	 */
	public static function build_html( $raw, $attrs = array(), $is_block = false ): string {
		return self::render_html( $raw, $attrs, $is_block );
	}

	public static function maybe_auto_display( $content ): string {
		if ( ! self::is_enabled() ) {
			return $content;
		}
		if ( ! is_singular() || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		$position = self::get_auto_display();
		if ( 'off' === $position ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		if ( get_post_meta( $post_id, RNRD_META_DISABLE, true ) ) {
			return $content;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! is_post_type_viewable( $post->post_type ) ) {
			return $content;
		}
		if ( ! self::is_post_type_enabled( $post->post_type ) ) {
			return $content;
		}

		if ( 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return $content;
		}

		if ( RNRD_Shortcode::post_has_manual( $post, 'rankready/ai-summary', RNRD_Shortcode::SUMMARY ) ) {
			return $content;
		}

		if ( RNRD_Util::is_theme_builder_page( $post_id ) ) {
			return $content;
		}

		$raw = (string) get_post_meta( $post_id, RNRD_META_SUMMARY, true );
		if ( empty( $raw ) ) {
			return $content;
		}

		$summary_html = self::render_html( $raw );
		if ( 'after' === $position ) {
			return $content . $summary_html;
		}

		return $summary_html . $content;
	}

}
