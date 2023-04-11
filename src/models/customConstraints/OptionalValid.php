<?php

namespace gcgov\framework\models\customConstraints;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

#[\Attribute]
class OptionalValid extends Constraint {

	public bool $negate = false;
	public string $expression = '';
	public string $message = 'The field "{{ string }}" is invalid';

	#[HasNamedArguments]
	public function __construct( string $expression, bool $negate=false, array $groups = null, mixed $payload = null ) {
		$this->expression = $expression;
		$this->negate = $negate;
		parent::__construct([], $groups, $payload);
	}

}