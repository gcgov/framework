<?php

namespace gcgov\framework\services\mongodb\tools;

class getAllStdQueryParams {

	public int $limit;
	public int $page;

	/**
	 * @var array<string, int>
	 */
	public array $sort;


	public function __construct( int $defaultLimit = 10, int $defaultPage = 1 ) {
		$this->limit = $defaultLimit;
		$this->page  = $defaultPage;
	}


	public static function get( int $defaultLimit = 10, int $defaultPage = 1 ): static {
		$params        = new self( $defaultLimit, $defaultPage );
		$params->limit = $_GET[ 'limit' ] ?? $defaultLimit;
		$params->page  = $_GET[ 'page' ] ?? $defaultPage;

		if( isset( $_GET[ 'sortBy' ] ) && is_array( $_GET[ 'sortBy' ] ) ) {
			foreach( $_GET[ 'sortBy' ] as $sortBy ) {
				$parts                       = explode( '|', $sortBy );
				$params->sort[ $parts[ 0 ] ] = $parts[ 1 ]=='desc' || $parts[ 0 ]=='false' || $parts[ 0 ]==0 ? -1 : 1;
			}
		}

		return $params;
	}

}
