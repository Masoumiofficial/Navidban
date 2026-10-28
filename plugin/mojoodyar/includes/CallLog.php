<?php
/**
 * Phone follow-up: one row per call attempt, plus the open/closed state of the
 * follow-up as a whole.
 *
 * Why a separate table and not a counter on the waitlist row:
 *  - the history has to survive, because "we called him on Tuesday and he said
 *    he would decide" is the most useful thing a shop keeps;
 *  - "تماس سوم" is the number of attempts recorded, never a value typed by
 *    hand, so two admins saving at the same moment cannot both create a third
 *    call.
 *
 * States:
 *  - `open`   the follow-up is in progress;
 *  - `closed` somebody ended it with a reason. A closed follow-up refuses new
 *    attempts until it is reopened, so a finished customer does not silently
 *    re-enter the daily call list.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

defined( 'ABSPATH' ) || exit;

use Mojoodyar\Support\Persian;
use Mojoodyar\Support\Validator;

/**
 * Class CallLog
 */
final class CallLog {

	/**
	 * Outcomes of one attempt: key => Persian label.
	 *
	 * @var array<string,string>
	 */
	const OUTCOMES = array(
		'answered'     => 'پاسخ داد',
		'no_answer'    => 'پاسخ نداد',
		'busy'         => 'مشغول بود',
		'callback'     => 'بعداً تماس بگیرم',
		'ordered'      => 'سفارش داد',
		'wrong_number' => 'شماره اشتباه بود',
		'unsubscribed' => 'درخواست لغو کرد',
	);

	/**
	 * Outcomes that end the follow-up on their own.
	 *
	 * Choosing one of these and leaving the follow-up open would leave a
	 * finished customer in the "not called yet" list forever.
	 *
	 * @var string[]
	 */
	const TERMINAL = array( 'ordered', 'wrong_number', 'unsubscribed' );

	/**
	 * Reasons for ending a follow-up by hand.
	 *
	 * @var array<string,string>
	 */
	const CLOSE_REASONS = array(
		'ordered'      => 'سفارش داد',
		'wrong_number' => 'شماره اشتباه',
		'unsubscribed' => 'درخواست لغو',
		'unreachable'  => 'دیگر پاسخ نمی‌دهد',
		'duplicate'    => 'ردیف تکراری',
		'other'        => 'سایر',
	);

	/**
	 * Ordinals for the first ten stages; beyond that a plain number is used.
	 *
	 * @var string[]
	 */
	const ORDINALS = array( 'اول', 'دوم', 'سوم', 'چهارم', 'پنجم', 'ششم', 'هفتم', 'هشتم', 'نهم', 'دهم' );

	/**
	 * Legacy `called_at` is what the table filters and the CSV already use, so
	 * every attempt updates it as well.
	 *
	 * @return string
	 */
	public static function table() {
		return Database::table( Database::CALL_LOG );
	}

	/**
	 * Is this a known outcome key?
	 *
	 * @param string $outcome Outcome key.
	 * @return bool
	 */
	public static function is_outcome( $outcome ) {
		return isset( self::OUTCOMES[ (string) $outcome ] );
	}

	/**
	 * Is this a known close reason?
	 *
	 * @param string $reason Reason key.
	 * @return bool
	 */
	public static function is_close_reason( $reason ) {
		return isset( self::CLOSE_REASONS[ (string) $reason ] );
	}

	/**
	 * Persian label of a stage: 1 => "تماس اول".
	 *
	 * @param int $stage Stage number.
	 * @return string
	 */
	public static function stage_label( $stage ) {
		$stage = (int) $stage;

		if ( $stage < 1 ) {
			return 'بدون تماس';
		}

		if ( isset( self::ORDINALS[ $stage - 1 ] ) ) {
			return 'تماس ' . self::ORDINALS[ $stage - 1 ];
		}

		return 'تماس ' . Persian::number( $stage );
	}

