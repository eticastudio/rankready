<?php
/**
 * RankReady — DeepSeek provider.
 *
 * POST https://api.deepseek.com/v1/chat/completions
 *
 * DeepSeek's API is OpenAI-compatible — same Authorization header, same
 * messages array shape, same usage metadata. Only the endpoint differs.
 * Native JSON output via `response_format: { type: "json_object" }`.
 *
 * @package RankReady
 */
defined( 'ABSPATH' ) || exit;

class RNRD_LLM_DeepSeek {

	const ENDPOINT = 'https://api.deepseek.com/v1/chat/completions';

	public static function generate( string $system, string $user, array $opts ): array {
		$provider = RNRD_LLM::PROVIDER_DEEPSEEK;
		$model    = RNRD_LLM::get_model( $provider );
		$api_key  = RNRD_LLM::get_api_key( $provider );

		$body = array(
			'model'       => $model,
			'messages'    => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user',   'content' => $user ),
			),
			'max_tokens'  => (int) $opts['max_tokens'],
			'temperature' => (float) $opts['temperature'],
		);

		if ( ! empty( $opts['json'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		/*
		 * Same sampling-parameter guard the Anthropic and Gemini clients
		 * carry. DeepSeek documents `temperature` and accepts it on every
		 * current model, so this does not fire today; it is here because the
		 * Anthropic client's missing guard turned a provider-side contract
		 * change into every generation failing with an error that blamed the
		 * model instead of the parameter. See that client for the incident.
		 */
		$omit_sampling = self::model_rejects_sampling( $model );
		if ( $omit_sampling ) {
			unset( $body['temperature'] );
		}

		$response = self::request( $api_key, $body, (int) $opts['timeout'] );

		if ( is_wp_error( $response ) ) {
			return RNRD_LLM::error_response( $provider, 'DeepSeek HTTP error: ' . $response->get_error_message(), $model );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

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
				return RNRD_LLM::error_response( $provider, 'DeepSeek HTTP ' . $http . ': ' . mb_substr( $raw, 0, 300 ), $model );
			}

			unset( $body['temperature'] );

			$response = self::request( $api_key, $body, $retry_timeout );
			if ( is_wp_error( $response ) ) {
				return RNRD_LLM::error_response( $provider, 'DeepSeek HTTP error: ' . $response->get_error_message(), $model );
			}
			$http = (int) wp_remote_retrieve_response_code( $response );
			$raw  = (string) wp_remote_retrieve_body( $response );

			// Only a 200 proves it, so only a 200 is remembered.
			if ( 200 === $http ) {
				self::remember_rejects_sampling( $model );
			}
		}

		if ( 200 !== $http ) {
			return RNRD_LLM::error_response( $provider, 'DeepSeek HTTP ' . $http . ': ' . mb_substr( $raw, 0, 300 ), $model );
		}

		$decoded = json_decode( $raw, true );
		$content = isset( $decoded['choices'][0]['message']['content'] ) ? trim( (string) $decoded['choices'][0]['message']['content'] ) : '';

		/*
		 * Cut off at the token cap, not finished. See RNRD_LLM::is_truncated_reason().
		 *
		 * Checked BEFORE the empty test, and that order is the whole point.
		 * `deepseek-flash` is a reasoning model whose reasoning tokens are
		 * billed against max_tokens, so a tight cap comes back as
		 * finish_reason=length with content "" and the entire answer sitting in
		 * `reasoning_content`. Verified live on 2026-10-20: max_tokens 64 ->
		 * 64 reasoning tokens, 0 content. With the empty test first, the user
		 * was told "DeepSeek returned an unexpected response. Try again in a
		 * moment.", which is wrong twice over: nothing was unexpected, and
		 * trying again cannot help. Truncation is the actionable answer.
		 */
		$finish = isset( $decoded['choices'][0]['finish_reason'] ) ? (string) $decoded['choices'][0]['finish_reason'] : '';
		if ( RNRD_LLM::is_truncated_reason( $finish ) ) {
			return RNRD_LLM::truncated_response( $provider, $model, $finish );
		}

		if ( '' === $content ) {
			return RNRD_LLM::error_response( $provider, 'DeepSeek returned empty content.', $model );
		}

		$tokens_in  = isset( $decoded['usage']['prompt_tokens'] ) ? (int) $decoded['usage']['prompt_tokens'] : 0;
		$tokens_out = isset( $decoded['usage']['completion_tokens'] ) ? (int) $decoded['usage']['completion_tokens'] : 0;

		return RNRD_LLM::success_response( $provider, $model, $content, $tokens_in, $tokens_out );
	}

	private static function request( string $api_key, array $body, int $timeout ) {
		return wp_remote_post( self::ENDPOINT, array(
			'timeout'    => $timeout,
			'user-agent' => 'RankReady/' . RNRD_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'    => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			// Send non-Latin content as real UTF-8 bytes — see openai client.
			'body' => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		) );
	}

	/**
	 * Keyed by model hash, so one model's answer never speaks for another and
	 * a long model ID cannot overflow the option name.
	 */
	private static function sampling_flag_key( string $model ): string {
		return RNRD_LLM::sampling_flag_key( RNRD_LLM::PROVIDER_DEEPSEEK, $model );
	}

	private static function model_rejects_sampling( string $model ): bool {
		return (bool) get_transient( self::sampling_flag_key( $model ) );
	}

	private static function remember_rejects_sampling( string $model ): void {
		set_transient( self::sampling_flag_key( $model ), 1, 7 * DAY_IN_SECONDS );
	}

	/**
	 * True when a 400 body names a sampling parameter. Deliberately loose; the
	 * learned flag is only written when the retry actually returns 200, so a
	 * wrong match costs one request and nothing else.
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
}
