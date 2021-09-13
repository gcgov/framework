<?php
namespace gcgov\framework\services\microsoft;


use gcgov\framework\config;
use gcgov\framework\exceptions\serviceException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Microsoft\Graph\Exception\GraphException;


class files {

	public string $rootBasePath = '';

	public function __construct( $rootBasePath='' ) {
		if(strlen(trim($rootBasePath, ' \\/'))>0) {
			$this->rootBasePath = trim( $rootBasePath, ' \\/').'/';
		}
	}


	/**
	 * @param  string[]  $microsoftPathParts  Ex: [ '2021-0001', 'Building 1', 'Inspections' ] will turn into {root}/2021-0001/Building 1/Inspections
	 *
	 * @return \Microsoft\Graph\Model\DriveItem[]
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public function list( array $microsoftPathParts ) : array {

		//get application access token
		$accessToken = $this->getMicrosoftAccessToken();

		//get file list
		try {
			$driveItems = $this->getMicrosoftDriveItems( $accessToken, implode( '/', $microsoftPathParts ) );
		}
		catch( serviceException $e ) {
			//if the error is that the folder doesn't exist, try to create it
			if( $e->getCode() == 404 ) {
				//generate the folders recursively
				//$newDriveItems = $this->createMicrosoftDirectories( $accessToken, $microsoftPathParts );
				//try to get the files again
				//$driveItems = $this->getMicrosoftDriveItems( $accessToken, implode( '/', $microsoftPathParts ) );
				$driveItems = [];
			}
			else {
				throw new serviceException( $e->getMessage(), $e->getCode(), $e );
			}
		}

		return $driveItems;
	}


	/**
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public function getFile( array $microsoftPathParts ) : \Microsoft\Graph\Model\DriveItem {
		//get application access token
		$accessToken = $this->getMicrosoftAccessToken();

		//get file list
		try {
			$graph = new \Microsoft\Graph\Graph();
			$graph->setAccessToken( $accessToken );

			/** @var \Microsoft\Graph\Model\DriveItem $driveItem */
			$driveItem = $graph->createRequest( "GET", "/drives/". config::getEnvironmentConfig()->microsoft->driveId.'/root:/'.$this->rootBasePath . implode( '/', $microsoftPathParts ) )
			                    ->setReturnType( \Microsoft\Graph\Model\DriveItem::class )->execute();

