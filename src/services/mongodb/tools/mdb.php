<?php
namespace gcgov\framework\services\mongodb\tools;

use gcgov\framework\config;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\models\config\environment\mongoDatabase;

final class mdb {

	private array $driverOptions = [];

	public mongoDatabase $connector;

	/** @var \MongoDB\Client */
	public \MongoDB\Client $client;

	/** @var \MongoDB\Database */
	public \MongoDB\Database $db;

	/** @var \MongoDB\Collection */
	public \MongoDB\Collection $collection;

	/** @var bool */
	public bool $audit = false;

	/** @var bool */
	public bool $auditForward = false;

	public bool $include_meta = true;

	public bool $include_metaLabels = true;

	public bool $include_metaFields = false;


	/**
	 * mdb constructor.
	 *
	 * @param string $collection
	 * @param string $database    Optional - if you want to use a database other than the one marked as default:true in
	 *                            app/config/environment.json
	 * @param array  $driverOptions
	 *
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public function __construct( string $collection = '', string $database = '', array $driverOptions = [], bool $useEncryptedClient=true ) {
		$connector       = $this->getConnector( $database );
		$this->connector = $connector;

		try {
			if( $connector->audit && $collection=='audit' ) {
				$this->client = new \MongoDB\Client( $connector->auditDatabaseUri, $connector->auditDatabaseClientParams );
				$this->db     = $this->client->{$connector->auditDatabaseName};

				$this->audit              = false;
				$this->auditForward       = false;
				$this->include_meta       = false;
				$this->include_metaLabels = false;
				$this->include_metaFields = false;
			}
			else {
				if($useEncryptedClient && !empty( $collection )) {
					$driverOptions = $this->addEncryptionDriverOptions( $connector, $collection, $driverOptions );
				}

				$this->client = new \MongoDB\Client( $connector->uri, $connector->clientParams, $driverOptions );
				$this->db     = $this->client->{$connector->database};

				$this->audit              = $connector->audit;
				$this->auditForward       = $connector->auditForward;
				$this->include_meta       = $connector->include_meta;
				$this->include_metaLabels = $connector->include_metaLabels;
				$this->include_metaFields = $connector->include_metaFields;
			}

			if( !empty( $collection ) ) {
				$this->collection = $this->db->{$collection};
			}

		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new modelException( 'Database connection issue: ' . $e->getMessage(), 503, $e );
		}

		$this->driverOptions = $driverOptions;
	}


	private function addEncryptionDriverOptions( mongoDatabase $connector, string $collectionName = '', array $driverOptions = [] ): array {
		//if encryption is enabled
		if( $connector->encryption->useEncryption && !empty( $collectionName ) ) {
			$encryptedFieldsMap = $this->connector->encryption->getDriverOptionEncryptedCollectionFieldMap( $collectionName );

			if( count( $encryptedFieldsMap )>0 ) {
				$driverOptions[ 'autoEncryption' ] = [
					'useNewUrlParser'    => true,
					'useUnifiedTopology' => true,
					'keyVaultNamespace'  => $connector->encryption->keyVaultNamespace,
					'kmsProviders'       => $this->connector->encryption->getDriverOptionKmsProviders(),
					'encryptedFieldsMap' => $encryptedFieldsMap,
					'extraOptions'       => [
						'mongocryptdBypassSpawn' => true, //don't try to launch old mongocryptd
						'cryptSharedLibPath'     => $connector->encryption->cryptSharedLibPath, //find the new lib here
						'cryptSharedLibRequired' => true //force use of new lib
					]
				];
			}
		}

		return $driverOptions;
	}


	/**
	 * @param string $database
	 *
	 * @return \gcgov\framework\models\config\environment\mongoDatabase
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public function getConnector( string $database = '' ): mongoDatabase {
		$environmentConfig = config::getEnvironmentConfig();

		foreach( $environmentConfig->mongoDatabases as $mongoDatabase ) {
			if( $mongoDatabase->default && $database==='' ) {
				return $mongoDatabase;
			}
			elseif( $database!=='' && $mongoDatabase->database===$database ) {
				return $mongoDatabase;
			}
		}

		throw new modelException( 'No suitable Mongo Database connector found in environment config', 500 );
	}


	/**
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function startSessionTransaction( string $database = '' ): \MongoDB\Driver\Session {
		$mdb            = new mdb( '', $database );
		$mongoDbSession = $mdb->client->startSession( [ 'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' ) ] );
		$mongoDbSession->startTransaction( [ 'maxCommitTimeMS' => 60000 ] );

		return $mongoDbSession;
	}


	public function getCollectionCount( $filter ): int {
		return $this->collection->countDocuments( $filter );
	}


	public function getDriverOptions(): array {
		return $this->driverOptions;
	}


	private function createClientEncryption(): \MongoDB\Driver\ClientEncryption {
		$kmsProviders     = $this->connector->encryption->getDriverOptionKmsProviders();
		$clientEncryption = [
			'keyVaultNamespace' => $this->connector->encryption->keyVaultNamespace,
			'kmsProviders'      => $kmsProviders
		];
		return $this->client->createClientEncryption( $clientEncryption );
	}

	public function createDataKey( string $name ): \MongoDB\BSON\Binary {
		$clientEncryption = $this->createClientEncryption();

		//create data key
		$this->db->{$this->connector->encryption->getKeyVaultCollectionName()}->deleteMany([ 'keyAltNames'=>$name ]);
		$masterKey = json_decode( file_get_contents($this->connector->encryption->kmsProviders->gcp->masterKeyLocationFilePathName) );
		$dataKeyOptions = [
			'masterKey'   => $masterKey,
			'keyAltNames' => [ $name ]
		];
		return $clientEncryption->createDataKey( 'gcp', $dataKeyOptions );
	}


	/**
	 * @param string $collectionName
	 *
	 * @return \MongoDB\BSON\Binary[]
	 */
	public function createEncryptedCollection( string $collectionName ): array {
		$fieldMap = $this->connector->encryption->getFieldMap($collectionName);

		$deks = [];
		$fields = [];
		foreach($fieldMap as $field) {
			$deks[ $field->keyAltName ] = $this->createDataKey($field->keyAltName);
			$driverField = $field->toDriverArray();
			$driverField['keyId'] = $deks[ $field->keyAltName ];
			$fields[] = $driverField;
		}

		$collectionOptions = [
			'encryptedFields'=>[
				'fields' => $fields
			]
		];

		$this->db->dropCollection( $collectionName );

		$this->db->createCollection( $collectionName, $collectionOptions );

		return $deks;

	}

