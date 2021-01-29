<?php


namespace gcgov\framework\models;


use gcgov\framework\interfaces\_controllerResponse;


class controllerDataResponse implements _controllerResponse {

	private mixed $data = null;

	/**
	 * @param  mixed  $data Data to be json encoded and output
	 */
	public function __construct( mixed $data ) {
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


}