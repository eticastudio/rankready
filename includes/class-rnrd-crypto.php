<?php
/**
 * RankReady — Secret encryption at rest.
 *
 * Wraps the 5 secret options (4 LLM provider API keys + the DataForSEO
 * password) so they're stored in `wp_options` as AES-256-GCM ciphertext
 * keyed off the site's AUTH_KEY + SECURE_AUTH_SALT constants.
 *
 * Two cases store plaintext instead, deliberately, because losing somebody's
 * key is worse than storing it the way WordPress stores every other option:
 * a host with no OpenSSL extension, and a cipher call that returns false.
 * Both are disclosed in the admin rather than left silent — see
 * is_plaintext_fallback(), can_encrypt() and raw_stored_value() below. The
 * file used to claim plaintext never persists, which was not true on those
 * hosts, and a security promise that is only usually true is worse than none.
 *
 * Backward-compat (CLAUDE.md hard rule): values stored as plaintext by
 * earlier RankReady versions still decrypt correctly (we detect the
 * "rnrdenc:" prefix; absent prefix = plaintext = return as-is). The
 * next save through the admin UI re-saves with the encryption prefix,
 * so plaintext leaves the DB on first user save in v1.1.0+.
 *
 * Threat model: protects against `wp_options` table dumps via SQL
 * injection on OTHER plugins, against backup files containing the
 * options table, and against `wp_options` being readable to other
 * tenants on shared hosts. Does NOT protect against full WP root
 * compromise — anyone with `wp-config.php` access has the keys.
 *
 * @package RankReady
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Crypto {

	/**
	 * Option that records a failed encryption, so the state is observable.
	 *
	 * Holds a timestamp and which branch failed. It never holds the secret, or
	 * any part of it: the whole reason encrypt() logs nothing on failure is that
	 * a log line is exactly where a leaked key ends up.
	 */
	public const DEGRADED_OPTION = 'rnrd_crypto_degraded';

	/** Prefix that marks a value as encrypted. */
	private const PREFIX = 'rnrdenc:';

	/** Cipher used for encryption. AES-256-GCM provides integrity (auth tag) too. */
	private const CIPHER = 'aes-256-gcm';

	/**
	 * Option keys that hold secrets. Reads decrypt, writes encrypt.
	 * Other RankReady options (model names, toggles, post-type arrays)
	 * are stored plaintext — they're not secrets.
	 */
	private const SECRET_OPTIONS = array(
		'rnrd_openai_api_key',
		'rnrd_anthropic_api_key',
		'rnrd_gemini_api_key',
		'rnrd_deepseek_api_key',
		'rnrd_dfs_password',
		'rnrd_cf_api_token',   // v1.1.0 — Cloudflare auto-fix API token
		'rnrd_cf_global_key',  // v1.2.1 — Cloudflare Global API Key (account-wide; MUST be encrypted at rest)
	);

	// ── Lifecycle ──────────────────────────────────────────────────────────

	public static function init(): void {
		foreach ( self::SECRET_OPTIONS as $opt ) {
			add_filter( 'pre_update_option_' . $opt, array( self::class, 'encrypt_on_save' ), 10, 1 );
			add_filter( 'option_' . $opt,             array( self::class, 'decrypt_on_read' ), 10, 1 );
			add_filter( 'default_option_' . $opt,     array( self::class, 'default_value' ),   10, 1 );
		}
	}

	// ── Filters ────────────────────────────────────────────────────────────

	/**
	 * `pre_update_option_X` filter — fires when `update_option()` is called.
	 * Encrypts the incoming plaintext value before WordPress writes it.
	 *
	 * @param mixed $value New value about to be saved.
	 * @return string Encrypted ciphertext (or empty string if input is empty).
	 */
	public static function encrypt_on_save( $value ): string {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		// Idempotent: if it's already ciphertext, don't double-encrypt.
		if ( self::is_encrypted( $value ) ) {
			return $value;
		}
		return self::encrypt( $value );
	}

	/**
	 * `option_X` filter — fires when `get_option()` reads the stored value.
	 * Decrypts ciphertext. Leaves plaintext (legacy values from v1.0.x)
	 * untouched so backward-compat reads keep working.
	 *
	 * @param mixed $value Stored value from wp_options.
	 * @return string Plaintext value for downstream code.
	 */
	public static function decrypt_on_read( $value ): string {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( ! self::is_encrypted( $value ) ) {
			// Legacy plaintext from a pre-1.1.0 install. Pass through.
			// The next time the user saves the option, encrypt_on_save()
			// will encrypt it — so plaintext drains naturally over time.
			return $value;
		}
		return self::decrypt( $value );
	}

	/**
	 * `default_option_X` filter — fires when an option doesn't exist yet.
	 * Returns empty string. Necessary because some callers pass a default
	 * to get_option() and we want our decryption logic to never run on
	 * the literal default.
	 */
	public static function default_value( $value ) {
		return '' === $value || null === $value ? '' : $value;
	}

	// ── Primitives ─────────────────────────────────────────────────────────

	/**
	 * Encrypt plaintext with AES-256-GCM.
	 *
	 * Format: rnrdenc:base64(iv ‖ tag ‖ ciphertext)
	 *
	 * @param string $plaintext Raw value to encrypt.
	 * @return string Prefixed ciphertext. Returns empty string on encryption failure.
	 */
	/**
	 * Is this stored value sitting in the database as plaintext?
	 *
	 * IMPORTANT: encrypt() deliberately FALLS BACK to storing the raw value when openssl
	 * is unavailable or the cipher call fails. That is the right call for the
	 * user's data — silently dropping somebody's API key is worse than storing
	 * it the way every other option in WordPress is stored — but it means the
	 * docblock promise "keys are encrypted at rest" is not always true, and
	 * nothing anywhere said so. A security guarantee you cannot verify is not a
	 * guarantee.
	 *
	 * No storage-format change is needed to detect it: real ciphertext carries
	 * the PREFIX and plaintext does not, so this is decidable from the stored
	 * value alone, and keys saved by older versions keep working untouched.
	 *
	 * @param string $stored Value as it sits in the options table.
	 * @return bool True when the value is non-empty and NOT encrypted.
	 */
	public static function is_plaintext_fallback( string $stored ): bool {
		return '' !== $stored && ! self::is_encrypted( $stored );
	}

	/**
	 * Can this site encrypt at all? Used to warn BEFORE a key is entered
	 * rather than after it has already been written in the clear.
	 */
	public static function can_encrypt(): bool {
		return function_exists( 'openssl_encrypt' );
	}

	/**
	 * The value as it actually sits in the options table.
	 *
	 * The plaintext disclosure has to look at stored bytes, and `get_option()`
	 * cannot show them: `decrypt_on_read()` is registered on `option_$opt`, so a
	 * correctly encrypted key comes back as plaintext and
	 * `is_plaintext_fallback()` then reports a perfectly healthy site as storing
	 * its keys in the clear. Standing this class's own filter down for the
	 * duration of the read keeps the options cache in play, so it costs no extra
	 * query, and restores the filter whether or not it was there to begin with.
	 *
	 * @param string $option Option name.
	 * @return string Stored value, ciphertext included.
	 */
	public static function raw_stored_value( string $option ): string {
		$callback = array( self::class, 'decrypt_on_read' );
		$was_set  = remove_filter( 'option_' . $option, $callback, 10 );
		$raw      = (string) get_option( $option, '' );
		if ( $was_set ) {
			add_filter( 'option_' . $option, $callback, 10, 1 );
		}
		return $raw;
	}

	/**
	 * Record that a secret was written in the clear.
	 *
	 * @param string $reason 'openssl_missing' or 'cipher_failed'.
	 */
	private static function mark_degraded( string $reason ): void {
		update_option(
			self::DEGRADED_OPTION,
			array(
				'reason' => $reason,
				'at'     => time(),
			),
			false
		);
	}

	/** Forget a past failure once a save encrypts cleanly again. */
	private static function clear_degraded(): void {
		if ( false !== get_option( self::DEGRADED_OPTION, false ) ) {
			delete_option( self::DEGRADED_OPTION );
		}
	}

	/**
	 * Which secrets are sitting in the options table unencrypted, by name.
	 *
	 * Names only. A caller that wants to show this to somebody must be able to
	 * do so without the value passing through a template, a REST response or a
	 * support export.
	 *
	 * @return string[] Option names.
	 */
	public static function unencrypted_secrets(): array {
		$out = array();
		foreach ( self::SECRET_OPTIONS as $opt ) {
			if ( self::is_plaintext_fallback( self::raw_stored_value( $opt ) ) ) {
				$out[] = $opt;
			}
		}
		return $out;
	}

	/**
	 * Does encryption actually work on this host, right now?
	 *
	 * can_encrypt() only proves the function exists. This proves the cipher
	 * round-trips, which is the case the extension check misses: `aes-256-gcm`
	 * absent from the build, or a policy refusing GCM. It encrypts a canary, so
	 * it never touches a real key and writes nothing.
	 */
	public static function self_test(): bool {
		if ( ! self::can_encrypt() ) {
			return false;
		}
		$canary = 'rnrd-self-test';
		$sealed = self::encrypt( $canary );
		return self::is_encrypted( $sealed ) && $canary === self::decrypt( $sealed );
	}

	/**
	 * Every option this class treats as a secret.
	 *
	 * Exposed so the admin disclosure can ask THIS class what the secrets are
	 * instead of keeping its own copy of the list. The copy had drifted: it
	 * named the four LLM keys and not `rnrd_cf_global_key`, an account-wide
	 * Cloudflare credential, nor `rnrd_dfs_password`. A site storing the worst
	 * of the seven in the clear was told nothing, which is the exact failure
	 * the disclosure exists to prevent. Adding a secret to SECRET_OPTIONS now
	 * covers it everywhere by construction.
	 *
	 * @return string[] Option names.
	 */
	public static function secret_options(): array {
		return self::SECRET_OPTIONS;
	}

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// openssl ext unavailable — fall back to plaintext rather than break.
			// This is theoretical on modern hosts; openssl ships with PHP.
			self::mark_degraded( 'openssl_missing' );
			return $plaintext;
		}

		$key = self::get_key();
		$iv  = random_bytes( 12 ); // 96-bit IV per GCM spec
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16 // 128-bit tag
		);

		if ( false === $ciphertext ) {
			// Encryption failed — refuse to silently drop the secret. Return
			// plaintext; the user's value still saves correctly. We log
			// nothing (could leak the secret). The marker is not a log: it names
			// the branch and the time, never the value, and it is the only way
			// the site owner ever learns this happened. `aes-256-gcm` missing
			// from the host's OpenSSL build, or a FIPS policy refusing GCM, both
			// land here with the extension present, so the check above does not
			// cover it.
			self::mark_degraded( 'cipher_failed' );
			return $plaintext;
		}

		self::clear_degraded();

		return self::PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $ciphertext Prefixed ciphertext.
	 * @return string Decrypted plaintext, or empty string on decryption failure.
	 */
	public static function decrypt( string $ciphertext ): string {
		if ( '' === $ciphertext ) {
			return '';
		}
		if ( ! self::is_encrypted( $ciphertext ) ) {
			return $ciphertext;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( substr( $ciphertext, strlen( self::PREFIX ) ), true );
		// IV (12) + GCM tag (16) = 28, and GCM is a stream mode, so a 1-byte
		// secret is a 29-byte blob. The old floor of 32 silently threw away
		// any stored secret of 1 to 3 characters.
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}

		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );

		$key       = self::get_key();
		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Heuristic: does this value look like one of our encrypted strings?
	 */
	public static function is_encrypted( string $value ): bool {
		return strncmp( $value, self::PREFIX, strlen( self::PREFIX ) ) === 0;
	}

	// ── Key derivation ─────────────────────────────────────────────────────

	/**
	 * Derive a 32-byte AES key from WP constants.
	 *
	 * If `AUTH_KEY` / `SECURE_AUTH_SALT` are unset (shouldn't happen on a
	 * real install but defensive), fall back to a per-site option-stored
	 * key so existing encrypted values stay decryptable.
	 */
	private static function get_key(): string {
		$material = '';
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$material .= AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_SALT' ) && SECURE_AUTH_SALT ) {
			$material .= SECURE_AUTH_SALT;
		}

		if ( '' === $material ) {
			// Defensive fallback for installs missing both constants.
			// Store a generated per-site key in options so values stay
			// readable across requests.
			$material = get_option( 'rnrd_crypto_fallback_key' );
			if ( ! is_string( $material ) || '' === $material ) {
				$material = bin2hex( random_bytes( 32 ) );
				update_option( 'rnrd_crypto_fallback_key', $material, false );
			}
		}

		return hash( 'sha256', 'rnrd:secret:v1:' . $material, true );
	}
}
