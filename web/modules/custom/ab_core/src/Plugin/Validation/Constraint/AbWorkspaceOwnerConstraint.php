<?php

declare(strict_types=1);

namespace Drupal\ab_core\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a workspace owner is a real user.
 */
#[Constraint(
  id: 'AbWorkspaceOwner',
  label: new TranslatableMarkup('Workspace owner', [], ['context' => 'Validation']),
  type: FALSE,
)]
final class AbWorkspaceOwnerConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   */
  public string $message = 'The workspace owner is missing.';

}
