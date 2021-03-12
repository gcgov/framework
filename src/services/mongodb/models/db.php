<?php

namespace gcgov\framework\services\mongodb\models;


class db {

	/** @OA\Property() */
	public float $score = 0;

	/** @OA\Property() */
	public int $matched = 0;

	/** @OA\Property() */
	public int $embeddedMatched = 0;

	/** @OA\Property() */
	public int $childrenMatched = 0;

	/** @OA\Property() */
	public int $modified = 0;

	/** @OA\Property() */
	public int $embeddedModified = 0;

	/** @OA\Property() */
	public int $childrenModified = 0;

	/** @OA\Property() */
	public int $upserted = 0;

	/** @OA\Property() */
	public int $embeddedUpserted = 0;

	/** @OA\Property() */
	public int $childrenUpserted = 0;

	/** @OA\Property() */
	public int $deleted = 0;

	/** @OA\Property() */
	public int $embeddedDeleted = 0;

	/** @OA\Property() */
	public int $childrenDeleted = 0;

	/** @OA\Property() */
	public string $upsertedId = '';

	/**
	 * @OA\Property()
	 * @var string[]
	 */
	public array $embeddedUpsertedIds = [];

	/**
	 * @OA\Property()
	 * @var string[]
	 */
	public array $childrenUpsertedIds = [];


	/**
	 * @param  ?\gcgov\framework\services\mongodb\updateDeleteResult  $primaryResult  Optional - preset from database
	 *                                                                                result if available
	 */
	public function __construct( ?\gcgov\framework\services\mongodb\updateDeleteResult $primaryResult = null ) {
		if( $primaryResult !== null ) {
			$this->set( $primaryResult );
		}
	}


	/**
	 * Set from database result
	 *
	 * @param  \gcgov\framework\services\mongodb\updateDeleteResult  $primaryResult
	 */
	public function set( \gcgov\framework\services\mongodb\updateDeleteResult $primaryResult ) {
		$this->deleted             = $primaryResult->getDeletedCount();
		$this->modified            = $primaryResult->getModifiedCount() ?? 0;
		$this->matched             = $primaryResult->getMatchedCount();
		$this->upserted            = $primaryResult->getUpsertedCount();
		$this->upsertedId          = $primaryResult->getUpsertedCount() > 0 ? (string) $primaryResult->getUpsertedId() : '';
		$this->embeddedDeleted     = $primaryResult->getEmbeddedDeletedCount();
		$this->embeddedMatched     = $primaryResult->getEmbeddedMatchedCount();
		$this->embeddedModified    = $primaryResult->getEmbeddedModifiedCount();
		$this->embeddedUpserted    = $primaryResult->getEmbeddedUpsertedCount();
		//$this->embeddedUpsertedIds = $primaryResult->getEmbeddedUpsertedIds();
		$this->childrenDeleted     = $primaryResult->getChildrenDeletedCount();
		$this->childrenModified    = $primaryResult->getChildrenModifiedCount();
		$this->childrenMatched     = $primaryResult->getChildrenMatchedCount();
		$this->childrenUpserted    = $primaryResult->getChildrenUpsertedCount();
		//$this->childrenUpsertedIds = $primaryResult->getChildrenUpsertedIds();
	}

}