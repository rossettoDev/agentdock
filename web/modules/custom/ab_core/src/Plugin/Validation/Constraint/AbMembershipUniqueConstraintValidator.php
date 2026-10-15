<?php

declare(strict_types=1);

namespace Drupal\ab_core\Plugin\Validation\Constraint;

use Drupal\ab_core\Entity\Membership;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that workspace and user are unique together.
 */
final class AbMembershipUniqueConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof AbMembershipUniqueConstraint) {
      throw new \InvalidArgumentException('Unexpected constraint.');
    }
    if (!$value instanceof Membership) {
      return;
    }
    $workspace_id = $value->get('workspace')->getString();
    $user_id = $value->get('user')->getString();
    if ($workspace_id === '' || $user_id === '') {
      return;
    }
    $query = $this->entityTypeManager->getStorage('ab_membership')->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace_id)
      ->condition('user', $user_id);
    if ($value->id()) {
      $query->condition('id', $value->id(), '<>');
    }
    if ($query->execute() !== []) {
      $this->context->addViolation($constraint->message);
    }
  }

}
