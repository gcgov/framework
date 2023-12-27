<?php

namespace gcgov\framework\services\mongodb\models\_meta;

use OpenApi\Attributes as OA;

#[OA\Schema]
class uiField
	extends
	\andrewsauder\jsonDeserialize\jsonDeserialize {

	#[OA\Property]
	public string $label = '';

	#[OA\Property]
	public bool $error = false;

	#[OA\Property]
	/** @var string[] $errorMessages */
	public string|array $errorMessages = [];

	#[OA\Property]
	public bool $success = false;

	#[OA\Property]
	/** @var string[] $successMessages */
	public string|array $successMessages = [];

	#[OA\Property]
	/** @var string[] $hints */
	public string|array $hints = '';

	#[OA\Property]
	public string $state = '';

	#[OA\Property]
	public bool $required = false;

	#[OA\Property]
	public bool $visible = true;

	#[OA\Property]
	public bool $valueIsVisibilityGroup = false;

	#[OA\Property]
	/** @var string[] $visibilityGroups */
	public array $visibilityGroups = [];

	#[OA\Property]
	public bool $validating = false;

}
