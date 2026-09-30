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

		$response = wp_remote_post( $endpoint, array(
			'timeout'    => (int) $opts['timeout'],
			'user-agent' => 'RankReady/' . RNRD_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'    => array(
				'Content-Type'    => 'application/json',
				// Header auth, not ?key= — see the class docblock.
				'x-goog-api-key' => $api_key,
			),
			// Send non-Latin content as real UTF-8 bytes — see openai client.
			'body'       => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		) );

		if ( is_wp_error( $response ) ) {
			return RNRD_LLM::error_response( $provider, 'Gemini HTTP error: ' . $response->get_error_message(), $model );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http ) {
			$err = wp_remote_retrieve_body( $response );
			return RNRD_LLM::error_response( $provider, 'Gemini HTTP ' . $http . ': ' . mb_substr( (string) $err, 0, 300 ), $model );
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

		if ( '' === $content ) {
			$finish = isset( $decoded['candidates'][0]['finishReason'] ) ? (string) $decoded['candidates'][0]['finishReason'] : 'unknown';
			return RNRD_LLM::error_response( $provider, 'Gemini returned empty content (finishReason: ' . $finish . ').', $model );
		}

		// Cut off at MAX_TOKENS, not finished. See RNRD_LLM::is_truncated_reason().
		$finish_reason = isset( $decoded['candidates'][0]['finishReason'] ) ? (string) $decoded['candidates'][0]['finishReason'] : '';
		if ( RNRD_LLM::is_truncated_reason( $finish_reason ) ) {
			return RNRD_LLM::truncated_response( $provider, $model, $finish_reason );
		}

		$tokens_in  = isset( $decoded['usageMetadata']['promptTokenCount'] ) ? (int) $decoded['usageMetadata']['promptTokenCount'] : 0;
		$tokens_out = isset( $decoded['usageMetadata']['candidatesTokenCount'] ) ? (int) $decoded['usageMetadata']['candidatesTokenCount'] : 0;

		return RNRD_LLM::success_response( $provider, $model, $content, $tokens_in, $tokens_out );
	}
}
