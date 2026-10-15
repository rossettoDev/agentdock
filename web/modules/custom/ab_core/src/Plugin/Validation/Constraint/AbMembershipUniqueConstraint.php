<?php

declare(strict_types=1);

namespace Drupal\ab_core\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a user has one membership per workspace.
 */
#[Constraint(
  id: 'AbMembershipUnique',
  label: new TranslatableMarkup('Unique membership', [], ['context' => 'Validation']),
  type: ['entity'],
)]
final class AbMembershipUniqueConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   */
  public string $message = 'This user already belongs to the workspace.';

}
