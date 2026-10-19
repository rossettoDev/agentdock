<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_communication\NotificationService;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Marks one notification as read for the authenticated account.
 */
final class NotificationReadForm extends FormBase {

  public function __construct(
    private readonly NotificationService $notifications,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_notification_read';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $notification_uuid = '') {
    $form['#id'] = 'ab-ui-notification-' . $notification_uuid;
    $form['notification'] = [
      '#type' => 'hidden',
      '#value' => $notification_uuid,
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Marcar como lida'),
        '#attributes' => ['class' => ['ab-button']],
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      $this->notifications->markRead($this->currentUser, (string) $form_state->getValue('notification'));
    }
    catch (ScopedResourceNotFoundException) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Notificação lida.'));
    $form_state->setRedirect('ab_ui.notifications');
  }

}
