<?php


namespace gcgov\framework\models;


use gcgov\framework\interfaces\_controllerResponse;


class controllerDataResponse implements _controllerResponse {

	private mixed $data = null;
	private int $httpStatus = 200;

	/**
	 * @param  mixed  $data Data to be json encoded and output
	 */
	public function __construct( mixed $data=null ) {
		$this->setData($data);
	}


	/**
	 * @return mixed
	 */
	public function getData() : mixed {

		return $this->data;
	}


	/**
	 * @param  mixed  $data
	 */
	public function setData( mixed $data ) : void {

		$this->data = $data;
	}


	/**
	 * @return int
	 */
	public function getHttpStatus() : int {

		return $this->httpStatus;
	}


	/**
	 * @param  int  $httpStatus
	 */
	public function setHttpStatus( int $httpStatus ) : void {

		$this->httpStatus = $httpStatus;
	}


}