	/**
	 * Label of an outcome, falling back to the raw value.
	 *
	 * @param string $outcome Outcome key.
	 * @return string
	 */
	public static function outcome_label( $outcome ) {
		return isset( self::OUTCOMES[ (string) $outcome ] ) ? self::OUTCOMES[ (string) $outcome ] : (string) $outcome;
	}

	/**
	 * Label of a close reason.
	 *
	 * @param string $reason Reason key.
	 * @return string
	 */
	public static function reason_label( $reason ) {
		return isset( self::CLOSE_REASONS[ (string) $reason ] ) ? self::CLOSE_REASONS[ (string) $reason ] : (string) $reason;
	}

	/**
	 * Every attempt for one subscriber, newest first.
	 *
	 * @param int $waitlist_id Waitlist row ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function history( $waitlist_id ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || (int) $waitlist_id <= 0 ) {
			return array();
		}

		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE waitlist_id = %d ORDER BY stage DESC, id DESC", (int) $waitlist_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of recorded attempts.
	 *
	 * @param int $waitlist_id Waitlist row ID.
	 * @return int
	 */
	public static function attempt_count( $waitlist_id ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || (int) $waitlist_id <= 0 ) {
			return 0;
		}

		$table = self::table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE waitlist_id = %d", (int) $waitlist_id ) );
	}

	/**
	 * Record one attempt.
	 *
	 * @param int    $waitlist_id Waitlist row ID.
	 * @param string $outcome     Outcome key (must exist in OUTCOMES).
	 * @param string $note        Free text, at most 500 characters.
	 * @param int    $user_id     WP user who made the call.
	 * @return array{ok:bool,stage:int,reason:string,closed:bool}
	 */
	public static function add_attempt( $waitlist_id, $outcome, $note, $user_id = 0 ) {
		global $wpdb;

		$waitlist_id = (int) $waitlist_id;

		if ( $waitlist_id <= 0 ) {
			return array( 'ok' => false, 'stage' => 0, 'reason' => 'invalid_id', 'closed' => false );
		}

		if ( ! self::is_outcome( $outcome ) ) {
			return array( 'ok' => false, 'stage' => 0, 'reason' => 'invalid_outcome', 'closed' => false );
		}

		$row = WaitlistRepository::get( $waitlist_id );

		if ( null === $row ) {
			return array( 'ok' => false, 'stage' => 0, 'reason' => 'not_found', 'closed' => false );
		}

		if ( isset( $row['call_state'] ) && 'closed' === $row['call_state'] ) {
			return array( 'ok' => false, 'stage' => 0, 'reason' => 'closed', 'closed' => true );
		}

		$stage   = self::attempt_count( $waitlist_id ) + 1;
		$note    = self::clean_note( $note );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$table   = self::table();
		$user_id = (int) $user_id;

		$inserted = $wpdb->insert(
			$table,
			array(
				'waitlist_id' => $waitlist_id,
				'stage'       => $stage,
				'outcome'     => (string) $outcome,
				'note'        => $note,
				'user_id'     => $user_id,
				'created_at'  => $now,
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return array( 'ok' => false, 'stage' => 0, 'reason' => 'db_error', 'closed' => false );
		}

		// Keep the old columns in step: the table filter, the CSV and the
		// dashboard card still read called_at/called_by.
		$waitlists = WaitlistRepository::table();

		$wpdb->update(
			$waitlists,
			array(
				'called_at' => $now,
				'called_by' => $user_id,
			),
			array( 'id' => $waitlist_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		$auto_closed = false;

		if ( in_array( (string) $outcome, self::TERMINAL, true ) ) {
			$closed      = self::close( $waitlist_id, (string) $outcome, '', $user_id );
			$auto_closed = (bool) $closed['ok'];
		}

		return array(
			'ok'     => true,
			'stage'  => $stage,
			'reason' => '',
			'closed' => $auto_closed,
		);
	}

	/**
	 * End the follow-up with a reason.
	 *
	 * @param int    $waitlist_id Waitlist row ID.
	 * @param string $reason      Reason key (must exist in CLOSE_REASONS).
	 * @param string $note        Optional note.
	 * @param int    $user_id     WP user.
	 * @return array{ok:bool,reason:string}
	 */
	public static function close( $waitlist_id, $reason, $note = '', $user_id = 0 ) {
		global $wpdb;

		$waitlist_id = (int) $waitlist_id;

		if ( $waitlist_id <= 0 ) {
			return array( 'ok' => false, 'reason' => 'invalid_id' );
		}

		if ( ! self::is_close_reason( $reason ) ) {
			return array( 'ok' => false, 'reason' => 'invalid_reason' );
		}

		if ( null === WaitlistRepository::get( $waitlist_id ) ) {
			return array( 'ok' => false, 'reason' => 'not_found' );
		}

		$table = WaitlistRepository::table();

		$updated = $wpdb->update(
			$table,
			array(
				'call_state'        => 'closed',
				'call_closed_at'    => gmdate( 'Y-m-d H:i:s' ),
				'call_closed_by'    => (int) $user_id,
				'call_close_reason' => (string) $reason,
				'call_close_note'   => self::clean_note( $note, 190 ),
			),
			array( 'id' => $waitlist_id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return array( 'ok' => false, 'reason' => 'db_error' );
		}

		return array( 'ok' => true, 'reason' => '' );
	}

	/**
	 * Put a closed follow-up back into the working list. The history is kept.
	 *
	 * @param int $waitlist_id Waitlist row ID.
	 * @return array{ok:bool,reason:string}
	 */
	public static function reopen( $waitlist_id ) {
		global $wpdb;

		$waitlist_id = (int) $waitlist_id;

		if ( $waitlist_id <= 0 ) {
			return array( 'ok' => false, 'reason' => 'invalid_id' );
		}

		if ( null === WaitlistRepository::get( $waitlist_id ) ) {
			return array( 'ok' => false, 'reason' => 'not_found' );
		}

		$table = WaitlistRepository::table();

		$updated = $wpdb->update(
			$table,
			array(
				'call_state'        => 'open',
				'call_closed_at'    => null,
				'call_closed_by'    => 0,
				'call_close_reason' => '',
				'call_close_note'   => '',
			),
			array( 'id' => $waitlist_id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return array( 'ok' => false, 'reason' => 'db_error' );
		}

		return array( 'ok' => true, 'reason' => '' );
	}

	/**
	 * One query for the whole page: waitlist ID => attempts + state.
	 *
	 * @param int[] $ids Waitlist IDs on this page.
	 * @return array<int,array{stage:int,state:string}>
	 */
	public static function summaries( array $ids ) {
		global $wpdb;

		$clean = array();

		foreach ( $ids as $id ) {
			if ( (int) $id > 0 ) {
				$clean[] = (int) $id;
			}
		}

		if ( empty( $clean ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$table      = self::table();
		$placeholer = implode( ',', array_fill( 0, count( $clean ), '%d' ) );

		// The placeholder string is built from %d tokens only; the IDs go
		// through prepare(), so nothing from the request reaches the SQL.
		$sql = $wpdb->prepare(
			"SELECT waitlist_id, COUNT(*) AS attempts FROM {$table} WHERE waitlist_id IN ({$placeholer}) GROUP BY waitlist_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$clean
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (int) $row['waitlist_id'] ] = (int) $row['attempts'];
			}
		}

		return $out;
	}

	/**
	 * Trim, strip and bound a note.
	 *
	 * @param string $note    Raw note.
	 * @param int    $max_len Maximum characters.
	 * @return string
	 */
	public static function clean_note( $note, $max_len = 500 ) {
		$note = Validator::plain_text( $note, (int) $max_len );

		return trim( $note );
	}

	/**
	 * State label for the table.
	 *
	 * @param array $row Waitlist row.
	 * @return string
	 */
	public static function state_label( array $row ) {
		if ( isset( $row['call_state'] ) && 'closed' === $row['call_state'] ) {
			return 'بسته: ' . self::reason_label( isset( $row['call_close_reason'] ) ? (string) $row['call_close_reason'] : '' );
		}

		return 'باز';
	}
}
