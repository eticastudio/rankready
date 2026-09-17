<?php
/**
 * Summary generator — triggers on publish/update, async via shutdown + cron fallback.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Generator {

	/** Minimum seconds between API calls for the same post. */
	private const MIN_INTERVAL = 30;

	public static function init(): void {
		// NOTE: the save_post-driven auto-generation hook (`schedule_generation`)
		// is a Pro engine. It is NOT registered here — the Pro add-on
		// (RNRD_Pro_Autogen) hooks `wp_after_insert_post` → schedule_generation
		// on its own init. The Free build only keeps the manual/cron runner
		// below (used by the single-post Regenerate button + REST).
		add_action( RNRD_CRON_HOOK, array( self::class, 'run_generation' ) );
	}

	// ── Trigger ───────────────────────────────────────────────────────────────

	/** Guard against re-entrant calls from wp_update_post inside generators. */
	public static $generating = false;

	/**
	 * Auto-generate-on-publish trigger. PRO ENGINE — this method is only hooked
	 * to `wp_after_insert_post` by the Pro add-on (RNRD_Pro_Autogen). It stays
	 * defined in Free so the bulk Author Changer can safely toggle it on/off
	 * around author reassignment, and so Pro can reference it as a callable.
	 * When Pro is inactive nothing hooks it, so it never fires.
	 */
	public static function schedule_generation( $post_id, $post, $update, $post_before ): void {
		$post_id = (int) $post_id;

		// Block re-entrant calls (FAQ generate_faq calls wp_update_post which re-fires this).
		if ( self::$generating ) {
			return;
		}

		// Auto-generate toggle: if off, only generate via manual/bulk actions.
		if ( 'on' !== get_option( RNRD_OPT_AUTO_GENERATE, 'off' ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}
		if ( class_exists( 'RNRD_Summary' ) && ! RNRD_Summary::is_post_type_enabled( $post->post_type ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// REST API autosave check (Gutenberg)
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
			if ( false !== strpos( $route, '/autosaves' ) ) {
				return;
			}
		}

		// Only run for public post types (skip attachments, nav_menu_item, etc.).
		$public_types = get_post_types( array( 'public' => true ), 'names' );
		if ( ! isset( $public_types[ $post->post_type ] ) || 'attachment' === $post->post_type ) {
			return;
		}

		// Per-post disable
		if ( get_post_meta( $post_id, RNRD_META_DISABLE, true ) ) {
			return;
		}

		// No API key for the active LLM provider — nothing to call.
		if ( ! RNRD_LLM::active_provider_ready() ) {
			return;
		}

		// Hash check: only call API if content changed
		$content  = self::get_content_string( $post );
		$new_hash = md5( $content );
		$old_hash = (string) get_post_meta( $post_id, RNRD_META_HASH, true );

		if ( $new_hash === $old_hash ) {
			return;
		}

		update_post_meta( $post_id, RNRD_META_HASH, $new_hash );

		// Use WP-Cron only (single path, no double-fire).
		wp_clear_scheduled_hook( RNRD_CRON_HOOK, array( $post_id ) );
		wp_schedule_single_event( time() + 5, RNRD_CRON_HOOK, array( $post_id ) );
		spawn_cron();
	}


	// ── WP-Cron runner (fallback) ─────────────────────────────────────────────

	public static function run_generation( $post_id ): void {
		self::$generating = true;

		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			self::$generating = false;
			return;
		}
		if ( class_exists( 'RNRD_Summary' ) && ! RNRD_Summary::is_post_type_enabled( $post->post_type ) ) {
			self::$generating = false;
			return;
		}

		// Per-post disable check
		if ( get_post_meta( $post_id, RNRD_META_DISABLE, true ) ) {
			self::$generating = false;
			return;
		}

		if ( ! RNRD_LLM::active_provider_ready() ) {
			self::$generating = false;
			return;
		}

		$last = (int) get_post_meta( $post_id, RNRD_META_GENERATED, true );
		if ( $last && ( time() - $last ) < self::MIN_INTERVAL ) {
			self::$generating = false;
			return;
		}

		$content = self::get_content_string( $post );
		$result  = self::call_openai( $content, $post );

		if ( $result ) {
			update_post_meta( $post_id, RNRD_META_SUMMARY,   $result );
			update_post_meta( $post_id, RNRD_META_GENERATED, time() );
		}

		self::$generating = false;
	}

	// ── Force generation (REST) ───────────────────────────────────────────────

	/**
	 * Force-generate summary for a post.
	 *
	 * @param int  $post_id       Post ID.
	 * @param bool $skip_unchanged When true, skip if content hash matches (token saver).
	 * @return string|false Generated summary or false on failure.
	 */
	public static function force_generate( $post_id, $skip_unchanged = false ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		// Free build: unlimited manual generation. No cap to check.

		if ( ! RNRD_LLM::active_provider_ready() ) {
			return false;
		}

		$content  = self::get_content_string( $post );
		$new_hash = md5( $content );

		// Skip if content unchanged and summary already exists.
		if ( $skip_unchanged ) {
			$old_hash = (string) get_post_meta( $post_id, RNRD_META_HASH, true );
			$existing = (string) get_post_meta( $post_id, RNRD_META_SUMMARY, true );
			if ( $new_hash === $old_hash && ! empty( $existing ) ) {
				return $existing; // Return existing without API call.
			}
		}

		$result = self::call_openai( $content, $post );

		if ( $result && ! is_wp_error( $result ) ) {
			update_post_meta( $post_id, RNRD_META_SUMMARY,   $result );
			update_post_meta( $post_id, RNRD_META_HASH,      $new_hash );
			update_post_meta( $post_id, RNRD_META_GENERATED, time() );
		}

		return $result;
	}

	// ── LLM call (multi-provider since v1.1.1) ─────────────────────────────────
	//
	// Method name kept as `call_openai()` for back-compat with any external
	// callers, but the body now dispatches through `RNRD_LLM::generate()` to
	// whichever provider is active (OpenAI / Claude / Gemini / DeepSeek).
	// `$api_key` parameter is ignored — the active provider's own key is
	// fetched by RNRD_LLM. Callers can pass an empty string.

	public static function call_openai( $content, $post, $api_key = '' ) {
		$word_count   = preg_match_all( '/\S+/', $content );
		$word_count   = false !== $word_count ? $word_count : 0;
		$bullet_count = 3;
		if ( $word_count >= 1500 ) {
			$bullet_count = 5;
		} elseif ( $word_count >= 600 ) {
			$bullet_count = 4;
		}

		$content = mb_substr( $content, 0, 12000 );

		$system_prompt  = RNRD_LLM::language_directive( is_object( $post ) ? $post->ID : (int) $post );
		$system_prompt .= "You extract key takeaways from blog posts. You produce factual, entity-rich bullet points.\n\n";
		$system_prompt .= "ABSOLUTE RULES:\n";
		$system_prompt .= "- You may ONLY state facts that appear word-for-word or are directly implied by the blog post text below.\n";
		$system_prompt .= "- NEVER add features, tools, integrations, platforms, pricing, or claims the post does not mention.\n";
		$system_prompt .= "- NEVER say a product works with a platform unless the post explicitly says so.\n";
		$system_prompt .= "- If you are unsure whether something is true, leave it out.\n";
		$system_prompt .= "- Use the exact product names, brand names, and version numbers from the post. No renaming, no generalizing.\n";
		$system_prompt .= "- No em dashes. No filler words (certainly, indeed, comprehensive, robust, leverage, utilize). No promotional language.";

		// v1.2.0-rc.3 — product context now sourced from Brand Identity About
		// field. Falls back to legacy rnrd_product_context for back-compat with
		// installs that filled in the old field before rc.3.
		$product_context = '';
		if ( class_exists( 'RNRD_Llms_Txt' ) ) {
			$product_context = RNRD_Brand_Identity::get_brand_about();
		}
		if ( '' === $product_context ) {
			$product_context = (string) get_option( RNRD_OPT_PRODUCT_CONTEXT, '' );
		}
		if ( ! empty( $product_context ) ) {
			$system_prompt .= "\n\nPRODUCT CONTEXT (use this as a fact-check reference — never contradict this, never add details beyond this):\n" . $product_context;
		}

		// Inject canonical brand terms (v1.2.0) — single source from AI Crawlers tab.
		// Wires same one input through every LLM call so brand naming stays consistent.
		if ( class_exists( 'RNRD_Llms_Txt' ) ) {
			$brand_terms = RNRD_Brand_Identity::get_brand_terms_string();
			if ( '' !== $brand_terms ) {
				$system_prompt .= "\n\nCANONICAL BRAND NAMES (use these exact spellings — never abbreviate, paraphrase, or use variants):\n" . $brand_terms;
			}
		}

		// Append custom prompt if set.
		$custom_prompt = (string) get_option( RNRD_OPT_CUSTOM_PROMPT, '' );
		if ( ! empty( $custom_prompt ) ) {
			$system_prompt .= "\n\nAdditional instructions:\n" . $custom_prompt;
		}

		$user_prompt = sprintf(
			'Extract exactly %d key takeaways from the blog post below.

Each takeaway must:
- Be one specific, valuable insight a reader would remember
- Start with a named entity (product name, feature name, tool name) or a strong action verb
- Contain at least one specific detail: a name, number, feature, or outcome
- Be factually accurate to the blog post — zero invention
- Be written in present tense, third person
- Be scannable in under 3 seconds

Do NOT:
- Start with "You can", "This post", "Learn how", "Find out", "Discover"
- Include vague takeaways like "improves performance" without specifics
- Mention any tool, platform, or integration the post does not explicitly discuss
- Repeat the same point in different words

Return ONLY valid JSON: {"bullets":["Takeaway 1.","Takeaway 2.","Takeaway 3."]}

Blog Post:
%s',
			$bullet_count,
			$content
		);

		$result = RNRD_LLM::generate( $system_prompt, $user_prompt, array(
			'max_tokens'  => 500,
			'temperature' => 0.2,
			'json'        => true,
			'timeout'     => 25,
		) );

		$source_label = strtoupper( (string) ( $result['provider'] ?? 'unknown' ) );

		if ( empty( $result['ok'] ) ) {
			self::log_error( $source_label, (string) $result['error'], $post->ID );
			return false;
		}

		// Track token usage (combined in/out for back-compat with existing
		// totals widget; cost stays accurate per provider).
		self::track_tokens( (int) $result['tokens_total'], $post->ID, 'summary' );

		$raw = (string) $result['content'];
		if ( '' === $raw ) {
			self::log_error( $source_label, 'Empty content from provider.', $post->ID );
			return false;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['bullets'] ) || ! is_array( $decoded['bullets'] ) ) {
			self::log_error( $source_label, 'Unexpected JSON structure: ' . mb_substr( $raw, 0, 200 ), $post->ID );
			return false;
		}

		$decoded['bullets'] = array_values(
			array_filter(
				array_map( 'sanitize_text_field', $decoded['bullets'] )
			)
		);

		// JSON_UNESCAPED_UNICODE keeps Turkish (ı, ş, ğ), CJK (中文/日本語/한국어),
		// Arabic, Hindi, Cyrillic, and every other non-Latin alphabet as actual
		// UTF-8 bytes in storage. Default json_encode escapes them to \uXXXX,
		// which is valid JSON but fragile across WP's slash-handling layers
		// (sanitize_meta filters, magic-quotes, translation plugins) — a single
		// dropped backslash turns "Yatırım" into visible "Yu0131lu0131".
		// JSON_UNESCAPED_SLASHES keeps URLs and paths readable.
		return wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	// ── Connection test ───────────────────────────────────────────────────────

	/**
	 * Connection test — pings the active LLM provider with a tiny prompt.
	 * Returns true on success, or a string error message on failure.
	 *
	 * Routes through `RNRD_LLM::generate()` so adding a new provider needs no
	 * changes here.
	 */
	public static function test_api_connection() {
		if ( ! RNRD_LLM::active_provider_ready() ) {
			return sprintf(
				/* translators: %s: AI provider name */
				__( 'No %s API key configured.', 'rankready-ai-llm-seo' ),
				RNRD_LLM::get_provider_label( RNRD_LLM::get_active_provider() )
			);
		}

		$result = RNRD_LLM::generate(
			'You reply with the single word OK.',
			'Reply with OK only.',
			array(
				'max_tokens'  => 5,
				'temperature' => 0.0,
				'json'        => false,
				'timeout'     => 10,
			)
		);

		return ! empty( $result['ok'] ) ? true : (string) $result['error'];
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public static function get_content_string( $post ): string {
		if ( ! class_exists( 'RNRD_Markdown' ) ) {
			// translators: %d: Post ID.
			trigger_error( sprintf( 'RankReady: RNRD_Markdown class not loaded for post %d. AI summary content may be empty.', $post->ID ), E_USER_WARNING );
			return $post->post_title;
		}
		// Cached markdown handles page builders, shortcodes, WooCommerce stripping, and builder wrapper cleanup.
		$body = RNRD_Markdown::get_post_markdown( $post );
		return $post->post_title . "\n\n" . $body;
	}

	public static function decode_summary( $raw ): array {
		if ( empty( $raw ) ) {
			return array( 'type' => 'empty', 'data' => array() );
		}

		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && ! empty( $decoded['bullets'] ) ) {
			return array( 'type' => 'bullets', 'data' => $decoded['bullets'] );
		}

		return array( 'type' => 'text', 'data' => $raw );
	}

	// ── Error logging ────────────────────────────────────────────────────────

	public static function log_error( string $source, string $message, int $post_id = 0 ): void {
		$log = (array) get_option( 'rnrd_error_log', array() );

		$log[] = array(
			'time'    => time(),
			'source'  => $source,
			'message' => mb_substr( $message, 0, 500 ),
			'post_id' => $post_id,
		);

		// Keep last 50 entries.
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, -50 );
		}

		update_option( 'rnrd_error_log', $log, false );
	}

	public static function get_error_log(): array {
		return (array) get_option( 'rnrd_error_log', array() );
	}

	public static function clear_error_log(): void {
		delete_option( 'rnrd_error_log' );
	}

	// ── Token tracking ───────────────────────────────────────────────────────

	public static function track_tokens( int $tokens, int $post_id, string $type ): void {
		// Per-post tracking.
		$meta_key = '_rnrd_tokens_used';
		$current  = (int) get_post_meta( $post_id, $meta_key, true );
		update_post_meta( $post_id, $meta_key, $current + $tokens );

		// Global cumulative tracking.
		$totals = (array) get_option( 'rnrd_token_usage', array(
			'summary_tokens' => 0,
			'faq_tokens'     => 0,
			'total_calls'    => 0,
		) );

		if ( 'summary' === $type ) {
			$totals['summary_tokens'] = ( isset( $totals['summary_tokens'] ) ? (int) $totals['summary_tokens'] : 0 ) + $tokens;
		} else {
			$totals['faq_tokens'] = ( isset( $totals['faq_tokens'] ) ? (int) $totals['faq_tokens'] : 0 ) + $tokens;
		}
		$totals['total_calls'] = ( isset( $totals['total_calls'] ) ? (int) $totals['total_calls'] : 0 ) + 1;

		update_option( 'rnrd_token_usage', $totals, false );
	}
}
