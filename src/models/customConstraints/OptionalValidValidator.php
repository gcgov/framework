<?php

namespace gcgov\framework\models\customConstraints;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\LogicException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Constraints as Assert;
class OptionalValidValidator extends ConstraintValidator {

	public function validate($value, Constraint $constraint): void
	{
		if (!$constraint instanceof OptionalValid) {
			throw new UnexpectedTypeException($constraint, OptionalValid::class);
		}

		// custom constraints should ignore null and empty values to allow
		// other constraints (NotBlank, NotNull, etc.) to take care of that
		if (null === $value || '' === $value) {
			return;
		}

		//if (!is_string($value)) {
			// throw this exception if your validator cannot handle the passed type so that it can be marked as invalid
			//throw new UnexpectedValueException($value, 'string');

			// separate multiple types using pipes
			// throw new UnexpectedValueException($value, 'string|int');
		//}

		// access your configuration options like this:
		//if ('strict' === $constraint->mode) {
			// ...
		//}

		if (!class_exists(ExpressionLanguage::class)) {
			throw new LogicException(sprintf('The "symfony/expression-language" component is required to use the "%s" constraint.', __CLASS__));
		}

		$expressionLanguage = new ExpressionLanguage();
		$variables = [
			'value' => $value,
			'this' => $this->context->getObject()
		];
		$expResult = $expressionLanguage->evaluate($constraint->expression, $variables);
		if ($constraint->negate xor $expressionLanguage->evaluate($constraint->expression, $variables)) {
			//add Assert\Valid
			$errors = $this->context->getValidator()->validate($value, new Assert\Valid() );

			foreach( $errors as $error ) {
				$this->context->buildViolation( $error->getMessage() )
					->atPath(  $error->getPropertyPath() )
					->addViolation();
			}
		}

	}
}