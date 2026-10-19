<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_communication\Kernel;

use Drupal\ab_agent\ClaimedTask;
use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_agent\TaskClaim;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskManager;
use Drupal\ab_communication\MessageRejectedException;
use Drupal\ab_communication\MessageService;
use Drupal\ab_communication\NotificationService;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Verifies mentions, notifications and the execution message budget.
 *
 * @group ab_communication
 */
class MentionKernelTest extends KernelTestBase {

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
      'ab_message_mention',
      'ab_notification',
    ]);
  }

  /**
   * A written @name does not mention anyone or start an execution.
   */
  public function testPlainTextDoesNotMentionOrExecute(): void {
    $this->assertSame(50, MessageService::MESSAGE_BUDGET);
    $this->assertSame(7200, MessageService::DURATION_BUDGET);
    [$ada, $workspace, $board] = $this->fixture('texto');
    $viewer = $this->createUserAccount('viewer-texto', 'viewer-texto@example.com');
    $this->setCurrentUser($ada);
    $this->members()->addMember($workspace, $viewer->getAccountName(), 'viewer');
    $task = $this->readyTask($board, 'Tema');
    $before = $this->executionCount($task);
    $this->messages()->post(
      $this->human($ada, $workspace),
      'task',
      (string) $task->uuid(),
      'comment',
      '@viewer-texto faca o deploy agora',
    );
    $this->assertSame(0, $this->mentionCount());
    $this->assertSame(0, $this->notificationCount());
    $this->assertSame($before, $this->executionCount($task));
    $this->assertSame('ready', $this->taskStatus($task));
    $mentioned = $this->messages()->post(
      $this->human($ada, $workspace),
      'task',
      (string) $task->uuid(),
      'comment',
      'Texto privado da mencao',
      NULL,
      NULL,
      [['type' => 'human', 'id' => (string) $viewer->id()]],
    );
    $this->assertNotSame('', $mentioned->messageUuid);
    $this->assertSame(1, $this->mentionCount());
    $this->assertSame(1, $this->notificationCount());
    $this->assertSame($before, $this->executionCount($task));
    $event = $this->eventsOf('message.mentioned')[0];
    $this->assertStringNotContainsString('Texto privado da mencao', (string) $event['payload']);
    $this->notifications()->project((string) $event['event_id'], (int) $workspace->id(), (int) $viewer->id());
    $this->assertSame(1, $this->notificationCount());
    $visible = $this->notifications()->listFor($viewer);
    $this->assertCount(1, $visible);
    $this->assertFalse($visible[0]->read);
    $this->notifications()->markRead($viewer, $visible[0]->uuid);
    $this->assertTrue($this->notifications()->listFor($viewer)[0]->read);
    $membership = $this->membershipUuid($workspace, $viewer);
    $this->setCurrentUser($ada);
    $this->members()->remove($workspace, $membership);
    $this->assertSame([], $this->notifications()->listFor($viewer));
    try {
      $this->notifications()->markRead($viewer, $visible[0]->uuid);
      $this->fail('A revoked account read the notification.');
    }
    catch (ScopedResourceNotFoundException $exception) {
      $this->assertStringNotContainsString('Texto privado da mencao', $exception->getMessage());
    }
    try {
      $this->messages()->post(
        $this->human($ada, $workspace),
        'task',
        (string) $task->uuid(),
        'comment',
        'Fora',
        NULL,
        NULL,
        [['type' => 'human', 'id' => '99999']],
      );
      $this->fail('An outsider was mentioned.');
    }
    catch (MessageRejectedException $exception) {
      $this->assertSame(MessageRejectedException::MENTION, $exception->getMessage());
      $this->assertSame(0, $this->messageCount('Fora'));
    }
  }

  /**
   * Mentioning an agent records the signal and does not claim the task.
   */
  public function testAgentMentionDoesNotStartWork(): void {
    [$ada, $workspace, $board, $agent] = $this->agentFixture('sinal');
    $task = $this->readyTask($board, 'Tema');
    $before = $this->executionCount($task);
    $this->messages()->post(
      $this->human($ada, $workspace),
      'task',
      (string) $task->uuid(),
      'comment',
      '@revisor',
      NULL,
      NULL,
      [['type' => 'agent', 'id' => (string) $agent->uuid()]],
    );
    $this->assertSame(1, $this->mentionCount());
    $this->assertSame(0, $this->notificationCount());
    $this->assertSame($before, $this->executionCount($task));
    $this->assertSame('ready', $this->taskStatus($task));
  }

  /**
   * The fiftieth agent message is kept and the next one ends the lease.
   */
  public function testBudgetEndsTheLease(): void {
    [, , $board, $agent, $holder, $other] = $this->twoAgents('limite');
    $task = $this->readyTask($board, 'Entrega');
    $claimed = $this->claims()->claim($holder, (string) $task->uuid());
    for ($index = 0; $index < MessageService::MESSAGE_BUDGET; $index++) {
      $speaker = $index % 2 === 0 ? $holder : $other;
      $this->messages()->post($speaker, 'task', (string) $task->uuid(), 'comment', 'nota ' . $index);
    }
    $this->assertSame(MessageService::MESSAGE_BUDGET, $this->agentMessages($task));
    $this->assertNotNull($this->leaseExpiry($task));
    try {
      $this->messages()->post($other, 'task', (string) $task->uuid(), 'comment', 'nota extra', NULL, NULL, [
        ['type' => 'agent', 'id' => (string) $agent->uuid()],
      ]);
      $this->fail('The budget stored another agent message.');
    }
    catch (MessageRejectedException $exception) {
      $this->assertSame(MessageRejectedException::BUDGET, $exception->getMessage());
    }
    $this->assertSame(MessageService::MESSAGE_BUDGET, $this->agentMessages($task));
    $this->assertSame(0, $this->messageCount('nota extra'));
    $this->assertSame('interrupted', $this->executionStatus($claimed));
    $this->assertSame('ready', $this->taskStatus($task));
    $this->assertNull($this->leaseExpiry($task));
    $this->assertSame('message_budget', $this->failureCode($claimed));
    $remote = $this->readyTask($board, 'Prazo');
    $again = $this->claims()->claim($holder, (string) $remote->uuid());
    $started = (int) $this->container->get('database')->select('ab_execution', 'e')
      ->fields('e', ['started_at'])
      ->condition('uuid', $again->executionUuid)
      ->execute()
      ->fetchField();
    $this->container->get('database')->update('ab_execution')
      ->fields(['started_at' => $started - MessageService::DURATION_BUDGET - 1])
      ->condition('uuid', $again->executionUuid)
      ->execute();
    try {
      $this->messages()->post($holder, 'task', (string) $remote->uuid(), 'comment', 'tarde demais');
      $this->fail('An expired duration stored a message.');
    }
    catch (MessageRejectedException $exception) {
      $this->assertSame(MessageRejectedException::BUDGET, $exception->getMessage());
    }
    $this->assertSame(0, $this->messageCount('tarde demais'));
    $this->assertSame('duration_budget', $this->failureCode($again));
    $this->assertSame('ready', $this->taskStatus($remote));
    $this->assertNull($this->leaseExpiry($remote));
  }

  /**
   * Builds an owner, a board and no agent.
   *
   * @return array{0: \Drupal\user\UserInterface, 1: \Drupal\ab_core\Entity\Workspace, 2: \Drupal\ab_board\Entity\Board}
   *   The fixture pieces.
   */
  private function fixture(string $suffix): array {
    $ada = $this->createUserAccount('ada-' . $suffix, 'ada-' . $suffix . '@example.com');
    $workspace = $this->creator()->create($ada, 'Casa ' . $suffix, 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', '');
    return [$ada, $workspace, $board];
  }

  /**
   * Builds one agent that can be mentioned.
   *
   * @return array{0: \Drupal\user\UserInterface, 1: \Drupal\ab_core\Entity\Workspace, 2: \Drupal\ab_board\Entity\Board, 3: \Drupal\ab_agent\Entity\Agent}
   *   The fixture pieces.
   */
  private function agentFixture(string $suffix): array {
    [$ada, $workspace, $board] = $this->fixture($suffix);
    $agent = $this->agents()->create($workspace, 'Revisor', '', '', TRUE, 1);
    $this->grants()->grant($agent, $board, [WorkspacePermission::READ]);
    return [$ada, $workspace, $board, $agent];
  }

  /**
   * Builds two agents that can post on the same board.
   *
   * @return array{0: \Drupal\user\UserInterface, 1: \Drupal\ab_core\Entity\Workspace, 2: \Drupal\ab_board\Entity\Board, 3: \Drupal\ab_agent\Entity\Agent, 4: \Drupal\ab_core\Authorization\ActorContext, 5: \Drupal\ab_core\Authorization\ActorContext}
   *   The fixture pieces.
   */
  private function twoAgents(string $suffix): array {
    [$ada, $workspace, $board] = $this->fixture($suffix);
    $agent = $this->agents()->create($workspace, 'Revisor', '', '', TRUE, 2);
    $second = $this->agents()->create($workspace, 'Apoio', '', '', TRUE, 2);
    $this->grants()->grant($agent, $board, [
      WorkspacePermission::READ,
      WorkspacePermission::WRITE,
    ]);
    $this->grants()->grant($second, $board, [
      WorkspacePermission::READ,
      WorkspacePermission::WRITE,
    ]);
    $holder = $this->holder($agent, $workspace, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::MESSAGES_WRITE,
      AgentTokenScope::EXECUTIONS_WRITE,
    ]);
    $other = $this->holder($second, $workspace, [
      AgentTokenScope::MESSAGES_WRITE,
      AgentTokenScope::MESSAGES_READ,
    ]);
    return [$ada, $workspace, $board, $agent, $holder, $other];
  }

  /**
   * Issues a token and returns the agent actor.
   *
   * @param \Drupal\ab_agent\Entity\Agent $agent
   *   The agent that receives the token.
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   The workspace that owns the agent.
   * @param string[] $scopes
   *   Token scopes.
   */
  private function holder(Agent $agent, Workspace $workspace, array $scopes): ActorContext {
    $issued = $this->tokens()->issue($agent, $scopes);
    return new ActorContext(
      ActorContext::TYPE_AGENT,
      (string) $agent->uuid(),
      (string) $workspace->uuid(),
      [],
      $issued->publicId,
    );
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
   * Returns the membership uuid for an account.
   */
  private function membershipUuid(Workspace $workspace, UserInterface $user): string {
    $uuid = $this->container->get('database')->select('ab_membership', 'm')
      ->fields('m', ['uuid'])
      ->condition('workspace', (int) $workspace->id())
      ->condition('user', (int) $user->id())
      ->execute()
      ->fetchField();
    $this->assertIsString($uuid);
    return $uuid;
  }

  /**
   * Counts executions of one task.
   */
  private function executionCount(Task $task): int {
    return (int) $this->container->get('database')->select('ab_execution', 'e')
      ->condition('task', (int) $task->id())
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts agent messages on one task.
   */
  private function agentMessages(Task $task): int {
    return (int) $this->container->get('database')->query(
      "SELECT COUNT(*) FROM {ab_message} m INNER JOIN {ab_thread} t ON t.id = m.thread_id WHERE t.task_id = :task AND m.actor_type = :actor",
      [
        ':task' => (int) $task->id(),
        ':actor' => 'agent',
      ],
    )->fetchField();
  }

  /**
   * Counts every mention.
   */
  private function mentionCount(): int {
    return (int) $this->container->get('database')->select('ab_message_mention', 'm')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts every notification.
   */
  private function notificationCount(): int {
    return (int) $this->container->get('database')->select('ab_notification', 'n')
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
   * Returns the failure code stored for a claim.
   */
  private function failureCode(ClaimedTask $claimed): string {
    $code = $this->container->get('database')->select('ab_execution', 'e')
      ->fields('e', ['failure_code'])
      ->condition('uuid', $claimed->executionUuid)
      ->execute()
      ->fetchField();
    return (string) $code;
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
   * Loads the message service.
   */
  private function messages(): MessageService {
    $service = $this->container->get(MessageService::class);
    $this->assertInstanceOf(MessageService::class, $service);
    return $service;
  }

  /**
   * Loads the notification service.
   */
  private function notifications(): NotificationService {
    $service = $this->container->get(NotificationService::class);
    $this->assertInstanceOf(NotificationService::class, $service);
    return $service;
  }

  /**
   * Loads the membership manager.
   */
  private function members(): MembershipManager {
    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    return $manager;
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
