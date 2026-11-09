<?php

declare(strict_types=1);

namespace Drupal\ab_api\Controller;

use Drupal\ab_api\Http\Catalog;
use Drupal\ab_api\Http\ColumnCommands;
use Drupal\ab_api\Http\TaskCommands;
use Drupal\ab_api\Http\TaskMemoryContext;
use Drupal\ab_api\StoredResponse;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves the workspace, board and task HTTP routes.
 *
 * The controller does not decide access. The catalog and the command
 * services do, from the authenticated caller.
 */
final class ApiController extends ControllerBase {

  public function __construct(
    #[Autowire(service: Catalog::class)]
    private readonly Catalog $catalog,
    #[Autowire(service: TaskCommands::class)]
    private readonly TaskCommands $commands,
    #[Autowire(service: ColumnCommands::class)]
    private readonly ColumnCommands $columns,
    #[Autowire(service: TaskMemoryContext::class)]
    private readonly TaskMemoryContext $taskMemory,
  ) {}

  /**
   * Lists workspaces.
   */
  public function workspaces(Request $request): JsonResponse {
    return new JsonResponse($this->catalog->workspaces($request));
  }

  /**
   * Lists boards.
   */
  public function boards(Request $request, string $workspace_id): JsonResponse {
    return new JsonResponse($this->catalog->boards($request, $workspace_id));
  }

  /**
   * Creates a board.
   */
  public function createBoard(Request $request, string $workspace_id): JsonResponse {
    return $this->stored($this->commands->createBoard($request, $workspace_id));
  }

  /**
   * Lists columns.
   */
  public function columns(Request $request, string $workspace_id, string $board_id): JsonResponse {
    return new JsonResponse([
      'data' => $this->columns->list($request, $workspace_id, $board_id),
    ]);
  }

  /**
   * Creates a column.
   */
  public function createColumn(Request $request, string $workspace_id, string $board_id): JsonResponse {
    return $this->stored($this->columns->create($request, $workspace_id, $board_id));
  }

  /**
   * Renames a column.
   */
  public function renameColumn(Request $request, string $workspace_id, string $board_id, string $column_id): JsonResponse {
    return $this->stored($this->columns->rename($request, $workspace_id, $board_id, $column_id));
  }

  /**
   * Moves a column one place.
   */
  public function moveColumn(Request $request, string $workspace_id, string $board_id, string $column_id): JsonResponse {
    return $this->stored($this->columns->move($request, $workspace_id, $board_id, $column_id));
  }

  /**
   * Archives a custom column.
   */
  public function archiveColumn(Request $request, string $workspace_id, string $board_id, string $column_id): JsonResponse {
    return $this->stored($this->columns->archive($request, $workspace_id, $board_id, $column_id));
  }

  /**
   * Lists tasks.
   */
  public function tasks(Request $request, string $workspace_id, string $board_id): JsonResponse {
    return new JsonResponse($this->catalog->tasks($request, $workspace_id, $board_id));
  }

  /**
   * Creates a task.
   */
  public function createTask(Request $request, string $workspace_id, string $board_id): JsonResponse {
    return $this->stored($this->commands->createTask($request, $workspace_id, $board_id));
  }

  /**
   * Reads one task.
   */
  public function task(Request $request, string $workspace_id, string $task_id): JsonResponse {
    $document = $this->catalog->task($request, $workspace_id, $task_id);
    $document = $this->taskMemory->enrich($request, $workspace_id, $task_id, $document);
    return $this->taskResponse($document);
  }

  /**
   * Patches whitelist fields.
   */
  public function updateTask(Request $request, string $workspace_id, string $task_id): JsonResponse {
    return $this->stored($this->commands->updateTask($request, $workspace_id, $task_id));
  }

  /**
   * Moves a task through a human transition.
   */
  public function transition(Request $request, string $workspace_id, string $task_id): JsonResponse {
    return $this->stored($this->commands->transition($request, $workspace_id, $task_id));
  }

  /**
   * Reads one execution.
   */
  public function execution(Request $request, string $workspace_id, string $execution_id): JsonResponse {
    $data = $this->catalog->execution($request, $workspace_id, $execution_id);
    return new JsonResponse(['data' => $data]);
  }

  /**
   * Returns a task document with its version as the ETag.
   *
   * @param array<string, mixed> $task
   *   The public task.
   */
  private function taskResponse(array $task): JsonResponse {
    return new JsonResponse(['data' => $task]);
  }

  /**
   * Returns a stored success body without encoding it twice.
   */
  private function stored(StoredResponse $stored): JsonResponse {
    return new JsonResponse($stored->body, $stored->status, [], TRUE);
  }

}
