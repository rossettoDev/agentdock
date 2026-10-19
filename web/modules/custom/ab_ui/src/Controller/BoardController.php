<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Controller;

use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_ui\Form\BoardArchiveForm;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Lists and opens boards of the workspace selected in the session.
 */
final class BoardController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly BoardRepository $boards,
    private readonly WorkspaceContext $context,
    private readonly MembershipScope $scope,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Lists boards of the selected workspace.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function listing(): array {
    $workspace = $this->context->workspace();
    if (!$workspace instanceof Workspace) {
      return [
        '#theme' => 'ab_ui_board_list',
        '#workspace_label' => NULL,
        '#add_url' => '',
        '#items' => [],
        '#cache' => $this->cacheMeta(NULL),
      ];
    }
    $can_manage = $this->scope->allows(
      $this->currentUser,
      $workspace,
      WorkspacePermission::MANAGE_BOARDS,
    );
    $items = [];
    foreach ($this->boards->accessibleBoards($workspace) as $board) {
      $items[] = [
        'label' => (string) $board->label(),
        'url' => Url::fromRoute('ab_ui.board_view', [
          'uuid' => $board->uuid(),
        ])->toString(),
        'archived' => (int) $board->get('archived')->getString() === 1,
      ];
    }
    return [
      '#theme' => 'ab_ui_board_list',
      '#workspace_label' => (string) $workspace->label(),
      '#add_url' => $can_manage ? Url::fromRoute('ab_ui.board_add')->toString() : '',
      '#items' => $items,
      '#cache' => $this->cacheMeta($workspace),
    ];
  }

  /**
   * Shows one readable board without changing the selected workspace.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function view(string $uuid): array {
    $board = $this->loadVisible($uuid);
    $workspace = $this->workspaceOf($board);
    $can_manage = $workspace instanceof Workspace && $this->scope->allows(
      $this->currentUser,
      $workspace,
      WorkspacePermission::MANAGE_BOARDS,
    );
    $archived = (int) $board->get('archived')->getString() === 1;
    $build = [
      '#theme' => 'ab_ui_board_view',
      '#label' => (string) $board->label(),
      '#description' => $board->get('description')->getString(),
      '#archived' => $archived,
      '#back_url' => Url::fromRoute('ab_ui.boards')->toString(),
      '#tasks_url' => Url::fromRoute('ab_ui.board_tasks', [
        'uuid' => $board->uuid(),
      ])->toString(),
      '#conversation_url' => Url::fromRoute('ab_ui.board_conversation', [
        'uuid' => $board->uuid(),
      ])->toString(),
      '#archive_form' => NULL,
      '#cache' => $this->cacheMeta($workspace),
    ];
    if ($can_manage && !$archived) {
      $build['#archive_form'] = $this->formBuilder->getForm(BoardArchiveForm::class, $board->uuid());
    }
    return $build;
  }

  /**
   * Loads a readable board or answers 404.
   */
  private function loadVisible(string $uuid): Board {
    $board = $this->boards->loadAccessibleByUuid($uuid);
    if ($board instanceof Board) {
      return $board;
    }
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships', 'ab_workspace'])
      ->addCacheTags(['ab_membership_list:' . $this->currentUser->id()]);
    throw new CacheableNotFoundHttpException($cache);
  }

  /**
   * Loads the workspace stored on the board.
   */
  private function workspaceOf(Board $board): ?Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($board->get('workspace')->getString());
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

  /**
   * Cache metadata for one workspace list.
   *
   * @return array<string, mixed>
   *   Render cache metadata.
   */
  private function cacheMeta(?Workspace $workspace): array {
    $tags = ['ab_membership_list:' . $this->currentUser->id()];
    if ($workspace instanceof Workspace && $workspace->id() !== NULL) {
      $tags[] = 'ab_workspace_boards:' . $workspace->id();
    }
    return [
      'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
      'tags' => $tags,
    ];
  }

}
