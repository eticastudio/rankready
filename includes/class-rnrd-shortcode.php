<?php
/**
 * Frontend shortcodes — Classic Editor and any shortcode-capable builder.
 *
 * Tags:
 * - [rankready_summary]
 * - [rankready_faq]
 * - [rankready_author]
 *
 * Render through the same HTML builders as Gutenberg / auto-display. Auto-display
 * skips when one of these tags (or the matching block) is already in post_content.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Shortcode {

	public const SUMMARY = 'rankready_summary';
	public const FAQ     = 'rankready_faq';
	public const AUTHOR  = 'rankready_author';

	public static function init(): void {
		add_shortcode( self::SUMMARY, array( self::class, 'render_summary' ) );
		add_shortcode( self::FAQ,     array( self::class, 'render_faq' ) );
		add_shortcode( self::AUTHOR,  array( self::class, 'render_author' ) );
	}

	/**
	 * Display form of a tag, e.g. [rankready_summary].
	 */
	public static function tag( string $name ): string {
		return '[' . $name . ']';
	}

	/**
	 * True when the post already places this feature manually (block or shortcode).
	 * Used by auto-display so the_content does not inject a second copy.
	 *
	 * @param WP_Post|int|null $post
	 */
	public static function post_has_manual( $post, string $block_name, string $shortcode ): bool {
		if ( is_numeric( $post ) ) {
			$post = get_post( (int) $post );
		}
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( has_block( $block_name, $post ) ) {
			return true;
		}
		return has_shortcode( (string) $post->post_content, $shortcode );
	}

	/**
	 * @param array<string,mixed>|string $atts
	 */
	public static function render_summary( $atts = array() ): string {
		if ( ! class_exists( 'RNRD_Summary' ) || ! RNRD_Summary::is_enabled() ) {
			return '';
		}
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}
		$post = get_post( $post_id );
		if ( ! $post || ! RNRD_Summary::is_post_type_enabled( $post->post_type ) ) {
			return '';
		}
		// Manual placement is still placement: honour the per-post opt-out and
		// skip password-protected posts, exactly as the automatic path does.
		if ( ! RNRD_Util::can_emit( (int) $post_id, RNRD_META_DISABLE ) ) {
			return '';
		}
		$raw = (string) get_post_meta( $post_id, RNRD_META_SUMMARY, true );
		if ( '' === $raw ) {
			return '';
		}
		return RNRD_Summary::render_html( $raw );
	}

	/**
	 * @param array<string,mixed>|string $atts
	 */
	public static function render_faq( $atts = array() ): string {
		if ( ! class_exists( 'RNRD_Faq' ) || ! RNRD_Faq::is_enabled() ) {
			return '';
		}
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}
		$post = get_post( $post_id );
		if ( ! $post || ! RNRD_Faq::is_post_type_enabled( $post->post_type ) ) {
			return '';
		}
		// Manual placement is still placement: honour the per-post opt-out and
		// skip password-protected posts, exactly as the automatic path does.
		if ( ! RNRD_Util::can_emit( (int) $post_id, RNRD_META_FAQ_DISABLE ) ) {
			return '';
		}
		$faq_data = RNRD_Faq::get_faq_data( (int) $post_id );
		if ( empty( $faq_data ) || ! is_array( $faq_data ) ) {
			return '';
		}
		return RNRD_Faq::render_html( $faq_data, array(), (int) $post_id );
	}

	/**
	 * @param array<string,mixed>|string $atts
	 */
	public static function render_author( $atts = array() ): string {
		if ( ! class_exists( 'RNRD_Author_Box' ) || ! RNRD_Author_Box::is_enabled() ) {
			return '';
		}
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}
		$post = get_post( $post_id );
		if ( ! $post || ! RNRD_Author_Box::is_post_type_enabled( $post->post_type ) ) {
			return '';
		}
		// Manual placement is still placement: honour the per-post opt-out and
		// skip password-protected posts, exactly as the automatic path does.
		if ( ! RNRD_Util::can_emit( (int) $post_id, RNRD_META_AUTHOR_DISABLE ) ) {
			return '';
		}
		$user_id = (int) get_post_field( 'post_author', $post_id );
		if ( $user_id <= 0 ) {
			return '';
		}
		return RNRD_Author_Box::render_html( $user_id, array(), (int) $post_id );
	}
}
