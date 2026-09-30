<?php
/**
 * RankReady — AI snippet preview controls.
 *
 * Injects `<meta name="robots" content="max-snippet:-1, max-image-preview:large">`
 * on singular post views, so a crawler that honours the directive is allowed
 * the full passage instead of being capped at ~160 characters.
 *
 * Three claims used to sit here: a named SEO vendor's "AI citation study", a
 * ranked ordinal with a score out of ten, and a statement that unlimited
 * snippets are more likely to be quoted verbatim. All three were deleted on
 * 2026-09-26, and none of them are restated here, both so they cannot be copied
 * back into copy and because the build gate now reads comments too.
 * None of those numbers came from a source anybody here has read,
 * and the last one is an outcome claim about citations, which this project does
 * not make in copy and should not make in a comment either. What the directive
 * actually does is lift a length cap; whether anything quotes the result is
 * not ours to assert.
 *
 * Resolution order per post:
 *   1. Per-post meta `_rnrd_max_snippet` if set ('on' | 'off').
 *   2. Site-wide default `rnrd_max_snippet_default` (defaults to 'on').
 *
 * No-op when:
 *   - The post is marked noindex by Yoast / RankMath / AIOSEO / SEOPress.
 *   - The user explicitly set 'off' on the post.
 *
 * @package RankReady
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Snippet {

	public static function init(): void {
		// v1.2.0 — Merge our snippet directives into WordPress core's SINGLE
		// robots meta via the wp_robots filter (WP 5.7+), the required practice.
		// Previously we echoed our own <meta name="robots"> on wp_head, which
		// duplicated core's wp_robots tag on core-only sites (two robots metas).
		// The filter only runs when core actually renders the robots meta — i.e.
		// when NO SEO plugin has taken it over — so there is never a duplicate.
		add_filter( 'wp_robots', array( self::class, 'add_robots_directives' ) );

		// When Yoast / RankMath / AIOSEO manage their own robots meta (and
		// disable core's wp_robots), merge into their tag instead.
		add_filter( 'wpseo_robots_array',  array( self::class, 'merge_into_yoast' ), 20 );
		add_filter( 'rank_math/frontend/robots', array( self::class, 'merge_into_rankmath' ), 20 );
		add_filter( 'aioseo_robots_meta', array( self::class, 'merge_into_aioseo' ), 20 );
	}

	/**
	 * Detect whether another SEO plugin already emitted a robots meta tag
	 * in this request. We do this by checking if their robots filter was
	 * even applied (Yoast/RankMath/AIOSEO/SEOPress) since we've hooked it.
	 *
	 * Tracked via static flag set by our merge_into_* filters.
	 */
	private static $seo_plugin_handled = false;

	public static function merge_into_yoast( array $robots ): array {
		self::$seo_plugin_handled = true;
		if ( self::should_emit_for_current_post() ) {
			$robots['max-snippet']       = 'max-snippet:-1';
			$robots['max-image-preview'] = 'max-image-preview:large';
			$robots['max-video-preview'] = 'max-video-preview:-1';
		}
		return $robots;
	}

	public static function merge_into_rankmath( $robots ) {
		self::$seo_plugin_handled = true;
		if ( ! is_array( $robots ) ) {
			return $robots;
		}
		if ( self::should_emit_for_current_post() ) {
			$robots['max-snippet']       = 'max-snippet:-1';
			$robots['max-image-preview'] = 'max-image-preview:large';
			$robots['max-video-preview'] = 'max-video-preview:-1';
		}
		return $robots;
	}

	public static function merge_into_aioseo( $robots ) {
		self::$seo_plugin_handled = true;
		if ( ! self::should_emit_for_current_post() ) {
			return $robots;
		}
		// v1.2.0 — AIOSEO 4.x passes an ASSOCIATIVE array ( key => directive
		// string ) and imploads the VALUES; the pre-1.2.0 guard only handled a
		// string, so on AIOSEO 4.x our directives were silently dropped. Handle
		// the array form (and keep the legacy string form for older builds).
		if ( is_array( $robots ) ) {
			$robots['max-snippet']       = 'max-snippet:-1';
			$robots['max-image-preview'] = 'max-image-preview:large';
			$robots['max-video-preview'] = 'max-video-preview:-1';
			return $robots;
		}
		if ( is_string( $robots ) ) {
			$additions = array( 'max-snippet:-1', 'max-image-preview:large', 'max-video-preview:-1' );
			foreach ( $additions as $directive ) {
				if ( false === stripos( $robots, $directive ) ) {
					$robots = rtrim( $robots, ', ' ) . ', ' . $directive;
				}
			}
		}
		return $robots;
	}

	/**
	 * Check current post state without emitting. Used by the merge filters
	 * so we don't duplicate the noindex / per-post / sitewide logic.
	 */
	private static function should_emit_for_current_post(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}
		if ( self::is_noindex( $post->ID ) ) {
			return false;
		}
		$pref = (string) get_post_meta( $post->ID, RNRD_META_MAX_SNIPPET, true );
		if ( '' === $pref ) {
			$pref = 'on' === get_option( RNRD_OPT_MAX_SNIPPET_DEFAULT, 'on' ) ? 'on' : 'off';
		}
		return 'on' === $pref;
	}

	/**
	 * Merge RankReady's snippet directives into WordPress core's single robots
	 * meta (the wp_robots filter, WP 5.7+). This replaces the old standalone
	 * <meta name="robots"> echo, which duplicated core's tag on core-only sites.
	 *
	 * Core renders each entry as "key:value", so the values below are the value
	 * ONLY ('-1', 'large') — core outputs "max-snippet:-1" etc. The filter only
	 * fires when core actually renders the robots meta (i.e. no SEO plugin took
	 * it over), so there is never a duplicate tag.
	 *
	 * @param array $robots Directive array from core.
	 * @return array
	 */
	public static function add_robots_directives( array $robots ): array {
		if ( self::should_emit_for_current_post() ) {
			$robots['max-snippet']       = '-1';
			$robots['max-image-preview'] = 'large';
			$robots['max-video-preview'] = '-1';
		}
		return $robots;
	}

	/**
	 * Returns true when a major SEO plugin has already flagged this post as
	 * noindex. Mirrors RNRD_Llms_Txt::should_exclude_from_llms() — same logic,
	 * different purpose.
	 */
	private static function is_noindex( int $post_id ): bool {
		if ( defined( 'WPSEO_VERSION' ) && '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}
		if ( defined( 'AIOSEO_VERSION' ) && '1' === (string) get_post_meta( $post_id, '_aioseo_noindex', true ) ) {
			return true;
		}
		if ( ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) ) && 'yes' === (string) get_post_meta( $post_id, '_seopress_robots_index', true ) ) {
			return true;
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm_robots = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true ) ) {
				return true;
			}
		}
		return false;
	}
}
