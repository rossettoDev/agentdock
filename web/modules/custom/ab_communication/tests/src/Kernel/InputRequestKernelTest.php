<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_communication\Kernel;

use Drupal\ab_agent\AgentAccessDeniedException;
use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\ClaimedTask;
use Drupal\ab_agent\ClaimWriter;
use Drupal\ab_agent\ExecutionMaintenance;
use Drupal\ab_agent\LeaseRejectedException;
use Drupal\ab_agent\TaskClaim;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskManager;
use Drupal\ab_communication\InputRequestRejectedException;
use Drupal\ab_communication\InputRequestService;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_event\EventStoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Verifies that a question waits and a late answer does not revive it.
 *
 * @group ab_communication
 */
class InputRequestKernelTest extends KernelTestBase {

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
    'ab_communication',
  ];

  /**
   * Clock used by the lease and the request.
   */
  private InputClock $clock;

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
    $this->installSchema('ab_communication', [
      'ab_thread',
      'ab_message',
      'ab_input_request',
    ]);
    $this->clock = new InputClock(1700000000);
    $this->container->set('datetime.time', $this->clock);
  }

  /**
   * Waiting lasts until every open question of the live lease is answered.
   */
  public function testWaitingStaysUntilEveryQuestionIsAnswered(): void {
    [$ada, $workspace, $board, , $holder] = $this->fixture('espera');
    $task = $this->readyTask($board, 'Tema');
    $claimed = $this->claims()->claim($holder, (string) $task->uuid());
    $version = $this->taskVersion($task);
    $first = $this->requests()->request($holder, $claimed->executionUuid, $claimed->leaseToken, 1, 'Qual o prazo?');
    $second = $this->requests()->request($holder, $claimed->executionUuid, $claimed->leaseToken, 1, 'Qual o formato?');
    $this->assertSame('waiting_input', $this->executionStatus($claimed));
    $this->assertSame('in_progress', $this->taskStatus($task));
    $this->assertSame($version, $this->taskVersion($task));
    $this->assertNotNull($this->leaseExpiry($task));
    $this->assertSame(2, $this->openCount($claimed));
    $this->assertSame('question', $this->messageKind($first->messageUuid));
    $this->clock->advance(1);
    $renewed = $this->leases()->heartbeat($holder, $claimed->executionUuid, $claimed->leaseToken, 1);
    $this->assertGreaterThan($claimed->leaseExpiresAt, $renewed->leaseExpiresAt);
    $this->human($ada, $workspace);
    $this->requests()->answer($this->human($ada, $workspace), $first->requestUuid, 'Amanhã');
    $this->assertSame('waiting_input', $this->executionStatus($claimed));
    $this->assertSame(1, $this->openCount($claimed));
    $this->requests()->answer($this->human($ada, $workspace), $second->requestUuid, 'Texto');
    $this->assertSame('running', $this->executionStatus($claimed));
    $this->assertSame('in_progress', $this->taskStatus($task));
    $this->assertSame(0, $this->openCount($claimed));
    $this->assertNotNull($this->leaseExpiry($task));
    $asked = $this->eventsOf('input.requested');
    $this->assertCount(2, $asked);
    $this->assertStringNotContainsString('Qual o prazo?', (string) $asked[0]['payload']);
    $this->assertStringNotContainsString('Amanhã', (string) $this->eventsOf('input.answered')[0]['payload']);
    $this->assertStringNotContainsString('Qual o prazo?', (string) $this->eventsOf('message.created')[0]['payload']);
    $views = $this->requests()->forTask($this->human($ada, $workspace), $this->reload($task));
    $this->assertCount(2, $views);
    $this->assertSame('answered', $views[0]->status);
    $this->assertSame('Amanhã', $views[0]->answer);
    $this->assertSame((string) $ada->id(), $views[0]->authorId);
  }

  /**
   * An answer after the lease expired stays in history.
   */
  public function testExpiredExecutionDoesNotRevive(): void {
    [$ada, $workspace, $board, , $holder] = $this->fixture('tarde');
    $task = $this->readyTask($board, 'Entrega');
    $claimed = $this->claims()->claim($holder, (string) $task->uuid());
    $opened = $this->requests()->request($holder, $claimed->executionUuid, $claimed->leaseToken, 1, 'Posso publicar?');
    $this->clock->advance(ClaimWriter::LEASE_TTL + 1);
    try {
      $this->requests()->request($holder, $claimed->executionUuid, $claimed->leaseToken, 1, 'Ainda dá tempo?');
      $this->fail('An expired lease opened another question.');
    }
    catch (LeaseRejectedException $exception) {
      $this->assertSame(LeaseRejectedException::EXPIRED, $exception->getMessage());
    }
    $this->assertSame('interrupted', $this->executionStatus($claimed));
    $this->assertSame('ready', $this->taskStatus($task));
    $this->assertNull($this->leaseExpiry($task));
    $this->assertSame(1, $this->openCount($claimed));
    $this->assertSame(0, $this->messageCount('Ainda dá tempo?'));
    $this->requests()->answer($this->human($ada, $workspace), $opened->requestUuid, 'Pode, na próxima tentativa');
    $this->assertSame('interrupted', $this->executionStatus($claimed));
    $this->assertSame('ready', $this->taskStatus($task));
    $this->assertNull($this->leaseExpiry($task));
    $this->assertSame(0, $this->openCount($claimed));
    $again = $this->claims()->claim($holder, (string) $task->uuid());
    $this->assertNotSame($claimed->executionUuid, $again->executionUuid);
    $history = $this->requests()->forTask($this->human($ada, $workspace), $this->reload($task));
    $this->assertSame('Pode, na próxima tentativa', $history[0]->answer);
    $this->assertSame('answered', $history[0]->status);
  }

  /**
   * The actor and the lease decide who may ask or answer.
   */
  public function testOutsidersCannotForgeTheRequest(): void {
    [$ada, $workspace, $board, $agent, $holder] = $this->fixture('fora');
    $viewer = $this->createUserAccount('viewer-fora', 'viewer-fora@example.com');
    $this->setCurrentUser($ada);
    $members = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $members);
    $members->addMember($workspace, $viewer->getAccountName(), 'viewer');
    $task = $this->readyTask($board, 'Tema');
    $claimed = $this->claims()->claim($holder, (string) $task->uuid());
    try {
      $this->requests()->request($this->human($ada, $workspace), $claimed->executionUuid, $claimed->leaseToken, 1, 'Humano pergunta');
      $this->fail('A human opened an input request.');
    }
    catch (AgentAccessDeniedException) {
      $this->assertSame(0, $this->requestCount());
    }
    $this->setCurrentUser($ada);
    $narrow = $this->tokens()->issue($agent, [AgentTokenScope::EXECUTIONS_WRITE]);
    $reader = new ActorContext(ActorContext::TYPE_AGENT, (string) $agent->uuid(), (string) $workspace->uuid(), [], $narrow->publicId);
    try {
      $this->requests()->request($reader, $claimed->executionUuid, $claimed->leaseToken, 1, 'Sem mensagem');
      $this->fail('A token without message scope opened a question.');
    }
    catch (AgentAccessDeniedException) {
      $this->assertSame(0, $this->requestCount());
    }
    try {
      $this->requests()->request($holder, $claimed->executionUuid, $claimed->leaseToken, 9, 'Geração');
      $this->fail('A stale generation opened a question.');
    }
    catch (LeaseRejectedException $exception) {
      $this->assertSame(LeaseRejectedException::GENERATION, $exception->getMessage());
      $this->assertSame('running', $this->executionStatus($claimed));
    }
    $opened = $this->requests()->request($holder, $claimed->executionUuid, $claimed->leaseToken, 1, '<script>alert(1)</script>');
    try {
      $this->requests()->answer($holder, $opened->requestUuid, 'Agente responde');
      $this->fail('The agent stored an answer.');
    }
    catch (InputRequestRejectedException $exception) {
      $this->assertSame(InputRequestRejectedException::ACTOR, $exception->getMessage());
      $this->assertSame(1, $this->openCount($claimed));
    }
    try {
      $this->requests()->answer($this->human($viewer, $workspace), $opened->requestUuid, 'Leitor');
      $this->fail('A viewer stored an answer.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame(1, $this->openCount($claimed));
    }
    $outsider = new ActorContext(ActorContext::TYPE_HUMAN, '9', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', [WorkspacePermission::WRITE]);
    try {
      $this->requests()->answer($outsider, $opened->requestUuid, 'Segredo da casa');
      $this->fail('An outsider stored an answer.');
    }
    catch (ScopedResourceNotFoundException $exception) {
      $this->assertStringNotContainsString('Segredo da casa', $exception->getMessage());
    }
    $this->requests()->answer($this->human($ada, $workspace), $opened->requestUuid, 'Certo');
    try {
      $this->requests()->answer($this->human($ada, $workspace), $opened->requestUuid, 'De novo');
      $this->fail('A second answer was stored.');
    }
    catch (InputRequestRejectedException $exception) {
      $this->assertSame(InputRequestRejectedException::STATE, $exception->getMessage());
      $this->assertSame(0, $this->messageCount('De novo'));
    }
    $remote = $this->readyTask($board, 'Remoto');
    $other = $this->claims()->claim($holder, (string) $remote->uuid());
    $failing = new class (
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('Drupal\ab_communication\MessageService'),
      $this->container->get('Drupal\ab_agent\ExecutionWriter'),
      $this->container->get(EventStoreInterface::class),
      $this->container->get('Drupal\ab_agent\AgentOperationGate'),
      $this->container->get('uuid'),
      $this->container->get('datetime.time'),
      $this->container->get('cache_tags.invalidator'),
    ) extends InputRequestService {

      /**
       * {@inheritdoc}
       */
      protected function afterRecord(): void {
        throw new \RuntimeException('stop');
      }

    };
    $before = $this->requestCount();
    try {
      $failing->request($holder, $other->executionUuid, $other->leaseToken, 1, 'Volta');
      $this->fail('A rolled back question was stored.');
    }
    catch (LeaseRejectedException | InputRequestRejectedException $exception) {
      $this->fail($exception->getMessage());
    }
    catch (\RuntimeException) {
      $this->assertSame($before, $this->requestCount());
      $this->assertSame('running', $this->executionStatus($other));
      $this->assertSame(0, $this->messageCount('Volta'));
    }
  }

  /**
   * Builds an agent that can claim, write and post.
   *
   * @return array{0: \Drupal\user\UserInterface, 1: \Drupal\ab_core\Entity\Workspace, 2: \Drupal\ab_board\Entity\Board, 3: \Drupal\ab_agent\Entity\Agent, 4: \Drupal\ab_core\Authorization\ActorContext}
   *   The fixture pieces.
   */
  private function fixture(string $suffix): array {
    $ada = $this->createUserAccount('ada-' . $suffix, 'ada-' . $suffix . '@example.com');
    $workspace = $this->creator()->create($ada, 'Casa ' . $suffix, 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', '');
    $agent = $this->agents()->create($workspace, 'Revisor', '', '', TRUE, 2);
    $this->grants()->grant($agent, $board, [WorkspacePermission::WRITE]);
    $issued = $this->tokens()->issue($agent, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::EXECUTIONS_WRITE,
      AgentTokenScope::MESSAGES_WRITE,
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
   * Builds the human actor from the current account.
   */
  private function human(UserInterface $user, Workspace $workspace): ActorContext {
    $this->setCurrentUser($user);
    $factory = $this->container->get(HumanActorContextFactory::class);
    $this->assertInstanceOf(HumanActorContextFactory::class, $factory);
    return $factory->create($workspace);
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
   * Returns the execution status stored for a claim.
   */
  private function executionStatus(ClaimedTask $claimed): string {
    $status = $this->container->get('database')->select('ab_execution', 'e')
      ->fields('e', ['status'])
      ->condition('uuid', $claimed->executionUuid)
      ->execute()
      ->fetchField();
    return (string) $status;
  }

  /**
   * Returns the task status from the table.
   */
  private function taskStatus(Task $task): string {
    $status = $this->container->get('database')->select('ab_task', 't')
      ->fields('t', ['status'])
      ->condition('id', (int) $task->id())
      ->execute()
      ->fetchField();
    return (string) $status;
  }

  /**
   * Returns the task version from the table.
   */
  private function taskVersion(Task $task): int {
    $version = $this->container->get('database')->select('ab_task', 't')
      ->fields('t', ['version'])
      ->condition('id', (int) $task->id())
      ->execute()
      ->fetchField();
    return (int) $version;
  }

  /**
   * Returns the lease expiry, or NULL when the lease is gone.
   */
  private function leaseExpiry(Task $task): ?int {
    $expiry = $this->container->get('database')->select('ab_execution_lease', 'l')
      ->fields('l', ['expires_at'])
      ->condition('task_id', (int) $task->id())
      ->execute()
      ->fetchField();
    return $expiry === FALSE ? NULL : (int) $expiry;
  }

  /**
   * Counts open requests of one execution.
   */
  private function openCount(ClaimedTask $claimed): int {
    $id = $this->container->get('database')->select('ab_execution', 'e')
      ->fields('e', ['id'])
      ->condition('uuid', $claimed->executionUuid)
      ->execute()
      ->fetchField();
    return (int) $this->container->get('database')->select('ab_input_request', 'r')
      ->condition('execution_id', (int) $id)
      ->condition('status', 'open')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts every request.
   */
  private function requestCount(): int {
    return (int) $this->container->get('database')->select('ab_input_request', 'r')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts messages with one body.
   */
  private function messageCount(string $body): int {
    return (int) $this->container->get('database')->select('ab_message', 'm')
      ->condition('body', $body)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Returns the stored kind of a message.
   */
  private function messageKind(string $uuid): string {
    $kind = $this->container->get('database')->select('ab_message', 'm')
      ->fields('m', ['kind'])
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchField();
    return (string) $kind;
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
   * Loads the input request service.
   */
  private function requests(): InputRequestService {
    $service = $this->container->get(InputRequestService::class);
    $this->assertInstanceOf(InputRequestService::class, $service);
    return $service;
  }

  /**
   * Loads the lease service.
   */
  private function leases(): ExecutionMaintenance {
    $service = $this->container->get(ExecutionMaintenance::class);
    $this->assertInstanceOf(ExecutionMaintenance::class, $service);
    return $service;
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
   * Loads the agent manager.
   */
  private function agents(): AgentManager {
    $manager = $this->container->get(AgentManager::class);
    $this->assertInstanceOf(AgentManager::class, $manager);
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
   * Loads the token manager.
   */
  private function tokens(): AgentTokenManager {
    $manager = $this->container->get(AgentTokenManager::class);
    $this->assertInstanceOf(AgentTokenManager::class, $manager);
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
   * Authenticates the account.
   */
  private function setCurrentUser(UserInterface $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

}

/**
 * A clock the input tests can move without sleeping.
 */
final class InputClock implements TimeInterface {

  public function __construct(
    private int $now,
  ) {}

  /**
   * Moves the clock forward.
   */
  public function advance(int $seconds): void {
    $this->now += $seconds;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestTime() {
    return $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestMicroTime() {
    return (float) $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTime() {
    return $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentMicroTime() {
    return (float) $this->now;
  }

}
