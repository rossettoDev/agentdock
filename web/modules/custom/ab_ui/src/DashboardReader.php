<?php

declare(strict_types=1);

namespace Drupal\ab_ui;

use Drupal\ab_board\BoardRepository;
use Drupal\ab_board\TaskDayWindow;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;

/**
 * Reads dashboard numbers from the selected workspace.
 *
 * Ten SQL reads plus the board list, each filtered by that workspace.
 * Archived boards stay out
 * of the totals. Hoje uses the same day window as the board filter: every
 * in-progress task, plus any other task that is not cancelled and is due
 * by the end of today. Atrasada is a due date before today on a task that
 * is not done or cancelled. These queries were not load-tested.
 */
final class DashboardReader {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly TaskDayWindow $days,
    private readonly BoardRepository $boards,
  ) {}

  /**
   * Collects the counts and short lists for one readable workspace.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace selected in the session.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Account whose notifications are counted.
   *
   * @return array<string, mixed>
   *   Boards, totals, threads, events and open requests.
   */
  public function read(Workspace $workspace, AccountInterface $account): array {
    $now = $this->time->getRequestTime();
    $timezone = $workspace->get('timezone')->getString();
    $start = $this->days->startOfDay($timezone, $now);
    $end = $this->days->endOfDay($timezone, $now);
    $boards = $this->boards($workspace);
    $ids = array_keys($boards);
    $statuses = array_fill_keys([
      'backlog',
      'ready',
      'in_progress',
      'in_review',
      'done',
      'cancelled',
    ], 0);
    $today = 0;
    $overdue = 0;
    if ($ids !== []) {
      $this->statusCounts($workspace, $ids, $boards, $statuses);
      [$today, $overdue] = $this->dayCounts($workspace, $ids, $boards, $start, $end);
    }
    $agents = $this->agentCounts($workspace);
    return [
      'boards' => array_values($boards),
      'statuses' => $statuses,
      'today' => $today,
      'overdue' => $overdue,
      'review' => $statuses['in_review'],
      'running_agents' => $agents['running'],
      'waiting_agents' => $agents['waiting'],
      'unread' => $this->unread($workspace, $account),
      'threads' => $this->threads($workspace),
      'events' => $this->events($workspace),
      'reviews' => $this->reviews($workspace, $ids),
      'overdue_tasks' => $this->overdueTasks($workspace, $ids, $start),
      'requests' => $this->requests($workspace),
    ];
  }

  /**
   * Lists non-archived boards keyed by id.
   *
   * @return array<int, array<string, mixed>>
   *   Board rows waiting for their counts.
   */
  private function boards(Workspace $workspace): array {
    $rows = [];
    foreach ($this->boards->accessibleBoards($workspace) as $board) {
      if ((int) $board->get('archived')->getString() === 1 || $board->id() === NULL) {
        continue;
      }
      $rows[(int) $board->id()] = [
        'uuid' => (string) $board->uuid(),
        'name' => (string) $board->label(),
        'statuses' => [],
        'today' => 0,
        'overdue' => 0,
        'review' => 0,
      ];
    }
    return $rows;
  }

  /**
   * Fills status totals from one grouped query.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace selected in the session.
   * @param list<int> $ids
   *   Non-archived board ids.
   * @param array<int, array<string, mixed>> $boards
   *   Board rows keyed by id.
   * @param array<string, int> $statuses
   *   Workspace totals keyed by status.
   */
  private function statusCounts(Workspace $workspace, array $ids, array &$boards, array &$statuses): void {
    $query = $this->database->select('ab_task', 't');
    $query->addField('t', 'board');
    $query->addField('t', 'status');
    $query->addExpression('COUNT(*)', 'n');
    $query->condition('workspace', (int) $workspace->id());
    $query->condition('board', $ids, 'IN');
    $query->groupBy('board');
    $query->groupBy('status');
    foreach ($query->execute() as $row) {
      if (!is_object($row)) {
        continue;
      }
      $board = (int) $row->board;
      $status = (string) $row->status;
      $count = (int) $row->n;
      if (!isset($boards[$board], $statuses[$status])) {
        continue;
      }
      $boards[$board]['statuses'][$status] = $count;
      $boards[$board]['review'] = $status === 'in_review' ? $count : $boards[$board]['review'];
      $statuses[$status] += $count;
    }
  }

  /**
   * Counts Hoje and Atrasada for the visible boards.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace selected in the session.
   * @param list<int> $ids
   *   Non-archived board ids.
   * @param array<int, array<string, mixed>> $boards
   *   Board rows keyed by id.
   * @param int|null $start
   *   Start of the workspace day, or NULL.
   * @param int|null $end
   *   End of the workspace day, or NULL.
   *
   * @return array{0: int, 1: int}
   *   Workspace totals for Hoje and Atrasada.
   */
  private function dayCounts(Workspace $workspace, array $ids, array &$boards, ?int $start, ?int $end): array {
    if ($start === NULL || $end === NULL) {
      return [0, 0];
    }
    $sql = <<<'SQL'
SELECT board,
  SUM(CASE
    WHEN status = 'in_progress'
      OR (status <> 'cancelled' AND due_at IS NOT NULL AND due_at <= :end_day)
    THEN 1 ELSE 0 END) AS today_count,
  SUM(CASE
    WHEN due_at IS NOT NULL AND due_at < :start_day
      AND status NOT IN ('done', 'cancelled')
    THEN 1 ELSE 0 END) AS overdue_count
FROM {ab_task}
WHERE workspace = :workspace AND board IN (:boards[])
GROUP BY board
SQL;
    $today = 0;
    $overdue = 0;
    $rows = $this->database->query($sql, [
      ':end_day' => $end,
      ':start_day' => $start,
      ':workspace' => (int) $workspace->id(),
      ':boards[]' => $ids,
    ]);
    foreach ($rows as $row) {
      if (!is_object($row) || !isset($boards[(int) $row->board])) {
        continue;
      }
      $boardToday = (int) $row->today_count;
      $boardOverdue = (int) $row->overdue_count;
      $boards[(int) $row->board]['today'] = $boardToday;
      $boards[(int) $row->board]['overdue'] = $boardOverdue;
      $today += $boardToday;
      $overdue += $boardOverdue;
    }
    return [$today, $overdue];
  }

  /**
   * Counts agents with a running or waiting execution.
   *
   * Waiting does not include an agent that also has a running execution,
   * matching the agent screen filter.
   *
   * @return array{running: int, waiting: int}
   *   Distinct agent counts.
   */
  private function agentCounts(Workspace $workspace): array {
    $query = $this->database->select('ab_execution', 'e');
    $query->addField('e', 'agent');
    $query->addField('e', 'status');
    $query->condition('workspace', (int) $workspace->id());
    $query->condition('status', ['running', 'waiting_input'], 'IN');
    $query->groupBy('agent');
    $query->groupBy('status');
    $live = [];
    foreach ($query->execute() as $row) {
      if (!is_object($row)) {
        continue;
      }
      $live[(int) $row->agent][(string) $row->status] = TRUE;
    }
    $counts = ['running' => 0, 'waiting' => 0];
    foreach ($live as $statuses) {
      if (isset($statuses['running'])) {
        $counts['running']++;
        continue;
      }
      if (isset($statuses['waiting_input'])) {
        $counts['waiting']++;
      }
    }
    return $counts;
  }

  /**
   * Counts unread notifications of this account in the workspace.
   */
  private function unread(Workspace $workspace, AccountInterface $account): int {
    $query = $this->database->select('ab_notification', 'n');
    $query->addExpression('COUNT(*)', 'n');
    $query->condition('workspace', (int) $workspace->id());
    $query->condition('recipient_user', (int) $account->id());
    $query->isNull('read_at');
    return (int) $query->execute()->fetchField();
  }

  /**
   * Returns the five newest board and task threads.
   *
   * @return list<array<string, mixed>>
   *   Thread id, labels, excerpt and timestamp.
   */
  private function threads(Workspace $workspace): array {
    $sql = <<<'SQL'
SELECT thread.uuid AS thread_uuid, board.uuid AS board_uuid, board.name AS board_name,
  task.uuid AS task_uuid, task.title AS task_title,
  last_message.body AS body, last_message.created AS created
FROM {ab_thread} thread
INNER JOIN {ab_board} board ON board.id = thread.board_id
LEFT JOIN {ab_task} task ON task.id = thread.task_id
INNER JOIN LATERAL (
  SELECT body, created, id
  FROM {ab_message} message
  WHERE message.thread_id = thread.id
  ORDER BY message.created DESC, message.id DESC
  LIMIT 1
) last_message ON TRUE
WHERE thread.workspace = :workspace AND board.archived = :archived
ORDER BY last_message.created DESC, last_message.id DESC
LIMIT 5
SQL;
    $items = [];
    $rows = $this->database->query($sql, [
      ':workspace' => (int) $workspace->id(),
      ':archived' => 0,
    ]);
    foreach ($rows as $row) {
      if (!is_object($row)) {
        continue;
      }
      $task = is_string($row->task_uuid) && $row->task_uuid !== '' ? $row->task_uuid : NULL;
      $items[] = [
        'uuid' => (string) $row->thread_uuid,
        'label' => $task === NULL ? (string) $row->board_name : (string) $row->task_title,
        'kind' => $task === NULL ? 'board' : 'task',
        'excerpt' => $this->excerpt((string) $row->body),
        'created' => (int) $row->created,
      ];
    }
    return $items;
  }

  /**
   * Returns recent event types without their payload.
   *
   * @return list<array{type: string, created: int}>
   *   Type and timestamp, newest sequence first.
   */
  private function events(Workspace $workspace): array {
    $query = $this->database->select('ab_event', 'e');
    $query->fields('e', ['type', 'created']);
    $query->condition('workspace_id', (string) $workspace->uuid());
    $query->orderBy('sequence', 'DESC');
    $query->range(0, 8);
    $items = [];
    foreach ($query->execute() as $row) {
      if (!is_object($row)) {
        continue;
      }
      $items[] = [
        'type' => (string) $row->type,
        'created' => (int) $row->created,
      ];
    }
    return $items;
  }

  /**
   * Lists tasks waiting for a human review.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace selected in the session.
   * @param list<int> $ids
   *   Non-archived board ids.
   *
   * @return list<array{uuid: string, title: string}>
   *   Task uuid and title.
   */
  private function reviews(Workspace $workspace, array $ids): array {
    if ($ids === []) {
      return [];
    }
    $query = $this->database->select('ab_task', 't');
    $query->fields('t', ['uuid', 'title']);
    $query->condition('workspace', (int) $workspace->id());
    $query->condition('board', $ids, 'IN');
    $query->condition('status', 'in_review');
    $query->orderBy('changed', 'DESC');
    $query->range(0, 5);
    $items = [];
    foreach ($query->execute() as $row) {
      if (!is_object($row)) {
        continue;
      }
      $items[] = [
        'uuid' => (string) $row->uuid,
        'title' => (string) $row->title,
      ];
    }
    return $items;
  }

  /**
   * Lists tasks whose due date is before today and are still open.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace selected in the session.
   * @param list<int> $ids
   *   Non-archived board ids.
   * @param int|null $start
   *   Start of the workspace day, or NULL.
   *
   * @return list<array{uuid: string, title: string}>
   *   Task uuid and title.
   */
  private function overdueTasks(Workspace $workspace, array $ids, ?int $start): array {
    if ($ids === [] || $start === NULL) {
      return [];
    }
    $query = $this->database->select('ab_task', 't');
    $query->fields('t', ['uuid', 'title']);
    $query->condition('workspace', (int) $workspace->id());
    $query->condition('board', $ids, 'IN');
    $query->isNotNull('due_at');
    $query->condition('due_at', $start, '<');
    $query->condition('status', ['done', 'cancelled'], 'NOT IN');
    $query->orderBy('due_at');
    $query->range(0, 5);
    $items = [];
    foreach ($query->execute() as $row) {
      if (!is_object($row)) {
        continue;
      }
      $items[] = [
        'uuid' => (string) $row->uuid,
        'title' => (string) $row->title,
      ];
    }
    return $items;
  }

  /**
   * Lists open information requests by task title.
   *
   * @return list<array{uuid: string, title: string, excerpt: string}>
   *   Task uuid, title and a short question.
   */
  private function requests(Workspace $workspace): array {
    $sql = <<<'SQL'
SELECT task.uuid AS task_uuid, task.title AS title, question.body AS body
FROM {ab_input_request} request
INNER JOIN {ab_task} task ON task.id = request.task_id
INNER JOIN {ab_board} board ON board.id = task.board
INNER JOIN {ab_message} question ON question.id = request.question_message_id
WHERE request.workspace = :workspace
  AND task.workspace = :workspace
  AND request.status = :open
  AND board.archived = :archived
ORDER BY request.created DESC, request.id DESC
LIMIT 5
SQL;
    $items = [];
    $rows = $this->database->query($sql, [
      ':workspace' => (int) $workspace->id(),
      ':open' => 'open',
      ':archived' => 0,
    ]);
    foreach ($rows as $row) {
      if (!is_object($row)) {
        continue;
      }
      $items[] = [
        'uuid' => (string) $row->task_uuid,
        'title' => (string) $row->title,
        'excerpt' => $this->excerpt((string) $row->body),
      ];
    }
    return $items;
  }

  /**
   * Shortens one stored body for a list.
   */
  private function excerpt(string $body): string {
    $flat = trim(preg_replace('/\s+/', ' ', $body) ?? '');
    if (mb_strlen($flat) <= 80) {
      return $flat;
    }
    return mb_substr($flat, 0, 80);
  }

}
