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
			'model'       => $model,
			'system'      => $system,
			'max_tokens'  => (int) $opts['max_tokens'],
			'temperature' => (float) $opts['temperature'],
			'messages'    => array(
				array( 'role' => 'user', 'content' => $user ),
			),
		);

		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout'    => (int) $opts['timeout'],
			'user-agent' => 'RankReady/' . RNRD_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'    => array(
				'x-api-key'         => $api_key,
				'anthropic-version' => self::VERSION,
				'content-type'      => 'application/json',
			),
			// Send non-Latin content as real UTF-8 bytes — see openai client.
			'body' => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		) );

		if ( is_wp_error( $response ) ) {
			return RNRD_LLM::error_response( $provider, 'Anthropic HTTP error: ' . $response->get_error_message(), $model );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http ) {
			$err = wp_remote_retrieve_body( $response );
			return RNRD_LLM::error_response( $provider, 'Anthropic HTTP ' . $http . ': ' . mb_substr( (string) $err, 0, 300 ), $model );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

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

		if ( '' === $content ) {
			return RNRD_LLM::error_response( $provider, 'Anthropic returned empty content.', $model );
		}

		// Cut off at max_tokens, not finished. See RNRD_LLM::is_truncated_reason().
		$stop = isset( $decoded['stop_reason'] ) ? (string) $decoded['stop_reason'] : '';
		if ( RNRD_LLM::is_truncated_reason( $stop ) ) {
			return RNRD_LLM::truncated_response( $provider, $model, $stop );
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
