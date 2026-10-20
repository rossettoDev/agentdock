<?php

declare(strict_types=1);

namespace Drupal\ab_communication;

use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Projects one notification per event and person.
 *
 * A second projection of the same event does not create another row.
 * A revoked membership hides the row instead of revealing its context.
 */
class NotificationService {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MembershipScope $scope,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Stores the notification when this event has not been projected.
   */
  public function project(string $eventId, int $workspaceId, int $userId): void {
    $existing = $this->database->select('ab_notification', 'n')
      ->fields('n', ['id'])
      ->condition('event_id', $eventId)
      ->condition('recipient_user', $userId)
      ->execute()
      ->fetchField();
    if ($existing !== FALSE) {
      return;
    }
    try {
      $this->database->insert('ab_notification')
        ->fields([
          'uuid' => $this->uuid->generate(),
          'workspace' => $workspaceId,
          'recipient_user' => $userId,
          'event_id' => $eventId,
          'read_at' => NULL,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      return;
    }
    $this->cacheTagsInvalidator->invalidateTags([
      'ab_notifications:' . $userId,
    ]);
  }

  /**
   * Lists notifications the account can still read.
   *
   * @return \Drupal\ab_communication\NotificationItem[]
   *   Newest first. The message text is not loaded.
   */
  public function listFor(AccountInterface $account): array {
    $rows = $this->database->select('ab_notification', 'n')
      ->fields('n', ['uuid', 'workspace', 'read_at', 'created', 'id'])
      ->condition('recipient_user', (int) $account->id())
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->execute();
    $items = [];
    foreach ($rows as $row) {
      if (!is_object($row)) {
        continue;
      }
      $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($row->workspace);
      if (!$workspace instanceof Workspace || !$this->scope->allows($account, $workspace, WorkspacePermission::READ)) {
        continue;
      }
      $items[] = new NotificationItem(
        (string) $row->uuid,
        $row->read_at !== NULL && $row->read_at !== '',
        (string) $workspace->label(),
        (string) $workspace->uuid(),
      );
    }
    return $items;
  }

  /**
   * Marks one visible notification as read.
   */
  public function markRead(AccountInterface $account, string $uuid): void {
    $row = $this->database->select('ab_notification', 'n')
      ->fields('n', ['id', 'workspace', 'recipient_user', 'read_at'])
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchAssoc();
    if (!is_array($row) || (int) $row['recipient_user'] !== (int) $account->id()) {
      throw new ScopedResourceNotFoundException('Notification was not found.');
    }
    $workspace = $this->entityTypeManager->getStorage('ab_workspace')->load($row['workspace']);
    if (!$workspace instanceof Workspace || !$this->scope->allows($account, $workspace, WorkspacePermission::READ)) {
      throw new ScopedResourceNotFoundException('Notification was not found.');
    }
    if ($row['read_at'] === NULL || $row['read_at'] === '') {
      $this->database->update('ab_notification')
        ->fields(['read_at' => $this->time->getRequestTime()])
        ->condition('id', (int) $row['id'])
        ->execute();
      $this->cacheTagsInvalidator->invalidateTags([
        'ab_notifications:' . $account->id(),
      ]);
    }
  }

}
