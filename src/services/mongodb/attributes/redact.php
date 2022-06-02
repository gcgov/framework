<?php

namespace gcgov\framework\services\mongodb\attributes;


use Attribute;


#[Attribute( Attribute::TARGET_PROPERTY )]
class redact {

	/** @var string[] If the user has one or more of these roles,  */
	public array $redactIfUserHasAnyRoles = [];

	/** @var string[] If the user has all of these roles,  */
	public array $redactIfUserHasAllRoles = [];


	public function __construct( array $redactIfUserHasAnyRoles=[], array $redactIfUserHasAllRoles=[] ) {
		$this->redactIfUserHasAnyRoles = $redactIfUserHasAnyRoles;
		$this->redactIfUserHasAllRoles = $redactIfUserHasAllRoles;
	}

}