<?php
//TODO: rework (burn down?) for \gcgov\framework
namespace gcgov\framework\helpers;

class db {

	private string $database = '';
	private array $connect = [];
	private array $pdo = [];

	function __construct( $params = [] ) {

		//make sure testing mode has been set (can be unset when running as cli)
		if( !isset($_SESSION[ AS_APP ][ 'testing' ]) ) {
			ASsessionController::setTesting();
		}

		//set database we're reading from
		if( isset($params[ 'database' ]) ) {
			$this->database = $params[ 'database' ];
		}
		else {
			$this->database = $_SESSION[ AS_APP ][ 'environmentConfig' ][ 'default_db' ];
		}

		//define our connection
		if( $this->database!==null ) {
			$this->defineConnectionSettings();
		}

		//we could define our connections immediately with the following:
		//$this->pdoConnect();
		//but this would connect a read and write user so we wait until each is needed the first time

	}


	/**
	 * read
	 *
	 * Fetch the results of a query and returns a numerical array of the results
	 *
	 * @param string $query     The SQL statement to run
	 *
	 * @param array  $params    (optional) Associative array of columnName=>value to pass into query
	 * @param string $className (optional) to retrieve each row as a specific class, pass the fully qualified class name. Excluding a class name will return an associative array for each row of data.
	 *
	 * @return array numerical array of the rows returned from the query
	 */
	public function read( $query, $params = [], $className = null ) : array {

		try {

			//verify connection exists for reading
			if( !isset($this->pdo[ 'read' ]) ) {
				$connected = $this->pdoConnect([ 'read' ]);

				if( $connected===false ) {
					throw new \PDOException('Database ' . $this->database . ' unavailable');
				}
			}

			//run the query
			if( count($params)>0 ) {
				//prepare query
				$sth = $this->pdo[ 'read' ]->prepare($query);

				//execute query
				$sth->execute($params);
			}
			else {
				$sth = $this->pdo[ 'read' ]->query($query);
			}

			//get results of query
			if( !isset($className) ) {
				$result = $sth->fetchAll(\PDO::FETCH_ASSOC);
			}
			else {
				$result = $sth->fetchAll(\PDO::FETCH_CLASS, $className);
			}


		}
		catch( \PDOException $e ) {

			error_log('Query Failed: ' . $query);
			error_log('Query Error Message: ' . $e->getMessage());

			$result = [];

		}

		if( $result===false ) {
			$result = [];
		}

		return $result;
	}


	/**
	 * readOneColumn
	 *
	 * Fetch the results of a query and returns a numerical array of the first column
	 *
	 * @param  string  $query   The SQL statement to run
	 *
	 * @param  array   $params  (optional) Associative array of columnName=>value to pass into query
	 *
	 * @return array numerical array of the first column of each row returned from the query
	 */
	public function readOneColumn( string $query, array $params = [] ) : array {

		try {

			//verify connection exists for reading
			if( !isset($this->pdo[ 'read' ]) ) {
				$connected = $this->pdoConnect([ 'read' ]);

				if( $connected===false ) {
					throw new \PDOException('Database ' . $this->database . ' unavailable');
				}
			}

			//run the query
			if( count($params)>0 ) {
				//prepare query
				$sth = $this->pdo[ 'read' ]->prepare($query);

				//execute query
				$sth->execute($params);
			}
			else {
				$sth = $this->pdo[ 'read' ]->query($query);
			}

			//get results of query
			$result = $sth->fetchAll(\PDO::FETCH_COLUMN, 0);

		}
		catch( \PDOException $e ) {

			error_log('Query Failed: ' . $query);
			error_log('Query Error Message: ' . $e->getMessage());

			$result = [];

		}

		if( $result===false ) {
			$result = [];
		}

		return $result;
	}


