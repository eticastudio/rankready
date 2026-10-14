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
	 *              `claude-haiku-4-5` is the dateless pin, not an evergreen pointer).
	 * - Gemini:    `gemini-2.5-flash` is the stable production pick. Google docs
	 *              warn against `*-latest` aliases in production (hot-swap risk).
	 * - DeepSeek:  `deepseek-v4-flash` replaces the deprecated `deepseek-chat`
	 *              alias (per DeepSeek's pricing page, `deepseek-chat` /
	 *              `deepseek-reasoner` are being retired).
	 *
	 * Retired IDs are routed to their current same-tier equivalent via
	 * self::migrate_model() — see self::model_migration_map() for the full list.
	 *
	 * If a user has an older saved value (e.g. `deepseek-chat`), it stays in
	 * `wp_options` and we still send it to the provider — the dropdown just
	 * stops listing it. When the user saves any change, sanitize_provider_model
	 * preserves whatever string they pick.
	 */
	public static function get_model( string $provider ): string {
		switch ( $provider ) {
			case self::PROVIDER_ANTHROPIC: $saved = (string) get_option( 'rnrd_anthropic_model', 'claude-haiku-4-5' ); break;
			case self::PROVIDER_GEMINI:    $saved = (string) get_option( 'rnrd_gemini_model',    'gemini-2.5-flash' ); break;
			case self::PROVIDER_DEEPSEEK:  $saved = (string) get_option( 'rnrd_deepseek_model',  'deepseek-flash' ); break;
			case self::PROVIDER_OPENAI:
			default:                       $saved = (string) get_option( RNRD_OPT_MODEL,         'gpt-5.4-mini' ); break;
		}
		// Route a retired ID to its tier-matched current equivalent at call-time
		// so the provider never receives a dead model — even before the one-shot
		// DB migration (RNRD_Admin::track_installed_version) has had a chance to
		// run. Side-effect-free; the DB rewrite + admin notice live in the admin.
		return self::migrate_model( $provider, $saved );
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
				// Haiku tier
				'claude-3-5-haiku-20241022'  => 'claude-haiku-4-5',
				'claude-3-5-haiku-latest'    => 'claude-haiku-4-5',
				'claude-3-haiku-20240307'    => 'claude-haiku-4-5',
				// Sonnet tier
				'claude-sonnet-4-20250514'   => 'claude-sonnet-5',
				'claude-sonnet-4-0'          => 'claude-sonnet-5',
				'claude-sonnet-4-5'          => 'claude-sonnet-5',
				'claude-sonnet-4-5-20250929' => 'claude-sonnet-5',
				'claude-sonnet-4-6'          => 'claude-sonnet-5',
				'claude-3-7-sonnet-20250219' => 'claude-sonnet-5',
				'claude-3-7-sonnet-latest'   => 'claude-sonnet-5',
				'claude-3-5-sonnet-20241022' => 'claude-sonnet-5',
				'claude-3-5-sonnet-latest'   => 'claude-sonnet-5',
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
		}

		global $wpdb;
		$key_like    = $wpdb->esc_like( '_transient_rnrd_models_' ) . '%';
		$timeout_like = $wpdb->esc_like( '_transient_timeout_rnrd_models_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $key_like, $timeout_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall/upgrade sweep for legacy DB transients
	}

	/**
	 * Keep the saved model visible. When a live remote list is active and the
	 * saved ID is absent, mark it deprecated so the user knows to switch.
	 *
	 * @param array<string,string> $models
	 */
	private static function ensure_saved_in_list( string $provider, array $models, bool $remote_active ): array {
		$saved = self::get_model( $provider );
		if ( '' === $saved || isset( $models[ $saved ] ) ) {
			return $models;
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

		$ids = self::extract_model_ids( $provider, $json );
		return array(
			'models' => self::filter_model_ids( $ids, self::provider_model_rules( $provider ) ),
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
		$ids = array();
		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
				$ids = array( 'gpt-5.4-nano', 'gpt-5.4-mini', 'gpt-5.4', 'gpt-5.5', 'gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-6-astra' );
				break;
			case self::PROVIDER_ANTHROPIC:
				$ids = array( 'claude-haiku-4-5', 'claude-sonnet-5', 'claude-opus-5-5', 'claude-fable-5-1' );
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
	 * Builds the standardized success response shape.
	 */
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
			sprintf(
				/* translators: 1: provider stop reason. */
				__( 'The model ran out of output length and stopped mid-answer (stop reason: %1$s). Nothing was saved, because a half-finished answer would have been published as though it were complete. Shorten the target length for this content type, or pick a model with more output room, and generate again.', 'rankready-ai-llm-seo' ),
				'' === $reason ? 'length' : $reason
			),
			$model
		);
	}

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
