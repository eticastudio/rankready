<?php
/**
 * Atomic counter primitives.
 *
 * WordPress gives you no way to increment a stored number without a race. Both
 * the Options API and the metadata API are read-modify-write:
 *
 *     $n = (int) get_option( $name );   // request A and request B both read 7
 *     update_option( $name, $n + 1 );   // both write 8, one increment is lost
 *
 * That pattern shipped in five places in this plugin, on paths that genuinely do
 * run concurrently: AI-referral logging (public, crawler-reachable), token and
 * DataForSEO spend tracking (bulk generation plus the editor at the same time),
 * and per-post token totals (already the subject of a real duplicate-row bug in
 * this project's history). The numbers it produced were shown to the user as
 * spend figures, so a lost update is not cosmetic.
 *
 * The only way to express an increment atomically with these storage engines is
 * to let MySQL resolve the arithmetic:
 *
 *     UPDATE wp_options SET option_value = option_value + 1 WHERE option_name = …
 *
 * Two concurrent statements then produce two increments, because each one reads
 * and writes inside its own row lock. The row-creation race is closed for
 * options with claim_option(), a single INSERT IGNORE against the UNIQUE
 * option_name (core's add_option() is NOT a fail-if-exists primitive: it is an
 * INSERT ... ON DUPLICATE KEY UPDATE behind a get_option() pre-check, so two
 * racing callers with different values can both report success). Post meta has
 * no unique key at all, so its row creation is serialised with a MySQL named lock.
 *
 * The cost of the direct query is that it bypasses the caching layer those APIs
 * own, so every method here invalidates the cache entry it just changed.
 *
 * @package RankReady
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RNRD_Atomic {

	/**
	 * Add to an integer stored in a single option, without losing updates.
	 *
	 * The option is always created non-autoloaded: these are counters, read on
	 * one admin screen, and there is no reason to put them on every page load.
	 *
	 * @param string $name Option name.
	 * @param int    $by   Amount to add. Zero and negatives are ignored, because
	 *                     every caller here counts upwards and a negative would
	 *                     silently mean "I had a sign bug somewhere".
	 * @return void
	 */
	public static function bump_option_int( string $name, int $by = 1 ): void {
		if ( $by <= 0 ) {
			return;
		}
		self::bump_option( $name, (string) $by, '%d' );
	}

	/**
	 * Add to a decimal stored in a single option, without losing updates.
	 *
	 * Used for reported API spend, which arrives as a float from the provider.
	 * MySQL does the addition in DECIMAL/DOUBLE on the stored string, so the
	 * result carries the same precision the two inputs did.
	 *
	 * @param string $name Option name.
	 * @param float  $by   Amount to add. Zero and negatives are ignored.
	 * @return void
	 */
	public static function bump_option_float( string $name, float $by ): void {
		if ( $by <= 0 ) {
			return;
		}
		// plain_number(), not (string): on PHP 7.4 under a comma locale the cast
		// gives "0,0012", which prepare('%f') reads as 0 and the spend is lost.
		// 10 dp because per-call API cost is routinely below 0.0001.
		self::bump_option( $name, RNRD_Util::plain_number( $by, 10 ), '%f' );
	}

	/**
	 * Shared body of the two option bumps.
	 *
	 * @param string $name        Option name.
	 * @param string $seed        Value to store if the row does not exist yet.
	 * @param string $placeholder '%d' or '%f', matching the type being added.
	 * @return void
	 */
	private static function bump_option( string $name, string $seed, string $placeholder ): void {
		global $wpdb;

		/*
		 * Both statements are written out in full instead of assembled into a
		 * $sql variable first. It is the same two queries either way, but a
		 * literal handed straight to prepare() is verifiable by reading it, and
		 * the static analysers can follow it too: the assembled version left
		 * every call here reported as "unescaped parameter $sql ... assigned
		 * unsafely", a warning that could only ever be answered with a comment
		 * claiming the interpolation was safe. Now nothing has to be claimed.
		 */
		$run = static function () use ( $wpdb, $placeholder, $seed, $name ) {
			if ( '%f' === $placeholder ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic increment, which the Options API cannot express. Cache invalidated below.
				return $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = option_value + %f WHERE option_name = %s",
						$seed,
						$name
					)
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
			return $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = option_value + %d WHERE option_name = %s",
					$seed,
					$name
				)
			);
		};

		$updated = $run();

		if ( ! $updated ) {
			// Either the row is missing, or it exists and MySQL reported no change.
			// claim_option() tells the two cases apart: it returns false when the row
			// is already there, and in that case the UPDATE above genuinely applied
			// (or added zero), so a second attempt is only needed on the race where
			// another request created the row between our UPDATE and our add.
			if ( ! self::claim_option( $name, (string) $seed ) ) {
				$run();
			}
		}

		// The UPDATE went round the Options API, so its cached copy is now stale.
		wp_cache_delete( $name, 'options' );

		// Rows are created non-autoloaded (claim_option() above), so the
		// line above is normally enough. If something autoloads the row anyway
		// (a third party, a hand edit), get_option() serves it from the
		// alloptions bucket and the counter reads stale forever. Drop it from
		// there too, the same way core's delete_option() does.
		$rnrd_all = wp_cache_get( 'alloptions', 'options' );
		if ( is_array( $rnrd_all ) && isset( $rnrd_all[ $name ] ) ) {
			unset( $rnrd_all[ $name ] );
			wp_cache_set( 'alloptions', $rnrd_all, 'options' );
		}
	}

	/**
	 * Create a non-autoloaded option only if it does not exist yet, atomically.
	 *
	 * One INSERT IGNORE against the UNIQUE option_name: exactly one caller gets
	 * true, everyone else gets false, whatever value they tried to write. Use this
	 * for locks and claims. add_option() cannot be used for that, see the file
	 * docblock.
	 *
	 * @param string $name  Option name.
	 * @param string $value Value to store when this call creates the row.
	 * @return bool True when this call created the row.
	 */
	public static function claim_option( string $name, string $value ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic create-if-absent, which the Options API cannot express. Cache invalidated below.
		$rows = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				$value
			)
		);

		// get_option() must not keep answering "missing" from the notoptions cache.
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return 1 === (int) $rows;
	}

	/**
	 * Take a time-limited lock stored in one option, atomically.
	 *
	 * claim_option() decides who creates the row. A holder that died leaves the row
	 * behind, so a row older than $ttl is taken over with a single conditional
	 * UPDATE: two takers both see the same stale value, only one UPDATE can still
	 * match it, so exactly one of them wins. The delete-then-claim shape this
	 * replaces let a second taker delete the first taker's fresh lock.
	 *
	 * @param string $name Option name used as the lock.
	 * @param int    $ttl  Seconds after which a held lock counts as abandoned.
	 * @param string $token Optional, by reference. Receives the owner token on success; pass it to release_lock() so a slow holder never deletes a successor's lock.
	 * @return bool True when the caller now holds the lock.
	 */
	public static function acquire_lock( string $name, int $ttl = 300, &$token = null ): bool {
		global $wpdb;

		$now   = time();
		$token = (string) $now;
		if ( self::claim_option( $name, (string) $now ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set on a stale lock, which the Options API cannot express. Cache invalidated below.
		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				(string) $now,
				$name,
				$now - max( 1, $ttl )
			)
		);

		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		if ( 1 !== (int) $taken ) {
			$token = null;
			return false;
		}
		return true;
	}

	/**
	 * Release a lock taken with acquire_lock().
	 *
	 * With an owner token the row is deleted only while it still holds that
	 * token, so a holder whose lock expired cannot remove its successor's.
	 *
	 * @param string      $name  Option name used as the lock.
	 * @param string|null $token Optional owner token from acquire_lock().
	 * @return void
	 */
	public static function release_lock( string $name, $token = null ): void {
		if ( null === $token || '' === (string) $token ) {
			delete_option( $name );
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete on the owner token, which the Options API cannot express. Cache invalidated below.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $token ) );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Read a counter written by bump_option_int().
	 *
	 * @param string $name Option name.
	 * @return int
	 */
	public static function read_int( string $name ): int {
		return (int) get_option( $name, 0 );
	}

	/**
	 * Read a counter written by bump_option_float().
	 *
	 * @param string $name Option name.
	 * @return float
	 */
	public static function read_float( string $name ): float {
		return (float) get_option( $name, 0 );
	}

	/**
	 * Add to an integer stored in one post meta row, without losing updates.
	 *
	 * The UPDATE is atomic. Creating the row is the hard part: postmeta has no
	 * unique key, so the create step runs under a MySQL named lock and retries
	 * the UPDATE inside it, which is what stops two requests inserting two rows.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param int    $by      Amount to add. Zero and negatives are ignored.
	 * @return void
	 */
	public static function bump_post_meta_int( int $post_id, string $key, int $by = 1 ): void {
		global $wpdb;

		if ( $post_id <= 0 || $by <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic increment, which the metadata API cannot express. Cache invalidated below.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + %d WHERE post_id = %d AND meta_key = %s",
				$by,
				$post_id,
				$key
			)
		);

		if ( ! $updated ) {
			// postmeta has no unique key, so add_post_meta( ..., true ) is a
			// SELECT then an INSERT and two requests can both insert. A named lock
			// makes the create step single-file; the UPDATE is re-run inside it so
			// the loser adds to the winner's row instead of creating a second one.
			$lock = 'rnrd_pm_' . md5( $post_id . '|' . $key );
			// get_var(), not query(): query() returns the row count, which is 1 whether
			// the lock was granted (1), timed out (0) or errored (NULL).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock, no data read.
			$locked = ( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 2 )', $lock ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + %d WHERE post_id = %d AND meta_key = %s",
					$by,
					$post_id,
					$key
				)
			);
			// Without the lock, creating the row could insert a second one. A counter
			// tick lost under that much contention is cheaper than duplicate rows.
			if ( ! $updated && $locked && ! add_post_meta( $post_id, $key, (string) $by, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + %d WHERE post_id = %d AND meta_key = %s",
						$by,
						$post_id,
						$key
					)
				);
			}
			if ( $locked ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
			}
		}

		wp_cache_delete( $post_id, 'post_meta' );
	}
}
