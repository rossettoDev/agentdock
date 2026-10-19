<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Controller;

use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentRepository;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\Entity\Agent;
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
   * @return array<string, mixed>
   *   A render array.
   */
  public function listing(): array {
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
    $items = [];
    foreach ($this->agents->accessibleAgents($workspace) as $agent) {
      $items[] = [
        'label' => (string) $agent->label(),
        'url' => Url::fromRoute('ab_ui.agent_view', [
          'uuid' => $agent->uuid(),
        ])->toString(),
        'status' => $this->statusLabel($agent),
        'limit' => $agent->get('concurrency_limit')->getString(),
      ];
    }
    return [
      '#theme' => 'ab_ui_agent_list',
      '#workspace_label' => (string) $workspace->label(),
      '#add_url' => $can_manage ? Url::fromRoute('ab_ui.agent_add')->toString() : '',
      '#items' => $items,
      '#cache' => $this->cacheMeta($workspace),
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
    $build = [
      '#theme' => 'ab_ui_agent_view',
      '#label' => (string) $agent->label(),
      '#description' => $agent->get('description')->getString(),
      '#capabilities' => $agent->get('capabilities')->getString(),
      '#status' => $this->statusLabel($agent),
      '#limit' => $agent->get('concurrency_limit')->getString(),
      '#signal' => $this->signalLabel($agent, $workspace),
      '#grants' => $this->grantLabels($agent),
      '#tokens' => $can_credentials ? $this->tokenLines($agent, $workspace) : [],
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
    $lines = [];
    foreach ($this->grants->pairs($agent) as $pair) {
      $board = $storage->load($pair['board_id']);
      $name = $board === NULL ? $pair['board_id'] : (string) $board->label();
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
