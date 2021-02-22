<?php

namespace gcgov\framework\services\mongodb;


class updateDeleteResult {

	private bool                    $acknowledged          = false;

	private int                     $deletedCount          = 0;

	private int                     $modifiedCount         = 0;

	private int                     $matchedCount          = 0;

	private ?\MongoDB\BSON\ObjectId $upsertedId            = null;

	private int                     $upsertedCount         = 0;

	private int                     $embeddedDeletedCount  = 0;

	private int                     $embeddedModifiedCount = 0;

	private int                     $embeddedMatchedCount  = 0;

	private int                     $embeddedUpsertedCount = 0;

	/** @var  \MongoDB\BSON\ObjectId[] */
	private array $embeddedUpsertedIds = [];


	/**
	 * updateDeleteResult constructor.
	 *
	 * @param  \MongoDB\UpdateResult|\MongoDB\DeleteResult|null  $result
	 * @param  \MongoDB\UpdateResult[]|\MongoDB\DeleteResult[]   $embeddedResults
	 */
	public function __construct( \MongoDB\UpdateResult|\MongoDB\DeleteResult|null $result = null, array $embeddedResults = [] ) {
		//don't do anything if a result is not provided
		if( $result === null ) {
			return;
		}

		//add primary response
		$this->acknowledged = $result->isAcknowledged();

		if( $this->acknowledged ) {
			if( $result instanceof \MongoDB\DeleteResult ) {
				$this->deletedCount = $result->getDeletedCount();
			}
			elseif( $result instanceof \MongoDB\UpdateResult ) {
				$this->modifiedCount = $result->getModifiedCount() ?? 0;
				$this->matchedCount  = $result->getMatchedCount();
				$this->upsertedId    = $result->getUpsertedId();
				$this->upsertedCount = $result->getUpsertedCount();
				if( $result->getUpsertedCount() > 0 ) {
					$this->upsertedId = $result->getUpsertedId();
				}
			}
		}

		//if embedded results are provided, add them to the object and sum their counts
		if( count( $embeddedResults ) > 0 ) {
			$embeddedResultObjects = self::generateFromResults( $embeddedResults );
			foreach( $embeddedResultObjects as $result ) {
				if( $result instanceof \MongoDB\DeleteResult ) {
					$this->embeddedDeletedCount += $result->getDeletedCount();
				}
				elseif( $result instanceof \MongoDB\UpdateResult ) {
					$this->embeddedMatchedCount  += $result->getMatchedCount();
					$this->embeddedModifiedCount += $result->getModifiedCount();
					$this->embeddedUpsertedCount += $result->getUpsertedCount();
					if( $result->getUpsertedCount() > 0 ) {
						$this->embeddedUpsertedIds[] = $result->getUpsertedId();
					}
				}
			}
		}
	}


	/**
	 * @param  \MongoDB\UpdateResult[]|\MongoDB\DeleteResult[]  $mongoResults
	 *
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult[]
	 */
	public static function generateFromResults( array $mongoResults ) : array {
		$results = [];
		foreach( $mongoResults as $mongoResult ) {
			$results[] = new \gcgov\framework\services\mongodb\updateDeleteResult( $mongoResult );
		}

		return $results;
	}


	public function isAcknowledged() : bool {
		return $this->acknowledged;
	}


	public function getDeletedCount() : int {
		return $this->deletedCount;
	}


	public function getModifiedCount() : int {
		return $this->modifiedCount;
	}


	public function getMatchedCount() : int {
		return $this->matchedCount;
	}


	public function getUpsertedId() : ?\MongoDB\BSON\ObjectId {
		return $this->upsertedId;
	}


	public function getUpsertedCount() : int {
		return $this->upsertedCount;
	}


	public function getEmbeddedDeletedCount() : int {
		return $this->embeddedDeletedCount;
	}


	public function getEmbeddedModifiedCount() : int {
		return $this->embeddedModifiedCount;
	}


	public function getEmbeddedMatchedCount() : int {
		return $this->embeddedMatchedCount;
	}


	public function getEmbeddedUpsertedCount() : int {
		return $this->embeddedUpsertedCount;
	}


	/** @return \MongoDB\BSON\ObjectId[] */
	public function getEmbeddedUpsertedIds() : array {
		return $this->embeddedUpsertedIds;
	}

}