<?php

declare(strict_types=1);

namespace Drupal\ab_core\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates AbWorkspaceOwnerConstraint.
 */
final class AbWorkspaceOwnerConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof AbWorkspaceOwnerConstraint) {
      throw new \InvalidArgumentException('Unexpected constraint.');
    }
    if ($value === NULL || $value === '') {
      return;
    }
    if (!is_numeric($value) || (int) $value <= 0) {
      $this->context->addViolation($constraint->message);
    }
  }

}
