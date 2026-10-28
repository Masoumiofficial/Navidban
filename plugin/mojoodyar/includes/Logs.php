<?php
/**
 * Read side of the log table: filters, paging, counters, cleanup.
 *
 * The log screen exists because "the plugin does nothing" is almost never a
 * thing the plugin can tell you from a single table: a rejection, a failed
 * delivery and an empty queue look identical from the outside. Every query
 * here is read-only except the explicit cleanup methods.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

use Mojoodyar\Logic\LogExplain;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logs
 */
final class Logs {

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 50;

	/**
	 * Levels offered in the filter, in the order they matter.
	 *
	 * @var string[]
	 */
	const FILTER_LEVELS = array( 'security', 'error', 'warning', 'info' );

	/**
	 * Periods offered in the filter, in days (0 = everything).
	 *
	 * @var int[]
	 */
	const RANGES = array( 1, 7, 30, 0 );

	/**
	 * Build a WHERE clause and its values from request-like arguments.
	 *
	 * Every value goes through a placeholder; the only string that is
	 * interpolated is the table name, which comes from Database.
	 *
	 * @param array $args level|event|search|days.
	 * @return array{where:string,values:array}
	 */
	private static function where( array $args ) {
		global $wpdb;

		$clauses = array( '1=1' );
		$values  = array();

		$level = isset( $args['level'] ) ? (string) $args['level'] : '';

		if ( '' !== $level && in_array( $level, self::FILTER_LEVELS, true ) ) {
			$clauses[] = 'level = %s';
			$values[]  = $level;
		}

		$event = isset( $args['event'] ) ? (string) $args['event'] : '';

		if ( '' !== $event ) {
			$clauses[] = 'event = %s';
			$values[]  = $event;
		}

		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		if ( '' !== $search ) {
			// A visitor's text is a substring here, not a pattern. The escape
			// character is '!' rather than the usual backslash because MySQL and
			// SQLite disagree about backslash inside a string literal; '!' is a
			// plain character in both, so the same SQL means the same thing in
			// production and in the harness.
			$clauses[] = "(context LIKE %s ESCAPE '!' OR event LIKE %s ESCAPE '!')";
			$like      = '%' . self::escape_like( $search ) . '%';
			$values[]  = $like;
			$values[]  = $like;
		}

		$days = isset( $args['days'] ) ? (int) $args['days'] : 0;

		if ( $days > 0 ) {
			$clauses[] = 'created_at >= %s';
			$values[]  = gmdate( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );
		}

		return array(
			'where'  => implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * Escape the three characters that mean something inside LIKE.
	 *
	 * @param string $text Raw search text.
	 * @return string
	 */
	private static function escape_like( $text ) {
		return str_replace( array( '!', '%', '_' ), array( '!!', '!%', '!_' ), (string) $text );
	}

	/**
	 * Fetch one page of log rows.
	 *
	 * @param array $args level|event|search|days|page|per_page.
	 * @return array{rows:array[],total:int,pages:int,page:int}
	 */
	public static function query( array $args ) {
		global $wpdb;

		$table    = Database::table( Database::LOGS );
		$where    = self::where( $args );
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 500, (int) $args['per_page'] ) ) : self::PER_PAGE;
		$offset   = ( $page - 1 ) * $per_page;

		$sql = "SELECT * FROM {$table} WHERE {$where['where']} ORDER BY id DESC LIMIT %d OFFSET %d";

		$values   = $where['values'];
		$values[] = $per_page;
		$values[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where['where']}";

		$total = (int) ( empty( $where['values'] )
			? $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $wpdb->prepare( $count_sql, $where['values'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
			'page'  => $page,
		);
	}

	/**
	 * Counters for the summary cards.
	 *
	 * @return array{total:int,today:int,problems:int,security:int,errors:int}
	 */
	public static function summary() {
		global $wpdb;

		$table = Database::table( Database::LOGS );
		$day   = gmdate( 'Y-m-d H:i:s', time() - 86400 );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
					SUM(CASE WHEN level IN ('warning','error','security') THEN 1 ELSE 0 END) AS problems,
					SUM(CASE WHEN level = 'security' THEN 1 ELSE 0 END) AS security,
					SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) AS errors,
					SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS today
				 FROM {$table}",
				$day
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$row = is_array( $row ) ? $row : array();

		return array(
			'total'    => (int) ( isset( $row['total'] ) ? $row['total'] : 0 ),
			'today'    => (int) ( isset( $row['today'] ) ? $row['today'] : 0 ),
			'problems' => (int) ( isset( $row['problems'] ) ? $row['problems'] : 0 ),
			'security' => (int) ( isset( $row['security'] ) ? $row['security'] : 0 ),
			'errors'   => (int) ( isset( $row['errors'] ) ? $row['errors'] : 0 ),
		);
	}

	/**
	 * Event keys actually present in the table, with counts.
	 *
	 * The dropdown shows what exists, so an empty filter value is impossible to
	 * pick by accident.
	 *
	 * @return array[] array( event, count, label )
	 */
	public static function events_present() {
		global $wpdb;

		$table = Database::table( Database::LOGS );

		$rows = $wpdb->get_results(
			"SELECT event, COUNT(*) AS c FROM {$table} GROUP BY event ORDER BY c DESC",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$row = (array) $row;

			$out[] = array(
				'event' => (string) $row['event'],
				'count' => (int) $row['c'],
				'label' => LogExplain::event( (string) $row['event'] ),
			);
		}

		return $out;
	}

	/**
	 * The newest rows an admin should not miss, for the dashboard panel.
	 *
	 * @param int $limit How many.
	 * @return array[]
	 */
	public static function recent_problems( $limit = 5 ) {
		global $wpdb;

		$table = Database::table( Database::LOGS );
		$limit = max( 1, (int) $limit );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE level IN ('warning','error','security')
				 ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete every log row.
	 *
	 * @return int Rows deleted.
	 */
	public static function clear() {
		global $wpdb;

		$table = Database::table( Database::LOGS );

		return (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Which events the panel currently has no translation for.
	 *
	 * Used by a test so a new logger call cannot ship an English key to the
	 * screen, and by the screen to fall back honestly.
	 *
	 * @return string[]
	 */
	public static function untranslated_events() {
		$known = array_keys( LogExplain::events() );
		$out   = array();

		foreach ( self::events_present() as $row ) {
			if ( ! in_array( $row['event'], $known, true ) ) {
				$out[] = $row['event'];
			}
		}

		return $out;
	}
}
