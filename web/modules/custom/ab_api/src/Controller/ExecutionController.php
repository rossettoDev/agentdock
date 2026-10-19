<?php

declare(strict_types=1);

namespace Drupal\ab_api\Controller;

use Drupal\ab_api\Http\ExecutionCommands;
use Drupal\ab_api\StoredResponse;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves the execution, review and conversation routes.
 *
 * The controller does not decide access. The command service does.
 */
final class ExecutionController extends ControllerBase {

  public function __construct(
    #[Autowire(service: ExecutionCommands::class)]
    private readonly ExecutionCommands $commands,
  ) {}

  /**
   * Claims a task.
   */
  public function claim(Request $request, string $workspace_id, string $task_id): JsonResponse {
    return $this->stored($this->commands->claim($request, $workspace_id, $task_id));
  }

  /**
   * Renews a lease.
   */
  public function heartbeat(Request $request, string $workspace_id, string $execution_id): JsonResponse {
    return $this->stored($this->commands->heartbeat($request, $workspace_id, $execution_id));
  }

  /**
   * Records a progress note.
   */
  public function progress(Request $request, string $workspace_id, string $execution_id): JsonResponse {
    return $this->stored($this->commands->progress($request, $workspace_id, $execution_id));
  }

  /**
   * Asks a human for information.
   */
  public function requestInput(Request $request, string $workspace_id, string $execution_id): JsonResponse {
    return $this->stored($this->commands->requestInput($request, $workspace_id, $execution_id));
  }

  /**
   * Answers an open question.
   */
  public function answer(Request $request, string $workspace_id, string $input_request_id): JsonResponse {
    return $this->stored($this->commands->answer($request, $workspace_id, $input_request_id));
  }

  /**
   * Delivers a result.
   */
  public function submit(Request $request, string $workspace_id, string $execution_id): JsonResponse {
    return $this->stored($this->commands->submit($request, $workspace_id, $execution_id));
  }

  /**
   * Records a failure.
   */
  public function fail(Request $request, string $workspace_id, string $execution_id): JsonResponse {
    return $this->stored($this->commands->fail($request, $workspace_id, $execution_id));
  }

  /**
   * Releases a lease.
   */
  public function release(Request $request, string $workspace_id, string $execution_id): JsonResponse {
    return $this->stored($this->commands->release($request, $workspace_id, $execution_id));
  }

  /**
   * Approves a delivery.
   */
  public function approve(Request $request, string $workspace_id, string $task_id): JsonResponse {
    return $this->stored($this->commands->approve($request, $workspace_id, $task_id));
  }

  /**
   * Rejects a delivery.
   */
  public function reject(Request $request, string $workspace_id, string $task_id): JsonResponse {
    return $this->stored($this->commands->reject($request, $workspace_id, $task_id));
  }

  /**
   * Lists messages.
   */
  public function messages(Request $request, string $workspace_id, string $thread_id): JsonResponse {
    return new JsonResponse($this->commands->messages($request, $workspace_id, $thread_id));
  }

  /**
   * Appends a message.
   */
  public function createMessage(Request $request, string $workspace_id, string $thread_id): JsonResponse {
    return $this->stored($this->commands->createMessage($request, $workspace_id, $thread_id));
  }

  /**
   * Returns a stored success body without encoding it twice.
   */
  private function stored(StoredResponse $stored): JsonResponse {
    return new JsonResponse($stored->body, $stored->status, [], TRUE);
  }

}
