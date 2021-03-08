<?php

namespace gcgov\framework\services\mongodb\attributes;


use Attribute;


#[Attribute( Attribute::TARGET_PROPERTY )]
class autoIncrement {

	public string $groupByPropertyName = '';
	public string $groupByMethodName = '';
	public string $countFormatMethod = '';

	public function __construct(
		string $groupByPropertyName = '',
		string $groupByMethodName='',
		string $countFormatMethod='' ) {

		$this->groupByPropertyName = $groupByPropertyName;
		$this->groupByMethodName = $groupByMethodName;
		$this->countFormatMethod = $countFormatMethod;

	}

}