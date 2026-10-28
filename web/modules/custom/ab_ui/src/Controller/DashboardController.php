<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Controller;

use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_ui\DashboardReader;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Shows workspace counts that already exist in the selected session.
 *
 * The screen does not invent cost, presence or a percentage.
 */
final class DashboardController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly DashboardReader $reader,
    private readonly WorkspaceContext $context,
    private readonly MembershipScope $scope,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Renders the dashboard for the workspace selected in the session.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function view(): array {
    $workspace = $this->context->workspace();
    if (!$workspace instanceof Workspace || !$this->canRead($workspace)) {
      return [
        '#theme' => 'ab_ui_dashboard',
        '#workspace_label' => NULL,
        '#cards' => [],
        '#statuses' => [],
        '#boards' => [],
        '#reviews' => [],
        '#overdue' => [],
        '#requests' => [],
        '#threads' => [],
        '#events' => [],
        '#empty' => (string) $this->t('Nenhum workspace selecionado.'),
        '#cache' => $this->cacheMeta(NULL),
      ];
    }
    try {
      $data = $this->reader->read($workspace, $this->currentUser);
    }
    catch (ScopedResourceNotFoundException) {
      return [
        '#theme' => 'ab_ui_dashboard',
        '#workspace_label' => NULL,
        '#cards' => [],
        '#statuses' => [],
        '#boards' => [],
        '#reviews' => [],
        '#overdue' => [],
        '#requests' => [],
        '#threads' => [],
        '#events' => [],
        '#empty' => (string) $this->t('Nenhum workspace selecionado.'),
        '#cache' => $this->cacheMeta(NULL),
      ];
    }
    return [
      '#theme' => 'ab_ui_dashboard',
      '#workspace_label' => (string) $workspace->label(),
      '#cards' => $this->cards($data),
      '#statuses' => $this->statuses($data['statuses']),
      '#boards' => $this->boardCards($data['boards']),
      '#reviews' => $this->reviewLinks($data['reviews']),
      '#overdue' => $this->reviewLinks($data['overdue_tasks']),
      '#requests' => $this->requestLinks($data['requests']),
      '#threads' => $this->threadLinks($data['threads'], $workspace),
      '#events' => $this->eventLines($data['events'], $workspace),
      '#empty' => '',
      '#cache' => $this->cacheMeta($workspace),
    ];
  }

  /**
   * Builds the summary cards and the filters they open.
   *
   * @param array<string, mixed> $data
   *   Counts from the reader.
   *
   * @return list<array<string, mixed>>
   *   Label, value, url and note.
   */
  private function cards(array $data): array {
    $boards = $data['boards'];
    $only = count($boards) === 1 ? $boards[0] : NULL;
    $tasksUrl = is_array($only)
      ? Url::fromRoute('ab_ui.board_tasks', ['uuid' => $only['uuid']])->toString()
      : Url::fromRoute('ab_ui.boards')->toString();
    $todayUrl = is_array($only)
      ? Url::fromRoute('ab_ui.board_tasks', ['uuid' => $only['uuid']], [
        'query' => ['today' => '1'],
      ])->toString()
      : Url::fromRoute('ab_ui.boards')->toString();
    return [
      [
        'label' => (string) $this->t('Quadros'),
        'value' => (string) count($boards),
        'url' => Url::fromRoute('ab_ui.boards')->toString(),
        'note' => (string) $this->t('Quadros não arquivados.'),
      ],
      [
        'label' => (string) $this->t('Hoje'),
        'value' => (string) $data['today'],
        'url' => $todayUrl,
        'note' => (string) $this->t('Em andamento, ou prazo até o fim do dia.'),
      ],
      [
        'label' => (string) $this->t('Atrasadas'),
        'value' => (string) $data['overdue'],
        'url' => $todayUrl,
        'note' => (string) $this->t('Prazo anterior a hoje, ainda não concluída.'),
      ],
      [
        'label' => (string) $this->t('Em revisão'),
        'value' => (string) $data['review'],
        'url' => $tasksUrl,
        'note' => (string) $this->t('Aguardando aprovação humana.'),
      ],
      [
        'label' => (string) $this->t('Em execução'),
        'value' => (string) $data['running_agents'],
        'url' => Url::fromRoute('ab_ui.agents', [], [
          'query' => ['work' => 'running'],
        ])->toString(),
        'note' => (string) $this->t('Agentes com execução em andamento.'),
      ],
      [
        'label' => (string) $this->t('Aguardando informação'),
        'value' => (string) $data['waiting_agents'],
        'url' => Url::fromRoute('ab_ui.agents', [], [
          'query' => ['work' => 'waiting'],
        ])->toString(),
        'note' => (string) $this->t('Agentes parados em um pedido.'),
      ],
      [
        'label' => (string) $this->t('Não lidas'),
        'value' => (string) $data['unread'],
        'url' => Url::fromRoute('ab_ui.notifications')->toString(),
        'note' => (string) $this->t('Notificações suas neste workspace.'),
      ],
    ];
  }

  /**
   * Lists stored task statuses and their counts.
   *
   * @param array<string, int> $counts
   *   Counts keyed by status.
   *
   * @return list<array{label: string, value: string}>
   *   Portuguese label and count.
   */
  private function statuses(array $counts): array {
    $labels = [
      'backlog' => (string) $this->t('Backlog'),
      'ready' => (string) $this->t('Pronta'),
      'in_progress' => (string) $this->t('Em andamento'),
      'in_review' => (string) $this->t('Em revisão'),
      'done' => (string) $this->t('Concluída'),
      'cancelled' => (string) $this->t('Cancelada'),
    ];
    $items = [];
    foreach ($labels as $status => $label) {
      $items[] = [
        'label' => $label,
        'value' => (string) ($counts[$status] ?? 0),
      ];
    }
    return $items;
  }

  /**
   * Links each visible board to its kanban and Hoje filter.
   *
   * @param list<array<string, mixed>> $boards
   *   Board rows from the reader.
   *
   * @return list<array<string, mixed>>
   *   Name, counts and links.
   */
  private function boardCards(array $boards): array {
    $items = [];
    foreach ($boards as $board) {
      $tasks = Url::fromRoute('ab_ui.board_tasks', ['uuid' => $board['uuid']]);
      $items[] = [
        'name' => (string) $board['name'],
        'url' => $tasks->toString(),
        'today_url' => $tasks->setOption('query', ['today' => '1'])->toString(),
        'tasks' => (string) array_sum($board['statuses']),
        'today' => (string) $board['today'],
        'overdue' => (string) $board['overdue'],
        'review' => (string) $board['review'],
      ];
    }
    return $items;
  }

  /**
   * Links review tasks to their detail.
   *
   * @param list<array{uuid: string, title: string}> $reviews
   *   Tasks in review.
   *
   * @return list<array{label: string, url: string}>
   *   Title and task link.
   */
  private function reviewLinks(array $reviews): array {
    $items = [];
    foreach ($reviews as $review) {
      $items[] = [
        'label' => $review['title'],
        'url' => Url::fromRoute('ab_ui.task_view', [
          'uuid' => $review['uuid'],
        ])->toString(),
      ];
    }
    return $items;
  }

  /**
   * Links open requests to the task conversation.
   *
   * @param list<array{uuid: string, title: string, excerpt: string}> $requests
   *   Open requests.
   *
   * @return list<array{label: string, excerpt: string, url: string}>
   *   Title, excerpt and conversation link.
   */
  private function requestLinks(array $requests): array {
    $items = [];
    foreach ($requests as $request) {
      $items[] = [
        'label' => $request['title'],
        'excerpt' => $request['excerpt'],
        'url' => Url::fromRoute('ab_ui.task_conversation', [
          'uuid' => $request['uuid'],
        ])->toString(),
      ];
    }
    return $items;
  }

  /**
   * Links recent threads to the conversation screen.
   *
   * @param list<array<string, mixed>> $threads
   *   Threads from the reader.
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace used to format the time.
   *
   * @return list<array<string, string>>
   *   Label, kind, excerpt, time and url.
   */
  private function threadLinks(array $threads, Workspace $workspace): array {
    $items = [];
    foreach ($threads as $thread) {
      $items[] = [
        'label' => (string) $thread['label'],
        'kind' => $thread['kind'] === 'task'
          ? (string) $this->t('Tarefa')
          : (string) $this->t('Quadro'),
        'excerpt' => (string) $thread['excerpt'],
        'when' => $this->formatWhen($workspace, (int) $thread['created']),
        'url' => Url::fromRoute('ab_ui.conversation', [
          'uuid' => $thread['uuid'],
        ])->toString(),
      ];
    }
    return $items;
  }

  /**
   * Formats recent event types. The payload stays out.
   *
   * @param list<array{type: string, created: int}> $events
   *   Event type and timestamp.
   * @param \Drupal\ab_core\Entity\Workspace $workspace
   *   Workspace used to format the time.
   *
   * @return list<array{label: string, when: string}>
   *   Label and time.
   */
  private function eventLines(array $events, Workspace $workspace): array {
    $items = [];
    foreach ($events as $event) {
      $items[] = [
        'label' => $this->eventLabel($event['type']),
        'when' => $this->formatWhen($workspace, $event['created']),
      ];
    }
    return $items;
  }

  /**
   * Returns a short Portuguese label for a known event type.
   */
  private function eventLabel(string $type): string {
    return match ($type) {
      'task.created' => (string) $this->t('Tarefa criada'),
      'task.updated' => (string) $this->t('Tarefa atualizada'),
      'task.claimed' => (string) $this->t('Tarefa assumida'),
      'task.approved' => (string) $this->t('Tarefa aprovada'),
      'task.transitioned' => (string) $this->t('Tarefa movida'),
      'execution.submitted' => (string) $this->t('Execução enviada'),
      'board.created' => (string) $this->t('Quadro criado'),
      'board.archived' => (string) $this->t('Quadro arquivado'),
      'message.created' => (string) $this->t('Mensagem'),
      'message.mentioned' => (string) $this->t('Menção'),
      'input.requested' => (string) $this->t('Pedido de informação'),
      'input.answered' => (string) $this->t('Pedido respondido'),
      'execution.interrupted' => (string) $this->t('Execução interrompida'),
      default => $type,
    };
  }

  /**
   * Formats a UTC timestamp in the workspace timezone.
   */
  private function formatWhen(Workspace $workspace, int $timestamp): string {
    if ($timestamp <= 0) {
      return '';
    }
    $timezone = $workspace->get('timezone')->getString();
    try {
      $zone = new \DateTimeZone($timezone === '' ? 'America/Sao_Paulo' : $timezone);
    }
    catch (\Exception) {
      $zone = new \DateTimeZone('America/Sao_Paulo');
    }
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format('d/m/Y H:i');
  }

  /**
   * Reports whether the account can read the workspace.
   */
  private function canRead(Workspace $workspace): bool {
    return $this->scope->allows($this->currentUser, $workspace, WorkspacePermission::READ);
  }

  /**
   * Cache metadata for the dashboard.
   *
   * @return array<string, mixed>
   *   Render cache metadata.
   */
  private function cacheMeta(?Workspace $workspace): array {
    $tags = [
      'ab_membership_list:' . $this->currentUser->id(),
      'ab_notifications:' . $this->currentUser->id(),
    ];
    if ($workspace instanceof Workspace && $workspace->id() !== NULL) {
      $tags[] = 'ab_workspace_boards:' . $workspace->id();
      $tags[] = 'ab_workspace_messages:' . $workspace->id();
    }
    return [
      'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
      'tags' => $tags,
    ];
  }

}
