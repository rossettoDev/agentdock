<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Controller;

use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskDayWindow;
use Drupal\ab_board\TaskDependencyManager;
use Drupal\ab_board\TaskRepository;
use Drupal\ab_board\TaskTransitionPolicy;
use Drupal\ab_communication\InputRequestService;
use Drupal\ab_communication\MessagePage;
use Drupal\ab_communication\MessageService;
use Drupal\ab_communication\StoredMessage;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_ui\Form\BoardArchiveForm;
use Drupal\ab_ui\Form\InputAnswerForm;
use Drupal\ab_ui\Form\MessageComposeForm;
use Drupal\ab_ui\Form\TaskDependencyForm;
use Drupal\ab_ui\Form\TaskReorderForm;
use Drupal\ab_ui\Form\TaskReviewForm;
use Drupal\ab_ui\Form\TaskTransitionForm;
use Drupal\ab_ui\TaskMarkdown;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lists and opens tasks without changing the selected workspace.
 */
final class TaskController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  /**
   * Statuses rendered as columns. Cancelled stays behind its filter.
   */
  private const COLUMN_STATUSES = [
    'backlog',
    'ready',
    'in_progress',
    'in_review',
    'done',
  ];

  public function __construct(
    private readonly BoardRepository $boards,
    private readonly TaskRepository $tasks,
    private readonly TaskDependencyManager $dependencies,
    private readonly MembershipScope $scope,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TaskMarkdown $markdown,
    private readonly FormBuilderInterface $formBuilder,
    private readonly AccountInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly Connection $database,
    private readonly InputRequestService $requests,
    private readonly HumanActorContextFactory $actors,
    private readonly MessageService $messageService,
  ) {}

  /**
   * Lists one board as fixed columns, with cancelled behind a filter.
   *
   * The query only chooses that filter. It does not select a workspace.
   *
   * @param string $uuid
   *   Public board id.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request. The query only filters the columns.
   * @param string|null $task
   *   Public id of the task open beside the columns.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function listing(string $uuid, Request $request, ?string $task = NULL): array {
    $board = $this->visibleBoard($uuid);
    $workspace = $this->tasks->workspaceOf($board);
    $can_write = $this->canWrite($workspace);
    $archived = (int) $board->get('archived')->getString() === 1;
    $open = $task;
    $cancelled_only = $request->query->get('cancelled') === '1';
    $today_only = !$cancelled_only && $request->query->get('today') === '1';
    $tasks = $this->tasks->accessibleTasks($board);
    $agents = $this->agentsOnBoard($workspace, $tasks);
    $priority = $this->priorityQuery($request);
    $agent = $this->agentQuery($request, $agents);
    $columns = [];
    foreach (self::COLUMN_STATUSES as $status) {
      $columns[$status] = [
        'status' => $status,
        'label' => $this->statusLabel($status),
        'cards' => [],
      ];
    }
    $cancelled = [];
    foreach ($tasks as $task) {
      if (!$this->matchesFilters($task, $priority, $agent, $agents)) {
        continue;
      }
      $card = $this->card($workspace, $board, $task, $can_write, $agents, $open);
      $status = $task->get('status')->getString();
      if ($status === 'cancelled') {
        if (!$today_only) {
          $cancelled[] = $card;
        }
        continue;
      }
      if ($today_only && !$this->dueToday($workspace, $task)) {
        continue;
      }
      if (isset($columns[$status])) {
        $columns[$status]['cards'][] = $card;
      }
    }
    $return_to = $today_only ? 'today' : '';
    if ($cancelled_only) {
      $cancelled = $this->withReorder($cancelled, $can_write, '');
    }
    else {
      foreach ($columns as $status => $column) {
        $columns[$status]['cards'] = $this->withReorder($column['cards'], $can_write, $return_to);
      }
    }
    $visible = 0;
    foreach ($columns as $column) {
      $visible += count($column['cards']);
    }
    $shown = $cancelled_only ? count($cancelled) : $visible;
    $cache = $this->cacheMeta($workspace);
    $cache['contexts'][] = 'url.query_args:cancelled';
    $cache['contexts'][] = 'url.query_args:today';
    $cache['contexts'][] = 'url.query_args:priority';
    $cache['contexts'][] = 'url.query_args:agent';
    $cache['contexts'][] = 'url.path';
    $kept = ['priority' => $priority, 'agent' => $agent];
    $can_manage = $this->scope->allows(
      $this->currentUser,
      $workspace,
      WorkspacePermission::MANAGE_BOARDS,
    );
    return [
      '#theme' => 'ab_ui_task_list',
      '#board_label' => (string) $board->label(),
      '#board_description' => $board->get('description')->getString(),
      '#archived' => $archived,
      '#archive_form' => $can_manage && !$archived
        ? $this->formBuilder->getForm(BoardArchiveForm::class, $board->uuid())
        : NULL,
      '#add_url' => $can_write && !$archived ? Url::fromRoute('ab_ui.task_add', [
        'uuid' => $board->uuid(),
      ])->toString() : '',
      '#panel' => $this->panel($board, $workspace, $open, $request, $can_write),
      '#columns' => $cancelled_only ? [] : $columns,
      '#cancelled' => $cancelled_only ? $cancelled : [],
      '#cancelled_only' => $cancelled_only,
      '#cancelled_url' => $this->boardUrl($board, ['cancelled' => '1'] + $kept),
      '#today_only' => $today_only,
      '#today_url' => $this->boardUrl($board, ['today' => '1'] + $kept),
      '#board_url' => $this->boardUrl($board, []),
      '#empty' => $tasks === [],
      '#today_empty' => $today_only && $visible === 0,
      '#priorities' => $this->priorityLinks($board, $priority, $today_only, $cancelled_only, $agent),
      '#agents' => $this->agentLinks($board, $agents, $agent, $today_only, $cancelled_only, $priority),
      '#priority' => $priority,
      '#agent' => $agent,
      '#filter_empty' => ($priority !== '' || $agent !== '') && !$today_only && $shown === 0 && $tasks !== [],
      '#attached' => [
        'library' => ['ab_ui/kanban', 'core/drupal.dialog.ajax'],
        'drupalSettings' => [
          'abUiSync' => [
            'snapshotUrl' => '/api/agent-board/v1/workspaces/' . $workspace->uuid() . '/boards/' . $board->uuid() . '/snapshot',
          ],
        ],
      ],
      '#cache' => $cache,
    ];
  }

  /**
   * Shows one readable task without changing the selected workspace.
   *
   * @param string $uuid
   *   Public task id.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request. The tab query selects one section.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function view(string $uuid, Request $request): array {
    $task = $this->visibleTask($uuid);
    $workspace = $this->workspaceOf($task);
    $can_write = $workspace instanceof Workspace && $this->canWrite($workspace);
    $targets = (new TaskTransitionPolicy())->targets($task->get('status')->getString());
    $board_id = $task->get('board')->getString();
    $board = $this->entityTypeManager->getStorage('ab_board')->load($board_id);
    $back = $board instanceof Board
      ? Url::fromRoute('ab_ui.board_tasks', ['uuid' => $board->uuid()])->toString()
      : Url::fromRoute('ab_ui.boards')->toString();
    $due = '';
    $assigned = FALSE;
    $agent_name = '';
    $attempts = [];
    $questions = [];
    $thread = [
      'messages' => [],
      'empty' => (string) $this->t('Nenhuma mensagem nesta conversa.'),
      'older' => '',
    ];
    if ($workspace instanceof Workspace) {
      $due = $this->dueLabel($workspace, $task->get('due_at')->getString());
      $agent = $this->assignedAgent($workspace, $task);
      $assigned = $agent['assigned'];
      $agent_name = $agent['name'];
      $attempts = $this->attempts($task, $workspace);
      $questions = $this->questions($task, $workspace, $can_write);
      $thread = $this->thread($workspace, $task, $request);
    }
    $has_links = FALSE;
    foreach ($attempts as $attempt) {
      if ($attempt['links'] !== []) {
        $has_links = TRUE;
      }
    }
    $tab = $this->taskTab($request);
    $build = [
      '#theme' => 'ab_ui_task_view',
      '#label' => (string) $task->label(),
      '#uuid' => (string) $task->uuid(),
      '#status' => $this->statusLabel($task->get('status')->getString()),
      '#priority' => $this->priorityLabel($task->get('priority')->getString()),
      '#owner' => $this->ownerName($task),
      '#due' => $due,
      '#agent_assigned' => $assigned,
      '#agent_name' => $agent_name,
      '#board_label' => $board instanceof Board ? (string) $board->label() : '',
      '#tab' => $tab,
      '#tabs' => $this->tabLinks($task, $tab),
      '#description' => $this->markup($task->get('description')->getString()),
      '#criteria' => $this->markup($task->get('acceptance_criteria')->getString()),
      '#edit_url' => $can_write ? Url::fromRoute('ab_ui.task_edit', [
        'uuid' => $task->uuid(),
      ])->toString() : '',
      '#transition_form' => NULL,
      '#blocked' => $this->dependencies->isBlocked($task),
      '#dependencies' => $this->dependencyItems($task),
      '#dependency_form' => NULL,
      '#attempts' => $attempts,
      '#has_links' => $has_links,
      '#review_form' => NULL,
      '#questions' => $questions,
      '#messages' => $thread['messages'],
      '#messages_empty' => $thread['empty'],
      '#older_url' => $thread['older'],
      '#message_form' => NULL,
      '#conversation_url' => Url::fromRoute('ab_ui.task_conversation', [
        'uuid' => $task->uuid(),
      ])->toString(),
      '#back_url' => $back,
      '#cache' => $this->cacheMeta($workspace),
    ];
    $build['#cache']['contexts'][] = 'url.query_args:tab';
    $build['#cache']['contexts'][] = 'url.query_args:before';
    if ($workspace instanceof Workspace && $workspace->id() !== NULL) {
      $build['#cache']['tags'][] = 'ab_workspace_messages:' . $workspace->id();
    }
    if ($can_write) {
      $build['#message_form'] = $this->formBuilder->getForm(
        MessageComposeForm::class,
        'task',
        (string) $task->uuid(),
        '',
        'task',
      );
    }
    if ($can_write && $targets !== []) {
      $build['#transition_form'] = $this->formBuilder->getForm(
        TaskTransitionForm::class,
        $task->uuid(),
      );
    }
    if ($can_write) {
      $build['#dependency_form'] = $this->formBuilder->getForm(
        TaskDependencyForm::class,
        $task->uuid(),
      );
    }
    $status = $task->get('status')->getString();
    $can_review = $workspace instanceof Workspace && $this->scope->allows(
      $this->currentUser,
      $workspace,
      WorkspacePermission::REVIEW,
    );
    $needs_review = $status === 'in_review' && $can_review;
    $needs_cancel = in_array($status, ['in_progress', 'in_review'], TRUE) && $can_write;
    if ($needs_review || $needs_cancel) {
      $build['#review_form'] = $this->formBuilder->getForm(
        TaskReviewForm::class,
        $task->uuid(),
      );
    }
    return $build;
  }

  /**
   * Lists attempts, notes and result links for one visible task.
   *
   * A row from another task or workspace is skipped. Notes stay text.
   *
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task already loaded for this screen.
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the task.
   *
   * @return array<int, array<string, mixed>>
   *   One item per stored execution.
   */
  private function attempts(Task $task, Workspace $workspace): array {
    $rows = $this->database->select('ab_execution', 'e')
      ->fields('e', [
        'id',
        'uuid',
        'attempt',
        'status',
        'result_summary',
        'failure_code',
        'heartbeat_at',
        'lease_expires_at',
        'task',
        'workspace',
      ])
      ->condition('task', $task->id())
      ->orderBy('attempt')
      ->execute()
      ->fetchAll();
    $ids = [];
    foreach ($rows as $row) {
      if (!$this->sameExecution($row, $task, $workspace)) {
        continue;
      }
      $ids[] = (int) $row->id;
    }
    $notes = [];
    $links = [];
    if ($ids !== []) {
      $note_rows = $this->database->select('ab_execution_progress', 'p')
        ->fields('p', ['execution_id', 'body'])
        ->condition('execution_id', $ids, 'IN')
        ->orderBy('sequence')
        ->execute();
      foreach ($note_rows as $note) {
        $notes[(int) $note->execution_id][] = (string) $note->body;
      }
      $link_rows = $this->database->select('ab_execution_link', 'l')
        ->fields('l', ['execution_id', 'url', 'label'])
        ->condition('execution_id', $ids, 'IN')
        ->orderBy('delta')
        ->execute();
      foreach ($link_rows as $link) {
        $url = (string) $link->url;
        if (preg_match('#^https?://[^\s<>]+$#i', $url) !== 1) {
          continue;
        }
        $label = (string) $link->label;
        $links[(int) $link->execution_id][] = [
          'url' => $url,
          'label' => $label === '' ? $url : $label,
        ];
      }
    }
    $expired = $this->expiredExecutionIds($task, $workspace);
    $items = [];
    foreach ($rows as $row) {
      if (!$this->sameExecution($row, $task, $workspace)) {
        continue;
      }
      $id = (int) $row->id;
      $status = (string) $row->status;
      $items[] = [
        'number' => (string) $row->attempt,
        'status' => $this->executionLabel($status),
        'summary' => $this->markup((string) $row->result_summary),
        'failure' => (string) $row->failure_code,
        'notice' => $this->executionNotice($row, $expired),
        'signal' => $this->executionSignal($workspace, $row),
        'notes' => $notes[$id] ?? [],
        'links' => $links[$id] ?? [],
      ];
    }
    return $items;
  }

  /**
   * Reports whether the execution row belongs to this task.
   *
   * @param object $row
   *   Execution row from storage.
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task open on the screen.
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the task.
   */
  private function sameExecution(object $row, Task $task, Workspace $workspace): bool {
    $same_task = (string) $row->task === (string) $task->id();
    $same_workspace = (string) $row->workspace === (string) $workspace->id();
    return $same_task && $same_workspace;
  }

  /**
   * Returns the sentence for a failed or expired execution.
   *
   * @param object $row
   *   Execution row from storage.
   * @param array<string, true> $expired
   *   Execution uuids whose interrupt reason is expiry.
   */
  private function executionNotice(object $row, array $expired): string {
    $status = (string) $row->status;
    if ($status === 'failed') {
      return (string) $this->t('A execução falhou.');
    }
    $uuid = (string) $row->uuid;
    if ($this->leaseLapsed($row) || isset($expired[$uuid])) {
      return (string) $this->t('A execução expirou.');
    }
    return '';
  }

  /**
   * Returns the stored signal, without calling the agent online.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the execution.
   * @param object $row
   *   Execution row from storage.
   */
  private function executionSignal(Workspace $workspace, object $row): string {
    if ($this->leaseLapsed($row)) {
      return '';
    }
    $heartbeat = (int) $row->heartbeat_at;
    if ($heartbeat > 0) {
      $when = $this->clockLabel($workspace, $heartbeat);
      return $when === '' ? '' : (string) $this->t('Último sinal: @time', [
        '@time' => $when,
      ]);
    }
    $live = in_array((string) $row->status, ['running', 'waiting_input'], TRUE);
    return $live ? (string) $this->t('Execução em andamento.') : '';
  }

  /**
   * Reports a live lease whose stored expiry is already past.
   *
   * @param object $row
   *   Execution row from storage.
   */
  private function leaseLapsed(object $row): bool {
    $live = in_array((string) $row->status, ['running', 'waiting_input'], TRUE);
    $expires = (int) $row->lease_expires_at;
    return $live && $expires > 0 && $expires <= $this->time->getRequestTime();
  }

  /**
   * Collects execution uuids interrupted because the lease expired.
   *
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task open on the screen.
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the task.
   *
   * @return array<string, true>
   *   Execution uuids keyed for lookup.
   */
  private function expiredExecutionIds(Task $task, Workspace $workspace): array {
    $rows = $this->database->select('ab_event', 'e')
      ->fields('e', ['payload'])
      ->condition('workspace_id', (string) $workspace->uuid())
      ->condition('type', 'execution.interrupted')
      ->condition('resource_id', (string) $task->uuid())
      ->execute();
    $ids = [];
    foreach ($rows as $row) {
      $payload = $row->payload;
      if (is_string($payload)) {
        $payload = json_decode($payload, TRUE);
      }
      if (!is_array($payload) || ($payload['reason'] ?? '') !== 'expired') {
        continue;
      }
      $uuid = $payload['execution_id'] ?? '';
      if (is_string($uuid) && $uuid !== '') {
        $ids[$uuid] = TRUE;
      }
    }
    return $ids;
  }

  /**
   * Returns the Portuguese label of an execution status.
   */
  private function executionLabel(string $status): string {
    return match ($status) {
      'running' => (string) $this->t('Em andamento'),
      'waiting_input' => (string) $this->t('Aguardando'),
      'submitted' => (string) $this->t('Enviada'),
      'failed' => (string) $this->t('Falhou'),
      'interrupted' => (string) $this->t('Interrompida'),
      'cancelled' => (string) $this->t('Cancelada'),
      default => (string) $this->t('Em andamento'),
    };
  }

  /**
   * Lists predecessor titles for the task detail.
   *
   * @return array<int, array<string, string>>
   *   Label and status of each predecessor.
   */
  private function dependencyItems(Task $task): array {
    $items = [];
    foreach ($this->dependencies->predecessors($task) as $predecessor) {
      $items[] = [
        'label' => (string) $predecessor->label(),
        'status' => $this->statusLabel($predecessor->get('status')->getString()),
        'url' => Url::fromRoute('ab_ui.task_view', [
          'uuid' => $predecessor->uuid(),
        ])->toString(),
      ];
    }
    return $items;
  }

  /**
   * Lists questions and the human answer form when writing is allowed.
   *
   * @return array<int, array<string, mixed>>
   *   Status, text and the optional answer form.
   */
  private function questions(Task $task, Workspace $workspace, bool $canWrite): array {
    $items = [];
    foreach ($this->requests->forTask($this->actors->create($workspace), $task) as $request) {
      $open = $request->status === 'open';
      $items[] = [
        'status' => $open ? (string) $this->t('Pendente') : (string) $this->t('Respondida'),
        'question' => $this->markup($request->question),
        'answer' => $request->answer === NULL ? [] : $this->markup($request->answer),
        'author' => $request->answer === NULL ? '' : (string) $this->t('Resposta de @name', [
          '@name' => $this->participant($workspace, $request->authorType, $request->authorId),
        ]),
        'form' => $open && $canWrite ? $this->formBuilder->getForm(InputAnswerForm::class, $request->uuid) : NULL,
      ];
    }
    return $items;
  }

  /**
   * Returns a display name for the person who answered.
   */
  private function participant(Workspace $workspace, ?string $type, ?string $id): string {
    if ($type === ActorContext::TYPE_AGENT && is_string($id) && $id !== '') {
      $ids = $this->entityTypeManager->getStorage('ab_agent')->getQuery()
        ->accessCheck(FALSE)
        ->condition('uuid', $id)
        ->condition('workspace', $workspace->id())
        ->execute();
      $agent = $this->entityTypeManager->getStorage('ab_agent')->load(reset($ids) ?: 0);
      return $agent instanceof Agent ? (string) $agent->label() : (string) $this->t('Participante');
    }
    if ($type === ActorContext::TYPE_HUMAN && is_string($id) && $id !== '') {
      $user = $this->entityTypeManager->getStorage('user')->load($id);
      return $user instanceof UserInterface ? $user->getAccountName() : (string) $this->t('Participante');
    }
    return (string) $this->t('Participante');
  }

  /**
   * Builds sanitized markup, or an empty array when there is no text.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function markup(string $source): array {
    $html = $this->markdown->render($source);
    if ($html === '') {
      return [];
    }
    return ['#markup' => $html];
  }

  /**
   * Loads a readable board or answers 404.
   */
  private function visibleBoard(string $uuid): Board {
    $board = $this->boards->loadAccessibleByUuid($uuid);
    if ($board instanceof Board) {
      return $board;
    }
    throw new CacheableNotFoundHttpException($this->notFoundCache());
  }

  /**
   * Loads a readable task or answers 404.
   */
  private function visibleTask(string $uuid): Task {
    $task = $this->tasks->loadAccessibleByUuid($uuid);
    if ($task instanceof Task) {
      return $task;
    }
    throw new CacheableNotFoundHttpException($this->notFoundCache());
  }

  /**
   * Adds the reorder form when the column has another card.
   *
   * @param array<int, array<string, mixed>> $cards
   *   Cards already ordered for one column.
   * @param bool $can_write
   *   Whether the account can edit tasks.
   * @param string $return_to
   *   Board filter to reopen after the move. Empty returns to the board.
   *
   * @return array<int, array<string, mixed>>
   *   The same cards, with a form on each when reordering is possible.
   */
  private function withReorder(array $cards, bool $can_write, string $return_to): array {
    if (!$can_write || count($cards) < 2) {
      return $cards;
    }
    $count = count($cards);
    foreach ($cards as $index => $card) {
      $before_up = $index > 0 ? (string) $cards[$index - 1]['uuid'] : '';
      $can_down = $index < $count - 1;
      $before_down = '';
      if ($can_down && isset($cards[$index + 2]['uuid'])) {
        $before_down = (string) $cards[$index + 2]['uuid'];
      }
      $cards[$index]['reorder_form'] = $this->formBuilder->getForm(
        TaskReorderForm::class,
        (string) $card['uuid'],
        $before_up,
        $before_down,
        $can_down ? '1' : '0',
        $return_to,
      );
    }
    return $cards;
  }

  /**
   * Reports whether the task belongs on the Hoje filter.
   */
  private function dueToday(Workspace $workspace, Task $task): bool {
    $raw = $task->get('due_at')->getString();
    $due = $raw === '' ? NULL : (int) $raw;
    return (new TaskDayWindow())->includes(
      $due,
      $task->get('status')->getString(),
      $workspace->get('timezone')->getString(),
      $this->time->getRequestTime(),
    );
  }

  /**
   * Keeps a priority query only when it is one of the stored values.
   */
  private function priorityQuery(Request $request): string {
    $priority = $request->query->get('priority');
    $allowed = ['low', 'normal', 'high', 'urgent'];
    if (!is_string($priority) || !in_array($priority, $allowed, TRUE)) {
      return '';
    }
    return $priority;
  }

  /**
   * Keeps an agent query only when that agent is on this board.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current board request.
   * @param array<string, array{id: string, label: string}> $agents
   *   Agents assigned to accessible tasks, keyed by uuid.
   */
  private function agentQuery(Request $request, array $agents): string {
    $agent = $request->query->get('agent');
    if (!is_string($agent) || !isset($agents[$agent])) {
      return '';
    }
    return $agent;
  }

  /**
   * Reports whether the task matches the priority and agent filters.
   *
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task already loaded for this board.
   * @param string $priority
   *   Selected priority, or an empty string.
   * @param string $agent
   *   Selected agent uuid, or an empty string.
   * @param array<string, array{id: string, label: string}> $agents
   *   Agents assigned to accessible tasks, keyed by uuid.
   */
  private function matchesFilters(Task $task, string $priority, string $agent, array $agents): bool {
    if ($priority !== '' && $task->get('priority')->getString() !== $priority) {
      return FALSE;
    }
    if ($agent === '') {
      return TRUE;
    }
    $id = $task->get('assigned_agent')->getString();
    return isset($agents[$agent]) && $agents[$agent]['id'] === $id;
  }

  /**
   * Lists agents assigned to accessible tasks of this workspace.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the board.
   * @param \Drupal\ab_board\Entity\Task[] $tasks
   *   Tasks the account can already read.
   *
   * @return array<string, array{id: string, label: string}>
   *   Label and id, keyed by the agent uuid.
   */
  private function agentsOnBoard(Workspace $workspace, array $tasks): array {
    $storage = $this->entityTypeManager->getStorage('ab_agent');
    $found = [];
    foreach ($tasks as $task) {
      $id = $task->get('assigned_agent')->getString();
      if ($id === '' || $id === '0') {
        continue;
      }
      $agent = $storage->load($id);
      if (!$agent instanceof Agent) {
        continue;
      }
      if ((string) $agent->get('workspace')->getString() !== (string) $workspace->id()) {
        continue;
      }
      $found[$agent->uuid()] = [
        'id' => (string) $agent->id(),
        'label' => (string) $agent->label(),
      ];
    }
    return $found;
  }

  /**
   * Builds the board URL with the filters that should stay.
   *
   * @param \Drupal\ab_board\Entity\Board $board
   *   Board whose screen is open.
   * @param array<string, string> $query
   *   Query arguments. Empty values are omitted.
   */
  private function boardUrl(Board $board, array $query): string {
    $kept = [];
    foreach ($query as $key => $value) {
      if ($value !== '') {
        $kept[$key] = $value;
      }
    }
    return Url::fromRoute('ab_ui.board_tasks', [
      'uuid' => $board->uuid(),
    ], ['query' => $kept])->toString();
  }

  /**
   * Priority links that keep the other active filters.
   *
   * @param \Drupal\ab_board\Entity\Board $board
   *   Board whose screen is open.
   * @param string $current
   *   Selected priority, or an empty string.
   * @param bool $today
   *   Whether the Hoje filter is active.
   * @param bool $cancelled
   *   Whether the cancelled filter is active.
   * @param string $agent
   *   Selected agent uuid, or an empty string.
   *
   * @return list<array{label: string, url: string, active: bool}>
   *   One link per stored priority.
   */
  private function priorityLinks(Board $board, string $current, bool $today, bool $cancelled, string $agent): array {
    $links = [];
    foreach (['low', 'normal', 'high', 'urgent'] as $priority) {
      $links[] = [
        'label' => $this->priorityLabel($priority),
        'url' => $this->boardUrl($board, [
          'today' => $today ? '1' : '',
          'cancelled' => $cancelled ? '1' : '',
          'priority' => $priority,
          'agent' => $agent,
        ]),
        'active' => $current === $priority,
      ];
    }
    return $links;
  }

  /**
   * Agent links limited to agents already visible on this board.
   *
   * @param \Drupal\ab_board\Entity\Board $board
   *   Board whose screen is open.
   * @param array<string, array{id: string, label: string}> $agents
   *   Agents assigned to accessible tasks, keyed by uuid.
   * @param string $current
   *   Selected agent uuid, or an empty string.
   * @param bool $today
   *   Whether the Hoje filter is active.
   * @param bool $cancelled
   *   Whether the cancelled filter is active.
   * @param string $priority
   *   Selected priority, or an empty string.
   *
   * @return list<array{label: string, url: string, active: bool}>
   *   One link per visible agent.
   */
  private function agentLinks(Board $board, array $agents, string $current, bool $today, bool $cancelled, string $priority): array {
    $links = [];
    foreach ($agents as $uuid => $agent) {
      $links[] = [
        'label' => $agent['label'],
        'url' => $this->boardUrl($board, [
          'today' => $today ? '1' : '',
          'cancelled' => $cancelled ? '1' : '',
          'priority' => $priority,
          'agent' => $uuid,
        ]),
        'active' => $current === $uuid,
      ];
    }
    return $links;
  }

  /**
   * Builds one card from the stored task.
   *
   * The agent name is shown only when that agent belongs to the workspace.
   * An unresolved id stays as "assigned" and is never printed.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the task.
   * @param \Drupal\ab_board\Entity\Board $board
   *   Board that contains the task.
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task already loaded for this board.
   * @param bool $can_write
   *   Whether the account can edit tasks.
   * @param array<string, array{id: string, label: string}> $agents
   *   Agents assigned to accessible tasks, keyed by uuid.
   * @param string|null $open
   *   Public id of the task selected on this page.
   *
   * @return array<string, mixed>
   *   Title, link, priority, due date, owner, blocked flag and move form.
   */
  private function card(
    Workspace $workspace,
    Board $board,
    Task $task,
    bool $can_write,
    array $agents,
    ?string $open,
  ): array {
    $agent_id = $task->get('assigned_agent')->getString();
    $agent_name = '';
    foreach ($agents as $agent) {
      if ($agent['id'] === $agent_id) {
        $agent_name = $agent['label'];
      }
    }
    $assigned = $agent_id !== '' && $agent_id !== '0';
    $status = $task->get('status')->getString();
    $targets = (new TaskTransitionPolicy())->targets($status);
    $move_form = [];
    if ($can_write && $targets !== []) {
      $move_form = $this->formBuilder->getForm(TaskTransitionForm::class, $task->uuid(), 'board');
    }
    $priority = $task->get('priority')->getString();
    $execution = $this->executionLine($workspace, $task);
    return [
      'label' => (string) $task->label(),
      'uuid' => $task->uuid(),
      'url' => Url::fromRoute('ab_ui.board_task', [
        'uuid' => $board->uuid(),
        'task' => $task->uuid(),
      ])->toString(),
      'current' => $open !== NULL && $open === (string) $task->uuid(),
      'priority' => $this->priorityLabel($priority),
      'priority_key' => $priority,
      'due' => $this->dueLabel($workspace, $task->get('due_at')->getString()),
      'owner' => $this->ownerName($task),
      'blocked' => $this->dependencies->isBlocked($task),
      'waiting' => $this->waitingForAnswer($workspace, $task),
      'agent' => $assigned,
      'agent_name' => $agent_name,
      'attempt' => $execution['attempt'],
      'execution' => $execution['execution'],
      'targets' => $can_write ? $targets : [],
      'move_form' => $move_form,
    ];
  }

  /**
   * Reads the active execution when it belongs to this task.
   *
   * @return array{attempt: string, execution: string}
   *   Attempt number and status label, or empty strings.
   */
  private function executionLine(Workspace $workspace, Task $task): array {
    $empty = ['attempt' => '', 'execution' => ''];
    $id = $task->get('active_execution')->getString();
    if ($id === '' || $id === '0') {
      return $empty;
    }
    $row = $this->database->select('ab_execution', 'e')
      ->fields('e', ['attempt', 'status', 'task', 'workspace'])
      ->condition('id', (int) $id)
      ->execute()
      ->fetchObject();
    if (!is_object($row)) {
      return $empty;
    }
    $same_task = (string) $row->task === (string) $task->id();
    $same_workspace = (string) $row->workspace === (string) $workspace->id();
    if (!$same_task || !$same_workspace) {
      return $empty;
    }
    return [
      'attempt' => (string) $row->attempt,
      'execution' => $this->executionLabel((string) $row->status),
    ];
  }

  /**
   * Reports an open question. The question text stays off the card.
   */
  private function waitingForAnswer(Workspace $workspace, Task $task): bool {
    foreach ($this->requests->forTask($this->actors->create($workspace), $task) as $request) {
      if ($request->status === 'open') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Loads the workspace stored on the task.
   */
  private function workspaceOf(Task $task): ?Workspace {
    $storage = $this->entityTypeManager->getStorage('ab_workspace');
    $workspace = $storage->load($task->get('workspace')->getString());
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

  /**
   * Reports whether the account can edit tasks in the workspace.
   */
  private function canWrite(Workspace $workspace): bool {
    return $this->scope->allows($this->currentUser, $workspace, WorkspacePermission::WRITE);
  }

  /**
   * Returns the Portuguese label of a priority.
   */
  private function priorityLabel(string $priority): string {
    return match ($priority) {
      'low' => (string) $this->t('Baixa'),
      'high' => (string) $this->t('Alta'),
      'urgent' => (string) $this->t('Urgente'),
      default => (string) $this->t('Normal'),
    };
  }

  /**
   * Returns the tab named in the query, or the description tab.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  private function taskTab(Request $request): string {
    $tab = $request->query->get('tab');
    $allowed = ['descricao', 'conversas', 'atividade', 'links'];
    if (is_string($tab) && in_array($tab, $allowed, TRUE)) {
      return $tab;
    }
    return 'descricao';
  }

  /**
   * Builds the four section links. Unknown sections are not offered.
   *
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task open on the screen.
   * @param string $current
   *   Selected tab.
   *
   * @return list<array{label: string, url: string, current: bool}>
   *   One link per honest section.
   */
  private function tabLinks(Task $task, string $current): array {
    $labels = [
      'descricao' => (string) $this->t('Descrição'),
      'conversas' => (string) $this->t('Conversas'),
      'atividade' => (string) $this->t('Execuções'),
      'links' => (string) $this->t('Links de entrega'),
    ];
    $links = [];
    foreach ($labels as $tab => $label) {
      $options = ['fragment' => 'ab-task-' . $tab];
      if ($tab !== 'descricao') {
        $options['query'] = ['tab' => $tab];
      }
      $links[] = [
        'label' => $label,
        'url' => Url::fromRoute('ab_ui.task_view', [
          'uuid' => $task->uuid(),
        ], $options)->toString(),
        'current' => $current === $tab,
      ];
    }
    return $links;
  }

  /**
   * Returns the assigned agent when it belongs to this workspace.
   *
   * An unresolved id stays assigned and the number is not returned.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the task.
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task open on the screen.
   *
   * @return array{assigned: bool, name: string}
   *   Whether an agent is stored, and the name when it resolves.
   */
  private function assignedAgent(Workspace $workspace, Task $task): array {
    $id = $task->get('assigned_agent')->getString();
    if ($id === '' || $id === '0') {
      return ['assigned' => FALSE, 'name' => ''];
    }
    $agent = $this->entityTypeManager->getStorage('ab_agent')->load($id);
    if (!$agent instanceof Agent) {
      return ['assigned' => TRUE, 'name' => ''];
    }
    $same = (string) $agent->get('workspace')->getString();
    if ($same !== (string) $workspace->id()) {
      return ['assigned' => TRUE, 'name' => ''];
    }
    return ['assigned' => TRUE, 'name' => (string) $agent->label()];
  }

  /**
   * Reads the task thread through the message service.
   *
   * Opening the thread does not create it. A miss stays empty.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the task.
   * @param \Drupal\ab_board\Entity\Task $task
   *   Task open on the screen.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request. The before query pages older messages.
   *
   * @return array<string, mixed>
   *   Lines, the empty sentence and the older-page URL.
   */
  private function thread(Workspace $workspace, Task $task, Request $request): array {
    $empty = (string) $this->t('Nenhuma mensagem nesta conversa.');
    $result = ['messages' => [], 'empty' => $empty, 'older' => ''];
    $raw = $request->query->get('before');
    $cursor = NULL;
    if (is_string($raw) && preg_match('/^\d+\.\d+$/', $raw) === 1) {
      $cursor = $raw;
    }
    try {
      $actor = $this->actors->create($workspace);
      $thread = $this->messageService->findThreadUuid($actor, 'task', (string) $task->uuid());
      $page = $thread === NULL
        ? new MessagePage([], NULL)
        : $this->messageService->read($actor, $thread, $cursor, MessageService::PAGE_DEFAULT);
    }
    catch (ScopedResourceNotFoundException | ScopedOperationDeniedException) {
      return $result;
    }
    $older = '';
    if ($page->cursor !== NULL) {
      $older = Url::fromRoute('ab_ui.task_view', ['uuid' => $task->uuid()], [
        'query' => [
          'tab' => 'conversas',
          'before' => $page->cursor,
        ],
        'fragment' => 'ab-task-conversas',
      ])->toString();
    }
    return [
      'messages' => $this->messageLines($workspace, $page),
      'empty' => $empty,
      'older' => $older,
    ];
  }

  /**
   * Prepares sanitized lines for the task thread.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the messages.
   * @param \Drupal\ab_communication\MessagePage $page
   *   One page already authorized for this actor.
   *
   * @return array<int, array<string, mixed>>
   *   Author, kind, time and body for each message.
   */
  private function messageLines(Workspace $workspace, MessagePage $page): array {
    $lines = [];
    foreach ($page->messages as $message) {
      $html = $this->markdown->render($message->body);
      $lines[] = [
        'author' => $this->messageAuthor($workspace, $message),
        'kind' => $this->messageKind($message->kind),
        'time' => $this->clockLabel($workspace, $message->created),
        'body' => $html === '' ? [] : ['#markup' => $html],
        'reply' => $message->replyTo !== NULL,
      ];
    }
    return $lines;
  }

  /**
   * Returns a display name for the message author in this workspace.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the message.
   * @param \Drupal\ab_communication\StoredMessage $message
   *   Stored message already read for this actor.
   */
  private function messageAuthor(Workspace $workspace, StoredMessage $message): string {
    if ($message->actorType === ActorContext::TYPE_SYSTEM) {
      return (string) $this->t('Sistema');
    }
    if ($message->actorType === ActorContext::TYPE_AGENT) {
      $ids = $this->entityTypeManager->getStorage('ab_agent')->getQuery()
        ->accessCheck(FALSE)
        ->condition('uuid', $message->actorId)
        ->condition('workspace', $workspace->id())
        ->execute();
      $agent = $this->entityTypeManager->getStorage('ab_agent')->load(reset($ids) ?: 0);
      return $agent instanceof Agent ? (string) $agent->label() : (string) $this->t('Participante');
    }
    $user = $this->entityTypeManager->getStorage('user')->load($message->actorId);
    return $user instanceof UserInterface ? $user->getAccountName() : (string) $this->t('Participante');
  }

  /**
   * Returns the Portuguese label of a message kind.
   *
   * @param string $kind
   *   Stored message kind.
   */
  private function messageKind(string $kind): string {
    return match ($kind) {
      'answer' => (string) $this->t('Resposta'),
      'progress' => (string) $this->t('Progresso'),
      'question' => (string) $this->t('Pergunta'),
      'result' => (string) $this->t('Resultado'),
      'system' => (string) $this->t('Sistema'),
      default => (string) $this->t('Comentário'),
    };
  }

  /**
   * Formats a timestamp in the workspace timezone.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace whose timezone is used.
   * @param int $timestamp
   *   UTC unix time. Zero stays blank.
   */
  private function clockLabel(Workspace $workspace, int $timestamp): string {
    if ($timestamp <= 0) {
      return '';
    }
    $timezone = $workspace->get('timezone')->getString();
    try {
      $zone = new \DateTimeZone($timezone === '' ? 'America/Sao_Paulo' : $timezone);
    }
    catch (\Exception) {
      $zone = new \DateTimeZone('America/Sao_Paulo');
    }
    $when = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone);
    return $when->format('d/m/Y H:i');
  }

  /**
   * Returns the Portuguese label of a status.
   */
  private function statusLabel(string $status): string {
    return match ($status) {
      'ready' => (string) $this->t('Pronta'),
      'in_progress' => (string) $this->t('Em andamento'),
      'in_review' => (string) $this->t('Em revisão'),
      'done' => (string) $this->t('Concluída'),
      'cancelled' => (string) $this->t('Cancelada'),
      default => (string) $this->t('Backlog'),
    };
  }

  /**
   * Returns the responsible account name.
   */
  private function ownerName(Task $task): string {
    $id = $task->get('human_owner')->getString();
    if ($id === '') {
      return '';
    }
    $user = $this->entityTypeManager->getStorage('user')->load($id);
    return $user instanceof UserInterface ? $user->getAccountName() : '';
  }

  /**
   * Formats a due timestamp in the workspace timezone.
   */
  private function dueLabel(Workspace $workspace, string $raw): string {
    if ($raw === '') {
      return '';
    }
    try {
      $zone = new \DateTimeZone($workspace->get('timezone')->getString());
    }
    catch (\Exception) {
      return '';
    }
    $date = new \DateTimeImmutable('@' . $raw);
    return $date->setTimezone($zone)->format('d/m/Y');
  }

  /**
   * Builds the task open beside the columns.
   *
   * A task from another board answers 404. The compose form stays
   * outside the region the snapshot replaces.
   *
   * @param \Drupal\ab_board\Entity\Board $board
   *   Board of this screen.
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the board.
   * @param string|null $taskUuid
   *   Public id of the selected task, or NULL when none is open.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param bool $canWrite
   *   Whether the account can comment.
   *
   * @return array<string, mixed>|null
   *   Title, sanitized text, messages and the compose form.
   */
  private function panel(
    Board $board,
    Workspace $workspace,
    ?string $taskUuid,
    Request $request,
    bool $canWrite,
  ): ?array {
    if ($taskUuid === NULL || $taskUuid === '') {
      return NULL;
    }
    $task = $this->visibleTask($taskUuid);
    if ($task->get('board')->getString() !== (string) $board->id()) {
      throw new CacheableNotFoundHttpException($this->notFoundCache());
    }
    $thread = $this->thread($workspace, $task, $request);
    $form = NULL;
    if ($canWrite) {
      $form = $this->formBuilder->getForm(
        MessageComposeForm::class,
        'task',
        (string) $task->uuid(),
        '',
        'task',
        (string) $board->uuid(),
      );
    }
    return [
      'label' => (string) $task->label(),
      'status' => $this->statusLabel($task->get('status')->getString()),
      'priority' => $this->priorityLabel($task->get('priority')->getString()),
      'owner' => $this->ownerName($task),
      'due' => $this->dueLabel($workspace, $task->get('due_at')->getString()),
      'description' => $this->markup($task->get('description')->getString()),
      'criteria' => $this->markup($task->get('acceptance_criteria')->getString()),
      'messages' => $thread['messages'],
      'messages_empty' => $thread['empty'],
      'message_form' => $form,
      'task_url' => Url::fromRoute('ab_ui.task_view', [
        'uuid' => $task->uuid(),
      ])->toString(),
      'close_url' => Url::fromRoute('ab_ui.board_tasks', [
        'uuid' => $board->uuid(),
      ])->toString(),
    ];
  }

  /**
   * Cache metadata for a missing board or task.
   */
  private function notFoundCache(): CacheableMetadata {
    return (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships', 'ab_workspace'])
      ->addCacheTags(['ab_membership_list:' . $this->currentUser->id()]);
  }

  /**
   * Cache metadata for one task list.
   *
   * @return array<string, mixed>
   *   Render cache metadata.
   */
  private function cacheMeta(?Workspace $workspace): array {
    $tags = ['ab_membership_list:' . $this->currentUser->id()];
    if ($workspace instanceof Workspace && $workspace->id() !== NULL) {
      $tags[] = 'ab_workspace_tasks:' . $workspace->id();
    }
    return [
      'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
      'tags' => $tags,
    ];
  }

}
