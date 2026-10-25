<?php
/**
 * Queue retry, backoff, rate limiting and lock policy. Pure logic: unit-tested.
 *
 * @package Navidban
 */

namespace Navidban\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * Class QueuePolicy
 */
final class QueuePolicy {

	/**
	 * Backoff ladder in minutes. The last value repeats.
	 *
	 * @var int[]
	 */
	const BACKOFF_MINUTES = array( 15, 60, 360 );

	/**
	 * Maximum delivery attempts before an item is marked failed.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Seconds after which a processing lock is considered abandoned.
	 *
	 * @var int
	 */
	const LOCK_TTL = 300;

	/**
	 * Delay before the next attempt, in minutes.
	 *
	 * @param int $attempts Attempts already made (1 after the first failure).
	 * @return int Minutes. 0 means "no retry left".
	 */
	public static function backoff_minutes( $attempts ) {
		$attempts = (int) $attempts;

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return 0;
		}

		if ( $attempts < 1 ) {
			$attempts = 1;
		}

		$index = $attempts - 1;
		if ( ! isset( self::BACKOFF_MINUTES[ $index ] ) ) {
			$index = count( self::BACKOFF_MINUTES ) - 1;
		}

		return self::BACKOFF_MINUTES[ $index ];
	}

	/**
	 * The status an item takes after a failure.
	 *
	 * @param int $attempts Attempts made so far, including the failed one.
	 * @return string "queued" or "failed".
	 */
	public static function status_after_failure( $attempts ) {
		return ( (int) $attempts >= self::MAX_ATTEMPTS ) ? 'failed' : 'queued';
	}

	/**
	 * Next run timestamp (UTC) after a failure.
	 *
	 * @param int $now      Current Unix timestamp.
	 * @param int $attempts Attempts made so far.
	 * @return int Unix timestamp; 0 when no retry is scheduled.
	 */
	public static function next_run_after_failure( $now, $attempts ) {
		$minutes = self::backoff_minutes( $attempts );

		if ( 0 === $minutes ) {
			return 0;
		}

		return (int) $now + ( $minutes * 60 );
	}

	/**
	 * How many items may be sent in the current call.
	 *
	 * @param int $batch_size      Configured batch size (1..500).
	 * @param int $per_minute      Rate limit per minute.
	 * @param int $sent_last_min   Items already sent in the trailing 60 seconds.
	 * @return int
	 */
	public static function allowance( $batch_size, $per_minute, $sent_last_min ) {
		$batch_size = max( 1, min( 500, (int) $batch_size ) );
		$per_minute = max( 1, (int) $per_minute );
		$sent_last  = max( 0, (int) $sent_last_min );

		$rate_room = $per_minute - $sent_last;

		if ( $rate_room <= 0 ) {
			return 0;
		}

		return (int) min( $batch_size, $rate_room );
	}

	/**
	 * Is the current hour inside the allowed sending window?
	 *
	 * Supports windows that wrap midnight (start > end).
	 *
	 * @param int $hour       Hour 0-23 in site time.
	 * @param int $start_hour Window start hour.
	 * @param int $end_hour   Window end hour (exclusive).
	 * @param bool $enabled   Window enabled at all.
	 * @return bool
	 */
	public static function inside_window( $hour, $start_hour, $end_hour, $enabled = true ) {
		if ( ! $enabled ) {
			return true;
		}

		$hour  = ( (int) $hour ) % 24;
		$start = ( (int) $start_hour ) % 24;
		$end   = ( (int) $end_hour ) % 24;

		if ( $start === $end ) {
			return true;
		}

		if ( $start < $end ) {
			return $hour >= $start && $hour < $end;
		}

		return $hour >= $start || $hour < $end;
	}

	/**
	 * Is a row still locked by a concurrent run?
	 *
	 * @param int|null $locked_at Unix timestamp of the lock, null when unlocked.
	 * @param int      $now       Current Unix timestamp.
	 * @param int      $ttl       Lock lifetime in seconds.
	 * @return bool
	 */
	public static function is_locked( $locked_at, $now, $ttl = self::LOCK_TTL ) {
		if ( null === $locked_at || '' === $locked_at ) {
			return false;
		}

		return ( (int) $now - (int) $locked_at ) < (int) $ttl;
	}

	/**
	 * Should the batch stop early to stay inside max_execution_time?
	 *
	 * @param int $started_at  Unix timestamp when the run started.
	 * @param int $now         Current Unix timestamp.
	 * @param int $budget      Seconds we allow ourselves (default 20 of a 30s limit).
	 * @return bool
	 */
	public static function budget_exhausted( $started_at, $now, $budget = 20 ) {
		return ( (int) $now - (int) $started_at ) >= (int) $budget;
	}

	/**
	 * Order-agnostic dedupe key for a notification payload.
	 *
	 * @param int    $waitlist_id Waitlist row ID.
	 * @param string $channel     Channel name.
	 * @param string $event_key   Event identifier, typically "product:variation:restock_at".
	 * @return string 64-char hash.
	 */
	public static function payload_hash( $waitlist_id, $channel, $event_key ) {
		return hash( 'sha256', (int) $waitlist_id . '|' . strtolower( (string) $channel ) . '|' . (string) $event_key );
	}
}
