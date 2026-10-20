<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Hook;

use Drupal\ab_ui\WorkspaceContext;
use Drupal\Core\Database\Connection;
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
    private readonly Connection $database,
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
          'conversation_url' => '',
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
          'attempts' => [],
          'review_form' => NULL,
          'questions' => [],
          'conversation_url' => '',
          'back_url' => '',
        ],
      ],
      'ab_ui_notification_list' => [
        'variables' => [
          'items' => [],
          'empty' => '',
        ],
      ],
      'ab_ui_conversation_list' => [
        'variables' => [
          'workspace_label' => NULL,
          'items' => [],
          'empty' => '',
        ],
      ],
      'ab_ui_conversation' => [
        'variables' => [
          'label' => '',
          'context_url' => '',
          'context_label' => '',
          'older_url' => '',
          'messages' => [],
          'empty' => '',
          'form' => NULL,
          'back_url' => '',
        ],
      ],
    ];
  }

  /**
   * Attaches one poller for the selected workspace.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    if ((int) $this->currentUser->id() <= 0) {
      return;
    }
    $workspace = $this->context->workspace();
    if ($workspace === NULL) {
      return;
    }
    $sequence = $this->database->query(
      'SELECT sequence FROM {ab_workspace_sequence} WHERE workspace_id = :id',
      [':id' => (string) $workspace->uuid()],
    )->fetchField();
    $cursor = $sequence === FALSE ? '0' : (string) $sequence;
    $prefix = '/api/agent-board/v1/workspaces/' . $workspace->uuid();
    $attachments['#attached']['library'][] = 'ab_ui/sync';
    $attachments['#attached']['drupalSettings']['abUiSync'] = [
      'eventsUrl' => $prefix . '/events',
      'cursor' => $cursor,
      'active' => 3000,
      'hidden' => 15000,
      'max' => 60000,
    ];
    $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'meta',
        '#attributes' => [
          'name' => 'ab-sync-cursor',
          'content' => $cursor,
        ],
      ],
      'ab_sync_cursor',
    ];
    $attachments['#cache']['contexts'][] = 'user';
    $attachments['#cache']['contexts'][] = 'ab_workspace';
  }

  /**
   * Prints the status line the poller fills in.
   */
  #[Hook('page_top')]
  public function pageTop(array &$page_top): void {
    if ((int) $this->currentUser->id() <= 0 || $this->context->workspace() === NULL) {
      return;
    }
    $page_top['ab_sync_status'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => [
        'class' => ['ab-sync-status'],
        'role' => 'status',
        'hidden' => 'hidden',
      ],
      '#value' => '',
      '#cache' => [
        'contexts' => ['user', 'ab_workspace'],
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
