<?php

declare(strict_types=1);

namespace Drupal\ab_agent;

use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_event\EventDraft;
use Drupal\ab_event\EventStoreInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Creates and updates agents inside the caller's transaction.
 *
 * A disabled agent is stored and can be edited. It cannot start work.
 * The claim that uses this gate arrives in a later story.
 */
class AgentManager {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly MembershipScope $scope,
    private readonly HumanActorContextFactory $actors,
    private readonly EventStoreInterface $events,
  ) {}

  /**
   * Creates an agent and records agent.created.
   *
   * @throws \InvalidArgumentException
   *   The name or the concurrency limit is invalid.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The workspace is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the workspace but cannot manage agents.
   */
  public function create(
    Workspace $workspace,
    string $name,
    string $description,
    string $capabilities,
    bool $enabled,
    int $concurrencyLimit,
  ): Agent {
    $this->assertManage($workspace);
    $fields = $this->fields($name, $description, $capabilities, $concurrencyLimit);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $created = Agent::create([
        'workspace' => $workspace->id(),
        'name' => $fields['name'],
        'description' => $fields['description'],
        'capabilities' => $fields['capabilities'],
        'enabled' => $enabled,
        'concurrency_limit' => $fields['concurrency_limit'],
      ]);
      $created->save();
      if (!$created instanceof Agent || $created->uuid() === NULL || $created->uuid() === '') {
        throw new \RuntimeException('The agent was not stored.');
      }
      $this->record($workspace, $created, 'agent.created');
      $this->afterRecord($created);
      return $created;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Updates the editable fields and records agent.updated.
   *
   * @throws \InvalidArgumentException
   *   The name or the concurrency limit is invalid.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The agent workspace is outside the caller's scope.
   * @throws \Drupal\ab_core\Authorization\ScopedOperationDeniedException
   *   The caller can see the workspace but cannot manage agents.
   */
  public function update(
    Agent $agent,
    string $name,
    string $description,
    string $capabilities,
    bool $enabled,
    int $concurrencyLimit,
  ): Agent {
    $workspace = $this->workspaceOf($agent);
    $this->assertManage($workspace);
    $fields = $this->fields($name, $description, $capabilities, $concurrencyLimit);
    $transaction = $this->database->startTransaction();
    try {
      $this->lockWorkspace($workspace);
      $this->lockAgent($agent);
      $agent = $this->reload($agent);
      $agent->set('name', $fields['name']);
      $agent->set('description', $fields['description']);
      $agent->set('capabilities', $fields['capabilities']);
      $agent->set('enabled', $enabled);
      $agent->set('concurrency_limit', $fields['concurrency_limit']);
      $agent->save();
      $this->record($workspace, $agent, 'agent.updated');
      $this->afterRecord($agent);
      return $agent;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Refuses to start work when the agent is disabled.
   *
   * @throws \Drupal\ab_agent\AgentUnavailableException
   *   The agent is disabled.
   * @throws \Drupal\ab_core\Authorization\ScopedResourceNotFoundException
   *   The agent workspace is outside the caller's scope.
   */
  public function assertCanStartWork(Agent $agent): void {
    $this->assertRead($this->workspaceOf($agent));
    if ((int) $agent->get('enabled')->getString() !== 1) {
      throw new AgentUnavailableException('A disabled agent cannot start work.');
    }
  }

  /**
   * Runs after the event is recorded and before the caller commits.
   */
  protected function afterRecord(Agent $agent): void {
    // Tests throw from here to prove the caller transaction rolls back.
  }

  /**
   * Normalizes the text fields and the concurrency limit.
   *
   * @return array{name: string, description: string, capabilities: string, concurrency_limit: int}
   *   Values ready to store.
   */
  private function fields(string $name, string $description, string $capabilities, int $concurrencyLimit): array {
    $name = trim($name);
    if ($name === '' || strlen($name) > 255) {
      throw new \InvalidArgumentException('An agent requires a name.');
    }
    $capabilities = trim($capabilities);
    if (strlen($capabilities) > 255) {
      throw new \InvalidArgumentException('Capabilities are too long.');
    }
    if ($concurrencyLimit < 1) {
      throw new \InvalidArgumentException('Concurrency limit must be at least 1.');
    }
    return [
      'name' => $name,
      'description' => $description,
      'capabilities' => $capabilities,
      'concurrency_limit' => $concurrencyLimit,
    ];
  }

  /**
   * Requires permission to manage agents in a visible workspace.
   */
  private function assertManage(Workspace $workspace): void {
    $this->assertRead($workspace);
    $allowed = $this->scope->allows(
      $this->currentUser->getAccount(),
      $workspace,
      WorkspacePermission::MANAGE_AGENTS,
    );
    if (!$allowed) {
      throw new ScopedOperationDeniedException('The agent cannot be changed.');
    }
  }

  /**
   * Requires a workspace the current user can read.
   */
  private function assertRead(Workspace $workspace): void {
    $allowed = $this->scope->allows(
      $this->currentUser->getAccount(),
      $workspace,
      WorkspacePermission::READ,
    );
    if (!$allowed) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
  }

  /**
   * Loads the workspace stored on the agent.
   */
  private function workspaceOf(Agent $agent): Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($agent->get('workspace')->getString());
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    return $workspace;
  }

  /**
   * Reloads an agent from storage.
   */
  private function reload(Agent $agent): Agent {
    $storage = $this->entityTypeManager->getStorage('ab_agent');
    $storage->resetCache([$agent->id()]);
    $reloaded = $storage->load($agent->id());
    if (!$reloaded instanceof Agent) {
      throw new ScopedResourceNotFoundException('Agent was not found.');
    }
    return $reloaded;
  }

  /**
   * Locks the workspace row for the current transaction.
   */
  private function lockWorkspace(Workspace $workspace): void {
    $locked = $this->database->query('SELECT id FROM {ab_workspace} WHERE id = :id FOR UPDATE', [
      ':id' => $workspace->id(),
    ])->fetchField();
    if ($locked === FALSE) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
  }

  /**
   * Locks the agent row for the current transaction.
   */
  private function lockAgent(Agent $agent): void {
    $locked = $this->database->query('SELECT id FROM {ab_agent} WHERE id = :id FOR UPDATE', [
      ':id' => $agent->id(),
    ])->fetchField();
    if ($locked === FALSE) {
      throw new ScopedResourceNotFoundException('Agent was not found.');
    }
  }

  /**
   * Records an agent event for the authenticated actor.
   */
  private function record(Workspace $workspace, Agent $agent, string $type): void {
    $actor = $this->actors->create($workspace);
    $this->events->record(new EventDraft(
      (string) $workspace->uuid(),
      $type,
      (string) $agent->uuid(),
      $actor->actorType,
      $actor->actorId,
      [
        'name' => (string) $agent->label(),
        'enabled' => (int) $agent->get('enabled')->getString() === 1,
        'concurrency_limit' => (int) $agent->get('concurrency_limit')->getString(),
      ],
    ));
  }

}
