<?php
/**
 * A deliberately small WordPress stand-in used by tests/integration.php.
 *
 * It is NOT a WordPress replacement: it implements exactly the functions the
 * plugin touches, backed by SQLite, so the real plugin classes can be executed
 * without a web server. Anything not implemented here would fail loudly with a
 * fatal error rather than silently succeed, which is what we want from a test
 * harness.
 *
 * @package Mojoodyar
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'MOJODYAR_FILE', __DIR__ . '/../../mojoodyar.php' );
define( 'MOJODYAR_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'MOJODYAR_URL', 'https://shop.test/wp-content/plugins/mojoodyar/' );
define( 'MOJODYAR_BASENAME', 'mojoodyar/mojoodyar.php' );
// Version comes from the plugin header, so a test report can never claim a
// version that the shipped file does not declare.
$mjy_header_source = (string) @file_get_contents( MOJODYAR_FILE );
$mjy_header_match  = array();
define(
	'MOJODYAR_VERSION',
	preg_match( '~^[ \t/*#@]*Version:\s*([0-9][0-9.\-a-zA-Z]*)~mi', $mjy_header_source, $mjy_header_match ) ? trim( $mjy_header_match[1] ) : '0.0.0'
);
define( 'MOJODYAR_DB_VERSION', '2' );
define( 'AUTH_SALT', 'test-salt' );
define( 'ARRAY_A', 'ARRAY_A' );

/**
 * Tiny WP_Error stand-in.
 */
class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

/**
 * $wpdb stand-in on top of SQLite.
 */
class MjyWpdb {

	public $prefix = 'wp_';
	public $insert_id = 0;
	public $rows_affected = 0;
	public $last_error = '';
	public $queries = array();

	/** @var PDO */
	private $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}

	public function get_charset_collate() {
		return '';
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$index = 0;

		return preg_replace_callback(
			'/%[sdf]/',
			function ( $match ) use ( $args, &$index ) {
				$value = $args[ $index ] ?? '';
				$index++;

				if ( '%d' === $match[0] ) {
					return (string) (int) $value;
				}
				if ( '%f' === $match[0] ) {
					return (string) (float) $value;
				}

				return $this->pdo->quote( (string) $value );
			},
			$query
		);
	}

	private function translate( $sql ) {
		$sql = trim( $sql );

		$sql = preg_replace( '/^INSERT IGNORE INTO/i', 'INSERT OR IGNORE INTO', $sql );
		$sql = preg_replace( '/^TRUNCATE TABLE\s+(\S+)/i', 'DELETE FROM $1', $sql );
		// The 2.0 rename migration moves tables to the new prefix. MySQL
		// spells it RENAME TABLE; SQLite spells it ALTER TABLE ... RENAME TO.
		$sql = preg_replace( '/^RENAME TABLE\s+(\S+)\s+TO\s+(\S+)/i', 'ALTER TABLE $1 RENAME TO $2', $sql );
		$sql = preg_replace( '/\bVALUES\(([a-zA-Z_]+)\)/i', 'excluded.$1', $sql );
		$sql = preg_replace( '/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT DO UPDATE SET', $sql );
		$sql = preg_replace( '/\)\s*ENGINE=\w+.*$/is', ')', $sql );

		return $sql;
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		$this->last_error = '';

		$sql = trim( $sql );

		if ( preg_match( '/^(START TRANSACTION|BEGIN)$/i', $sql ) ) {
			$this->pdo->beginTransaction();
			return 1;
		}
		if ( preg_match( '/^COMMIT$/i', $sql ) ) {
			$this->pdo->commit();
			return 1;
		}
		if ( preg_match( '/^ROLLBACK$/i', $sql ) ) {
			$this->pdo->rollBack();
			return 1;
		}

		try {
			$translated = $this->translate( $sql );
			$statement  = $this->pdo->query( $translated );
			$this->rows_affected = $statement ? $statement->rowCount() : 0;

			return (int) $this->rows_affected;
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}
	}

	public function get_results( $sql, $output = ARRAY_A ) {
		if ( preg_match( '/^SHOW TABLES LIKE\s+[\'\"](.+?)[\'\"]$/i', trim( $sql ), $m ) ) {
			$exists = $this->pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace( "'", "''", $m[1] ) . "'" )->fetchAll( PDO::FETCH_ASSOC );

			return $exists ? array( array( 'name' => $m[1] ) ) : array();
		}

		// The harness has no storage engines. Returning the declared engine keeps
		// Database::inspect() exercisable; the InnoDB claim itself is asserted on
		// the generated DDL text in tests/integration.php.
		if ( preg_match( '/SHOW TABLE STATUS LIKE\s+%?s?[\'"]?(.+?)[\'"]?$/i', trim( $sql ), $m ) ) {
			$table = trim( $m[1], "\"' " );
			$exists = $this->pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace( "'", "''", $table ) . "'" )->fetchAll();

			return $exists ? array( array( 'Name' => $table, 'Engine' => 'InnoDB' ) ) : array();
		}

		try {
			$statement = $this->pdo->query( $this->translate( $sql ) );

			return $statement ? $statement->fetchAll( PDO::FETCH_ASSOC ) : array();
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();

			return array();
		}
	}

	public function get_row( $sql, $output = ARRAY_A ) {
		$rows = $this->get_results( $sql, $output );

		return isset( $rows[0] ) ? $rows[0] : null;
	}

	public function get_var( $sql ) {
		$rows = $this->get_results( $sql );

		if ( empty( $rows ) ) {
			return null;
		}

		$row = $rows[0];

		return isset( $row[0] ) ? $row[0] : reset( $row );
	}

	public function get_col( $sql ) {
		$rows = $this->get_results( $sql );
		$out  = array();

		foreach ( $rows as $row ) {
			$out[] = reset( $row );
		}

		return $out;
	}

	public function insert( $table, $data, $format = null ) {
		$columns = array_keys( $data );
		$values  = array();

		foreach ( $data as $value ) {
			$values[] = is_null( $value ) ? 'NULL' : $this->pdo->quote( (string) $value );
		}

		$sql = 'INSERT INTO ' . $table . ' (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ')';

		try {
			$this->pdo->exec( $sql );
			$this->insert_id     = (int) $this->pdo->lastInsertId();
			$this->rows_affected = 1;

			return 1;
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			$this->rows_affected = 0;

			return false;
		}
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$sets = array();

		foreach ( $data as $column => $value ) {
			$sets[] = $column . ' = ' . ( is_null( $value ) ? 'NULL' : $this->pdo->quote( (string) $value ) );
		}

		$conditions = array();
		foreach ( $where as $column => $value ) {
			$conditions[] = $column . ' = ' . $this->pdo->quote( (string) $value );
		}

		$sql = 'UPDATE ' . $table . ' SET ' . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $conditions );

		try {
			$statement           = $this->pdo->query( $sql );
			$this->rows_affected = $statement ? $statement->rowCount() : 0;

			return (int) $this->rows_affected;
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}
}

