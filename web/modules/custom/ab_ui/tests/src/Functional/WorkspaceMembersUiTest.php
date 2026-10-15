<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_ui\Functional;

use Drupal\ab_core\Entity\Membership;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\Tests\BrowserTestBase;

/**
 * Verifies the member screen does not follow a URL into another workspace.
 *
 * @group ab_ui
 */
class WorkspaceMembersUiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ab_core',
    'ab_board',
    'ab_agent',
    'ab_communication',
    'ab_ui',
  ];

  /**
   * Owners manage members; admins cannot take or remove ownership.
   */
  public function testMemberAdministrationFollowsRole(): void {
    $ada = $this->drupalCreateUser();
    $grace = $this->drupalCreateUser();
    $admin = $this->drupalCreateUser();
    $outsider = $this->drupalCreateUser();
    $workspace = $this->creator()->create($ada, 'Casa Ada', 'America/Sao_Paulo');
    $members = 'workspaces/' . $workspace->uuid() . '/members';

    $this->drupalLogin($ada);
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextContains('Nenhum workspace selecionado.');
    $this->drupalGet($members);
    $this->drupalGet('workspaces');
    $this->assertSession()->pageTextContains('Nenhum workspace selecionado.');

    $this->drupalGet($members);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->optionNotExists('add_role', 'owner');
    $this->assertSession()->fieldExists('transfer_name');
    $this->submitForm([
      'add_name' => $grace->getAccountName(),
      'add_role' => 'member',
    ], 'Adicionar membro');
    $this->assertSession()->pageTextContains($grace->getAccountName());
    $grace_membership = $this->membershipFor($workspace, $grace->id());
    $this->submitForm([
      'members[' . $grace_membership->uuid() . '][role]' => 'viewer',
    ], 'Salvar papel');
    $this->assertSession()->pageTextContains($grace->getAccountName() . ' (Leitor)');

    $this->submitForm([
      'add_name' => $admin->getAccountName(),
      'add_role' => 'admin',
    ], 'Adicionar membro');
    $owner = $this->ownerMembership($workspace);

    $this->drupalLogin($admin);
    $this->drupalGet($members);
    $this->assertSession()->optionNotExists('add_role', 'owner');
    $this->assertSession()->fieldNotExists('transfer_name');
    $this->assertSession()->elementNotExists('css', '[name="remove-' . $owner->uuid() . '"]');

    $this->drupalLogin($grace);
    $this->drupalGet($members);
    $this->assertSession()->pageTextContains($ada->getAccountName());
    $this->assertSession()->fieldNotExists('add_name');

    $this->drupalLogin($outsider);
    $this->drupalGet($members);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Casa Ada');

    $this->drupalLogin($ada);
    $this->drupalGet($members);
    $this->submitForm([
      'transfer_name' => $grace->getAccountName(),
    ], 'Transferir ownership');
    $this->assertSession()->pageTextContains($grace->getAccountName() . ' (Owner)');
    $this->assertSession()->pageTextContains($ada->getAccountName() . ' (Admin)');
    $this->assertSame((string) $grace->id(), $this->reload($workspace)->get('owner')->getString());
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
   * Loads one user's membership in a workspace.
   */
  private function membershipFor(Workspace $workspace, int|string $uid): Membership {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('user', $uid)
      ->execute();
    $this->assertCount(1, $ids);
    $membership = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Membership::class, $membership);
    return $membership;
  }

  /**
   * Loads the active owner membership.
   */
  private function ownerMembership(Workspace $workspace): Membership {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_membership');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('role', 'owner')
      ->condition('status', 'active')
      ->execute();
    $this->assertCount(1, $ids);
    $membership = $storage->load((string) reset($ids));
    $this->assertInstanceOf(Membership::class, $membership);
    return $membership;
  }

  /**
   * Reloads a workspace from storage.
   */
  private function reload(Workspace $workspace): Workspace {
    $storage = $this->container->get('entity_type.manager')->getStorage('ab_workspace');
    $storage->resetCache([$workspace->id()]);
    $workspace = $storage->load($workspace->id());
    $this->assertInstanceOf(Workspace::class, $workspace);
    return $workspace;
  }

}
