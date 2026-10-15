<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Form;

use Drupal\ab_core\Authorization\MembershipScope;
use Drupal\ab_core\Authorization\ScopedOperationDeniedException;
use Drupal\ab_core\Authorization\ScopedResourceNotFoundException;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\WorkspaceRepository;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds members, changes roles and transfers ownership.
 */
final class MembershipManageForm extends FormBase {

  public function __construct(
    private readonly MembershipManager $memberships,
    private readonly WorkspaceRepository $workspaces,
    private readonly MembershipScope $scope,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ab_ui_membership_manage';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $workspace_uuid = '') {
    $workspace = $this->workspace($workspace_uuid);
    $form['#cache'] = [
      'contexts' => ['user', 'ab_memberships'],
      'tags' => ['ab_workspace_members:' . $workspace->id()],
    ];
    $form['workspace_uuid'] = [
      '#type' => 'hidden',
      '#value' => $workspace->uuid(),
    ];
    $form['members'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];
    foreach ($this->memberships->listMembers($workspace) as $member) {
      $uuid = $member['uuid'];
      $form['members'][$uuid]['name'] = [
        '#type' => 'item',
        '#title' => $this->t('@name (@role)', [
          '@name' => $member['name'],
          '@role' => $this->roleLabel($member['role']),
        ]),
      ];
      if ($member['role'] === 'owner') {
        continue;
      }
      $form['members'][$uuid]['role'] = [
        '#type' => 'select',
        '#title' => $this->t('Papel'),
        '#options' => $this->assignableRoles(),
        '#default_value' => $member['role'],
      ];
      $form['members'][$uuid]['save'] = [
        '#type' => 'submit',
        '#name' => 'save-' . $uuid,
        '#value' => $this->t('Salvar papel'),
        '#limit_validation_errors' => [
          ['workspace_uuid'],
          ['members', $uuid, 'role'],
        ],
      ];
      $form['members'][$uuid]['remove'] = [
        '#type' => 'submit',
        '#name' => 'remove-' . $uuid,
        '#value' => $this->t('Remover'),
        '#limit_validation_errors' => [
          ['workspace_uuid'],
        ],
      ];
    }
    $form['add_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nome de usuário'),
    ];
    $form['add_role'] = [
      '#type' => 'select',
      '#title' => $this->t('Papel do novo membro'),
      '#options' => $this->assignableRoles(),
      '#default_value' => 'member',
    ];
    $form['add'] = [
      '#type' => 'submit',
      '#name' => 'add-member',
      '#value' => $this->t('Adicionar membro'),
      '#limit_validation_errors' => [
        ['workspace_uuid'],
        ['add_name'],
        ['add_role'],
      ],
    ];
    if ($this->scope->allows($this->currentUser(), $workspace, WorkspacePermission::TRANSFER_OWNERSHIP)) {
      $form['transfer_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Novo owner'),
      ];
      $form['transfer'] = [
        '#type' => 'submit',
        '#name' => 'transfer-owner',
        '#value' => $this->t('Transferir ownership'),
        '#limit_validation_errors' => [
          ['workspace_uuid'],
          ['transfer_name'],
        ],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $button = (string) ($form_state->getTriggeringElement()['#name'] ?? '');
    try {
      $workspace = $this->workspace((string) $form_state->getValue('workspace_uuid'));
      if ($button === 'add-member') {
        $this->memberships->addMember(
          $workspace,
          trim((string) $form_state->getValue('add_name')),
          (string) $form_state->getValue('add_role'),
        );
        $this->messenger()->addStatus($this->t('Membro adicionado.'));
      }
      elseif ($button === 'transfer-owner') {
        $this->memberships->transferOwnership(
          $workspace,
          trim((string) $form_state->getValue('transfer_name')),
        );
        $this->messenger()->addStatus($this->t('Ownership transferido.'));
      }
      elseif (str_starts_with($button, 'save-')) {
        $uuid = substr($button, 5);
        $role = (string) $form_state->getValue(['members', $uuid, 'role']);
        $this->memberships->changeRole($workspace, $uuid, $role);
        $this->messenger()->addStatus($this->t('Papel atualizado.'));
      }
      elseif (str_starts_with($button, 'remove-')) {
        $this->memberships->remove($workspace, substr($button, 7));
        $this->messenger()->addStatus($this->t('Membro removido.'));
      }
    }
    catch (ScopedResourceNotFoundException) {
      $form_state->setErrorByName('workspace_uuid', $this->t('O registro não foi encontrado.'));
      return;
    }
    catch (ScopedOperationDeniedException) {
      $form_state->setErrorByName('workspace_uuid', $this->t('Operação não permitida.'));
      return;
    }
    catch (\InvalidArgumentException $exception) {
      $field = $button === 'transfer-owner' ? 'transfer_name' : 'add_name';
      $form_state->setErrorByName($field, $this->messageFor($exception));
      return;
    }
    $form_state->setRedirect('ab_ui.workspace_members', [
      'uuid' => $workspace->uuid(),
    ]);
  }

  /**
   * Loads a workspace the current user can read.
   */
  private function workspace(string $uuid): Workspace {
    $workspace = $this->workspaces->loadAccessibleByUuid($uuid);
    if (!$workspace instanceof Workspace) {
      throw new ScopedResourceNotFoundException('Workspace was not found.');
    }
    return $workspace;
  }

  /**
   * Returns roles that can be assigned without a transfer.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Role options.
   */
  private function assignableRoles(): array {
    return [
      'admin' => $this->t('Admin'),
      'member' => $this->t('Membro'),
      'viewer' => $this->t('Leitor'),
    ];
  }

  /**
   * Returns the visible label of a stored role.
   */
  private function roleLabel(string $role): string {
    $labels = $this->assignableRoles() + ['owner' => $this->t('Owner')];
    $label = $labels[$role] ?? $role;
    return (string) $label;
  }

  /**
   * Maps a domain error to a form message.
   */
  private function messageFor(\InvalidArgumentException $exception): string {
    return match ($exception->getMessage()) {
      'The user was not found.' => (string) $this->t('Usuário não encontrado.'),
      'This user is already a member.' => (string) $this->t('Este usuário já é membro.'),
      'This user is already the owner.' => (string) $this->t('Este usuário já é o owner.'),
      default => (string) $this->t('Não foi possível concluir a operação.'),
    };
  }

}
