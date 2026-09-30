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

		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout'    => (int) $opts['timeout'],
			'user-agent' => 'RankReady/' . RNRD_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'    => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			// Send non-Latin content as real UTF-8 bytes — see openai client.
			'body' => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		) );

		if ( is_wp_error( $response ) ) {
			return RNRD_LLM::error_response( $provider, 'DeepSeek HTTP error: ' . $response->get_error_message(), $model );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http ) {
			$err = wp_remote_retrieve_body( $response );
			return RNRD_LLM::error_response( $provider, 'DeepSeek HTTP ' . $http . ': ' . mb_substr( (string) $err, 0, 300 ), $model );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$content = isset( $decoded['choices'][0]['message']['content'] ) ? trim( (string) $decoded['choices'][0]['message']['content'] ) : '';

		if ( '' === $content ) {
			return RNRD_LLM::error_response( $provider, 'DeepSeek returned empty content.', $model );
		}

		// Cut off at the token cap, not finished. See RNRD_LLM::is_truncated_reason().
		$finish = isset( $decoded['choices'][0]['finish_reason'] ) ? (string) $decoded['choices'][0]['finish_reason'] : '';
		if ( RNRD_LLM::is_truncated_reason( $finish ) ) {
			return RNRD_LLM::truncated_response( $provider, $model, $finish );
		}

		$tokens_in  = isset( $decoded['usage']['prompt_tokens'] ) ? (int) $decoded['usage']['prompt_tokens'] : 0;
		$tokens_out = isset( $decoded['usage']['completion_tokens'] ) ? (int) $decoded['usage']['completion_tokens'] : 0;

		return RNRD_LLM::success_response( $provider, $model, $content, $tokens_in, $tokens_out );
	}
}
