<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Controller;

use Drupal\ab_communication\NotificationService;
use Drupal\ab_ui\Form\NotificationReadForm;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Lists internal notifications for the authenticated account.
 *
 * The list does not include the message text.
 */
final class NotificationController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly NotificationService $notifications,
    private readonly FormBuilderInterface $formBuilder,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Lists the notifications this account can still read.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function listing(): array {
    $items = [];
    foreach ($this->notifications->listFor($this->currentUser) as $notification) {
      $items[] = [
        'label' => (string) $this->t('Menção'),
        'workspace' => $notification->workspaceLabel,
        'read' => $notification->read ? (string) $this->t('Lida') : (string) $this->t('Não lida'),
        'form' => $notification->read ? NULL : $this->formBuilder->getForm(NotificationReadForm::class, $notification->uuid),
      ];
    }
    return [
      '#theme' => 'ab_ui_notification_list',
      '#items' => $items,
      '#empty' => (string) $this->t('Nenhuma notificação.'),
      '#cache' => [
        'contexts' => ['user', 'ab_memberships'],
        'tags' => ['ab_notifications:' . $this->currentUser->id()],
      ],
    ];
  }

}
