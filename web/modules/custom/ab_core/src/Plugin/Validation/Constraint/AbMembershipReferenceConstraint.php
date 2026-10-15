<?php

declare(strict_types=1);

namespace Drupal\ab_core\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a membership reference points at a real entity.
 */
#[Constraint(
  id: 'AbMembershipReference',
  label: new TranslatableMarkup('Membership reference', [], ['context' => 'Validation']),
  type: FALSE,
)]
final class AbMembershipReferenceConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   */
  public string $message = 'The membership reference is missing.';

}
