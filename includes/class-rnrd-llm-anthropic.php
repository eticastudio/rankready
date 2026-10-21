<?php
/**
 * RankReady — Anthropic (Claude) provider.
 *
 * POST https://api.anthropic.com/v1/messages
 * Headers: x-api-key, anthropic-version: 2023-06-01, content-type
 *
 * Notes vs OpenAI:
 *   - System prompt is a top-level `system` field (not a message role).
 *   - max_tokens is required, not optional.
 *   - No native JSON mode — when $opts['json'] is true we append a strict
 *     JSON-only instruction to the system prompt and then defensively
 *     extract the JSON object from the response (handles ``` fences and
 *     pre/post chatter).
 *   - Token usage in `usage.input_tokens` / `usage.output_tokens`.
 *
 * @package RankReady
 */
defined( 'ABSPATH' ) || exit;

class RNRD_LLM_Anthropic {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	const VERSION  = '2023-06-01';

	public static function generate( string $system, string $user, array $opts ): array {
		$provider = RNRD_LLM::PROVIDER_ANTHROPIC;
		$model    = RNRD_LLM::get_model( $provider );
		$api_key  = RNRD_LLM::get_api_key( $provider );

		// Claude has no native JSON mode — bias toward JSON-only output via
		// system prompt when caller asks for json.
		if ( ! empty( $opts['json'] ) ) {
			$system .= "\n\nCRITICAL: Return ONLY a single valid JSON object. No prose, no markdown, no code fences. Start your response with { and end with }.";
		}

		$body = array(
			'model'      => $model,
			'system'     => $system,
			'max_tokens' => (int) $opts['max_tokens'],
			'messages'   => array(
				array( 'role' => 'user', 'content' => $user ),
			),
		);

		/*
		 * `temperature` is sent only to models that accept a non-default value.
		 *
		 * Anthropic's current generation rejects it outright: on Haiku 5.5,
		 * Sonnet 5.5, Opus 5.5, Sonnet 5, Opus 5 and every Fable/Mythos model,
		 * "a request that includes `temperature` must set it to 1 ... any other
		 * value returns a 400 error", per the Haiku 5.5 migration guide
		 * (docs.claude.com, read 2026-10-20). Haiku 4.5 and the 4.x line still
		 * accept it. Because this client hardcoded 0.2, every generation on a
		 * current model failed with a 400 — the whole provider was unusable the
		 * moment a user picked a model newer than the saved default, which is
		 * exactly what the dropdown offers once a key is set.
		 *
		 * Rather than carry a model list that goes stale on Anthropic's next
		 * release — the failure this file's own migration map was rewritten to
		 * undo — the param is dropped for any model already known to reject it
		 * and the knowledge is learned from the API itself, below. Models that
		 * accept it keep the 0.2 they have always been sent, so output on an
		 * existing install does not change.
		 */
		$omit_sampling = self::model_rejects_sampling( $model );
		if ( ! $omit_sampling ) {
			$body['temperature'] = (float) $opts['temperature'];
		}

		$response = self::request( $api_key, $body, (int) $opts['timeout'] );

		if ( is_wp_error( $response ) ) {
			return RNRD_LLM::error_response( $provider, 'Anthropic HTTP error: ' . $response->get_error_message(), $model );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		/*
		 * Self-heal, same shape as the OpenAI client's sampling-param retry: a
		 * 400 that names a sampling parameter is retried once without it, so a
		 * model Anthropic ships after this release still works on day one.
		 *
		 * The model is remembered only when the retry SUCCEEDS. The live 400,
		 * captured against api.anthropic.com on 2026-10-20, reads
		 * `` `temperature` is deprecated for this model. `` — the parameter name
		 * is the only part of it that is predictable, so the test above matches on
		 * that alone and will occasionally fire on an unrelated 400 that merely
		 * mentions a sampling parameter. Remembering on the failure path would
		 * then drop temperature for a week on a model that accepts it, quietly
		 * changing output for a reason nobody could trace. Gating the write on a
		 * 200 means the flag only ever records something the API actually proved,
		 * and the cost of a wrong guess stays at one request.
		 */
		if ( 400 === $http && ! $omit_sampling && self::is_unsupported_param_error( $raw ) ) {
			/*
			 * The retry is a second HTTP request inside this one call. The Pro
			 * bulk runner has already dequeued this post and persisted the
			 * shortened queue, so running out of execution time here does not
			 * retry the post, it loses it. retry_timeout() therefore gives the
			 * retry only the room that is actually left, so it cannot overrun
			 * the execution limit, and returns 0 when there is too little to be
			 * worth trying. See RNRD_LLM::retry_timeout(): asking for room for
			 * the FULL timeout instead, as this did first, skipped the retry on
			 * every bulk post after the first and so never learned the flag.
			 */
			$retry_timeout = RNRD_LLM::retry_timeout( (int) $opts['timeout'] );
			if ( 0 === $retry_timeout ) {
				return RNRD_LLM::error_response( $provider, 'Anthropic HTTP ' . $http . ': ' . mb_substr( $raw, 0, 300 ), $model );
			}

			unset( $body['temperature'] );

			$response = self::request( $api_key, $body, $retry_timeout );
			if ( is_wp_error( $response ) ) {
				return RNRD_LLM::error_response( $provider, 'Anthropic HTTP error: ' . $response->get_error_message(), $model );
			}
			$http = (int) wp_remote_retrieve_response_code( $response );
			$raw  = (string) wp_remote_retrieve_body( $response );

			// A bulk run of 500 posts now pays the 400 once, not 500 times.
			if ( 200 === $http ) {
				self::remember_rejects_sampling( $model );
			}
		}

		if ( 200 !== $http ) {
			return RNRD_LLM::error_response( $provider, 'Anthropic HTTP ' . $http . ': ' . mb_substr( $raw, 0, 300 ), $model );
		}

		$decoded = json_decode( $raw, true );

		// Claude returns content as an array of typed blocks. Extract text.
		$content = '';
		if ( isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
			foreach ( $decoded['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$content .= $block['text'];
				}
			}
		}
		$content = trim( $content );

		/*
		 * Cut off at max_tokens, not finished. See RNRD_LLM::is_truncated_reason().
		 * Checked before the empty test for the reason spelled out in the
		 * DeepSeek client: a model that thinks before answering can spend the
		 * whole cap on thinking and come back with stop_reason=max_tokens and
		 * no text at all. "Truncated, shorten the target or raise the cap" is
		 * something the user can act on; "empty content" is not.
		 */
		$stop = isset( $decoded['stop_reason'] ) ? (string) $decoded['stop_reason'] : '';
		if ( RNRD_LLM::is_truncated_reason( $stop ) ) {
			return RNRD_LLM::truncated_response( $provider, $model, $stop );
		}

		if ( '' === $content ) {
			return RNRD_LLM::error_response( $provider, 'Anthropic returned empty content.', $model );
		}

		// Defensive: when JSON was requested but the model wrapped output in
		// ```json fences, strip them. Also pull just the {...} block if the
		// model added prose around it.
		if ( ! empty( $opts['json'] ) ) {
			$content = self::extract_json_object( $content );
		}

		$tokens_in  = isset( $decoded['usage']['input_tokens'] ) ? (int) $decoded['usage']['input_tokens'] : 0;
		$tokens_out = isset( $decoded['usage']['output_tokens'] ) ? (int) $decoded['usage']['output_tokens'] : 0;

		return RNRD_LLM::success_response( $provider, $model, $content, $tokens_in, $tokens_out );
	}

	/**
	 * POST one message request. Shared by the first attempt and the retry so
	 * the two cannot drift in headers, user-agent or JSON flags.
	 *
	 * @return array|WP_Error
	 */
	private static function request( string $api_key, array $body, int $timeout ) {
		return wp_remote_post( self::ENDPOINT, array(
			'timeout'    => $timeout,
			'user-agent' => 'RankReady/' . RNRD_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'    => array(
				'x-api-key'         => $api_key,
				'anthropic-version' => self::VERSION,
				'content-type'      => 'application/json',
			),
			// Send non-Latin content as real UTF-8 bytes — see openai client.
			'body' => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		) );
	}

