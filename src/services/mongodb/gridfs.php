<?php

namespace gcgov\framework\services\mongodb;

use gcgov\framework\exceptions\modelException;
use gcgov\framework\services\mongodb\attributes\label;
use JetBrains\PhpStorm\Deprecated;
use OpenApi\Attributes as OA;

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
	 * @return $this
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getFile( \MongoDB\BSON\ObjectId $_id ): gridfs {

		$collectionName = static::_getCollectionName();
		$mdb            = new \gcgov\framework\services\mongodb\tools\mdb( collection: static::_getCollectionName() );

		$bucket = $mdb->db->selectGridFSBucket( [ 'bucketName' => $collectionName ] );

		$stream   = $bucket->openDownloadStream( $_id );

		$metadata = $bucket->getFileDocumentForStream($stream);

		$contents = stream_get_contents( $stream );
		if( $contents===false ) {
			throw new modelException('Unable to get file contents', 500);
		}

		$fileGridFs = new gridfs();
		$fileGridFs->filename = $metadata->filename;
		$fileGridFs->contentType = $metadata->metadata?->contentType ?? '';
		$fileGridFs->base64EncodedContent = base64_encode($contents);

		return $fileGridFs;
	}

	public static function deleteMany( array $filter=[], array $options=[] ): void {
		$collectionName = static::_getCollectionName();
		try {
			$mdb = new \gcgov\framework\services\mongodb\tools\mdb( collection: static::_getCollectionName() );

			$bucket = $mdb->db->selectGridFSBucket( [ 'bucketName' => $collectionName ] );

			$gridFsEntities = $bucket->find($filter);

			foreach($gridFsEntities as $gridFsEntity) {
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

			$stream = $bucket->openUploadStream( basename( $filePathname ), [ '_id' => $_id, ['metadata'=>['contentType'=>mime_content_type($filePathname)??'']] ] );

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
