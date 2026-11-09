<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_board\Entity\Board;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_memory\MemoryService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Verifies the memory central lists, filters and opens entries in-session.
 *
 * @group ab_ui
 */
class MemoryUiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ab_core',
    'ab_event',
    'ab_board',
    'ab_agent',
    'ab_communication',
    'ab_memory',
    'ab_ui',
  ];

  /**
   * Empty, list, filter, detail and cross-workspace stay inside the session.
   */
  public function testMemoryCentralListsAndOpensEntries(): void {
    $this->drupalGet('memory');
    $this->assertSession()->statusCodeEquals(403);

    $ada = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();

    $this->drupalLogin($ada);
    $this->drupalGet('workspaces/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'name' => 'Casa Ada',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Casa Ada');
    $workspace = $this->workspaceByName('Casa Ada');
    $this->drupalGet('boards/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Criar board');
    $this->submitForm([
      'name' => 'Quadro Ada',
      'description' => '',
    ], 'Criar board');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Quadro Ada');
    $board = $this->boardByName('Quadro Ada');

    $this->drupalGet('memory');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Nenhuma memória neste workspace.');
    $this->assertSession()->elementExists('css', '.ab-state--empty[role="status"]');
    $this->assertSession()->elementExists('css', 'nav.ab-memory-filters[aria-label]');

    $this->seedMemory($ada, $workspace, $board, 'Decisão do claim', 'Usamos claim atômico.', 'decision', 'published');
    $this->seedMemory($ada, $workspace, $board, 'Rascunho interno', 'Ainda em edição.', 'fact', 'draft');
    
    // Ensure memory entries are visible in the UI.
    $this->getSession()->reload();

    $this->drupalGet('memory');
    $this->assertSession()->pageTextContains('Total visível: 2');
    $this->assertSession()->linkExists('Decisão do claim');
    $this->assertSession()->linkExists('Rascunho interno');
    $this->assertSession()->pageTextContains('Publicada');
    $this->assertSession()->pageTextContains('Rascunho');
    $this->assertSession()->pageTextContains('Manual');
    $this->assertSession()->pageTextContains('Revisão 1');

    $this->drupalGet('memory', ['query' => ['status' => 'published']]);
    $this->assertSession()->linkExists('Decisão do claim');
    $this->assertSession()->linkNotExists('Rascunho interno');
    $this->assertSession()->elementExists('css', 'nav.ab-memory-filters a[aria-current="page"]');

    $this->drupalGet('memory', ['query' => ['q' => 'claim']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Decisão do claim');
    $this->assertSession()->linkNotExists('Rascunho interno');

    $this->clickLink('Decisão do claim');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressMatches('/\/memory\/[a-f0-9-]+$/');
    $this->assertSession()->pageTextContains('Decisão do claim');
    $this->assertSession()->pageTextContains('Publicada');
    $this->assertSession()->pageTextContains('Decisão');
    $this->assertSession()->pageTextContains('Origem: Manual');
    $this->assertSession()->pageTextContains('Revisão 1');
    $this->assertSession()->pageTextContains('Usamos claim atômico.');
    $this->assertSession()->linkExists('Voltar à memória');

    $this->drupalLogin($grace);
    $this->drupalGet('workspaces/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'name' => 'Casa Grace',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Casa Grace');
    $this->drupalGet('memory');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Nenhuma memória neste workspace.');
    $this->assertSession()->pageTextNotContains('Decisão do claim');

    $published = $this->memoryUuidByTitle('Decisão do claim');
    $this->drupalGet('memory/' . $published);
    $this->assertSession()->statusCodeEquals(404);

    $this->drupalGet('memory', [
      'query' => ['workspace' => $workspace->uuid()],
    ]);
    $this->assertSession()->pageTextNotContains('Decisão do claim');
  }

  /**
   * Create, revise with diff, publish, archive and revision nav respect policy.
   */
  public function testMemoryDraftEditPublishAndRevisions(): void {
    $ada = $this->drupalCreateUser();
    $viewer = $this->drupalCreateUser();

    $this->drupalLogin($ada);
    $this->drupalGet('workspaces/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'name' => 'Casa Memória',
      'timezone' => 'America/Sao_Paulo',
    ], 'Criar workspace');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Casa Memória');
    $workspace = $this->workspaceByName('Casa Memória');
    $this->drupalGet('boards/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Criar board');
    $this->submitForm([
      'name' => 'Quadro Memória',
      'description' => '',
    ], 'Criar board');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Quadro Memória');
    $board = $this->boardByName('Quadro Memória');

    $this->drupalGet('memory');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Criar memória');
    $this->clickLink('Criar memória');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'title' => 'Política de claim',
      'kind' => 'decision',
      'content_markdown' => "Usamos claim.\n<script>alert(1)</script>",
      'board_id' => (string) $board->uuid(),
      'tags' => 'claim, lease',
    ], 'Criar rascunho');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Rascunho criado.');
    $this->assertSession()->pageTextContains('Política de claim');
    $this->assertSession()->pageTextContains('Rascunho');
    $this->assertSession()->pageTextContains('Usamos claim.');
    $this->assertSession()->responseNotContains('<script>alert(1)</script>');
    $this->assertSession()->buttonExists('Publicar');
    $this->assertSession()->buttonExists('Arquivar');
    $this->assertSession()->linkExists('Editar rascunho');

    $this->clickLink('Editar rascunho');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('title', 'Política de claim');
    $this->submitForm([
      'title' => 'Política de claim',
      'content_markdown' => "Usamos claim e lease.\nSegunda linha.",
      'tags' => 'claim, lease',
      'change_reason' => 'Detalhe do lease',
    ], 'Salvar revisão');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Revisão salva.');
    $this->assertSession()->pageTextContains('Usamos claim e lease.');
    $this->assertSession()->pageTextContains('Revisão 2');
    $this->assertSession()->elementExists('css', 'section.ab-memory-revisions');
    $this->assertSession()->pageTextContains('Diff entre revisões');

    $this->submitForm([], 'Publicar');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Memória publicada.');
    $this->assertSession()->pageTextContains('Publicada');
    $this->assertSession()->linkNotExists('Editar rascunho');
    $this->assertSession()->buttonNotExists('Publicar');
    $this->assertSession()->buttonExists('Arquivar');

    $uuid = $this->memoryUuidByTitle('Política de claim');
    $this->drupalGet('memory/' . $uuid . '/edit');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalGet('memory/' . $uuid);
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Arquivar');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Memória arquivada.');
    $this->assertSession()->pageTextContains('Arquivada');
    $this->assertSession()->buttonNotExists('Arquivar');

    $manager = $this->container->get(MembershipManager::class);
    $this->assertInstanceOf(MembershipManager::class, $manager);
    $manager->addMember($workspace, $viewer->getAccountName(), 'viewer');
    $this->drupalLogin($viewer);
    $this->drupalGet('workspaces');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'workspace' => $workspace->uuid(),
    ], 'Usar este workspace');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('memory');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Política de claim');
    $this->assertSession()->linkNotExists('Criar memória');
    $this->drupalGet('memory/add');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('memory/' . $uuid);
    $this->assertSession()->pageTextContains('Arquivada');
    $this->assertSession()->buttonNotExists('Publicar');
    $this->assertSession()->buttonNotExists('Arquivar');
  }

  /**
   * Creates a memory entry for the already authenticated owner.
   *
   * Does not call drupalLogin: that would reset the session workspace.
   */
  private function seedMemory(
    UserInterface $user,
    Workspace $workspace,
    Board $board,
    string $title,
    string $markdown,
    string $kind,
    string $status,
  ): void {
    $this->assertSame((int) $user->id(), (int) $this->container->get('current_user')->id());
    $factory = $this->container->get(HumanActorContextFactory::class);
    $this->assertInstanceOf(HumanActorContextFactory::class, $factory);
    $actor = $factory->create($workspace);
    $memory = $this->container->get(MemoryService::class);
    $this->assertInstanceOf(MemoryService::class, $memory);
    $created = $memory->createDraft($actor, [
      'title' => $title,
      'content_markdown' => $markdown,
      'kind' => $kind,
      'board_id' => (string) $board->uuid(),
      'source_type' => 'manual',
    ]);
    $this->assertNotNull($created);
    $this->assertNotEmpty($created->uuid);
    if ($status === 'published') {
      $memory->publish($actor, $created->uuid, $created->version);
    }
    // Ensure transaction is committed before continuing.
    \Drupal::database()->query('SELECT 1')->fetchField();
  }

  /**
   * Loads a workspace by name.
   */
  private function workspaceByName(string $name): Workspace {
    $ids = \Drupal::entityTypeManager()->getStorage('ab_workspace')->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();
    $workspace = $ids === [] ? NULL : \Drupal::entityTypeManager()->getStorage('ab_workspace')->load(reset($ids));
    $this->assertInstanceOf(Workspace::class, $workspace);
    return $workspace;
  }

  /**
   * Loads a board by name.
   */
  private function boardByName(string $name): Board {
    $ids = \Drupal::entityTypeManager()->getStorage('ab_board')->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();
    $board = $ids === [] ? NULL : \Drupal::entityTypeManager()->getStorage('ab_board')->load(reset($ids));
    $this->assertInstanceOf(Board::class, $board);
    return $board;
  }

  /**
   * Resolves a memory UUID by title.
   */
  private function memoryUuidByTitle(string $title): string {
    $uuid = (string) \Drupal::database()->select('ab_memory_entry', 'e')
      ->fields('e', ['uuid'])
      ->condition('title', $title)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    $this->assertNotSame('', $uuid);
    return $uuid;
  }

}
