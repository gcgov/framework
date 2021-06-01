<?php

namespace gcgov\framework\services;


class formatting {

	public static function fileName( string $fileName, string $replacementForIllegalChars='-' ) {
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
			' '
		];
		return str_replace( $illegalChars, '-', strtolower($fileName) );
	}

}