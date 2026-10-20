<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_board\Kernel;

use Drupal\ab_agent\AgentManager;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\TaskManager;
use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Measures one workspace at the pilot volume. This is not a capacity claim.
 *
 * @group ab_board
 */
class PilotLoadKernelTest extends KernelTestBase {

  /**
   * Humans stored in the workspace.
   */
  private const HUMANS = 20;

  /**
   * Agents stored in the workspace.
   */
  private const AGENTS = 10;

  /**
   * Boards stored in the workspace.
   */
  private const BOARDS = 10;

  /**
   * Tasks stored across the boards.
   */
  private const TASKS = 5000;

  /**
   * Repeated reads of one board page.
   */
  private const READS = 100;

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
    $this->installSchema('ab_event', [
      'ab_workspace_sequence',
      'ab_event',
      'ab_outbox',
    ]);
    $this->installSchema('ab_agent', ['ab_agent_board_grant']);
  }

  /**
   * Creates the pilot volume and records the page-read latency.
   */
  public function testPilotVolumeStaysReadable(): void {
    $owner = $this->createUserAccount('owner', 'owner@example.com');
    $workspace = $this->creator()->create($owner, 'Casa piloto', 'America/Sao_Paulo');
    $this->setCurrentUser($owner);
    for ($index = 2; $index <= self::HUMANS; $index++) {
      $account = $this->createUserAccount('human' . $index, 'human' . $index . '@example.com');
      $this->membership($workspace, $account, 'member');
    }
    $boards = [];
    for ($index = 1; $index <= self::BOARDS; $index++) {
      $boards[] = $this->boards()->create($workspace, 'Quadro ' . $index, 'Texto');
    }
    for ($index = 1; $index <= self::AGENTS; $index++) {
      $this->agents()->create($workspace, 'Agente ' . $index, 'Texto', 'Revisao', TRUE, 1);
    }

    $errors = 0;
    $writes = [];
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_task');
    for ($index = 1; $index <= self::TASKS; $index++) {
      $board = $boards[($index - 1) % self::BOARDS];
      $started = hrtime(TRUE);
      try {
        $this->tasks()->create($board, [
          'title' => 'Tarefa ' . $index,
          'priority' => 'normal',
          'position' => $index,
        ]);
      }
      catch (\Throwable) {
        $errors++;
      }
      $writes[] = (hrtime(TRUE) - $started) / 1e6;
      if ($index % 200 === 0) {
        $storage->resetCache();
      }
    }

    $reads = [];
    $connection = $this->connection();
    $boardId = (int) $boards[0]->id();
    for ($index = 0; $index < self::READS; $index++) {
      $started = hrtime(TRUE);
      $query = $connection->select('ab_task', 't');
      $query->addField('t', 'id');
      $query->condition('board', $boardId);
      $query->orderBy('position');
      $query->orderBy('id');
      $query->range(0, 50);
      $query->execute();
      $reads[] = (hrtime(TRUE) - $started) / 1e6;
    }

    $humans = (int) $connection->select('ab_membership', 'm')
      ->condition('workspace', $workspace->id())
      ->condition('status', 'active')
      ->countQuery()
      ->execute()
      ->fetchField();
    $agents = (int) $connection->select('ab_agent', 'a')
      ->condition('workspace', $workspace->id())
      ->countQuery()
      ->execute()
      ->fetchField();
    $boardCount = (int) $connection->select('ab_board', 'b')
      ->condition('workspace', $workspace->id())
      ->countQuery()
      ->execute()
      ->fetchField();
    $taskCount = (int) $connection->select('ab_task', 't')
      ->condition('workspace', $workspace->id())
      ->countQuery()
      ->execute()
      ->fetchField();

    $report = sprintf(
      "pilot humans=%d agents=%d boards=%d tasks=%d errors=%d write_p95_ms=%.2f read_p95_ms=%.2f\n",
      $humans,
      $agents,
      $boardCount,
      $taskCount,
      $errors,
      $this->percentile($writes, 95),
      $this->percentile($reads, 95),
    );
    file_put_contents(DRUPAL_ROOT . '/sites/simpletest/pilot-load.txt', $report);

    $this->assertSame(self::HUMANS, $humans);
    $this->assertSame(self::AGENTS, $agents);
    $this->assertSame(self::BOARDS, $boardCount);
    $this->assertSame(self::TASKS, $taskCount);
    $this->assertSame(0, $errors);
    $this->assertGreaterThan(0, $this->percentile($reads, 95));
  }

  /**
   * Returns a percentile from durations in milliseconds.
   *
   * @param float[] $samples
   *   Durations in milliseconds.
   * @param int $percentile
   *   Percentile from 1 to 100.
   */
  private function percentile(array $samples, int $percentile): float {
    sort($samples, SORT_NUMERIC);
    $index = (int) ceil(count($samples) * $percentile / 100) - 1;
    return $samples[max(0, $index)];
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
   * Adds an active membership.
   */
  private function membership(Workspace $workspace, User $account, string $role): void {
    $membership = Membership::create([
      'workspace' => $workspace->id(),
      'user' => $account->id(),
      'role' => $role,
      'status' => 'active',
    ]);
    $membership->save();
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
   * Authenticates the account.
   */
  private function setCurrentUser(User $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Loads the test database connection.
   */
  private function connection(): Connection {
    $connection = $this->container->get('database');
    $this->assertInstanceOf(Connection::class, $connection);
    return $connection;
  }

}
