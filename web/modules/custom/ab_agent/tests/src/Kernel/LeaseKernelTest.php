<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_agent\Kernel;

use Drupal\ab_agent\AgentAccessDeniedException;
use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\ClaimedTask;
use Drupal\ab_agent\ClaimRejectedException;
use Drupal\ab_agent\ClaimWriter;
use Drupal\ab_agent\Drush\Commands\LeaseRecoveryCommands;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_agent\Entity\Execution;
use Drupal\ab_agent\ExecutionMaintenance;
use Drupal\ab_agent\ExecutionWriter;
use Drupal\ab_agent\LeaseMutation;
use Drupal\ab_agent\LeaseRejectedException;
use Drupal\ab_agent\TaskClaim;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskDependencyManager;
use Drupal\ab_board\TaskManager;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies an expired lease fails before the recovery command runs again.
 *
 * @group ab_agent
 */
class LeaseKernelTest extends KernelTestBase {

  /**
   * Clock used by claim and lease services.
   */
  private FrozenClock $clock;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'ab_core',
    'ab_event',
    'ab_board',
    'ab_agent',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('ab_workspace');
    $this->installEntitySchema('ab_membership');
    $this->installEntitySchema('ab_board');
    $this->installEntitySchema('ab_task');
    $this->installEntitySchema('ab_agent');
    $this->installEntitySchema('ab_execution');
    $this->installSchema('ab_event', [
      'ab_workspace_sequence',
      'ab_event',
      'ab_outbox',
    ]);
    $this->installSchema('ab_board', ['ab_task_dependency']);
    $this->installSchema('ab_agent', [
      'ab_agent_board_grant',
      'ab_agent_token',
      'ab_execution_lease',
    ]);
    $this->clock = new FrozenClock(1700000000);
    $this->container->set('datetime.time', $this->clock);
  }

  /**
   * Heartbeat follows the clock and a stale generation writes nothing.
   */
  public function testHeartbeatFollowsTheClock(): void {
    $this->assertSame(30, ExecutionMaintenance::HEARTBEAT_INTERVAL);
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $other = $this->creator()->create($ada, 'Casa Longe', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', '');
    $agent = $this->agents()->create($workspace, 'Revisor', '', '', TRUE, 2);
    $this->grants()->grant($agent, $board, [WorkspacePermission::WRITE]);
    $issued = $this->tokens()->issue($agent, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
    ]);
    $narrow = $this->tokens()->issue($agent, [AgentTokenScope::TASKS_CLAIM]);
    $holder = $this->actorFor($agent, $workspace, $issued->publicId);
    $task = $this->readyTask($board, 'Tema');
    $claimed = $this->claims()->claim($holder, (string) $task->uuid());
    $this->assertSame(1, $claimed->generation);
    $secret = $this->secret($claimed);
    $expires = $claimed->leaseExpiresAt;

    try {
      $this->leases()->heartbeat($this->actorFor($agent, $workspace, $narrow->publicId), $claimed->executionUuid, $claimed->leaseToken, 1);
      $this->fail('A token without execution scope renewed the lease.');
    }
    catch (AgentAccessDeniedException) {
      $this->assertSame($expires, $this->leaseExpiry($task));
    }

    try {
      $this->leases()->heartbeat($holder, $claimed->executionUuid, $claimed->leaseToken, 2);
      $this->fail('A stale generation renewed the lease.');
    }
    catch (LeaseRejectedException $exception) {
      $this->assertSame(LeaseRejectedException::GENERATION, $exception->getMessage());
      $this->assertStringNotContainsString($secret, $exception->getMessage());
      $this->assertSame($expires, $this->leaseExpiry($task));
    }

    try {
      $this->leases()->heartbeat($holder, $claimed->executionUuid, 'ab_lease_' . str_repeat('a', 32) . '.' . str_repeat('b', 64), 1);
      $this->fail('A foreign lease renewed the execution.');
    }
    catch (LeaseRejectedException $exception) {
      $this->assertSame(LeaseRejectedException::TOKEN, $exception->getMessage());
      $this->assertSame($expires, $this->leaseExpiry($task));
    }

    $intruder = $this->agents()->create($workspace, 'Outro', '', '', TRUE, 1);
    $this->grants()->grant($intruder, $board, [WorkspacePermission::WRITE]);
    $intruderToken = $this->tokens()->issue($intruder, [AgentTokenScope::EXECUTIONS_WRITE]);
    try {
      $this->leases()->heartbeat($this->actorFor($intruder, $workspace, $intruderToken->publicId), $claimed->executionUuid, $claimed->leaseToken, 1);
      $this->fail('Another agent renewed the lease.');
    }
    catch (AgentAccessDeniedException) {
      $this->assertSame($expires, $this->leaseExpiry($task));
    }

    $human = new ActorContext(ActorContext::TYPE_HUMAN, (string) $ada->id(), (string) $workspace->uuid(), [WorkspacePermission::WRITE]);
    try {
      $this->leases()->heartbeat($human, $claimed->executionUuid, $claimed->leaseToken, 1);
      $this->fail('A human renewed the lease.');
    }
    catch (AgentAccessDeniedException) {
      $this->assertSame('in_progress', $this->reload($task)->get('status')->getString());
    }

    $remoteBoard = $this->boards()->create($other, 'Longe', '');
    $remoteAgent = $this->agents()->create($other, 'Longe', '', '', TRUE, 1);
    $this->grants()->grant($remoteAgent, $remoteBoard, [WorkspacePermission::WRITE]);
    $remoteToken = $this->tokens()->issue($remoteAgent, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
    ]);
    $remoteActor = $this->actorFor($remoteAgent, $other, $remoteToken->publicId);
    $remote = $this->claims()->claim($remoteActor, (string) $this->readyTask($remoteBoard, 'Alheia')->uuid());
    try {
      $this->leases()->heartbeat($holder, $remote->executionUuid, $remote->leaseToken, 1);
      $this->fail('A foreign execution was renewed.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame($expires, $this->leaseExpiry($task));
    }
    $this->leases()->release($remoteActor, $remote->executionUuid, $remote->leaseToken, $remote->generation);

    $this->clock->advance(30);
    $interrupted = count($this->eventsOf('execution.interrupted'));
    $renewed = $this->leases()->heartbeat($holder, $claimed->executionUuid, $claimed->leaseToken, $claimed->generation);
    $this->assertSame($this->clock->getRequestTime() + ClaimWriter::LEASE_TTL, $renewed->leaseExpiresAt);
    $this->assertSame($renewed->leaseExpiresAt, $this->leaseExpiry($task));
    $this->assertSame((string) $this->clock->getRequestTime(), $this->execution($claimed)->get('heartbeat_at')->getString());
    $this->assertSame('in_progress', $this->reload($task)->get('status')->getString());
    $this->assertCount($interrupted, $this->eventsOf('execution.interrupted'));
    $this->assertStringNotContainsString($secret, json_encode($this->rows('ab_execution_lease')));

    $this->clock->advance(ClaimWriter::LEASE_TTL);
    try {
      $this->leases()->heartbeat($holder, $claimed->executionUuid, $claimed->leaseToken, $claimed->generation);
      $this->fail('An expired lease was renewed.');
    }
    catch (LeaseRejectedException $exception) {
      $this->assertSame(LeaseRejectedException::EXPIRED, $exception->getMessage());
      $this->assertStringNotContainsString($secret, $exception->getMessage());
    }
    $stored = $this->reload($task);
    $this->assertSame('ready', $stored->get('status')->getString());
    $this->assertSame('', $stored->get('active_execution')->getString());
    $this->assertSame('', $stored->get('assigned_agent')->getString());
    $this->assertSame('interrupted', $this->execution($claimed)->get('status')->getString());
    $remaining = array_filter($this->rows('ab_execution_lease'), static fn (array $row): bool => (int) $row['task_id'] === (int) $stored->id());
    $this->assertSame([], array_values($remaining));
    $events = $this->eventsOf('execution.interrupted');
    $this->assertCount($interrupted + 1, $events);
    $this->assertSame((string) $agent->uuid(), $events[array_key_last($events)]['actor_id']);
    $this->assertStringNotContainsString($secret, (string) $events[0]['payload']);
    $this->assertSame(0, $this->leases()->recover());
  }

  /**
   * Fail and release return the task once and keep a blocker in place.
   */
  public function testFailAndReleaseKeepBlockers(): void {
    $ada = $this->createUserAccount('ada2', 'ada2@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Falha', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', '');
    $agent = $this->agents()->create($workspace, 'Revisor', '', '', TRUE, 3);
    $other = $this->agents()->create($workspace, 'Reserva', '', '', TRUE, 1);
    $this->grants()->grant($agent, $board, [WorkspacePermission::WRITE]);
    $this->grants()->grant($other, $board, [WorkspacePermission::WRITE]);
    $issued = $this->tokens()->issue($agent, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
    ]);
    $otherToken = $this->tokens()->issue($other, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
    ]);
    $holder = $this->actorFor($agent, $workspace, $issued->publicId);
    $failed = $this->claim($holder, $board, 'Quebrou');
    try {
      $this->leases()->fail($holder, $failed->executionUuid, $failed->leaseToken, $failed->generation, '');
      $this->fail('An empty failure code was stored.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame('in_progress', $this->reloadByUuid($failed->taskUuid)->get('status')->getString());
    }
    $this->leases()->fail($holder, $failed->executionUuid, $failed->leaseToken, $failed->generation, 'runner_stopped');
    $this->assertSame('failed', $this->execution($failed)->get('status')->getString());
    $this->assertSame('runner_stopped', $this->execution($failed)->get('failure_code')->getString());
    $this->assertSame('ready', $this->reloadByUuid($failed->taskUuid)->get('status')->getString());
    $failedEvents = $this->eventsOf('execution.failed');
    $this->assertCount(1, $failedEvents);
    $this->assertStringNotContainsString($this->secret($failed), (string) $failedEvents[0]['payload']);
    try {
      $this->leases()->fail($holder, $failed->executionUuid, $failed->leaseToken, $failed->generation, 'runner_stopped');
      $this->fail('A second fail recorded another event.');
    }
    catch (LeaseRejectedException $exception) {
      $this->assertSame(LeaseRejectedException::STATE, $exception->getMessage());
      $this->assertCount(1, $this->eventsOf('execution.failed'));
    }

    $released = $this->claim($holder, $board, 'Solta');
    $this->leases()->release($holder, $released->executionUuid, $released->leaseToken, $released->generation);
    $this->assertSame('interrupted', $this->execution($released)->get('status')->getString());
    $this->assertSame('', $this->execution($released)->get('failure_code')->getString());
    $releasedTask = $this->reloadByUuid($released->taskUuid);
    $this->assertSame('ready', $releasedTask->get('status')->getString());
    $this->assertSame('', $releasedTask->get('assigned_agent')->getString());
    $again = $this->claims()->claim($this->actorFor($other, $workspace, $otherToken->publicId), $released->taskUuid);
    $this->assertSame('2', $this->execution($again)->get('attempt')->getString());
    $this->leases()->release($this->actorFor($other, $workspace, $otherToken->publicId), $again->executionUuid, $again->leaseToken, $again->generation);

    $predecessor = $this->readyTask($board, 'Antes');
    $blocked = $this->claim($holder, $board, 'Depois');
    $blockedTask = $this->reloadByUuid($blocked->taskUuid);
    $this->dependencies()->add($blockedTask, $predecessor, (int) $blockedTask->get('version')->getString());
    $this->leases()->release($holder, $blocked->executionUuid, $blocked->leaseToken, $blocked->generation);
    $this->assertCount(1, $this->rows('ab_task_dependency'));
    try {
      $this->claims()->claim($holder, $blocked->taskUuid);
      $this->fail('A blocker disappeared with the lease.');
    }
    catch (ClaimRejectedException $exception) {
      $this->assertSame(ClaimRejectedException::BLOCKED, $exception->getMessage());
      $this->assertSame('ready', $this->reloadByUuid($blocked->taskUuid)->get('status')->getString());
    }

    $rollback = $this->claim($holder, $board, 'Volta');
    $before = $this->leaseExpiry($this->reloadByUuid($rollback->taskUuid));
    $failing = new class (
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get(ExecutionWriter::class),
      $this->container->get('Drupal\ab_agent\AgentOperationGate'),
      $this->container->get('cache_tags.invalidator'),
    ) extends ExecutionMaintenance {

      /**
       * {@inheritdoc}
       */
      protected function afterRecord(LeaseMutation $mutation): void {
        throw new \RuntimeException('stop');
      }

    };
    try {
      $failing->heartbeat($holder, $rollback->executionUuid, $rollback->leaseToken, $rollback->generation);
      $this->fail('A failed heartbeat was stored.');
    }
    catch (LeaseRejectedException $exception) {
      $this->fail($exception->getMessage());
    }
    catch (\RuntimeException) {
      $this->assertSame($before, $this->leaseExpiry($this->reloadByUuid($rollback->taskUuid)));
      $this->assertSame('', $this->execution($rollback)->get('heartbeat_at')->getString());
      $this->assertSame('in_progress', $this->reloadByUuid($rollback->taskUuid)->get('status')->getString());
    }
  }

  /**
   * The recovery command interrupts once and ignores a second pass.
   */
  public function testRecoveryRunsOnce(): void {
    $ada = $this->createUserAccount('ada3', 'ada3@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Worker', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', '');
    $agent = $this->agents()->create($workspace, 'Revisor', '', '', TRUE, 1);
    $this->grants()->grant($agent, $board, [WorkspacePermission::WRITE]);
    $issued = $this->tokens()->issue($agent, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
    ]);
    $holder = $this->actorFor($agent, $workspace, $issued->publicId);
    $claimed = $this->claim($holder, $board, 'Esqueceu');
    $this->clock->advance(ClaimWriter::LEASE_TTL);
    $this->assertSame(1, $this->leases()->recover());
    $task = $this->reloadByUuid($claimed->taskUuid);
    $this->assertSame('ready', $task->get('status')->getString());
    $version = (int) $task->get('version')->getString();
    $events = $this->eventsOf('execution.interrupted');
    $this->assertCount(1, $events);
    $this->assertSame('lease-recovery', $events[0]['actor_id']);
    $this->assertSame('system', $events[0]['actor_type']);
    $this->assertStringNotContainsString($this->secret($claimed), (string) $events[0]['payload']);
    $command = new LeaseRecoveryCommands($this->leases());
    $command->recover();
    $this->assertSame(0, $this->leases()->recover());
    $this->assertSame($version, (int) $this->reload($task)->get('version')->getString());
    $this->assertCount(1, $this->eventsOf('execution.interrupted'));
    $next = $this->claims()->claim($holder, $claimed->taskUuid);
    $this->assertSame('2', $this->execution($next)->get('attempt')->getString());
    $this->assertSame('interrupted', $this->execution($claimed)->get('status')->getString());
  }

  /**
   * An old lease cannot write over the execution that replaced it.
   */
  public function testStaleExecutorCannotChangeTheNewExecution(): void {
    $ada = $this->createUserAccount('ada4', 'ada4@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Velha', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', '');
    $agent = $this->agents()->create($workspace, 'Revisor', '', '', TRUE, 1);
    $this->grants()->grant($agent, $board, [WorkspacePermission::WRITE]);
    $issued = $this->tokens()->issue($agent, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
    ]);
    $holder = $this->actorFor($agent, $workspace, $issued->publicId);
    $first = $this->claim($holder, $board, 'Primeira');
    $this->clock->advance(ClaimWriter::LEASE_TTL);
    $this->assertSame(1, $this->leases()->recover());
    $second = $this->claims()->claim($holder, $first->taskUuid);
    $version = (int) $this->reloadByUuid($second->taskUuid)->get('version')->getString();
    try {
      $this->leases()->heartbeat($holder, $second->executionUuid, $first->leaseToken, $first->generation);
      $this->fail('The old lease renewed the new execution.');
    }
    catch (LeaseRejectedException $exception) {
      $this->assertSame(LeaseRejectedException::TOKEN, $exception->getMessage());
    }
    try {
      $this->leases()->submit($holder, $second->executionUuid, $first->leaseToken, $first->generation);
      $this->fail('The old lease delivered the new execution.');
    }
    catch (LeaseRejectedException $exception) {
      $this->assertSame(LeaseRejectedException::TOKEN, $exception->getMessage());
    }
    try {
      $this->leases()->heartbeat($holder, $first->executionUuid, $first->leaseToken, $first->generation);
      $this->fail('The old execution accepted a heartbeat.');
    }
    catch (LeaseRejectedException $exception) {
      $this->assertSame(LeaseRejectedException::TOKEN, $exception->getMessage());
    }
    $task = $this->reloadByUuid($second->taskUuid);
    $this->assertSame('in_progress', $task->get('status')->getString());
    $this->assertSame($version, (int) $task->get('version')->getString());
    $this->assertSame((string) $this->execution($second)->id(), $task->get('active_execution')->getString());
    $this->assertSame('', $this->execution($second)->get('heartbeat_at')->getString());
    $this->assertSame('interrupted', $this->execution($first)->get('status')->getString());
    $renewed = $this->leases()->heartbeat($holder, $second->executionUuid, $second->leaseToken, $second->generation);
    $this->assertSame($second->executionUuid, $renewed->executionUuid);
    $this->assertSame('in_progress', $this->reload($task)->get('status')->getString());
  }

  /**
   * Claims a ready task for the holder.
   */
  private function claim(ActorContext $actor, Board $board, string $title): ClaimedTask {
    $task = $this->readyTask($board, $title);
    return $this->claims()->claim($actor, (string) $task->uuid());
  }

  /**
   * Returns the secret half of a lease token.
   */
  private function secret(ClaimedTask $claimed): string {
    return substr($claimed->leaseToken, (int) strrpos($claimed->leaseToken, '.') + 1);
  }

  /**
   * Returns the stored lease expiry for a task.
   */
  private function leaseExpiry(Task $task): int {
    $expires = $this->container->get('database')->select('ab_execution_lease', 'l')
      ->fields('l', ['expires_at'])
      ->condition('task_id', $task->id())
      ->execute()
      ->fetchField();
    return (int) $expires;
  }

  /**
   * Creates a task and moves it to ready.
   */
  private function readyTask(Board $board, string $title): Task {
    $task = $this->tasks()->create($board, ['title' => $title]);
    return $this->tasks()->transition($task, 'ready', 1);
  }

  /**
   * Reloads a task after a direct write.
   */
  private function reload(Task $task): Task {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_task');
    $storage->resetCache([(int) $task->id()]);
    $reloaded = $storage->load($task->id());
    $this->assertInstanceOf(Task::class, $reloaded);
    return $reloaded;
  }

  /**
   * Reloads a task by its public uuid.
   */
  private function reloadByUuid(string $uuid): Task {
    $ids = $this->container->get('entity_type.manager')->getStorage('ab_task')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid)
      ->execute();
    $task = $this->container->get('entity_type.manager')->getStorage('ab_task')->load(reset($ids) ?: 0);
    $this->assertInstanceOf(Task::class, $task);
    return $this->reload($task);
  }

  /**
   * Loads the execution returned by a claim.
   */
  private function execution(ClaimedTask $claimed): Execution {
    $ids = $this->container->get('entity_type.manager')->getStorage('ab_execution')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $claimed->executionUuid)
      ->execute();
    $execution = $this->container->get('entity_type.manager')->getStorage('ab_execution')->load(reset($ids) ?: 0);
    $this->assertInstanceOf(Execution::class, $execution);
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_execution');
    $storage->resetCache([(int) $execution->id()]);
    $reloaded = $storage->load($execution->id());
    $this->assertInstanceOf(Execution::class, $reloaded);
    return $reloaded;
  }

  /**
   * Returns every row of a table.
   *
   * @return array<int, array<string, mixed>>
   *   Table rows.
   */
  private function rows(string $table): array {
    $rows = $this->container->get('database')->select($table, 't')
      ->fields('t')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    return array_values($rows);
  }

  /**
   * Returns events of one type.
   *
   * @return array<int, array<string, mixed>>
   *   Event rows.
   */
  private function eventsOf(string $type): array {
    $rows = $this->container->get('database')->select('ab_event', 'e')
      ->fields('e')
      ->condition('type', $type)
      ->orderBy('sequence')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    return array_values($rows);
  }

  /**
   * Creates an active user.
   */
  private function createUserAccount(string $name, string $mail): User {
    $user = User::create([
      'name' => $name,
      'mail' => $mail,
      'status' => 1,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Builds an agent actor from the issued token.
   */
  private function actorFor(Agent $agent, Workspace $workspace, string $publicId): ActorContext {
    return new ActorContext(
      ActorContext::TYPE_AGENT,
      (string) $agent->uuid(),
      (string) $workspace->uuid(),
      [],
      $publicId,
    );
  }

  /**
   * Loads the workspace creator.
   */
  private function creator(): WorkspaceCreator {
    $creator = $this->container->get(WorkspaceCreator::class);
    $this->assertInstanceOf(WorkspaceCreator::class, $creator);
    return $creator;
  }

  /**
   * Loads the agent manager.
   */
  private function agents(): AgentManager {
    $manager = $this->container->get(AgentManager::class);
    $this->assertInstanceOf(AgentManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the token manager.
   */
  private function tokens(): AgentTokenManager {
    $manager = $this->container->get(AgentTokenManager::class);
    $this->assertInstanceOf(AgentTokenManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the grant manager.
   */
  private function grants(): AgentGrantManager {
    $manager = $this->container->get(AgentGrantManager::class);
    $this->assertInstanceOf(AgentGrantManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the board manager.
   */
  private function boards(): BoardManager {
    $manager = $this->container->get(BoardManager::class);
    $this->assertInstanceOf(BoardManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the task manager.
   */
  private function tasks(): TaskManager {
    $manager = $this->container->get(TaskManager::class);
    $this->assertInstanceOf(TaskManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the dependency manager.
   */
  private function dependencies(): TaskDependencyManager {
    $manager = $this->container->get(TaskDependencyManager::class);
    $this->assertInstanceOf(TaskDependencyManager::class, $manager);
    return $manager;
  }

  /**
   * Loads the claim service.
   */
  private function claims(): TaskClaim {
    $claims = $this->container->get(TaskClaim::class);
    $this->assertInstanceOf(TaskClaim::class, $claims);
    return $claims;
  }

  /**
   * Loads the lease service.
   */
  private function leases(): ExecutionMaintenance {
    $leases = $this->container->get(ExecutionMaintenance::class);
    $this->assertInstanceOf(ExecutionMaintenance::class, $leases);
    return $leases;
  }

  /**
   * Authenticates the account.
   */
  private function setCurrentUser(User $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

}
