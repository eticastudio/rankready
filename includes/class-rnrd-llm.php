<?php
/**
 * RankReady — LLM provider dispatcher.
 *
 * Single entry point for every AI call in the plugin (summary + FAQ + future
 * features). Dispatches to one of four providers based on the
 * `rnrd_llm_provider` option: OpenAI, Anthropic (Claude), Google (Gemini),
 * or DeepSeek.
 *
 * Every provider implementation follows the same contract:
 *
 *     ProviderClass::generate( string $system, string $user, array $opts ): array
 *     // Returns: [
 *     //   'ok'         => bool,
 *     //   'content'    => string,   // raw assistant content
 *     //   'tokens_in'  => int,
 *     //   'tokens_out' => int,
 *     //   'tokens_total' => int,
 *     //   'model'      => string,
 *     //   'provider'   => string,
 *     //   'error'      => string,   // empty when ok=true
 *     // ]
 *
 * Options (all optional):
 *   - max_tokens       (int)    Default: 500
 *   - temperature      (float)  Default: 0.2
 *   - json             (bool)   Request JSON-only output. Default: false
 *   - timeout          (int)    HTTP timeout in seconds. Default: 25
 *
 * @package RankReady
 */
defined( 'ABSPATH' ) || exit;

class RNRD_LLM {

	/** Provider IDs — keep in sync with the provider radio in admin. */
	const PROVIDER_OPENAI    = 'openai';
	const PROVIDER_ANTHROPIC = 'anthropic';
	const PROVIDER_GEMINI    = 'gemini';
	const PROVIDER_DEEPSEEK  = 'deepseek';

	/**
	 * Option: resolve the saved model to the newest in its line (default on).
	 * Additive and read at call time only, so an older plugin build that does
	 * not know this option still sends the concrete ID stored in wp_options.
	 */
	const OPT_AUTO_LATEST = 'rnrd_llm_auto_latest';

	/** Option: last successful /models result per provider (list + metadata). */
	const OPT_CATALOG = 'rnrd_llm_catalog';

	/**
	 * Lock guarding the read-modify-write in remember_catalog().
	 *
	 * Swept by uninstall.php with the rest of the rnrd_ options.
	 */
	const LOCK_CATALOG = 'rnrd_llm_catalog_lock';

	/**
	 * Returns the currently active provider ID, validated against the
	 * known list. Falls back to OpenAI if a stale/unknown value is stored.
	 */
	public static function get_active_provider(): string {
		$provider = (string) get_option( 'rnrd_llm_provider', self::PROVIDER_OPENAI );
		$valid    = array(
			self::PROVIDER_OPENAI,
			self::PROVIDER_ANTHROPIC,
			self::PROVIDER_GEMINI,
			self::PROVIDER_DEEPSEEK,
		);
		return in_array( $provider, $valid, true ) ? $provider : self::PROVIDER_OPENAI;
	}

	/**
	 * Returns the API key for a given provider. Empty string if not set.
	 */
	public static function get_api_key( string $provider ): string {
		switch ( $provider ) {
			case self::PROVIDER_ANTHROPIC: return (string) get_option( 'rnrd_anthropic_api_key', '' );
			case self::PROVIDER_GEMINI:    return (string) get_option( 'rnrd_gemini_api_key', '' );
			case self::PROVIDER_DEEPSEEK:  return (string) get_option( 'rnrd_deepseek_api_key', '' );
			case self::PROVIDER_OPENAI:
			default:                       return (string) get_option( RNRD_OPT_KEY, '' );
		}
	}

	/**
	 * Returns the active model for a given provider.
	 *
	 * Defaults are kept in sync with each provider's *current* recommended
	 * production ID as of the plugin release. Notes per provider:
	 *
	 * - OpenAI:    no evergreen alias. `gpt-5.4-mini` is the cheapest current
	 *              text model (the GPT-4o generation is now legacy → migrated).
	 * - Anthropic: every ID is pinned (Anthropic docs explicitly say so —
	 *              `claude-haiku-5-5` is a dateless pin, not an evergreen pointer.
 *              Verified against GET /models on 2026-10-20: the catalogue lists
 *              `claude-haiku-4-5-20251001` but NOT the bare `claude-haiku-4-5`
 *              this plugin used to default to, so the old default was an
 *              unlisted alias the dropdown could not even show).
	 * - Gemini:    `gemini-2.5-flash` is the stable production pick. Google docs
	 *              warn against `*-latest` aliases in production (hot-swap risk).
	 * - DeepSeek:  `deepseek-flash` is the canonical name for the model their
	 *              pricing page calls DeepSeek-V4.1-Flash; `deepseek-chat`,
	 *              `deepseek-reasoner` and the older `deepseek-v4-flash` alias
	 *              all route to it. Confirmed against GET /models 2026-10-20:
	 *              DeepSeek serves exactly two IDs, `deepseek-flash` and
	 *              `deepseek-v4-pro`, and sends no dates with either, which is
	 *              why self::resolve_latest() leaves a DeepSeek model alone.
	 *
	 * Retired IDs are routed to their current same-tier equivalent via
	 * self::migrate_model() — see self::model_migration_map() for the full list.
	 * The result then goes through self::resolve_latest(), which moves it to the
	 * newest model in the SAME line when the provider's own catalogue says
	 * there is one, so a new model does not need a plugin release to be used.
	 *
	 * If a user has an older saved value (e.g. `deepseek-chat`), it stays in
	 * `wp_options` and we still send it to the provider — the dropdown just
	 * stops listing it. When the user saves any change, sanitize_provider_model
	 * preserves whatever string they pick.
	 */
	public static function get_model( string $provider, string $mode = self::GET_MODEL_RESOLVED ): string {
		switch ( $provider ) {
			case self::PROVIDER_ANTHROPIC: $saved = (string) get_option( 'rnrd_anthropic_model', 'claude-haiku-5-5' ); break;
			case self::PROVIDER_GEMINI:    $saved = (string) get_option( 'rnrd_gemini_model',    'gemini-2.5-flash' ); break;
			case self::PROVIDER_DEEPSEEK:  $saved = (string) get_option( 'rnrd_deepseek_model',  'deepseek-flash' ); break;
			case self::PROVIDER_OPENAI:
			default:                       $saved = (string) get_option( RNRD_OPT_MODEL,         'gpt-5.4-mini' ); break;
		}
		// Route a retired ID to its tier-matched current equivalent at call-time
		// so the provider never receives a dead model — even before the one-shot
		// DB migration (RNRD_Admin::track_installed_version) has had a chance to
		// run. Side-effect-free; the DB rewrite + admin notice live in the admin.
		$model = self::migrate_model( $provider, $saved );

		if ( self::GET_MODEL_SAVED === $mode ) {
			return $model;
		}

		// Then move to the newest model in the SAME line when the provider's own
		// catalogue says there is one. The migration map above can only know the
		// models that existed when this release was cut; this does not need a
		// release. See resolve_latest() for the two limits on what it may do.
		return self::resolve_latest( $provider, $model );
	}

	/**
	 * The model the user actually picked, with retirement migration applied but
	 * WITHOUT auto-latest resolution.
	 *
	 * This is the value a settings form must render and post back, and it is a
	 * different question from self::get_model(), which answers "what do we send
	 * to the provider right now".
	 *
	 * Conflating the two destroys the user's choice on an ordinary save. The
	 * model control is a <select> whose name IS the real option, so whatever it
	 * renders as selected is what the next "Save AI" click writes to the
	 * database. Render the resolved ID there and auto-latest stops being a
	 * runtime resolution and becomes a one-way rewrite of the saved row: the
	 * pick is gone, unchecking the box pins the user to whatever the resolver
	 * last chose rather than to what they selected, and the screen contradicts
	 * its own "Turn it off to stay on exactly the model selected above".
	 * Nothing warns them, because every provider card is rendered and merely
	 * hidden with inline CSS, so one save posts all four.
	 */
	public static function get_saved_model( string $provider ): string {
		return self::get_model( $provider, self::GET_MODEL_SAVED );
	}

