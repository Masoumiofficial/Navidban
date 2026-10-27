<?php
/**
 * CSV cell hardening (CSV injection defence). Pure logic: no WordPress calls.
 *
 * Spreadsheet applications execute any cell whose first character is one of
 * "=", "+", "-", "@", TAB or CR. Prefixing such values with a single quote
 * keeps the data intact for a human but makes the cell inert. This is the same
 * defensive pattern used by several mature market products.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Class Csv
 */
final class Csv {

	/**
	 * Characters that make a spreadsheet treat a cell as a formula.
	 *
	 * @var string[]
	 */
	const DANGEROUS_PREFIXES = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Neutralise a single cell value.
	 *
	 * @param mixed $value Raw cell value.
	 * @return string
	 */
	public static function cell( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		// Leading whitespace is still interpreted by some spreadsheet builds.
		$trimmed = ltrim( $value );

		if ( '' !== $trimmed && in_array( $trimmed[0], self::DANGEROUS_PREFIXES, true ) ) {
			return "'" . $value;
		}

		// Control characters are never legitimate here.
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $value );

		return null === $value ? '' : $value;
	}

	/**
	 * Neutralise a whole row.
	 *
	 * @param array $row Cells.
	 * @return array
	 */
	public static function row( array $row ) {
		$out = array();
		foreach ( $row as $cell ) {
			$out[] = self::cell( $cell );
		}

		return $out;
	}

	/**
	 * Excel needs a UTF-8 BOM to read Persian text correctly.
	 *
	 * @return string
	 */
	public static function bom() {
		return "\xEF\xBB\xBF";
	}
}
