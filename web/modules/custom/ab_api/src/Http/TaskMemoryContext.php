<?php

declare(strict_types=1);

namespace Drupal\ab_api\Http;

use Drupal\ab_agent\AgentAccessDeniedException;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_memory\MemoryService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Enriches a task document with related memory hits.
 *
 * Kept outside Catalog so MemoryService does not form a container cycle with
 * task reads. Reading never claims a task or creates an execution.
 */
final class TaskMemoryContext {

  public function __construct(
    private readonly ApiCaller $caller,
    private readonly MemoryService $memory,
    private readonly MemoryDocument $documents,
  ) {}

  /**
   * Adds related_memories when the caller may read memory.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Current request.
   * @param string $workspaceUuid
   *   Public workspace id.
   * @param string $taskUuid
   *   Public task id.
   * @param array<string, mixed> $document
   *   Public task document.
   *
   * @return array<string, mixed>
   *   Task document, optionally with related_memories.
   */
  public function enrich(Request $request, string $workspaceUuid, string $taskUuid, array $document): array {
    $actor = $this->caller->actor($request, $workspaceUuid);
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      try {
        $this->caller->assertScope($actor, AgentTokenScope::MEMORY_READ);
      }
      catch (AgentAccessDeniedException) {
        return $document;
      }
    }
    try {
      $related = $this->memory->relatedForTask($actor, $taskUuid);
    }
    catch (ScopedOperationDeniedException | ScopedResourceNotFoundException | AgentAccessDeniedException) {
      return $document;
    }
    $hits = [];
    foreach ($related as $record) {
      $hits[] = $this->documents->hit($record);
    }
    $document['related_memories'] = $hits;
    return $document;
  }

}
