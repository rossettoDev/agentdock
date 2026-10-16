<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Hook;

use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Theme and page variables for the workspace screens.
 */
final class AbUiHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly WorkspaceContext $context,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Registers the workspace list template.
   *
   * @return array<string, mixed>
   *   Theme hook definitions.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'ab_ui_workspace_list' => [
        'variables' => [
          'current_label' => NULL,
          'add_url' => '',
          'items' => [],
          'select_form' => NULL,
        ],
      ],
      'ab_ui_member_list' => [
        'variables' => [
          'items' => [],
        ],
      ],
      'ab_ui_board_list' => [
        'variables' => [
          'workspace_label' => NULL,
          'add_url' => '',
          'items' => [],
        ],
      ],
      'ab_ui_board_view' => [
        'variables' => [
          'label' => '',
          'description' => '',
          'archived' => FALSE,
          'back_url' => '',
          'tasks_url' => '',
          'archive_form' => NULL,
        ],
      ],
      'ab_ui_agent_list' => [
        'variables' => [
          'workspace_label' => NULL,
          'add_url' => '',
          'items' => [],
        ],
      ],
      'ab_ui_agent_view' => [
        'variables' => [
          'label' => '',
          'description' => '',
          'capabilities' => '',
          'status' => '',
          'limit' => '',
          'signal' => '',
          'grants' => [],
          'tokens' => [],
          'back_url' => '',
          'edit_form' => NULL,
          'grant_form' => NULL,
          'token_form' => NULL,
        ],
      ],
      'ab_ui_task_list' => [
        'variables' => [
          'board_label' => '',
          'add_url' => '',
          'columns' => [],
          'cancelled' => [],
          'cancelled_only' => FALSE,
          'cancelled_url' => '',
          'today_only' => FALSE,
          'today_url' => '',
          'today_empty' => FALSE,
          'board_url' => '',
          'empty' => TRUE,
        ],
      ],
      'ab_ui_task_view' => [
        'variables' => [
          'label' => '',
          'status' => '',
          'priority' => '',
          'owner' => '',
          'due' => '',
          'description' => [],
          'criteria' => [],
          'edit_url' => '',
          'transition_form' => NULL,
          'blocked' => FALSE,
          'dependencies' => [],
          'dependency_form' => NULL,
          'back_url' => '',
        ],
      ],
    ];
  }

  /**
   * Shows the selected workspace in the header.
   *
   * The link does not carry a workspace id.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    if ((int) $this->currentUser->id() <= 0) {
      return;
    }
    $workspace = $this->context->workspace();
    $variables['workspace_selector'] = [
      '#type' => 'link',
      '#title' => $workspace === NULL ? $this->t('Meus workspaces') : $workspace->label(),
      '#url' => Url::fromRoute('ab_ui.workspaces'),
      '#attributes' => ['class' => ['ab-workspace-selector__link']],
      '#cache' => [
        'contexts' => ['user', 'ab_memberships', 'ab_workspace'],
        'tags' => ['ab_membership_list:' . $this->currentUser->id()],
      ],
    ];
  }

}
