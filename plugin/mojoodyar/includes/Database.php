<?php
/**
 * Table definitions and upgrades. Every table is InnoDB with an explicit charset.
 *
 * Why InnoDB and not MyISAM (a documented weakness of the free competitor):
 *  - row-level locking instead of table locks, so a queue run cannot block the storefront;
 *  - transactions, which we need for "insert waitlist row + enqueue notification";
 *  - crash safety.
 *
 * Why a dedicated table instead of postmeta: a waitlist row is a first-class
 * record that is queried by status/product and exported in bulk; postmeta joins
 * do not scale for that and pollute the postmeta table of every product.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

defined( 'ABSPATH' ) || exit;

/**
 * Class Database
 */
final class Database {

	/**
	 * Waitlist table (without prefix).
	 *
	 * @var string
	 */
	const WAITLISTS = 'mjy_waitlists';

	/**
	 * Stock snapshot table (without prefix).
	 *
	 * @var string
	 */
	const SNAPSHOTS = 'mjy_stock_snapshots';

	/**
	 * Queue table (without prefix).
	 *
	 * @var string
	 */
	const QUEUE = 'mjy_queue';

	/**
	 * Log table (without prefix).
	 *
	 * @var string
	 */
	const LOGS = 'mjy_logs';

	/**
	 * Phone-follow-up log (one row per call attempt).
	 *
	 * @var string
	 */
	const CALL_LOG = 'mjy_call_log';

	/**
	 * Fully qualified table name.
	 *
	 * @param string $table Table constant.
	 * @return string
	 */
	public static function table( $table ) {
		global $wpdb;

		return $wpdb->prefix . $table;
	}

	/**
	 * All plugin tables.
	 *
	 * @return string[]
	 */
	public static function tables() {
		return array(
			self::table( self::WAITLISTS ),
			self::table( self::SNAPSHOTS ),
			self::table( self::QUEUE ),
			self::table( self::LOGS ),
			self::table( self::CALL_LOG ),
		);
	}

