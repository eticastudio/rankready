<?php
/**
 * Content Signals — contentsignals.org directive builder for robots.txt.
 *
 * Manages the three content-usage permission signals (ai-train, search,
 * ai-input) that tell AI engines what they may do with site content.
 * The directives are emitted inside the RankReady block in robots.txt by
 * RNRD_Robots, which reads is_enabled() and get_directive() and places the
 * Content-Signal line in its own `User-agent: *` group.
 * get_robots_fragment() has no caller inside the plugin since 1.4.0; it stays
 * public because it shipped, and third-party code may call it.
 *
 * Option keys (defined in rankready.php):
 *   RNRD_OPT_CONTENT_SIGNALS_ENABLE   — master on/off toggle
 *   RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN — 'allow' | 'deny'
 *   RNRD_OPT_CONTENT_SIGNALS_SEARCH   — 'allow' | 'deny'
 *   RNRD_OPT_CONTENT_SIGNALS_AI_INPUT — 'allow' | 'deny'
 *
 * @package RankReady
 * @since   1.2.2
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Content_Signals {

	/**
	 * Whether Content Signals are enabled.
	 */
	public static function is_enabled(): bool {
		return 'on' === get_option( RNRD_OPT_CONTENT_SIGNALS_ENABLE, 'off' );
	}

	/**
	 * Read the three signal values.
	 *
	 * @return array{ai_train:string, search:string, ai_input:string}
	 *   Each value is 'yes' (allow) or 'no' (deny) — the output format
	 *   per the contentsignals.org spec.
	 */
	public static function get_signals(): array {
		return array(
			'ai_train' => 'allow' === get_option( RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN, 'allow' ) ? 'yes' : 'no',
			'search'   => 'allow' === get_option( RNRD_OPT_CONTENT_SIGNALS_SEARCH, 'allow' ) ? 'yes' : 'no',
			'ai_input' => 'allow' === get_option( RNRD_OPT_CONTENT_SIGNALS_AI_INPUT, 'allow' ) ? 'yes' : 'no',
		);
	}

	/**
	 * Build the robots.txt fragment for Content Signals.
	 *
	 * Returns an empty string when the feature is off.
	 * The returned string is meant to be embedded inside the RankReady
	 * BEGIN/END block — it does NOT include those markers.
	 *
	 * Format per isitagentready.com:
	 *   # Content Signals (contentsignals.org)
	 *   Content-Signal: ai-train=yes, search=yes, ai-input=yes
	 */
	public static function get_robots_fragment(): string {
		if ( ! self::is_enabled() ) {
			return '';
		}

		return "# Content Signals (contentsignals.org)\n" . self::get_directive() . "\n\n";
	}

	/**
	 * The bare `Content-Signal:` line, or an empty string when the feature is off.
	 *
	 * RNRD_Robots places it inside its own `User-agent: *` group, since the
	 * directive applies to whichever group it sits in.
	 */
	public static function get_directive(): string {
		if ( ! self::is_enabled() ) {
			return '';
		}

		$s = self::get_signals();
		return "Content-Signal: ai-train={$s['ai_train']}, search={$s['search']}, ai-input={$s['ai_input']}";
	}
}