			return $driveItem;
		}
		catch( ClientException $e ) {
			throw new serviceException( 'Folder not found', $e->getCode(), $e );
		}
		catch( GuzzleException $e ) {
			throw new serviceException( 'Error getting files from Microsoft', 500, $e );
		}
		catch( GraphException $e ) {
			throw new serviceException( $e->getMessage(), 500, $e );
		}
	}


	/**
	 * @param  string  $serverFullFilePath
	 * @param  string  $fileName
	 * @param  string[]   $uploadPathParts Ex: [ '2021-0001', 'Building 1', 'Inspections' ] will turn into {root}/2021-0001/Building 1/Inspections
	 *
	 * @return \gcgov\framework\services\microsoft\components\upload
	 */
	public function upload( string $serverFullFilePath, string $fileName, array $uploadPathParts ) : \gcgov\framework\services\microsoft\components\upload {

		$response = new \gcgov\framework\services\microsoft\components\upload();

		//get or user application access token
		$accessToken = $this->getMicrosoftAccessToken();

		//MICROSOFT UPLOAD
		$graph = new \Microsoft\Graph\Graph();
		$graph->setAccessToken( $accessToken );

		$fileEndpoint = "/drives/".config::getEnvironmentConfig()->microsoft->driveId.'/root:/'.$this->rootBasePath . implode( '/', $uploadPathParts ) . '/' . $fileName;

		$fileSize = filesize( $serverFullFilePath );

		try {
			//if less than 4 mb, simple upload
			if( $fileSize <= 4194304 ) {
				$driveItem = $graph->createRequest( "PUT", $fileEndpoint . ":/content" )
				                   ->attachBody( file_get_contents( $serverFullFilePath ) )
				                   ->setReturnType( \Microsoft\Graph\Model\DriveItem::class )->execute();
			}
			//larger than 4 mb, upload in chunks
			else {
				//1. create upload session
				$graphBody = [
					"@microsoft.graph.conflictBehavior" => "rename",
					"description"                       => "",
					"fileSystemInfo"                    => [ "@odata.type" => "microsoft.graph.fileSystemInfo" ],
					"name"                              => $fileName,
				];

				$uploadSession = $graph->createRequest( "POST", $fileEndpoint . ":/createUploadSession" )
				                       ->attachBody( $graphBody )
				                       ->setReturnType( \Microsoft\Graph\Model\UploadSession::class )
				                       ->execute();

				//2. upload bytes
				$fragSize       = 1024 * 1024 * 4;
				$graphUrl       = $uploadSession->getUploadUrl();
				$numFragments   = ceil( $fileSize / $fragSize );
				$bytesRemaining = $fileSize;
				$i              = 0;

				if( $stream = fopen( $serverFullFilePath, 'r' ) ) {
					while( $i < $numFragments ) {
						$chunkSize = $numBytes = $fragSize;
						$start     = $i * $fragSize;
						$end       = $i * $fragSize + $chunkSize - 1;
						$offset    = $i * $fragSize;
						if( $bytesRemaining < $chunkSize ) {
							$chunkSize = $numBytes = $bytesRemaining;
							$end       = $fileSize - 1;
						}

						// get contents using offset
						$data = stream_get_contents( $stream, $chunkSize, $offset );

						$content_range  = "bytes " . $start . "-" . $end . "/" . $fileSize;
						$headers        = [
							"Content-Length" => $numBytes,
							"Content-Range"  => $content_range
						];
						$uploadByte     = $graph->createRequest( "PUT", $graphUrl )->addHeaders( $headers )
						                        ->attachBody( $data )
						                        ->setReturnType( \Microsoft\Graph\Model\UploadSession::class )
						                        ->setTimeout( "1000" )->execute();
						$bytesRemaining = $bytesRemaining - $chunkSize;
						$i++;
					}
					fclose( $stream );
				}

				$driveItem = $graph->createRequest( "GET", $fileEndpoint )
				                   ->setReturnType( \Microsoft\Graph\Model\DriveItem::class )->execute();
			}

			$response->files[] = $driveItem;
		}
		catch( GraphException | GuzzleException $e ) {
			$response->errors[] = new \gcgov\framework\services\microsoft\components\envelope( $e->getCode(), true, $fileName . ' did not upload. ' . $e->getMessage() );
		}

		return $response;

	}


	/**
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public function delete( string $itemId ) {
		//get application access token
		$accessToken = $this->getMicrosoftAccessToken();

		//Microsoft Delete
		$graph = new \Microsoft\Graph\Graph();
		$graph->setAccessToken( $accessToken );

		$itemEndpoint = "/drives/" . config::getEnvironmentConfig()->microsoft->driveId . "/items/" . $itemId;

		try {
			$deleteRequest = $graph->createRequest( "DELETE", $itemEndpoint )->execute();
		}
		catch( GuzzleException|GraphException $e ) {
			throw new serviceException( $e->getMessage(), 500, $e );
		}

		return $deleteRequest;
	}


	/**
	 * @return mixed
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	private function getMicrosoftAccessToken() : string {
		$microsoftAuth = new auth();

		//get user access token
		if( isset( $_SERVER[ 'HTTP_X_MSACCESSTOKEN' ] ) ) {

			return (string) $microsoftAuth->getAccessToken( $_SERVER[ 'HTTP_X_MSACCESSTOKEN' ] );

		}
		//get access token
		else {
			return $microsoftAuth->getApplicationAccessToken();
		}
	}
	/**
	 * @param          $accessToken
	 * @param  string  $path
	 *
	 * @return \Microsoft\Graph\Model\DriveItem[]
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	private function getMicrosoftDriveItems( $accessToken, string $path ) : array {
		//get file list
		try {
			$graph = new \Microsoft\Graph\Graph();
			$graph->setAccessToken( $accessToken );

			//get all the project folders
			/** @var \Microsoft\Graph\Model\DriveItem[] $driveItems */
			$driveItems = $graph->createRequest( "GET", "/drives/".config::getEnvironmentConfig()->microsoft->driveId.'/root:/'.$this->rootBasePath . $path . ":/children" )
			                    ->setReturnType( \Microsoft\Graph\Model\DriveItem::class )->execute();

			foreach( $driveItems as $i => $driveItem ) {
				if( $driveItem->getFolder() !== null ) {
					$children = $this->getMicrosoftDriveItems( $accessToken, $path . '/' . $driveItem->getName() );
					$driveItems[ $i ]->setChildren( $children );
				}
			}

			return $driveItems;
		}
		catch( ClientException $e ) {
			throw new serviceException( 'Folder not found', $e->getCode(), $e );
		}
		catch( GuzzleException $e ) {
			throw new serviceException( 'Error getting files from Microsoft', 500, $e );
		}
		catch( GraphException $e ) {
			throw new serviceException( $e->getMessage(), 500, $e );
		}
	}


	/**
	 * @param            $accessToken
	 * @param  string[]  $basePath
	 *
	 * @return \Microsoft\Graph\Model\DriveItem[]
	 */
	private function createMicrosoftDirectories( $accessToken, array $basePath ) : array {
		$driveItems = [];

		$graph = new \Microsoft\Graph\Graph();
		$graph->setAccessToken( $accessToken );

		$path = '';
		foreach( $basePath as $i => $directoryName ) {
			$body = [
				'name'                              => $directoryName,
				'folder'                            => (object) [],
				'@microsoft.graph.conflictBehavior' => 'fail'
			];

			try {
				/** @var \Microsoft\Graph\Model\DriveItem $driveItem */
				$driveItems[] = $graph->createRequest( "POST", "/drives/".config::getEnvironmentConfig()->microsoft->driveId.'/root:/'.$this->rootBasePath . $path . ":/children" )
				                      ->attachBody( $body )->setReturnType( \Microsoft\Graph\Model\DriveItem::class )
				                      ->execute();
			}
			catch( \Exception | \GuzzleHttp\Exception\GuzzleException $e ) {
				error_log( $path . '/' . $directoryName . ' not created - already exists?' );
				error_log( $e );
			}

			//build out the path with each iteration since the array is the directory structure
			//ie if $basePath array = [ parent folder, child folder, grandchild folder ], $path becomes "/parent folder/child folder" after the second index
			$path .= '/' . $directoryName;
		}

		return $driveItems;
	}

}