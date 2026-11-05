<?php

declare(strict_types=1);

namespace Drupal\ab_api\Http;

use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_api\ApiException;
use Drupal\ab_api\IdempotencyKey;
use Drupal\ab_api\IdempotencyService;
use Drupal\ab_api\JsonPayload;
use Drupal\ab_api\ProblemDetails;
use Drupal\ab_api\StoredResponse;
use Drupal\ab_board\ColumnManager;
use Drupal\ab_board\ColumnRepository;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Column;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Symfony\Component\HttpFoundation\Request;

/**
 * Creates and edits board columns through the domain services.
 *
 * The actor is the authenticated caller. A payload cannot choose one.
 */
final class ColumnCommands {

  public function __construct(
    private readonly ApiCaller $caller,
    private readonly Catalog $catalog,
    private readonly IdempotencyService $idempotency,
    private readonly ColumnManager $manager,
    private readonly ColumnRepository $columns,
  ) {}

  /**
   * Lists the active columns of one readable board.
   *
   * @return list<array<string, mixed>>
   *   Public column documents, in display order.
   */
  public function list(Request $request, string $workspaceUuid, string $boardUuid): array {
    $board = $this->catalog->boardIn($request, $workspaceUuid, $boardUuid, AgentTokenScope::TASKS_READ);
    $rows = [];
    foreach ($this->columns->ensure($board) as $column) {
      $rows[] = $this->document($column, $board);
    }
    return $rows;
  }

  /**
   * Creates a column.
   */
  public function create(Request $request, string $workspaceUuid, string $boardUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->assertHumanManage($actor);
    $board = $this->catalog->boardIn($request, $workspaceUuid, $boardUuid, AgentTokenScope::TASKS_READ);
    $body = JsonPayload::object($request->getContent());
    $name = $body['name'] ?? NULL;
    if (!is_string($name)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    unset($body['name']);
    if ($body !== []) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $this->idempotency->execute(
      $actor,
      'column.create',
      $boardUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($board, $name): StoredResponse {
        try {
          $column = $this->manager->create($board, $name);
        }
        catch (\InvalidArgumentException) {
          throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
        }
        return new StoredResponse(201, $this->encode($this->document($column, $board)));
      },
    );
  }

  /**
   * Renames a column. The machine key stays.
   */
  public function rename(Request $request, string $workspaceUuid, string $boardUuid, string $columnUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->assertHumanManage($actor);
    $board = $this->catalog->boardIn($request, $workspaceUuid, $boardUuid, AgentTokenScope::TASKS_READ);
    $column = $this->columnOn($board, $columnUuid);
    $body = JsonPayload::object($request->getContent());
    $name = $body['name'] ?? NULL;
    if (!is_string($name)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    unset($body['name']);
    if ($body !== []) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $this->idempotency->execute(
      $actor,
      'column.rename',
      $columnUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($column, $board, $name): StoredResponse {
        try {
          $renamed = $this->manager->rename($column, $name);
        }
        catch (\InvalidArgumentException) {
          throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
        }
        return new StoredResponse(200, $this->encode($this->document($renamed, $board)));
      },
    );
  }

  /**
   * Moves a column one place.
   */
  public function move(Request $request, string $workspaceUuid, string $boardUuid, string $columnUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->assertHumanManage($actor);
    $board = $this->catalog->boardIn($request, $workspaceUuid, $boardUuid, AgentTokenScope::TASKS_READ);
    $column = $this->columnOn($board, $columnUuid);
    $body = JsonPayload::object($request->getContent());
    $direction = $body['direction'] ?? NULL;
    if (!is_string($direction) || !in_array($direction, ['up', 'down'], TRUE)) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    unset($body['direction']);
    if ($body !== []) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $this->idempotency->execute(
      $actor,
      'column.reorder',
      $columnUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($column, $board, $direction): StoredResponse {
        $moved = $this->manager->move($column, $direction);
        return new StoredResponse(200, $this->encode($this->document($moved, $board)));
      },
    );
  }

  /**
   * Archives a custom column that has no tasks.
   */
  public function archive(Request $request, string $workspaceUuid, string $boardUuid, string $columnUuid): StoredResponse {
    $this->caller->assertCsrf($request);
    $actor = $this->caller->actor($request, $workspaceUuid);
    $this->assertHumanManage($actor);
    $board = $this->catalog->boardIn($request, $workspaceUuid, $boardUuid, AgentTokenScope::TASKS_READ);
    $column = $this->columnOn($board, $columnUuid);
    $body = JsonPayload::object($request->getContent());
    if ($body !== []) {
      throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
    }
    return $this->idempotency->execute(
      $actor,
      'column.archive',
      $columnUuid,
      IdempotencyKey::fromHeader($request->headers->get('Idempotency-Key')),
      $request->getContent(),
      function () use ($column, $board): StoredResponse {
        try {
          $archived = $this->manager->archive($column);
        }
        catch (\InvalidArgumentException) {
          throw new ApiException(422, 'invalid', ProblemDetails::fixedDetail(422));
        }
        return new StoredResponse(200, $this->encode($this->document($archived, $board)));
      },
    );
  }

  /**
   * Requires a column of this board that the caller can read.
   */
  private function columnOn(Board $board, string $columnUuid): Column {
    $column = $this->columns->loadAccessibleByUuid($columnUuid);
    $same = $column instanceof Column
      && $column->get('board')->getString() === (string) $board->id();
    if (!$same) {
      throw new ScopedResourceNotFoundException('Column was not found.');
    }
    return $column;
  }

  /**
   * Refuses agents and humans who cannot manage boards.
   */
  private function assertHumanManage(ActorContext $actor): void {
    if ($actor->actorType === ActorContext::TYPE_AGENT) {
      throw new ScopedOperationDeniedException('The column cannot be changed.');
    }
    if (!$actor->allows(WorkspacePermission::MANAGE_BOARDS)) {
      throw new ScopedOperationDeniedException('The column cannot be changed.');
    }
  }

  /**
   * Builds the public column document.
   *
   * @return array<string, mixed>
   *   Fields safe to return.
   */
  private function document(Column $column, Board $board): array {
    return [
      'id' => (string) $column->uuid(),
      'board_id' => (string) $board->uuid(),
      'name' => $column->get('name')->getString(),
      'key' => $column->get('machine')->getString(),
      'position' => (int) $column->get('position')->getString(),
      'archived' => (int) $column->get('archived')->getString() === 1,
      'locked' => (int) $column->get('locked')->getString() === 1,
      'system' => (int) $column->get('system')->getString() === 1,
    ];
  }

  /**
   * Encodes one document.
   *
   * @param array<string, mixed> $data
   *   The public column.
   */
  private function encode(array $data): string {
    return json_encode(['data' => $data], JSON_THROW_ON_ERROR);
  }

}