	/**
	 * Single source of truth for retired → current model migration.
	 *
	 * Every entry maps a retired/deprecated provider model ID to the CURRENT
	 * model in the SAME tier (Haiku→Haiku, Sonnet→Sonnet, Opus→Opus, mini→mini,
	 * flash→flash, pro→pro) — never cross-tier (a Sonnet user is never silently
	 * upgraded to Opus pricing). Used by both get_model() (call-time routing)
	 * and RNRD_Admin::track_installed_version() (one-shot DB rewrite + notice).
	 *
	 * To add a newly-retired model later: add one line here. Nothing else.
	 *
	 * @return array<string,array<string,string>> provider => [ old => new ]
	 */
	public static function model_migration_map(): array {
		return array(
			self::PROVIDER_OPENAI => array(
				'gpt-4o-mini'   => 'gpt-5.4-mini',
				'gpt-4.1-mini'  => 'gpt-5.4-mini',
				'gpt-3.5-turbo' => 'gpt-5.4-mini',
				'gpt-4o'        => 'gpt-5.4',
				'gpt-4.1'       => 'gpt-5.4',
				'gpt-4-turbo'   => 'gpt-5.4',
				'gpt-4'         => 'gpt-5.4',
			),
			/*
			 * Targets verified 2026-09-27 against Anthropic's own model
			 * overview, because the previous ones were invented: this map
			 * pointed the Sonnet tier at `claude-sonnet-4-6` and the Opus tier
			 * at `claude-opus-4-8`, and neither model has ever existed. The
			 * effect was the opposite of what a migration map is for. Someone
			 * running the real, working `claude-sonnet-4-5` had it silently
			 * rewritten to an ID the API answers with a 404, so generation
			 * broke on upgrade and the settings screen showed a model name
			 * nobody could look up. `claude-sonnet-4-6`, `claude-opus-4-7` and
			 * `claude-opus-4-8` are therefore SOURCES here now, not targets:
			 * 1.3.1 offered all three in its dropdown, so live installs have
			 * them saved and need routing forward like any other dead ID.
			 *
			 * Tier discipline still holds, and it is why Fable 5.1 is absent.
			 * It is the current top of Anthropic's range, but at $10/$50 per
			 * MTok against Opus 5.5's $4/$20 it is not the Opus tier, and
			 * moving someone onto it without asking would more than double
			 * their bill. It is offered in the dropdown as a choice instead.
			 */
			self::PROVIDER_ANTHROPIC => array(
				/*
				 * Haiku tier. The bare `claude-haiku-4-5` was the TARGET here
				 * and the plugin default until 1.4.2. It is a source now for
				 * one reason only: GET /models does not list it, so the API
				 * does not answer it and anyone holding it cannot generate.
				 *
				 * Do not read that as "nobody chose it". An earlier draft of
				 * this comment claimed so and it was wrong: 1.4.1's offline
				 * fallback list for Anthropic was array( 'claude-haiku-4-5',
				 * 'claude-sonnet-5', 'claude-opus-5-5', 'claude-fable-5-1' ),
				 * so a site that had not yet refreshed the live list did offer
				 * it in the dropdown and a user could have picked it. Chosen or
				 * inherited makes no difference here, because the ID is dead
				 * either way. It matters for the dated snapshot below, where it
				 * is the whole argument.
				 *
				 * The dated `claude-haiku-4-5-20251001` is deliberately NOT a
				 * source. It is Active, it is listed, it still answers, and it
				 * is pickable from the dropdown, so a user may well have
				 * chosen it. This map exists for IDs that have stopped or will
				 * stop working, and moving a working model the user selected
				 * would override the auto-latest opt-out, which promises the
				 * saved model is left alone. With auto-latest ON the resolver
				 * moves it to the newest Haiku anyway, which is the honest
				 * route: it is a preference, not a forced rewrite. If
				 * Anthropic publishes a deprecation for it, add it here then.
				 */
				'claude-3-5-haiku-20241022'  => 'claude-haiku-5-5',
				'claude-3-5-haiku-latest'    => 'claude-haiku-5-5',
				'claude-3-haiku-20240307'    => 'claude-haiku-5-5',
				'claude-haiku-4-5'           => 'claude-haiku-5-5',
				// Sonnet tier. Targets are 5.5, not 5: GET /models dates
				// claude-sonnet-5 to 2026-06-29 and claude-sonnet-5-5 to
				// 2026-09-28, and claude-sonnet-4-5-20250929 is already
				// Deprecated with retirement set for 2026-11-30.
				'claude-sonnet-4-20250514'   => 'claude-sonnet-5-5',
				'claude-sonnet-4-0'          => 'claude-sonnet-5-5',
				'claude-sonnet-4-5'          => 'claude-sonnet-5-5',
				'claude-sonnet-4-5-20250929' => 'claude-sonnet-5-5',
				'claude-sonnet-4-6'          => 'claude-sonnet-5-5',
				'claude-3-7-sonnet-20250219' => 'claude-sonnet-5-5',
				'claude-3-7-sonnet-latest'   => 'claude-sonnet-5-5',
				'claude-3-5-sonnet-20241022' => 'claude-sonnet-5-5',
				'claude-3-5-sonnet-latest'   => 'claude-sonnet-5-5',
				// Opus tier
				'claude-opus-4-20250514'     => 'claude-opus-5-5',
				'claude-opus-4-0'            => 'claude-opus-5-5',
				'claude-opus-4-1'            => 'claude-opus-5-5',
				'claude-opus-4-1-20250805'   => 'claude-opus-5-5',
				'claude-opus-4-5'            => 'claude-opus-5-5',
				'claude-opus-4-6'            => 'claude-opus-5-5',
				'claude-opus-4-7'            => 'claude-opus-5-5',
				'claude-opus-4-8'            => 'claude-opus-5-5',
				'claude-3-opus-20240229'     => 'claude-opus-5-5',
				'claude-3-opus-latest'       => 'claude-opus-5-5',
			),
			self::PROVIDER_GEMINI => array(
				'gemini-1.5-flash'        => 'gemini-2.5-flash',
				'gemini-1.5-flash-latest' => 'gemini-2.5-flash',
				'gemini-2.0-flash'        => 'gemini-2.5-flash',
				'gemini-2.0-flash-001'    => 'gemini-2.5-flash',
				'gemini-2.5-flash-lite'   => 'gemini-3.1-flash-lite',
				'gemini-1.5-pro'          => 'gemini-2.5-pro',
				'gemini-1.5-pro-latest'   => 'gemini-2.5-pro',
			),
			/*
			 * `deepseek-v4-flash` is still accepted by DeepSeek but their docs
			 * now call it a legacy name served by DeepSeek-V4.1-Flash, so the
			 * canonical ID is `deepseek-flash`. Nothing breaks either way; this
			 * keeps the dropdown showing the name their pricing page shows.
			 */
			self::PROVIDER_DEEPSEEK => array(
				'deepseek-chat'     => 'deepseek-flash',
				'deepseek-v4-flash' => 'deepseek-flash',
				'deepseek-reasoner' => 'deepseek-v4-pro',
			),
		);
	}

	/**
	 * Resolve a (possibly retired) model ID to its current equivalent for a
	 * provider. Returns the input unchanged when it is already current or not
	 * in the migration map. Pure function — no DB writes, no side effects.
	 */
	public static function migrate_model( string $provider, string $model ): string {
		$map = self::model_migration_map();
		return isset( $map[ $provider ][ $model ] ) ? $map[ $provider ][ $model ] : $model;
	}

	/**
	 * Dynamic model resolution: always use the newest model in the line the
	 * user picked, without waiting for a plugin update.
	 *
	 * Why this exists. Every model ID in this file used to be a literal, and
	 * literals rot on the provider's schedule, not ours. The 1.4.1 cycle made
	 * the cost concrete: the Anthropic client sent `temperature` to a model
	 * generation that rejects it, and the only way for a site to get a working
	 * model was a plugin release. The provider /models endpoints already carry
	 * everything needed to decide this at runtime:
	 *
	 * - Anthropic returns `line` (haiku / sonnet / opus / fable), `created_at`,
	 *   `lifecycle`, `deprecated_at` and `retires_at` per model.
	 * - OpenAI returns `created` and `shutdown_date`.
	 * - DeepSeek and Gemini return neither date; those fall back to the version
	 *   number parsed out of the ID, and when that is ambiguous the saved model
	 *   is kept. Thin metadata degrades to "change nothing", never to a guess.
	 *
	 * Two hard limits on what this is allowed to do:
	 *
	 * 1. It never crosses a line. A Haiku user is never moved to Sonnet, a
	 *    `mini` user never to a full model, because the lines are priced
	 *    differently and nobody should find a bigger bill in the morning. The
	 *    line is the provider's own `line` field where there is one, and the
	 *    suffix of the model ID where there is not.
	 * 2. It never picks a model the provider has flagged as deprecated or
	 *    retiring. Moving onto a model with a retirement date would just queue
	 *    the same breakage up again.
	 *
	 * It is also read-only and network-free. get_model() runs on the front end
	 * during generation; the catalogue it reads here is whatever the last
	 * successful /models fetch stored (transient first, then the persisted
	 * `rnrd_llm_catalog` option so this keeps working after the transient
	 * expires). No key, no cache and no metadata all mean the same thing: hand
	 * back the saved ID untouched.
	 */

	/** Mode flags for self::get_model(). */
	const GET_MODEL_RESOLVED = 'resolved';
	const GET_MODEL_SAVED    = 'saved';

	/**
	 * True when the saved model should resolve to the newest in its line.
	 * Default on; the Settings → API Keys checkbox turns it off to pin a model.
	 */
	public static function auto_latest_enabled(): bool {
		return '1' === (string) get_option( self::OPT_AUTO_LATEST, '1' );
	}

	/**
	 * The line (price/size family) a model ID belongs to.
	 *
	 * Provider metadata wins when it carries a line; otherwise this is derived
	 * from the ID, which is why the tokens below are matched longest-first
	 * (`flash-lite` before `flash`, or every Gemini Flash Lite would be read as
	 * plain Flash and the two are not the same price).
	 */
	public static function model_line( string $provider, string $model ): string {
		$model = strtolower( trim( $model ) );
		if ( '' === $model ) {
			return '';
		}

		$meta = self::get_model_meta( $provider );
		if ( isset( $meta[ $model ]['line'] ) && '' !== $meta[ $model ]['line'] ) {
			return (string) $meta[ $model ]['line'];
		}

		switch ( $provider ) {
			case self::PROVIDER_ANTHROPIC:
				foreach ( array( 'haiku', 'sonnet', 'opus', 'fable' ) as $line ) {
					if ( false !== strpos( $model, $line ) ) {
						return $line;
					}
				}
				return 'claude';

			case self::PROVIDER_GEMINI:
				foreach ( array( 'flash-lite', 'flash', 'pro' ) as $line ) {
					if ( false !== strpos( $model, $line ) ) {
						return $line;
					}
				}
				return 'gemini';

			case self::PROVIDER_DEEPSEEK:
				foreach ( array( 'flash', 'pro', 'reasoner' ) as $line ) {
					if ( false !== strpos( $model, $line ) ) {
						return $line;
					}
				}
				return 'deepseek';

			case self::PROVIDER_OPENAI:
			default:
				/*
				 * OpenAI has no line field, and its IDs carry two different
				 * kinds of suffix: price tiers (`mini`, `nano`, `pro`) and
				 * named variants (`gpt-5.6-luna`, `gpt-6-astra`). Both are
				 * treated as their own line on purpose. Collapsing the named
				 * variants into one "standard" line would let a site on
				 * `gpt-5.4` wake up on `gpt-6-astra`, a different model with
				 * different output and different pricing, which is exactly the
				 * surprise rule 1 above exists to prevent. Keeping them
				 * separate costs nothing: a variant with no newer sibling just
				 * resolves to itself.
				 */
				$rest = preg_replace( '/^gpt-[0-9]+(?:\.[0-9]+)*-?/', '', $model );
				$rest = trim( (string) $rest, '-' );
				return '' === $rest ? 'base' : $rest;
		}
	}

