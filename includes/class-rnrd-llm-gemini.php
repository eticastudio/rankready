<?php
/**
 * RankReady — Google Gemini provider.
 *
 * POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 * Auth: x-goog-api-key header. Never ?key= — a query string is logged by every
 * proxy, WAF and access log on the path, and lands in cURL error text.
 *
 * Notes vs OpenAI:
 *   - API key goes in the x-goog-api-key header, not Authorization: Bearer.
 *   - System prompt goes in `systemInstruction.parts[].text`.
 *   - User content is in `contents[].parts[].text` with role: "user".
 *   - Native JSON mode supported via `generationConfig.responseMimeType:
 *     "application/json"`.
 *   - Token usage in `usageMetadata.promptTokenCount` /
 *     `usageMetadata.candidatesTokenCount`.
 *
 * @package RankReady
 */
defined( 'ABSPATH' ) || exit;

class RNRD_LLM_Gemini {

	const ENDPOINT_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	public static function generate( string $system, string $user, array $opts ): array {
		$provider = RNRD_LLM::PROVIDER_GEMINI;
		$model    = RNRD_LLM::get_model( $provider );
		$api_key  = RNRD_LLM::get_api_key( $provider );

		$generation_config = array(
			'temperature'     => (float) $opts['temperature'],
			'maxOutputTokens' => (int) $opts['max_tokens'],
		);
		if ( ! empty( $opts['json'] ) ) {
			$generation_config['responseMimeType'] = 'application/json';
		}

		$body = array(
			'systemInstruction' => array(
				'parts' => array( array( 'text' => $system ) ),
			),
			'contents' => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $user ) ),
				),
			),
			'generationConfig' => $generation_config,
		);

		$endpoint = sprintf( self::ENDPOINT_TEMPLATE, rawurlencode( $model ) );

		/*
		 * `temperature` is dropped for any model already known to reject it,
		 * and the first 400 that names it teaches this install which those are.
		 *
		 * Gemini accepts a non-default temperature on every current model, so
		 * nothing here fires today — the reference's own samples pass
		 * `temperature=1` in generationConfig (ai.google.dev/api, read
		 * 2026-10-20). This is carried anyway because the Anthropic client had
		 * no such guard and that is precisely how it broke: Anthropic's current
		 * generation started returning a 400 for a non-default temperature, the
		 * hardcoded 0.2 then failed every single generation, and the error
		 * mapper blamed the model, so the user cycled through models that all
		 * failed for the same reason. A provider changing a parameter contract
		 * after a plugin release is not a thing a shipped version can predict,
		 * and this plugin's update sits behind a WP.org review. Costing a 200
		 * nothing and a bad release nothing is worth the few lines.
		 */
		$omit_sampling = self::model_rejects_sampling( $model );
		if ( $omit_sampling ) {
			unset( $body['generationConfig']['temperature'] );
		}

		$response = self::request( $endpoint, $api_key, $body, (int) $opts['timeout'] );

		if ( is_wp_error( $response ) ) {
			return RNRD_LLM::error_response( $provider, 'Gemini HTTP error: ' . $response->get_error_message(), $model );
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
				return RNRD_LLM::error_response( $provider, 'Gemini HTTP ' . $http . ': ' . mb_substr( $raw, 0, 300 ), $model );
			}

			unset( $body['generationConfig']['temperature'] );

			$response = self::request( $endpoint, $api_key, $body, $retry_timeout );
			if ( is_wp_error( $response ) ) {
				return RNRD_LLM::error_response( $provider, 'Gemini HTTP error: ' . $response->get_error_message(), $model );
			}
			$http = (int) wp_remote_retrieve_response_code( $response );
			$raw  = (string) wp_remote_retrieve_body( $response );

			// Only a 200 proves it, so only a 200 is remembered. A bulk run
			// then pays the 400 once instead of once per post.
			if ( 200 === $http ) {
				self::remember_rejects_sampling( $model );
			}
		}

		if ( 200 !== $http ) {
			return RNRD_LLM::error_response( $provider, 'Gemini HTTP ' . $http . ': ' . mb_substr( $raw, 0, 300 ), $model );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		// Gemini may finish with safety blocks → no content.
		if ( isset( $decoded['promptFeedback']['blockReason'] ) ) {
			return RNRD_LLM::error_response( $provider, 'Gemini blocked the request: ' . (string) $decoded['promptFeedback']['blockReason'], $model );
		}

		$content = '';
		if ( isset( $decoded['candidates'][0]['content']['parts'] ) && is_array( $decoded['candidates'][0]['content']['parts'] ) ) {
			foreach ( $decoded['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$content .= (string) $part['text'];
				}
			}
		}
		$content = trim( $content );

		/*
		 * Cut off at MAX_TOKENS, not finished. See RNRD_LLM::is_truncated_reason().
		 * Checked before the empty test, same reason as the other three
		 * clients: Gemini's thinking models report MAX_TOKENS with an empty
		 * parts array, and the truncation message is the one that tells the
		 * user what to change.
		 */
		$finish_reason = isset( $decoded['candidates'][0]['finishReason'] ) ? (string) $decoded['candidates'][0]['finishReason'] : '';
		if ( RNRD_LLM::is_truncated_reason( $finish_reason ) ) {
			return RNRD_LLM::truncated_response( $provider, $model, $finish_reason );
		}

		if ( '' === $content ) {
			$finish = '' === $finish_reason ? 'unknown' : $finish_reason;
			return RNRD_LLM::error_response( $provider, 'Gemini returned empty content (finishReason: ' . $finish . ').', $model );
		}

		$tokens_in  = isset( $decoded['usageMetadata']['promptTokenCount'] ) ? (int) $decoded['usageMetadata']['promptTokenCount'] : 0;
		$tokens_out = isset( $decoded['usageMetadata']['candidatesTokenCount'] ) ? (int) $decoded['usageMetadata']['candidatesTokenCount'] : 0;

		return RNRD_LLM::success_response( $provider, $model, $content, $tokens_in, $tokens_out );
	}

	private static function request( string $endpoint, string $api_key, array $body, int $timeout ) {
		return wp_remote_post( $endpoint, array(
			'timeout'    => $timeout,
			'user-agent' => 'RankReady/' . RNRD_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'    => array(
				'Content-Type'    => 'application/json',
				// Header auth, not ?key= — see the class docblock.
				'x-goog-api-key' => $api_key,
			),
			// Send non-Latin content as real UTF-8 bytes — see openai client.
			'body'       => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		) );
	}

	/**
	 * Keyed by model hash, so one model's answer never speaks for another and
	 * a long model ID cannot overflow the option name.
	 */
	private static function sampling_flag_key( string $model ): string {
		return RNRD_LLM::sampling_flag_key( RNRD_LLM::PROVIDER_GEMINI, $model );
	}

	private static function model_rejects_sampling( string $model ): bool {
		return (bool) get_transient( self::sampling_flag_key( $model ) );
	}

	private static function remember_rejects_sampling( string $model ): void {
		set_transient( self::sampling_flag_key( $model ), 1, 7 * DAY_IN_SECONDS );
	}

	/**
	 * True when a 400 body names a sampling parameter. Deliberately loose:
	 * a provider that starts rejecting one will not phrase it the way this
	 * file guessed. The cost of a wrong match is one extra request, and the
	 * learned flag is only written when the retry actually returns 200.
	 */
	private static function is_unsupported_param_error( string $raw ): bool {
		// Word-bounded, not a bare substring. This client's parameters lowercase
		// to `topp` and `topk`, and `topp` is a substring of "stopped", so a
		// plain strpos() treated any error mentioning a stopped stream as a
		// sampling rejection and burned a retry on an identical body. The
		// boundaries keep `generationconfig.topp` and `.top_p` matching, since
		// `.` is not a word character, while "stopped" stays out.
		//
		// Anthropic and DeepSeek share this shape but omit `topp` and `topk`,
		// which they never send. OpenAI needs a different shape entirely,
		// because `temperature` alone is too common in its bodies to decide on.
		return 1 === preg_match( '/\\b(?:temperature|top_p|topp|top_k|topk)\\b/', strtolower( $raw ) );
	}
}
