<?php
namespace gcgov\framework\services\mongodb\models;

use gcgov\framework\services\mongodb\attributes\excludeBsonSerialize;
use gcgov\framework\services\mongodb\attributes\excludeBsonUnserialize;
use gcgov\framework\services\mongodb\attributes\excludeJsonDeserialize;
use gcgov\framework\services\mongodb\attributes\excludeJsonSerialize;
use gcgov\framework\services\mongodb\attributes\label;
use gcgov\framework\services\mongodb\updateDeleteResult;
use MongoDB\BSON\ObjectId;
use OpenApi\Attributes as OA;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[OA\Schema]
#[TypeScript('IAudit')]
final class audit
	extends
	\gcgov\framework\services\mongodb\model {

	const _COLLECTION = 'audit';

	const _HUMAN = 'audit';

	const _HUMAN_PLURAL = 'audits';

	#[label( 'Id' )]
	#[OA\Property(type:'string')]
	public \MongoDB\BSON\ObjectId  $_id;

	#[label( 'Collection' )]
	#[OA\Property]
	public string                  $collection       = '';

	#[label( 'Action' )]
	#[OA\Property]
	public string                  $action           = '';

	#[label( 'Record Id' )]
	#[OA\Property(type:'string')]
	public ?\MongoDB\BSON\ObjectId $recordId         = null;

	#[label( 'User Id' )]
	#[OA\Property(type:'string')]
	public ?\MongoDB\BSON\ObjectId $userId           = null;

	#[label( 'User Name' )]
	#[OA\Property(type:'string')]
	public string                  $userName         = '';

	#[label( 'IP' )]
	#[OA\Property]
	public string                  $ip               = '';

	#[label( 'Message' )]
	#[OA\Property]
	public string                  $message          = '';

	#[label( 'Matched' )]
	#[OA\Property]
	public int                     $matched          = 0;

	#[label( 'Modified' )]
	#[OA\Property]
	public int                     $modified         = 0;

	#[label( 'Upserted' )]
	#[OA\Property]
	public int                     $upserted         = 0;

	#[label( 'Deleted' )]
	#[OA\Property]
	public int                     $deleted          = 0;

	#[label( 'Embedded Matched' )]
	#[OA\Property]
	public int                     $embeddedMatched  = 0;

	#[label( 'Embedded Modified' )]
	#[OA\Property]
	public int                     $embeddedModified = 0;

	#[label( 'Embedded Upserted' )]
	#[OA\Property]
	public int                     $embeddedUpserted = 0;

	#[label( 'Embedded Deleted' )]
	#[OA\Property]
	public int                     $embeddedDeleted  = 0;

	#[label( 'Data' )]
	#[OA\Property]
	#[LiteralTypeScriptType('unknown')]
	public mixed                   $data             = null;

	#[label( 'Date Time Stamp' )]
	#[OA\Property]
	public \DateTimeImmutable      $dateTimeStamp;

	#[excludeBsonSerialize]
	#[excludeBsonUnserialize]
	#[excludeJsonSerialize]
	#[excludeJsonDeserialize]
	private \MongoDB\ChangeStream $changeStream;

	public function __construct() {
		parent::__construct();
		$this->_id = new \MongoDB\BSON\ObjectId();

		$auditUser           = auditUser::getInstance();
		$this->userId        = $auditUser->userId;
		$this->userName      = $auditUser->name;
		$this->ip            = $_SERVER[ 'REMOTE_ADDR' ];
		$this->dateTimeStamp = new \DateTimeImmutable();
	}


	/**
	 * @param  string                                                     $collectionName
	 * @param  \MongoDB\BSON\ObjectId                                     $_id
	 * @param  string                                                     $action              CREATE, UPDATE, DELETE
	 * @param  \gcgov\framework\services\mongodb\updateDeleteResult|null  $updateDeleteResult  optional
	 * @param  string|string[]                                            $message             optional
	 * @param  mixed|null                                                 $data                optional
	 *
	 * @return \gcgov\framework\services\mongodb\models\audit
	 */
	public static function create( string $collectionName, ObjectId $_id, string $action, ?updateDeleteResult $updateDeleteResult = null, string|array $message = '', mixed $data = null ) : audit {
		$audit             = new audit();
		$audit->collection = $collectionName;
		$audit->recordId   = $_id;
		$audit->action     = $action;
		$audit->message    = is_array( $message ) ? implode( ', ', $message ) : $message;
		if( $updateDeleteResult != null ) {
			$audit->matched          = $updateDeleteResult->getMatchedCount();
			$audit->modified         = $updateDeleteResult->getModifiedCount();
			$audit->upserted         = $updateDeleteResult->getUpsertedCount();
			$audit->deleted          = $updateDeleteResult->getDeletedCount();
			$audit->embeddedMatched  = $updateDeleteResult->getEmbeddedMatchedCount();
			$audit->embeddedModified = $updateDeleteResult->getEmbeddedModifiedCount();
			$audit->embeddedUpserted = $updateDeleteResult->getEmbeddedUpsertedCount();
			$audit->embeddedDeleted  = $updateDeleteResult->getEmbeddedDeletedCount();
		}
		$audit->data = $data;

		return $audit;
	}


}
