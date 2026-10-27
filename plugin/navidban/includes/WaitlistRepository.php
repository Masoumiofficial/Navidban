<?php
/**
 * All database access for waitlist rows, snapshots and queue rows lives here.
 * Every statement uses $wpdb->prepare(); no string concatenation of user data.
 *
 * @package Navidban
 */

namespace Navidban;

defined( 'ABSPATH' ) || exit;

/**
 * Class WaitlistRepository
 */
final class WaitlistRepository {

	/**
	 * Allowed statuses.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'pending', 'confirmed', 'notified', 'expired', 'unsubscribed' );

	/**
	 * Waitlist table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Database::table( Database::WAITLISTS );
	}

	/**
	 * Snapshot table name.
	 *
	 * @return string
	 */
	public static function snapshots() {
		return Database::table( Database::SNAPSHOTS );
	}

	/**
	 * Queue table name.
	 *
	 * @return string
	 */
	public static function queue() {
		return Database::table( Database::QUEUE );
	}

	/**
	 * Insert a waitlist row and enqueue its confirmation message atomically.
	 *
	 * @param array $data Row data.
	 * @return array{ok:bool,id:int,duplicate:bool,reason:string}
	 */
	public static function create( array $data ) {
		global $wpdb;

		$defaults = array(
			'product_id'   => 0,
			'variation_id' => 0,
			'event_type'   => 'restock',
			'channel'      => 'email',
			'email'        => '',
			'phone'        => '',
			'full_name'    => '',
			'user_id'      => 0,
			'status'       => 'pending',
			'stock_at_signup' => 0,
			'ip_hash'      => '',
		);

		$row = array_merge( $defaults, $data );

		$row['product_id']   = (int) $row['product_id'];
		$row['variation_id'] = (int) $row['variation_id'];
		$row['user_id']      = (int) $row['user_id'];
		$row['full_name']    = substr( (string) $row['full_name'], 0, 120 );
		$row['stock_at_signup'] = (int) $row['stock_at_signup'];

		if ( $row['product_id'] <= 0 ) {
			return array( 'ok' => false, 'id' => 0, 'duplicate' => false, 'reason' => 'product' );
		}

		if ( ! in_array( $row['channel'], array( 'email', 'sms' ), true ) ) {
			$row['channel'] = 'email';
		}

		if ( 'email' === $row['channel'] && '' === $row['email'] ) {
			return array( 'ok' => false, 'id' => 0, 'duplicate' => false, 'reason' => 'email' );
		}

		if ( 'sms' === $row['channel'] && '' === $row['phone'] ) {
			return array( 'ok' => false, 'id' => 0, 'duplicate' => false, 'reason' => 'phone' );
		}

		$existing = self::find_existing( $row['product_id'], $row['variation_id'], (string) $row['email'], (string) $row['phone'] );
		if ( $existing > 0 ) {
			return array( 'ok' => false, 'id' => $existing, 'duplicate' => true, 'reason' => 'duplicate' );
		}

		$row['token']    = Consent::generate_token();
		$row['created_at'] = gmdate( 'Y-m-d H:i:s' );

		if ( Settings::enabled( 'consent_required' ) ) {
			$row['consent_at'] = gmdate( 'Y-m-d H:i:s' );
		}

		if ( Settings::enabled( 'double_opt_in' ) ) {
			$ttl_hours = max( 1, (int) Settings::get( 'confirm_ttl_hours', 48 ) );
			$row['confirm_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + ( $ttl_hours * 3600 ) );
			$row['status']             = 'pending';
		} else {
			$row['status']       = 'confirmed';
			$row['confirmed_at'] = gmdate( 'Y-m-d H:i:s' );
		}

		$wpdb->query( 'START TRANSACTION' );

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'token'             => $row['token'],
				'product_id'        => $row['product_id'],
				'variation_id'      => $row['variation_id'],
				'event_type'        => substr( (string) $row['event_type'], 0, 32 ),
				'channel'           => $row['channel'],
				'email'             => substr( (string) $row['email'], 0, 190 ),
				'phone'             => substr( (string) $row['phone'], 0, 20 ),
				'full_name'         => $row['full_name'],
				'user_id'           => $row['user_id'],
				'status'            => $row['status'],
				'consent_at'        => isset( $row['consent_at'] ) ? $row['consent_at'] : null,
				'confirmed_at'      => isset( $row['confirmed_at'] ) ? $row['confirmed_at'] : null,
				'confirm_expires_at' => isset( $row['confirm_expires_at'] ) ? $row['confirm_expires_at'] : null,
				'stock_at_signup'   => $row['stock_at_signup'],
				'ip_hash'           => substr( (string) $row['ip_hash'], 0, 64 ),
				'created_at'        => $row['created_at'],
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );

			// A concurrent request won the unique key race: treat it as duplicate.
			if ( ! empty( $wpdb->last_error ) && false !== strpos( $wpdb->last_error, 'Duplicate' ) ) {
				$existing = self::find_existing( $row['product_id'], $row['variation_id'], (string) $row['email'], (string) $row['phone'] );

				return array( 'ok' => false, 'id' => (int) $existing, 'duplicate' => true, 'reason' => 'duplicate' );
			}

			return array( 'ok' => false, 'id' => 0, 'duplicate' => false, 'reason' => 'db' );
		}