	/**
	 * Create or upgrade the schema.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();
		$wait    = self::table( self::WAITLISTS );
		$snap    = self::table( self::SNAPSHOTS );
		$queue   = self::table( self::QUEUE );
		$logs    = self::table( self::LOGS );

		// NOTE: email/phone are NOT NULL DEFAULT '' on purpose. MySQL treats
		// NULLs as distinct inside a UNIQUE key, which would let the same
		// visitor register twice. Empty strings keep uniq_subscriber effective
		// for email-only and sms-only rows.
		$sql_waitlists = "CREATE TABLE {$wait} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token CHAR(64) NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event_type VARCHAR(32) NOT NULL DEFAULT 'restock',
			channel VARCHAR(16) NOT NULL DEFAULT 'email',
			email VARCHAR(190) NOT NULL DEFAULT '',
			phone VARCHAR(20) NOT NULL DEFAULT '',
			full_name VARCHAR(120) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			consent_at DATETIME DEFAULT NULL,
			confirmed_at DATETIME DEFAULT NULL,
			notified_at DATETIME DEFAULT NULL,
			confirm_expires_at DATETIME DEFAULT NULL,
			stock_at_signup INT NOT NULL DEFAULT 0,
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			called_at DATETIME DEFAULT NULL,
			called_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			call_state VARCHAR(16) NOT NULL DEFAULT 'open',
			call_closed_at DATETIME DEFAULT NULL,
			call_closed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			call_close_reason VARCHAR(32) NOT NULL DEFAULT '',
			call_close_note VARCHAR(190) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_token (token),
			UNIQUE KEY uniq_subscriber (product_id, variation_id, email, phone),
			KEY idx_status_product (status, product_id, variation_id),
			KEY idx_status_created (status, created_at),
			KEY idx_phone (phone),
			KEY idx_email (email),
			KEY idx_called (called_at),
			KEY idx_call_state (call_state),
			KEY idx_name (full_name)
		) ENGINE=InnoDB {$charset};";

		$sql_snapshots = "CREATE TABLE {$snap} (
			product_id BIGINT UNSIGNED NOT NULL,
			variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			stock_status VARCHAR(16) NOT NULL,
			stock_qty INT NOT NULL DEFAULT 0,
			is_managed TINYINT(1) NOT NULL DEFAULT 1,
			announced_qty INT NOT NULL DEFAULT 0,
			announced_at DATETIME DEFAULT NULL,
			checked_at DATETIME NOT NULL,
			PRIMARY KEY  (product_id, variation_id),
			KEY idx_status (stock_status, checked_at)
		) ENGINE=InnoDB {$charset};";

		$sql_queue = "CREATE TABLE {$queue} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			waitlist_id BIGINT UNSIGNED NOT NULL,
			channel VARCHAR(16) NOT NULL,
			event_key VARCHAR(100) NOT NULL DEFAULT '',
			payload_hash CHAR(64) NOT NULL,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'queued',
			next_run_at DATETIME NOT NULL,
			last_error VARCHAR(255) DEFAULT NULL,
			locked_at DATETIME DEFAULT NULL,
			sent_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_payload (waitlist_id, payload_hash),
			KEY idx_run (status, next_run_at),
			KEY idx_waitlist (waitlist_id),
			KEY idx_sent (status, sent_at)
		) ENGINE=InnoDB {$charset};";

		$sql_logs = "CREATE TABLE {$logs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(16) NOT NULL DEFAULT 'info',
			event VARCHAR(64) NOT NULL,
			context VARCHAR(255) NOT NULL DEFAULT '',
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_event_created (event, created_at),
			KEY idx_level (level)
		) ENGINE=InnoDB {$charset};";

		$call_log = self::table( self::CALL_LOG );

		// One row per phone attempt. The stage number is not stored as a
		// counter on the waitlist row: it is the number of rows that exist for
		// that subscriber, so two admins saving at the same time cannot both
		// produce "تماس دوم".
		$sql_call_log = "CREATE TABLE {$call_log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			waitlist_id BIGINT UNSIGNED NOT NULL,
			stage INT UNSIGNED NOT NULL DEFAULT 1,
			outcome VARCHAR(32) NOT NULL DEFAULT '',
			note VARCHAR(500) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_waitlist_stage (waitlist_id, stage),
			KEY idx_created (created_at)
		) ENGINE=InnoDB {$charset};";

		dbDelta( $sql_waitlists );
		dbDelta( $sql_snapshots );
		dbDelta( $sql_queue );
		dbDelta( $sql_logs );
		dbDelta( $sql_call_log );

		self::force_innodb();

		if ( function_exists( 'update_option' ) ) {
			update_option( 'mojoodyar_db_version', MOJODYAR_DB_VERSION, false );
		}
	}

	/**
	 * dbDelta can silently keep an existing engine. Verify and correct it.
	 *
	 * @return void
	 */
	public static function force_innodb() {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
			if ( ! is_array( $row ) || empty( $row['Engine'] ) ) {
				continue;
			}

			if ( 'InnoDB' !== $row['Engine'] ) {
				$wpdb->query( 'ALTER TABLE `' . str_replace( '`', '', $table ) . '` ENGINE=InnoDB' );
			}
		}
	}

	/**
	 * Do all tables exist with the expected engine? Used by the health check.
	 *
	 * @return array<string,string> table => engine (or "missing").
	 */
	public static function inspect() {
		global $wpdb;

		$result = array();

		foreach ( self::tables() as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );

			if ( ! is_array( $row ) || empty( $row['Engine'] ) ) {
				$result[ $table ] = 'missing';
				continue;
			}

			$result[ $table ] = (string) $row['Engine'];
		}

		return $result;
	}

	/**
	 * Drop every plugin table (used only by uninstall when explicitly allowed).
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $table ) . '`' );
		}
	}
}