/* -------------------------------------------------------------------------
 * Options, transients, hooks
 * ---------------------------------------------------------------------- */

$GLOBALS['mjy_options']    = array();
$GLOBALS['mjy_transients'] = array();
$GLOBALS['mjy_actions']    = array();
$GLOBALS['mjy_cron']       = array();
$GLOBALS['mjy_enqueued']   = array();
$GLOBALS['mjy_mails']      = array();
$GLOBALS['mjy_http_calls'] = array();
$GLOBALS['mjy_http_response'] = null;

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['mjy_options'] ) ? $GLOBALS['mjy_options'][ $name ] : $default;
}

function add_option( $name, $value, $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, $GLOBALS['mjy_options'] ) ) {
		return false;
	}
	$GLOBALS['mjy_options'][ $name ] = $value;

	return true;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['mjy_options'][ $name ] = $value;

	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['mjy_options'][ $name ] );

	return true;
}

function set_transient( $name, $value, $ttl = 0 ) {
	$GLOBALS['mjy_transients'][ $name ] = $value;

	return true;
}

function get_transient( $name ) {
	return $GLOBALS['mjy_transients'][ $name ] ?? false;
}

function delete_transient( $name ) {
	unset( $GLOBALS['mjy_transients'][ $name ] );

	return true;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['mjy_actions'][ $hook ][ $priority ][] = $callback;

	return true;
}

function remove_action( $hook, $callback, $priority = 10 ) {
	if ( empty( $GLOBALS['mjy_actions'][ $hook ][ $priority ] ) ) {
		return false;
	}

	foreach ( $GLOBALS['mjy_actions'][ $hook ][ $priority ] as $index => $existing ) {
		if ( $existing === $callback ) {
			unset( $GLOBALS['mjy_actions'][ $hook ][ $priority ][ $index ] );
			$GLOBALS['mjy_actions'][ $hook ][ $priority ] = array_values( $GLOBALS['mjy_actions'][ $hook ][ $priority ] );

			return true;
		}
	}

	return false;
}

