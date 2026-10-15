<?php

declare(strict_types=1);

namespace Drupal\ab_core\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a workspace timezone is a known IANA identifier.
 */
#[Constraint(
  id: 'AbWorkspaceTimezone',
  label: new TranslatableMarkup('Workspace timezone', [], ['context' => 'Validation']),
  type: FALSE,
)]
final class AbWorkspaceTimezoneConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   */
  public string $message = 'The timezone "%timezone" is not a valid IANA timezone.';

}
