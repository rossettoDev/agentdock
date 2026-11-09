<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Controller;

use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_memory\MemoryRecord;
use Drupal\ab_memory\MemoryService;
use Drupal\ab_ui\Form\MemoryStatusForm;
use Drupal\ab_ui\MemoryDiff;
use Drupal\ab_ui\TaskMarkdown;
use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lists and opens shared memory of the workspace selected in the session.
 *
 * Listing and detail attach ab_workspace_memory:{id} so mutations bust the
 * page cache. Discovery goes through MemoryService::listVisible / get.
 */
final class MemoryController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly MemoryService $memory,
    private readonly HumanActorContextFactory $actors,
    private readonly WorkspaceContext $context,
    private readonly MembershipScope $scope,
    private readonly TaskMarkdown $markdown,
    private readonly MemoryDiff $diff,
    private readonly FormBuilderInterface $formBuilder,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Lists memories the session actor may discover.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function listing(Request $request): array {
    $workspace = $this->context->workspace();
    if (!$workspace instanceof Workspace) {
      return [
        '#theme' => 'ab_ui_memory_list',
        '#workspace_label' => NULL,
        '#add_url' => '',
        '#items' => [],
        '#filters' => [],
        '#status' => '',
        '#kind' => '',
        '#q' => '',
        '#list_url' => Url::fromRoute('ab_ui.memory')->toString(),
        '#filter_empty' => FALSE,
        '#loading' => FALSE,
        '#error' => '',
        '#empty' => (string) $this->t('Nenhum workspace selecionado.'),
        '#cache' => $this->cacheMeta(NULL),
      ];
    }
    if (!$this->scope->allows($this->currentUser, $workspace, WorkspacePermission::READ)) {
      $cache = $this->cacheMeta($workspace);
      throw new CacheableAccessDeniedHttpException(
        CacheableMetadata::createFromRenderArray(['#cache' => $cache]),
        'Access denied.',
      );
    }
    $status = $this->statusQuery($request);
    $kind = $this->kindQuery($request);
    $q = $this->searchQuery($request);
    $actor = $this->actors->create($workspace);
    try {
      $records = $this->memory->listVisible($actor, [
        'status' => $status,
        'kind' => $kind,
        'q' => $q,
      ]);
      $total = $this->memory->countVisible($actor);
    }
    catch (ScopedOperationDeniedException) {
      return [
        '#theme' => 'ab_ui_memory_list',
        '#workspace_label' => (string) $workspace->label(),
        '#add_url' => '',
        '#items' => [],
        '#filters' => [],
        '#status' => $status,
        '#kind' => $kind,
        '#q' => $q,
        '#list_url' => Url::fromRoute('ab_ui.memory')->toString(),
        '#filter_empty' => FALSE,
        '#loading' => FALSE,
        '#error' => (string) $this->t('Não foi possível carregar a memória.'),
        '#empty' => '',
        '#cache' => $this->cacheMeta($workspace),
      ];
    }
    $items = [];
    foreach ($records as $record) {
      $items[] = $this->listItem($record);
    }
    $can_write = $this->scope->allows($this->currentUser, $workspace, WorkspacePermission::WRITE);
    $cache = $this->cacheMeta($workspace);
    $cache['contexts'][] = 'url.query_args:status';
    $cache['contexts'][] = 'url.query_args:kind';
    $cache['contexts'][] = 'url.query_args:q';
    return [
      '#theme' => 'ab_ui_memory_list',
      '#workspace_label' => (string) $workspace->label(),
      '#add_url' => $can_write ? Url::fromRoute('ab_ui.memory_add')->toString() : '',
      '#items' => $items,
      '#filters' => $this->filterLinks($status, $kind, $q),
      '#status' => $status,
      '#kind' => $kind,
      '#q' => $q,
      '#list_url' => Url::fromRoute('ab_ui.memory')->toString(),
      '#filter_empty' => $total > 0 && $items === [],
      '#loading' => FALSE,
      '#error' => '',
      '#empty' => (string) $this->t('Nenhuma memória neste workspace.'),
      '#count' => $total,
      '#cache' => $cache,
    ];
  }

  /**
   * Opens one memory the session actor may read.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function view(string $uuid, Request $request): array {
    $workspace = $this->context->workspace();
    if (!$workspace instanceof Workspace) {
      throw new CacheableNotFoundHttpException($this->notFoundCache());
    }
    if (!$this->scope->allows($this->currentUser, $workspace, WorkspacePermission::READ)) {
      throw new CacheableAccessDeniedHttpException($this->notFoundCache(), 'Access denied.');
    }
    $actor = $this->actors->create($workspace);
    try {
      $record = $this->memory->get($actor, $uuid);
      $revisions = $this->memory->listRevisions($actor, $uuid);
    }
    catch (ScopedResourceNotFoundException) {
      throw new CacheableNotFoundHttpException($this->notFoundCache($workspace));
    }
    catch (ScopedOperationDeniedException) {
      throw new CacheableAccessDeniedHttpException($this->notFoundCache($workspace), 'Access denied.');
    }
    $from = $this->revisionQuery($request, 'from', $revisions);
    $to = $this->revisionQuery($request, 'to', $revisions);
    if ($from === 0 && $to === 0 && count($revisions) >= 2) {
      $to = (int) $revisions[0]['version'];
      $from = (int) $revisions[1]['version'];
    }
    $diff = [];
    $fromBody = $this->revisionBody($revisions, $from);
    $toBody = $this->revisionBody($revisions, $to);
    if ($fromBody !== NULL && $toBody !== NULL && $from !== $to) {
      $diff = $this->diff->table(
        $fromBody,
        $toBody,
        (string) $this->t('Revisão @n', ['@n' => $from]),
        (string) $this->t('Revisão @n', ['@n' => $to]),
      );
    }
    $body = $this->markdown->render($record->contentMarkdown);
    $can_write = $this->scope->allows($this->currentUser, $workspace, WorkspacePermission::WRITE);
    $cache = $this->cacheMeta($workspace);
    $cache['contexts'][] = 'url.query_args:from';
    $cache['contexts'][] = 'url.query_args:to';
    return [
      '#theme' => 'ab_ui_memory_view',
      '#title' => $record->title,
      '#status' => $this->statusLabel($record->status),
      '#kind' => $this->kindLabel($record->kind),
      '#source' => $this->sourceLabel($record->sourceType, $record->sourceId),
      '#revision' => (string) $this->t('Revisão @n', ['@n' => $record->revisionVersion]),
      '#version' => (string) $record->version,
      '#tags' => $record->tags,
      '#body' => $body !== '' ? ['#markup' => $body] : [],
      '#edit_url' => ($can_write && $record->status === 'draft')
        ? Url::fromRoute('ab_ui.memory_edit', ['uuid' => $record->uuid])->toString()
        : '',
      '#status_form' => $this->formBuilder->getForm(MemoryStatusForm::class, $record->uuid),
      '#revisions' => $this->revisionItems($record->uuid, $revisions, $from, $to),
      '#diff' => $diff,
      '#diff_empty' => $from !== $to && $diff === [],
      '#back_url' => Url::fromRoute('ab_ui.memory')->toString(),
      '#cache' => $cache,
    ];
  }

  /**
   * Builds one list row.
   *
   * @return array<string, mixed>
   *   Theme item.
   */
  private function listItem(MemoryRecord $record): array {
    return [
      'title' => $record->title,
      'url' => Url::fromRoute('ab_ui.memory_view', [
        'uuid' => $record->uuid,
      ])->toString(),
      'status' => $this->statusLabel($record->status),
      'kind' => $this->kindLabel($record->kind),
      'source' => $this->sourceLabel($record->sourceType, $record->sourceId),
      'revision' => (string) $this->t('Revisão @n', ['@n' => $record->revisionVersion]),
    ];
  }

  /**
   * Builds revision navigation links.
   *
   * @param string $uuid
   *   Memory entry UUID.
   * @param list<array<string, mixed>> $revisions
   *   Revision rows newest first.
   * @param int $from
   *   Selected left revision number.
   * @param int $to
   *   Selected right revision number.
   *
   * @return list<array<string, mixed>>
   *   Theme items.
   */
  private function revisionItems(string $uuid, array $revisions, int $from, int $to): array {
    $items = [];
    foreach ($revisions as $row) {
      $version = (int) $row['version'];
      $items[] = [
        'label' => (string) $this->t('Revisão @n', ['@n' => $version]),
        'reason' => $row['change_reason'] !== NULL ? (string) $row['change_reason'] : '',
        'author' => (string) $row['author_type'] . ':' . (string) $row['author_id'],
        'url' => Url::fromRoute('ab_ui.memory_view', ['uuid' => $uuid], [
          'query' => [
            'from' => $from > 0 ? $from : max(1, $version - 1),
            'to' => $version,
          ],
        ])->toString(),
        'current' => $version === $to,
      ];
    }
    return $items;
  }

  /**
   * Reads a revision number query argument.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Current request.
   * @param string $key
   *   Query key (from or to).
   * @param list<array<string, mixed>> $revisions
   *   Known revisions.
   */
  private function revisionQuery(Request $request, string $key, array $revisions): int {
    $value = $request->query->get($key, '');
    if (!is_string($value) && !is_int($value)) {
      return 0;
    }
    $version = (int) $value;
    if ($version < 1) {
      return 0;
    }
    foreach ($revisions as $row) {
      if ((int) $row['version'] === $version) {
        return $version;
      }
    }
    return 0;
  }

  /**
   * Finds markdown for one revision number.
   *
   * @param list<array<string, mixed>> $revisions
   *   Revision rows.
   * @param int $version
   *   Revision number.
   */
  private function revisionBody(array $revisions, int $version): ?string {
    if ($version < 1) {
      return NULL;
    }
    foreach ($revisions as $row) {
      if ((int) $row['version'] === $version) {
        return (string) $row['content_markdown'];
      }
    }
    return NULL;
  }

  /**
   * Status filter links with aria-current on the active one.
   *
   * @return list<array{label: string, url: string, current: bool}>
   *   Filter links.
   */
  private function filterLinks(string $status, string $kind, string $q): array {
    $options = [
      '' => (string) $this->t('Todas'),
      'draft' => (string) $this->t('Rascunho'),
      'published' => (string) $this->t('Publicada'),
      'archived' => (string) $this->t('Arquivada'),
    ];
    $links = [];
    foreach ($options as $value => $label) {
      $query = array_filter([
        'status' => $value !== '' ? $value : NULL,
        'kind' => $kind !== '' ? $kind : NULL,
        'q' => $q !== '' ? $q : NULL,
      ], static fn ($item) => $item !== NULL);
      $links[] = [
        'label' => $label,
        'url' => Url::fromRoute('ab_ui.memory', [], ['query' => $query])->toString(),
        'current' => $status === $value,
      ];
    }
    return $links;
  }

  /**
   * Reads the status query argument.
   */
  private function statusQuery(Request $request): string {
    $value = $request->query->get('status', '');
    if (!is_string($value) || !in_array($value, ['', 'draft', 'published', 'archived'], TRUE)) {
      return '';
    }
    return $value;
  }

  /**
   * Reads the kind query argument.
   */
  private function kindQuery(Request $request): string {
    $value = $request->query->get('kind', '');
    if (!is_string($value) || ($value !== '' && !in_array($value, MemoryService::KINDS, TRUE))) {
      return '';
    }
    return $value;
  }

  /**
   * Reads the search query argument.
   */
  private function searchQuery(Request $request): string {
    $value = $request->query->get('q', '');
    if (!is_string($value)) {
      return '';
    }
    $value = trim($value);
    return strlen($value) > 100 ? substr($value, 0, 100) : $value;
  }

  /**
   * Human label for a status.
   */
  private function statusLabel(string $status): string {
    return match ($status) {
      'draft' => (string) $this->t('Rascunho'),
      'published' => (string) $this->t('Publicada'),
      'archived' => (string) $this->t('Arquivada'),
      default => $status,
    };
  }

  /**
   * Human label for a kind.
   */
  private function kindLabel(string $kind): string {
    return match ($kind) {
      'decision' => (string) $this->t('Decisão'),
      'fact' => (string) $this->t('Fato'),
      'procedure' => (string) $this->t('Procedimento'),
      'lesson' => (string) $this->t('Lição'),
      'summary' => (string) $this->t('Resumo'),
      default => $kind,
    };
  }

  /**
   * Human label for the origin of the current revision.
   */
  private function sourceLabel(string $sourceType, ?string $sourceId): string {
    $type = match ($sourceType) {
      'manual' => (string) $this->t('Manual'),
      'task' => (string) $this->t('Tarefa'),
      'execution' => (string) $this->t('Execução'),
      'message' => (string) $this->t('Mensagem'),
      default => $sourceType,
    };
    if ($sourceId === NULL || $sourceId === '') {
      return $type;
    }
    return (string) $this->t('@type · @id', [
      '@type' => $type,
      '@id' => $sourceId,
    ]);
  }

  /**
   * Cache metadata including the memory tag when a workspace is selected.
   *
   * @return array<string, mixed>
   *   Render cache metadata.
   */
  private function cacheMeta(?Workspace $workspace): array {
    $tags = ['ab_membership_list:' . $this->currentUser->id()];
    if ($workspace instanceof Workspace && $workspace->id() !== NULL) {
      $tags[] = 'ab_workspace_memory:' . $workspace->id();
    }
    return [
      'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
      'tags' => $tags,
    ];
  }

  /**
   * Cache metadata for a 404/403 response.
   */
  private function notFoundCache(?Workspace $workspace = NULL): CacheableMetadata {
    return CacheableMetadata::createFromRenderArray([
      '#cache' => $this->cacheMeta($workspace),
    ]);
  }

}
