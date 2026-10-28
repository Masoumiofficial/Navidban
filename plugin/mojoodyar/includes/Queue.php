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

		if ( false === $result ) {
			// A failed INSERT used to be silently counted as queued: enqueue()
			// returned true because `false !== false` was the only test, and the
			// caller's "queued=2" in the log was a lie. The shop then saw an
			// empty queue with a log insisting two messages were waiting.
			Logger::log(
				'error',
				'queue_insert_failed',
				\Mojoodyar\Support\Masking::free_text( (string) $wpdb->last_error, 150 )
			);

			return false;
		}

		// INSERT IGNORE also answers 0 when the unique key swallowed the row,
		// which is the intended duplicate guard - the message is already queued.
		return true;
	}

	/**
	 * Was a queue row actually written by the last enqueue() call?
	 *
	 * INSERT IGNORE cannot tell us: it returns the number of *inserted* rows,
	 * so 0 means "the unique key already had this message". Callers that want
	 * to report "N messages queued" need that distinction.
	 *
	 * @return int Rows inserted by the most recent query.
	 */
	public static function last_insert_count() {
		global $wpdb;

		return (int) $wpdb->rows_affected;
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
	public static function reclaim_stale() {
		global $wpdb;

		$table = self::table();

		// A claimed row whose worker never came back. The claim writes
		// locked_at, so a row older than the TTL is abandoned by definition:
		// the whole send is a single HTTP call with a 15 second timeout, and the
		// run itself is capped by TIME_BUDGET, so nothing legitimate holds a
		// claim for five minutes.
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) QueuePolicy::LOCK_TTL );

		$reclaimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'queued', locked_at = NULL
				 WHERE status = 'processing' AND ( locked_at IS NULL OR locked_at < %s )",
				$cutoff
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$reclaimed = (int) $reclaimed;

		if ( $reclaimed > 0 ) {
			// Worth a warning: it means a worker died mid-send, and the same
			// crash may be hitting every run.
			Logger::log( 'warning', 'queue_reclaimed', 'rows=' . $reclaimed );
		}

		return $reclaimed;
	}

	/**
	 * Explain why the queue is not moving.
	 *
	 * "0 sent (reason: empty)" is the least useful sentence a queue can print:
	 * the rows may be scheduled for later, stuck in processing after a crashed
	 * worker, or not exist at all - three completely different problems that
	 * all render as "empty". This returns the counts behind the sentence.
	 *
	 * @return array{queued:int,ready:int,scheduled:int,processing:int,failed:int,skipped:int,hint:string}
	 */
	public static function diagnose() {
		global $wpdb;

		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$queued = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'queued' AND next_run_at <= %s", $now )
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$scheduled = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'queued' AND next_run_at > %s", $now )
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$processing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'processing'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$failed     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$skipped    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'skipped'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$earliest = $wpdb->get_var(
			$wpdb->prepare( "SELECT next_run_at FROM {$table} WHERE status = 'queued' AND next_run_at > %s ORDER BY next_run_at ASC LIMIT 1", $now )
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$hint = '';

		if ( $queued > 0 ) {
			$hint = 'آمادهٔ ارسال است؛ اگر ارسال نشد، قفل صف یا بازهٔ ارسال را ببینید.';
		} elseif ( $scheduled > 0 ) {
			$hint = 'زمان اجرای بعدی این پیام‌ها نرسیده است' . ( is_string( $earliest ) ? ' (نزدیک‌ترین: ' . $earliest . ' UTC)' : '' ) . '.';
		} elseif ( $processing > 0 ) {
			$hint = 'ردیف‌ها در وضعیت «در حال ارسال» گیر کرده‌اند؛ ابزار «آزادکردن قفل صف» را بزنید.';
		} elseif ( $failed > 0 ) {
			$hint = 'همهٔ پیام‌ها ناموفق‌اند؛ ابزار «تلاش دوباره برای پیام‌های ناموفق» را بزنید.';
		} elseif ( $skipped > 0 ) {
			$hint = 'پیام‌ها رد شده‌اند (لغو اشتراک یا تأییدنشده)؛ لیست انتظار را ببینید.';
		} else {
			$hint = 'صف خالی است؛ هنوز اعلانی ساخته نشده است.';
		}

		return array(
			'queued'     => $queued,
			'ready'      => $queued,
			'scheduled'  => $scheduled,
			'processing' => $processing,
			'failed'     => $failed,
			'skipped'    => $skipped,
			'hint'       => $hint,
		);
	}

	public static function process( $limit = null ) {
		global $wpdb;

		$stats = array(
			'processed' => 0,
			'sent'      => 0,
			'failed'    => 0,
			'skipped'   => '',
			'reclaimed' => 0,
		);

		if ( ! self::acquire_lock() ) {
			$stats['skipped'] = 'locked';

			return $stats;
		}

		// Before looking for work, take back rows abandoned by a worker that
		// died mid-send. Without this a single crash left those rows in
		// "processing" forever: the selection below only reads 'queued', so the
		// queue reported "empty" for ever while the dashboard kept showing one
		// message in flight.
		$stats['reclaimed'] = self::reclaim_stale();

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

			// Distinguish "there is nothing" from "nothing is due yet": the two
			// need completely different advice, and calling both "empty" is how
			// an admin ends up convinced the queue is broken.
			$diagnosis        = self::diagnose();
			$stats['skipped'] = $diagnosis['scheduled'] > 0 ? 'scheduled' : 'empty';
			$stats['stats']   = $diagnosis;

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

		// The status alone ("failed") tells the admin nothing: the gateway's own
		// sentence is the only thing that says *why*. It used to be written to
		// last_error and nowhere else, so the log screen showed three identical
		// "delivery failed" rows with no reason - and the shopkeeper had to open
		// the database to find out that, say, the sender line was rejected.
		// The message is redacted first: gateways quote the recipient back.
		Logger::log(
			'warning',
			'delivery_failed',
			(string) $row['channel'] . '|' . $status
				. '|attempt=' . (int) $attempts
				. '|err=' . \Mojoodyar\Support\Masking::free_text( (string) $message, 150 )
		);

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
	 * Put every permanently failed row back in the queue.
	 *
	 * A row reaches status='failed' after the retry policy is exhausted, and
	 * nothing ever tries it again: the message is lost silently even though the
	 * reason may have been transient (gateway timeout, expired token, a
	 * provider outage at 3am). The admin needs a way to say "try once more now
	 * that I fixed the gateway". Attempts are reset so a fresh set of retries
	 * is available, and the unique payload key still prevents duplicates.
	 *
	 * @param int $limit Safety cap.
	 * @return int Rows requeued.
	 */
	public static function retry_failed( $limit = 200 ) {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, (int) $limit );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = 'failed' ORDER BY id ASC LIMIT %d",
				$limit
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$count = 0;

		foreach ( (array) $ids as $id ) {
			$updated = $wpdb->update(
				$table,
				array(
					'status'      => 'queued',
					'attempts'    => 0,
					'next_run_at' => gmdate( 'Y-m-d H:i:s' ),
					'last_error'  => null,
					'locked_at'   => null,
				),
				array( 'id' => (int) $id ),
				array( '%s', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false !== $updated && (int) $updated > 0 ) {
				$count++;
			}
		}

		if ( $count > 0 ) {
			Logger::log( 'info', 'queue_retry', 'requeued=' . $count );
		}

		return $count;
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