	public function rotateKeys(): void {
		putenv("GOOGLE_APPLICATION_CREDENTIALS=".$this->connector->encryption->kmsProviders->gcp->credentialsFilePathName);
		$masterKeyLocationInfo = json_decode( file_get_contents($this->connector->encryption->kmsProviders->gcp->masterKeyLocationFilePathName), true );


		//create a new version of the master key
		// Create the Cloud KMS client.
		$client = new \Google\Cloud\Kms\V1\Client\KeyManagementServiceClient();

		// Build the parent key name.
		$keyName = $client->cryptoKeyName($masterKeyLocationInfo['projectId'], $masterKeyLocationInfo['location'], $masterKeyLocationInfo['keyRing'], $masterKeyLocationInfo['keyName']);

		// Build the key version.
		$version = new \Google\Cloud\Kms\V1\CryptoKeyVersion();

		// Call the API.
		$createCryptoKeyVersionRequest = (new \Google\Cloud\Kms\V1\CreateCryptoKeyVersionRequest())
			->setParent($keyName)
			->setCryptoKeyVersion($version);
		$createdVersion = $client->createCryptoKeyVersion($createCryptoKeyVersionRequest);
		error_log('Created key version: ' . $createdVersion->getName());



		//update the primary version of the key
		// Call the API.
		$updateCryptoKeyPrimaryVersionRequest = (new \Google\Cloud\Kms\V1\UpdateCryptoKeyPrimaryVersionRequest())
			->setName($keyName)
			->setCryptoKeyVersionId( substr($createdVersion->getName(), strrpos($createdVersion->getName(), '/' )+1 ));
		$updatedKey = $client->updateCryptoKeyPrimaryVersion( $updateCryptoKeyPrimaryVersionRequest );
		error_log('Updated primary '. $updatedKey->getName(). ' to '. $createdVersion->getName());

		//rewrap the deks
		$clientEncryption = $this->createClientEncryption();
		$clientEncryption->rewrapManyDataKey([], [ 'provider'=>'gcp', 'masterKey'=>$masterKeyLocationInfo ]);


		//return $createdVersion;
	}


}
