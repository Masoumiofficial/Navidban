<?php
/**
 * Minimal PSR-4 autoloader. No Composer, no generated maps, no external files.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

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
		'Mojoodyar\\Support\\'  => 'includes/Support/',
		'Mojoodyar\\Logic\\'    => 'includes/Logic/',
		'Mojoodyar\\Channels\\' => 'includes/Channels/',
		'Mojoodyar\\Admin\\'    => 'admin/',
		'Mojoodyar\\'           => 'includes/',
	);

	/**
	 * Explicit class map for files whose name does not follow PSR-4.
	 *
	 * @var array<string,string>
	 */
	private static $classmap = array(
		'Mojoodyar\\Admin\\Admin' => 'admin/class-admin.php',
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
			$file = MOJODYAR_DIR . self::$classmap[ $class ];
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

			$path = MOJODYAR_DIR . $dir . str_replace( '\\', '/', $relative ) . '.php';
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
