<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Hook;

use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskRepository;
use Drupal\ab_communication\NotificationService;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceRepository;
use Drupal\ab_ui\Form\WorkspaceSelectForm;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

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
    private readonly TaskRepository $tasks,
    private readonly WorkspaceRepository $workspaceList,
    private readonly MembershipScope $scope,
    private readonly RouteMatchInterface $routeMatch,
    private readonly FormBuilderInterface $formBuilder,
    private readonly NotificationService $notifications,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
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
          'q' => '',
          'enabled' => '',
          'work' => '',
          'filters' => [],
          'counts' => [],
          'filter_empty' => FALSE,
          'list_url' => '',
        ],
      ],
      'ab_ui_agent_view' => [
        'variables' => [
          'label' => '',
          'description' => '',
          'capabilities' => '',
          'status' => '',
          'activity' => '',
          'limit' => '',
          'signal' => '',
          'executions' => [],
          'closed' => [],
          'grants' => [],
          'tokens' => [],
          'access_note' => '',
          'back_url' => '',
          'edit_form' => NULL,
          'grant_form' => NULL,
          'token_form' => NULL,
        ],
      ],
      'ab_ui_task_list' => [
        'variables' => [
          'board_label' => '',
          'board_description' => '',
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
          'priorities' => [],
          'agents' => [],
          'priority' => '',
          'agent' => '',
          'filter_empty' => FALSE,
          'archived' => FALSE,
          'archive_form' => NULL,
          'panel' => NULL,
        ],
      ],
      'ab_ui_task_view' => [
        'variables' => [
          'label' => '',
          'uuid' => '',
          'status' => '',
          'priority' => '',
          'owner' => '',
          'due' => '',
          'agent_assigned' => FALSE,
          'agent_name' => '',
          'board_label' => '',
          'tab' => 'descricao',
          'tabs' => [],
          'description' => [],
          'criteria' => [],
          'edit_url' => '',
          'transition_form' => NULL,
          'blocked' => FALSE,
          'dependencies' => [],
          'dependency_form' => NULL,
          'attempts' => [],
          'has_links' => FALSE,
          'review_form' => NULL,
          'questions' => [],
          'messages' => [],
          'messages_empty' => '',
          'older_url' => '',
          'message_form' => NULL,
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
      'ab_ui_dashboard' => [
        'variables' => [
          'workspace_label' => NULL,
          'cards' => [],
          'statuses' => [],
          'boards' => [],
          'reviews' => [],
          'overdue' => [],
          'requests' => [],
          'threads' => [],
          'events' => [],
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
          'context' => [],
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
   * Tells the person to sign in again without repeating the destination.
   */
  #[Hook('form_user_login_form_alter')]
  public function loginForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($form_id !== 'user_login_form') {
      return;
    }
    $form['#cache']['contexts'][] = 'url.query_args:destination';
    $destination = $this->requestStack->getMainRequest()?->query->get('destination');
    if (!is_string($destination) || $destination === '') {
      return;
    }
    $form['ab_session'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['ab-session-expired']],
      '#value' => $this->t('Sua sessão não está ativa. Entre para continuar.'),
      '#weight' => -50,
    ];
  }

  /**
   * Builds the shell variables for a logged-in page.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    $this->statusPage($variables);
    if ((int) $this->currentUser->id() <= 0) {
      return;
    }
    $workspace = $this->context->workspace();
    $tags = ['ab_membership_list:' . $this->currentUser->id()];
    if ($workspace instanceof Workspace && $workspace->id() !== NULL) {
      $tags[] = 'ab_workspace_boards:' . $workspace->id();
    }
    $variables['workspace_selector'] = $this->workspaceSelector($workspace, $tags);
    $variables['shell_account'] = $this->currentUser->getDisplayName();
    $variables['shell_account_url'] = Url::fromRoute('user.page')->toString();
    $variables['shell_nav'] = $this->shellNav();
    $variables['shell_boards'] = $this->shellBoards($workspace);
    $variables['shell_add_board'] = $this->shellAddBoard($workspace);
    $variables['shell_breadcrumb'] = $this->shellBreadcrumb();
    $variables['shell_notices'] = $this->shellNotices($workspace);
    $variables['shell_unread'] = $variables['shell_notices']['unread'];
    $tags[] = 'ab_notifications:' . $this->currentUser->id();
    $variables['#cache']['tags'] = array_merge($variables['#cache']['tags'] ?? [], $tags);
    foreach (['user', 'route', 'ab_workspace', 'ab_memberships'] as $context) {
      $variables['#cache']['contexts'][] = $context;
    }
    $variables['notifications_url'] = Url::fromRoute('ab_ui.notifications')->toString();
  }

  /**
   * Replaces the generic error page with a message that names no resource.
   */
  private function statusPage(array &$variables): void {
    $route = (string) $this->routeMatch->getRouteName();
    if ($route === 'system.403') {
      $title = $this->t('Acesso negado');
      $message = $this->t('Você não tem acesso a esta operação.');
    }
    elseif ($route === 'system.404') {
      $title = $this->t('Página não encontrada');
      $message = $this->t('A página não foi encontrada.');
    }
    else {
      return;
    }
    $variables['page']['content'] = [
      '#type' => 'inline_template',
      '#template' => '<h1>{{ title }}</h1><p>{{ message }}</p>',
      '#context' => [
        'title' => $title,
        'message' => $message,
      ],
    ];
  }

  /**
   * Links the screens that already have a route.
   *
   * @return list<array{label: string, url: string, active: bool}>
   *   Primary navigation.
   */
  private function shellNav(): array {
    $route = (string) $this->routeMatch->getRouteName();
    $tasks = $this->tasksUrl();
    $items = [
      [
        'label' => (string) $this->t('Dashboard'),
        'url' => Url::fromRoute('ab_ui.dashboard')->toString(),
        'active' => $route === 'ab_ui.dashboard',
        'icon' => 'home',
      ],
      [
        'label' => (string) $this->t('Meus boards'),
        'url' => Url::fromRoute('ab_ui.boards')->toString(),
        'active' => in_array($route, [
          'ab_ui.boards',
          'ab_ui.board_add',
          'ab_ui.board_view',
        ], TRUE),
        'icon' => 'board',
      ],
    ];
    if ($tasks !== NULL) {
      $items[] = [
        'label' => (string) $this->t('Tarefas'),
        'url' => $tasks,
        'active' => in_array($route, [
          'ab_ui.board_tasks',
          'ab_ui.board_task',
          'ab_ui.task_add',
          'ab_ui.task_view',
          'ab_ui.task_edit',
        ], TRUE),
        'icon' => 'task',
      ];
    }
    $items[] = [
      'label' => (string) $this->t('Conversas'),
      'url' => Url::fromRoute('ab_ui.conversations')->toString(),
      'active' => in_array($route, [
        'ab_ui.conversations',
        'ab_ui.conversation',
        'ab_ui.board_conversation',
        'ab_ui.task_conversation',
      ], TRUE),
      'icon' => 'message',
    ];
    $items[] = [
      'label' => (string) $this->t('Agentes'),
      'url' => Url::fromRoute('ab_ui.agents')->toString(),
      'active' => in_array($route, [
        'ab_ui.agents',
        'ab_ui.agent_add',
        'ab_ui.agent_view',
      ], TRUE),
      'icon' => 'agent',
    ];
    return $items;
  }

  /**
   * The kanban of the board in the current route, when that board is visible.
   */
  private function tasksUrl(): ?string {
    $board = $this->contextBoard();
    if (!$board instanceof Board) {
      return NULL;
    }
    return Url::fromRoute('ab_ui.board_tasks', [
      'uuid' => $board->uuid(),
    ])->toString();
  }

  /**
   * Loads the board addressed by the route when the user can read it.
   */
  private function contextBoard(): ?Board {
    $route = (string) $this->routeMatch->getRouteName();
    $uuid = (string) $this->routeMatch->getRawParameter('uuid');
    if ($uuid === '') {
      return NULL;
    }
    $boardRoutes = [
      'ab_ui.board_view',
      'ab_ui.board_tasks',
      'ab_ui.board_task',
      'ab_ui.board_conversation',
      'ab_ui.task_add',
    ];
    if (in_array($route, $boardRoutes, TRUE)) {
      return $this->boards->loadAccessibleByUuid($uuid);
    }
    $taskRoutes = [
      'ab_ui.task_view',
      'ab_ui.task_edit',
      'ab_ui.task_conversation',
    ];
    if (!in_array($route, $taskRoutes, TRUE)) {
      return NULL;
    }
    $task = $this->tasks->loadAccessibleByUuid($uuid);
    if (!$task instanceof Task) {
      return NULL;
    }
    $board = $this->entityTypeManager->getStorage('ab_board')->load($task->get('board')->getString());
    if (!$board instanceof Board) {
      return NULL;
    }
    return $this->boards->loadAccessibleByUuid($board->uuid());
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
    $current = $this->contextBoard();
    $currentUuid = $current instanceof Board ? $current->uuid() : '';
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
        'active' => $currentUuid !== '' && $currentUuid === $board->uuid(),
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

  /**
   * Puts the session selector in the header, except on its own page.
   *
   * @param \Drupal\ab_core\Entity\Workspace|null $workspace
   *   The workspace stored in the session, when one is selected.
   * @param string[] $tags
   *   Cache tags already collected for the workspace.
   *
   * @return array<string, mixed>
   *   A form, or a link when the person has no workspace.
   */
  private function workspaceSelector(?Workspace $workspace, array $tags): array {
    $cache = [
      'contexts' => ['user', 'ab_memberships', 'ab_workspace', 'route'],
      'tags' => $tags,
    ];
    $route = (string) $this->routeMatch->getRouteName();
    if ($route !== 'ab_ui.workspaces' && $this->workspaceList->accessibleWorkspaces() !== []) {
      $form = $this->formBuilder->getForm(WorkspaceSelectForm::class);
      $form['#cache'] = $cache;
      return $form;
    }
    return [
      '#type' => 'link',
      '#title' => $workspace === NULL ? $this->t('Meus workspaces') : $workspace->label(),
      '#url' => Url::fromRoute('ab_ui.workspaces'),
      '#attributes' => ['class' => ['ab-workspace-selector__link']],
      '#cache' => $cache,
    ];
  }

  /**
   * Parents of the current screen. The page title stays out of the trail.
   *
   * @return array<string, mixed>
   *   A breadcrumb render array.
   */
  private function shellBreadcrumb(): array {
    $route = (string) $this->routeMatch->getRouteName();
    $links = [];
    $board = $this->contextBoard();
    $boardRoutes = [
      'ab_ui.board_add',
      'ab_ui.board_view',
      'ab_ui.board_tasks',
      'ab_ui.board_task',
      'ab_ui.task_add',
      'ab_ui.task_view',
      'ab_ui.task_edit',
      'ab_ui.board_conversation',
    ];
    if (in_array($route, $boardRoutes, TRUE) || $board instanceof Board) {
      $links[] = Link::createFromRoute($this->t('Meus boards'), 'ab_ui.boards');
    }
    if ($board instanceof Board && !in_array($route, ['ab_ui.board_view'], TRUE)) {
      $links[] = Link::createFromRoute($board->label(), 'ab_ui.board_tasks', [
        'uuid' => $board->uuid(),
      ]);
    }
    $conversation = [
      'ab_ui.conversation',
      'ab_ui.task_conversation',
      'ab_ui.board_conversation',
    ];
    if (in_array($route, $conversation, TRUE)) {
      $links = [Link::createFromRoute($this->t('Conversas'), 'ab_ui.conversations')];
    }
    if (in_array($route, ['ab_ui.agent_add', 'ab_ui.agent_view'], TRUE)) {
      $links = [Link::createFromRoute($this->t('Agentes'), 'ab_ui.agents')];
    }
    if (in_array($route, ['ab_ui.workspace_add', 'ab_ui.workspace_view', 'ab_ui.workspace_members'], TRUE)) {
      $links = [Link::createFromRoute($this->t('Meus workspaces'), 'ab_ui.workspaces')];
    }
    if ($links === []) {
      return [];
    }
    $breadcrumb = new Breadcrumb();
    $breadcrumb->setLinks($links);
    $breadcrumb->addCacheContexts(['route', 'user', 'ab_memberships', 'ab_workspace']);
    return $breadcrumb->toRenderable();
  }

  /**
   * Unread mentions of the selected workspace only.
   *
   * @return array{unread: int, empty: string, items: list<array{label: string, read: string}>}
   *   Count and lines without the message body.
   */
  private function shellNotices(?Workspace $workspace): array {
    if (!$workspace instanceof Workspace) {
      return [
        'unread' => 0,
        'empty' => (string) $this->t('Selecione um workspace para ver as menções.'),
        'items' => [],
      ];
    }
    $items = [];
    $unread = 0;
    foreach ($this->notifications->listFor($this->currentUser) as $notification) {
      if ($notification->workspaceUuid !== $workspace->uuid()) {
        continue;
      }
      if (!$notification->read) {
        $unread++;
      }
      $items[] = [
        'label' => (string) $this->t('Menção'),
        'read' => $notification->read ? (string) $this->t('Lida') : (string) $this->t('Não lida'),
      ];
    }
    return [
      'unread' => $unread,
      'empty' => (string) $this->t('Nenhuma menção neste workspace.'),
      'items' => $items,
    ];
  }

}
