<?php

namespace gcgov\framework\services\mongodb;

use gcgov\framework\exceptions\modelException;
use gcgov\framework\services\mongodb\attributes\label;
use JetBrains\PhpStorm\Deprecated;
use OpenApi\Attributes as OA;

/**
 * @phpstan-consistent-constructor
 */
#[OA\Schema]
class gridfs extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	#[label( 'File Id' )]
	#[OA\Property]
	public ?\MongoDB\BSON\ObjectId $_id = null;

	#[label( 'File Name' )]
	#[OA\Property]
	public string $filename  = '';
	#[label( 'Content Type' )]
	#[OA\Property]
	public string $contentType  = '';

	#[label( 'Base 64 Encoded Content' )]
	#[OA\Property]
	public string $base64EncodedContent  = '';

	#[label( 'Date Uploaded' )]
	#[OA\Property]
	public ?\DateTimeImmutable $uploadDate = null;

	/**
	 * @return string
	 */
	public static function _getCollectionName(): string {
		$classFqn = get_called_class();
		if( defined( $classFqn . '::_COLLECTION' ) ) {
			return $classFqn::_COLLECTION;
		}
		elseif( strrpos( $classFqn, '\\' )!==false ) {
			return substr( $classFqn, strrpos( $classFqn, '\\' ) + 1 );
		}

		return $classFqn;
	}


	/**
	 * @return static
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getFile( \MongoDB\BSON\ObjectId $_id ): static {

		$collectionName = static::_getCollectionName();
		$mdb            = new \gcgov\framework\services\mongodb\tools\mdb( collection: static::_getCollectionName() );

		$bucket = $mdb->db->selectGridFSBucket( [ 'bucketName' => $collectionName ] );

		$stream   = $bucket->openDownloadStream( $_id );

		$metadata = $bucket->getFileDocumentForStream($stream);
		if( !is_object( $metadata ) ) {
			throw new modelException( 'GridFS file metadata is not in the expected document shape', 500 );
		}

		$contents = stream_get_contents( $stream );
		if( $contents===false ) {
			throw new modelException('Unable to get file contents', 500);
		}

		$nestedMetadata = $metadata->metadata ?? null;
		$contentType    = is_object( $nestedMetadata ) && isset( $nestedMetadata->contentType ) ? (string) $nestedMetadata->contentType : '';

		$uploadDate = $metadata->uploadDate;
		if( !( $uploadDate instanceof \MongoDB\BSON\UTCDateTime ) ) {
			throw new modelException( 'GridFS file metadata is missing the uploadDate field', 500 );
		}

		$fileGridFs = new static();
		$fileGridFs->filename = (string) $metadata->filename;
		$fileGridFs->contentType = $contentType;
		$fileGridFs->base64EncodedContent = base64_encode($contents);
		$fileGridFs->uploadDate = \DateTimeImmutable::createFromMutable( $uploadDate->toDateTime() )->setTimezone( new \DateTimeZone( date_default_timezone_get() ) );

		return $fileGridFs;
	}

	public static function deleteMany( array $filter=[], array $options=[] ): void {
		$collectionName = static::_getCollectionName();
		try {
			$mdb = new \gcgov\framework\services\mongodb\tools\mdb( collection: static::_getCollectionName() );

			$bucket = $mdb->db->selectGridFSBucket( [ 'bucketName' => $collectionName ] );

			$gridFsEntities = $bucket->find($filter);

			foreach($gridFsEntities as $gridFsEntity) {
				if( !is_object( $gridFsEntity ) || !isset( $gridFsEntity->_id ) ) {
					continue;
				}
				$bucket->delete( $gridFsEntity->_id );
			}
		}
		catch( \Exception $e ) {
			error_log( $e );
			throw new modelException( $e->getMessage(), 500, $e );
		}
	}


	/**
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function saveFile( string $filePathname ): \MongoDB\BSON\ObjectId {

		$collectionName = static::_getCollectionName();
		try {
			$mdb = new \gcgov\framework\services\mongodb\tools\mdb( collection: static::_getCollectionName() );

			$bucket = $mdb->db->selectGridFSBucket( [ 'bucketName' => $collectionName ] );

			$_id = new \MongoDB\BSON\ObjectId();

			$detectedContentType = mime_content_type( $filePathname );
			$stream = $bucket->openUploadStream( basename( $filePathname ), [ '_id' => $_id, ['metadata'=>['contentType'=> $detectedContentType === false ? '' : $detectedContentType]] ] );

		}
		catch( \Exception $e ) {
			error_log( $e );
			throw new modelException( $e->getMessage(), 500, $e );
		}

		$contents = file_get_contents( $filePathname );
		if( $contents===false ) {
			throw new modelException( 'Unable to get file contents', 400 );
		}
		$wroteSuccessfully = fwrite( $stream, $contents );
		fclose( $stream );

		if(!$wroteSuccessfully) {
			throw new modelException( 'Failed to save contents to stream', 500 );
		}

		return $_id;

	}



	#[Deprecated]
	/**
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function saveFileBase64EncodedContents( string $filename, string $base64EncodedContent ): \MongoDB\BSON\ObjectId {

		$collectionName = static::_getCollectionName();
		try {
			$mdb = new \gcgov\framework\services\mongodb\tools\mdb( collection: static::_getCollectionName() );

			$bucket = $mdb->db->selectGridFSBucket( [ 'bucketName' => $collectionName ] );

			$_id = new \MongoDB\BSON\ObjectId();

			$stream = $bucket->openUploadStream( $filename, [ '_id' => $_id ] );

		}
		catch( \Exception $e ) {
			error_log( $e );
			throw new modelException( $e->getMessage(), 500, $e );
		}

		$wroteSuccessfully = fwrite( $stream, base64_decode($base64EncodedContent) );
		fclose( $stream );

		if(!$wroteSuccessfully) {
			throw new modelException( 'Failed to save contents to stream', 500 );
		}

		return $_id;

	}


	/**
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function saveFileBase64EncodedContentsByAttachment( \gcgov\framework\services\mongodb\gridfs $attachment ): \MongoDB\BSON\ObjectId {

		$collectionName = static::_getCollectionName();
		try {
			$mdb = new \gcgov\framework\services\mongodb\tools\mdb( collection: static::_getCollectionName() );

			$bucket = $mdb->db->selectGridFSBucket( [ 'bucketName' => $collectionName ] );

			$_id = new \MongoDB\BSON\ObjectId();

			$stream = $bucket->openUploadStream( $attachment->filename, [ '_id' => $_id, 'metadata'=>['contentType'=>$attachment->contentType] ] );

		}
		catch( \Exception $e ) {
			error_log( $e );
			throw new modelException( $e->getMessage(), 500, $e );
		}

		$wroteSuccessfully = fwrite( $stream, base64_decode($attachment->base64EncodedContent) );
		fclose( $stream );

		if(!$wroteSuccessfully) {
			throw new modelException( 'Failed to save contents to stream', 500 );
		}

		return $_id;

	}


	/**
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function deleteFile( \MongoDB\BSON\ObjectId $_id ): void {
		$collectionName = static::_getCollectionName();
		try {
			$mdb = new \gcgov\framework\services\mongodb\tools\mdb( collection: static::_getCollectionName() );

			$bucket = $mdb->db->selectGridFSBucket( [ 'bucketName' => $collectionName ] );

			$bucket->delete( $_id );
		}
		catch( \Exception $e ) {
			error_log( $e );
			throw new modelException( $e->getMessage(), 500, $e );
		}
	}

}
