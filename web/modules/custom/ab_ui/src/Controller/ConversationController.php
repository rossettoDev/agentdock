<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Controller;

use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskDependencyManager;
use Drupal\ab_board\TaskRepository;
use Drupal\ab_communication\InputRequestService;
use Drupal\ab_communication\MessagePage;
use Drupal\ab_communication\MessageService;
use Drupal\ab_communication\StoredMessage;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_ui\Form\MessageComposeForm;
use Drupal\ab_ui\TaskMarkdown;
use Drupal\ab_ui\WorkspaceContext;
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
 * Lists board and task conversations and opens one of them.
 *
 * Direct messages are not a screen. Opening a thread does not change
 * the selected workspace.
 */
final class ConversationController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly MessageService $messages,
    private readonly HumanActorContextFactory $actors,
    private readonly BoardRepository $boards,
    private readonly TaskRepository $tasks,
    private readonly TaskDependencyManager $dependencies,
    private readonly InputRequestService $requests,
    private readonly MembershipScope $scope,
    private readonly WorkspaceContext $context,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
    private readonly TaskMarkdown $markdown,
    private readonly Connection $database,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Lists conversations of the selected workspace.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function listing(): array {
    $workspace = $this->context->workspace();
    if (!$workspace instanceof Workspace || !$this->canRead($workspace)) {
      return [
        '#theme' => 'ab_ui_conversation_list',
        '#workspace_label' => NULL,
        '#items' => [],
        '#empty' => (string) $this->t('Nenhum workspace selecionado.'),
        '#cache' => $this->cacheMeta(NULL),
      ];
    }
    $items = [];
    try {
      $threads = $this->messages->listThreads($this->actors->create($workspace));
    }
    catch (ScopedResourceNotFoundException) {
      $threads = [];
    }
    foreach ($threads as $thread) {
      $task = $thread->taskUuid !== NULL;
      $items[] = [
        'label' => $task ? $thread->taskLabel : $thread->boardLabel,
        'kind' => $task ? (string) $this->t('Tarefa') : (string) $this->t('Quadro'),
        'when' => $this->formatWhen($workspace, $thread->created),
        'url' => Url::fromRoute('ab_ui.conversation', [
          'uuid' => $thread->threadUuid,
        ])->toString(),
        'excerpt' => $thread->excerpt,
        'context_url' => $task
          ? Url::fromRoute('ab_ui.task_view', ['uuid' => $thread->taskUuid])->toString()
          : Url::fromRoute('ab_ui.board_tasks', ['uuid' => $thread->boardUuid])->toString(),
        'context_label' => $task ? (string) $this->t('Tarefa') : (string) $this->t('Tarefas'),
      ];
    }
    return [
      '#theme' => 'ab_ui_conversation_list',
      '#workspace_label' => (string) $workspace->label(),
      '#items' => $items,
      '#empty' => (string) $this->t('Nenhuma conversa neste workspace.'),
      '#cache' => $this->cacheMeta($workspace),
    ];
  }

  /**
   * Opens the board conversation without creating it on read.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function board(string $uuid, Request $request): array {
    $board = $this->boards->loadAccessibleByUuid($uuid);
    if (!$board instanceof Board) {
      throw new CacheableNotFoundHttpException($this->notFoundCache());
    }
    $workspace = $this->workspaceOfBoard($board);
    return $this->render(
      $workspace,
      'board',
      (string) $board->uuid(),
      (string) $board->label(),
      Url::fromRoute('ab_ui.board_tasks', ['uuid' => $board->uuid()])->toString(),
      (string) $this->t('Tarefas'),
      '',
      $request,
    );
  }

  /**
   * Opens the task conversation without creating it on read.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function task(string $uuid, Request $request): array {
    $task = $this->tasks->loadAccessibleByUuid($uuid);
    if (!$task instanceof Task) {
      throw new CacheableNotFoundHttpException($this->notFoundCache());
    }
    $workspace = $this->workspaceOfTask($task);
    return $this->render(
      $workspace,
      'task',
      (string) $task->uuid(),
      (string) $task->label(),
      Url::fromRoute('ab_ui.task_view', ['uuid' => $task->uuid()])->toString(),
      (string) $this->t('Tarefa'),
      '',
      $request,
    );
  }

  /**
   * Opens a stored thread by its public id.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function open(string $uuid, Request $request): array {
    $row = $this->database->select('ab_thread', 't')
      ->fields('t', ['workspace', 'board_id', 'task_id', 'uuid'])
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchAssoc();
    if (!is_array($row)) {
      throw new CacheableNotFoundHttpException($this->notFoundCache());
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($row['workspace']);
    if (!$workspace instanceof Workspace || !$this->canRead($workspace)) {
      throw new CacheableNotFoundHttpException($this->notFoundCache());
    }
    $board = $this->entityTypeManager->getStorage('ab_board')->load($row['board_id']);
    if (!$board instanceof Board) {
      throw new CacheableNotFoundHttpException($this->notFoundCache());
    }
    if ((int) $row['task_id'] > 0) {
      $task = $this->entityTypeManager->getStorage('ab_task')->load($row['task_id']);
      if (!$task instanceof Task) {
        throw new CacheableNotFoundHttpException($this->notFoundCache());
      }
      return $this->render(
        $workspace,
        'task',
        (string) $task->uuid(),
        (string) $task->label(),
        Url::fromRoute('ab_ui.task_view', ['uuid' => $task->uuid()])->toString(),
        (string) $this->t('Tarefa'),
        (string) $row['uuid'],
        $request,
      );
    }
    return $this->render(
      $workspace,
      'board',
      (string) $board->uuid(),
      (string) $board->label(),
      Url::fromRoute('ab_ui.board_tasks', ['uuid' => $board->uuid()])->toString(),
      (string) $this->t('Tarefas'),
      (string) $row['uuid'],
      $request,
    );
  }

  /**
   * Renders one conversation and the composer when writing is allowed.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function render(
    ?Workspace $workspace,
    string $scope,
    string $target,
    string $label,
    string $contextUrl,
    string $contextLabel,
    string $returnUuid,
    Request $request,
  ): array {
    if (!$workspace instanceof Workspace || !$this->canRead($workspace)) {
      throw new CacheableNotFoundHttpException($this->notFoundCache());
    }
    $actor = $this->actors->create($workspace);
    $cursor = $request->query->get('before');
    $cursor = is_string($cursor) && preg_match('/^\d+\.\d+$/', $cursor) === 1 ? $cursor : NULL;
    try {
      $thread = $returnUuid !== '' ? $returnUuid : $this->messages->findThreadUuid($actor, $scope, $target);
      $page = $thread === NULL
        ? new MessagePage([], NULL)
        : $this->messages->read($actor, $thread, $cursor, MessageService::PAGE_DEFAULT);
    }
    catch (ScopedResourceNotFoundException) {
      throw new CacheableNotFoundHttpException($this->notFoundCache());
    }
    if ($returnUuid !== '') {
      $route = 'ab_ui.conversation';
    }
    elseif ($scope === 'task') {
      $route = 'ab_ui.task_conversation';
    }
    else {
      $route = 'ab_ui.board_conversation';
    }
    $routeUuid = $returnUuid !== '' ? $returnUuid : $target;
    $older = '';
    if ($page->cursor !== NULL) {
      $older = Url::fromRoute($route, ['uuid' => $routeUuid], [
        'query' => ['before' => $page->cursor],
      ])->toString();
    }
    $build = [
      '#theme' => 'ab_ui_conversation',
      '#label' => $label,
      '#context_url' => $contextUrl,
      '#context_label' => $contextLabel,
      '#older_url' => $older,
      '#messages' => $this->lines($workspace, $page),
      '#empty' => (string) $this->t('Nenhuma mensagem nesta conversa.'),
      '#form' => NULL,
      '#back_url' => Url::fromRoute('ab_ui.conversations')->toString(),
      '#context' => $this->context($workspace, $scope, $target, $contextUrl, $contextLabel, $label),
      '#cache' => $this->cacheMeta($workspace),
    ];
    $build['#cache']['contexts'][] = 'url.query_args:before';
    if ($this->canWrite($workspace)) {
      $build['#form'] = $this->formBuilder->getForm(
        MessageComposeForm::class,
        $scope,
        $target,
        $returnUuid,
      );
    }
    return $build;
  }

  /**
   * Prepares sanitized lines for the template.
   *
   * @return array<int, array<string, mixed>>
   *   Author, kind and body for each message.
   */
  private function lines(Workspace $workspace, MessagePage $page): array {
    $ids = [];
    foreach ($page->messages as $message) {
      $ids[] = $message->id;
    }
    $mentions = $this->mentionNames($workspace, $ids);
    $lines = [];
    foreach ($page->messages as $message) {
      $html = $this->markdown->render($message->body);
      $lines[] = [
        'author' => $this->author($workspace, $message),
        'actor' => $this->actorLabel($message->actorType),
        'when' => $this->formatWhen($workspace, $message->created),
        'kind' => $this->kindLabel($message->kind),
        'body' => $html === '' ? [] : ['#markup' => $html],
        'reply' => $message->replyTo !== NULL,
        'mentions' => $mentions[$message->id] ?? [],
      ];
    }
    return $lines;
  }

  /**
   * Returns a display name for the authenticated author.
   */
  private function author(Workspace $workspace, StoredMessage $message): string {
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
   */
  private function kindLabel(string $kind): string {
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
   * Loads the workspace stored on the board.
   */
  private function workspaceOfBoard(Board $board): ?Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($board->get('workspace')->getString());
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

  /**
   * Loads the workspace stored on the task.
   */
  private function workspaceOfTask(Task $task): ?Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($task->get('workspace')->getString());
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

  /**
   * Builds the side panel from the board or the task already in scope.
   *
   * @return array<string, mixed>
   *   Kind, link and the task fields when the thread belongs to one.
   */
  private function context(
    Workspace $workspace,
    string $scope,
    string $target,
    string $contextUrl,
    string $contextLabel,
    string $label,
  ): array {
    $panel = [
      'kind' => $scope === 'task' ? (string) $this->t('Tarefa') : (string) $this->t('Quadro'),
      'title' => $label,
      'url' => $contextUrl,
      'url_label' => $contextLabel,
      'status' => '',
      'priority' => '',
      'owner' => '',
      'agent' => '',
      'dependencies' => [],
      'requests' => [],
    ];
    if ($scope !== 'task') {
      return $panel;
    }
    $task = $this->tasks->loadAccessibleByUuid($target);
    if (!$task instanceof Task) {
      return $panel;
    }
    $panel['status'] = $this->statusLabel($task->get('status')->getString());
    $panel['priority'] = $this->priorityLabel($task->get('priority')->getString());
    $panel['owner'] = $this->ownerName($task);
    $panel['agent'] = $this->agentName($workspace, $task);
    try {
      foreach ($this->dependencies->predecessors($task) as $predecessor) {
        $panel['dependencies'][] = [
          'label' => (string) $predecessor->label(),
          'url' => Url::fromRoute('ab_ui.task_view', [
            'uuid' => $predecessor->uuid(),
          ])->toString(),
          'status' => $this->statusLabel($predecessor->get('status')->getString()),
        ];
      }
    }
    catch (ScopedResourceNotFoundException) {
      $panel['dependencies'] = [];
    }
    foreach ($this->requests->forTask($this->actors->create($workspace), $task) as $request) {
      $html = $this->markdown->render($request->question);
      $panel['requests'][] = [
        'status' => $request->status === 'open'
          ? (string) $this->t('Pendente')
          : (string) $this->t('Respondida'),
        'question' => $html === '' ? [] : ['#markup' => $html],
      ];
    }
    return $panel;
  }

  /**
   * Names stored mentions without leaving the workspace.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the messages.
   * @param list<int> $messageIds
   *   Message ids on the current page.
   *
   * @return array<int, list<string>>
   *   Display names keyed by message id.
   */
  private function mentionNames(Workspace $workspace, array $messageIds): array {
    if ($messageIds === []) {
      return [];
    }
    $rows = $this->database->select('ab_message_mention', 'm')
      ->fields('m', ['message_id', 'recipient_type', 'recipient_id'])
      ->condition('workspace', (int) $workspace->id())
      ->condition('message_id', $messageIds, 'IN')
      ->execute();
    $names = [];
    foreach ($rows as $row) {
      if (!is_object($row)) {
        continue;
      }
      $names[(int) $row->message_id][] = $this->recipientName(
        $workspace,
        (string) $row->recipient_type,
        (string) $row->recipient_id,
      );
    }
    return $names;
  }

  /**
   * Returns a display name for a mention in this workspace.
   */
  private function recipientName(Workspace $workspace, string $type, string $id): string {
    if ($type === ActorContext::TYPE_AGENT) {
      $ids = $this->entityTypeManager->getStorage('ab_agent')->getQuery()
        ->accessCheck(FALSE)
        ->condition('uuid', $id)
        ->condition('workspace', $workspace->id())
        ->execute();
      $agent = $this->entityTypeManager->getStorage('ab_agent')->load(reset($ids) ?: 0);
      return $agent instanceof Agent ? (string) $agent->label() : (string) $this->t('Participante');
    }
    if ($type === ActorContext::TYPE_HUMAN) {
      $user = $this->entityTypeManager->getStorage('user')->load($id);
      return $user instanceof UserInterface ? $user->getAccountName() : (string) $this->t('Participante');
    }
    return (string) $this->t('Participante');
  }

  /**
   * Returns the Portuguese label of an actor type.
   */
  private function actorLabel(string $type): string {
    return match ($type) {
      ActorContext::TYPE_AGENT => (string) $this->t('Agente'),
      ActorContext::TYPE_SYSTEM => (string) $this->t('Sistema'),
      default => (string) $this->t('Humano'),
    };
  }

  /**
   * Formats a UTC timestamp in the workspace timezone.
   */
  private function formatWhen(Workspace $workspace, int $timestamp): string {
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
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format('d/m/Y H:i');
  }

  /**
   * Returns the Portuguese label of a task status.
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
   * Returns the assigned agent when it still belongs to the workspace.
   */
  private function agentName(Workspace $workspace, Task $task): string {
    $id = (int) $task->get('assigned_agent')->getString();
    if ($id <= 0) {
      return '';
    }
    $agent = $this->entityTypeManager->getStorage('ab_agent')->load($id);
    if (!$agent instanceof Agent) {
      return '';
    }
    if ($agent->get('workspace')->getString() !== (string) $workspace->id()) {
      return '';
    }
    return (string) $agent->label();
  }

  /**
   * Reports whether the account can read the workspace.
   */
  private function canRead(Workspace $workspace): bool {
    return $this->scope->allows($this->currentUser, $workspace, WorkspacePermission::READ);
  }

  /**
   * Reports whether the account can write in the workspace.
   */
  private function canWrite(Workspace $workspace): bool {
    return $this->scope->allows($this->currentUser, $workspace, WorkspacePermission::WRITE);
  }

  /**
   * Cache metadata for one conversation.
   *
   * @return array<string, mixed>
   *   Render cache metadata.
   */
  private function cacheMeta(?Workspace $workspace): array {
    $tags = ['ab_membership_list:' . $this->currentUser->id()];
    if ($workspace instanceof Workspace && $workspace->id() !== NULL) {
      $tags[] = 'ab_workspace_messages:' . $workspace->id();
    }
    return [
      'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
      'tags' => $tags,
    ];
  }

  /**
   * Cache metadata for a hidden conversation.
   */
  private function notFoundCache(): CacheableMetadata {
    return (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships', 'ab_workspace'])
      ->addCacheTags(['ab_membership_list:' . $this->currentUser->id()]);
  }

}
