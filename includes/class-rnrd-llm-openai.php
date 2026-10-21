<?php
/**
 * RankReady — OpenAI provider.
 *
 * Implements the RNRD_LLM provider contract for the OpenAI Chat Completions
 * API (`POST https://api.openai.com/v1/chat/completions`).
 *
 * Native JSON mode supported via `response_format: { type: "json_object" }`.
 *
 * @package RankReady
 */
defined( 'ABSPATH' ) || exit;

class RNRD_LLM_OpenAI {

	const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	public static function generate( string $system, string $user, array $opts ): array {
		$provider = RNRD_LLM::PROVIDER_OPENAI;
		$model    = RNRD_LLM::get_model( $provider );
		$api_key  = RNRD_LLM::get_api_key( $provider );

		$body = array(
			'model'                 => $model,
			'messages'              => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user',   'content' => $user ),
			),
			'max_completion_tokens' => (int) $opts['max_tokens'], // GPT-5.x rejects 'max_tokens'; Chat Completions requires 'max_completion_tokens'.
		);

		/*
		 * Skip the sampling params for a model already known to reject them.
		 * Without this the retry below is correct but unremembered, so a model
		 * that always rejects them paid the 400 on EVERY generation: a bulk run
		 * of 500 posts made 1,000 requests and doubled both the latency and the
		 * wasted calls. The other three clients learn this; this one did not.
		 */
		$omit_sampling = self::model_rejects_sampling( $model );
		if ( ! $omit_sampling ) {
			$body['temperature']       = (float) $opts['temperature'];
			$body['frequency_penalty'] = 0.3;
		}

		if ( ! empty( $opts['json'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$timeout  = (int) $opts['timeout'];
		$response = self::request( $api_key, $body, $timeout );
		if ( is_wp_error( $response ) ) {
			return RNRD_LLM::error_response( $provider, 'OpenAI HTTP error: ' . $response->get_error_message(), $model );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		// Self-heal the SAME class as the max_tokens bug: some models (notably
		// GPT-5.x reasoning variants) reject a non-default 'temperature' or
		// 'frequency_penalty' with a 400. If that is why the call failed, retry
		// ONCE with those sampling params dropped — keeping 'max_completion_tokens'
		// and JSON mode, which those models accept. Models that accept the params
		// (every curated chat model today) succeed on the first request, unchanged.
		/*
		 * Three conditions, all required. $omit_sampling: the params were never
		 * added, so unset() is a no-op and the retry would be a byte-identical
		 * request, paid for twice. retry_timeout(): the retry gets only the room
		 * actually left under max_execution_time, and 0 means there is too
		 * little to be worth it, so the real 400 is returned instead of losing
		 * the post. The other three clients carry the same two guards.
		 */
		$retry_timeout = $omit_sampling ? 0 : RNRD_LLM::retry_timeout( $timeout );
		if ( 400 === $http && $retry_timeout > 0 && self::is_unsupported_param_error( $raw ) ) {
			unset( $body['temperature'], $body['frequency_penalty'] );
			$response = self::request( $api_key, $body, $retry_timeout );
			if ( is_wp_error( $response ) ) {
				return RNRD_LLM::error_response( $provider, 'OpenAI HTTP error: ' . $response->get_error_message(), $model );
			}
			$http = (int) wp_remote_retrieve_response_code( $response );
			$raw  = (string) wp_remote_retrieve_body( $response );

			// Learned only on a 200. A retry that fails for some other reason
			// proves nothing about the parameters, so nothing is recorded.
			if ( 200 === $http ) {
				self::remember_rejects_sampling( $model );
			}
		}

		if ( 200 !== $http ) {
			return RNRD_LLM::error_response( $provider, 'OpenAI HTTP ' . $http . ': ' . mb_substr( $raw, 0, 300 ), $model );
		}

		$decoded = json_decode( $raw, true );
		$content = isset( $decoded['choices'][0]['message']['content'] ) ? trim( (string) $decoded['choices'][0]['message']['content'] ) : '';

		// Cut off at the token cap, not finished. See RNRD_LLM::is_truncated_reason().
		// Checked before the empty test: a reasoning model can spend the whole cap
		// thinking and return no content with finish_reason=length, and "truncated"
		// is the actionable answer, "empty" is not.
		$finish = isset( $decoded['choices'][0]['finish_reason'] ) ? (string) $decoded['choices'][0]['finish_reason'] : '';
		if ( RNRD_LLM::is_truncated_reason( $finish ) ) {
			return RNRD_LLM::truncated_response( $provider, $model, $finish );
		}

		if ( '' === $content ) {
			return RNRD_LLM::error_response( $provider, 'OpenAI returned empty content.', $model );
		}

		$tokens_in  = isset( $decoded['usage']['prompt_tokens'] ) ? (int) $decoded['usage']['prompt_tokens'] : 0;
		$tokens_out = isset( $decoded['usage']['completion_tokens'] ) ? (int) $decoded['usage']['completion_tokens'] : 0;

		return RNRD_LLM::success_response( $provider, $model, $content, $tokens_in, $tokens_out );
	}

	/**
	 * POST a chat-completions body to OpenAI. Returns the raw wp_remote_post()
	 * result (response array or WP_Error) so the caller can inspect status + body.
	 *
	 * @param string $api_key OpenAI API key.
	 * @param array  $body    Request body.
	 * @param int    $timeout Request timeout in seconds.
	 * @return array|WP_Error
	 */
	private static function request( string $api_key, array $body, int $timeout ) {
		return wp_remote_post( self::ENDPOINT, array(
			'timeout'    => $timeout,
			'user-agent' => 'RankReady/' . RNRD_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'    => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			// Send non-Latin content (Turkish/CJK/Arabic/Hindi/Cyrillic) as real
			// UTF-8 bytes rather than \uXXXX escapes — smaller on the wire and
			// removes a class of edge-case escape bugs.
			'body' => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		) );
	}

	/**
	 * Transient name recording that one model ID rejects sampling parameters.
	 * Keyed by a hash of the model so an arbitrary ID cannot build an invalid
	 * or over-long option name.
	 */
	private static function sampling_flag_key( string $model ): string {
		return RNRD_LLM::sampling_flag_key( RNRD_LLM::PROVIDER_OPENAI, $model );
	}

	/**
	 * Whether this model already answered a sampling parameter with a 400.
	 * Cached for a week: a model's parameter contract does not change under a
	 * fixed ID, and a wrong answer self-corrects. A false positive only means
	 * the provider default is used, a false negative costs one retry.
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
	 * Whether an OpenAI 400 body indicates a rejected sampling parameter
	 * ('temperature' / 'frequency_penalty'). Matches the model-rejects-param
	 * class so the caller can retry without them instead of failing outright.
	 *
	 * @param string $raw Raw response body.
	 * @return bool
	 */
	private static function is_unsupported_param_error( string $raw ): bool {
		$raw = strtolower( $raw );

		// Must reference one of the sampling params we would drop.
		if ( false === strpos( $raw, 'temperature' ) && false === strpos( $raw, 'frequency_penalty' ) ) {
			return false;
		}

		// ...and read like an "unsupported / not-allowed value" error.
		foreach ( array( 'unsupported', 'does not support', 'not supported', 'only the default', 'unsupported_value', 'unsupported_parameter' ) as $needle ) {
			if ( false !== strpos( $raw, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