		$id = (int) $wpdb->insert_id;

		// Queue the confirmation message inside the same transaction: either the
		// subscriber exists and will be contacted, or nothing is written at all.
		if ( 'pending' === $row['status'] ) {
			$queued = Queue::enqueue(
				$id,
				$row['channel'],
				'confirm',
				array(
					'waitlist_id' => $id,
				),
				time()
			);

			if ( ! $queued ) {
				$wpdb->query( 'ROLLBACK' );

				return array( 'ok' => false, 'id' => 0, 'duplicate' => false, 'reason' => 'queue' );
			}
		}

		$wpdb->query( 'COMMIT' );

		return array(
			'ok'        => true,
			'id'        => $id,
			'duplicate' => false,
			'reason'    => '',
		);
	}

	/**
	 * Look up an existing row for the same product and identifier.
	 *
	 * @param int    $product_id   Product ID.
	 * @param int    $variation_id Variation ID.
	 * @param string $email        Email.
	 * @param string $phone        Phone.
	 * @return int
	 */
	public static function find_existing( $product_id, $variation_id, $email, $phone ) {
		global $wpdb;

		$table = self::table();

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE product_id = %d AND variation_id = %d AND email = %s AND phone = %s LIMIT 1",
				(int) $product_id,
				(int) $variation_id,
				(string) $email,
				(string) $phone
			)
		);

		return (int) $id;
	}

	/**
	 * Fetch one row.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch one row by token.
	 *
	 * @param string $token 64-char token.
	 * @return array|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;

		$token = (string) $token;
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}

		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update the status of one row and stamp the matching timestamp.
	 *
	 * @param int    $id     Row ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		$data = array( 'status' => $status );
		$now  = gmdate( 'Y-m-d H:i:s' );

		if ( 'confirmed' === $status ) {
			$data['confirmed_at'] = $now;
		}
		if ( 'notified' === $status ) {
			$data['notified_at'] = $now;
		}

		$updated = $wpdb->update( self::table(), $data, array( 'id' => (int) $id ), null, array( '%d' ) );

		return false !== $updated;
	}

	/**
	 * Mark (or unmark) a row as called.
	 *
	 * The shop's real flow is offline: the plugin sends the message, a human
	 * dials the number. This is the one part of that flow the plugin can keep
	 * honestly - which rows have been called, when, and by whom.
	 *
	 * @param int  $id      Row ID.
	 * @param bool $called  Whether the customer was called.
	 * @param int  $user_id Admin user who ticked the box.
	 * @return bool
	 */
	public static function set_called( $id, $called, $user_id = 0 ) {
		global $wpdb;

		$table = self::table();

		if ( $called ) {
			return false !== $wpdb->update(
				$table,
				array(
					'called_at' => gmdate( 'Y-m-d H:i:s' ),
					'called_by' => (int) $user_id,
				),
				array( 'id' => (int) $id ),
				array( '%s', '%d' ),
				array( '%d' )
			);
		}

		return false !== $wpdb->update(
			$table,
			array(
				'called_at' => null,
				'called_by' => 0,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Confirm a subscriber from a token. Returns a machine-readable outcome.
	 *
	 * @param string $token Token.
	 * @return string "confirmed" | "already" | "expired" | "invalid" | "unsubscribed"
	 */
	public static function confirm_by_token( $token ) {
		$row = self::get_by_token( $token );

		if ( null === $row ) {
			return 'invalid';
		}

		if ( 'confirmed' === $row['status'] || 'notified' === $row['status'] ) {
			return 'already';
		}

		if ( 'unsubscribed' === $row['status'] ) {
			return 'unsubscribed';
		}

		if ( ! empty( $row['confirm_expires_at'] ) && strtotime( $row['confirm_expires_at'] ) < time() ) {
			self::set_status( (int) $row['id'], 'expired' );

			return 'expired';
		}

		self::set_status( (int) $row['id'], 'confirmed' );

		return 'confirmed';
	}

	/**
	 * Unsubscribe from a token (no login required, no expiry).
	 *
	 * @param string $token Token.
	 * @return string "unsubscribed" | "already" | "invalid"
	 */
	public static function unsubscribe_by_token( $token ) {
		$row = self::get_by_token( $token );

		if ( null === $row ) {
			return 'invalid';
		}

		if ( 'unsubscribed' === $row['status'] ) {
			return 'already';
		}

		self::set_status( (int) $row['id'], 'unsubscribed' );

		return 'unsubscribed';
	}

	/**
	 * Confirmed subscribers for one product/variation, oldest first.
	 *
	 * Subscribers who already have a restock message queued or delivered are
	 * excluded: a second restock that happens before the queue drains must not
	 * put the same person in the queue twice.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID.
	 * @param int $limit        Max rows (0 = all).
	 * @return array[]
	 */
	public static function confirmed_for( $product_id, $variation_id, $limit = 0 ) {
		global $wpdb;

		$table     = self::table();
		$queue     = self::queue();
		$limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', (int) $limit ) : '';

		$sql = "SELECT w.* FROM {$table} w
				WHERE w.product_id = %d AND w.variation_id = %d AND w.status = 'confirmed'
				AND NOT EXISTS (
					SELECT 1 FROM {$queue} q
					WHERE q.waitlist_id = w.id
					AND q.event_key LIKE 'restock%%'
					AND q.status IN ('queued','processing','sent')
				)
				ORDER BY w.created_at ASC, w.id ASC" . $limit_sql;

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, (int) $product_id, (int) $variation_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count confirmed subscribers that are still waiting for a notification.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID.
	 * @return int
	 */
	public static function count_confirmed( $product_id, $variation_id ) {
		global $wpdb;

		$table = self::table();
		$queue = self::queue();

		$sql = "SELECT COUNT(*) FROM {$table} w
				WHERE w.product_id = %d AND w.variation_id = %d AND w.status = 'confirmed'
				AND NOT EXISTS (
					SELECT 1 FROM {$queue} q
					WHERE q.waitlist_id = w.id
					AND q.event_key LIKE 'restock%%'
					AND q.status IN ('queued','processing','sent')
				)";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, (int) $product_id, (int) $variation_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Mark rows notified (called after a successful dispatch).
	 *
	 * @param int[] $ids Row IDs.
	 * @return int Rows updated.
	 */
	public static function mark_notified( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$now          = gmdate( 'Y-m-d H:i:s' );

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET status = 'notified', notified_at = %s WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( $now ), $ids )
		);

		return (int) $wpdb->query( $sql );
	}

	/**
	 * Paginated admin query.
	 *
	 * @param array $args Filters: product_id, status, channel, from, to, search, per_page, page, orderby, order.
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $args ) {
		global $wpdb;

		$defaults = array(
			'product_id' => 0,
			'status'     => '',
			'channel'    => '',
			'called'     => '',
			'from'       => '',
			'to'         => '',
			'search'     => '',
			'per_page'   => 20,
			'page'       => 1,
			'orderby'    => 'created_at',
			'order'      => 'DESC',
		);

		$args = array_merge( $defaults, $args );

		$where  = array( '1=1' );
		$params = array();

		if ( (int) $args['product_id'] > 0 ) {
			$where[]  = 'product_id = %d';
			$params[] = (int) $args['product_id'];
		}

		if ( '' !== $args['status'] && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( in_array( $args['channel'], array( 'email', 'sms' ), true ) ) {
			$where[]  = 'channel = %s';
			$params[] = $args['channel'];
		}

		if ( '' !== $args['from'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['from'] . ' 00:00:00';
		}

		if ( '' !== $args['to'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['to'] . ' 23:59:59';
		}

		if ( in_array( $args['called'], array( 'yes', 'no' ), true ) ) {
			$where[] = ( 'yes' === $args['called'] ) ? 'called_at IS NOT NULL' : 'called_at IS NULL';
		}

		if ( '' !== $args['search'] ) {
			$like = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = '(email LIKE %s OR phone LIKE %s OR full_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$orderby = in_array( $args['orderby'], array( 'created_at', 'id', 'status', 'product_id' ), true ) ? $args['orderby'] : 'created_at';
		$order   = ( 'ASC' === strtoupper( (string) $args['order'] ) ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$where_sql = implode( ' AND ', $where );
		$table     = self::table();

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, $offset ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Counters for the dashboard.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;

		$table = self::table();

		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$counts = array(
			'pending'      => 0,
			'confirmed'    => 0,
			'notified'     => 0,
			'expired'      => 0,
			'unsubscribed' => 0,
			'total'        => 0,
			'called'       => 0,
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$status = (string) $row['status'];
				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ] = (int) $row['c'];
				}
				$counts['total'] += (int) $row['c'];
			}
		}

		// A scalar query instead of a second GROUP BY: "called" is not a status,
		// and a row can be called while it is still confirmed or notified.
		$counts['called'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE called_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $counts;
	}

	/**
	 * Delete rows older than N days (retention policy).
	 *
	 * @param int $days Days.
	 * @return int Rows deleted.
	 */
	public static function prune( $days ) {
		global $wpdb;

		$days = max( 1, (int) $days );
		$cut  = gmdate( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );
		$table = self::table();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE created_at < %s AND status IN ('notified','expired','unsubscribed')",
				$cut
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids = array_map( 'intval', $ids );
		Queue::delete_for_waitlists( $ids );

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Snapshot rows keyed by "product:variation".
	 *
	 * @return array<string,array>
	 */
	public static function snapshots_all() {
		global $wpdb;

		$table = self::snapshots();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out   = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$key         = $row['product_id'] . ':' . $row['variation_id'];
				$out[ $key ] = array(
					'status'        => (string) $row['stock_status'],
					'qty'           => (int) $row['stock_qty'],
					'is_managed'    => (int) $row['is_managed'],
					'announced_qty' => (int) $row['announced_qty'],
				);
			}
		}

		return $out;
	}

	/**
	 * Snapshot for one product/variation.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID.
	 * @return array|null
	 */
	public static function snapshot_get( $product_id, $variation_id ) {
		global $wpdb;

		$table = self::snapshots();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d AND variation_id = %d",
				(int) $product_id,
				(int) $variation_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert or update one snapshot row.
	 *
	 * @param int    $product_id   Product ID.
	 * @param int    $variation_id Variation ID.
	 * @param string $status       Stock status.
	 * @param int    $qty          Quantity.
	 * @param bool   $is_managed   Whether stock is managed.
	 * @return void
	 */
	public static function snapshot_put( $product_id, $variation_id, $status, $qty, $is_managed = true ) {
		global $wpdb;

		$table = self::snapshots();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (product_id, variation_id, stock_status, stock_qty, is_managed, checked_at)
			 VALUES (%d, %d, %s, %d, %d, %s)
			 ON DUPLICATE KEY UPDATE stock_status = VALUES(stock_status), stock_qty = VALUES(stock_qty),
			 is_managed = VALUES(is_managed), checked_at = VALUES(checked_at)",
			(int) $product_id,
			(int) $variation_id,
			(string) $status,
			(int) $qty,
			$is_managed ? 1 : 0,
			$now
		);

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Atomically claim a restock transition.
	 *
	 * Only one caller can flip a snapshot from an unavailable status to a
	 * sellable one; the second caller (a duplicate hook, a second cron worker)
	 * sees zero affected rows and therefore sends nothing. This is the repeat
	 * control for the whole plugin.
	 *
	 * @param int    $product_id   Product ID.
	 * @param int    $variation_id Variation ID.
	 * @param string $new_status   New stock status.
	 * @param int    $qty          New quantity.
	 * @param bool   $is_managed   Whether stock is managed.
	 * @return bool True when this caller won the claim.
	 */
	public static function claim_transition( $product_id, $variation_id, $new_status, $qty, $is_managed = true ) {
		global $wpdb;

		$table = self::snapshots();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$sql = $wpdb->prepare(
			"UPDATE {$table}
			 SET stock_status = %s, stock_qty = %d, is_managed = %d, checked_at = %s
			 WHERE product_id = %d AND variation_id = %d AND stock_status IN ('outofstock','onbackorder')",
			(string) $new_status,
			(int) $qty,
			$is_managed ? 1 : 0,
			$now,
			(int) $product_id,
			(int) $variation_id
		);

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return ( (int) $wpdb->rows_affected ) > 0;
	}

	/**
	 * Record the announced quantity baseline for a product/variation.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID.
	 * @param int $qty          Quantity announced at.
	 * @return void
	 */
	public static function set_announced_qty( $product_id, $variation_id, $qty ) {
		global $wpdb;

		$table = self::snapshots();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET announced_qty = %d, announced_at = %s WHERE product_id = %d AND variation_id = %d",
				(int) $qty,
				$now,
				(int) $product_id,
				(int) $variation_id
			)
		);
	}

	/**
	 * Oldest unprocessed confirmation requests (used by the "resend" tool).
	 *
	 * @param int $limit How many.
	 * @return array[]
	 */
	public static function pending_confirmations( $limit = 50 ) {
		global $wpdb;

		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d",
				(int) $limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
