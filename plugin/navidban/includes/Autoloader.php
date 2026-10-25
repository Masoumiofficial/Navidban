<?php
/**
 * Minimal PSR-4 autoloader. No Composer, no generated maps, no external files.
 *
 * @package Navidban
 */

namespace Navidban;

defined( 'ABSPATH' ) || exit;

/**
 * Class Autoloader
 */
final class Autoloader {

	/**
	 * PSR-4 prefixes mapped to directories (relative to the plugin root).
	 *
	 * @var array<string,string>
	 */
	private static $prefixes = array(
		'Navidban\\Support\\'  => 'includes/Support/',
		'Navidban\\Logic\\'    => 'includes/Logic/',
		'Navidban\\Channels\\' => 'includes/Channels/',
		'Navidban\\Admin\\'    => 'admin/',
		'Navidban\\'           => 'includes/',
	);

	/**
	 * Explicit class map for files whose name does not follow PSR-4.
	 *
	 * @var array<string,string>
	 */
	private static $classmap = array(
		'Navidban\\Admin\\Admin' => 'admin/class-admin.php',
	);

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Resolve a fully qualified class name to a file and require it.
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public static function load( $class ) {
		if ( isset( self::$classmap[ $class ] ) ) {
			$file = NAVIDBAN_DIR . self::$classmap[ $class ];
			if ( is_readable( $file ) ) {
				require_once $file;
			}
			return;
		}

		foreach ( self::$prefixes as $prefix => $dir ) {
			$length = strlen( $prefix );
			if ( 0 !== strncmp( $prefix, $class, $length ) ) {
				continue;
			}

			$relative = substr( $class, $length );
			if ( '' === $relative ) {
				continue;
			}

			$path = NAVIDBAN_DIR . $dir . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
			return;
		}
	}

	/**
	 * Prefix map, exposed for the release verifier and the test harness.
	 *
	 * @return array<string,string>
	 */
	public static function prefixes() {
		return self::$prefixes;
	}
}
