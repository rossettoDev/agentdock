<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_communication\Kernel;

use Drupal\ab_agent\AgentAccessDeniedException;
use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\ClaimedTask;
use Drupal\ab_agent\TaskClaim;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskManager;
use Drupal\ab_communication\MessagePage;
use Drupal\ab_communication\MessageRejectedException;
use Drupal\ab_communication\MessageService;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\ab_event\EventStoreInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Verifies one thread per context and an author taken from authentication.
 *
 * @group ab_communication
 */
class MessageKernelTest extends KernelTestBase {

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
    ]);
  }

  /**
   * A repeated create keeps one thread and does not edit the first body.
   */
  public function testThreadIsCreatedOnce(): void {
    $this->assertSame(16384, MessageService::BODY_LIMIT);
    $this->assertSame(50, MessageService::PAGE_DEFAULT);
    [$ada, $workspace, $board] = $this->fixture('fala');
    $human = $this->human($ada, $workspace);
    $first = $this->messages()->post($human, 'board', (string) $board->uuid(), 'comment', 'Primeira');
    $second = $this->messages()->post($human, 'board', (string) $board->uuid(), 'comment', 'Segunda');
    $this->assertSame($first->threadUuid, $second->threadUuid);
    $this->assertSame(1, $this->threadCount((int) $board->id(), 0));
    $this->assertSame('Primeira', $this->body($first->messageUuid));
    $this->assertNotSame($first->messageUuid, $second->messageUuid);
    try {
      $this->messages()->post($human, 'board', (string) $board->uuid(), 'comment', '   ');
      $this->fail('An empty message was stored.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame(2, $this->messageCount($first->threadUuid));
    }
    try {
      $this->messages()->post($human, 'board', (string) $board->uuid(), 'comment', str_repeat('a', 16385));
      $this->fail('A message over 16 KiB was stored.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame(2, $this->messageCount($first->threadUuid));
    }
    $reply = $this->messages()->post($human, 'board', (string) $board->uuid(), 'comment', 'Resposta', $first->messageUuid);
    $this->assertSame($first->threadUuid, $reply->threadUuid);
    $stored = $this->row($reply->messageUuid);
    $this->assertSame($this->messageId($first->messageUuid), (int) $stored['reply_to']);
    $task = $this->readyTask($board, 'Tema');
    try {
      $this->messages()->post($human, 'task', (string) $task->uuid(), 'comment', 'Fora', $first->messageUuid);
      $this->fail('A reply crossed threads.');
    }
    catch (MessageRejectedException $exception) {
      $this->assertSame(MessageRejectedException::REPLY, $exception->getMessage());
      $this->assertSame(0, $this->threadCount((int) $board->id(), (int) $task->id()));
    }
    $events = $this->eventsOf('message.created');
    $this->assertCount(3, $events);
    $this->assertSame('human', $events[0]['actor_type']);
    $this->assertSame((string) $ada->id(), $events[0]['actor_id']);
    $this->assertStringNotContainsString('Primeira', (string) $events[0]['payload']);
    $page = $this->messages()->read($human, $first->threadUuid, NULL, 2);
    $this->assertCount(2, $page->messages);
    $this->assertNotNull($page->cursor);
    $older = $this->messages()->read($human, $first->threadUuid, $page->cursor, 2);
    $this->assertCount(1, $older->messages);
    $this->assertSame('Primeira', $older->messages[0]->body);
    $this->assertNull($older->cursor);
    try {
      $this->messages()->read($human, $first->threadUuid, 'ontem', 2);
      $this->fail('A broken cursor was accepted.');
    }
    catch (\InvalidArgumentException) {
      $this->assertSame(3, $this->messageCount($first->threadUuid));
    }
  }

  /**
   * Kind and execution stay inside the authenticated actor.
   */
  public function testActorCannotForgeKind(): void {
    [$ada, $workspace, $board, $agent, $holder] = $this->agentFixture('tipo');
    $viewer = $this->createUserAccount('viewer-tipo', 'viewer-tipo@example.com');
    $this->setCurrentUser($ada);
    $members = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $members);
    $members->addMember($workspace, $viewer->getAccountName(), 'viewer');
    $human = $this->human($ada, $workspace);
    $task = $this->readyTask($board, 'Entrega');
    $claimed = $this->claims()->claim($holder, (string) $task->uuid());
    $posted = $this->messages()->post(
      $holder,
      'task',
      (string) $task->uuid(),
      'comment',
      '<script>alert(1)</script>',
      NULL,
      $claimed->executionUuid,
    );
    $this->assertSame('comment', $posted->kind);
    $this->assertSame((string) $agent->uuid(), $this->row($posted->messageUuid)['actor_id']);
    $this->assertSame($this->executionId($claimed), (int) $this->row($posted->messageUuid)['execution_id']);
    $this->assertStringNotContainsString('<script>', (string) $this->eventsOf('message.created')[0]['payload']);
    $before = $this->messageCount($posted->threadUuid);
    foreach (['answer', 'system'] as $kind) {
      try {
        $this->messages()->post($holder, 'task', (string) $task->uuid(), $kind, 'Forjado');
        $this->fail('The agent stored a ' . $kind . ' message.');
      }
      catch (MessageRejectedException $exception) {
        $this->assertSame(MessageRejectedException::KIND, $exception->getMessage());
      }
    }
    try {
      $this->messages()->post($human, 'task', (string) $task->uuid(), 'system', 'Humano sistema');
      $this->fail('A human stored a system message.');
    }
    catch (MessageRejectedException $exception) {
      $this->assertSame(MessageRejectedException::KIND, $exception->getMessage());
    }
    try {
      $this->messages()->post($human, 'task', (string) $task->uuid(), 'comment', 'Com execução', NULL, $claimed->executionUuid);
      $this->fail('A human linked an execution.');
    }
    catch (MessageRejectedException $exception) {
      $this->assertSame(MessageRejectedException::EXECUTION, $exception->getMessage());
    }
    $this->assertSame($before, $this->messageCount($posted->threadUuid));
    $running = $this->reload($task);
    $this->assertSame('in_progress', $running->get('status')->getString());
    $version = (int) $running->get('version')->getString();
    $question = $this->messages()->post($holder, 'task', (string) $task->uuid(), 'question', 'Qual o prazo?');
    $afterQuestion = $this->reload($task);
    $this->assertSame('in_progress', $afterQuestion->get('status')->getString());
    $this->assertSame($version, (int) $afterQuestion->get('version')->getString());
    $this->assertSame($posted->threadUuid, $question->threadUuid);
    $system = new ActorContext(ActorContext::TYPE_SYSTEM, 'message-system', (string) $workspace->uuid(), []);
    $note = $this->messages()->post($system, 'board', (string) $board->uuid(), 'system', 'Registro');
    $this->assertSame('system', $this->row($note->messageUuid)['actor_type']);
    $other = $this->readyTask($board, 'Outra');
    $otherClaim = $this->claims()->claim($holder, (string) $other->uuid());
    try {
      $this->messages()->post($holder, 'task', (string) $task->uuid(), 'result', 'Troca', NULL, $otherClaim->executionUuid);
      $this->fail('An execution from another task was linked.');
    }
    catch (MessageRejectedException $exception) {
      $this->assertSame(MessageRejectedException::EXECUTION, $exception->getMessage());
    }
    $this->setCurrentUser($viewer);
    $reader = $this->human($viewer, $workspace);
    $page = $this->messages()->read($reader, $posted->threadUuid, NULL, MessageService::PAGE_DEFAULT);
    $this->assertInstanceOf(MessagePage::class, $page);
    $this->assertNotSame('', $page->messages[0]->body);
    try {
      $this->messages()->post($reader, 'task', (string) $task->uuid(), 'answer', 'Não posso');
      $this->fail('A viewer stored an answer.');
    }
    catch (ScopedOperationDeniedException) {
      $this->assertSame('in_progress', $this->reload($task)->get('status')->getString());
    }
    $outsider = new ActorContext(ActorContext::TYPE_HUMAN, '9', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', [WorkspacePermission::READ]);
    try {
      $this->messages()->post($outsider, 'task', (string) $task->uuid(), 'comment', 'Segredo da casa');
      $this->fail('An outsider stored a message.');
    }
    catch (ScopedResourceNotFoundException $exception) {
      $this->assertStringNotContainsString('Segredo da casa', $exception->getMessage());
    }
    $this->setCurrentUser($ada);
    $narrow = $this->tokens()->issue($agent, [AgentTokenScope::MESSAGES_READ]);
    $readerAgent = new ActorContext(ActorContext::TYPE_AGENT, (string) $agent->uuid(), (string) $workspace->uuid(), [], $narrow->publicId);
    $visible = $this->messages()->listThreads($readerAgent);
    $this->assertNotSame([], $visible);
    try {
      $this->messages()->post($readerAgent, 'task', (string) $task->uuid(), 'comment', 'Sem escrita');
      $this->fail('A read token stored a message.');
    }
    catch (AgentAccessDeniedException) {
      $this->assertSame($this->body($posted->messageUuid), '<script>alert(1)</script>');
    }
    $hidden = $this->boards()->create($workspace, 'Oculto', '');
    $this->setCurrentUser($ada);
    $this->messages()->post($this->human($ada, $workspace), 'board', (string) $hidden->uuid(), 'comment', 'Texto oculto');
    $listed = $this->messages()->listThreads($holder);
    $labels = array_map(static fn ($item): string => $item->boardLabel, $listed);
    $this->assertNotContains('Oculto', $labels);
    $this->assertContains('Quadro', $labels);
    $failing = new class (
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get(EventStoreInterface::class),
      $this->container->get('Drupal\ab_agent\AgentOperationGate'),
      $this->container->get('uuid'),
      $this->container->get('datetime.time'),
      $this->container->get('cache_tags.invalidator'),
    ) extends MessageService {

      /**
       * {@inheritdoc}
       */
      protected function afterRecord(): void {
        throw new \RuntimeException('stop');
      }

    };
    $remote = $this->boards()->create($workspace, 'Remoto', '');
    $threads = $this->threadTotal();
    try {
      $failing->post($this->human($ada, $workspace), 'board', (string) $remote->uuid(), 'comment', 'Volta');
      $this->fail('A rolled back message was stored.');
    }
    catch (MessageRejectedException $exception) {
      $this->fail($exception->getMessage());
    }
    catch (\RuntimeException) {
      $this->assertSame($threads, $this->threadTotal());
      $this->assertSame(0, $this->messageCountByBody('Volta'));
    }
  }

  /**
   * Builds an owner and a board.
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
   * Builds an agent that can claim and write messages.
   *
   * @return array{0: \Drupal\user\UserInterface, 1: \Drupal\ab_core\Entity\Workspace, 2: \Drupal\ab_board\Entity\Board, 3: \Drupal\ab_agent\Entity\Agent, 4: \Drupal\ab_core\Authorization\ActorContext}
   *   The fixture pieces.
   */
  private function agentFixture(string $suffix): array {
    [$ada, $workspace, $board] = $this->fixture($suffix);
    $agent = $this->agents()->create($workspace, 'Revisor', '', '', TRUE, 2);
    $this->grants()->grant($agent, $board, [
      WorkspacePermission::READ,
      WorkspacePermission::WRITE,
    ]);
    $issued = $this->tokens()->issue($agent, [
      AgentTokenScope::TASKS_CLAIM,
      AgentTokenScope::MESSAGES_READ,
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
   * Counts threads for one board and task pair.
   */
  private function threadCount(int $boardId, int $taskId): int {
    return (int) $this->container->get('database')->select('ab_thread', 't')
      ->condition('board_id', $boardId)
      ->condition('task_id', $taskId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts every thread.
   */
  private function threadTotal(): int {
    return (int) $this->container->get('database')->select('ab_thread', 't')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts messages in one thread.
   */
  private function messageCount(string $threadUuid): int {
    $thread = $this->container->get('database')->select('ab_thread', 't')
      ->fields('t', ['id'])
      ->condition('uuid', $threadUuid)
      ->execute()
      ->fetchField();
    return (int) $this->container->get('database')->select('ab_message', 'm')
      ->condition('thread_id', (int) $thread)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts messages with one body.
   */
  private function messageCountByBody(string $body): int {
    return (int) $this->container->get('database')->select('ab_message', 'm')
      ->condition('body', $body)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Returns the stored body.
   */
  private function body(string $uuid): string {
    return (string) $this->row($uuid)['body'];
  }

  /**
   * Returns one message row.
   *
   * @return array<string, mixed>
   *   The stored message.
   */
  private function row(string $uuid): array {
    $row = $this->container->get('database')->select('ab_message', 'm')
      ->fields('m')
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    return $row;
  }

  /**
   * Returns the numeric id of a message.
   */
  private function messageId(string $uuid): int {
    return (int) $this->row($uuid)['id'];
  }

  /**
   * Returns the numeric id of a claimed execution.
   */
  private function executionId(ClaimedTask $claimed): int {
    $id = $this->container->get('database')->select('ab_execution', 'e')
      ->fields('e', ['id'])
      ->condition('uuid', $claimed->executionUuid)
      ->execute()
      ->fetchField();
    return (int) $id;
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
