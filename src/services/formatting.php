<?php

namespace gcgov\framework\services;


class formatting {

	/**
	 * @param  string  $fileName
	 * @param  string  $replacementForIllegalChars
	 * @param  bool    $forceLowerCase
	 *
	 * @return string
	 */
	public static function fileName( string $fileName, string $replacementForIllegalChars = '-', bool $forceLowerCase = true ) : string {
		$illegalChars = [
			'\\',
			'/',
			':',
			'*',
			'?',
			'"',
			'<',
			'>',
			'|',
			',',
			' '
		];

		$correction1 = $forceLowerCase ? strtolower( $fileName ) : $fileName;
		$correction2 = str_replace( $illegalChars, $replacementForIllegalChars, $correction1 );
		$correction3 = preg_replace( '/(\r\n|\r|\n)+/', ' ', $correction2 );
		$correction4 = preg_replace( '/(' . $replacementForIllegalChars . ')+/', ' ', $correction3 );

		return $correction4;
	}


	/**
	 * @param  string  $tabName
	 * @param  string  $replacementForIllegalChars
	 * @param  bool    $forceLowerCase
	 *
	 * @return string
	 */
	public static function xlsxTabName( string $tabName, string $replacementForIllegalChars = ' ', bool $forceLowerCase = false ) : string {
		$illegalChars = [
			'\\',
			'/',
			'*',
			'[',
			']',
			':',
			'?'
		];
		$correction1  = $forceLowerCase ? strtolower( $tabName ) : $tabName;
		$correction2  = str_replace( $illegalChars, $replacementForIllegalChars, $correction1 );
		$correction3 = preg_replace( '/(\r\n|\r|\n)+/', ' ', $correction2 );
		$correction4  = preg_replace( '/(' . $replacementForIllegalChars . ')+/', ' ', $correction3 );

		if(strlen($correction4)>31) {
			return substr($correction4, 0, 31);
		}

		return $correction4;
	}


	/**
	 * @param  \DateInterval  $interval
	 *
	 * @return string
	 */
	public static function getDateIntervalHumanText( \DateInterval $interval ) : string {
		$doPlural = function( $nb, $str ) {
			return $nb > 1 ? $str . 's' : $str;
		}; // adds plurals

		$format = [];
		if( $interval->y !== 0 ) {
			$format[] = "%y " . $doPlural( $interval->y, "year" );
		}
		if( $interval->m !== 0 ) {
			$format[] = "%m " . $doPlural( $interval->m, "month" );
		}
		if( $interval->d !== 0 ) {
			$format[] = "%d " . $doPlural( $interval->d, "day" );
		}
		if( $interval->h !== 0 ) {
			$format[] = "%h " . $doPlural( $interval->h, "hour" );
		}
		if( $interval->i !== 0 ) {
			$format[] = "%i " . $doPlural( $interval->i, "minute" );
		}
		if( $interval->s !== 0 ) {
			if( count( $format )===0 ) {
				$format[] = "%s " . $doPlural( $interval->s, "second" );
			}
			else {
				$format[] = "and %s " . $doPlural( $interval->s, "second" );
			}
		}

		// Prepend 'since ' or whatever you like
		return $interval->format( implode( ', ', $format ) );
	}

}
