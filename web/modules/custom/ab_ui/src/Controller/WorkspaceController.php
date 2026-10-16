<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Controller;

use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceRepository;
use Drupal\ab_ui\Form\MembershipManageForm;
use Drupal\ab_ui\Form\WorkspaceSelectForm;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Lists accessible workspaces and opens one by UUID without selecting it.
 */
final class WorkspaceController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly WorkspaceRepository $workspaces,
    private readonly WorkspaceContext $context,
    private readonly MembershipManager $memberships,
    private readonly MembershipScope $scope,
    private readonly FormBuilderInterface $formBuilder,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Lists workspaces the current user can read.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function listing(): array {
    $current = $this->context->workspace();
    $items = [];
    foreach ($this->workspaces->accessibleWorkspaces() as $workspace) {
      $items[] = [
        'label' => (string) $workspace->label(),
        'current' => $current instanceof Workspace && $current->uuid() === $workspace->uuid(),
        'members_url' => Url::fromRoute('ab_ui.workspace_members', [
          'uuid' => $workspace->uuid(),
        ])->toString(),
        'boards_url' => Url::fromRoute('ab_ui.boards')->toString(),
      ];
    }
    $form = $items === [] ? NULL : $this->formBuilder->getForm(WorkspaceSelectForm::class);
    return [
      '#theme' => 'ab_ui_workspace_list',
      '#current_label' => $current instanceof Workspace ? (string) $current->label() : NULL,
      '#add_url' => Url::fromRoute('ab_ui.workspace_add')->toString(),
      '#items' => $items,
      '#select_form' => $form,
      '#cache' => [
        'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
        'tags' => ['ab_membership_list:' . $this->currentUser->id()],
      ],
    ];
  }

  /**
   * Shows one accessible workspace without changing the selection.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function view(string $uuid): array {
    $workspace = $this->loadVisible($uuid);
    return [
      '#type' => 'inline_template',
      '#template' => '<p class="ab-workspace-view">{{ label }}</p><p><a href="{{ boards }}">{{ boards_label }}</a></p><p><a href="{{ members }}">{{ members_label }}</a></p><p><a href="{{ back }}">{{ back_label }}</a></p>',
      '#context' => [
        'label' => (string) $workspace->label(),
        'boards' => Url::fromRoute('ab_ui.boards')->toString(),
        'boards_label' => $this->t('Boards'),
        'members' => Url::fromRoute('ab_ui.workspace_members', [
          'uuid' => $workspace->uuid(),
        ])->toString(),
        'members_label' => $this->t('Membros'),
        'back' => Url::fromRoute('ab_ui.workspaces')->toString(),
        'back_label' => $this->t('Meus workspaces'),
      ],
      '#cache' => [
        'contexts' => ['user', 'ab_memberships'],
        'tags' => ['ab_membership_list:' . $this->currentUser->id()],
      ],
    ];
  }

  /**
   * Lists members without changing the selected workspace.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function members(string $uuid): array {
    $workspace = $this->loadVisible($uuid);
    $can_manage = $this->scope->allows(
      $this->currentUser,
      $workspace,
      WorkspacePermission::MANAGE_MEMBERS,
    );
    if ($can_manage) {
      return $this->formBuilder->getForm(MembershipManageForm::class, $workspace->uuid());
    }
    $items = [];
    foreach ($this->memberships->listMembers($workspace) as $member) {
      $items[] = $member['name'] . ' (' . $this->roleLabel($member['role']) . ')';
    }
    return [
      '#theme' => 'ab_ui_member_list',
      '#items' => $items,
      '#cache' => [
        'contexts' => ['user', 'ab_memberships'],
        'tags' => [
          'ab_membership_list:' . $this->currentUser->id(),
          'ab_workspace_members:' . $workspace->id(),
        ],
      ],
    ];
  }

  /**
   * Returns the visible label of a stored role.
   */
  private function roleLabel(string $role): string {
    return (string) match ($role) {
      'owner' => $this->t('Owner'),
      'admin' => $this->t('Admin'),
      'member' => $this->t('Membro'),
      'viewer' => $this->t('Leitor'),
      default => $role,
    };
  }

  /**
   * Loads a visible workspace or answers 404.
   */
  private function loadVisible(string $uuid): Workspace {
    try {
      $workspace = $this->workspaces->loadAccessibleByUuid($uuid);
    }
    catch (\InvalidArgumentException) {
      $workspace = NULL;
    }
    if ($workspace instanceof Workspace) {
      return $workspace;
    }
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships'])
      ->addCacheTags(['ab_membership_list:' . $this->currentUser->id()]);
    throw new CacheableNotFoundHttpException($cache);
  }

}
