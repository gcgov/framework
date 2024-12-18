<?php

namespace gcgov\framework\services\mongodb\models\_meta;

use OpenApi\Attributes as OA;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[OA\Schema]
#[TypeScript('IMetaUi')]
class ui {

	#[OA\Property]
	public bool $loading = false;

	#[OA\Property]
	public bool $loadingDialog = false;

	#[OA\Property]
	public bool $adding = false;

	#[OA\Property]
	public bool $addingDialog = false;

	#[OA\Property]
	public bool $saving = false;

	#[OA\Property]
	public bool $savingDialog = false;

	#[OA\Property]
	public bool $editing = false;

	#[OA\Property]
	public bool $editingDialog = false;

	#[OA\Property]
	public bool $removing = false;

	#[OA\Property]
	public bool $removingDialog = false;

	#[OA\Property]
	public bool $error = false;

	#[OA\Property]
	public string $errorCode = '';

	#[OA\Property]
	public string $errorMessage = '';

	#[OA\Property]
	public bool $success = false;

	#[OA\Property]
	public string $successMessage = '';

	public function __construct() {
	}

}
