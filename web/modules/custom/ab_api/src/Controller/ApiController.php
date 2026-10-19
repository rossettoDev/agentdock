<?php

declare(strict_types=1);

namespace Drupal\ab_api\Controller;

use Drupal\ab_api\Http\Catalog;
use Drupal\ab_api\Http\TaskCommands;
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
    return $this->taskResponse($this->catalog->task($request, $workspace_id, $task_id));
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