	/**
	 * Comparable version number parsed from a model ID.
	 *
	 * `claude-haiku-4-5` and `gpt-5.4-mini` both yield 4.5 and 5.4. An 8-digit
	 * date stamp (`claude-sonnet-4-5-20250929`) is never read as a
	 * twenty-million-point version, which is what a naive digit grab does: the
	 * lookarounds below bound a run to 1 to 3 digits with no digit on either
	 * side, so no part of an 8-digit run can match and the stamp contributes
	 * nothing. There used to be an `8 === strlen( $major )` guard here as
	 * well, which could never fire for that same reason; it is gone rather
	 * than left to imply the regex allows what it does not.
	 * Returns 0.0 when there is no version in the ID at all, so a dateless ID
	 * never outranks a real one.
	 */
	public static function model_version( string $model ): float {
		$model = strtolower( $model );

		/*
		 * The FIRST version-like run wins, which is why this reads one match
		 * rather than looping. It used to collect every match and return inside
		 * the first pass of a foreach, with an unreachable `return 0.0;` after
		 * it: both branches of that loop returned, so it could never reach a
		 * second set, and the trailing return could never run. That is the same
		 * dead-guard shape the note above says was removed from this function,
		 * so it is written out here instead of left to imply the loop has a
		 * second iteration to give. Behaviour is unchanged: measured identical
		 * across 30 IDs, date stamps and all-digit strings included.
		 */
		if ( ! preg_match( '/(?<![0-9])([0-9]{1,3})(?:[.-]([0-9]{1,3}))?(?![0-9])/', $model, $m ) ) {
			return 0.0;
		}

		$major = (string) $m[1];
		$minor = isset( $m[2] ) ? (string) $m[2] : '';

		return '' === $minor ? (float) $major : (float) ( $major . '.' . $minor );
	}

	/**
	 * Cached model metadata for a provider: id => [line, created, retiring].
	 * Transient first, then the persisted catalogue. Never hits the network.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_model_meta( string $provider ): array {
		$cached = get_transient( self::model_meta_cache_key( $provider ) );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$catalog = get_option( self::OPT_CATALOG, array() );
		if ( is_array( $catalog ) && ! empty( $catalog[ $provider ]['meta'] ) && is_array( $catalog[ $provider ]['meta'] ) ) {
			return $catalog[ $provider ]['meta'];
		}

		return array();
	}

	/**
	 * Every model ID the plugin currently believes a provider offers, from the
	 * same two read-only sources as get_model_meta(). Used so a line can be
	 * resolved even for a provider whose /models payload carries no metadata
	 * beyond the IDs themselves.
	 *
	 * @return string[]
	 */
	private static function known_model_ids( string $provider ): array {
		$meta = self::get_model_meta( $provider );
		if ( ! empty( $meta ) ) {
			return array_keys( $meta );
		}

		$cached = get_transient( self::models_cache_key( $provider ) );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return array_keys( $cached );
		}

		$catalog = get_option( self::OPT_CATALOG, array() );
		if ( is_array( $catalog ) && ! empty( $catalog[ $provider ]['models'] ) && is_array( $catalog[ $provider ]['models'] ) ) {
			return array_keys( $catalog[ $provider ]['models'] );
		}

