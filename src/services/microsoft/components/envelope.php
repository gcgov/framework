<?php

namespace gcgov\framework\services\microsoft\components;


use JetBrains\PhpStorm\Deprecated;
use OpenApi\Attributes as OA;

#[Deprecated('Use \andrewsauder\microsoftServices instead')]
#[OA\Schema]
class envelope {

	#[OA\Property]
	public bool   $error   = false;

	#[OA\Property]
	public string $message = '';

	#[OA\Property]
	public int    $status  = 0;

	#[OA\Property(type:'array', items: new OA\Items())]
	public array  $data    = [];


	/**
	 * envelope constructor.
	 *
	 * @param  int     $status
	 * @param  bool    $error
	 * @param  string  $message
	 * @param  array   $data
	 */
	public function __construct( int $status = 200, bool $error = false, string $message = '', array $data = [] ) {

		$this->status = $status;

		$this->error = $error;

		$this->message = $message;

		$this->data = $data;
	}

}
