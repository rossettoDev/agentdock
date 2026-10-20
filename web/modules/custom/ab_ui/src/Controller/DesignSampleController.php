<?php

declare(strict_types=1);

namespace Drupal\ab_ui\Controller;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Renders the design-system sample.
 *
 * The page uses fixed labels of statuses that already exist. It does not
 * read the database and it is not linked from the product navigation.
 */
final class DesignSampleController {

  use StringTranslationTrait;

  /**
   * Shows the shared components and their disabled state.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function sample(): array {
    $here = Url::fromRoute('ab_ui.design_sample')->toString();
    return [
      '#theme' => 'ab_ui_design_sample',
      '#primary' => $this->button((string) $this->t('Abrir boards'), 'primary', Url::fromRoute('ab_ui.boards')->toString(), FALSE),
      '#quiet' => $this->button((string) $this->t('Ação secundária'), 'quiet', '', FALSE),
      '#disabled' => $this->button((string) $this->t('Indisponível'), 'primary', '', TRUE),
      '#badges' => [
        '#type' => 'container',
        'backlog' => $this->badge((string) $this->t('Backlog'), 'backlog'),
        'ready' => $this->badge((string) $this->t('Pronta'), 'ready'),
        'in_progress' => $this->badge((string) $this->t('Em andamento'), 'in_progress'),
        'in_review' => $this->badge((string) $this->t('Em revisão'), 'in_review'),
        'done' => $this->badge((string) $this->t('Concluída'), 'done'),
        'cancelled' => $this->badge((string) $this->t('Cancelada'), 'cancelled'),
        'blocked' => $this->badge((string) $this->t('Bloqueada'), 'blocked'),
        'waiting_input' => $this->badge((string) $this->t('Aguardando'), 'waiting_input'),
        'enabled' => $this->badge((string) $this->t('Habilitado'), 'enabled'),
        'disabled' => $this->badge((string) $this->t('Desabilitado'), 'disabled'),
      ],
      '#tabs' => [
        '#type' => 'component',
        '#component' => 'agent_board:ab-tabs',
        '#props' => [
          'label' => (string) $this->t('Seções da amostra'),
          'items' => [
            [
              'label' => (string) $this->t('Descrição'),
              'url' => $here . '#descricao',
              'current' => TRUE,
            ],
            [
              'label' => (string) $this->t('Entrega'),
              'url' => $here . '#entrega',
              'current' => FALSE,
            ],
          ],
        ],
      ],
      '#human' => $this->avatar((string) $this->t('Pessoa'), 'human', (string) $this->t('Humano')),
      '#agent' => $this->avatar((string) $this->t('Agente'), 'agent', (string) $this->t('Agente')),
      '#signal' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ab-sample__signal']],
        '#value' => $this->t('Não há sinal de execução.'),
      ],
      '#skeleton' => [
        '#type' => 'component',
        '#component' => 'agent_board:ab-skeleton',
        '#props' => [
          'lines' => 3,
        ],
      ],
      '#card' => [
        '#type' => 'component',
        '#component' => 'agent_board:ab-card',
        '#props' => [
          'title' => (string) $this->t('Tarefa de exemplo'),
        ],
        '#slots' => [
          'body' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('O cartão reutiliza a classe ab-card.'),
          ],
        ],
      ],
      '#empty_state' => $this->state('empty', (string) $this->t('Nenhuma tarefa neste board.'), (string) $this->t('O quadro continua utilizável sem cards.')),
      '#error' => $this->state('error', (string) $this->t('Não foi possível carregar.'), (string) $this->t('A mensagem não revela outro workspace.')),
      '#cache' => [
        'contexts' => ['languages:language_interface', 'user'],
      ],
    ];
  }

  /**
   * Builds one button component.
   *
   * @return array<string, mixed>
   *   A component render element.
   */
  private function button(string $label, string $variant, string $url, bool $disabled): array {
    return [
      '#type' => 'component',
      '#component' => 'agent_board:ab-button',
      '#props' => [
        'label' => $label,
        'url' => $url,
        'variant' => $variant,
        'disabled' => $disabled,
      ],
    ];
  }

  /**
   * Builds one badge component.
   *
   * @return array<string, mixed>
   *   A component render element.
   */
  private function badge(string $label, string $tone): array {
    return [
      '#type' => 'component',
      '#component' => 'agent_board:ab-badge',
      '#props' => [
        'label' => $label,
        'tone' => $tone,
      ],
    ];
  }

  /**
   * Builds one avatar component.
   *
   * @return array<string, mixed>
   *   A component render element.
   */
  private function avatar(string $name, string $kind, string $kindLabel): array {
    return [
      '#type' => 'component',
      '#component' => 'agent_board:ab-avatar',
      '#props' => [
        'name' => $name,
        'kind' => $kind,
        'kind_label' => $kindLabel,
      ],
    ];
  }

  /**
   * Builds one empty or error state.
   *
   * @return array<string, mixed>
   *   A component render element.
   */
  private function state(string $variant, string $title, string $message): array {
    return [
      '#type' => 'component',
      '#component' => 'agent_board:ab-state',
      '#props' => [
        'variant' => $variant,
        'title' => $title,
        'message' => $message,
      ],
    ];
  }

}
