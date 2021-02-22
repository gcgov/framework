<?php

namespace gcgov\framework\services\mongodb\models;


class ui {

	/** @OA\Property() */
	public bool $editing = false;

	/** @OA\Property() */
	public bool $saving = false;

	/** @OA\Property() */
	public bool $deleting = false;

	/** @OA\Property() */
	public bool $expanded = false;

	/** @OA\Property() */
	public string $message = '';

}