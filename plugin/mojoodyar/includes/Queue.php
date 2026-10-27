<?php
/**
 * Background dispatch queue.
 *
 * Shape of a run (see docs/SCHEDULER-POLICY.md):
 *   1. take a global lock (an option row) so two cron workers never overlap;
 *   2. respect the sending window and the per-minute rate limit;
 *   3. claim each row individually (UPDATE ... WHERE status = 'queued');
 *   4. stop before max_execution_time is threatened and leave the rest queued;
 *   5. retry with exponential backoff, give up after MAX_ATTEMPTS.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

use Mojoodyar\Logic\QueuePolicy;
use Mojoodyar\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Class Queue
 */
final class Queue {

	/**
	 * Lock option name.
	 *
	 * @var string
	 */
	const LOCK_OPTION = 'mojoodyar_queue_lock';

	/**
	 * Seconds we allow a single cron run to spend sending.
	 *
	 * @var int
	 */
	const TIME_BUDGET = 20;

	/**
	 * Queue table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Database::table( Database::QUEUE );
	}

	/**
	 * Enqueue one message.
	 *
	 * @param int    $waitlist_id Waitlist row ID.
	 * @param string $channel     email|sms.
	 * @param string $event_key   Event key ("confirm", "restock:<timestamp>").
	 * @param array  $payload     Extra payload for the channel layer.
	 * @param int    $next_run_at Unix timestamp; null = now.
	 * @return bool
	 */
	public static function enqueue( $waitlist_id, $channel, $event_key, array $payload = array(), $next_run_at = null ) {
		global $wpdb;

		$waitlist_id = (int) $waitlist_id;
		$channel     = ( 'sms' === $channel ) ? 'sms' : 'email';
		$event_key   = substr( (string) $event_key, 0, 100 );

		if ( $waitlist_id <= 0 ) {
			return false;
		}

		$hash = QueuePolicy::payload_hash( $waitlist_id, $channel, $event_key . '|' . wp_json_encode_compat( $payload ) );
		$when = ( null === $next_run_at ) ? time() : (int) $next_run_at;

		$sql = $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::table() . ' (waitlist_id, channel, event_key, payload_hash, attempts, status, next_run_at, created_at)
			 VALUES (%d, %s, %s, %s, 0, %s, %s, %s)',
			$waitlist_id,
			$channel,
			$event_key,
			$hash,
			'queued',
			gmdate( 'Y-m-d H:i:s', $when ),
			gmdate( 'Y-m-d H:i:s' )
		);

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return false !== $result;
	}

	/**
	 * Count messages sent in the trailing minute (rate-limit input).
	 *
	 * @return int
	 */
	public static function sent_last_minute() {
		global $wpdb;

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - 60 );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'sent' AND sent_at >= %s",
				$since
			)
		);
	}

	/**
	 * Current site hour (0-23) for the sending window.
	 *
	 * @return int
	 */
	public static function site_hour() {
		if ( function_exists( 'current_time' ) ) {
			return (int) current_time( 'G' );
		}

		return (int) gmdate( 'G' );
	}

	/**
	 * Dispatch a batch. Safe to call from cron, WP-CLI or the admin tool.
	 *
	 * @param int|null $limit Optional hard limit (defaults to the configured batch size).
	 * @return array{processed:int,sent:int,failed:int,skipped:string}
	 */
	public static function process( $limit = null ) {
		global $wpdb;

		$stats = array(
			'processed' => 0,
			'sent'      => 0,
			'failed'    => 0,
			'skipped'   => '',
		);

		if ( ! self::acquire_lock() ) {
			$stats['skipped'] = 'locked';

			return $stats;
		}

		$window_enabled = Settings::enabled( 'send_window_enabled' );
		$inside_window  = QueuePolicy::inside_window(
			self::site_hour(),
			(int) Settings::get( 'send_window_start', 8 ),
			(int) Settings::get( 'send_window_end', 22 ),
			$window_enabled
		);

		if ( ! $inside_window ) {
			self::release_lock();
			$stats['skipped'] = 'window';

			return $stats;
		}

		$batch      = ( null === $limit ) ? (int) Settings::get( 'queue_batch_size', 50 ) : (int) $limit;
		$allowance  = QueuePolicy::allowance( $batch, (int) Settings::get( 'queue_per_minute', 60 ), self::sent_last_minute() );

		if ( $allowance <= 0 ) {
			self::release_lock();
			$stats['skipped'] = 'rate';

			return $stats;
		}

		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status = 'queued' AND next_run_at <= %s
				 ORDER BY next_run_at ASC, id ASC
				 LIMIT %d",
				$now,
				$allowance
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			self::release_lock();
			$stats['skipped'] = 'empty';

			return $stats;
		}

		$started = time();

		foreach ( $rows as $row ) {
			if ( QueuePolicy::budget_exhausted( $started, time(), self::TIME_BUDGET ) ) {
				$stats['skipped'] = 'budget';
				break;
			}

			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'processing', locked_at = %s WHERE id = %d AND status = 'queued'",
					$now,
					(int) $row['id']
				)
			);

			if ( ! $claimed ) {
				continue;
			}

			$stats['processed']++;

			$outcome = self::deliver( (int) $row['id'] );

			if ( true === $outcome ) {
				$stats['sent']++;
			} else {
				$stats['failed']++;
			}
		}

		self::release_lock();

		return $stats;
	}

	/**
	 * Deliver one queue row.
	 *
	 * @param int $queue_id Queue row ID.
	 * @return bool
	 */
	public static function deliver( $queue_id ) {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $queue_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return false;
		}

		$waitlist = WaitlistRepository::get( (int) $row['waitlist_id'] );

		if ( null === $waitlist ) {
			self::mark_skipped( $queue_id, 'waitlist_missing' );

			return false;
		}

		if ( 'unsubscribed' === $waitlist['status'] ) {
			self::mark_skipped( $queue_id, 'unsubscribed' );

			return false;
		}

		// A pending subscriber may only receive the confirmation message.
		if ( 'pending' === $waitlist['status'] && 0 !== strpos( (string) $row['event_key'], 'confirm' ) ) {
			self::mark_skipped( $queue_id, 'not_confirmed' );

			return false;
		}

		$result = Notifier::dispatch( $waitlist, (string) $row['event_key'], (string) $row['channel'] );

		if ( true === $result ) {
			$wpdb->update(
				$table,
				array(
					'status'   => 'sent',
					'sent_at'  => gmdate( 'Y-m-d H:i:s' ),
					'locked_at' => null,
				),
				array( 'id' => (int) $queue_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			return true;
		}

		$attempts = (int) $row['attempts'] + 1;
		$status   = QueuePolicy::status_after_failure( $attempts );
		$next_run = QueuePolicy::next_run_after_failure( time(), $attempts );

		$message = is_object( $result ) && method_exists( $result, 'get_error_message' )
			? $result->get_error_message()
			: ( is_string( $result ) ? $result : 'unknown' );

		$wpdb->update(
			$table,
			array(
				'attempts'    => $attempts,
				'status'      => $status,
				'next_run_at' => gmdate( 'Y-m-d H:i:s', 0 === $next_run ? time() : $next_run ),
				// Character-aware: a byte-wise substr() can cut a Persian string
				// mid-character, and MySQL rejects that as invalid utf8mb4.
				'last_error'  => Text::substring( (string) $message, 0, 255 ),
				'locked_at'   => null,
			),
			array( 'id' => (int) $queue_id ),
			array( '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		Logger::log( 'warning', 'delivery_failed', (string) $row['channel'] . '|' . $status );

		return false;
	}

	/**
	 * Mark a row skipped (never retried).
	 *
	 * @param int    $queue_id Row ID.
	 * @param string $reason   Reason key.
	 * @return void
	 */
	public static function mark_skipped( $queue_id, $reason ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status'     => 'skipped',
				'last_error' => Text::substring( (string) $reason, 0, 255 ),
				'locked_at'  => null,
			),
			array( 'id' => (int) $queue_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Take the global lock.
	 *
	 * @return bool
	 */
	public static function acquire_lock() {
		if ( ! function_exists( 'add_option' ) ) {
			return true;
		}

		$now    = time();
		$locked = get_option( self::LOCK_OPTION, null );

		if ( null !== $locked && '' !== $locked ) {
			if ( QueuePolicy::is_locked( (int) $locked, $now, QueuePolicy::LOCK_TTL ) ) {
				return false;
			}

			// Stale lock from a crashed run.
			delete_option( self::LOCK_OPTION );
		}

		return (bool) add_option( self::LOCK_OPTION, $now, '', false );
	}

	/**
	 * Release the global lock.
	 *
	 * @return void
	 */
	public static function release_lock() {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Delete queue rows belonging to a set of waitlist rows.
	 *
	 * @param int[] $waitlist_ids IDs.
	 * @return void
	 */
	public static function delete_for_waitlists( array $waitlist_ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $waitlist_ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE waitlist_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Empty the queue (admin tool).
	 *
	 * @return int Rows deleted.
	 */
	public static function empty_queue() {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->query( "DELETE FROM {$table} WHERE status IN ('queued','processing','failed','skipped')" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Queue counters for the dashboard.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;

		$table = self::table();

		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$stats = array(
			'queued'     => 0,
			'processing' => 0,
			'sent'       => 0,
			'failed'     => 0,
			'skipped'    => 0,
			'oldest'     => '',
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$status = (string) $row['status'];
				if ( isset( $stats[ $status ] ) ) {
					$stats[ $status ] = (int) $row['c'];
				}
			}
		}

		$oldest = $wpdb->get_var( "SELECT next_run_at FROM {$table} WHERE status = 'queued' ORDER BY next_run_at ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$stats['oldest'] = is_string( $oldest ) ? $oldest : '';

		return $stats;
	}
}

/**
 * wp_json_encode() exists in WordPress; fallback for the harness.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_compat( $value ) {
	if ( function_exists( 'wp_json_encode' ) ) {
		$encoded = wp_json_encode( $value );
	} else {
		$encoded = json_encode( $value );
	}

	return false === $encoded ? '' : $encoded;
}
