<?php

declare(strict_types=1);

namespace Drupal\ab_api\Http;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Core\Database\Connection;

/**
 * Shapes domain records into the public JSON contract.
 *
 * Internal numeric ids stay out of the document. The lease secret is
 * never selected.
 */
final class TaskDocument {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Builds a workspace document.
   *
   * @return array<string, mixed>
   *   The public workspace.
   */
  public function workspace(Workspace $workspace): array {
    return [
      'id' => (string) $workspace->uuid(),
      'name' => (string) $workspace->label(),
      'timezone' => (string) $workspace->get('timezone')->getString(),
    ];
  }

  /**
   * Builds a board document.
   *
   * @return array<string, mixed>
   *   The public board.
   */
  public function board(Board $board): array {
    $archived = (int) $board->get('archived')->getString() === 1;
    return [
      'id' => (string) $board->uuid(),
      'workspace_id' => $this->uuidOf('ab_workspace', (int) $board->get('workspace')->getString()),
      'name' => (string) $board->label(),
      'description' => (string) $board->get('description')->getString(),
      'archived' => $archived,
    ];
  }

  /**
   * Builds a task document.
   *
   * @return array<string, mixed>
   *   The public task.
   */
  public function task(Task $task): array {
    $owner = (int) $task->get('human_owner')->getString();
    $agent = (int) $task->get('assigned_agent')->getString();
    $execution = (int) $task->get('active_execution')->getString();
    return [
      'id' => (string) $task->uuid(),
      'workspace_id' => $this->uuidOf('ab_workspace', (int) $task->get('workspace')->getString()),
      'board_id' => $this->uuidOf('ab_board', (int) $task->get('board')->getString()),
      'title' => (string) $task->label(),
      'description' => (string) $task->get('description')->getString(),
      'acceptance_criteria' => (string) $task->get('acceptance_criteria')->getString(),
      'status' => (string) $task->get('status')->getString(),
      'priority' => (string) $task->get('priority')->getString(),
      'human_owner' => $owner > 0 ? $this->uuidOf('users', $owner, 'uid') : NULL,
      'assigned_agent' => $agent > 0 ? $this->uuidOf('ab_agent', $agent) : NULL,
      'due_at' => $this->time($task->get('due_at')->getString()),
      'position' => (int) $task->get('position')->getString(),
      'version' => (int) $task->get('version')->getString(),
      'active_execution' => $execution > 0 ? $this->uuidOf('ab_execution', $execution) : NULL,
    ];
  }

  /**
   * Builds a task document from values already read with the cursor.
   *
   * @param array<string, mixed> $row
   *   Task columns captured in the snapshot statement.
   *
   * @return array<string, mixed>
   *   The public task.
   */
  public function captured(array $row): array {
    $owner = (int) ($row['human_owner'] ?? 0);
    $agent = (int) ($row['assigned_agent'] ?? 0);
    $execution = (int) ($row['active_execution'] ?? 0);
    return [
      'id' => (string) $row['uuid'],
      'workspace_id' => $this->uuidOf('ab_workspace', (int) $row['workspace']),
      'board_id' => $this->uuidOf('ab_board', (int) $row['board']),
      'title' => (string) ($row['title'] ?? ''),
      'description' => (string) ($row['description'] ?? ''),
      'acceptance_criteria' => (string) ($row['acceptance_criteria'] ?? ''),
      'status' => (string) ($row['status'] ?? ''),
      'priority' => (string) ($row['priority'] ?? ''),
      'human_owner' => $owner > 0 ? $this->uuidOf('users', $owner, 'uid') : NULL,
      'assigned_agent' => $agent > 0 ? $this->uuidOf('ab_agent', $agent) : NULL,
      'due_at' => $this->time($row['due_at'] ?? NULL),
      'position' => (int) ($row['position'] ?? 0),
      'version' => (int) ($row['version'] ?? 0),
      'active_execution' => $execution > 0 ? $this->uuidOf('ab_execution', $execution) : NULL,
    ];
  }

  /**
   * Formats a UTC timestamp for the contract.
   */
  public function time(mixed $value): ?string {
    if ($value === NULL || $value === '' || $value === FALSE) {
      return NULL;
    }
    $stamp = (int) $value;
    if ($stamp <= 0) {
      return NULL;
    }
    return gmdate('Y-m-d\TH:i:s\Z', $stamp);
  }

  /**
   * Resolves a public UUID from an internal id.
   */
  private function uuidOf(string $table, int $id, string $key = 'id'): ?string {
    $tables = ['ab_workspace', 'ab_board', 'ab_agent', 'ab_execution', 'users'];
    if ($id <= 0 || !in_array($table, $tables, TRUE) || !in_array($key, ['id', 'uid'], TRUE)) {
      return NULL;
    }
    $uuid = $this->database->query(
      'SELECT uuid FROM {' . $table . '} WHERE ' . $key . ' = :id',
      [':id' => $id],
    )->fetchField();
    return is_string($uuid) && $uuid !== '' ? $uuid : NULL;
  }

}
