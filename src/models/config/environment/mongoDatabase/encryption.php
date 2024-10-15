<?php

namespace gcgov\framework\models\config\environment\mongoDatabase;

class encryption extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public bool $useEncryption = false;
	public string $keyVaultNamespace = '';

	public kmsProviders $kmsProviders;

	/** @var \gcgov\framework\models\config\environment\mongoDatabase\encryptedCollectionsFields[] $encryptedCollectionsFields */
	public array $encryptedCollectionsFields = [];

	public string $cryptSharedLibPath = '';


	public function __construct() {
		$this->kmsProviders = new kmsProviders();
	}

	protected function _afterJsonDeserialize(): void {
		if(!isset($this->kmsProviders)) {
			$this->kmsProviders = new kmsProviders();
		}
	}


	/**
	 * @param string $collectionName
	 *
	 * @return array
	 */
	public function getDriverOptionEncryptedCollectionFieldMap( string $collectionName ): array {
		$map = [];
		foreach( $this->encryptedCollectionsFields as $encryptedCollectionFields) {
			if($encryptedCollectionFields->collection==$collectionName) {
				foreach($encryptedCollectionFields->encryptedFieldMap as $encryptedFieldMap) {
					$map[] = $encryptedFieldMap->toDriverArray();
				}
				break;
			}
		}
		return $map;
	}

	/**
	 * @param string $collectionName
	 *
	 * @return encryptedFieldMap[]
	 */
	public function getFieldMap( string $collectionName ): array {
		foreach( $this->encryptedCollectionsFields as $encryptedCollectionFields) {
			if($encryptedCollectionFields->collection==$collectionName) {
				return $encryptedCollectionFields->encryptedFieldMap;
			}
		}
		return [];
	}


	public function getKeyVaultCollectionName(): string {
		return substr( $this->keyVaultNamespace, strpos( $this->keyVaultNamespace, '.' ) + 1 );
	}

	public function getDriverOptionKmsProviders(): array {
		$kmsProviders = [];
		if( isset( $this->kmsProviders?->gcp ) ) {
			$kmsProviders[ 'gcp' ] = [
				'email' => $this->kmsProviders->gcp->email
			];
			if( !empty( $this->kmsProviders->gcp->privateKey ) ) {
				$kmsProviders[ 'gcp' ][ 'privateKey' ] = $this->kmsProviders->gcp->privateKey;
			}
			if( !empty( $this->kmsProviders->gcp->privateKeyFilePathName ) ) {
				$kmsProviders[ 'gcp' ][ 'privateKey' ] = file_get_contents( $this->kmsProviders->gcp->privateKeyFilePathName );
			}
		}

		return $kmsProviders;
	}
}
