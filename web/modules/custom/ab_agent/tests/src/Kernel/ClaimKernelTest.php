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
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_agent\Entity\Execution;
use Drupal\ab_agent\TaskClaim;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskDependencyManager;
use Drupal\ab_board\TaskManager;
use Drupal\ab_board\TaskTransitionRejectedException;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies one task has one lease and a lost claim leaves nothing.
 *
 * @group ab_agent
 */
class ClaimKernelTest extends KernelTestBase {

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
  }

  /**
   * A ready task becomes in progress with a hashed lease and one event.
   */
  public function testClaimStoresHashAndRejectsSecondClaim(): void {
    $ada = $this->createUserAccount('ada', 'ada@example.com');
    $outsider = $this->createUserAccount('out', 'out@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', '');
    $agent = $this->agents()->create($workspace, 'Revisor', '', '', TRUE, 1);
    $this->grants()->grant($agent, $board, [WorkspacePermission::WRITE]);
    $issued = $this->tokens()->issue($agent, [AgentTokenScope::TASKS_CLAIM]);
    $task = $this->readyTask($board, 'Tema');
    $version = (int) $task->get('version')->getString();

    $claimed = $this->claims()->claim($this->actorFor($agent, $workspace, $issued->publicId), (string) $task->uuid());
    $secret = substr($claimed->leaseToken, (int) strrpos($claimed->leaseToken, '.') + 1);
    $this->assertSame(1, preg_match('/^ab_lease_[0-9a-f]{32}\.[0-9a-f]{64}$/', $claimed->leaseToken));
    $this->assertSame($version + 1, $claimed->taskVersion);

    $stored = $this->reload($task);
    $this->assertSame('in_progress', $stored->get('status')->getString());
    $this->assertSame((string) $agent->id(), $stored->get('assigned_agent')->getString());
    $execution = $this->execution($claimed);
    $this->assertSame((string) $execution->id(), $stored->get('active_execution')->getString());
    $this->assertSame('running', $execution->get('status')->getString());
    $this->assertSame('1', $execution->get('attempt')->getString());
    $this->assertSame('', $execution->get('heartbeat_at')->getString());

    $leases = $this->rows('ab_execution_lease');
    $this->assertCount(1, $leases);
    $this->assertSame(hash('sha256', $secret), $leases[0]['token_hash']);
    $this->assertSame('1', (string) $leases[0]['generation']);
    $this->assertSame($claimed->leaseExpiresAt, (int) $leases[0]['expires_at']);
    $started = (int) $execution->get('started_at')->getString();
    $this->assertSame($started + ClaimWriter::LEASE_TTL, $claimed->leaseExpiresAt);
    $this->assertStringNotContainsString($secret, json_encode($leases));
    $events = $this->eventsOf('task.claimed');
    $this->assertCount(1, $events);
    $this->assertSame((string) $agent->uuid(), $events[0]['actor_id']);
    $this->assertStringNotContainsString($secret, (string) $events[0]['payload']);
    $payload = json_decode((string) $events[0]['payload'], TRUE);
    $this->assertSame($claimed->executionUuid, $payload['execution_id']);

    try {
      $this->claims()->claim($this->actorFor($agent, $workspace, $issued->publicId), (string) $task->uuid());
      $this->fail('A second claim returned another lease.');
    }
    catch (ClaimRejectedException $exception) {
      $this->assertSame(ClaimRejectedException::LEASE, $exception->getMessage());
      $this->assertStringNotContainsString($secret, $exception->getMessage());
      $this->assertCount(1, $this->rows('ab_execution'));
    }

    try {
      $this->tasks()->transition($stored, 'cancelled', $claimed->taskVersion);
      $this->fail('A human moved a claimed task.');
    }
    catch (TaskTransitionRejectedException $exception) {
      $this->assertSame('An active execution owns this task.', $exception->getMessage());
      $this->assertSame('in_progress', $this->reload($task)->get('status')->getString());
    }

    $this->setCurrentUser($outsider);
    $hidden = $this->container->get('entity_type.manager')->getStorage('ab_execution')->getQuery()
      ->accessCheck(TRUE)
      ->execute();
    $this->assertSame([], array_values($hidden));
    $this->setCurrentUser($ada);
    $visible = $this->container->get('entity_type.manager')->getStorage('ab_execution')->getQuery()
      ->accessCheck(TRUE)
      ->execute();
    $this->assertNotSame([], array_values($visible));
  }

  /**
   * Status, blockers, assignment, scope and a failed record all refuse.
   */
  public function testClaimRejectsUnavailableTasks(): void {
    $ada = $this->createUserAccount('ada2', 'ada2@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Regras', 'America/Sao_Paulo');
    $other = $this->creator()->create($ada, 'Casa Longe', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', '');
    $foreign = $this->boards()->create($workspace, 'Outro', '');
    $agent = $this->agents()->create($workspace, 'Revisor', '', '', TRUE, 1);
    $this->grants()->grant($agent, $board, [WorkspacePermission::WRITE]);
    $issued = $this->tokens()->issue($agent, [AgentTokenScope::TASKS_CLAIM]);
    $actor = $this->actorFor($agent, $workspace, $issued->publicId);

    $backlog = $this->tasks()->create($board, ['title' => 'Ainda no backlog']);
    $this->expectRejection($actor, $backlog, ClaimRejectedException::STATUS, 'backlog');

    $predecessor = $this->readyTask($board, 'Antes');
    $blocked = $this->readyTask($board, 'Depois');
    $blocked = $this->dependencies()->add($blocked, $predecessor, (int) $blocked->get('version')->getString());
    $this->expectRejection($actor, $blocked, ClaimRejectedException::BLOCKED);

    $taken = $this->readyTask($board, 'De outro');
    $this->container->get('database')->update('ab_task')
      ->fields(['assigned_agent' => 999])
      ->condition('id', $taken->id())
      ->execute();
    $this->expectRejection($actor, $taken, ClaimRejectedException::ASSIGNED);

    $first = $this->readyTask($board, 'Primeira');
    $second = $this->readyTask($board, 'Segunda');
    $this->claims()->claim($actor, (string) $first->uuid());
    $this->expectRejection($actor, $second, ClaimRejectedException::LIMIT);

    $reader = $this->agents()->create($workspace, 'Leitor', '', '', TRUE, 1);
    $this->grants()->grant($reader, $board, [WorkspacePermission::READ]);
    $readToken = $this->tokens()->issue($reader, [AgentTokenScope::TASKS_CLAIM]);
    $waiting = $this->readyTask($board, 'Sem escrita');
    $this->expectDenied($this->actorFor($reader, $workspace, $readToken->publicId), $waiting);

    $narrow = $this->agents()->create($workspace, 'Estreito', '', '', TRUE, 1);
    $this->grants()->grant($narrow, $board, [WorkspacePermission::WRITE]);
    $readOnly = $this->tokens()->issue($narrow, [AgentTokenScope::TASKS_READ]);
    $this->expectDenied($this->actorFor($narrow, $workspace, $readOnly->publicId), $waiting);

    $elsewhere = $this->readyTask($foreign, 'Fora do grant');
    $this->expectDenied($actor, $elsewhere);

    $paused = $this->agents()->create($workspace, 'Pausado', '', '', TRUE, 1);
    $this->grants()->grant($paused, $board, [WorkspacePermission::WRITE]);
    $pausedToken = $this->tokens()->issue($paused, [AgentTokenScope::TASKS_CLAIM]);
    $this->agents()->update($paused, 'Pausado', '', '', FALSE, 1);
    $this->expectDenied($this->actorFor($paused, $workspace, $pausedToken->publicId), $waiting);

    $stale = $this->agents()->create($workspace, 'Vencido', '', '', TRUE, 1);
    $this->grants()->grant($stale, $board, [WorkspacePermission::WRITE]);
    $staleToken = $this->tokens()->issue($stale, [AgentTokenScope::TASKS_CLAIM], $this->now());
    $this->expectDenied($this->actorFor($stale, $workspace, $staleToken->publicId), $waiting);

    $revoked = $this->agents()->create($workspace, 'Revogado', '', '', TRUE, 1);
    $this->grants()->grant($revoked, $board, [WorkspacePermission::WRITE]);
    $revokedToken = $this->tokens()->issue($revoked, [AgentTokenScope::TASKS_CLAIM]);
    $this->tokens()->revoke($revoked, $revokedToken->publicId);
    $this->expectDenied($this->actorFor($revoked, $workspace, $revokedToken->publicId), $waiting);

    $remote = $this->readyTask($this->boards()->create($other, 'Longe', ''), 'Alheia');
    try {
      $this->claims()->claim($actor, (string) $remote->uuid());
      $this->fail('A foreign task was claimed.');
    }
    catch (ScopedResourceNotFoundException) {
      $this->assertSame('ready', $this->reload($remote)->get('status')->getString());
    }
    try {
      $this->claims()->claim($actor, '44444444-4444-4444-8444-444444444444');
      $this->fail('A missing task was claimed.');
    }
    catch (ScopedResourceNotFoundException) {
    }

    $rollbackAgent = $this->agents()->create($workspace, 'Falho', '', '', TRUE, 1);
    $this->grants()->grant($rollbackAgent, $board, [WorkspacePermission::WRITE]);
    $rollbackToken = $this->tokens()->issue($rollbackAgent, [AgentTokenScope::TASKS_CLAIM]);
    $rollbackActor = $this->actorFor($rollbackAgent, $workspace, $rollbackToken->publicId);
    $rollbackTask = $this->readyTask($board, 'Falha');
    $before = count($this->eventsOf('task.claimed'));
    $failing = new class (
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get(ClaimWriter::class),
      $this->container->get('Drupal\ab_agent\AgentOperationGate'),
      $this->container->get('cache_tags.invalidator'),
    ) extends TaskClaim {

      /**
       * {@inheritdoc}
       */
      protected function afterRecord(ClaimedTask $claimed): void {
        throw new \RuntimeException('stop');
      }

    };
    try {
      $failing->claim($rollbackActor, (string) $rollbackTask->uuid());
      $this->fail('A failed claim was stored.');
    }
    catch (ClaimRejectedException $exception) {
      $this->fail($exception->getMessage());
    }
    catch (\RuntimeException) {
      $this->assertSame('ready', $this->reload($rollbackTask)->get('status')->getString());
      $this->assertSame('', $this->reload($rollbackTask)->get('active_execution')->getString());
      $this->assertCount($before, $this->eventsOf('task.claimed'));
    }

    $moved = Execution::create([
      'workspace' => $workspace->id(),
      'task' => $rollbackTask->id(),
      'agent' => $agent->id(),
      'status' => 'running',
      'lease_expires_at' => $this->now() + 120,
      'started_at' => $this->now(),
      'attempt' => 1,
    ]);
    $moved->save();
    $moved->set('task', $first->id());
    try {
      $moved->save();
      $this->fail('An execution changed task.');
    }
    catch (EntityStorageException) {
      $this->assertSame((string) $rollbackTask->id(), $this->reloadExecution($moved)->get('task')->getString());
    }
  }

  /**
   * Two processes claim one task and only one execution remains.
   */
  public function testTwoProcessesClaimSameTask(): void {
    $ada = $this->createUserAccount('ada3', 'ada3@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Corrida', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', '');
    $task = $this->readyTask($board, 'Disputada');
    $left = $this->agentCommand($workspace, $board, 'Esquerda', 2, $task);
    $right = $this->agentCommand($workspace, $board, 'Direita', 2, $task);
    $results = $this->racePair($left, $right);
    $wins = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === TRUE));
    $this->assertCount(1, $wins);
    $this->assertCount(1, $this->rows('ab_execution'));
    $this->assertCount(1, $this->rows('ab_execution_lease'));
    $this->assertCount(1, $this->eventsOf('task.claimed'));
    $this->assertSame('in_progress', $this->reload($task)->get('status')->getString());
    $this->assertSame($wins[0]['task_version'], (int) $this->reload($task)->get('version')->getString());
    $loss = $results[0]['ok'] === TRUE ? $results[1] : $results[0];
    $this->assertContains($loss['code'], [
      ClaimRejectedException::STATUS,
      ClaimRejectedException::LEASE,
    ]);
  }

  /**
   * Two processes share one agent and the second stops at the limit.
   */
  public function testTwoProcessesStopAtConcurrencyLimit(): void {
    $ada = $this->createUserAccount('ada4', 'ada4@example.com');
    $workspace = $this->creator()->create($ada, 'Casa Limite', 'America/Sao_Paulo');
    $this->setCurrentUser($ada);
    $board = $this->boards()->create($workspace, 'Quadro', '');
    $agent = $this->agents()->create($workspace, 'Unico', '', '', TRUE, 1);
    $this->grants()->grant($agent, $board, [WorkspacePermission::WRITE]);
    $issued = $this->tokens()->issue($agent, [AgentTokenScope::TASKS_CLAIM]);
    $alpha = $this->readyTask($board, 'Alfa');
    $beta = $this->readyTask($board, 'Beta');
    $results = $this->racePair(
      $this->command($workspace, $agent, $board, $issued->publicId, $alpha),
      $this->command($workspace, $agent, $board, $issued->publicId, $beta),
    );
    $wins = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === TRUE));
    $this->assertCount(1, $wins);
    $this->assertCount(1, $this->rows('ab_execution'));
    $ready = 0;
    $running = 0;
    foreach ([$alpha, $beta] as $task) {
      $status = $this->reload($task)->get('status')->getString();
      $ready += $status === 'ready' ? 1 : 0;
      $running += $status === 'in_progress' ? 1 : 0;
    }
    $this->assertSame(1, $ready);
    $this->assertSame(1, $running);
    $loss = $results[0]['ok'] === TRUE ? $results[1] : $results[0];
    $this->assertSame(ClaimRejectedException::LIMIT, $loss['code']);
  }

  /**
   * Asserts a claim is rejected and the stored status does not change.
   */
  private function expectRejection(ActorContext $actor, Task $task, string $code, string $status = 'ready'): void {
    try {
      $this->claims()->claim($actor, (string) $task->uuid());
      $this->fail($code);
    }
    catch (ClaimRejectedException $exception) {
      $this->assertSame($code, $exception->getMessage());
      $this->assertSame($status, $this->reload($task)->get('status')->getString());
      $this->assertSame('', $this->reload($task)->get('active_execution')->getString());
    }
  }

  /**
   * Asserts the gate denies the claim.
   */
  private function expectDenied(ActorContext $actor, Task $task): void {
    $before = count($this->rows('ab_execution'));
    try {
      $this->claims()->claim($actor, (string) $task->uuid());
      $this->fail('A denied claim was stored.');
    }
    catch (AgentAccessDeniedException $exception) {
      $this->assertSame('The agent cannot perform that operation.', $exception->getMessage());
      $this->assertSame('ready', $this->reload($task)->get('status')->getString());
      $this->assertCount($before, $this->rows('ab_execution'));
    }
  }

  /**
   * Creates a task and moves it to ready.
   */
  private function readyTask(Board $board, string $title): Task {
    $task = $this->tasks()->create($board, ['title' => $title]);
    return $this->tasks()->transition($task, 'ready', 1);
  }

  /**
   * Builds a second agent and the command that claims one task.
   *
   * @return array<string, int|string>
   *   Child command fields.
   */
  private function agentCommand(Workspace $workspace, Board $board, string $name, int $limit, Task $task): array {
    $agent = $this->agents()->create($workspace, $name, '', '', TRUE, $limit);
    $this->grants()->grant($agent, $board, [WorkspacePermission::WRITE]);
    $issued = $this->tokens()->issue($agent, [AgentTokenScope::TASKS_CLAIM]);
    return $this->command($workspace, $agent, $board, $issued->publicId, $task);
  }

  /**
   * Builds the payload a child process claims with.
   *
   * @return array<string, int|string>
   *   Child command fields.
   */
  private function command(Workspace $workspace, Agent $agent, Board $board, string $tokenId, Task $task): array {
    return [
      'workspace_id' => (int) $workspace->id(),
      'workspace_uuid' => (string) $workspace->uuid(),
      'agent_id' => (int) $agent->id(),
      'agent_uuid' => (string) $agent->uuid(),
      'task_id' => (int) $task->id(),
      'task_uuid' => (string) $task->uuid(),
      'board_id' => (int) $board->id(),
      'concurrency_limit' => (int) $agent->get('concurrency_limit')->getString(),
      'token_id' => $tokenId,
    ];
  }

  /**
   * Starts both claims before waiting, so the locks overlap.
   *
   * The lease secret is not part of either result.
   *
   * @param array<string, int|string> $left
   *   First child command.
   * @param array<string, int|string> $right
   *   Second child command.
   *
   * @return array<int, array<string, mixed>>
   *   Both child results.
   */
  private function racePair(array $left, array $right): array {
    $children = [];
    foreach ([$left, $right] as $command) {
      $resultFile = tempnam(sys_get_temp_dir(), 'ab_claim_');
      $traceFile = tempnam(sys_get_temp_dir(), 'ab_claim_trace_');
      $this->assertIsString($resultFile);
      $this->assertIsString($traceFile);
      $pipes = [];
      $process = $this->openChild($command, $resultFile, $traceFile, $pipes);
      $children[] = [$process, $pipes, $resultFile, $traceFile];
    }
    $results = [];
    foreach ($children as [$process, $pipes, $resultFile, $traceFile]) {
      $results[] = $this->finishChild($process, $pipes, $resultFile, $traceFile);
    }
    return $results;
  }

  /**
   * Starts one child claim.
   *
   * @param array<string, int|string> $command
   *   Child command fields.
   * @param string $resultFile
   *   File that receives the child result.
   * @param string $traceFile
   *   File that receives child progress markers.
   * @param array<int, resource> $pipes
   *   Process pipes, filled by reference.
   *
   * @return resource
   *   The child process.
   */
  private function openChild(array $command, string $resultFile, string $traceFile, array &$pipes) {
    $env = getenv();
    $this->assertIsArray($env);
    $env['AGENTDOCK_AUTOLOAD'] = dirname(DRUPAL_ROOT) . '/vendor/autoload.php';
    $env['AGENTDOCK_RECORD_REQUEST'] = json_encode([
      'connection' => $this->connectionOptions(),
      'command' => $command,
    ], JSON_THROW_ON_ERROR);
    $env['AGENTDOCK_RESULT_FILE'] = $resultFile;
    $env['AGENTDOCK_TRACE_FILE'] = $traceFile;
    $process = proc_open(
      [PHP_BINARY, __DIR__ . '/concurrent_claim.php'],
      [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
      ],
      $pipes,
      dirname(DRUPAL_ROOT),
      $env,
    );
    $this->assertIsResource($process);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], FALSE);
    stream_set_blocking($pipes[2], FALSE);
    return $process;
  }

  /**
   * Waits for one child and deletes its files.
   *
   * @param resource $process
   *   The child process.
   * @param array<int, resource> $pipes
   *   The child pipes.
   * @param string $resultFile
   *   File written by the child.
   * @param string $traceFile
   *   File written by the child.
   *
   * @return array<string, mixed>
   *   The child result.
   */
  private function finishChild($process, array $pipes, string $resultFile, string $traceFile): array {
    $stdout = '';
    $stderr = '';
    $deadline = microtime(TRUE) + 15;
    $exitCode = NULL;
    do {
      $stdout .= $this->readPipe($pipes[1]);
      $stderr .= $this->readPipe($pipes[2]);
      $status = proc_get_status($process);
      if (!$status['running']) {
        $exitCode = $status['exitcode'];
        break;
      }
      usleep(20000);
    } while (microtime(TRUE) < $deadline);
    if ($exitCode === NULL) {
      proc_terminate($process);
    }
    $stdout .= $this->readPipe($pipes[1]);
    $stderr .= $this->readPipe($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $decoded = json_decode((string) file_get_contents($resultFile), TRUE);
    if (is_file($resultFile)) {
      unlink($resultFile);
    }
    if (is_file($traceFile)) {
      unlink($traceFile);
    }
    $this->assertSame(0, $exitCode, $stderr . $stdout);
    $this->assertIsArray($decoded);
    return $decoded;
  }

  /**
   * Reads available bytes from a pipe.
   *
   * @param resource $pipe
   *   The pipe to read.
   */
  private function readPipe($pipe): string {
    $chunk = fread($pipe, 8192);
    return is_string($chunk) ? $chunk : '';
  }

  /**
   * Returns connection options that stay on the prefixed test database.
   *
   * @return array<string, mixed>
   *   Driver options safe to pass to another process.
   */
  private function connectionOptions(): array {
    $database = $this->container->get('database');
    $this->assertInstanceOf(Connection::class, $database);
    $options = $database->getConnectionOptions();
    unset($options['pdo']);
    foreach ($options as $key => $value) {
      if (is_object($value)) {
        unset($options[$key]);
      }
    }
    $prefix = $options['prefix'] ?? '';
    $this->assertIsString($prefix);
    $this->assertStringStartsWith('test', $prefix);
    return $options;
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
   * Reloads an execution after a rejected move.
   */
  private function reloadExecution(Execution $execution): Execution {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_execution');
    $storage->resetCache([(int) $execution->id()]);
    $reloaded = $storage->load($execution->id());
    $this->assertInstanceOf(Execution::class, $reloaded);
    return $reloaded;
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
    return $execution;
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
   * Authenticates the account.
   */
  private function setCurrentUser(User $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Returns the test request time.
   */
  private function now(): int {
    return (int) $this->container->get('datetime.time')->getRequestTime();
  }

}
