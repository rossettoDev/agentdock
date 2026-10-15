<?php

declare(strict_types=1);

namespace Drupal\ab_core;

use Drupal\ab_core\Entity\Workspace;
use Drupal\user\UserInterface;

/**
 * Provisions the single personal workspace of a user.
 */
interface PersonalWorkspaceProvisionerInterface {

  /**
   * Timezone used for the personal workspace.
   */
  public const TIMEZONE = 'America/Sao_Paulo';

  /**
   * Default name of the personal workspace.
   */
  public const NAME = 'Pessoal';

  /**
   * Returns the user's personal workspace, creating it once.
   *
   * The workspace and its owner membership commit together. A failure rolls
   * both back. A later call returns the same workspace.
   *
   * @throws \InvalidArgumentException
   *   The account is anonymous or is not stored.
   */
  public function provision(UserInterface $account): Workspace;

}
