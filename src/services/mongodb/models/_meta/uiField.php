<?php

namespace gcgov\framework\services\mongodb\models\_meta;


class uiField
	extends
	\andrewsauder\jsonDeserialize\jsonDeserialize {

	/** @OA\Property() */
	public string $label = '';

	/** @OA\Property() */
	public bool $error = false;

	/**
	 * @OA\Property()
	 * @var string[]
	 */
	public string|array $errorMessages = [];

	/** @OA\Property() */
	public bool $success = false;

	/**
	 * @OA\Property()
	 * @var string[]
	 */
	public string|array $successMessages = [];

	/**
	 * @OA\Property()
	 * @var string[]
	 */
	public string|array $hints = '';

	/**
	 * @OA\Property()
	 * @var string
	 */
	public string $state = '';

}