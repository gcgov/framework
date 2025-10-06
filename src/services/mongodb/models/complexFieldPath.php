<?php

namespace gcgov\framework\services\mongodb\models;

use MongoDB\BSON\ObjectId;

class complexFieldPath {

	private string        $fieldPath        = '';
	private bool          $useArrayFilter   = true;
	private ObjectId|null $arrayFilterValue = null;

	private array $arrayFilters     = [];
	private array $complexPathParts = [];


	public function __construct( string $fieldPath, bool $useArrayFilter = true, ObjectId $arrayFilterValue = null ) {
		$this->fieldPath        = $fieldPath;
		$this->useArrayFilter   = $useArrayFilter;
		$this->arrayFilterValue = $arrayFilterValue;

		$this->convert();
	}


	private function convert(): void {
		//convert $fieldPath  `
		// from     `inspections.$.scheduleRequests.$.comments.$`
		// to       `inspections.$[].scheduleRequests.$[].comments.$[arrayFilter]`
		$pathParts          = explode( '.', $this->fieldPath );
		$reversedPathParts  = array_reverse( $pathParts );
		$foundPrimaryTarget = false;

		$this->arrayFilters = [];
		$arrayFilterIndex   = 0;

		foreach( $reversedPathParts as $i => $part ) {
			//on the first dollar sign, convert `$`=>`$[arrayFilter]`
			if( !$foundPrimaryTarget && $part==='$' ) {
				$foundPrimaryTarget = true;
				if( $this->useArrayFilter ) {
					$reversedPathParts[ $i ] = '$[arrayFilter' . $arrayFilterIndex . ']';
					$this->arrayFilters[]    = [ 'arrayFilter' . $arrayFilterIndex . '._id' => $this->arrayFilterValue ];
					$arrayFilterIndex++;
				}
				else {
					unset( $reversedPathParts[ $i ] );
				}
			}
			elseif( $foundPrimaryTarget && $part==='$' ) {
				if( $this->useArrayFilter ) {
					$reversedPathParts[ $i ] = '$[arrayFilter' . $arrayFilterIndex . ']';

					$notNullPathParts = [];
					for( $notNullIndex = $i - 1; $notNullIndex>=0; $notNullIndex-- ) {
						if( str_starts_with( $reversedPathParts[ $notNullIndex ], '$' ) ) {
							break;
						}
						$notNullPathParts[] = $reversedPathParts[ $notNullIndex ];
					}
					$this->arrayFilters[] = [ 'arrayFilter' . $arrayFilterIndex . '.' . implode( '.', $notNullPathParts ) => [ '$ne' => null ] ];

					$arrayFilterIndex++;
				}
				else {
					$reversedPathParts[ $i ] = '$[]';
				}
			}
		}

		$this->arrayFilters     = array_reverse( $this->arrayFilters );
		$this->complexPathParts = array_reverse( $reversedPathParts );

	}


	public function getComplexPath(): string {
		return implode( '.', $this->complexPathParts );
	}


	public function getArrayFilters(): array {
		return $this->arrayFilters;
	}

}