function remove_all_actions( $hook ) {
	unset( $GLOBALS['mjy_actions'][ $hook ] );
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function do_action( $hook, ...$args ) {
	if ( empty( $GLOBALS['mjy_actions'][ $hook ] ) ) {
		return;
	}

	ksort( $GLOBALS['mjy_actions'][ $hook ] );

	foreach ( $GLOBALS['mjy_actions'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			call_user_func_array( $callback, $args );
		}
	}
}

function has_action( $hook ) {
	return ! empty( $GLOBALS['mjy_actions'][ $hook ] );
}

function wp_next_scheduled( $hook ) {
	return $GLOBALS['mjy_cron'][ $hook ] ?? false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$GLOBALS['mjy_cron'][ $hook ] = $timestamp;

	return true;
}

function wp_clear_scheduled_hook( $hook ) {
	unset( $GLOBALS['mjy_cron'][ $hook ] );

	return true;
}

function wp_enqueue_style( $handle, $src, $deps = array(), $version = false ) {
	$GLOBALS['mjy_enqueued']['style'][] = $handle;
}

function wp_enqueue_script( $handle, $src, $deps = array(), $version = false, $footer = false ) {
	$GLOBALS['mjy_enqueued']['script'][] = $handle;
}

function wp_localize_script( $handle, $name, $data ) {
	$GLOBALS['mjy_enqueued']['localized'][ $name ] = $data;

	return true;
}

/* -------------------------------------------------------------------------
 * Escaping / sanitising / i18n
 * ---------------------------------------------------------------------- */

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_textarea( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $value ) {
	return filter_var( (string) $value, FILTER_SANITIZE_URL );
}

function esc_url_raw( $value ) {
	return esc_url( $value );
}

function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_strip_all_tags( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function __( $text, $domain = null ) {
	unset( $domain );

	return $text;
}

function esc_html__( $text, $domain = null ) {
	return esc_html( __( $text, $domain ) );
}

function load_plugin_textdomain( $domain, $deprecated = false, $path = '' ) {
	unset( $domain, $deprecated, $path );

	return true;
}

/* -------------------------------------------------------------------------
 * URLs, redirects, misc
 * ---------------------------------------------------------------------- */

function home_url( $path = '/' ) {
	return 'https://shop.test' . ( '/' === $path ? '/' : '/' . ltrim( $path, '/' ) );
}

function admin_url( $path = '' ) {
	return 'https://shop.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function plugin_dir_url( $file ) {
	unset( $file );

	return MOJODYAR_URL;
}

function plugin_dir_path( $file ) {
	unset( $file );

	return MOJODYAR_DIR;
}

function plugin_basename( $file ) {
	unset( $file );

	return MOJODYAR_BASENAME;
}

function register_activation_hook( $file, $callback ) {
	unset( $file, $callback );
}

function register_deactivation_hook( $file, $callback ) {
	unset( $file, $callback );
}

function add_query_arg( $key, $value = null, $url = null ) {
	if ( is_array( $key ) ) {
		$args = $key;
		$url  = $value;
	} else {
		$args = array( $key => $value );
	}

	$url = (string) ( $url ? $url : home_url( '/' ) );
	$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';

	return $url . $sep . http_build_query( $args );
}

function wp_safe_redirect( $url ) {
	$GLOBALS['mjy_redirect'] = $url;

	return true;
}

function status_header( $code ) {
	$GLOBALS['mjy_status'] = (int) $code;
}

function nocache_headers() {}

function wp_die( $message ) {
	throw new RuntimeException( 'wp_die: ' . $message );
}

function is_admin() {
	return (bool) ( $GLOBALS['mjy_is_admin'] ?? false );
}

function wp_doing_ajax() {
	return (bool) ( $GLOBALS['mjy_is_ajax'] ?? false );
}

function is_product() {
	return (bool) ( $GLOBALS['mjy_is_product'] ?? false );
}

function is_feed() {
	return false;
}

function is_robots() {
	return false;
}

function get_queried_object_id() {
	return (int) ( $GLOBALS['mjy_queried_id'] ?? 0 );
}

function get_the_title( $id ) {
	return $GLOBALS['mjy_titles'][ (int) $id ] ?? ( 'محصول ' . $id );
}

function get_permalink( $id ) {
	return 'https://shop.test/product/' . (int) $id . '/';
}

function current_time( $type ) {
	if ( 'G' === $type ) {
		return (int) gmdate( 'G' );
	}

	return gmdate( 'Y-m-d H:i:s' );
}

function get_bloginfo( $what ) {
	unset( $what );

	return 'فروشگاه تست';
}

function get_privacy_policy_url() {
	return 'https://shop.test/privacy/';
}

function get_current_user_id() {
	return (int) ( $GLOBALS['mjy_user_id'] ?? 0 );
}

function get_userdata( $id ) {
	if ( empty( $GLOBALS['mjy_users'][ (int) $id ] ) ) {
		return false;
	}

	$user              = new stdClass();
	$user->ID          = (int) $id;
	$user->user_email  = $GLOBALS['mjy_users'][ (int) $id ]['email'];
	$user->display_name = $GLOBALS['mjy_users'][ (int) $id ]['name'];

	return $user;
}

function get_user_meta( $id, $key, $single = false ) {
	unset( $single );

	return $GLOBALS['mjy_user_meta'][ (int) $id ][ $key ] ?? '';
}

function current_user_can( $cap ) {
	return (bool) ( $GLOBALS['mjy_caps'][ $cap ] ?? false );
}

function wp_create_nonce( $action ) {
	return 'nonce-' . substr( md5( (string) $action ), 0, 10 );
}

function wp_verify_nonce( $nonce, $action ) {
	return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : false;
}

function check_ajax_referer( $action, $name = false, $stop = true ) {
	unset( $name, $stop );

	return isset( $_POST['nonce'] ) && wp_verify_nonce( $_POST['nonce'], $action );
}

function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['mjy_rest'][] = array( $namespace, $route, $args );

	return true;
}

function rest_url( $path ) {
	return 'https://shop.test/wp-json/' . $path;
}

function rest_ensure_response( $data ) {
	$response = new stdClass();
	$response->data = $data;
	$response->status = 200;
	$response->set_status = function ( $code ) use ( $response ) {
		$response->status = (int) $code;
	};

	return $response;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_mail( $to, $subject, $message, $headers = array() ) {
	$GLOBALS['mjy_mails'][] = array(
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
		'headers' => $headers,
	);

	$ok = (bool) ( $GLOBALS['mjy_mail_ok'] ?? true );

	if ( ! $ok ) {
		// Real WordPress does exactly this when PHPMailer throws.
		do_action(
			'wp_mail_failed',
			new WP_Error( 'wp_mail_failed', (string) ( $GLOBALS['mjy_mail_error'] ?? '' ) )
		);
	}

	return $ok;
}

function wp_remote_post( $url, $args ) {
	$GLOBALS['mjy_http_calls'][] = array( $url, $args );

	$response = $GLOBALS['mjy_http_response'];

	if ( null === $response ) {
		return new WP_Error( 'http_request_failed', 'Simulated transport failure' );
	}

	return $response;
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 0;
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

function wp_roles() {
	$roles          = new stdClass();
	$roles->roles   = array( 'administrator' => array(), 'shop_manager' => array() );

	return $roles;
}

function get_role( $name ) {
	unset( $name );

	return new class() {
		public $caps = array();

		public function add_cap( $cap ) {
			$this->caps[ $cap ] = true;
		}

		public function remove_cap( $cap ) {
			unset( $this->caps[ $cap ] );
		}
	};
}

function is_multisite() {
	return false;
}

function dbDelta( $sql ) {
	$GLOBALS['mjy_ddl'][] = $sql;

	$statements = preg_split( '/;\s*\n/', rtrim( $sql, ";\n " ) );

	foreach ( $statements as $statement ) {
		$statement = trim( $statement );

		if ( '' === $statement ) {
			continue;
		}

		if ( ! preg_match( '/^CREATE TABLE\s+(\S+)\s*\((.*)\)/is', $statement, $matches ) ) {
			continue;
		}

		$table = $matches[1];
		$body  = $matches[2];

		$indexes = array();

		$body = preg_replace_callback(
			'/^\s*(UNIQUE\s+)?KEY\s+(\w+)\s*\(([^)]+)\),?\s*$/im',
			function ( $m ) use ( &$indexes ) {
				$indexes[] = 'CREATE ' . trim( $m[1] ) . ' INDEX ' . $m[2] . ' ON ' . '__TABLE__' . ' (' . $m[3] . ')';

				return '';
			},
			$body
		);

		$body = preg_replace( '/^\s*PRIMARY KEY\s+\(id\),?\s*$/im', '', $body );
		$body = preg_replace( '/BIGINT UNSIGNED NOT NULL AUTO_INCREMENT/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $body );
		$body = rtrim( trim( $body ), ", \n\r\t" );

		try {
			$GLOBALS['wpdb']->query( 'CREATE TABLE IF NOT EXISTS ' . $table . ' (' . $body . ')' );
		} catch ( Throwable $e ) {
			$GLOBALS['wpdb']->last_error = $e->getMessage();
			continue;
		}

		foreach ( $indexes as $index ) {
			$index = str_replace( '__TABLE__', $table, $index );
			if ( ! preg_match( '/INDEX\s+(\w+)/', $index, $name_match ) ) {
				continue;
			}
			$index = preg_replace( '/INDEX\s+\w+\s+ON/', 'INDEX ' . $name_match[1] . ' ON', $index );
			$GLOBALS['wpdb']->query( $index );
		}
	}
}

$GLOBALS['wpdb'] = new MjyWpdb();
$GLOBALS['mjy_ddl'] = array();
