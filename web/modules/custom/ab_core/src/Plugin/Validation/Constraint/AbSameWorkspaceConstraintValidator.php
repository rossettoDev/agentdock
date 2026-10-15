<?php

declare(strict_types=1);

namespace Drupal\ab_core\Plugin\Validation\Constraint;

use Drupal\ab_core\Authorization\WorkspaceBoundary;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that entity references stay inside the workspace.
 */
final class AbSameWorkspaceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WorkspaceBoundary $boundary,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get(WorkspaceBoundary::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    $is_scoped = $constraint instanceof AbSameWorkspaceConstraint && $value instanceof ContentEntityInterface && $value->hasField('workspace');
    if (!$is_scoped) {
      return;
    }
    $workspace = $this->loadWorkspace($value->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      return;
    }
    foreach ($value->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'entity_reference' || $name === 'user' || $name === 'workspace') {
        continue;
      }
      $target_id = $value->get($name)->getString();
      if ($target_id === '') {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($definition->getSetting('target_type'));
      $target = $storage->load($target_id);
      if ($target instanceof ContentEntityInterface && $this->boundary->isCrossWorkspaceReference($workspace, $target)) {
        $this->context->addViolation($constraint->message);
      }
    }
  }

  /**
   * Loads a workspace by id.
   */
  private function loadWorkspace(string $workspace_id): ?Workspace {
    if ($workspace_id === '') {
      return NULL;
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($workspace_id);
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

}
