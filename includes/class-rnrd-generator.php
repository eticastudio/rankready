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

	/**
	 * Fewest words (title included) worth sending to the model. The manual REST
	 * endpoint has always refused below this; every other route into the model
	 * now shares the same bar, so an unreadable body cannot reach the provider.
	 */
	public const MIN_WORDS = 25;

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

		/*
		 * Hash check: only call the API if the content changed.
		 *
		 * The hash used to be stamped here, before the generation it was meant
		 * to describe had run. That made it a promise rather than a record: if
		 * the cron run then failed (provider outage, exhausted quota, a 429, or
		 * WP-Cron simply never firing on the site), the post was left with a
		 * hash matching its current content and no summary, so every later save
		 * returned early right here and the post was never retried. The only
		 * escape was editing the content or generating by hand.
		 *
		 * The hash is now written by run_generation() and force_generate() when
		 * a summary actually exists, so it means what it says. The gate below
		 * therefore asks both questions: has the content changed, and do we
		 * have a summary for it. A post that failed answers no to the second
		 * and gets picked up on the next save.
		 */
		$content     = self::get_content_string( $post );
		$new_hash    = md5( $content );
		$old_hash    = (string) get_post_meta( $post_id, RNRD_META_HASH, true );
		$has_summary = '' !== (string) get_post_meta( $post_id, RNRD_META_SUMMARY, true );

		if ( $new_hash === $old_hash && $has_summary ) {
			return;
		}

		// Use WP-Cron only (single path, no double-fire).
		wp_clear_scheduled_hook( RNRD_CRON_HOOK, array( $post_id ) );
		wp_schedule_single_event( time() + 5, RNRD_CRON_HOOK, array( $post_id ) );
		self::mark_autogen_queued( 'summary', $post_id );
		spawn_cron();
	}

	/**
	 * Remember that an automatic run was queued, so the metabox can say so.
	 * WP-Cron drops the event the moment it starts, which leaves the seconds
	 * of the AI call itself invisible, so the queue time is kept for 3 minutes.
	 *
	 * @param string $kind summary|faq
	 */
	public static function mark_autogen_queued( string $kind, int $post_id ): void {
		delete_transient( 'rnrd_autogen_err_' . $kind . '_' . $post_id );
		set_transient( 'rnrd_autogen_' . $kind . '_' . $post_id, time(), 3 * MINUTE_IN_SECONDS );
	}

	/**
	 * Remember that an automatic run failed, and why, so the metabox can show
	 * the actual error instead of the "queued" notice quietly disappearing and
	 * leaving the author to wonder whether anything happened.
	 *
	 * Ends the queued state at once, and keeps the reason for 10 minutes: long
	 * enough to be seen on the next look at the post, short enough that it never
	 * outlives the moment it explains.
	 *
	 * @param string $kind   summary|faq
	 * @param string $detail Why it failed, already written for a person.
	 */
	public static function mark_autogen_failed( string $kind, int $post_id, string $detail ): void {
		delete_transient( 'rnrd_autogen_' . $kind . '_' . $post_id );
		$detail = trim( wp_strip_all_tags( $detail ) );

		/*
		 * Our own finished message first, recognised by its marker. This
		 * transient is printed straight into the metabox, so the marker is
		 * user-visible from here, and these messages are written to END in the
		 * remedy, which is exactly the half a 300-character cut removes. The
		 * truncation notice is 394 characters: the user was shown
		 * "[rnrd:ready] The model ran out of output length ... leaves more r"
		 * and never got to "a developer can raise the ceiling". RNRD_Rest
		 * stripped the marker on its own path, which is why this went unseen
		 * on the one path that has no REST response to strip it.
		 */
		$ready = class_exists( 'RNRD_LLM' ) ? RNRD_LLM::friendly_error_text( $detail ) : '';
		if ( '' !== $ready ) {
			set_transient( 'rnrd_autogen_err_' . $kind . '_' . $post_id, $ready, 10 * MINUTE_IN_SECONDS );
			return;
		}

		// Providers answer errors with a JSON body ("DeepSeek HTTP 401: {"error":
		// {"message":"..."}}"). A person should read the message, not the braces.
		if ( preg_match( '/^(.*?HTTP\s+\d{3}):\s*(\{.*\})\s*$/s', $detail, $m ) ) {
			$body   = json_decode( $m[2], true );
			$reason = '';
			if ( is_array( $body ) ) {
				if ( isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
					$reason = $body['error']['message'];
				} elseif ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
					$reason = $body['error'];
				} elseif ( isset( $body['message'] ) && is_string( $body['message'] ) ) {
					$reason = $body['message'];
				}
			}
			$detail = '' !== trim( $reason ) ? $m[1] . ': ' . trim( $reason ) : $m[1];
		}
		if ( '' === $detail ) {
			$detail = __( 'The AI provider did not return a usable answer. Try again in a moment.', 'rankready-ai-llm-seo' );
		}
		// A raw provider blob still gets a hard cap, on a word boundary so it
		// does not end mid-word. Only our own messages above are kept whole,
		// because only those have a known author and a known length.
		if ( mb_strlen( $detail ) > 300 ) {
			$clipped = mb_substr( $detail, 0, 300 );
			$space   = mb_strrpos( $clipped, ' ' );
			$detail  = ( false !== $space && $space > 200 ) ? mb_substr( $clipped, 0, $space ) : $clipped;
		}

		set_transient( 'rnrd_autogen_err_' . $kind . '_' . $post_id, $detail, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * The sentence the metabox shows when the last automatic run failed, or an
	 * empty string when it did not (or a newer run has been queued since).
	 *
	 * @param string $kind summary|faq
	 */
	public static function autogen_error( string $kind, int $post_id ): string {
		$detail = get_transient( 'rnrd_autogen_err_' . $kind . '_' . $post_id );
		if ( ! is_string( $detail ) || '' === $detail ) {
			return '';
		}
		return sprintf(
			/* translators: %s: the reason, e.g. "DeepSeek returned an unexpected response. Try again in a moment." */
			__( 'Auto-generation failed: %s', 'rankready-ai-llm-seo' ),
			$detail
		);
	}

	/**
	 * Whether an automatic Summary or FAQ run is queued or in flight for a post.
	 * Ends as soon as the generated timestamp passes the queue time, so a
	 * finished run never leaves a stale notice behind.
	 *
	 * @param string $kind summary|faq
	 */
	public static function autogen_pending( string $kind, int $post_id ): bool {
		$hook = 'faq' === $kind ? 'rnrd_async_faq_generate' : RNRD_CRON_HOOK;
		$done = (int) get_post_meta( $post_id, 'faq' === $kind ? RNRD_META_FAQ_GENERATED : RNRD_META_GENERATED, true );
		$queued = (int) get_transient( 'rnrd_autogen_' . $kind . '_' . $post_id );
		if ( $queued > 0 && $done >= $queued ) {
			return false;
		}
		return false !== wp_next_scheduled( $hook, array( $post_id ) ) || $queued > 0;
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

		// Same per-post lock as the REST summary endpoints, taken before the
		// interval check so a cron run and a click cannot both pass it.
		$lock_name  = 'rnrd_lk_sum_' . $post_id;
		$lock_token = null;
		if ( ! RNRD_Atomic::acquire_lock( $lock_name, 180, $lock_token ) ) {
			self::$generating = false;
			return;
		}

		$last = (int) get_post_meta( $post_id, RNRD_META_GENERATED, true );
		if ( $last && ( time() - $last ) < self::MIN_INTERVAL ) {
			RNRD_Atomic::release_lock( $lock_name, $lock_token );
			self::$generating = false;
			return;
		}

		try {
			$content = self::get_content_string( $post );
			// A body that could not be read must not reach the model: its answer
			// would replace a good stored summary. Nothing is written, so
			// maybe_schedule() tries this post again once the body is readable.
			if ( self::is_thin_content( $content ) ) {
				self::$last_error = self::thin_content_message();
				$result           = false;
			} else {
				$result = self::call_openai( $content, $post );
			}
		} finally {
			RNRD_Atomic::release_lock( $lock_name, $lock_token );
		}

		if ( $result && ! is_wp_error( $result ) ) {
			// The hash is stamped here, not at schedule time, and it records the
			// content this summary was actually made from — which is the content
			// as of now, not as of when the event was queued. A failure writes
			// nothing, so maybe_schedule() will try this post again.
			update_post_meta( $post_id, RNRD_META_SUMMARY,   wp_slash( $result ) ); // wp_slash: core unslashes meta, which would strip the escapes out of the stored JSON.
			update_post_meta( $post_id, RNRD_META_HASH,      md5( $content ) );
			update_post_meta( $post_id, RNRD_META_GENERATED, time() );
			delete_transient( 'rnrd_autogen_err_summary_' . $post_id );
			// Neither path fires save_post, so /post.md would keep serving the old summary.
			if ( class_exists( 'RNRD_Rest' ) ) {
				RNRD_Rest::purge_derived_output( (int) $post_id );
			}
		} else {
			self::mark_autogen_failed( 'summary', $post_id, self::$last_error );
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

		// Same bar as the manual endpoint. Without it a body that failed to
		// resolve (builder meta that did not render, a shortcode that returned
		// nothing) sent the title alone to the model and the answer replaced a
		// good stored summary. Bulk and Start Over both come through here.
		if ( self::is_thin_content( $content ) ) {
			self::$last_error = self::thin_content_message();
			self::log_error( 'Generator', self::$last_error, $post_id );
			return false;
		}

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
			update_post_meta( $post_id, RNRD_META_SUMMARY,   wp_slash( $result ) ); // wp_slash: core unslashes meta, which would strip the escapes out of the stored JSON.
			update_post_meta( $post_id, RNRD_META_HASH,      $new_hash );
			update_post_meta( $post_id, RNRD_META_GENERATED, time() );
			if ( class_exists( 'RNRD_Rest' ) ) {
				RNRD_Rest::purge_derived_output( (int) $post_id );
			}
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

	/**
	 * Why the most recent call_openai() returned false, written for a person.
	 * Read by run_generation() so a failed automatic run can say what happened.
	 *
	 * @var string
	 */
	private static $last_error = '';

	public static function call_openai( $content, $post, $api_key = '' ) {
		self::$last_error = '';
		$post             = get_post( $post );
		if ( ! $post ) {
			return false;
		}
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
		if ( class_exists( 'RNRD_Brand_Identity' ) ) {
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
		if ( class_exists( 'RNRD_Brand_Identity' ) ) {
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
			// See RNRD_LLM::output_budget(): 500 truncated about half of all
			// summaries once the resolver moved users onto a reasoning model.
			'max_tokens'  => RNRD_LLM::output_budget( 2500, 'summary' ),
			'temperature' => 0.2,
			'json'        => true,
			'timeout'     => 25,
		) );

		$source_label = strtoupper( (string) ( $result['provider'] ?? 'unknown' ) );

		if ( empty( $result['ok'] ) ) {
			self::log_error( $source_label, (string) $result['error'], $post->ID );
			self::$last_error = (string) $result['error'];
			return false;
		}

		// Track token usage (combined in/out for back-compat with existing
		// totals widget; cost stays accurate per provider).
		self::track_tokens( (int) $result['tokens_total'], $post->ID, 'summary' );

		$raw = (string) $result['content'];
		if ( '' === $raw ) {
			self::log_error( $source_label, 'Empty content from provider.', $post->ID );
			self::$last_error = self::unexpected_response_text( (string) ( $result['provider'] ?? '' ) );
			return false;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['bullets'] ) || ! is_array( $decoded['bullets'] ) ) {
			self::log_error( $source_label, 'Unexpected JSON structure: ' . mb_substr( $raw, 0, 200 ), $post->ID );
			self::$last_error = self::unexpected_response_text( (string) ( $result['provider'] ?? '' ) );
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

	/**
	 * "DeepSeek returned an unexpected response. Try again in a moment."
	 *
	 * @param string $provider Provider slug; falls back to a generic name.
	 */
	private static function unexpected_response_text( string $provider ): string {
		$label = '' !== $provider ? RNRD_LLM::get_provider_label( $provider ) : __( 'The AI provider', 'rankready-ai-llm-seo' );
		return sprintf(
			/* translators: %s: AI provider name, e.g. DeepSeek. */
			__( '%s returned an unexpected response. Try again in a moment.', 'rankready-ai-llm-seo' ),
			$label
		);
	}

	// ── Connection test ───────────────────────────────────────────────────────

	/**
	 * Connection test — pings the active LLM provider with a tiny prompt.
	 * Returns true on success, or a string error message on failure.
	 *
	 * Routes through `RNRD_LLM::generate()` so adding a new provider needs no
	 * changes here.
	 *
	 * No caller inside the plugin as of 1.4.0: the admin's own connection test
	 * is the `/verify-key` REST route, and the dead notice that used to call
	 * this was removed. Kept because it is a `public static` on a class other
	 * plugins can reach, and removing a public method is a breaking change for
	 * anything that does.
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
				// 5 is enough for "OK" on the 4.x line and was measured fine on
				// Haiku 5.5 too, but a model that reasons first would spend the
				// whole budget thinking and report a working key as broken. The
				// ceiling costs nothing when the answer really is two letters.
				'max_tokens'  => RNRD_LLM::output_budget( 256, 'ping' ),
				'temperature' => 0.0,
				'json'        => false,
				'timeout'     => 10,
			)
		);

		return ! empty( $result['ok'] ) ? true : (string) $result['error'];
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public static function is_thin_content( string $content ): bool {
		$words = preg_match_all( '/\S+/', $content );
		return ( false === $words ? 0 : $words ) < self::MIN_WORDS;
	}

	private static function thin_content_message(): string {
		return __( 'Not enough readable content to summarise. The existing summary was left unchanged.', 'rankready-ai-llm-seo' );
	}

	public static function get_content_string( $post ): string {
		// issue #9 — page builders (Elementor etc.) leave post_content empty or
		// near-empty, which used to feed the LLM an empty/near-empty string for
		// a real, published page. Resolve through resolve_post_content() first.
		$raw_content = ( $post instanceof WP_Post ) ? self::resolve_post_content( $post ) : (string) $post->post_content;

		// strip_shortcodes() avoids side-effects from WooCommerce / form / cron-unsafe
		// shortcodes when this runs under WP-Cron. We only need the raw text for the LLM.
		$body = wp_strip_all_tags( strip_shortcodes( $raw_content ) );
		return $post->post_title . "\n\n" . $body;
	}

	/**
	 * Resolve the real rendered body of a post, for any caller that needs the
	 * ACTUAL content rather than the raw `post_content` column.
	 *
	 * issue #9 — page builders (Elementor, and anything else that stores its
	 * body in postmeta instead of `post_content`) leave `post_content` empty or
	 * a near-empty placeholder even on a fully rendered, published page. Every
	 * content path that read `$post->post_content` directly therefore silently
	 * produced an empty AI summary, an empty llms.txt/llms-full.txt entry, or an
	 * empty .md file for a page that has real content on the frontend.
	 *
	 * Resolution order, cheapest and most reliable first:
	 *   1. `post_content` itself, when it is real text — length is checked
	 *      AFTER stripping tags/shortcodes so a lone `[elementor-template]`-style
	 *      shortcode wrapper (which page builders sometimes leave behind) does
	 *      not count as "real" content.
	 *   2. Elementor's own render pipeline, when Elementor is active and the
	 *      post carries Elementor data — the only reliable way to read a
	 *      builder page's actual body without booting the full frontend
	 *      template. Fully guarded (class/property/method existence + try/catch)
	 *      since this has not been exercised against a live Elementor install in
	 *      this environment; any failure falls straight through to step 3.
	 *   3. The `the_content` filter chain as a last-resort fallback — covers
	 *      every other page builder (Bricks, Oxygen, Divi, Beaver Builder,
	 *      WPBakery) that hooks `the_content` rather than storing its own meta.
	 *      Run defensively: buffered output, and the `$post` global plus loop
	 *      state are saved and restored so a filter that depends on (or
	 *      echoes via) the global query loop cannot leak state into the caller.
	 *
	 * @param WP_Post $post
	 * @return string Resolved HTML/text body, or '' if nothing could be resolved.
	 */
	public static function resolve_post_content( WP_Post $post ): string {
		// 1. Raw post_content, if it is actually real text.
		$raw      = (string) $post->post_content;
		$stripped = trim( wp_strip_all_tags( strip_shortcodes( $raw ) ) );
		if ( mb_strlen( $stripped ) > 20 ) {
			return $raw;
		}

		// 2. Elementor fast path.
		if ( class_exists( '\Elementor\Plugin' ) && '' !== (string) get_post_meta( $post->ID, '_elementor_data', true ) ) {
			try {
				$instance = \Elementor\Plugin::$instance;
				if ( $instance && isset( $instance->frontend ) && method_exists( $instance->frontend, 'get_builder_content_for_display' ) ) {
					$rendered = (string) $instance->frontend->get_builder_content_for_display( $post->ID );
					if ( mb_strlen( trim( wp_strip_all_tags( $rendered ) ) ) > 20 ) {
						return $rendered;
					}
				}
			} catch ( \Throwable $e ) {
				// Fall through to the the_content fallback below.
			}
		}

		// 3. Defensive the_content fallback.
		$prev_global_post = $GLOBALS['post'] ?? null;
		$GLOBALS['post']  = $post;
		setup_postdata( $post );

		ob_start();
		$filtered = apply_filters( 'the_content', $raw );
		$echoed   = ob_get_clean();

		wp_reset_postdata();
		if ( null !== $prev_global_post ) {
			$GLOBALS['post'] = $prev_global_post;
		} else {
			unset( $GLOBALS['post'] );
		}

		if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
			return $filtered;
		}
		return is_string( $echoed ) ? $echoed : '';
	}

	/** Max bytes of server-rendered block output accepted into Markdown. */
	const BLOCK_RENDER_MAX_BYTES = 524288;

	/** Max nested block-render depth (a block that renders another post's blocks). */
	const BLOCK_RENDER_MAX_DEPTH = 3;

	/** @var int[] Post IDs currently being block-rendered (re-entrancy guard). */
	private static $block_render_active = array();

	/**
	 * Does this raw post_content contain at least one server-rendered (dynamic)
	 * block? Static blocks already survive comment stripping, so only dynamic
	 * ones (core/latest-posts, core/block reusable refs, ACF blocks, ...) need
	 * rendering. Guarded: any registry/parser problem returns false.
	 *
	 * @param string $content Raw post_content.
	 * @return bool
	 */
	public static function has_dynamic_blocks( string $content ): bool {
		if ( '' === $content || false === strpos( $content, '<!-- wp:' ) ) {
			return false;
		}
		if ( ! function_exists( 'parse_blocks' ) || ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return false;
		}
		try {
			$registry = WP_Block_Type_Registry::get_instance();
			$stack    = parse_blocks( $content );
			while ( $stack ) {
				$block = array_pop( $stack );
				if ( ! is_array( $block ) ) {
					continue;
				}
				$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
				if ( '' !== $name ) {
					$type = $registry->get_registered( $name );
					if ( $type && method_exists( $type, 'is_dynamic' ) && $type->is_dynamic() ) {
						return true;
					}
				}
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					foreach ( $block['innerBlocks'] as $inner ) {
						$stack[] = $inner;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return false;
	}

	/**
	 * Render server-side (dynamic) blocks in raw post_content so they reach the
	 * Markdown instead of being deleted with the block comments.
	 *
	 * Safe by construction: returns $raw unchanged on any problem (no dynamic
	 * blocks, re-entrancy, depth cap, oversize or empty output, Throwable), so
	 * the result can only GAIN content relative to the old raw path. Runs
	 * do_blocks() only; shortcodes are NOT run here (the caller does that once),
	 * so there are no double shortcode side effects. Preserves the global $post.
	 *
	 * @since 1.4.1
	 * @param WP_Post $post Post being converted.
	 * @param string  $raw  Its raw post_content.
	 * @return string Rendered HTML, or $raw.
	 */
	public static function render_dynamic_blocks( WP_Post $post, string $raw ): string {
		if ( ! function_exists( 'do_blocks' ) || ! self::has_dynamic_blocks( $raw ) ) {
			return $raw;
		}
		$id = (int) $post->ID;
		if ( in_array( $id, self::$block_render_active, true ) || count( self::$block_render_active ) >= self::BLOCK_RENDER_MAX_DEPTH ) {
			return $raw;
		}

		self::$block_render_active[] = $id;
		$had_post  = array_key_exists( 'post', $GLOBALS );
		$prev_post = $had_post ? $GLOBALS['post'] : null;
		$out       = '';
		$buffering = false;
		// The result is cached and served to anonymous crawlers, so render as a
		// logged-out visitor: blocks like Query Loop must not leak what the
		// current user (an editor opening /post.md) is allowed to see.
		$prev_user = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			$GLOBALS['post'] = $post;
			setup_postdata( $post );
			ob_start();
			$buffering = true;
			$out       = do_blocks( $raw );
			ob_end_clean();
			$buffering = false;
		} catch ( \Throwable $e ) {
			if ( $buffering ) {
				ob_end_clean();
			}
			$out = '';
		}
		array_pop( self::$block_render_active );
		wp_set_current_user( $prev_user );
		wp_reset_postdata();
		if ( $had_post ) {
			$GLOBALS['post'] = $prev_post;
		} else {
			unset( $GLOBALS['post'] );
		}

		if ( ! is_string( $out ) || '' === trim( wp_strip_all_tags( $out ) ) || strlen( $out ) > self::BLOCK_RENDER_MAX_BYTES ) {
			return $raw;
		}
		// Blocks may emit inline script/style; neither belongs in Markdown.
		$clean = preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#si', '', $out );
		return ( is_string( $clean ) && '' !== trim( wp_strip_all_tags( $clean ) ) ) ? $clean : $raw;
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
		$entry = array(
			'time'    => time(),
			'source'  => $source,
			'message' => mb_substr( $message, 0, 500 ),
			'post_id' => $post_id,
		);

		/*
		 * Locked, because this ring buffer is a read-modify-write and errors
		 * arrive in bursts rather than evenly: a bulk run that fails ten posts
		 * writes ten entries in a few seconds, which is exactly when two
		 * writers overlap and one entry is lost. Bulk summaries and bulk FAQ
		 * hold different locks and both call this, so neither of those
		 * critical sections excludes the other here.
		 *
		 * The lost entry is the one that matters. This log is the only place
		 * the plugin explains WHY a generation failed, and a provider outage
		 * is precisely the case that fails many posts at once, so the burst
		 * that loses entries is the burst somebody is about to read.
		 *
		 * The write is never skipped on a failed lock. A duplicated or
		 * clobbered line is recoverable; silence on an error path is not, and
		 * dropping the entry would trade a rare lost line for a guaranteed
		 * one. Three bounded attempts, matching remember_catalog(), put this
		 * read after the holder's write in every case except a holder that
		 * stalls 150ms between its own get and set.
		 */
		$token  = null;
		$locked = false;
		if ( class_exists( 'RNRD_Atomic' ) ) {
			// The lock is unfair: a writer inside a burst can retake it before a
			// waiter's next try, and on a loaded host a holder can stall. Three
			// tries (150ms) measurably let the waiter fall through and clobber an
			// entry, so wait up to about five seconds. The lock TTL is 10s and the
			// critical section is one get and one set, so this only ever costs
			// time under real contention.
			for ( $attempt = 0; $attempt < 100; $attempt++ ) {
				$locked = RNRD_Atomic::acquire_lock( 'rnrd_error_log_lock', 10, $token );
				if ( $locked ) {
					break;
				}
				usleep( 50000 );
			}
		}

		// The holder's write landed in the database, not in this request's
		// option cache, so the read has to go past it.
		wp_cache_delete( 'rnrd_error_log', 'options' );

		$log   = (array) get_option( 'rnrd_error_log', array() );
		$log[] = $entry;

		// Keep last 50 entries.
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, -50 );
		}

		update_option( 'rnrd_error_log', $log, false );

		if ( $locked ) {
			RNRD_Atomic::release_lock( 'rnrd_error_log_lock', $token );
		}
	}

	public static function get_error_log(): array {
		return (array) get_option( 'rnrd_error_log', array() );
	}

	public static function clear_error_log(): void {
		delete_option( 'rnrd_error_log' );
	}

	// ── Token tracking ───────────────────────────────────────────────────────

	/**
	 * Option names of the atomic token counters.
	 *
	 * These replace the three fields that used to live inside the single
	 * serialised `rnrd_token_usage` array. A serialised array cannot be
	 * incremented atomically: every write was
	 * `get_option() -> modify -> update_option()`, so bulk generation running
	 * beside the editor lost updates, and the figure this feeds is presented to
	 * the user as spend. `rnrd_token_usage` itself is a shipped option and is
	 * still READ by get_token_usage() below, so upgrades keep their history; it
	 * is simply never written again.
	 */
	const OPT_TOK_SUMMARY = 'rnrd_tok_summary';
	const OPT_TOK_FAQ     = 'rnrd_tok_faq';
	const OPT_TOK_CALLS   = 'rnrd_tok_calls';

	public static function track_tokens( int $tokens, int $post_id, string $type ): void {
		// Per-post tracking. Atomic: two generators can finish on the same post
		// (editor and bulk), and this project has already been bitten by a real
		// concurrent post-meta bug.
		RNRD_Atomic::bump_post_meta_int( $post_id, '_rnrd_tokens_used', $tokens );

		// Global cumulative tracking.
		RNRD_Atomic::bump_option_int(
			'summary' === $type ? self::OPT_TOK_SUMMARY : self::OPT_TOK_FAQ,
			$tokens
		);
		RNRD_Atomic::bump_option_int( self::OPT_TOK_CALLS, 1 );
	}

	/**
	 * Cumulative token usage, folding pre-1.4.0 history in.
	 *
	 * The legacy serialised option is read and added, never written, so a site
	 * that generated before the counters were split keeps its totals instead of
	 * appearing to reset to zero on update.
	 *
	 * @return array{summary_tokens:int,faq_tokens:int,total_calls:int}
	 */
	public static function get_token_usage(): array {
		$legacy = (array) get_option( 'rnrd_token_usage', array() );

		return array(
			'summary_tokens' => ( isset( $legacy['summary_tokens'] ) ? (int) $legacy['summary_tokens'] : 0 )
				+ RNRD_Atomic::read_int( self::OPT_TOK_SUMMARY ),
			'faq_tokens'     => ( isset( $legacy['faq_tokens'] ) ? (int) $legacy['faq_tokens'] : 0 )
				+ RNRD_Atomic::read_int( self::OPT_TOK_FAQ ),
			'total_calls'    => ( isset( $legacy['total_calls'] ) ? (int) $legacy['total_calls'] : 0 )
				+ RNRD_Atomic::read_int( self::OPT_TOK_CALLS ),
		);
	}
}
