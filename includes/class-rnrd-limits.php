<?php
/**
 * RankReady — Free tier capabilities.
 *
 * The Free WP.org build has NO monthly caps on manual generation. Every
 * AI Summary and FAQ generation a user triggers is permitted. The features
 * that are NOT in the Free build. Free renders an upsell panel in their place
 * (RNRD_Admin::render_pro_panel) naming the feature and linking to Pro; it has
 * not said "Coming Soon" about any of them since rc.16, because these three
 * have all shipped in Pro and calling a shipped feature Coming Soon both breaks
 * the promise and kills the upsell:
 *
 *   - Bulk regeneration across existing posts
 *   - Auto-generate on post publish/update
 *   - Scheduled / batch processing
 *
 * Those gates live in the relevant feature classes — NOT here.
 *
 * This class is kept as a thin stub so any historical call sites that
 * still reference RNRD_Limits::can_generate_*() / record_*() short-circuit
 * cleanly without breaking the page. New code should not call into it.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Limits {

	// ── Always-allow gates (kept for back-compat) ────────────────────────────

	/**
	 * Manual AI Summary generation is always permitted in Free.
	 *
	 * @return bool true
	 */
	public static function can_generate_summary(): bool {
		return true;
	}

	/**
	 * Manual FAQ generation is always permitted in Free.
	 *
	 * @return bool true
	 */
	public static function can_generate_faq(): bool {
		return true;
	}

	// ── No-op record/usage methods (kept for back-compat) ────────────────────

	public static function record_summary(): void {
		// No cap to enforce — nothing to record.
	}

	public static function record_faq(): void {
		// No cap to enforce — nothing to record.
	}

	public static function summary_used(): int {
		return 0;
	}

	public static function faq_used(): int {
		return 0;
	}

	public static function summary_remaining(): int {
		return PHP_INT_MAX;
	}

	public static function faq_remaining(): int {
		return PHP_INT_MAX;
	}

	// ── REST endpoint kept for back-compat: a public route, so external
	//    callers may poll it even though no bundled script does. ─────────────

	public static function register_rest(): void {
		register_rest_route(
			'rankready/v1',
			'/limits',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_limits' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * REST callback — returns unlimited stats.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_limits(): \WP_REST_Response {
		return rest_ensure_response( self::get_stats() );
	}

	/**
	 * Stats shape — kept the same key names so existing callers don't break,
	 * but every limit is now "unlimited" (-1 sentinel).
	 *
	 * @return array
	 */
	public static function get_stats(): array {
		return array(
			'summary_used'      => 0,
			'summary_limit'     => -1, // -1 = unlimited
			'summary_remaining' => PHP_INT_MAX,
			'faq_used'          => 0,
			'faq_limit'         => -1,
			'faq_remaining'     => PHP_INT_MAX,
			'is_pro'            => false,
			'unlimited'         => true,
			'reset_date'        => '',
		);
	}
}