	/**
	 * readOneRow
	 *
	 * Fetch the results of a query and return the last or only row of data
	 *
	 * @param string $query     The SQL statement to run
	 *
	 * @param array  $params    (optional) Associative array of columnName=>value to pass into query
	 * @param string $className (optional) to retrieve each row as a specific class, pass the fully qualified class name. Excluding a class name will return an associative array for each row of data.
	 *
	 * @return mixed associative array of the columns selected for the last or only row of data OR specific class type if class name is provided
	 */
	public function readOneRow( $query, $params = [], $className = null ) {

		$result = [];

		try {

			//verify connection exists for reading
			if( !isset($this->pdo[ 'read' ]) ) {
				$connected = $this->pdoConnect([ 'read' ]);

				if( $connected===false ) {
					throw new \PDOException('Database ' . $this->database . ' unavailable');
				}
			}

			//run the query
			if( count($params)>0 ) {
				//prepare query
				$sth = $this->pdo[ 'read' ]->prepare($query);

				//execute query
				$sth->execute($params);
			}
			else {
				$sth = $this->pdo[ 'read' ]->query($query);
			}

			//get results of query
			//get results of query
			if( !isset($className) ) {
				$result = $sth->fetch(\PDO::FETCH_ASSOC);
			}
			else {
				$sth->setFetchMode(\PDO::FETCH_CLASS, $className);
				$result = $sth->fetch();
			}

		}
		catch( \PDOException $e ) {

			error_log('Query Failed: ' . $query);
			error_log('Query Error Message: ' . $e->getMessage());

			if($className===null) {
				$result = [];
			}
			else {
				$result = false;
			}

		}


		if($className===null && $result===false) {
			$result = [];
		}

		return $result;
	}


	/**
	 * write
	 *
	 * Update, insert, or delete a query
	 *
	 * @param $query (string) the SQL statement to run
	 *
	 * @return boolean true
	 */
	public function write( $query, $params = [] ) {

		//verify connection exists for writing
		if( !isset($this->pdo[ 'write' ]) ) {
			$connected = $this->pdoConnect([ 'write' ]);

			if( $connected===false ) {
				throw new \PDOException('Database ' . $this->database . ' unavailable');
			}
		}

		try {
			//run the query
			if( count($params)>0 ) {
				//prepare query
				$sth = $this->pdo[ 'write' ]->prepare($query);

				//execute query
				$sth->execute($params);
			}
			else {
				$sth = $this->pdo[ 'write' ]->query($query);
			}
		}
		catch( \PDOException $e ) {

			error_log('Query Failed: ' . $query);
			error_log('Query Error Message: ' . $e->getMessage());

			$result = false;

		}

		//insert statements return the primary key
		try {
			$id = $this->pdo[ 'write' ]->lastInsertId();
		}
		catch( Exception $e ) {
			$id = true;
		}


		return $id;
	}



	private function defineConnectionSettings() {

		$env = $_SESSION[ AS_APP ][ 'environmentConfig' ];

		$dbConnector = [];

		if( isset($env[ 'db_connector' ][ 0 ]) ) {
			foreach( $env[ 'db_connector' ] as $dbconnectors ) {

				$dbNames = [];
				if( isset($dbconnectors[ 'alias' ]) ) {
					if( is_array($dbconnectors[ 'alias' ]) ) {
						$dbNames = $dbconnectors[ 'alias' ];
					}
					else {
						$dbNames[] = $dbconnectors[ 'alias' ];
					}
				}
				$dbNames[] = $dbconnectors[ 'db' ];

				if( in_array($this->database, $dbNames) ) {
					$dbConnector = $dbconnectors;
				}
			}
		}
		else {
			$dbConnector = $env[ 'db_connector' ];
		}

		if( count($dbConnector)==0 ) {
			error_log('DB Connector invalid (none provided in var/config.xml)');
			httpError(500);
		}

		$this->connect = [
			'read'  => [
				'server'    => isset($dbConnector[ 'server' ]) ? $dbConnector[ 'server' ] : '',
				'driver'    => isset($dbConnector[ 'driver' ]) ? $dbConnector[ 'driver' ] : '',
				'pdodriver' => isset($dbConnector[ 'pdodriver' ]) ? $dbConnector[ 'pdodriver' ] : '',
				'db'        => isset($dbConnector[ 'db' ]) ? $dbConnector[ 'db' ] : '',
				'user'      => isset($dbConnector[ 'read' ][ 'user' ]) ? $dbConnector[ 'read' ][ 'user' ] : '',
				'pass'      => isset($dbConnector[ 'read' ][ 'pass' ]) ? $dbConnector[ 'read' ][ 'pass' ] : ''
			],
			'write' => [
				'server'    => isset($dbConnector[ 'server' ]) ? $dbConnector[ 'server' ] : '',
				'driver'    => isset($dbConnector[ 'driver' ]) ? $dbConnector[ 'driver' ] : '',
				'pdodriver' => isset($dbConnector[ 'pdodriver' ]) ? $dbConnector[ 'pdodriver' ] : '',
				'db'        => isset($dbConnector[ 'db' ]) ? $dbConnector[ 'db' ] : '',
				'user'      => isset($dbConnector[ 'write' ][ 'user' ]) ? $dbConnector[ 'write' ][ 'user' ] : '',
				'pass'      => isset($dbConnector[ 'write' ][ 'pass' ]) ? $dbConnector[ 'write' ][ 'pass' ] : ''
			]
		];

		if( isset($dbConnector[ 'dsn' ]) ) {
			$this->connect[ 'read' ][ 'dsn' ]  = $dbConnector[ 'dsn' ];
			$this->connect[ 'write' ][ 'dsn' ] = $dbConnector[ 'dsn' ];
		}

	}


