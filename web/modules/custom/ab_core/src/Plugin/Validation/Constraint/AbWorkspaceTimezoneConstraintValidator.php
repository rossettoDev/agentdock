<?php

declare(strict_types=1);

namespace Drupal\ab_core\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that a timezone is a known IANA identifier.
 */
final class AbWorkspaceTimezoneConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof AbWorkspaceTimezoneConstraint) {
      throw new \InvalidArgumentException('Unexpected constraint.');
    }
    if ($value === NULL || $value === '') {
      return;
    }
    $known = \DateTimeZone::listIdentifiers();
    if (!is_string($value) || !in_array($value, $known, TRUE)) {
      $timezone = is_scalar($value) ? (string) $value : '';
      $this->context->addViolation($constraint->message, [
        '%timezone' => $timezone,
      ]);
    }
  }

}
