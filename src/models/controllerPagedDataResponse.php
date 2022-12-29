<?php

namespace gcgov\framework\models;

use gcgov\framework\exceptions\controllerException;
use gcgov\framework\interfaces\_controllerDataResponse;

class controllerPagedDataResponse extends controllerDataResponse implements _controllerDataResponse {

	/**
	 * @param \gcgov\framework\interfaces\dbGetResult $result
	 *
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function __construct( \gcgov\framework\interfaces\dbGetResult $result ) {
		if( $result->getTotalPageCount()>0 && ($result->getPage()<1 || $result->getPage()>$result->getTotalPageCount())) {
			throw new controllerException('Page '.$result->getPage().' not found. Select a page between 1 and '.$result->getTotalPageCount(), 404);
		}

		$headers = [
			new controllerResponseHeader( 'X-Page', $result->getPage() ),
			new controllerResponseHeader( 'X-Count', $result->getCount() ),
			new controllerResponseHeader( 'X-Limit', $result->getLimit() ),
			new controllerResponseHeader( 'X-Page-Count', $result->getTotalPageCount() ),
			new controllerResponseHeader( 'X-Total-Count', $result->getTotalDocumentCount() ),
		];

		parent::__construct( $result->getData(), $headers );
	}

}