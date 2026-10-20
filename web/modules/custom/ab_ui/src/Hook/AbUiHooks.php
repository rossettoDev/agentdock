<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Hook;

use Drupal\ab_board\BoardRepository;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Database\Connection;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Theme and page variables for the workspace screens.
 */
final class AbUiHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly WorkspaceContext $context,
    private readonly AccountInterface $currentUser,
    private readonly Connection $database,
    private readonly BoardRepository $boards,
    private readonly MembershipScope $scope,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Registers the workspace list template.
   *
   * @return array<string, mixed>
   *   Theme hook definitions.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'ab_ui_workspace_list' => [
        'variables' => [
          'current_label' => NULL,
          'add_url' => '',
          'items' => [],
          'select_form' => NULL,
        ],
      ],
      'ab_ui_member_list' => [
        'variables' => [
          'items' => [],
        ],
      ],
      'ab_ui_board_list' => [
        'variables' => [
          'workspace_label' => NULL,
          'add_url' => '',
          'items' => [],
        ],
      ],
      'ab_ui_board_view' => [
        'variables' => [
          'label' => '',
          'description' => '',
          'archived' => FALSE,
          'back_url' => '',
          'tasks_url' => '',
          'conversation_url' => '',
          'archive_form' => NULL,
        ],
      ],
      'ab_ui_agent_list' => [
        'variables' => [
          'workspace_label' => NULL,
          'add_url' => '',
          'items' => [],
        ],
      ],
      'ab_ui_agent_view' => [
        'variables' => [
          'label' => '',
          'description' => '',
          'capabilities' => '',
          'status' => '',
          'limit' => '',
          'signal' => '',
          'grants' => [],
          'tokens' => [],
          'back_url' => '',
          'edit_form' => NULL,
          'grant_form' => NULL,
          'token_form' => NULL,
        ],
      ],
      'ab_ui_task_list' => [
        'variables' => [
          'board_label' => '',
          'add_url' => '',
          'columns' => [],
          'cancelled' => [],
          'cancelled_only' => FALSE,
          'cancelled_url' => '',
          'today_only' => FALSE,
          'today_url' => '',
          'today_empty' => FALSE,
          'board_url' => '',
          'empty' => TRUE,
        ],
      ],
      'ab_ui_task_view' => [
        'variables' => [
          'label' => '',
          'status' => '',
          'priority' => '',
          'owner' => '',
          'due' => '',
          'description' => [],
          'criteria' => [],
          'edit_url' => '',
          'transition_form' => NULL,
          'blocked' => FALSE,
          'dependencies' => [],
          'dependency_form' => NULL,
          'attempts' => [],
          'review_form' => NULL,
          'questions' => [],
          'conversation_url' => '',
          'back_url' => '',
        ],
      ],
      'ab_ui_notification_list' => [
        'variables' => [
          'items' => [],
          'empty' => '',
        ],
      ],
      'ab_ui_conversation_list' => [
        'variables' => [
          'workspace_label' => NULL,
          'items' => [],
          'empty' => '',
        ],
      ],
      'ab_ui_conversation' => [
        'variables' => [
          'label' => '',
          'context_url' => '',
          'context_label' => '',
          'older_url' => '',
          'messages' => [],
          'empty' => '',
          'form' => NULL,
          'back_url' => '',
        ],
      ],
      'ab_ui_design_sample' => [
        'variables' => [
          'primary' => [],
          'quiet' => [],
          'disabled' => [],
          'badges' => [],
          'tabs' => [],
          'human' => [],
          'agent' => [],
          'signal' => [],
          'skeleton' => [],
          'card' => [],
          'empty_state' => [],
          'error' => [],
        ],
      ],
    ];
  }

  /**
   * Attaches one poller for the selected workspace.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    if ((int) $this->currentUser->id() <= 0) {
      return;
    }
    $workspace = $this->context->workspace();
    if ($workspace === NULL) {
      return;
    }
    $sequence = $this->database->query(
      'SELECT sequence FROM {ab_workspace_sequence} WHERE workspace_id = :id',
      [':id' => (string) $workspace->uuid()],
    )->fetchField();
    $cursor = $sequence === FALSE ? '0' : (string) $sequence;
    $prefix = '/api/agent-board/v1/workspaces/' . $workspace->uuid();
    $attachments['#attached']['library'][] = 'ab_ui/sync';
    $attachments['#attached']['drupalSettings']['abUiSync'] = [
      'eventsUrl' => $prefix . '/events',
      'cursor' => $cursor,
      'active' => 3000,
      'hidden' => 15000,
      'max' => 60000,
    ];
    $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'meta',
        '#attributes' => [
          'name' => 'ab-sync-cursor',
          'content' => $cursor,
        ],
      ],
      'ab_sync_cursor',
    ];
    $attachments['#cache']['contexts'][] = 'user';
    $attachments['#cache']['contexts'][] = 'ab_workspace';
  }

  /**
   * Prints the status line the poller fills in.
   */
  #[Hook('page_top')]
  public function pageTop(array &$page_top): void {
    if ((int) $this->currentUser->id() <= 0 || $this->context->workspace() === NULL) {
      return;
    }
    $page_top['ab_sync_status'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => [
        'class' => ['ab-sync-status'],
        'role' => 'status',
        'hidden' => 'hidden',
      ],
      '#value' => '',
      '#cache' => [
        'contexts' => ['user', 'ab_workspace'],
      ],
    ];
  }

  /**
   * Shows the selected workspace in the header.
   *
   * The link does not carry a workspace id.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    if ((int) $this->currentUser->id() <= 0) {
      return;
    }
    $workspace = $this->context->workspace();
    $tags = ['ab_membership_list:' . $this->currentUser->id()];
    if ($workspace instanceof Workspace && $workspace->id() !== NULL) {
      $tags[] = 'ab_workspace_boards:' . $workspace->id();
    }
    $variables['workspace_selector'] = [
      '#type' => 'link',
      '#title' => $workspace === NULL ? $this->t('Meus workspaces') : $workspace->label(),
      '#url' => Url::fromRoute('ab_ui.workspaces'),
      '#attributes' => ['class' => ['ab-workspace-selector__link']],
      '#cache' => [
        'contexts' => ['user', 'ab_memberships', 'ab_workspace', 'route'],
        'tags' => $tags,
      ],
    ];
    $variables['shell_account'] = $this->currentUser->getDisplayName();
    $variables['shell_nav'] = $this->shellNav();
    $variables['shell_boards'] = $this->shellBoards($workspace);
    $variables['shell_add_board'] = $this->shellAddBoard($workspace);
  }

  /**
   * Links the screens that already have a route.
   *
   * @return list<array{label: string, url: string, active: bool}>
   *   Primary navigation.
   */
  private function shellNav(): array {
    $route = (string) $this->routeMatch->getRouteName();
    $items = [
      ['ab_ui.boards', $this->t('Meus boards'), [
        'ab_ui.boards',
        'ab_ui.board_add',
        'ab_ui.board_view',
        'ab_ui.board_tasks',
        'ab_ui.task_add',
      ]],
      ['ab_ui.conversations', $this->t('Conversas'), [
        'ab_ui.conversations',
        'ab_ui.conversation',
        'ab_ui.board_conversation',
        'ab_ui.task_conversation',
      ]],
      ['ab_ui.agents', $this->t('Agentes'), [
        'ab_ui.agents',
        'ab_ui.agent_add',
        'ab_ui.agent_view',
      ]],
      ['ab_ui.notifications', $this->t('Notificações'), [
        'ab_ui.notifications',
      ]],
    ];
    $nav = [];
    foreach ($items as $item) {
      $nav[] = [
        'label' => (string) $item[1],
        'url' => Url::fromRoute($item[0])->toString(),
        'active' => in_array($route, $item[2], TRUE),
      ];
    }
    return $nav;
  }

  /**
   * Lists the boards of the selected workspace.
   *
   * @return list<array{label: string, url: string, active: bool}>
   *   Board links. Archived boards stay on the board list.
   */
  private function shellBoards(?Workspace $workspace): array {
    if (!$workspace instanceof Workspace) {
      return [];
    }
    $current = (string) $this->routeMatch->getRawParameter('uuid');
    $route = (string) $this->routeMatch->getRouteName();
    $on_board = in_array($route, [
      'ab_ui.board_view',
      'ab_ui.board_tasks',
      'ab_ui.board_conversation',
      'ab_ui.task_add',
    ], TRUE);
    $links = [];
    foreach ($this->boards->accessibleBoards($workspace) as $board) {
      if ((int) $board->get('archived')->getString() === 1) {
        continue;
      }
      $links[] = [
        'label' => (string) $board->label(),
        'url' => Url::fromRoute('ab_ui.board_tasks', [
          'uuid' => $board->uuid(),
        ])->toString(),
        'active' => $on_board && $current === $board->uuid(),
      ];
    }
    return $links;
  }

  /**
   * Returns the create link when the member may manage boards.
   */
  private function shellAddBoard(?Workspace $workspace): string {
    if (!$workspace instanceof Workspace) {
      return '';
    }
    if (!$this->scope->allows($this->currentUser, $workspace, WorkspacePermission::MANAGE_BOARDS)) {
      return '';
    }
    return Url::fromRoute('ab_ui.board_add')->toString();
  }

}
