<?php

declare(strict_types=1);

namespace Drupal\Tests\ab_core\Kernel;

use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\WorkspaceSchemaInstaller;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Verifies the workspace entity constraints and schema.
 *
 * @group ab_core
 */
class WorkspaceEntityKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'options', 'ab_core'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('ab_workspace');
  }

  /**
   * A valid workspace stores the base fields.
   */
  public function testValidWorkspacePersistsBaseFields(): void {
    $owner = $this->createOwner();
    $workspace = $this->workspace($owner->id());
    $violations = $workspace->validate();
    $this->assertSame(0, $violations->count(), (string) $violations);
    $workspace->save();

    $loaded = Workspace::load($workspace->id());
    $this->assertInstanceOf(Workspace::class, $loaded);
    $this->assertSame($workspace->uuid(), $loaded->uuid());
    $this->assertSame('Pessoal', $loaded->label());
    $this->assertSame($owner->id(), $loaded->getOwnerId());
    $this->assertSame('America/Sao_Paulo', $loaded->get('timezone')->value);
    $this->assertSame('active', $loaded->get('status')->value);
    $this->assertGreaterThan(0, (int) $loaded->get('created')->value);
    $this->assertGreaterThan(0, (int) $loaded->get('changed')->value);
  }

  /**
   * An invalid timezone is rejected by validation and by save.
   */
  public function testInvalidTimezoneIsRejected(): void {
    $workspace = $this->workspace($this->createOwner()->id());
    $workspace->set('timezone', 'Mars/Olympus');

    $this->assertStringContainsString('Mars/Olympus', $this->violationText($workspace));

    $this->expectException(EntityStorageException::class);
    $workspace->save();
  }

  /**
   * A missing owner is rejected by validation and by save.
   */
  public function testMissingOwnerIsRejected(): void {
    $workspace = $this->workspace(NULL);
    $this->assertStringContainsString('owner', strtolower($this->violationText($workspace)));

    $this->expectException(EntityStorageException::class);
    $workspace->save();
  }

  /**
   * The anonymous user cannot own a workspace.
   */
  public function testAnonymousOwnerIsRejected(): void {
    $workspace = $this->workspace(0);
    $this->assertStringContainsString('owner', strtolower($this->violationText($workspace)));
  }

  /**
   * The update hook creates the same schema on an existing install.
   */
  public function testUpdateInstallsWorkspaceSchema(): void {
    $schema = $this->container->get('database')->schema();
    $schema->dropTable('ab_workspace');
    $this->assertFalse($schema->tableExists('ab_workspace'));

    $created = WorkspaceSchemaInstaller::install();
    $this->assertSame('Installed the workspace entity schema.', $created);
    $this->assertTrue($schema->tableExists('ab_workspace'));
    $this->assertTrue($schema->fieldExists('ab_workspace', 'uuid'));
    $this->assertTrue($schema->fieldExists('ab_workspace', 'name'));
    $this->assertTrue($schema->fieldExists('ab_workspace', 'owner'));
    $this->assertTrue($schema->fieldExists('ab_workspace', 'timezone'));
    $this->assertTrue($schema->fieldExists('ab_workspace', 'status'));
    $this->assertTrue($schema->fieldExists('ab_workspace', 'created'));
    $this->assertTrue($schema->fieldExists('ab_workspace', 'changed'));

    $again = WorkspaceSchemaInstaller::install();
    $this->assertSame('Workspace schema already present.', $again);
  }

  /**
   * Creates an active user.
   */
  private function createOwner(): User {
    $owner = User::create([
      'name' => 'ada',
      'mail' => 'ada@example.com',
      'status' => 1,
    ]);
    $owner->save();
    return $owner;
  }

  /**
   * Builds a workspace, optionally without an owner.
   */
  private function workspace(int|string|null $ownerId): Workspace {
    $values = [
      'name' => 'Pessoal',
      'timezone' => 'America/Sao_Paulo',
      'status' => 'active',
    ];
    if ($ownerId !== NULL) {
      $values['owner'] = $ownerId;
    }
    $workspace = Workspace::create($values);
    $this->assertInstanceOf(Workspace::class, $workspace);
    return $workspace;
  }

  /**
   * Returns property paths and messages for a workspace.
   */
  private function violationText(Workspace $workspace): string {
    $lines = [];
    foreach ($workspace->validate() as $violation) {
      $lines[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    $this->assertNotSame([], $lines);
    return implode("\n", $lines);
  }

}
