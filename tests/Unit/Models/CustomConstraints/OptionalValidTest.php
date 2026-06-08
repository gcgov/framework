<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models\CustomConstraints;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\customConstraints\OptionalValid;
use gcgov\framework\models\customConstraints\OptionalValidValidator;

#[CoversClass(OptionalValid::class)]
#[CoversClass(OptionalValidValidator::class)]
final class OptionalValidTest extends TestCase {

	public function testConstructorAssignsExpression(): void {
		$constraint = new OptionalValid( 'value > 0' );
		$this->assertSame( 'value > 0', $constraint->expression );
		$this->assertFalse( $constraint->negate );
	}

	public function testNegateFlagCanBeSet(): void {
		$constraint = new OptionalValid( 'value < 0', true );
		$this->assertTrue( $constraint->negate );
	}

	public function testHasDefaultMessage(): void {
		$constraint = new OptionalValid( 'true' );
		$this->assertSame( 'The field "{{ string }}" is invalid', $constraint->message );
	}

	public function testIsAttribute(): void {
		$reflection = new \ReflectionClass( OptionalValid::class );
		$attributes = $reflection->getAttributes( \Attribute::class );
		$this->assertNotEmpty( $attributes );
	}

	public function testExtendsSymfonyConstraint(): void {
		$this->assertTrue(
			is_subclass_of(
				OptionalValid::class,
				\Symfony\Component\Validator\Constraint::class
			)
		);
	}

	public function testValidatorExtendsConstraintValidator(): void {
		$this->assertTrue(
			is_subclass_of(
				OptionalValidValidator::class,
				\Symfony\Component\Validator\ConstraintValidator::class
			)
		);
	}

	public function testValidatorReturnsEarlyForNullAndEmptyString(): void {
		$validator = new OptionalValidValidator();
		// Configure the validator with a mock execution context so that we
		// can assert no violations are built.
		$context = $this->createMock( \Symfony\Component\Validator\Context\ExecutionContextInterface::class );
		$context->expects( $this->never() )->method( 'buildViolation' );
		$validator->initialize( $context );

		$validator->validate( null, new OptionalValid( 'value > 0' ) );
		$validator->validate( '', new OptionalValid( 'value > 0' ) );

		$this->assertTrue( true );
	}

	public function testValidatorRejectsWrongConstraintType(): void {
		$validator = new OptionalValidValidator();
		$context = $this->createStub( \Symfony\Component\Validator\Context\ExecutionContextInterface::class );
		$validator->initialize( $context );

		$this->expectException( \Symfony\Component\Validator\Exception\UnexpectedTypeException::class );
		$validator->validate( 'any', new \Symfony\Component\Validator\Constraints\NotBlank() );
	}

}
