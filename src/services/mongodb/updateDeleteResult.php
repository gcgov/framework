<?php

namespace gcgov\framework\services\mongodb;

class updateDeleteResult
	implements
	\JsonSerializable {

	private bool $acknowledged = false;

	private int $acknowledgedCount = 0;

	private int $deletedCount = 0;

	private int $modifiedCount = 0;

	private int $matchedCount = 0;

	private ?\MongoDB\BSON\ObjectId $upsertedId = null;

	/** @var  \MongoDB\BSON\ObjectId[] */
	private array $upsertedIds = [];

	private int $upsertedCount = 0;

	private int $embeddedDeletedCount = 0;

	private int $embeddedModifiedCount = 0;

	private int $embeddedMatchedCount = 0;

	private int $embeddedUpsertedCount = 0;

	/** @var  \MongoDB\BSON\ObjectId[] */
	private array $embeddedUpsertedIds = [];


	/**
	 * updateDeleteResult constructor.
	 *
	 * @param \MongoDB\UpdateResult|\MongoDB\DeleteResult|\MongoDB\BulkWriteResult|null|\MongoDB\UpdateResult[]|\MongoDB\DeleteResult[]|updateDeleteResult[] $result
	 * @param \MongoDB\UpdateResult[]|\MongoDB\DeleteResult[]|updateDeleteResult[]|\MongoDB\BulkWriteResult                                                  $embeddedResults
	 */
	public function __construct( \MongoDB\UpdateResult|\MongoDB\DeleteResult|\MongoDB\BulkWriteResult|null|array $result = null, array|\MongoDB\BulkWriteResult $embeddedResults = [] ) {
		//don't do anything if a result is not provided
		if( $result===null ) {
			return;
		}

		$results = $result;
		if( !is_array( $result ) ) {
			$results = [ $result ];
		}

		//add primary response
		foreach( $results as $result ) {
			if( $result->isAcknowledged() ) {
				$this->acknowledged = true;
				$this->acknowledgedCount++;

				if( $result instanceof \MongoDB\DeleteResult ) {
					$this->deletedCount += $result->getDeletedCount();
				}
				elseif( $result instanceof \MongoDB\UpdateResult ) {
					$this->modifiedCount += $result->getModifiedCount() ?? 0;
					$this->matchedCount  += $result->getMatchedCount();
					$this->upsertedCount += $result->getUpsertedCount();
					if( $result->getUpsertedCount()>0 ) {
						$this->upsertedId     = $result->getUpsertedId();
						$this->upsertedIds [] = $result->getUpsertedId();
					}
				}
				elseif( $result instanceof \MongoDB\BulkWriteResult ) {
					$this->modifiedCount += $result->getModifiedCount() ?? 0;
					$this->matchedCount  += $result->getMatchedCount();
					$this->upsertedCount += $result->getUpsertedCount();
					if( $result->getUpsertedCount()>0 ) {
						$this->upsertedIds = $result->getUpsertedIds();
					}
				}
			}

		}

		//if embedded results are provided, add them to the object and sum their counts
		if( count( $embeddedResults )>0 ) {
			foreach( $embeddedResults as $result ) {
				if( $result instanceof \MongoDB\DeleteResult || $result instanceof updateDeleteResult ) {
					$this->embeddedDeletedCount += $result->getDeletedCount();
				}
				if( $result instanceof \MongoDB\UpdateResult || $result instanceof updateDeleteResult ) {
					$this->embeddedMatchedCount  += $result->getMatchedCount();
					$this->embeddedModifiedCount += $result->getModifiedCount();
					$this->embeddedUpsertedCount += $result->getUpsertedCount();
					if( $result->getUpsertedCount()>0 ) {
						$this->embeddedUpsertedIds[] = $result->getUpsertedId();
					}
				}
				if( $result instanceof \MongoDB\BulkWriteResult ) {
					$this->embeddedMatchedCount  += $result->getMatchedCount();
					$this->embeddedModifiedCount += $result->getModifiedCount();
					$this->embeddedUpsertedCount += $result->getUpsertedCount();
					if( $result->getUpsertedCount()>0 ) {
						$this->embeddedUpsertedIds = array_merge( $this->embeddedUpsertedIds, $result->getUpsertedIds() );
					}
				}
			}
		}
	}


	public function isAcknowledged(): bool {
		return $this->acknowledged;
	}


	public function getDeletedCount(): int {
		return $this->deletedCount;
	}


	public function getModifiedCount(): int {
		return $this->modifiedCount;
	}


	public function getMatchedCount(): int {
		return $this->matchedCount;
	}


	public function getUpsertedId(): ?\MongoDB\BSON\ObjectId {
		return $this->upsertedId;
	}


	public function getUpsertedCount(): int {
		return $this->upsertedCount;
	}


	public function getEmbeddedDeletedCount(): int {
		return $this->embeddedDeletedCount;
	}


	public function getEmbeddedModifiedCount(): int {
		return $this->embeddedModifiedCount;
	}


	public function getEmbeddedMatchedCount(): int {
		return $this->embeddedMatchedCount;
	}


	public function getEmbeddedUpsertedCount(): int {
		return $this->embeddedUpsertedCount;
	}


	/** @return \MongoDB\BSON\ObjectId[] */
	public function getEmbeddedUpsertedIds(): array {
		return $this->embeddedUpsertedIds;
	}


	public function jsonSerialize(): array {
		$export = [
			'acknowledged'          => $this->isAcknowledged(),
			'deletedCount'          => $this->getDeletedCount(),
			'modifiedCount'         => $this->getModifiedCount(),
			'matchedCount'          => $this->getMatchedCount(),
			'upsertedCount'         => $this->getUpsertedCount(),
			'embeddedDeletedCount'  => $this->getEmbeddedDeletedCount(),
			'embeddedModifiedCount' => $this->getEmbeddedModifiedCount(),
			'embeddedMatchedCount'  => $this->getEmbeddedMatchedCount(),
			'embeddedUpsertedCount' => $this->getEmbeddedUpsertedCount(),
		];

		return $export;
	}

}