	private function pdoConnect( $keys = [ 'read', 'write' ] ) {

		try {

			foreach( $keys as $key ) {

				if( $this->connect[ $key ][ 'pdodriver' ]=='sqlsrv' ) {
					$this->pdo[ $key ] = new \PDO("sqlsrv:Server=" . $this->connect[ $key ][ 'server' ] . ";
										Database=" . $this->connect[ $key ][ 'db' ],
					                             $this->connect[ $key ][ 'user' ],
					                             $this->connect[ $key ][ 'pass' ]
					);
				}

				elseif( $this->connect[ $key ][ 'pdodriver' ]=='mysql' ) {
					$this->pdo[ $key ] = new \PDO('mysql:host=' . $this->connect[ $key ][ 'server' ] . ';
									dbname=' . $this->connect[ $key ][ 'db' ],
					                             $this->connect[ $key ][ 'user' ],
					                             $this->connect[ $key ][ 'pass' ]
					);
				}

				elseif( $this->connect[ $key ][ 'pdodriver' ]=='odbc' ) {

					if( isset($this->connect[ $key ][ 'dsn' ]) ) {
						$this->pdo[ $key ] = new \PDO("odbc:DSN=" . $this->connect[ $key ][ 'dsn' ],
						                             $this->connect[ $key ][ 'user' ],
						                             $this->connect[ $key ][ 'pass' ]
						);
					}

					//dBASE, Access (file based)
					elseif( strpos(strtolower($this->connect[ $key ][ 'driver' ]), 'dbase')!==false || strpos(strtolower($this->connect[ $key ][ 'driver' ]), 'access')!==false ) {
						$this->pdo[ $key ] = new \PDO("odbc:Driver=" . $this->connect[ $key ][ 'driver' ] . ";
											Dbq=" . $this->connect[ $key ][ 'server' ] . ";
											UID=" . $this->connect[ $key ][ 'user' ] . ";",
						                             $this->connect[ $key ][ 'user' ],
						                             $this->connect[ $key ][ 'pass' ]
						);
					}

					//pervasive 64 bit
					elseif( $this->connect[ $key ][ 'driver' ]=='{Pervasive ODBC Interface}' ) {
						$connStr           = "odbc:Driver=" . $this->connect[ $key ][ 'driver' ] . ";
											ServerName=" . $this->connect[ $key ][ 'server' ] . ";
											Dbq=" . $this->connect[ $key ][ 'db' ] . ";
											UID=" . $this->connect[ $key ][ 'user' ] . ";
											PWD=" . $this->connect[ $key ][ 'pass' ] . ";";
						$this->pdo[ $key ] = new \PDO($connStr,
						                             $this->connect[ $key ][ 'user' ],
						                             $this->connect[ $key ][ 'pass' ]
						);
					}

					//pervasive 32 bit
					else {
						$this->pdo[ $key ] = new \PDO("odbc:Driver=" . $this->connect[ $key ][ 'driver' ] . ";
											ServerName=" . $this->connect[ $key ][ 'server' ] . ";
											ServerDSN=" . $this->connect[ $key ][ 'db' ] . ";
											UID=" . $this->connect[ $key ][ 'user' ] . ";
											PWD=" . $this->connect[ $key ][ 'pass' ] . ";",
						                             $this->connect[ $key ][ 'user' ],
						                             $this->connect[ $key ][ 'pass' ]
						);
					}
				}

				$this->pdo[ $key ]->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

			}

		}
		catch( \PDOException $e ) {
			error_log($e->getMessage());
		}

		if( count($this->pdo)===0 ) {

			foreach( $keys as $key ) {
				error_log('Database failed to load. Driver:' . $this->connect[ $key ][ 'driver' ] . '; Server:' . $this->connect[ $key ][ 'server' ] . '; DB:' . $this->connect[ $key ][ 'db' ]);
			}

			return false;
		}

		return true;
	}

	public function getConnectionDetails() {
		return $this->connect;
	}


}