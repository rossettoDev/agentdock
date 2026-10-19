<?php

declare(strict_types=1);

namespace Drupal\ab_api\Controller;

use Drupal\ab_api\Http\SyncReader;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves snapshot, event and inbox reads.
 *
 * The controller does not decide access. The reader does.
 */
final class SyncController extends ControllerBase {

  public function __construct(
    #[Autowire(service: SyncReader::class)]
    private readonly SyncReader $sync,
  ) {}

  /**
   * Lists events after a cursor.
   */
  public function events(Request $request, string $workspace_id): JsonResponse {
    return new JsonResponse($this->sync->events($request, $workspace_id));
  }

  /**
   * Reads one board and the cursor from the same statement.
   */
  public function snapshot(Request $request, string $workspace_id, string $board_id): JsonResponse {
    return new JsonResponse($this->sync->snapshot($request, $workspace_id, $board_id));
  }

  /**
   * Lists signals for the authenticated agent.
   */
  public function inbox(Request $request, string $workspace_id): JsonResponse {
    return new JsonResponse($this->sync->inbox($request, $workspace_id));
  }

}
