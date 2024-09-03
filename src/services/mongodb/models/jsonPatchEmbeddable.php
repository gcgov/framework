<?php
namespace gcgov\framework\services\mongodb\models;

use OpenApi\Attributes as OA;

#[OA\Schema]
class jsonPatchEmbeddable extends \gcgov\framework\services\mongodb\embeddable {

	#[OA\Property]
	public string $op = '';

	#[OA\Property]
	public string $path = '';

	#[OA\Property]
	public $value = null;


	public function __construct() {
		parent::__construct();
	}

	/*
	 * Available hooks:
	 *  protected function _beforeJsonSerialize() : void {};
	 *  protected function _afterJsonDeserialize() : void {}
	 *  protected function _beforeBsonSerialize() : void {};
	 *  protected function _afterBsonUnserialize( $rawBsonData ) : void {};
	 *  protected static function _beforeSave( self & ) : void {}
	 *  protected static function _afterSave( self &, bool , ?updateDeleteResult  = null ) : void {}
	 */
}