		return array();
	}

	/**
	 * The newest usable model in a line, or '' when nothing is known about it.
	 *
	 * Ordering prefers the provider's own creation date and falls back to the
	 * version in the ID. Models the provider flagged as deprecated or retiring
	 * are not candidates.
	 */
	public static function latest_in_line( string $provider, string $line ): string {
		$line = strtolower( trim( $line ) );
		if ( '' === $line ) {
			return '';
		}

		$meta = self::get_model_meta( $provider );

		/*
		 * Two accumulators, not one, because a single comparison cannot mix
		 * the two keys without changing which key it compares on partway
		 * through the loop. The pass this replaces compared by date when both
		 * sides carried one and by version otherwise, so as soon as the
		 * running best had no date, a dated candidate was judged on the number
		 * scraped out of its ID: an undated ID with a higher version beat a
		 * model the provider had actually stamped, which is the opposite of
		 * what its own comment claimed. Measured over 200,000 random candidate
		 * sets, this version is identical on all 23,726 all-dated sets and all
		 * 23,364 all-undated sets, and differs on 61.65 percent of mixed sets.
		 *
		 * Those two uniform cases are the only ones the four providers can
		 * produce today, because extract_model_meta() stamps every row of a
		 * payload or none of them, so the old shape was latent rather than
		 * something a site could hit. It is fixed anyway: the tier order is
		 * what the docblock promises, and a provider that starts stamping some
		 * rows and not others would otherwise invert it in silence.
		 */
		$best_dated   = '';
		$best_created = -1.0;
		$best_undated = '';
		$best_version = -1.0;

		/*
		 * Candidates are filtered through the SAME rules as the dropdown, and
		 * that is a correctness requirement, not tidiness. known_model_ids()
		 * prefers the raw /models metadata, which carries every row the
		 * provider publishes: image, TTS, transcription, embedding and
		 * robotics variants included. model_line() buckets on a substring, so
		 * `gemini-<n>-pro-image-preview` lands in the `pro` line beside the
		 * text model a user actually chose, and one provider release in which
		 * an image variant ships on the next version number before the text
		 * model would resolve a working site onto a model that cannot answer a
		 * text request. provider_model_rules() is this plugin's own statement
		 * that those IDs must never be offered; the resolver has to honour it
		 * or auto-latest can hand a site a model it was never allowed to pick.
		 */
		$selectable = self::filter_model_ids( self::known_model_ids( $provider ), self::provider_model_rules( $provider ) );

		foreach ( $selectable as $id ) {
			$id = (string) $id;
			if ( self::model_line( $provider, $id ) !== $line ) {
				continue;
			}
			if ( ! empty( $meta[ $id ]['retiring'] ) ) {
				continue;
			}

			$created = isset( $meta[ $id ]['created'] ) ? (float) $meta[ $id ]['created'] : 0.0;

			// A provider that stamps dates is trusted over a number scraped
			// out of a string, so a dated candidate is only ever compared with
			// other dated candidates.
			if ( $created > 0.0 ) {
				if ( $created > $best_created ) {
					$best_dated   = $id;
					$best_created = $created;
				}
				continue;
			}

			$version = self::model_version( $id );
			if ( $version > $best_version ) {
				$best_undated = $id;
				$best_version = $version;
			}
		}

		/*
		 * The undated tier answers only when the line has no dated candidate
		 * at all. A retiring model never reaches either accumulator, so a line
		 * whose only dated model is retiring still falls through to the
		 * version-ranked tier rather than returning ''. A tie inside a tier
		 * keeps the first candidate seen, which is the lowest ID, because
		 * filter_model_ids() ksorts what it hands back.
		 */
		return '' !== $best_dated ? $best_dated : $best_undated;
	}

	/**
	 * Resolve a concrete model ID to the newest one in its line.
	 *
	 * Returns the input unchanged when auto-latest is off, when nothing is
	 * known about the provider's catalogue, or when the saved model is already
	 * the newest in its line. A saved model the provider has flagged as
	 * retiring is moved even when it still works, because that flag is the
	 * provider saying it will stop working.
	 */
	public static function resolve_latest( string $provider, string $model ): string {
		$model = trim( $model );
		if ( '' === $model || ! self::auto_latest_enabled() ) {
			return $model;
		}

		/*
		 * extract_model_meta() keys by strtolower( $id ) and model_line()
		 * lowercases internally, so a saved ID carrying any uppercase would
		 * miss its own `retiring` flag and its own `created` date here and
		 * drop silently into the version-only branch: a retiring model would
		 * not be moved. Match the keys the metadata actually uses.
		 */
		$model = strtolower( $model );

		$latest = self::latest_in_line( $provider, self::model_line( $provider, $model ) );
		if ( '' === $latest || $latest === $model ) {
			return $model;
		}

		$meta = self::get_model_meta( $provider );

		// The saved model is on its way out: take the replacement.
		if ( ! empty( $meta[ $model ]['retiring'] ) ) {
			return $latest;
		}

		$saved_created  = isset( $meta[ $model ]['created'] ) ? (float) $meta[ $model ]['created'] : 0.0;
		$latest_created = isset( $meta[ $latest ]['created'] ) ? (float) $meta[ $latest ]['created'] : 0.0;

		if ( $saved_created > 0.0 && $latest_created > 0.0 ) {
			return $latest_created > $saved_created ? $latest : $model;
		}

		/*
		 * No dates to compare. Only move on a version the ID itself proves is
		 * newer. This is the DeepSeek case: `deepseek-flash` and
		 * `deepseek-v4-pro` carry no dates and one carries no version either,
		 * so the honest answer is to leave the saved model alone.
		 */
		return self::model_version( $latest ) > self::model_version( $model ) ? $latest : $model;
	}

	/**
	 * Transient key for cached per-model metadata, keyed like the model list
	 * so a key change invalidates both together.
	 */
	private static function model_meta_cache_key( string $provider, string $key = '' ): string {
		if ( '' === $key ) {
			$key = trim( self::get_api_key( $provider ) );
		}
		$fingerprint = '' !== $key ? substr( hash( 'sha256', $key ), 0, 16 ) : 'none';
		return 'rnrd_modelmeta_v1_' . $provider . '_' . $fingerprint;
	}

	/**
	 * Pull per-model metadata out of a /models payload.
	 *
	 * `retiring` is deliberately one boolean rather than the raw dates: every
	 * provider spells retirement differently (Anthropic `deprecated_at` /
	 * `retires_at` / `lifecycle`, OpenAI `shutdown_date`) and the only question
	 * the resolver asks is "may I move someone onto this".
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function extract_model_meta( string $provider, array $json ): array {
		$out  = array();
		$rows = array();

		if ( self::PROVIDER_GEMINI === $provider ) {
			$rows = isset( $json['models'] ) && is_array( $json['models'] ) ? $json['models'] : array();
		} elseif ( isset( $json['data'] ) && is_array( $json['data'] ) ) {
			$rows = $json['data'];
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id = '';
			if ( ! empty( $row['id'] ) ) {
				$id = (string) $row['id'];
			} elseif ( ! empty( $row['name'] ) ) {
				$id = str_replace( 'models/', '', (string) $row['name'] );
			}
			if ( '' === $id ) {
				continue;
			}

			$created = 0.0;
			if ( isset( $row['created'] ) && is_numeric( $row['created'] ) ) {
				$created = (float) $row['created'];
			} elseif ( ! empty( $row['created_at'] ) ) {
				$ts = strtotime( (string) $row['created_at'] );
				if ( false !== $ts ) {
					$created = (float) $ts;
				}
			}

			$retiring = false;
			foreach ( array( 'deprecated_at', 'retires_at', 'shutdown_date' ) as $field ) {
				if ( ! empty( $row[ $field ] ) ) {
					$retiring = true;
					break;
				}
			}
			if ( ! empty( $row['lifecycle'] ) && 'active' !== strtolower( (string) $row['lifecycle'] ) ) {
				$retiring = true;
			}

			$out[ strtolower( $id ) ] = array(
				'line'     => ! empty( $row['line'] ) ? strtolower( (string) $row['line'] ) : '',
				'created'  => $created,
				'retiring' => $retiring,
			);
		}

		return $out;
	}

	/**
	 * Persist the last successful /models result so the offline list and the
	 * line resolver survive a transient expiry, a cache flush and a site that
	 * cannot reach the provider right now.
	 *
	 * This is what makes get_fallback_models() stop being a snapshot of
	 * whatever was current on the day the release was cut.
	 *
	 * @param array<string,string>               $models id => label
	 * @param array<string,array<string,mixed>>  $meta   id => metadata
	 */
	private static function remember_catalog( string $provider, array $models, array $meta ): void {
		if ( empty( $models ) ) {
			return;
		}

		/*
		 * One option holds all four providers, so this read-modify-write can
		 * lose a slice: two refreshes for different providers that interleave
		 * between the read and the write leave only the second provider's
		 * models stored. /models/refresh takes one provider per request, so it
		 * needs two requests in flight, which the Settings screen reaches by
		 * refreshing one provider while another is still running.
		 *
		 * The wait is the point. Taking the lock once and writing anyway on
		 * failure would fix nothing, because a failed lock IS the contended
		 * case: the other holder is mid-write and reading before it finishes
		 * is what loses the slice. Three bounded attempts put this read after
		 * that write in every case except a holder that stalls for 150ms
		 * between a get_option() and an update_option(), after a network round
		 * trip that already cost far more than that.
		 *
		 * The write is never skipped. Dropping it on a failed lock would trade
		 * a cache slice that heals on the next refresh for one that is never
		 * written at all, so the contended case stays no worse than it was. A
		 * crashed holder is taken over after the TTL, and the lock is released
		 * only when this call actually holds it, so a late release can never
		 * remove a successor's.
		 */
		$token  = null;
		$locked = false;
		if ( class_exists( 'RNRD_Atomic' ) ) {
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$locked = RNRD_Atomic::acquire_lock( self::LOCK_CATALOG, 15, $token );
				if ( $locked ) {
					break;
				}
				usleep( 50000 );
			}
		}

		// The holder's write landed in the database, not in this request's
		// option cache, so the read has to go past it to see the slice.
		wp_cache_delete( self::OPT_CATALOG, 'options' );

		$catalog = get_option( self::OPT_CATALOG, array() );
		if ( ! is_array( $catalog ) ) {
			$catalog = array();
		}

		$catalog[ $provider ] = array(
			'models'  => $models,
			'meta'    => $meta,
			'fetched' => time(),
		);

		update_option( self::OPT_CATALOG, $catalog, false );

		if ( $locked ) {
			RNRD_Atomic::release_lock( self::LOCK_CATALOG, $token );
		}
	}

	/**
	 * Returns true when the active provider has an API key configured.
	 * Used by the admin "API key set?" status badge.
	 */
	public static function active_provider_ready(): bool {
		return '' !== trim( self::get_api_key( self::get_active_provider() ) );
	}

	/**
	 * Returns a human-readable label for a provider ID. Used in admin UI
	 * and error messages. Translatable via the `rankready` text domain.
	 */
	public static function get_provider_label( string $provider ): string {
		switch ( $provider ) {
			case self::PROVIDER_ANTHROPIC: return __( 'Claude (Anthropic)', 'rankready-ai-llm-seo' );
			case self::PROVIDER_GEMINI:    return __( 'Gemini (Google)', 'rankready-ai-llm-seo' );
			case self::PROVIDER_DEEPSEEK:  return __( 'DeepSeek', 'rankready-ai-llm-seo' );
			case self::PROVIDER_OPENAI:
			default:                       return __( 'OpenAI', 'rankready-ai-llm-seo' );
		}
	}

	/**
	 * Returns model choices for Settings / metabox dropdowns: `id => label`.
	 *
	 * When an API key is set and the provider /models fetch succeeds, the live
	 * list is used as-is (labels are the model ID). Curated fallbacks apply only
	 * when there is no key or the fetch fails / returns empty. If the saved model
	 * is missing from a successful remote list it is appended with a deprecated
	 * notice so the user can pick a replacement.
	 *
	 * @param bool $force_refresh When true, bypass the transient cache.
	 */
	public static function get_models_for( string $provider, bool $force_refresh = false ): array {
		if ( $force_refresh ) {
			$result = self::refresh_models_for( $provider );
			return $result['models'];
		}

		$fallback = self::get_fallback_models( $provider );
		$key      = trim( self::get_api_key( $provider ) );

		if ( '' === $key ) {
			return self::ensure_saved_in_list( $provider, $fallback, false );
		}

		$cache_key = self::models_cache_key( $provider, $key );
		$cached    = get_transient( $cache_key );

		if ( false === $cached ) {
			$fetched = self::fetch_remote_models_result( $provider, $key );
			$remote  = $fetched['models'];
			if ( ! empty( $remote ) ) {
				set_transient( $cache_key, $remote, 12 * HOUR_IN_SECONDS );
				return self::ensure_saved_in_list( $provider, $remote, true );
			}
			if ( 'auth' !== $fetched['error'] ) {
				set_transient( $cache_key, array(), 30 * MINUTE_IN_SECONDS );
			}
			return self::ensure_saved_in_list( $provider, $fallback, false );
		}

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return self::ensure_saved_in_list( $provider, $cached, true );
		}

		return self::ensure_saved_in_list( $provider, $fallback, false );
	}

	/**
	 * Force-refresh the remote model list for a provider (clears transient cache).
	 *
	 * @param string $key_override Optional key from the settings form (before save).
	 * @return array{ok:bool,models:array<string,string>,source:string,count:int,message:string}
	 */
	public static function refresh_models_for( string $provider, string $key_override = '' ): array {
		$key = self::resolve_api_key( $provider, $key_override );
		if ( '' === $key ) {
			$fallback = self::get_fallback_models( $provider );
			return array(
				'ok'      => false,
				'models'  => self::ensure_saved_in_list( $provider, $fallback, false ),
				'source'  => 'fallback',
				'count'   => count( $fallback ),
				'message' => __( 'No API key stored for this provider. Add one under RankReady → Settings → API Keys, then try again.', 'rankready-ai-llm-seo' ),
			);
		}

		delete_transient( self::models_cache_key( $provider, $key ) );
		$fetched = self::fetch_remote_models_result( $provider, $key );
		$remote  = $fetched['models'];
		$cache_key = self::models_cache_key( $provider, $key );

		if ( ! empty( $remote ) ) {
			set_transient( $cache_key, $remote, 12 * HOUR_IN_SECONDS );
			$models = self::ensure_saved_in_list( $provider, $remote, true );
			return array(
				'ok'      => true,
				'models'  => $models,
				'source'  => 'remote',
				'count'   => count( $models ),
				'message' => '',
			);
		}

		if ( 'auth' !== $fetched['error'] ) {
			set_transient( $cache_key, array(), 30 * MINUTE_IN_SECONDS );
		}
		$fallback = self::get_fallback_models( $provider );
		$models   = self::ensure_saved_in_list( $provider, $fallback, false );

		$message = 'auth' === $fetched['error']
			? __( 'The API key was rejected by the provider. Check the key and try again.', 'rankready-ai-llm-seo' )
			: __( 'Could not fetch models from the provider. Showing offline defaults.', 'rankready-ai-llm-seo' );

		return array(
			'ok'      => false,
			'models'  => $models,
			'source'  => 'fallback',
			'count'   => count( $models ),
			'message' => $message,
		);
	}

	/**
	 * Resolve an API key from a form submission or stored option (masked → stored).
	 */
	public static function resolve_api_key( string $provider, string $submitted_key = '' ): string {
		$key = trim( $submitted_key );
		if ( '' === $key || false !== strpos( $key, '••••' ) ) {
			$key = trim( self::get_api_key( $provider ) );
		}
		return $key;
	}

	/**
	 * Transient key for cached remote model lists (scoped per provider + API key).
	 */
	private static function models_cache_key( string $provider, string $key = '' ): string {
		if ( '' === $key ) {
			$key = trim( self::get_api_key( $provider ) );
		}
		$fingerprint = '' !== $key ? substr( hash( 'sha256', $key ), 0, 16 ) : 'none';
		return 'rnrd_models_remote_v5_' . $provider . '_' . $fingerprint;
	}

	/**
	 * Delete cached remote model lists (object-cache-aware via delete_transient).
	 *
	 * @param string|null $provider Limit to one provider, or null for all four.
	 */
	public static function purge_models_cache( ?string $provider = null ): void {
		$providers = null === $provider
			? array( self::PROVIDER_OPENAI, self::PROVIDER_ANTHROPIC, self::PROVIDER_GEMINI, self::PROVIDER_DEEPSEEK )
			: array( $provider );

		foreach ( $providers as $p ) {
			delete_transient( 'rnrd_models_remote_v4_' . $p );
			delete_transient( self::models_cache_key( $p ) );
			delete_transient( self::model_meta_cache_key( $p ) );
		}

		/*
		 * The by-name deletes above are not enough on their own, and the first
		 * version of this sweep made it worse rather than better.
		 *
		 * Both cache keys are fingerprinted with a hash of the API key in use
		 * when they were written, so a name derived from the key stored RIGHT
		 * NOW misses every list cached under a key that has since been changed,
		 * cleared or replaced. Clearing the box and pasting a new key is the
		 * normal way a user gets here, which is exactly when the stale list
		 * survives the purge.
		 *
		 * The sweep that covered that case ran a raw DELETE, and a raw DELETE
		 * does not touch WordPress's object cache. get_transient() therefore
		 * kept returning rows the DELETE had just removed, so "Refresh list"
		 * reported success and the resolver carried on reading the old
		 * metadata. Under a persistent object cache, which most managed hosts
		 * run, it never expired at all within the cache's own lifetime. Two
		 * lab assertions caught it on the same run: a model resolved forward
		 * off a catalogue that had been deleted, and a sampling flag read back
		 * true after being purged.
		 *
		 * So the names are read from the options table and each one is then
		 * removed with delete_transient(), which clears the row, its timeout
		 * row and the cache entry together. No raw DELETE is left here.
		 */
		self::delete_transients_like( 'rnrd_models_' );
		self::delete_transients_like( 'rnrd_modelmeta_' );

		self::purge_sampling_flags( $providers );
	}

	/**
	 * Forget which models rejected a sampling parameter.
	 *
	 * Every new rnrd_* transient bucket needs a paired delete in the central
	 * flush, and these had none: the per-model flags live for seven days and
	 * are written when a retry returns 200, which is strong evidence but not
	 * proof that the parameter was the reason. A 400 that merely mentioned
	 * `temperature` for an unrelated reason would therefore drop it for a week
	 * on a model that accepts it, quietly changing generation output, and
	 * "Refresh list" was the natural place to look for a reset and did not
	 * clear them. Uninstall was already covered by the catch-all sweep; this
	 * is the in-life flush.
	 *
	 * Deleted by name, not by a LIKE sweep. On a site with a persistent object
	 * cache, transients never reach the options table at all, so a DB sweep
	 * would report success and clear nothing. The names are hashes of model
	 * IDs, which looks unenumerable but is not: the IDs are exactly the ones
	 * the plugin knows about, plus whatever is saved, so every flag this
	 * install could have written can be named. The LIKE sweep still runs
	 * afterwards for flags left behind by a model that has since dropped off
	 * the provider's list.
	 *
	 * @param string[] $providers Provider slugs to clear.
	 */
	private static function purge_sampling_flags( array $providers ): void {
		foreach ( $providers as $p ) {
			$ids   = self::known_model_ids( $p );
			$ids[] = (string) self::get_model( $p );

			foreach ( array_unique( $ids ) as $id ) {
				$id = (string) $id;
				if ( '' === $id ) {
					continue;
				}
				delete_transient( self::sampling_flag_key( $p, $id ) );
			}

			self::delete_transients_like( 'rnrd_' . $p . '_nosample_' );
		}
	}

	/**
	 * Delete every transient whose name starts with a prefix, cache included.
	 *
	 * Reads the names, then hands each one to delete_transient(). That matters
	 * more than it looks: a `DELETE ... LIKE` removes the rows but leaves
	 * WordPress's object cache holding the values, so get_transient() goes on
	 * returning data that is no longer in the database, for the rest of the
	 * request on a default install and until the cache itself expires on a site
	 * running Redis or Memcached. delete_transient() clears the value row, the
	 * timeout row and the cache entry together, which is why the timeout names
	 * are not matched here.
	 *
	 * Only transients already written to the options table can be found this
	 * way. Under a persistent object cache they may never be there at all,
	 * which is why every caller names the keys it can derive first and treats
	 * this as the sweep for the ones it cannot: a stale API-key fingerprint, or
	 * a model that has dropped off the provider's list since the flag was set.
	 *
	 * @param string $prefix Transient name prefix, without `_transient_`.
	 */
	private static function delete_transients_like( string $prefix ): void {
		global $wpdb;

		$like  = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- names cannot be derived without reading them; each is then deleted through the cache-aware API

		foreach ( (array) $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}
	}

	/**
	 * Transient name recording that one model rejects sampling parameters.
	 *
	 * One definition for all four providers. It used to be a private copy in
	 * each client, which is four places for the same string to drift and, more
	 * to the point, four private methods the central flush could not call, so
	 * the flush had to guess at the names instead of deriving them.
	 *
	 * Keyed by a hash of the model so an arbitrary provider ID cannot build an
	 * invalid or over-long option name, and prefixed per provider so one
	 * provider's answer never speaks for another's model of the same name.
	 */
	public static function sampling_flag_key( string $provider, string $model ): string {
		return 'rnrd_' . $provider . '_nosample_' . substr( hash( 'sha256', $model ), 0, 20 );
	}

	/**
	 * Keep the saved model visible. When a live remote list is active and the
	 * saved ID is absent, mark it deprecated so the user knows to switch.
	 *
	 * @param array<string,string> $models
	 */
	private static function ensure_saved_in_list( string $provider, array $models, bool $remote_active ): array {
		/*
		 * BOTH IDs, and the saved one is the load-bearing half. The admin
		 * <select> marks an option selected by matching the value it renders,
		 * so an ID that is missing from this list matches nothing, the browser
		 * falls back to the first option on its own, and the next save writes
		 * that unrelated model. Keeping only the resolved ID visible (which is
		 * all this did while it called self::get_model() with no mode) means a
		 * pinned model that has dropped off the provider's list cannot be
		 * displayed or kept. The resolved ID is listed too, because it is what
		 * generation will actually use and the screen says so out loud.
		 */
		foreach ( array( self::get_saved_model( $provider ), self::get_model( $provider ) ) as $saved ) {
			if ( '' === $saved || isset( $models[ $saved ] ) ) {
				continue;
			}

			if ( $remote_active ) {
				$models[ $saved ] = sprintf(
					/* translators: %s: retired model ID */
					__( '%s — deprecated; please choose another model', 'rankready-ai-llm-seo' ),
					$saved
				);
			} else {
				$models[ $saved ] = $saved;
			}
		}

		return $models;
	}

	/**
	 * Live model list plus a coarse error bucket for user-facing messages.
	 *
	 * @return array{models:array<string,string>,error:string} error: '' | 'auth' | 'remote'
	 */
	private static function fetch_remote_models_result( string $provider, string $key ): array {
		$resp = self::request_models_list( $provider, $key );
		if ( is_wp_error( $resp ) ) {
			return array(
				'models' => array(),
				'error'  => 'remote',
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 401 === $code || 403 === $code ) {
			return array(
				'models' => array(),
				'error'  => 'auth',
			);
		}

		$json = self::decode_ok( $resp );
		if ( null === $json ) {
			return array(
				'models' => array(),
				'error'  => 'remote',
			);
		}

		$ids    = self::extract_model_ids( $provider, $json );
		$models = self::filter_model_ids( $ids, self::provider_model_rules( $provider ) );
		$meta   = self::extract_model_meta( $provider, $json );

		/*
		 * Metadata is cached and persisted here, in the one place every caller
		 * funnels through, rather than in get_models_for() and
		 * refresh_models_for() separately. The metadata transient is keyed and
		 * expired exactly like the list transient so the two can never disagree
		 * about which key they describe.
		 */
		if ( ! empty( $models ) ) {
			if ( ! empty( $meta ) ) {
				set_transient( self::model_meta_cache_key( $provider, $key ), $meta, 12 * HOUR_IN_SECONDS );
			}
			self::remember_catalog( $provider, $models, $meta );
		}

		return array(
			'models' => $models,
			'error'  => '',
		);
	}

	/**
	 * HTTP GET for a provider's model-list endpoint.
	 */
	private static function request_models_list( string $provider, string $key ) {
		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
				return wp_remote_get( 'https://api.openai.com/v1/models', array(
					'timeout' => 8,
					'headers' => array( 'Authorization' => 'Bearer ' . $key ),
				) );
			case self::PROVIDER_DEEPSEEK:
				return wp_remote_get( 'https://api.deepseek.com/v1/models', array(
					'timeout' => 8,
					'headers' => array( 'Authorization' => 'Bearer ' . $key ),
				) );
			case self::PROVIDER_ANTHROPIC:
				return wp_remote_get( 'https://api.anthropic.com/v1/models', array(
					'timeout' => 8,
					'headers' => array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ),
				) );
			case self::PROVIDER_GEMINI:
				// Key in a header, never in the query string — see RNRD_LLM_Gemini.
				return wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models', array(
					'timeout' => 8,
					'headers' => array( 'x-goog-api-key' => $key ),
				) );
		}
		return null;
	}

	/**
	 * Provider-specific prefix / exclude rules for text-generation model IDs.
	 *
	 * @return array{prefix:string,exclude:array<int,string>,drop_regex:string}
	 */
	private static function provider_model_rules( string $provider ): array {
		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
				return array(
					/*
					 * Every prefix in this method is load-bearing beyond
					 * tidiness, so do not relax one without checking the save
					 * path. RNRD_Admin::sanitize_provider_model() keeps only
					 * [a-zA-Z0-9._-/] and drops the rest in silence, the colon
					 * included, which it has to: Gemini addresses a method as
					 * `models/<id>:generateContent`, so a colon inside an ID
					 * would retarget the call. These prefixes are made of
					 * permitted characters, and that is what keeps an
					 * unstorable ID such as an OpenAI fine-tune
					 * (`ft:gpt-5.4-mini:acme::AbCd`) out of the select at all.
					 * Relax the prefix and the plugin starts offering models it
					 * mangles on save, then tells the user their own pick is
					 * unavailable.
					 */
					'prefix'     => 'gpt-',
					'exclude'    => array( 'instruct', 'realtime', 'audio', 'transcribe', 'tts', 'image', 'dall', 'whisper', 'search', 'embedding', 'moderation', 'codex', '-chat' ),
					'drop_regex' => '/(\d{4}|\d+k\b|preview|chatgpt)/',
				);
			case self::PROVIDER_GEMINI:
				return array(
					'prefix'     => 'gemini-',
					'exclude'    => array( 'computer-use', 'robotics', '-image', 'tts', 'transcribe', 'omni', '-latest', 'customtools', 'embedding', 'aqa' ),
					'drop_regex' => '',
				);
			case self::PROVIDER_ANTHROPIC:
				return array(
					'prefix'     => 'claude-',
					'exclude'    => array(),
					'drop_regex' => '',
				);
			case self::PROVIDER_DEEPSEEK:
				return array(
					'prefix'     => 'deepseek-',
					'exclude'    => array(),
					'drop_regex' => '',
				);
			default:
				return array(
					'prefix'     => '',
					'exclude'    => array(),
					'drop_regex' => '',
				);
		}
	}

	/**
	 * Pull raw model ID strings from a provider /models JSON body.
	 *
	 * @return string[]
	 */
	private static function extract_model_ids( string $provider, array $json ): array {
		$ids = array();

		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
			case self::PROVIDER_DEEPSEEK:
			case self::PROVIDER_ANTHROPIC:
				if ( empty( $json['data'] ) || ! is_array( $json['data'] ) ) {
					break;
				}
				foreach ( $json['data'] as $m ) {
					if ( ! empty( $m['id'] ) ) {
						$ids[] = (string) $m['id'];
					}
				}
				break;

			case self::PROVIDER_GEMINI:
				if ( empty( $json['models'] ) || ! is_array( $json['models'] ) ) {
					break;
				}
				foreach ( $json['models'] as $m ) {
					$methods = isset( $m['supportedGenerationMethods'] ) ? (array) $m['supportedGenerationMethods'] : array();
					if ( ! in_array( 'generateContent', $methods, true ) ) {
						continue;
					}
					if ( empty( $m['name'] ) ) {
						continue;
					}
					$ids[] = str_replace( 'models/', '', (string) $m['name'] );
				}
				break;
		}

		return $ids;
	}

	/**
	 * Apply prefix + exclude rules to a list of model IDs. Returns id => id.
	 *
	 * @param string[]                                      $ids
	 * @param array{prefix:string,exclude:array,drop_regex:string} $rules
	 * @return array<string,string>
	 */
	private static function filter_model_ids( array $ids, array $rules ): array {
		$prefix     = (string) ( $rules['prefix'] ?? '' );
		$exclude    = (array) ( $rules['exclude'] ?? array() );
		$drop_regex = (string) ( $rules['drop_regex'] ?? '' );
		$out        = array();

		foreach ( $ids as $id ) {
			$id = (string) $id;
			if ( '' === $id ) {
				continue;
			}
			if ( '' !== $prefix && 0 !== strpos( $id, $prefix ) ) {
				continue;
			}
			if ( self::is_model_id_excluded( $id, $exclude, $drop_regex ) ) {
				continue;
			}
			$out[ $id ] = $id;
		}

		ksort( $out );
		return $out;
	}

	/**
	 * Returns true when a model ID should be dropped from provider lists.
	 */
	private static function is_model_id_excluded( string $id, array $exclude, string $drop_regex = '' ): bool {
		foreach ( $exclude as $term ) {
			if ( false !== strpos( $id, $term ) ) {
				return true;
			}
		}
		return '' !== $drop_regex && (bool) preg_match( $drop_regex, $id );
	}

	/**
	 * Return the JSON body of a successful (HTTP 200) wp_remote response as an
	 * array, or null on any error / non-200 / non-array body.
	 */
	private static function decode_ok( $resp ): ?array {
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $json ) ? $json : null;
	}

	/**
	 * Offline fallback when no API key is set or the live /models fetch fails.
	 * Labels match values (model ID) so the dropdown stays unambiguous.
	 *
	 * @return array<string,string>
	 */
	private static function get_fallback_models( string $provider ): array {
		/*
		 * The last live list the site actually fetched beats the seed below.
		 * Without this, a site whose provider is briefly unreachable drops back
		 * to whatever was current on the day the release was cut and offers the
		 * user models that may no longer exist. The seed is the floor, not the
		 * answer: it is only reached on a site that has never had a working key.
		 */
		$catalog = get_option( self::OPT_CATALOG, array() );
		if ( is_array( $catalog ) && ! empty( $catalog[ $provider ]['models'] ) && is_array( $catalog[ $provider ]['models'] ) ) {
			return $catalog[ $provider ]['models'];
		}

		$ids = array();
		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
				$ids = array( 'gpt-5.4-nano', 'gpt-5.4-mini', 'gpt-5.4', 'gpt-5.5', 'gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-6-astra' );
				break;
			case self::PROVIDER_ANTHROPIC:
				$ids = array( 'claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5', 'claude-fable-5-1' );
				break;
			case self::PROVIDER_GEMINI:
				$ids = array( 'gemini-3.1-flash-lite', 'gemini-2.5-flash', 'gemini-3.5-flash', 'gemini-2.5-pro', 'gemini-3.8-flash' );
				break;
			case self::PROVIDER_DEEPSEEK:
				$ids = array( 'deepseek-flash', 'deepseek-v4-pro' );
				break;
		}
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = $id;
		}
		return $out;
	}

	/**
	 * Output-token budget for one generation.
	 *
	 * Why this is not just a literal at each call site. Current models spend
	 * output tokens reasoning before they answer, and that reasoning is billed
	 * against `max_tokens`. Measured live on 2026-10-20 with the summary
	 * prompt: Haiku 4.5 answered in 74 to 79 output tokens, while Haiku 5.5
	 * and Sonnet 5.5 used 800 to 1,466 for the same prompt. The old 500 was
	 * generous for the 4.x line and too tight for everything newer, so once
	 * the resolver moved users onto a current model, roughly half of all
	 * summaries came back `stop_reason=max_tokens` and were discarded. A real
	 * bulk run failed 4 of 8 posts.
	 *
	 * Raising the ceiling is not the same as spending more: billing is on
	 * tokens actually produced, so a model that finishes in 80 tokens costs
	 * the same under a 2,500 ceiling as under a 500 one. The only thing a
	 * higher ceiling buys is room to finish.
	 *
	 * The filter exists because the next generation of models will reason
	 * differently again, and a site owner should be able to move this without
	 * waiting for a release.
	 *
	 * @param int    $default Measured default for this purpose.
	 * @param string $purpose Short slug: 'summary', 'faq', 'ping', 'verify'.
	 * @return int At least 1.
	 */
	public static function output_budget( int $default, string $purpose ): int {
		/**
		 * Filters the output-token ceiling for one RankReady generation.
		 *
		 * @since 1.4.2
		 * @param int    $default Measured default for this purpose.
		 * @param string $purpose One of 'summary', 'faq', 'ping', 'verify'.
		 */
		$budget = (int) apply_filters( 'rnrd_max_output_tokens', $default, $purpose );

		/*
		 * A non-positive value falls back to the measured default rather than
		 * to 1. The floor used to be 1, and 1 is answered by all four
		 * providers with a stop reason of `length`, which is_truncated_reason()
		 * matches, so every generation on the site failed as truncated: the
		 * exact outcome the floor exists to prevent. Returning 0 from an
		 * integer-ceiling filter to mean "use the provider default" is a
		 * reasonable reading of it, and it should not break the site.
		 */
		if ( $budget < 1 ) {
			return max( 1, $default );
		}

		return $budget;
	}

	/**
	 * Main dispatch entry point. All callers (summary, FAQ, future features)
	 * use this. Routes to the active provider's static `generate()` method.
	 *
	 * Returns the standardized response array described in the file docblock.
	 * Never throws — errors come back as `['ok' => false, 'error' => '…']`.
	 *
	 * @param string $system System prompt (provider-agnostic).
	 * @param string $user   User prompt.
	 * @param array  $opts   Optional: max_tokens, temperature, json, timeout.
	 */
	public static function generate( string $system, string $user, array $opts = array() ): array {
		$provider = self::get_active_provider();
		$api_key  = self::get_api_key( $provider );

		if ( '' === trim( $api_key ) ) {
			return self::error_response(
				$provider,
				sprintf(
					/* translators: %s: provider label */
					__( 'No %s API key configured. Add one in RankReady → Settings.', 'rankready-ai-llm-seo' ),
					self::get_provider_label( $provider )
				)
			);
		}

		$opts = wp_parse_args( $opts, array(
			'max_tokens'  => 500,
			'temperature' => 0.2,
			'json'        => false,
			'timeout'     => 25,
		) );

		switch ( $provider ) {
			case self::PROVIDER_ANTHROPIC: return RNRD_LLM_Anthropic::generate( $system, $user, $opts );
			case self::PROVIDER_GEMINI:    return RNRD_LLM_Gemini::generate( $system, $user, $opts );
			case self::PROVIDER_DEEPSEEK:  return RNRD_LLM_DeepSeek::generate( $system, $user, $opts );
			case self::PROVIDER_OPENAI:
			default:                       return RNRD_LLM_OpenAI::generate( $system, $user, $opts );
		}
	}

	/**
	 * Seconds of PHP execution time left, or INF when there is no limit.
	 *
	 * The sampling-parameter self-heal in the provider clients issues a SECOND
	 * HTTP request inside one generate() call, so a request that fit the
	 * execution limit on its own can stop fitting. That matters most in the Pro
	 * bulk runner, which dequeues a post and persists the shortened queue
	 * BEFORE generating: a fatal there does not retry the post, it loses it.
	 *
	 * This is deliberately an approximation. WordPress sets $timestart at load,
	 * so elapsed is measured from there, while PHP's own counter restarts on
	 * every set_time_limit() call and does not count time spent in blocking
	 * socket reads on some SAPIs. It therefore reads as LESS time remaining
	 * than there may actually be, which is the safe direction: the cost of
	 * being wrong is one skipped retry and an honest error message, against a
	 * silently dropped post.
	 */
	public static function seconds_remaining(): float {
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max <= 0 ) {
			return INF; // CLI, or an explicitly unlimited limit.
		}
		$start = isset( $GLOBALS['timestart'] ) ? (float) $GLOBALS['timestart'] : microtime( true );
		return (float) $max - ( microtime( true ) - $start );
	}

	/**
	 * Timeout to give a retry that must finish inside this request's remaining
	 * execution time, or 0 when there is not enough room to try at all.
	 *
	 * The first version of this guard asked whether there was room for a
	 * request of the FULL configured timeout, and that was wrong in a way that
	 * defeated the retry it was written to protect. Every live caller passes
	 * 25, so the test was `seconds_remaining() > 27`: on the common
	 * max_execution_time of 30 the retry could only run in the first three
	 * seconds of the request. In a Pro bulk tick post 1 could self-heal and no
	 * later post could, and because the retry's 200 is the ONLY thing that
	 * writes the learned flag, a run whose first post failed for any other
	 * reason never learned it at all. Every post then failed with the raw
	 * provider 400, which is the exact 1.4.1 symptom this release exists to
	 * fix. Found by a code review on 2026-10-20, before release.
	 *
	 * Clamping instead of refusing keeps both properties. The retry is given
	 * whatever room is actually left, minus slack to decode and return, so it
	 * cannot overrun the execution limit and cannot lose a post the Pro runner
	 * has already dequeued; and it still runs whenever a usable amount of time
	 * remains, which on a 30-second host is nearly always. Below the minimum,
	 * the honest error beats a request that cannot finish.
	 *
	 * @param int $timeout Timeout the first request used.
	 * @param int $minimum Smallest timeout worth attempting, in seconds.
	 * @return int Clamped timeout in seconds, or 0 when there is no room.
	 */
	public static function retry_timeout( int $timeout, int $minimum = 5 ): int {
		$remaining = self::seconds_remaining();
		if ( INF === $remaining ) {
			return max( 1, $timeout );
		}

		$room = (int) floor( $remaining - 2.0 );
		if ( $room < $minimum ) {
			return 0;
		}

		return max( 1, min( $timeout, $room ) );
	}

	/**
	 * Marker on an error string that is ALREADY a finished, translated,
	 * user-facing sentence and must reach the user whole.
	 *
	 * RNRD_Rest::friendly_generation_error() maps raw provider errors onto
	 * short guided messages by matching English needles, and collapses
	 * anything it does not recognise to 200 characters. The truncation message
	 * is written here rather than there, because only the client knows the
	 * stop reason, and it matched none of those needles: it shipped through
	 * the raw fallback and was cut off at 199 characters, which amputated
	 * every remedy in it and left the user with "nothing was saved" and no
	 * next step. Needle-matching the message itself cannot work either, since
	 * it is translated before it gets there. A marker is language-independent,
	 * so it survives translation, and the mapper strips it.
	 *
	 * Every consumer must strip it. RNRD_Rest::friendly_generation_error() does,
	 * and RNRD_Generator::mark_autogen_failed() does since 1.4.2 (before that
	 * the marker and a mid-word cut of the message both reached the metabox on
	 * the autogen path). Treat a new reader of one of these strings as a new
	 * place to strip it.
	 */
	const FRIENDLY_PREFIX = '[rnrd:ready] ';

	/**
	 * The finished message inside an error string carrying FRIENDLY_PREFIX, or
	 * '' when the string is a raw provider error that still needs mapping.
	 */
	public static function friendly_error_text( string $raw ): string {
		$raw = trim( $raw );
		if ( 0 !== strpos( $raw, self::FRIENDLY_PREFIX ) ) {
			return '';
		}
		return trim( substr( $raw, strlen( self::FRIENDLY_PREFIX ) ) );
	}

	/**
	 * Builds the standardized error response shape. Centralized so every
	 * provider returns identical fields when something fails.
	 */
	public static function error_response( string $provider, string $error, string $model = '' ): array {
		return array(
			'ok'           => false,
			'content'      => '',
			'tokens_in'    => 0,
			'tokens_out'   => 0,
			'tokens_total' => 0,
			'model'        => $model,
			'provider'     => $provider,
			'error'        => $error,
		);
	}

	/**
	 * Did the model stop because it ran out of room rather than because it
	 * finished?
	 *
	 * Every provider reports this and, until 1.4.0, nothing read it. The
	 * response still carries text, so a cut-off answer looked exactly like a
	 * complete one: `'' === $content` was the only failure any provider
	 * checked. That text then went straight into a saved AI summary, or into
	 * FAQPage schema as a published answer ending mid-sentence. Structured data
	 * is a claim to a search engine that this IS the answer, so shipping half
	 * of one is worse than failing, and failing is recoverable: the user
	 * shortens the target length or raises the token cap and generates again.
	 *
	 * Each provider's own signal, since they do not agree:
	 *   OpenAI + DeepSeek  choices[0].finish_reason === 'length'
	 *   Anthropic          stop_reason === 'max_tokens'
	 *   Gemini             candidates[0].finishReason === 'MAX_TOKENS'
	 *
	 * @param string $reason The provider's raw stop/finish reason.
	 * @return bool True when the reason means "truncated", not "done".
	 */
	public static function is_truncated_reason( string $reason ): bool {
		$reason = strtolower( trim( $reason ) );
		return in_array( $reason, array( 'length', 'max_tokens', 'maxtokens', 'max_output_tokens' ), true );
	}

	/**
	 * The error a provider returns when its answer was cut off. One wording, so
	 * the four providers cannot drift into four different explanations of the
	 * same thing, and the fix is in the message because the user is the only
	 * one who can apply it.
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Model id.
	 * @param string $reason   Raw provider reason, for the log.
	 * @return array Error response.
	 */
	public static function truncated_response( string $provider, string $model, string $reason ): array {
		return self::error_response(
			$provider,
			self::FRIENDLY_PREFIX . sprintf(
				/* translators: 1: provider stop reason. */
				__( 'The model ran out of output length and stopped mid-answer (stop reason: %1$s). Nothing was saved, because a half-finished answer would have been published as though it were complete. Pick a model with more output room and generate again. For an FAQ, a lower FAQ Count leaves more room for each answer. A developer can raise the ceiling with the rnrd_max_output_tokens filter.', 'rankready-ai-llm-seo' ),
				'' === $reason ? 'length' : $reason
			),
			$model
		);
	}

	/**
	 * Builds the standardized success response shape.
	 */
	public static function success_response( string $provider, string $model, string $content, int $tokens_in = 0, int $tokens_out = 0 ): array {
		return array(
			'ok'           => true,
			'content'      => $content,
			'tokens_in'    => $tokens_in,
			'tokens_out'   => $tokens_out,
			'tokens_total' => $tokens_in + $tokens_out,
			'model'        => $model,
			'provider'     => $provider,
			'error'        => '',
		);
	}
	/**
	 * Detect the human language of a post's content so generated Summaries/FAQs
	 * are written in the SAME language as the page instead of defaulting to
	 * English. Order: WPML per-post -> Polylang per-post -> site language
	 * (Settings > General) -> current locale.
	 *
	 * @param int $post_id Post ID.
	 * @return array e.g. array( 'code' => 'de', 'name' => 'German' ). 'name' may be ''.
	 */
	public static function detect_content_language( int $post_id ): array {
		$locale   = '';
		// Issue #27: true only when a per-post source (WPML/Polylang) actually
		// declared this post's language. The site-wide fallback below is a
		// guess, not a fact — a post can legitimately be in a different
		// language than Settings > General (a guest post, a quoted excerpt,
		// any multilingual site not running WPML/Polylang). Callers that
		// assert the language as fact (the LLM prompt directive) must check
		// this before doing so; callers that just need a best-effort locale
		// for standard metadata (schema inLanguage, markdown lang:
		// frontmatter, DataForSEO geo-targeting) are fine using the fallback.
		$declared = false;

		// WPML per-post language.
		if ( has_filter( 'wpml_post_language_details' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				$locale   = (string) $details['language_code'];
				$declared = true;
			}
		}

		// Polylang per-post language.
		if ( '' === $locale && function_exists( 'pll_get_post_language' ) ) {
			$pll = pll_get_post_language( $post_id, 'locale' );
			if ( ! empty( $pll ) ) {
				$locale   = (string) $pll;
				$declared = true;
			}
		}

		// Site language (Settings > General) — preferred over the current user's
		// admin/REST locale, which can differ from the content language.
		if ( '' === $locale ) {
			if ( defined( 'WPLANG' ) && WPLANG ) {
				$locale = (string) WPLANG;
			} else {
				$site   = get_option( 'WPLANG' );
				$locale = ( is_string( $site ) && '' !== $site ) ? $site : get_locale();
			}
		}

		$code = strtolower( substr( str_replace( '-', '_', $locale ), 0, 2 ) );

		$names = array(
			'en' => 'English',    'de' => 'German',     'es' => 'Spanish',    'fr' => 'French',
			'it' => 'Italian',    'pt' => 'Portuguese', 'nl' => 'Dutch',      'pl' => 'Polish',
			'ru' => 'Russian',    'ja' => 'Japanese',   'zh' => 'Chinese',    'ar' => 'Arabic',
			'tr' => 'Turkish',    'sv' => 'Swedish',    'da' => 'Danish',     'nb' => 'Norwegian',
			'nn' => 'Norwegian',  'fi' => 'Finnish',    'cs' => 'Czech',      'hu' => 'Hungarian',
			'ro' => 'Romanian',   'el' => 'Greek',      'he' => 'Hebrew',     'ko' => 'Korean',
			'id' => 'Indonesian', 'th' => 'Thai',       'vi' => 'Vietnamese', 'uk' => 'Ukrainian',
			'hi' => 'Hindi',      'sk' => 'Slovak',     'bg' => 'Bulgarian',  'hr' => 'Croatian',
			'sr' => 'Serbian',    'lt' => 'Lithuanian', 'lv' => 'Latvian',    'et' => 'Estonian',
			'sl' => 'Slovenian',  'fa' => 'Persian',    'bn' => 'Bengali',    'ta' => 'Tamil',
			'ms' => 'Malay',      'ca' => 'Catalan',    'eu' => 'Basque',     'gl' => 'Galician',
		);

		$name = isset( $names[ $code ] ) ? $names[ $code ] : '';
		if ( '' === $name && function_exists( 'locale_get_display_language' ) ) {
			$display = @locale_get_display_language( $code, 'en' );
			if ( is_string( $display ) && '' !== $display && strtolower( $display ) !== $code ) {
				$name = $display;
			}
		}

		return array(
			'code'     => '' !== $code ? $code : 'en',
			'name'     => $name,
			'locale'   => $locale,
			'declared' => $declared,
		);
	}

	/**
	 * DataForSEO search location for a post, so FAQ seed questions come from the
	 * right country's search data (a German post gets German search volume, not
	 * US). Google geo-target IDs = 2000 + ISO 3166-1 numeric (verified DE=2276).
	 * Unknown locales fall back to the US (2840) — the historic default, so this
	 * never regresses an English site.
	 *
	 * @param int $post_id Post ID.
	 * @return int DataForSEO location_code.
	 */
	public static function dfs_location_code( int $post_id ): int {
		$lang    = self::detect_content_language( $post_id );
		$locale  = isset( $lang['locale'] ) ? (string) $lang['locale'] : '';
		$country = '';

		$parts = preg_split( '/[_-]/', $locale );
		if ( is_array( $parts ) && count( $parts ) >= 2 && 2 === strlen( $parts[1] ) ) {
			$country = strtoupper( $parts[1] );
		}
		if ( '' === $country ) {
			// Bare locales (e.g. "de") -> primary market for that language.
			$lang_country = array(
				'en' => 'US', 'de' => 'DE', 'fr' => 'FR', 'es' => 'ES', 'it' => 'IT',
				'pt' => 'PT', 'nl' => 'NL', 'pl' => 'PL', 'sv' => 'SE', 'da' => 'DK',
				'nb' => 'NO', 'nn' => 'NO', 'fi' => 'FI', 'ru' => 'RU', 'tr' => 'TR',
				'ja' => 'JP', 'cs' => 'CZ', 'hu' => 'HU', 'ro' => 'RO', 'el' => 'GR',
				'uk' => 'UA', 'id' => 'ID', 'vi' => 'VN', 'th' => 'TH', 'ko' => 'KR',
				'hi' => 'IN', 'ar' => 'SA', 'zh' => 'TW', 'he' => 'IL', 'sk' => 'SK',
				'bg' => 'BG', 'hr' => 'HR', 'sr' => 'RS', 'lt' => 'LT', 'lv' => 'LV',
				'et' => 'EE', 'sl' => 'SI', 'fa' => 'IR', 'ms' => 'MY', 'ca' => 'ES',
			);
			$country = isset( $lang_country[ $lang['code'] ] ) ? $lang_country[ $lang['code'] ] : 'US';
		}

		// Google geo-target country IDs (2000 + ISO 3166-1 numeric).
		$geo = array(
			'US' => 2840, 'GB' => 2826, 'DE' => 2276, 'FR' => 2250, 'ES' => 2724,
			'IT' => 2380, 'NL' => 2528, 'PT' => 2620, 'BR' => 2076, 'PL' => 2616,
			'SE' => 2752, 'DK' => 2208, 'NO' => 2578, 'FI' => 2246, 'RU' => 2643,
			'TR' => 2792, 'JP' => 2392, 'KR' => 2410, 'IN' => 2356, 'ID' => 2360,
			'VN' => 2704, 'TH' => 2764, 'CZ' => 2203, 'HU' => 2348, 'RO' => 2642,
			'GR' => 2300, 'UA' => 2804, 'MX' => 2484, 'AR' => 2032, 'CA' => 2124,
			'AU' => 2036, 'AT' => 2040, 'CH' => 2756, 'BE' => 2056, 'IE' => 2372,
			'SA' => 2682, 'AE' => 2784, 'IL' => 2376, 'ZA' => 2710, 'TW' => 2158,
			'BG' => 2100, 'HR' => 2191, 'RS' => 2688, 'LT' => 2440, 'LV' => 2428,
			'EE' => 2233, 'SI' => 2705, 'SK' => 2703, 'IR' => 2364, 'MY' => 2458, 'CN' => 2156,
		);

		return isset( $geo[ $country ] ) ? $geo[ $country ] : 2840;
	}

	/**
	 * High-priority LANGUAGE instruction block for generation prompts, so the
	 * model writes output in the post's language instead of English. Safe for
	 * English sites (the "match the content language" rule yields English).
	 *
	 * @param int $post_id Post ID.
	 * @return string Prompt block ending with a blank line.
	 */
	public static function language_directive( int $post_id ): string {
		$lang = self::detect_content_language( $post_id );

		$out  = "LANGUAGE RULE (highest priority - overrides every formatting rule below):\n";
		$out .= "- Detect the language of the page CONTENT provided below and write EVERY word of your output in that SAME language.\n";
		// Issue #27: only assert a specific language as fact when a per-post
		// source (WPML/Polylang) actually declared it. Without that, $lang
		// is a guess from the SITE'S locale, which can be wrong for this
		// specific post (guest post, quoted excerpt, any multilingual site
		// without WPML/Polylang) — asserting it as fact told the model to
		// mistranslate correct content into the wrong language. The generic
		// "detect from content" line above still does the real work.
		if ( ! empty( $lang['declared'] ) && '' !== $lang['name'] && 'English' !== $lang['name'] ) {
			$out .= "- This page is in {$lang['name']}. Write your entire response in {$lang['name']}.\n";
		}
		$out .= "- Never default to English. If the page is not in English, your output must not be in English.\n";
		$out .= "- Only JSON keys (such as \"question\", \"answer\", \"bullets\") stay in English; every JSON VALUE must be in the page's language.\n";
		$out .= "- Example search queries or keywords provided below may be in another language; translate and adapt them into the page's language.\n\n";

		return $out;
	}
}
