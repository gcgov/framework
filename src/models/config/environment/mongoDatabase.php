<?php

namespace gcgov\framework\models\config\environment;




class mongoDatabase extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public bool   $default  = false;

	public string $uri      = '';

	public string $database = '';

	/** @var array Associative array to pass to the mongo client */
	public array $clientParams       = [];

	public bool  $include_meta       = true;

	public bool  $include_metaLabels = true;

	public bool  $include_metaFields = false;

	public bool  $logging            = false;

	public bool   $audit             = false;

	public string $auditDatabaseName = '';

	public string $auditDatabaseUri  = '';


	public function __construct() {
	}


	protected function _afterJsonDeserialize(): void {
		if( $this->audit ) {
			if( empty( $this->auditDatabaseName ) ) {
				$this->auditDatabaseName = $this->database;
			}
			if( empty( $this->auditDatabaseUri ) ) {
				$this->auditDatabaseUri = $this->uri;
			}
		}
	}

}