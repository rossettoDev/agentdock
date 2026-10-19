<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_agent\Kernel;

use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentRuntimeAccount;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\ClaimedTask;
use Drupal\ab_agent\ClaimWriter;
use Drupal\ab_agent\Entity\Execution;
use Drupal\ab_agent\ExecutionMaintenance;
use Drupal\ab_agent\ExecutionRejectedException;
use Drupal\ab_agent\ExecutionWriter;
use Drupal\ab_agent\LeaseRejectedException;
use Drupal\ab_agent\TaskClaim;
use Drupal\ab_agent\TaskReview;
use Drupal\ab_agent\TaskReviewRejectedException;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskDependencyManager;
use Drupal\ab_board\TaskManager;
use Drupal\ab_board\TaskTransitionRejectedException;
use Drupal\ab_board\TaskVersionConflictException;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Verifies delivery ends the lease and a human decides the task.
 *
 * @group ab_agent
 */
class ReviewKernelTest extends KernelTestBase {

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
      'ab_execution_progress',
      'ab_execution_link',
    ]);
    $this->clock = new FrozenClock(1700000000);
    $this->container->set('datetime.time', $this->clock);
  }

  /**
   * Progress is limited and submit moves the task to review.
   */
  public function testProgressAndSubmitEndTheLease(): void {
    $this->assertSame(20, ExecutionWriter::PROGRESS_LIMIT);
    [, , $board, $agent, $holder] = $this->fixture('nota');
    $claimed = $this->claim($holder, $board, 'Tema');
    $secret = $this->secret($claimed);
    $task = $this->reloadByUuid($claimed->taskUuid);
    $version = (int) $task->get('version')->getString();

    try {
      $this->leases()->progress($holder, $claimed->executionUuid, $claimed->leaseToken, 1, '<script>');
      $this->fail('A markup note was stored.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame(0, $this->progressCount($claimed));
    }
    try {
      $this->leases()->submit($holder, $claimed->executionUuid, $claimed->leaseToken, 1, '', [
        ['url' => 'javascript:alert(1)', 'label' => 'ruim'],
      ]);
      $this->fail('A script link was stored.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame('in_progress', $this->reload($task)->get('status')->getString());
      $this->assertSame($version, (int) $this->reload($task)->get('version')->getString());
    }
    try {
      $this->leases()->submit($holder, $claimed->executionUuid, 'ab_lease_' . str_repeat('a', 32) . '.' . str_repeat('b', 64), 1, 'Resumo');
      $this->fail('A stale lease delivered the result.');
    }
    catch (LeaseRejectedException $exception) {
      $this->assertSame(LeaseRejectedException::TOKEN, $exception->getMessage());
      $this->assertStringNotContainsString($secret, $exception->getMessage());
      $this->assertSame('', $this->execution($claimed)->get('result_summary')->getString());
    }

    for ($index = 1; $index <= ExecutionWriter::PROGRESS_LIMIT; $index++) {
      $this->leases()->progress($holder, $claimed->executionUuid, $claimed->leaseToken, 1, 'Nota ' . $index);
    }
    try {
      $this->leases()->progress($holder, $claimed->executionUuid, $claimed->leaseToken, 1, 'Nota extra');
      $this->fail('Progress passed the limit.');
    }
    catch (ExecutionRejectedException $exception) {
      $this->assertSame(ExecutionRejectedException::PROGRESS, $exception->getMessage());
    }
    $stored = $this->reload($task);
    $this->assertSame('in_progress', $stored->get('status')->getString());
    $this->assertSame($version, (int) $stored->get('version')->getString());
    $this->assertSame(ExecutionWriter::PROGRESS_LIMIT, $this->progressCount($claimed));
    $this->assertNotSame('', $stored->get('active_execution')->getString());

    $summary = "Pronto\n<script>alert(1)</script>";
    $this->leases()->submit($holder, $claimed->executionUuid, $claimed->leaseToken, 1, $summary, [
      ['url' => 'https://example.com/resultado', 'label' => 'Resultado'],
    ]);
    $delivered = $this->reload($task);
    $this->assertSame('in_review', $delivered->get('status')->getString());
    $this->assertSame($version + 1, (int) $delivered->get('version')->getString());
    $this->assertSame((string) $agent->id(), $delivered->get('assigned_agent')->getString());
    $this->assertNotSame('', $delivered->get('active_execution')->getString());
    $execution = $this->execution($claimed);
    $this->assertSame('submitted', $execution->get('status')->getString());
    $this->assertSame($summary, $execution->get('result_summary')->getString());
    $this->assertSame([], $this->leaseRows((int) $delivered->id()));
    $links = $this->rows('ab_execution_link');
    $this->assertCount(1, $links);
    $this->assertSame('https://example.com/resultado', $links[0]['url']);
    $events = $this->eventsOf('execution.submitted');
    $this->assertCount(1, $events);
    $this->assertSame((string) $agent->uuid(), $events[0]['actor_id']);
    $this->assertStringNotContainsString($secret, (string) $events[0]['payload']);
    $this->assertStringNotContainsString($secret, (string) $this->eventsOf('execution.progressed')[0]['payload']);
    try {
      $this->leases()->submit($holder, $claimed->executionUuid, $claimed->leaseToken, 1, $summary);
      $this->fail('A second submit wrote another result.');
    }
    catch (LeaseRejectedException $exception) {
      $this->assertSame(LeaseRejectedException::STATE, $exception->getMessage());
    }
    try {
      $this->tasks()->transition($this->reload($task), 'done', $version + 1);
      $this->fail('A human transition approved the delivery.');
    }
    catch (TaskTransitionRejectedException) {
      $this->assertSame('in_review', $this->reload($task)->get('status')->getString());
    }

    $expired = $this->claim($holder, $board, 'Vencida');
    $this->clock->advance(ClaimWriter::LEASE_TTL);
    try {
      $this->leases()->submit($holder, $expired->executionUuid, $expired->leaseToken, 1, 'Tarde');
      $this->fail('An expired lease delivered the result.');
    }
    catch (LeaseRejectedException $exception) {
      $this->assertSame(LeaseRejectedException::EXPIRED, $exception->getMessage());
    }
    $closed = $this->reloadByUuid($expired->taskUuid);
    $this->assertSame('ready', $closed->get('status')->getString());
    $this->assertSame('', $closed->get('active_execution')->getString());
    $this->assertSame('', $this->execution($expired)->get('result_summary')->getString());
    $this->assertSame('interrupted', $this->execution($expired)->get('status')->getString());
  }

  /**
   * Approval, rejection and cancellation belong to the human.
   */
  public function testHumanReviewKeepsTheAttempt(): void {
    [$ada, $workspace, $board, , $holder] = $this->fixture('revisao');
    $viewer = $this->createUserAccount('viewer-revisao', 'viewer-revisao@example.com');
    $outsider = $this->createUserAccount('fora-revisao', 'fora-revisao@example.com');
    $this->setCurrentUser($ada);
    $members = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $members);
    $members->addMember($workspace, $viewer->getAccountName(), 'viewer');

    $claimed = $this->claim($holder, $board, 'Entrega');
    $secret = $this->secret($claimed);
    $this->leases()->submit($holder, $claimed->executionUuid, $claimed->leaseToken, 1, 'Entregue', []);
    $open = $this->reloadByUuid($claimed->taskUuid);
    $predecessor = $this->readyTask($board, 'Antes');
    $open = $this->dependencies()->add($open, $predecessor, (int) $open->get('version')->getString());
    $version = (int) $open->get('version')->getString();
    $edges = count($this->rows('ab_task_dependency'));

    $this->setCurrentUser($outsider);
    try {
      $this->reviews()->approve((string) $open->uuid(), $version);
      $this->fail('An outsider approved the delivery.');
    }
    catch (ScopedResourceNotFoundException $exception) {
      $this->assertSame('Task was not found.', $exception->getMessage());
      $this->assertStringNotContainsString('Entrega', $exception->getMessage());
    }
    $this->setCurrentUser($viewer);
    try {
      $this->reviews()->approve((string) $open->uuid(), $version);
      $this->fail('A viewer approved the delivery.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame('in_review', $this->reload($open)->get('status')->getString());
    }
    $runtime = $this->runtimeAccount();
    $this->setCurrentUser($runtime);
    try {
      $this->reviews()->approve((string) $open->uuid(), $version);
      $this->fail('The agent account approved the delivery.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame('in_review', $this->reload($open)->get('status')->getString());
    }

    $this->setCurrentUser($ada);
    try {
      $this->reviews()->approve((string) $open->uuid(), $version - 1);
      $this->fail('A stale form approved the delivery.');
    }
    catch (TaskVersionConflictException) {
      $this->assertSame('in_review', $this->reload($open)->get('status')->getString());
    }
    $failing = new class (
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('Drupal\ab_core\Authorization\MembershipScope'),
      $this->container->get('Drupal\ab_core\Authorization\HumanActorContextFactory'),
      $this->container->get('Drupal\ab_event\EventStoreInterface'),
      $this->container->get('datetime.time'),
      $this->container->get('cache_tags.invalidator'),
    ) extends TaskReview {

      /**
       * {@inheritdoc}
       */
      protected function afterRecord(): void {
        throw new \RuntimeException('stop');
      }

    };
    try {
      $failing->approve((string) $open->uuid(), $version);
      $this->fail('A rolled back approval was stored.');
    }
    catch (TaskReviewRejectedException $exception) {
      $this->fail($exception->getMessage());
    }
    catch (\RuntimeException) {
      $this->assertSame('in_review', $this->reload($open)->get('status')->getString());
      $this->assertSame([], $this->eventsOf('task.approved'));
    }

    $this->reviews()->approve((string) $open->uuid(), $version);
    $done = $this->reload($open);
    $this->assertSame('done', $done->get('status')->getString());
    $this->assertSame('', $done->get('active_execution')->getString());
    $this->assertSame('', $done->get('assigned_agent')->getString());
    $this->assertSame('submitted', $this->execution($claimed)->get('status')->getString());
    $this->assertCount($edges, $this->rows('ab_task_dependency'));
    $approved = $this->eventsOf('task.approved');
    $this->assertCount(1, $approved);
    $this->assertSame('human', $approved[0]['actor_type']);
    $this->assertSame((string) $ada->id(), $approved[0]['actor_id']);
    $this->assertStringNotContainsString($secret, (string) $approved[0]['payload']);
    try {
      $this->reviews()->approve((string) $open->uuid(), $version + 1);
      $this->fail('A second approval recorded another event.');
    }
    catch (TaskReviewRejectedException) {
      $this->assertCount(1, $this->eventsOf('task.approved'));
    }

    $rejected = $this->claim($holder, $board, 'Correcao');
    $this->leases()->submit($holder, $rejected->executionUuid, $rejected->leaseToken, 1, 'Quase', []);
    $waiting = $this->reloadByUuid($rejected->taskUuid);
    $rejectVersion = (int) $waiting->get('version')->getString();
    try {
      $this->reviews()->reject((string) $waiting->uuid(), $rejectVersion, '   ');
      $this->fail('An empty reason rejected the delivery.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame('in_review', $this->reload($waiting)->get('status')->getString());
    }
    $this->reviews()->reject((string) $waiting->uuid(), $rejectVersion, 'Falta o criterio.');
    $ready = $this->reload($waiting);
    $this->assertSame('ready', $ready->get('status')->getString());
    $this->assertSame('', $ready->get('active_execution')->getString());
    $this->assertSame('submitted', $this->execution($rejected)->get('status')->getString());
    $this->assertSame('Quase', $this->execution($rejected)->get('result_summary')->getString());
    $rejection = $this->eventsOf('task.rejected');
    $this->assertCount(1, $rejection);
    $this->assertStringContainsString('Falta o criterio.', (string) $rejection[0]['payload']);
    $this->assertStringNotContainsString($this->secret($rejected), (string) $rejection[0]['payload']);
    $again = $this->claims()->claim($holder, (string) $ready->uuid());
    $attempts = $this->container->get('database')->select('ab_execution', 'e')
      ->fields('e', ['attempt', 'status'])
      ->condition('task', $ready->id())
      ->orderBy('attempt')
      ->execute()
      ->fetchAll();
    $this->assertCount(2, $attempts);
    $this->assertSame('1', (string) $attempts[0]->attempt);
    $this->assertSame('submitted', (string) $attempts[0]->status);
    $this->assertSame('2', (string) $attempts[1]->attempt);
    $this->assertSame('running', (string) $attempts[1]->status);
    $this->assertNotSame($rejected->executionUuid, $again->executionUuid);

    $running = $this->claim($holder, $board, 'Parar');
    $live = $this->reloadByUuid($running->taskUuid);
    $this->reviews()->cancel((string) $live->uuid(), (int) $live->get('version')->getString());
    $cancelled = $this->reload($live);
    $this->assertSame('cancelled', $cancelled->get('status')->getString());
    $this->assertSame('', $cancelled->get('active_execution')->getString());
    $this->assertSame('cancelled', $this->execution($running)->get('status')->getString());
    $this->assertSame([], $this->leaseRows((int) $cancelled->id()));
    $this->assertCount(1, $this->eventsOf('execution.cancelled'));
    try {
      $this->reviews()->cancel((string) $live->uuid(), (int) $cancelled->get('version')->getString());
      $this->fail('A second cancel recorded another event.');
    }
    catch (TaskReviewRejectedException) {
      $this->assertCount(1, $this->eventsOf('execution.cancelled'));
    }
  }

  /**
   * Builds an owner, a board and one agent that can claim.
   *
   * @return array{0: \Drupal\user\UserInterface, 1: \Drupal\ab_core\Entity\Workspace, 2: \Drupal\ab_board\Entity\Board, 3: \Drupal\ab_agent\Entity\Agent, 4: \Drupal\ab_core\Authorization\ActorContext}
   *   The fixture pieces.
   */
  private function fixture(string $suffix): array {
    $ada = $this->createUserAccount('ada-' . $suffix, 'ada-' . $suffix . '@example.com');
    $workspace = $this->creator()->create($ada, 'Casa ' . $suffix, 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', '');
    $agent = $this->agents()->create($workspace, 'Revisor', '', '', TRUE, 3);
    $this->grants()->grant($agent, $board, [WorkspacePermission::WRITE]);
    $issued = $this->tokens()->issue($agent, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
    ]);
    $holder = new ActorContext(
      ActorContext::TYPE_AGENT,
      (string) $agent->uuid(),
      (string) $workspace->uuid(),
      [],
      $issued->publicId,
    );
    return [$ada, $workspace, $board, $agent, $holder];
  }

  /**
   * Claims a ready task for the holder.
   */
  private function claim(ActorContext $actor, Board $board, string $title): ClaimedTask {
    $task = $this->readyTask($board, $title);
    return $this->claims()->claim($actor, (string) $task->uuid());
  }

  /**
   * Creates a task and moves it to ready.
   */
  private function readyTask(Board $board, string $title): Task {
    $task = $this->tasks()->create($board, ['title' => $title]);
    return $this->tasks()->transition($task, 'ready', 1);
  }

  /**
   * Returns the secret half of a lease token.
   */
  private function secret(ClaimedTask $claimed): string {
    return substr($claimed->leaseToken, (int) strrpos($claimed->leaseToken, '.') + 1);
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
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_execution');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $claimed->executionUuid)
      ->execute();
    $storage->resetCache();
    $execution = $storage->load(reset($ids) ?: 0);
    $this->assertInstanceOf(Execution::class, $execution);
    return $execution;
  }

  /**
   * Counts progress notes for one claim.
   */
  private function progressCount(ClaimedTask $claimed): int {
    $execution = $this->execution($claimed);
    return (int) $this->container->get('database')->select('ab_execution_progress', 'p')
      ->condition('execution_id', $execution->id())
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Returns lease rows for one task.
   *
   * @return array<int, array<string, mixed>>
   *   Lease rows.
   */
  private function leaseRows(int $taskId): array {
    $rows = $this->container->get('database')->select('ab_execution_lease', 'l')
      ->fields('l')
      ->condition('task_id', $taskId)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    return array_values($rows);
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
   * Loads the blocked account used by bearer requests.
   */
  private function runtimeAccount(): UserInterface {
    return AgentRuntimeAccount::ensure();
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
   * Loads the human review service.
   */
  private function reviews(): TaskReview {
    $reviews = $this->container->get(TaskReview::class);
    $this->assertInstanceOf(TaskReview::class, $reviews);
    return $reviews;
  }

  /**
   * Authenticates the account.
   */
  private function setCurrentUser(UserInterface $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

}
