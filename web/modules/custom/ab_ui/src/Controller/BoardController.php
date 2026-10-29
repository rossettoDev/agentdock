<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Controller;

use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableRedirectResponse;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
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
        'url' => Url::fromRoute('ab_ui.board_tasks', [
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
   * Sends a readable board to its task page.
   *
   * A board outside the selected workspace stays 404. The name,
   * description and archive action are on the task page.
   */
  public function view(string $uuid): CacheableRedirectResponse {
    $board = $this->loadVisible($uuid);
    $response = new CacheableRedirectResponse(Url::fromRoute('ab_ui.board_tasks', [
      'uuid' => $board->uuid(),
    ])->toString());
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships', 'ab_workspace']);
    $response->addCacheableDependency($cache);
    return $response;
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
