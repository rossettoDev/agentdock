<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_board\BoardManager;
use Drupal\ab_board\BoardRepository;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Archives one board without deleting its stored fields.
 */
final class BoardArchiveForm extends FormBase {

  public function __construct(
    private readonly BoardManager $boards,
    private readonly BoardRepository $repository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_board_archive';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $board_uuid = '') {
    $form['board_uuid'] = [
      '#type' => 'hidden',
      '#value' => $board_uuid,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Arquivar'),
      '#attributes' => ['class' => ['ab-button', 'ab-button--secondary']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uuid = (string) $form_state->getValue('board_uuid');
    $board = $this->repository->loadAccessibleByUuid($uuid);
    if ($board === NULL) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    try {
      $this->boards->archive($board);
    }
    catch (ScopedResourceNotFoundException) {
      $this->messenger()->addError($this->t('O registro não foi encontrado.'));
      return;
    }
    catch (ScopedOperationDeniedException) {
      $this->messenger()->addError($this->t('Operação não permitida.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Board arquivado.'));
    $form_state->setRedirect('ab_ui.board_tasks', ['uuid' => $board->uuid()]);
  }

}