	/**
	 * Transient name recording that one model ID rejects sampling parameters.
	 * Keyed by a hash of the model so an arbitrary ID cannot build an invalid
	 * or over-long option name.
	 */
	private static function sampling_flag_key( string $model ): string {
		return RNRD_LLM::sampling_flag_key( RNRD_LLM::PROVIDER_ANTHROPIC, $model );
	}

	/**
	 * Whether this model already answered a sampling parameter with a 400.
	 * Cached for a week: a model's parameter contract does not change under a
	 * fixed ID, and a wrong answer self-corrects — a false positive only means
	 * the default temperature is used, a false negative costs one retry.
	 */
	private static function model_rejects_sampling( string $model ): bool {
		return (bool) get_transient( self::sampling_flag_key( $model ) );
	}

	/**
	 * Record that this model rejects sampling parameters.
	 */
	private static function remember_rejects_sampling( string $model ): void {
		set_transient( self::sampling_flag_key( $model ), 1, 7 * DAY_IN_SECONDS );
	}

	/**
	 * Whether an Anthropic 400 body is a complaint about a sampling parameter.
	 *
	 * `temperature` is the only sampling parameter this client ever sends, so a
	 * 400 naming it is by construction a complaint about it, whatever the
	 * wording. The name match alone is therefore the signal; the phrase list
	 * below is kept so a body that names the parameter in passing while failing
	 * for an unrelated reason still reads as a parameter rejection rather than
	 * being missed. `top_p` and `top_k` are matched too: they are rejected by
	 * the same models and a future caller may add them.
	 *
	 * @param string $raw Raw response body.
	 */
	private static function is_unsupported_param_error( string $raw ): bool {
		// Word-bounded, not a bare substring, so a JSON path in an error body
		// still matches: `.` is not a word character, which is what keeps
		// `.top_p` a hit.
		//
		// `topp` and `topk` are Gemini's spellings and are deliberately NOT in
		// this alternation. This client does not send them, and `topp` is a
		// substring of "stopped", so matching it here would read any error about
		// a stopped stream as a sampling rejection and burn a retry on an
		// identical body. Only the Gemini client carries those two
		// alternatives, because only it sends them, and the same word
		// boundaries keep "stopped" out there too.
		//
		// Measured 2026-10-20, so the comment cannot drift from the pattern:
		// this one does NOT match `generationconfig.topp`, and does match
		// `.top_p`. Anthropic, DeepSeek and Gemini share this word-bounded
		// shape; OpenAI needs a different one, because `temperature` alone is
		// too common in its bodies to decide on.
		return 1 === preg_match( '/\\b(?:temperature|top_p|top_k)\\b/', strtolower( $raw ) );
	}

	/**
	 * Extracts the first balanced JSON object from a string. Strips ``` fences
	 * and leading/trailing prose. Falls back to the original string if no
	 * JSON object can be located (caller's JSON parser will then error
	 * cleanly, same as today).
	 */
	private static function extract_json_object( string $text ): string {
		// Strip code fences (```json … ``` or ``` … ```).
		$text = preg_replace( '/^```(?:json)?\s*\n?/i', '', $text );
		$text = preg_replace( '/\n?```\s*$/i', '', $text );

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return trim( $text );
		}
		return trim( substr( $text, $start, $end - $start + 1 ) );
	}
}
