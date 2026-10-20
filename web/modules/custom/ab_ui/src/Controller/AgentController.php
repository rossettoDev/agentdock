<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Controller;

use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentRepository;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_ui\Form\AgentEditForm;
use Drupal\ab_ui\Form\AgentGrantForm;
use Drupal\ab_ui\Form\AgentTokenForm;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lists agents of the selected workspace and opens one by UUID.
 *
 * The screen shows enabled or disabled. A live execution can show the
 * last heartbeat. It does not call the agent online.
 */
final class AgentController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly AgentRepository $agents,
    private readonly AgentGrantManager $grants,
    private readonly AgentTokenManager $tokens,
    private readonly WorkspaceContext $context,
    private readonly MembershipScope $scope,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
    private readonly AccountInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly Connection $database,
  ) {}

  /**
   * Lists agents of the selected workspace.
   *
   * The query only filters that list. It does not select a workspace.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function listing(Request $request): array {
    $workspace = $this->context->workspace();
    if (!$workspace instanceof Workspace) {
      return [
        '#theme' => 'ab_ui_agent_list',
        '#workspace_label' => NULL,
        '#add_url' => '',
        '#items' => [],
        '#cache' => $this->cacheMeta(NULL),
      ];
    }
    $can_manage = $this->scope->allows(
      $this->currentUser,
      $workspace,
      WorkspacePermission::MANAGE_AGENTS,
    );
    $q = $this->searchQuery($request);
    $enabled = $this->enabledQuery($request);
    $work = $this->workQuery($request);
    $agents = $this->agents->accessibleAgents($workspace);
    $facts = $this->executionFacts($workspace);
    $items = [];
    foreach ($agents as $agent) {
      if (!$this->matchesAgent($agent, $q, $enabled, $work, $facts)) {
        continue;
      }
      $live = $this->liveCount($agent, $facts);
      $items[] = [
        'label' => (string) $agent->label(),
        'url' => Url::fromRoute('ab_ui.agent_view', [
          'uuid' => $agent->uuid(),
        ])->toString(),
        'status' => $this->statusLabel($agent),
        'limit' => $agent->get('concurrency_limit')->getString(),
        'activity' => $this->activityLabel($this->activityKey($agent, $facts)),
        'live' => $live > 1 ? (string) $live : '',
      ];
    }
    $cache = $this->cacheMeta($workspace);
    $cache['contexts'][] = 'url.query_args:q';
    $cache['contexts'][] = 'url.query_args:enabled';
    $cache['contexts'][] = 'url.query_args:work';
    return [
      '#theme' => 'ab_ui_agent_list',
      '#workspace_label' => (string) $workspace->label(),
      '#add_url' => $can_manage ? Url::fromRoute('ab_ui.agent_add')->toString() : '',
      '#items' => $items,
      '#q' => $q,
      '#enabled' => $enabled,
      '#work' => $work,
      '#filters' => $this->filterLinks($q, $enabled, $work),
      '#counts' => $this->countLines($agents, $facts),
      '#filter_empty' => $agents !== [] && $items === [],
      '#list_url' => Url::fromRoute('ab_ui.agents')->toString(),
      '#cache' => $cache,
    ];
  }

  /**
   * Shows one readable agent without changing the selected workspace.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function view(string $uuid): array {
    $agent = $this->loadVisible($uuid);
    $workspace = $this->workspaceOf($agent);
    $can_manage = $workspace instanceof Workspace && $this->scope->allows(
      $this->currentUser,
      $workspace,
      WorkspacePermission::MANAGE_AGENTS,
    );
    $can_credentials = $workspace instanceof Workspace && $this->scope->allows(
      $this->currentUser,
      $workspace,
      WorkspacePermission::MANAGE_CREDENTIALS,
    );
    $cards = ['live' => [], 'closed' => []];
    if ($workspace instanceof Workspace) {
      $cards = $this->executionCards($workspace, $agent);
    }
    $facts = $workspace instanceof Workspace ? $this->executionFacts($workspace) : $this->emptyFacts();
    $build = [
      '#theme' => 'ab_ui_agent_view',
      '#label' => (string) $agent->label(),
      '#description' => $agent->get('description')->getString(),
      '#capabilities' => $agent->get('capabilities')->getString(),
      '#status' => $this->statusLabel($agent),
      '#activity' => $this->activityLabel($this->activityKey($agent, $facts)),
      '#limit' => $agent->get('concurrency_limit')->getString(),
      '#signal' => $this->signalLabel($agent, $workspace),
      '#executions' => $cards['live'],
      '#closed' => $cards['closed'],
      '#grants' => $this->grantLabels($agent),
      '#tokens' => $can_credentials && $workspace instanceof Workspace ? $this->tokenLines($agent, $workspace) : [],
      '#access_note' => $this->accessNote($can_manage, $can_credentials),
      '#back_url' => Url::fromRoute('ab_ui.agents')->toString(),
      '#edit_form' => NULL,
      '#grant_form' => NULL,
      '#token_form' => NULL,
      '#cache' => $this->cacheMeta($workspace),
    ];
    $build['#cache']['tags'][] = 'ab_agent_tokens:' . $agent->id();
    $build['#cache']['tags'][] = 'ab_agent_signal:' . $agent->id();
    if ($can_manage) {
      $build['#edit_form'] = $this->formBuilder->getForm(AgentEditForm::class, $agent->uuid());
      $build['#grant_form'] = $this->formBuilder->getForm(AgentGrantForm::class, $agent->uuid());
    }
    if ($can_credentials) {
      $build['#token_form'] = $this->formBuilder->getForm(AgentTokenForm::class, $agent->uuid());
    }
    return $build;
  }

  /**
   * Loads a readable agent or answers 404.
   */
  private function loadVisible(string $uuid): Agent {
    $agent = $this->agents->loadAccessibleByUuid($uuid);
    if ($agent instanceof Agent) {
      return $agent;
    }
    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'ab_memberships', 'ab_workspace'])
      ->addCacheTags(['ab_membership_list:' . $this->currentUser->id()]);
    throw new CacheableNotFoundHttpException($cache);
  }

  /**
   * Loads the workspace stored on the agent.
   */
  private function workspaceOf(Agent $agent): ?Workspace {
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($agent->get('workspace')->getString());
    return $workspace instanceof Workspace ? $workspace : NULL;
  }

  /**
   * Reads the search text. It never selects a workspace.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  private function searchQuery(Request $request): string {
    $q = $request->query->get('q');
    if (!is_string($q)) {
      return '';
    }
    $q = trim($q);
    if (mb_strlen($q) > 80) {
      $q = mb_substr($q, 0, 80);
    }
    return $q;
  }

  /**
   * Reads the administrative enabled filter.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  private function enabledQuery(Request $request): string {
    $enabled = $request->query->get('enabled');
    return $enabled === '0' || $enabled === '1' ? $enabled : '';
  }

  /**
   * Reads the activity filter.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  private function workQuery(Request $request): string {
    $work = $request->query->get('work');
    $allowed = ['running', 'waiting', 'idle', 'attention'];
    if (is_string($work) && in_array($work, $allowed, TRUE)) {
      return $work;
    }
    return '';
  }

  /**
   * Reports whether the agent stays inside the selected filters.
   *
   * @param \Drupal\ab_agent\Entity\Agent $agent
   *   Agent already loaded for this workspace.
   * @param string $q
   *   Search text.
   * @param string $enabled
   *   Enabled filter, or an empty string.
   * @param string $work
   *   Activity filter, or an empty string.
   * @param array<string, mixed> $facts
   *   Execution counts for the workspace.
   */
  private function matchesAgent(Agent $agent, string $q, string $enabled, string $work, array $facts): bool {
    if ($q !== '' && !$this->matchesText($agent, $q)) {
      return FALSE;
    }
    $is_enabled = (int) $agent->get('enabled')->getString() === 1;
    if ($enabled === '1' && !$is_enabled) {
      return FALSE;
    }
    if ($enabled === '0' && $is_enabled) {
      return FALSE;
    }
    $key = $this->activityKey($agent, $facts);
    $attention = $facts['attention'][(int) $agent->id()] ?? 0;
    return match ($work) {
      'running' => $key === 'running',
      'waiting' => $key === 'waiting_input',
      'idle' => $key === 'idle',
      'attention' => $attention > 0,
      default => TRUE,
    };
  }

  /**
   * Matches the search text against the agent's own fields.
   *
   * @param \Drupal\ab_agent\Entity\Agent $agent
   *   Agent already loaded for this workspace.
   * @param string $q
   *   Search text.
   */
  private function matchesText(Agent $agent, string $q): bool {
    $haystack = [
      (string) $agent->label(),
      $agent->get('description')->getString(),
      $agent->get('capabilities')->getString(),
    ];
    foreach ($haystack as $text) {
      if (mb_stripos($text, $q) !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Builds the filter links. Empty values are omitted.
   *
   * @param string $q
   *   Search text.
   * @param string $enabled
   *   Enabled filter, or an empty string.
   * @param string $work
   *   Activity filter, or an empty string.
   *
   * @return list<array{label: string, url: string, current: bool}>
   *   One link per filter.
   */
  private function filterLinks(string $q, string $enabled, string $work): array {
    $links = [[
      'label' => (string) $this->t('Todos'),
      'url' => $this->agentUrl(['q' => $q]),
      'current' => $enabled === '' && $work === '',
    ]];
    $choices = [
      'enabled:1' => (string) $this->t('Habilitados'),
      'enabled:0' => (string) $this->t('Desabilitados'),
      'work:running' => (string) $this->t('Em execução'),
      'work:waiting' => (string) $this->t('Aguardando informação'),
      'work:idle' => (string) $this->t('Aguardando tarefa'),
      'work:attention' => (string) $this->t('Sem continuação'),
    ];
    foreach ($choices as $choice => $label) {
      [$kind, $value] = explode(':', $choice, 2);
      $next_enabled = $kind === 'enabled' ? $value : $enabled;
      $next_work = $kind === 'work' ? $value : $work;
      $links[] = [
        'label' => $label,
        'url' => $this->agentUrl([
          'q' => $q,
          'enabled' => $next_enabled,
          'work' => $next_work,
        ]),
        'current' => $kind === 'enabled' ? $enabled === $value : $work === $value,
      ];
    }
    return $links;
  }

  /**
   * Builds the agent list URL without an empty query.
   *
   * @param array<string, string> $query
   *   Query arguments. Empty values are omitted.
   */
  private function agentUrl(array $query): string {
    $kept = [];
    foreach ($query as $key => $value) {
      if ($value !== '') {
        $kept[$key] = $value;
      }
    }
    return Url::fromRoute('ab_ui.agents', [], ['query' => $kept])->toString();
  }

  /**
   * Counts agents and stalled executions inside this workspace.
   *
   * @param \Drupal\ab_agent\Entity\Agent[] $agents
   *   Agents already loaded for this workspace.
   * @param array<string, mixed> $facts
   *   Execution counts for the workspace.
   *
   * @return list<array{label: string, value: string}>
   *   One integer per allowed count.
   */
  private function countLines(array $agents, array $facts): array {
    $enabled = 0;
    $disabled = 0;
    $running = 0;
    $waiting = 0;
    $idle = 0;
    foreach ($agents as $agent) {
      if ((int) $agent->get('enabled')->getString() === 1) {
        $enabled++;
      }
      else {
        $disabled++;
      }
      $key = $this->activityKey($agent, $facts);
      if ($key === 'running') {
        $running++;
      }
      elseif ($key === 'waiting_input') {
        $waiting++;
      }
      elseif ($key === 'idle') {
        $idle++;
      }
    }
    return [
      ['label' => (string) $this->t('Habilitados'), 'value' => (string) $enabled],
      ['label' => (string) $this->t('Desabilitados'), 'value' => (string) $disabled],
      ['label' => (string) $this->t('Em execução'), 'value' => (string) $running],
      [
        'label' => (string) $this->t('Aguardando informação'),
        'value' => (string) $waiting,
      ],
      ['label' => (string) $this->t('Aguardando tarefa'), 'value' => (string) $idle],
      [
        'label' => (string) $this->t('Sem continuação'),
        'value' => (string) $facts['attention_total'],
      ],
    ];
  }

  /**
   * Collects live and stalled execution counts for the workspace.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the agents.
   *
   * @return array<string, mixed>
   *   Live counts by agent, stalled counts by agent, and the stalled total.
   */
  private function executionFacts(Workspace $workspace): array {
    $facts = $this->emptyFacts();
    $live = $this->database->select('ab_execution', 'e');
    $live->fields('e', ['agent', 'status']);
    $live->addExpression('COUNT(*)', 'n');
    $live->condition('workspace', (int) $workspace->id());
    $live->condition('status', ['running', 'waiting_input'], 'IN');
    $live->groupBy('agent');
    $live->groupBy('status');
    foreach ($live->execute() as $row) {
      $agent = (int) $row->agent;
      $status = (string) $row->status;
      $facts['live'][$agent][$status] = (int) $row->n;
    }
    $stalled = $this->database->select('ab_execution', 'e');
    $stalled->join('ab_task', 't', 't.id = e.task');
    $stalled->addField('e', 'agent');
    $stalled->addExpression('COUNT(*)', 'n');
    $stalled->condition('e.workspace', (int) $workspace->id());
    $stalled->condition('t.workspace', (int) $workspace->id());
    $stalled->condition('e.status', ['failed', 'interrupted'], 'IN');
    $open = $stalled->orConditionGroup()
      ->isNull('t.active_execution')
      ->condition('t.active_execution', 0);
    $stalled->condition($open);
    $stalled->groupBy('e.agent');
    foreach ($stalled->execute() as $row) {
      $facts['attention'][(int) $row->agent] = (int) $row->n;
      $facts['attention_total'] += (int) $row->n;
    }
    return $facts;
  }

  /**
   * Returns the empty execution counts.
   *
   * @return array<string, mixed>
   *   Live and stalled maps with a zero total.
   */
  private function emptyFacts(): array {
    return [
      'live' => [],
      'attention' => [],
      'attention_total' => 0,
    ];
  }

  /**
   * Returns the activity key stored for this agent.
   *
   * Enabled and idle is the only case called waiting for a task.
   *
   * @param \Drupal\ab_agent\Entity\Agent $agent
   *   Agent already loaded for this workspace.
   * @param array<string, mixed> $facts
   *   Execution counts for the workspace.
   */
  private function activityKey(Agent $agent, array $facts): string {
    $live = $facts['live'][(int) $agent->id()] ?? [];
    if (($live['running'] ?? 0) > 0) {
      return 'running';
    }
    if (($live['waiting_input'] ?? 0) > 0) {
      return 'waiting_input';
    }
    if ((int) $agent->get('enabled')->getString() === 1) {
      return 'idle';
    }
    return '';
  }

  /**
   * Returns the Portuguese label of an activity key.
   *
   * @param string $key
   *   Activity key, or an empty string.
   */
  private function activityLabel(string $key): string {
    return match ($key) {
      'running' => (string) $this->t('Em execução'),
      'waiting_input' => (string) $this->t('Aguardando informação'),
      'idle' => (string) $this->t('Aguardando tarefa'),
      default => '',
    };
  }

  /**
   * Counts live executions for one agent.
   *
   * @param \Drupal\ab_agent\Entity\Agent $agent
   *   Agent already loaded for this workspace.
   * @param array<string, mixed> $facts
   *   Execution counts for the workspace.
   */
  private function liveCount(Agent $agent, array $facts): int {
    $live = $facts['live'][(int) $agent->id()] ?? [];
    return (int) ($live['running'] ?? 0) + (int) ($live['waiting_input'] ?? 0);
  }

  /**
   * Lists live executions and the latest closed attempt.
   *
   * Rows from another workspace are skipped.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the agent.
   * @param \Drupal\ab_agent\Entity\Agent $agent
   *   Agent open on the screen.
   *
   * @return array<string, mixed>
   *   Current executions and one closed attempt.
   */
  private function executionCards(Workspace $workspace, Agent $agent): array {
    $rows = $this->database->select('ab_execution', 'e')
      ->fields('e', ['task', 'status', 'attempt', 'heartbeat_at', 'workspace'])
      ->condition('agent', (int) $agent->id())
      ->condition('workspace', (int) $workspace->id())
      ->orderBy('attempt')
      ->execute()
      ->fetchAll();
    $live = [];
    $closed = [];
    foreach ($rows as $row) {
      if ((string) $row->workspace !== (string) $workspace->id()) {
        continue;
      }
      $status = (string) $row->status;
      if (in_array($status, ['running', 'waiting_input'], TRUE)) {
        $live[] = $this->executionCard($workspace, $row);
        continue;
      }
      if (in_array($status, ['submitted', 'failed', 'interrupted', 'cancelled'], TRUE)) {
        $closed = $this->executionCard($workspace, $row);
      }
    }
    return ['live' => $live, 'closed' => $closed];
  }

  /**
   * Builds one execution line when the task belongs to the workspace.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace that owns the execution.
   * @param object $row
   *   Execution row from storage.
   *
   * @return array<string, string>
   *   Task, link, attempt, status and signal.
   */
  private function executionCard(Workspace $workspace, object $row): array {
    $task = $this->entityTypeManager->getStorage('ab_task')->load($row->task);
    $label = (string) $this->t('Tarefa');
    $url = '';
    $workspace_id = (string) $workspace->id();
    if ($task instanceof Task && (string) $task->get('workspace')->getString() === $workspace_id) {
      $label = (string) $task->label();
      $url = Url::fromRoute('ab_ui.task_view', [
        'uuid' => $task->uuid(),
      ])->toString();
    }
    $status = (string) $row->status;
    return [
      'task' => $label,
      'url' => $url,
      'attempt' => (string) $row->attempt,
      'status' => $this->executionBadge($status),
      'signal' => $this->rowSignal($workspace, $status, (int) $row->heartbeat_at),
    ];
  }

  /**
   * Returns the Portuguese label of an execution status.
   *
   * @param string $status
   *   Stored execution status.
   */
  private function executionBadge(string $status): string {
    return match ($status) {
      'running' => (string) $this->t('Em execução'),
      'waiting_input' => (string) $this->t('Aguardando informação'),
      'submitted' => (string) $this->t('Enviada'),
      'failed' => (string) $this->t('Falhou'),
      'interrupted' => (string) $this->t('Interrompida'),
      'cancelled' => (string) $this->t('Cancelada'),
      default => '',
    };
  }

  /**
   * Returns the signal for one live execution.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace whose timezone is used.
   * @param string $status
   *   Stored execution status.
   * @param int $heartbeat
   *   Heartbeat timestamp. Zero stays without a clock.
   */
  private function rowSignal(Workspace $workspace, string $status, int $heartbeat): string {
    if (!in_array($status, ['running', 'waiting_input'], TRUE)) {
      return '';
    }
    if ($heartbeat <= 0) {
      return (string) $this->t('Execução em andamento.');
    }
    $when = $this->clockLabel($workspace, $heartbeat);
    return $when === '' ? '' : (string) $this->t('Último sinal: @time', [
      '@time' => $when,
    ]);
  }

  /**
   * Formats a timestamp in the workspace timezone.
   *
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace whose timezone is used.
   * @param int $timestamp
   *   UTC unix time.
   */
  private function clockLabel(?Workspace $workspace, int $timestamp): string {
    if ($timestamp <= 0) {
      return '';
    }
    $timezone = $workspace instanceof Workspace ? $workspace->get('timezone')->getString() : '';
    try {
      $zone = new \DateTimeZone($timezone === '' ? 'America/Sao_Paulo' : $timezone);
    }
    catch (\Exception) {
      $zone = new \DateTimeZone('America/Sao_Paulo');
    }
    $when = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone);
    return $when->format('d/m/Y H:i');
  }

  /**
   * Tells the account which agent operations it can perform.
   *
   * @param bool $can_manage
   *   Whether the account can edit agents.
   * @param bool $can_credentials
   *   Whether the account can issue tokens.
   */
  private function accessNote(bool $can_manage, bool $can_credentials): string {
    if ($can_manage && $can_credentials) {
      return (string) $this->t('Você pode editar o agente e emitir credenciais.');
    }
    if ($can_manage) {
      return (string) $this->t('Você pode editar o agente. A emissão de credenciais não está nesta conta.');
    }
    return '';
  }

  /**
   * Shows a live execution without calling the agent online.
   */
  private function signalLabel(Agent $agent, ?Workspace $workspace): string {
    $query = $this->database->select('ab_execution', 'e');
    $query->addExpression('COUNT(*)');
    $query->condition('agent', (int) $agent->id());
    $query->condition('status', ['running', 'waiting_input'], 'IN');
    $running = (int) $query->execute()->fetchField();
    if ($running === 0) {
      return (string) $this->t('Não há sinal de execução.');
    }
    $heartbeat_query = $this->database->select('ab_execution', 'e');
    $heartbeat_query->addExpression('MAX(heartbeat_at)');
    $heartbeat_query->condition('agent', (int) $agent->id());
    $heartbeat_query->condition('status', ['running', 'waiting_input'], 'IN');
    $heartbeat = $heartbeat_query->execute()->fetchField();
    if ($heartbeat === FALSE || $heartbeat === NULL || (int) $heartbeat <= 0) {
      return (string) $this->t('Execução em andamento.');
    }
    $timezone = $workspace instanceof Workspace ? $workspace->get('timezone')->getString() : '';
    try {
      $zone = new \DateTimeZone($timezone === '' ? 'America/Sao_Paulo' : $timezone);
    }
    catch (\Exception) {
      $zone = new \DateTimeZone('America/Sao_Paulo');
    }
    $when = (new \DateTimeImmutable('@' . (int) $heartbeat))->setTimezone($zone);
    return (string) $this->t('Último sinal: @time', [
      '@time' => $when->format('d/m/Y H:i'),
    ]);
  }

  /**
   * Status the screen is allowed to show.
   */
  private function statusLabel(Agent $agent): string {
    $enabled = (int) $agent->get('enabled')->getString() === 1;
    return (string) ($enabled ? $this->t('Habilitado') : $this->t('Desabilitado'));
  }

  /**
   * Grant lines for the agent view.
   *
   * @return string[]
   *   One line per stored grant.
   */
  private function grantLabels(Agent $agent): array {
    $storage = $this->entityTypeManager->getStorage('ab_board');
    $workspace_id = (string) $agent->get('workspace')->getString();
    $lines = [];
    foreach ($this->grants->pairs($agent) as $pair) {
      $board = $storage->load($pair['board_id']);
      if (!$board instanceof Board) {
        continue;
      }
      if ((string) $board->get('workspace')->getString() !== $workspace_id) {
        continue;
      }
      $name = (string) $board->label();
      $labels = [];
      foreach (explode(',', $pair['permissions']) as $permission) {
        $labels[] = match ($permission) {
          WorkspacePermission::WRITE => (string) $this->t('Escrever'),
          WorkspacePermission::REVIEW => (string) $this->t('Revisar'),
          default => (string) $this->t('Ler'),
        };
      }
      $lines[] = $name . ': ' . implode(', ', $labels);
    }
    return $lines;
  }

  /**
   * Public token lines. The secret and the hash stay out.
   *
   * @return string[]
   *   One line per stored token.
   */
  private function tokenLines(Agent $agent, Workspace $workspace): array {
    $timezone = $workspace->get('timezone')->getString();
    try {
      $zone = new \DateTimeZone($timezone === '' ? 'America/Sao_Paulo' : $timezone);
    }
    catch (\Exception) {
      $zone = new \DateTimeZone('America/Sao_Paulo');
    }
    $lines = [];
    foreach ($this->tokens->list($agent) as $token) {
      $when = (new \DateTimeImmutable('@' . $token->expiresAt))->setTimezone($zone);
      if ($token->revokedAt !== NULL) {
        $state = (string) $this->t('Revogado');
      }
      elseif ($token->expiresAt <= $this->time->getRequestTime()) {
        $state = (string) $this->t('Expirado');
      }
      else {
        $state = (string) $this->t('Ativo');
      }
      $lines[] = 'ab_' . $token->publicId . ' · ' . $token->scopes . ' · ' . $when->format('d/m/Y') . ' · ' . $state;
    }
    return $lines;
  }

  /**
   * Cache metadata for one workspace list.
   *
   * @return array<string, mixed>
   *   Render cache metadata.
   */
  private function cacheMeta(?Workspace $workspace): array {
    $tags = ['ab_membership_list:' . $this->currentUser->id()];
    if ($workspace instanceof Workspace && $workspace->id() !== NULL) {
      $tags[] = 'ab_workspace_agents:' . $workspace->id();
    }
    return [
      'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
      'tags' => $tags,
    ];
  }

}
