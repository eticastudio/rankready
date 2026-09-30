<?php
/**
 * Shared utilities used across RankReady feature classes.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Util {

	/**
	 * Check if a theme builder (Elementor Pro or Nexter) renders this page.
	 * The blog post itself is NOT built with Elementor — the theme builder template is.
	 */
	public static function is_theme_builder_page( int $post_id ): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return true;
		}

		if ( did_action( 'elementor/theme/before_do_single' ) ) {
			return true;
		}

		if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			$theme_module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
			if ( method_exists( $theme_module, 'get_conditions_manager' ) ) {
				$conditions = $theme_module->get_conditions_manager();
				if ( method_exists( $conditions, 'get_documents_for_location' ) ) {
					$docs = $conditions->get_documents_for_location( 'single' );
					if ( ! empty( $docs ) ) {
						return true;
					}
				}
			}
		}

		if ( did_action( 'nexter_single_builder_render' ) ) {
			return true;
		}

		$nxt_single = get_option( 'nexter_builder_single_template', '' );
		if ( ! empty( $nxt_single ) ) {
			return true;
		}

		return false;
	}

	public static function validate_heading_tag( $tag ): string {
		$allowed = array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' );
		return in_array( $tag, $allowed, true ) ? $tag : 'h4';
	}

	public static function sanitize_color( $color ): string {
		$color = trim( (string) $color );
		// Inside rgb()/hsl() the charset admits every CSS Color 4 form
		// (`rgb(0 0 0 / 50%)`, `hsl(210deg 50% 50%)`) but never `;` `:` `(` `)`
		// or a quote, so the value cannot close the function and open a second
		// declaration. The old `[^)]+` admitted `rgb(0,0,0;background-image:x)`.
		if ( preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\([\w\s.,%\/+-]+\)|hsla?\([\w\s.,%\/+-]+\)|[a-zA-Z]{3,20})$/', $color ) ) {
			return $color;
		}
		return '';
	}

	/**
	 * Validate one CSS value destined for a `style` attribute, by value kind.
	 *
	 * Two reasons this exists instead of a bare esc_attr() at each call site.
	 *
	 * 1. **esc_attr() is the wrong layer.** It stops attribute breakout but
	 *    leaves `;` intact, so a post editor could set a font family to
	 *    `Georgia;background-image:url(https://attacker.example)` and inject a
	 *    second declaration: an outbound request on every render, or a
	 *    UI-redress overlay. An allowlist per value kind is the layer that
	 *    actually closes that.
	 * 2. **Escaping twice corrupts the value.** The caller escapes the whole
	 *    imploded style attribute, so a value escaped here as well comes out
	 *    as `Georgia, &quot;Times New Roman&quot;, serif` — the browser reads
	 *    the entities literally and drops the declaration, so anyone who picks
	 *    a font with a space in its name silently gets the wrong font. This
	 *    returns the validated value RAW and leaves escaping to the caller.
	 *
	 * @param string $attr  Block attribute name, e.g. 'labelFontFamily'. Matched
	 *                      case-insensitively on the kind suffix, so
	 *                      'questionFontWeight' and 'fontWeight' behave alike.
	 * @param string $value Raw attribute value.
	 * @return string Validated value, or '' when the caller should skip the
	 *                declaration entirely.
	 */
	public static function sanitize_style_value( string $attr, string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( false !== stripos( $attr, 'color' ) ) {
			return self::sanitize_color( $value );
		}

		if ( false !== stripos( $attr, 'fontfamily' ) ) {
			// Font stacks: letters, digits, spaces, quotes, hyphens, commas, and
			// the parentheses of a theme.json preset such as
			// var(--wp--preset--font-family--inter). Anything that can end the
			// declaration or open markup (; { } < > \ :) is still rejected.
			return preg_match( '/^[a-zA-Z0-9 ,\'"\-_()]{1,300}$/', $value ) ? $value : '';
		}

		if ( false !== stripos( $attr, 'fontweight' ) ) {
			return preg_match( '/^(normal|bold|lighter|bolder|[1-9]00)$/', $value ) ? $value : '';
		}

		if ( false !== stripos( $attr, 'texttransform' ) ) {
			return preg_match( '/^(none|uppercase|lowercase|capitalize)$/', $value ) ? $value : '';
		}

		if ( false !== stripos( $attr, 'lineheight' ) ) {
			return is_numeric( $value ) ? self::plain_number( $value ) : '';
		}

		// Unknown kind — a conservative token charset.
		return preg_match( '/^[a-zA-Z0-9 .%\-]{1,60}$/', $value ) ? $value : '';
	}

	/**
	 * A float as a plain decimal string, locale-independently.
	 *
	 * Casting a float to string is locale-dependent on PHP 7.4, the declared
	 * minimum: under LC_NUMERIC=de_DE, `(string) 8.5` is "8,5". In CSS that
	 * emits `--rnrd-ab-radius:8,5px` and kills the declaration; handed to
	 * `$wpdb->prepare( '%f' )` it casts to 0 and the value is silently lost.
	 * PHP 8.0 made the cast locale-independent; 7.4 did not, so this does it
	 * by hand. Use it anywhere a float becomes text.
	 *
	 * @param mixed $value Numeric value.
	 * @param int   $dp    Maximum decimal places to keep.
	 * @return string e.g. "8.5", "16", "1.25".
	 */
	public static function plain_number( $value, int $dp = 4 ): string {
		$out = number_format( (float) $value, $dp, '.', '' );
		if ( false !== strpos( $out, '.' ) ) {
			$out = rtrim( rtrim( $out, '0' ), '.' );
		}
		return '' === $out ? '0' : $out;
	}

	/**
	 * May RankReady emit this post's generated text for this feature?
	 *
	 * Two checks that every emitter needs and that only some of them had.
	 *
	 * 1. **Per-post opt-out.** `_rnrd_disable_summary`, `_rnrd_faq_disable` and
	 *    `_rnrd_author_disable` used to be read on the visible `the_content`
	 *    path and nowhere else. A user who ticked "disable" watched the box
	 *    disappear from the page while the identical text kept going out as
	 *    JSON-LD, as Markdown to AI crawlers, through the shortcodes, through
	 *    the Elementor widgets and over WebMCP. The opt-out has to mean the
	 *    same thing on every surface or it does not mean anything.
	 * 2. **Password-protected posts.** WordPress replaces the content with the
	 *    password form; the generated summary, FAQ and author schema are
	 *    derived from that content and were not covered by it. Thirteen sites
	 *    in this plugin already check `post_password` (the oldest is labelled
	 *    TC-SEC-01); the manual-placement paths and the SEO-plugin merge
	 *    filters did not, so this closes an asymmetry rather than setting new
	 *    policy.
	 *
	 * Deliberately NOT checked here: `post_status`, `is_post_type_enabled()`
	 * and `is_post_type_viewable()`. Those vary by caller by design — the
	 * shortcodes must still render inside a draft preview, and the per-feature
	 * post-type setting lives on each feature class. Callers keep those.
	 *
	 * @param int    $post_id       Post to test.
	 * @param string $disable_meta  Opt-out meta key, e.g. RNRD_META_FAQ_DISABLE.
	 *                              Pass '' to check only the password state.
	 * @return bool True when emitting is allowed.
	 */
	public static function can_emit( int $post_id, string $disable_meta = '' ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( '' !== (string) $post->post_password ) {
			return false;
		}

		if ( '' !== $disable_meta && get_post_meta( $post_id, $disable_meta, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * wp_update_post() for a metadata-only change, without re-filtering the content.
	 *
	 * wp_update_post() re-runs the stored post_content through kses whenever the
	 * current user lacks `unfiltered_html`. That is every cron tick (no user),
	 * every multisite Editor or Admin, and any site with DISALLOW_UNFILTERED_HTML.
	 * A "touch" or an author change would then silently strip iframes, style
	 * blocks, forms and non-allowed attributes from posts nobody edited. The
	 * content is unchanged here, so the kses filters are lifted for this one call
	 * and put back exactly as they were.
	 *
	 * @param array $postarr   Fields to update; must include ID.
	 * @param bool  $wp_error  Return WP_Error on failure, as wp_update_post() does.
	 * @return int|WP_Error
	 */
	public static function update_post_fields( array $postarr, bool $wp_error = false ) {
		$filtered = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $filtered ) {
			kses_remove_filters();
		}
		try {
			return wp_update_post( $postarr, $wp_error );
		} finally {
			if ( $filtered ) {
				kses_init_filters();
			}
		}
	}
}
