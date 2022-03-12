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
	public static function fileName( string $fileName, string $replacementForIllegalChars='-', bool $forceLowerCase=true ) : string {
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

		$correction1 = $forceLowerCase ? strtolower($fileName) : $fileName;
		$correction2 = str_replace( $illegalChars, $replacementForIllegalChars, $correction1 );
		$correction3 = preg_replace('/('.$replacementForIllegalChars.')+/', ' ', $correction2);
		return $correction3;
	}


	/**
	 * @param  string  $tabName
	 * @param  string  $replacementForIllegalChars
	 * @param  bool    $forceLowerCase
	 *
	 * @return string
	 */
	public static function xlsxTabName( string $tabName, string $replacementForIllegalChars=' ', bool $forceLowerCase=false ) : string {
		$illegalChars = [
			'\\',
			'/',
			'*',
			'[',
			']',
			':',
			'?'
		];
		$correction1 = $forceLowerCase ? strtolower($tabName) : $tabName;
		$correction2 = str_replace( $illegalChars, $replacementForIllegalChars, $correction1 );
		$correction3 = preg_replace('/('.$replacementForIllegalChars.')+/', ' ', $correction2);
		return $correction3;
	}

}