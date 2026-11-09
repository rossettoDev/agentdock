<?php

declare(strict_types=1);

namespace Drupal\ab_api\Controller;

use Drupal\ab_api\Http\MemoryCommands;
use Drupal\ab_api\StoredResponse;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves the memory HTTP routes under /api/agent-board/v1.
 *
 * Access stays in MemoryCommands and MemoryService. This controller only
 * forwards the request and returns JSON envelopes.
 */
final class MemoryController extends ControllerBase {

  public function __construct(
    #[Autowire(service: MemoryCommands::class)]
    private readonly MemoryCommands $memory,
  ) {}

  /**
   * Searches authorized memory entries.
   */
  public function search(Request $request, string $workspace_id): JsonResponse {
    return new JsonResponse($this->memory->search($request, $workspace_id));
  }

  /**
   * Creates a memory draft.
   */
  public function createDraft(Request $request, string $workspace_id): JsonResponse {
    return $this->stored($this->memory->create($request, $workspace_id));
  }

  /**
   * Reads one memory entry.
   */
  public function view(Request $request, string $workspace_id, string $memory_id): JsonResponse {
    return new JsonResponse($this->memory->get($request, $workspace_id, $memory_id));
  }

  /**
   * Creates a new draft revision.
   */
  public function revise(Request $request, string $workspace_id, string $memory_id): JsonResponse {
    return $this->stored($this->memory->revise($request, $workspace_id, $memory_id));
  }

  /**
   * Publishes a draft.
   */
  public function publish(Request $request, string $workspace_id, string $memory_id): JsonResponse {
    return $this->stored($this->memory->publish($request, $workspace_id, $memory_id));
  }

  /**
   * Archives a draft or published entry.
   */
  public function archive(Request $request, string $workspace_id, string $memory_id): JsonResponse {
    return $this->stored($this->memory->archive($request, $workspace_id, $memory_id));
  }

  /**
   * Lists revisions of one entry.
   */
  public function revisions(Request $request, string $workspace_id, string $memory_id): JsonResponse {
    return new JsonResponse($this->memory->revisions($request, $workspace_id, $memory_id));
  }

  /**
   * Returns a stored success body without encoding it twice.
   */
  private function stored(StoredResponse $stored): JsonResponse {
    return new JsonResponse($stored->body, $stored->status, [], TRUE);
  }

}
