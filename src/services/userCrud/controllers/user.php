<?php
namespace gcgov\framework\services\userCrud\controllers;

use gcgov\framework\exceptions\controllerException;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\interfaces\controller;
use gcgov\framework\models\controllerDataResponse;
use gcgov\framework\models\controllerPagedDataResponse;
use gcgov\framework\services\request;

/**
 * Class user
 * @OA\Tag(
 *     name="User",
 *     description="user object"
 * )
 */
class user implements controller {

	public function __construct() {
	}


	/**
	 * Processed after lifecycle is complete with this instance
	 */
	public static function _after(): void {
	}


	/**
	 * Processed prior to __constructor() being called
	 */
	public static function _before(): void {
	}


	/**
	 * @OA\Get(
	 *     path="/user/",
	 *     tags={"User", "Auth"},
	 *     description="Fetch all users",
	 *     @OA\Parameter(
	 *         name="limit",
	 *         in="query",
	 *         description="How many objects to fetch",
	 *         required=false,
	 *         @OA\Schema(type="int")
	 *     ),
	 *     @OA\Parameter(
	 *         name="page",
	 *         in="query",
	 *         description="What page of objects to fetch. Defaults to 1 when limit is provided.",
	 *         required=false,
	 *         @OA\Schema(type="int")
	 *     ),
	 *     @OA\Response(
	 *      response="200",
	 *      description="Successfully fetched",
	 *      @OA\JsonContent(
	 *          type="array",
	 *          @OA\Items(ref="#/components/schemas/user")
	 *      )
	 *    )
	 * )
	 * @return \gcgov\framework\models\controllerPagedDataResponse
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function getAll(): controllerPagedDataResponse {

		$filter  = [];
		$options = [
			'sort' => [
				'name' => -1
			]
		];
		$limit   = $_GET[ 'limit' ] ?? 10;
		$page    = $_GET[ 'page' ] ?? 1;


		try {
			$userClassName = request::getUserClassFqdn();
			$users = $userClassName::getPagedResponse($limit, $page, $filter, $options );
		}
		catch( modelException $e ) {
			throw new controllerException( $e->getMessage(), $e->getCode(), $e );
		}

		return new controllerPagedDataResponse( $users );

	}


	/**
	 * @OA\Get(
	 *     path="/user/{_id}",
	 *     tags={"User", "Auth"},
	 *      description="Fetch an user object",
	 *     @OA\Parameter(in="path", name="_id", required=true, @OA\Schema(type="string")),
	 *     @OA\Response(
	 *      response="200",
	 *      description="Successfully fetched",
	 *      @OA\JsonContent(ref="#/components/schemas/user")
	 *    )
	 * )
	 *
	 * @param string $_id
	 *
	 * @return \gcgov\framework\models\controllerDataResponse
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function getOne( string $_id ): controllerDataResponse {

		$userClassName = request::getUserClassFqdn();
		if( $_id==='new' ) {
			$user = new $userClassName();
		}
		else {
			try {
				$user = $userClassName::getOne( $_id );
			}
			catch( modelException $e ) {
				throw new controllerException( $e->getMessage(), $e->getCode(), $e );
			}
		}

		return new controllerDataResponse( $user );
	}


	/**
	 * @OA\Post(
	 *     path="/user/{_id}",
	 *     tags={"User", "Auth"},
	 *      description="Create or update an user object",
	 *     @OA\Parameter(in="path", name="_id", required=true, @OA\Schema(type="string")),
	 *     @OA\RequestBody(
	 *      description="user object to save",
	 *      required=true,
	 *      @OA\JsonContent(ref="#/components/schemas/user")
	 *     ),
	 *     @OA\Response(
	 *      response="200",
	 *      description="Successfully saved",
	 *      @OA\JsonContent(ref="#/components/schemas/user")
	 *    )
	 * )
	 *
	 * @param string $_id
	 *
	 * @return \gcgov\framework\models\controllerDataResponse
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function save( string $_id ): controllerDataResponse {

		$userClassName = request::getUserClassFqdn();
		$userJSON = file_get_contents( 'php://input' );
		try {
			$user = $userClassName::jsonDeserialize( $userJSON );
		}
		catch( modelException $e ) {
			throw new controllerException( $e->getMessage(), $e->getCode(), $e );
		}

		// The URL decides which document is written — not the body. This method used to
		// ignore $_id entirely, so a caller holding User.Write could POST to their own
		// /user/{_id} with a body naming any other account and overwrite it, roles
		// included. The URL is also the only part a reverse proxy, an audit log or a
		// route-level policy can see, so it has to be the part that binds.
		//
		// Compared as strings rather than coerced to a type: getId() is declared
		// string|int|ObjectId, and an application may substitute its own user model.
		if( $_id==='new' ) {
			// A create must create. Without this, POST /user/new carrying an existing
			// account's _id would overwrite that account — the same hole by another route.
			unset( $user->_id );
		}
		elseif( !isset( $user->_id ) || (string)$user->_id==='' ) {
			throw new controllerException( 'The request body must carry the _id it is being saved to', 400 );
		}
		elseif( (string)$user->_id!==$_id ) {
			throw new controllerException( 'The _id in the request body does not match the _id in the url', 400 );
		}

		try {
			$userClassName::save( $user );
		}
		catch( modelException $e ) {
			throw new controllerException( $e->getMessage(), $e->getCode(), $e );
		}

		return new controllerDataResponse( $user );
	}


	/**
	 * @OA\Delete(
	 *     path="/user/{_id}",
	 *     tags={"User", "Auth"},
	 *     @OA\Parameter(in="path", name="_id", required=true, @OA\Schema(type="string")),
	 *     @OA\Response(
	 *      response="204",
	 *      description="Successfully deleted"
	 *    )
	 * )
	 *
	 * @param string $_id
	 *
	 * @return \gcgov\framework\models\controllerDataResponse
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function delete( string $_id ): controllerDataResponse {
		$userClassName = request::getUserClassFqdn();
		try {
			$deleteResult = $userClassName::delete( $_id );
		}
		catch( modelException $e ) {
			throw new controllerException( $e->getMessage(), $e->getCode(), $e );
		}

		if( $deleteResult->getDeletedCount()===0 ) {
			throw new controllerException( 'user not found', 404 );
		}

		$response = new controllerDataResponse();
		$response->setHttpStatus( 204 );
		return $response;

	}

}
