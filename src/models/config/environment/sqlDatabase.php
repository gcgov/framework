<?php

namespace gcgov\framework\models\config\environment;


class sqlDatabase extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public bool            $default = false;

	public string          $name    = '';

	public string          $dsn     = '';

	public sqlDatabaseUser $readAccount;

	public sqlDatabaseUser $writeAccount;


	public function __construct() {
		$this->readAccount  = new sqlDatabaseUser();
		$this->writeAccount = new sqlDatabaseUser();
	}


	protected function _afterJsonDeserialize(): void {
		// jsonDeserialize may instantiate this class without invoking the constructor,
		// leaving these typed-non-nullable properties uninitialized. Without this guard an
		// entry that omits readAccount/writeAccount raises "must not be accessed before
		// initialization" at the point of use rather than a configException at load.
		foreach( [ 'readAccount', 'writeAccount' ] as $property ) {
			if( !( new \ReflectionProperty( $this, $property ) )->isInitialized( $this ) ) {
				$this->$property = new sqlDatabaseUser();
			}
		}
	}

}
