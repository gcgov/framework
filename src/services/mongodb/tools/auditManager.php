<?php
namespace gcgov\framework\services\mongodb\tools;

use gcgov\framework\exceptions\modelException;
use gcgov\framework\services\mongodb\models\audit;
use gcgov\framework\services\mongodb\updateDeleteResult;
use OpenApi\Attributes as OA;
use Swaggest\JsonDiff\JsonDiff;
use Swaggest\JsonDiff\JsonPatch;

#[OA\Schema]
final class auditManager {

	public mdb                   $mdb;
	public bool                  $doAudit = false;
	public \MongoDB\ChangeStream $changeStream;


	public function __construct( mdb $mdb ) {
		$this->mdb = $mdb;
		if( $this->mdb->audit && $this->mdb->collection->getCollectionName()!='audit' ) {
			$this->doAudit = true;
		}
	}

	public function recordDelete( \MongoDB\BSON\ObjectId $_id, ?updateDeleteResult $updateDeleteResult = null ): void {
		try {
			$audit = audit::create( $this->mdb->collection->getCollectionName(), $_id, 'delete', $updateDeleteResult, '', [] );
			audit::save( $audit );
		}
		catch( modelException $e ) {
			error_log($e);
		}
	}

	public function recordDiff( mixed $afterSaveObject, mixed $beforeSaveObject, ?updateDeleteResult $updateDeleteResult = null ): JsonPatch {
		$afterSaveObjectClone = clone $afterSaveObject;
		$beforeSaveObjectClone = clone $beforeSaveObject;

		$after = json_decode(json_encode($afterSaveObjectClone->doBsonSerialize( true, true )));
		$before = json_decode(json_encode($beforeSaveObjectClone->doBsonSerialize( true, true )));

		//create the patch from new to old (this allows us to work backwards from the current version that is saved in the main table)
		if( $this->mdb->auditForward ) {
			$diff = new JsonDiff($before, $after, JsonDiff::REARRANGE_ARRAYS);
		}
		else {
			$diff = new JsonDiff($after, $before, JsonDiff::REARRANGE_ARRAYS);
		}
		$jsonPatch = $diff->getPatch();
		$patchArray = $jsonPatch->jsonSerialize();

		if(count($patchArray)==0) {
			return $jsonPatch;
		}

		//remove tests from patch array
		foreach($patchArray as $key=>$patch) {
			if($patch->op=='test') {
				unset($patchArray[$key]);
			}
		}
		$patchArray = array_values($patchArray);

		try {
			$audit = audit::create( $this->mdb->collection->getCollectionName(), $afterSaveObject->_id, 'patch', $updateDeleteResult, '', $patchArray );
			audit::save( $audit );
		}
		catch( modelException $e ) {
			error_log($e);
		}

		//error_log(json_encode($patchArray));
		return $jsonPatch;
	}


	public function startChangeStreamWatchCollection(): \MongoDB\ChangeStream {
		$this->changeStream = $this->mdb->collection->watch( [], [
			'typeMap'                  => [ 'array' => 'array' ],
			'fullDocument'             => \MongoDB\Operation\Watch::FULL_DOCUMENT_WHEN_AVAILABLE,
			'fullDocumentBeforeChange' => \MongoDB\Operation\Watch::FULL_DOCUMENT_BEFORE_CHANGE_WHEN_AVAILABLE,
			'maxAwaitTimeMS'           => 60000
		] );
		return $this->changeStream;
	}


	public function startChangeStreamWatchOnDb(): \MongoDB\ChangeStream {
		$this->changeStream = $this->mdb->db->watch( [], [
			'typeMap'                  => [ 'array' => 'array' ],
			'fullDocument'             => \MongoDB\Operation\Watch::FULL_DOCUMENT_WHEN_AVAILABLE,
			'fullDocumentBeforeChange' => \MongoDB\Operation\Watch::FULL_DOCUMENT_BEFORE_CHANGE_WHEN_AVAILABLE,
			'maxAwaitTimeMS'           => 60000
		] );
		return $this->changeStream;
	}


	/**
	 * @param ?\gcgov\framework\services\mongodb\updateDeleteResult $updateDeleteResult
	 *
	 * @return \gcgov\framework\services\mongodb\models\audit[]
	 */
	public function processChangeStream( ?updateDeleteResult $updateDeleteResult = null ): array {
		$audits = [];

		if( !isset( $this->changeStream ) ) {
			return $audits;
		}
		//error_log(iterator_count($this->changeStream).' in change stream');

		for( $this->changeStream->rewind(); true; $this->changeStream->next() ) {
			error_log( '--' . iterator_count( $this->changeStream ) . ' in change stream' );
			if( !$this->changeStream->valid() ) {
				continue;
			}

			$event = $this->changeStream->current();

			$ns = sprintf( '%s.%s', $event->ns->db, $event->ns->coll );
			$id = (string)$event->documentKey->_id;

			$data    = null;
			$message = '';

			if( $event->operationType=='delete' ) {
				$message = "Deleted document in " . $ns . " with id: " . $id;
			}
			elseif( $event->operationType=='insert' ) {
				$data    = $event->fullDocument;
				$message = "Inserted new document in " . $ns;
			}
			elseif( $event->operationType=='replace' ) {
				$data    = $event->fullDocument;
				$message = "Replaced new document in " . $ns . " with _id: " . $id;
			}
			elseif( $event->operationType=='update' ) {
				$data    = $event->updateDescription;
				$message = "Updated document in " . $ns . " with _id: " . $id;
			}

			log::info( 'MongoAudit', $message, json_decode( json_encode( $data ), true ) );

			$audits[] = \gcgov\framework\services\mongodb\models\audit::create( $event->ns->coll, $event->documentKey->_id, $event->operationType, $updateDeleteResult, $message, $data );
			//break;
			if( $updateDeleteResult===null || count( $audits )>=$updateDeleteResult->getModifiedCount() || count( $audits )>=$updateDeleteResult->getUpsertedCount() || count( $audits )>=$updateDeleteResult->getDeletedCount() ) {
				break;
			}
		}

		return $audits;
	}

}
