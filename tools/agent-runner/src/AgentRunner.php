<?php

declare(strict_types=1);

namespace AgentRunner;

/**
 * Walks one agent through claim, progress, a question, and submit.
 *
 * Configuration comes from the environment. Local state keeps the list
 * cursor, the active execution, and the idempotency keys. The lease stays
 * in that file. This process never approves work and never reads storage
 * beyond that file.
 */
final class AgentRunner {

  private const PROGRESS = '{"note":"Progresso deterministico"}';

  private const QUESTION = '{"body":"Preciso de uma decisao humana"}';

  private const SUMMARY = '{"summary":"Entrega deterministica"}';

  /**
   * Cursor, active execution, and idempotency keys.
   *
   * @var array<string, mixed>
   */
  private array $state;

  public function __construct(
    private readonly HttpClient $client,
    private readonly string $workspace,
    private readonly string $statePath,
    private readonly int $interval,
  ) {
    $this->state = [
      'cursors' => [],
      'execution' => NULL,
      'keys' => [],
    ];
  }

  /**
   * Builds a runner from the environment, before any request.
   */
  public static function fromEnvironment(): self {
    $url = self::required('AGENT_BOARD_URL');
    if (!preg_match('#^https?://#', $url)) {
      throw new \RuntimeException('AGENT_BOARD_URL is invalid.');
    }
    $token = self::required('AGENT_BOARD_TOKEN');
    $workspace = self::required('AGENT_BOARD_WORKSPACE');
    if (!preg_match('/^[0-9a-f-]{36}$/', $workspace)) {
      throw new \RuntimeException('AGENT_BOARD_WORKSPACE is invalid.');
    }
    $path = self::required('AGENT_BOARD_STATE');
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
      throw new \RuntimeException('AGENT_BOARD_STATE is not writable.');
    }
    $timeout = self::integer('AGENT_BOARD_TIMEOUT', 10, 1);
    $interval = self::integer('AGENT_BOARD_INTERVAL', 30, 0);
    $cookie = getenv('AGENT_BOARD_COOKIE');
    return new self(
      new HttpClient($url, $token, $timeout, is_string($cookie) ? $cookie : ''),
      $workspace,
      $path,
      $interval,
    );
  }

  /**
   * Claims an eligible task, or continues the stored execution.
   *
   * Stops when the lease is no longer valid. A question waits for a human
   * answer. The next invocation submits only after that answer.
   */
  public function run(): int {
    $this->load();
    for ($step = 0; $step < 8; $step++) {
      if ($this->execution() === NULL) {
        if (!$this->claimEligible()) {
          $this->line('idle');
          return 0;
        }
        continue;
      }
      $status = $this->validate();
      if ($status === NULL || !$this->renew(FALSE)) {
        $this->line('stopped');
        return 2;
      }
      if ($status === 'waiting_input') {
        $request = $this->execution()['request_id'] ?? '';
        $this->line('waiting ' . (is_string($request) ? $request : ''));
        return 0;
      }
      $execution = $this->execution();
      if (empty($execution['progress'])) {
        $this->progress();
        continue;
      }
      if (empty($execution['asked'])) {
        $this->ask();
        continue;
      }
      $id = (string) $execution['id'];
      $this->submit();
      $this->clearExecution();
      $this->line('submitted ' . $id);
      return 0;
    }
    throw new \RuntimeException('The runner did not settle.');
  }

  /**
   * Renews the stored lease and does no other work.
   */
  public function heartbeat(): int {
    $this->load();
    if ($this->execution() === NULL) {
      $this->line('idle');
      return 0;
    }
    if ($this->validate() === NULL || !$this->renew(TRUE)) {
      $this->line('stopped');
      return 2;
    }
    $this->line('heartbeat');
    return 0;
  }

  /**
   * Claims the first ready task that accepts this agent.
   */
  private function claimEligible(): bool {
    $boards = $this->pages('/workspaces/' . $this->workspace . '/boards', 'boards');
    foreach ($boards as $board) {
      if (($board['archived'] ?? FALSE) === TRUE) {
        continue;
      }
      $boardId = $board['id'] ?? NULL;
      if (!is_string($boardId)) {
        continue;
      }
      $path = '/workspaces/' . $this->workspace . '/boards/' . $boardId . '/tasks?status=ready&limit=50';
      foreach ($this->pages($path, 'tasks:' . $boardId) as $task) {
        $taskId = $task['id'] ?? NULL;
        if (is_string($taskId) && $this->claim($taskId)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Claims one task. A conflict means the task is not eligible.
   */
  private function claim(string $taskId): bool {
    $response = $this->client->send(
      'POST',
      '/workspaces/' . $this->workspace . '/tasks/' . $taskId . '/claim',
      '{}',
      ['Idempotency-Key' => $this->key('task.claim', $taskId)],
    );
    if ($response['status'] === 409) {
      return FALSE;
    }
    if ($response['status'] !== 201) {
      throw new \RuntimeException('claim failed: ' . $this->describe($response));
    }
    $data = $response['json']['data'] ?? NULL;
    $execution = is_array($data) ? ($data['execution_id'] ?? NULL) : NULL;
    $task = is_array($data) ? ($data['task_id'] ?? NULL) : NULL;
    $lease = is_array($data) ? ($data['lease_token'] ?? NULL) : NULL;
    if (!is_string($execution) || !is_string($task) || !is_string($lease)) {
      throw new \RuntimeException('claim response was not understood.');
    }
    $this->state['execution'] = [
      'id' => $execution,
      'task_id' => $task,
      'lease_token' => $lease,
      'progress' => FALSE,
      'asked' => FALSE,
      'request_id' => '',
      'beats' => 0,
      'last_heartbeat' => 0,
    ];
    $this->save();
    $this->line('claimed ' . $execution);
    return TRUE;
  }

  /**
   * Reads the execution and drops it when it can no longer continue.
   */
  private function validate(): ?string {
    $execution = $this->execution();
    if ($execution === NULL) {
      return NULL;
    }
    $response = $this->client->send('GET', $this->executionPath($execution), NULL, []);
    $status = $response['json']['data']['status'] ?? NULL;
    if ($response['status'] !== 200 || !is_string($status)) {
      $this->clearExecution();
      return NULL;
    }
    if (!in_array($status, ['running', 'waiting_input'], TRUE)) {
      $this->clearExecution();
      return NULL;
    }
    return $status;
  }

  /**
   * Renews the lease when the interval has elapsed.
   */
  private function renew(bool $force): bool {
    $execution = $this->execution();
    if ($execution === NULL) {
      return FALSE;
    }
    $last = (int) ($execution['last_heartbeat'] ?? 0);
    if (!$force && (time() - $last) < $this->interval) {
      return TRUE;
    }
    $beat = (int) ($execution['beats'] ?? 0);
    $response = $this->client->send(
      'POST',
      $this->executionPath($execution) . '/heartbeat',
      '{}',
      [
        'Idempotency-Key' => $this->key('execution.heartbeat', $execution['id'] . ':' . $beat),
        'X-Execution-Lease' => (string) $execution['lease_token'],
      ],
    );
    if ($response['status'] !== 200) {
      $this->clearExecution();
      return FALSE;
    }
    $execution['beats'] = $beat + 1;
    $execution['last_heartbeat'] = time();
    $this->state['execution'] = $execution;
    $this->save();
    return TRUE;
  }

  /**
   * Records the fixed progress note once.
   */
  private function progress(): void {
    $execution = $this->mustExecution();
    $response = $this->client->send(
      'POST',
      $this->executionPath($execution) . '/progress',
      self::PROGRESS,
      [
        'Idempotency-Key' => $this->key('execution.progress', (string) $execution['id']),
        'X-Execution-Lease' => (string) $execution['lease_token'],
      ],
    );
    if ($response['status'] !== 201) {
      throw new \RuntimeException('progress failed: ' . $this->describe($response));
    }
    $execution['progress'] = TRUE;
    $this->state['execution'] = $execution;
    $this->save();
  }

  /**
   * Asks a human and remembers the request so a retry does not ask again.
   */
  private function ask(): void {
    $execution = $this->mustExecution();
    $response = $this->client->send(
      'POST',
      $this->executionPath($execution) . '/request-input',
      self::QUESTION,
      [
        'Idempotency-Key' => $this->key('execution.request_input', (string) $execution['id']),
        'X-Execution-Lease' => (string) $execution['lease_token'],
      ],
    );
    if ($response['status'] !== 201) {
      throw new \RuntimeException('request-input failed: ' . $this->describe($response));
    }
    $request = $response['json']['data']['id'] ?? NULL;
    if (!is_string($request)) {
      throw new \RuntimeException('request-input response was not understood.');
    }
    $execution['asked'] = TRUE;
    $execution['request_id'] = $request;
    $this->state['execution'] = $execution;
    $this->save();
  }

  /**
   * Submits the fixed summary. The caller clears the execution after this.
   */
  private function submit(): void {
    $execution = $this->mustExecution();
    $response = $this->client->send(
      'POST',
      $this->executionPath($execution) . '/submit',
      self::SUMMARY,
      [
        'Idempotency-Key' => $this->key('execution.submit', (string) $execution['id']),
        'X-Execution-Lease' => (string) $execution['lease_token'],
      ],
    );
    if ($response['status'] !== 200) {
      throw new \RuntimeException('submit failed: ' . $this->describe($response));
    }
  }

  /**
   * Lists every page and stores the cursor after each one.
   *
   * @return list<array<string, mixed>>
   *   Documents from the page.
   */
  private function pages(string $path, string $cursorKey): array {
    $stored = $this->state['cursors'][$cursorKey] ?? NULL;
    $cursor = is_string($stored) ? $stored : NULL;
    $rows = [];
    $reset = FALSE;
    for ($page = 0; $page < 20; $page++) {
      $query = $path;
      if ($cursor !== NULL && $cursor !== '') {
        $query .= (str_contains($path, '?') ? '&' : '?') . 'cursor=' . rawurlencode($cursor);
      }
      $response = $this->client->send('GET', $query, NULL, []);
      if ($response['status'] !== 200) {
        throw new \RuntimeException('list failed: ' . $this->describe($response));
      }
      $data = $response['json']['data'] ?? NULL;
      if (!is_array($data)) {
        throw new \RuntimeException('list response was not understood.');
      }
      if ($data === [] && $cursor !== NULL && $cursor !== '' && !$reset) {
        $cursor = NULL;
        $reset = TRUE;
        continue;
      }
      foreach ($data as $row) {
        if (is_array($row)) {
          $rows[] = $row;
        }
      }
      $next = $response['json']['page']['next_cursor'] ?? NULL;
      $cursor = is_string($next) && $next !== '' ? $next : NULL;
      if (!is_array($this->state['cursors'])) {
        $this->state['cursors'] = [];
      }
      $this->state['cursors'][$cursorKey] = $cursor;
      $this->save();
      if ($cursor === NULL) {
        break;
      }
    }
    return $rows;
  }

  /**
   * Returns the stored key, creating it before the request.
   */
  private function key(string $operation, string $resource): string {
    $name = $operation . ':' . $resource;
    if (!is_array($this->state['keys'])) {
      $this->state['keys'] = [];
    }
    $existing = $this->state['keys'][$name] ?? NULL;
    if (is_string($existing)) {
      return $existing;
    }
    $created = self::uuid();
    $this->state['keys'][$name] = $created;
    $this->save();
    return $created;
  }

  /**
   * Returns the stored execution.
   *
   * @return array<string, mixed>|null
   *   The active execution, when one is stored.
   */
  private function execution(): ?array {
    $execution = $this->state['execution'] ?? NULL;
    return is_array($execution) ? $execution : NULL;
  }

  /**
   * Returns the stored execution or stops.
   *
   * @return array<string, mixed>
   *   The active execution.
   */
  private function mustExecution(): array {
    $execution = $this->execution();
    if ($execution === NULL) {
      throw new \RuntimeException('The active execution is missing.');
    }
    return $execution;
  }

  /**
   * Builds the path of one execution.
   *
   * @param array<string, mixed> $execution
   *   The stored execution.
   */
  private function executionPath(array $execution): string {
    return '/workspaces/' . $this->workspace . '/executions/' . $execution['id'];
  }

  /**
   * Drops the active execution so a later invocation cannot continue it.
   */
  private function clearExecution(): void {
    $this->state['execution'] = NULL;
    $this->save();
  }

  /**
   * Reads the state file. A missing file starts empty.
   */
  private function load(): void {
    if (!is_file($this->statePath)) {
      return;
    }
    $decoded = json_decode((string) file_get_contents($this->statePath), TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException('AGENT_BOARD_STATE is invalid.');
    }
    $this->state = $decoded + $this->state;
  }

  /**
   * Writes the state file without leaving a partial copy.
   */
  private function save(): void {
    $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $temporary = $this->statePath . '.tmp';
    if (file_put_contents($temporary, $json) === FALSE) {
      throw new \RuntimeException('AGENT_BOARD_STATE could not be written.');
    }
    chmod($temporary, 0600);
    if (!rename($temporary, $this->statePath)) {
      throw new \RuntimeException('AGENT_BOARD_STATE could not be written.');
    }
  }

  /**
   * Describes a failed response without its body.
   *
   * @param array{status: int, json: array<string, mixed>|null} $response
   *   A response that failed.
   */
  private function describe(array $response): string {
    $code = $response['json']['code'] ?? '';
    return $response['status'] . ' ' . (is_string($code) ? $code : '');
  }

  /**
   * Prints one machine-readable line. Secrets stay out of it.
   */
  private function line(string $text): void {
    fwrite(STDOUT, $text . "\n");
  }

  /**
   * Reads a required environment variable.
   */
  private static function required(string $name): string {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
      throw new \RuntimeException('Missing ' . $name);
    }
    return $value;
  }

  /**
   * Reads an integer environment variable.
   */
  private static function integer(string $name, int $default, int $minimum): int {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
      return $default;
    }
    if (!preg_match('/^[0-9]+$/', $value)) {
      throw new \RuntimeException($name . ' is invalid.');
    }
    $parsed = (int) $value;
    if ($parsed < $minimum) {
      throw new \RuntimeException($name . ' is invalid.');
    }
    return $parsed;
  }

  /**
   * Builds a lowercase UUID for an idempotency key.
   */
  private static function uuid(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
    $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8)
      . '-' . substr($hex, 8, 4)
      . '-' . substr($hex, 12, 4)
      . '-' . substr($hex, 16, 4)
      . '-' . substr($hex, 20);
  }

}
