<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Controller;

use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskRepository;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_ui\TaskMarkdown;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\user\UserInterface;

/**
 * Lists and opens tasks without changing the selected workspace.
 */
final class TaskController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly BoardRepository $boards,
    private readonly TaskRepository $tasks,
    private readonly MembershipScope $scope,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TaskMarkdown $markdown,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Lists tasks of one readable board.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function listing(string $uuid): array {
    $board = $this->visibleBoard($uuid);
    $workspace = $this->tasks->workspaceOf($board);
    $can_write = $this->canWrite($workspace);
    $items = [];
    foreach ($this->tasks->accessibleTasks($board) as $task) {
      $items[] = [
        'label' => (string) $task->label(),
        'url' => Url::fromRoute('ab_ui.task_view', [
          'uuid' => $task->uuid(),
        ])->toString(),
        'priority' => $this->priorityLabel($task->get('priority')->getString()),
        'due' => $this->dueLabel($workspace, $task->get('due_at')->getString()),
      ];
    }
    return [
      '#theme' => 'ab_ui_task_list',
      '#board_label' => (string) $board->label(),
      '#add_url' => $can_write ? Url::fromRoute('ab_ui.task_add', [
        'uuid' => $board->uuid(),
      ])->toString() : '',
      '#items' => $items,
      '#cache' => $this->cacheMeta($workspace),
    ];
  }

  /**
   * Shows one readable task without changing the selected workspace.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function view(string $uuid): array {
    $task = $this->visibleTask($uuid);
    $workspace = $this->workspaceOf($task);
    $can_write = $workspace instanceof Workspace && $this->canWrite($workspace);
    $board_id = $task->get('board')->getString();
    $board = $this->entityTypeManager->getStorage('ab_board')->load($board_id);
    $back = $board instanceof Board
      ? Url::fromRoute('ab_ui.board_tasks', ['uuid' => $board->uuid()])->toString()
      : Url::fromRoute('ab_ui.boards')->toString();
    return [
      '#theme' => 'ab_ui_task_view',
      '#label' => (string) $task->label(),
      '#status' => $this->statusLabel($task->get('status')->getString()),
      '#priority' => $this->priorityLabel($task->get('priority')->getString()),
      '#owner' => $this->ownerName($task),
      '#due' => $workspace instanceof Workspace ? $this->dueLabel($workspace, $task->get('due_at')->getString()) : '',
      '#description' => $this->markup($task->get('description')->getString()),
      '#criteria' => $this->markup($task->get('acceptance_criteria')->getString()),
      '#edit_url' => $can_write ? Url::fromRoute('ab_ui.task_edit', [
        'uuid' => $task->uuid(),
      ])->toString() : '',
      '#back_url' => $back,
      '#cache' => $this->cacheMeta($workspace),
    ];
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
   * Cache metadata for a missing task or board.
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
