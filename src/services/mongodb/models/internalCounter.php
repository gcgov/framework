<?php

namespace gcgov\framework\services\mongodb\models;


use gcgov\framework\exceptions\modelException;
use gcgov\framework\services\mongodb\model;
use gcgov\framework\services\mongodb\tools\mdb;


final class internalCounter
	extends
	model {

	const _COLLECTION = 'internalCounter';

	const _HUMAN = 'internal counter';

	const _HUMAN_PLURAL = 'internal counters';

	public string $_id = '';

	/** @var int Last count to be assigned to a counter with this _id */
	public int $currentCount = 0;


	public function __construct() {
		parent::__construct();
	}


	/**
	 * Get and increment the counter of your choice but only INSIDE OF A MongoDB TRANSACTION SESSION
	 * The session makes the entire transaction atomic to assure the counter's uniqueness
	 * If the _id does not exist, it will be created and the count will start at 1
	 *
	 * @param  string                   $_id
	 * @param  \MongoDB\Driver\Session  $session  Transaction session
	 *
	 * @return self
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getAndIncrement( string $_id, \MongoDB\Driver\Session $session ) : self {
		$mdb = new mdb( collection: self::_getCollectionName() );

		try {
			//get and increment internalCounter
			$filter = [
				'_id' => $_id
			];

			$update = [
				'$inc' => [
					'currentCount' => 1
				]
			];

			$options = [
				'upsert'  => true,
				'typeMap' => self::_getTypeMap(),
				'session' => $session,
				'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
			];

			/** @noinspection PhpIncompatibleReturnTypeInspection */
			return $mdb->collection->findOneAndUpdate( $filter, $update, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException | \MongoDB\Driver\Exception\CommandException $e ) {
			$session->abortTransaction();
			throw new modelException( 'Database error: ' . $e->getMessage(), 500, $e );
		}
	}

}