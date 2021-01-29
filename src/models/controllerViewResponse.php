<?php


namespace gcgov\framework\models;


use gcgov\framework\interfaces\_controllerResponse;


class controllerViewResponse implements _controllerResponse {

	/**
	 * @var string path to view file
	 */
	private string $view = '';

	/**
	 * @var array Associative array we convert keys to local variables in view
	 */
	private array $vars = [];


	/**
	 * @param  string  $view Path to view file
	 * @param  array   $vars Associative array where keys will be converted to local variables in view
	 */
	public function __construct( string $view, array $vars ) {
		$this->setView($view);
		$this->setVars($vars);
	}


	/**
	 * @return string
	 */
	public function getView() : string {

		return $this->view;
	}


	/**
	 * @param  string  $view
	 */
	public function setView( string $view ) : void {

		$this->view = $view;
	}


	/**
	 * @return array
	 */
	public function getVars() : array {

		return $this->vars;
	}


	/**
	 * @param  array  $vars
	 */
	public function setVars( array $vars ) : void {

		$this->vars = $vars;
	}


}