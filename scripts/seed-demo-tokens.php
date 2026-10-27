<?php

/**
 * @file
 * Completa a conversa da massa de demonstração e emite tokens novos.
 *
 * Os segredos antigos não podem ser recuperados. Este script revoga os
 * tokens ativos da Redatora, do Revisor, do Observador, do Desativado e
 * do Marceneiro, e grava os novos em private/demo-acesso.txt.
 *
 * Uso: lando drush php:script scripts/seed-demo-tokens.php
 */

declare(strict_types=1);

use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_agent\IssuedAgentToken;
use Drupal\ab_communication\MessageService;
use Drupal\ab_communication\NotificationService;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Entity\Workspace;
use Drupal\user\UserInterface;

/**
 * Rotates demo bearer tokens and fills the missing conversation.
 */
final class DemoTokens {

  private const PASSWORD = 'Teste#2026';

  /**
   * Report lines.
   *
   * @var list<string>
   */
  private array $report = [];

  /**
   * Revokes the unknown secrets and prints the replacements.
   */
  public function run(): void {
    $ana = $this->account('ana.costa');
    $bruno = $this->account('bruno.lima');
    $carla = $this->account('carla.mendes');
    $diego = $this->account('diego.alves');
    $elena = $this->account('elena.rios');
    $fabio = $this->account('fabio.nunes');
    $plataforma = $this->account('plataforma');
    $norte = $this->workspace('Estúdio Norte');
    $sul = $this->workspace('Casa Sul');
    $redatora = $this->agent($norte, 'Redatora');
    $revisor = $this->agent($norte, 'Revisor');
    $observador = $this->agent($norte, 'Observador');
    $desativado = $this->agent($norte, 'Desativado');
    $marceneiro = $this->agent($sul, 'Marceneiro');

    \Drupal::database()->update('ab_message')
      ->fields(['body' => 'O prazo da copy é hoje. Se travar, me chamem neste quadro.'])
      ->condition('body', 'Ana, deixo o texto no card e te aviso aqui.')
      ->execute();

    $this->asUser($carla, function () use ($ana, $carla, $norte): void {
      if ($this->messageExists('Ana, vi a mensagem. Começo pela copy urgente.')) {
        return;
      }
      $produto = $this->board($norte, 'Produto');
      $actor = \Drupal::service(HumanActorContextFactory::class)->create($norte);
      \Drupal::service(MessageService::class)->post(
        $actor,
        'board',
        (string) $produto->uuid(),
        'comment',
        'Ana, vi a mensagem. Começo pela copy urgente.',
        NULL,
        NULL,
        [['type' => 'human', 'id' => (string) $ana->id()]],
      );
      unset($carla);
    });
    $this->asUser($elena, function () use ($elena, $sul, $marceneiro): void {
      if ($this->messageExists('Marceneiro, o protótipo da cadeira está pronto para assumir.')) {
        return;
      }
      $oficina = $this->board($sul, 'Oficina');
      $actor = \Drupal::service(HumanActorContextFactory::class)->create($sul);
      \Drupal::service(MessageService::class)->post(
        $actor,
        'board',
        (string) $oficina->uuid(),
        'comment',
        'Marceneiro, o protótipo da cadeira está pronto para assumir.',
        NULL,
        NULL,
        [['type' => 'agent', 'id' => (string) $marceneiro->uuid()]],
      );
      unset($elena);
    });
    $this->asUser($carla, function () use ($carla): void {
      $items = \Drupal::service(NotificationService::class)->listFor($carla);
      $unread = array_values(array_filter($items, static fn ($item): bool => !$item->read));
      if (count($unread) >= 2) {
        \Drupal::service(NotificationService::class)->markRead($carla, $unread[count($unread) - 1]->uuid);
      }
    });

    $this->revokeActive($ana, [$redatora, $revisor, $observador, $desativado]);
    $this->revokeActive($elena, [$marceneiro]);

    $tokenRedatora = $this->issue($ana, $redatora, AgentTokenScope::all());
    $tokenRevogado = $this->issue($ana, $redatora, AgentTokenScope::all());
    $this->asUser($ana, function () use ($redatora, $tokenRevogado): void {
      \Drupal::service(AgentTokenManager::class)->revoke($redatora, $tokenRevogado->publicId);
    });
    $tokenRevisor = $this->issue($ana, $revisor, [
      AgentTokenScope::BOARDS_READ,
      AgentTokenScope::TASKS_READ,
      AgentTokenScope::MESSAGES_READ,
      AgentTokenScope::EVENTS_READ,
    ]);
    $tokenObservador = $this->issue($ana, $observador, [
      AgentTokenScope::BOARDS_READ,
      AgentTokenScope::TASKS_READ,
      AgentTokenScope::MESSAGES_READ,
      AgentTokenScope::EVENTS_READ,
    ]);
    $tokenDesativado = $this->issue($ana, $desativado, AgentTokenScope::all());
    $tokenMarceneiro = $this->issue($elena, $marceneiro, AgentTokenScope::all());

    $copy = $this->taskUuid($norte, 'Preparar a copy da landing');
    $preco = $this->taskUuid($norte, 'Definir o preço promocional');
    $depoimentos = $this->taskUuid($norte, 'Implementar o bloco de depoimentos');

    $this->line('AgentDock — acesso da massa de demonstração');
    $this->line('Site: https://agentdock.lndo.site/user/login');
    $this->line('Senha de todos os humanos: ' . self::PASSWORD);
    $this->line('Depois do login, abra https://agentdock.lndo.site/workspaces e escolha o workspace.');
    $this->line('');
    $this->line('Humanos');
    $this->line('ana.costa — owner de Estúdio Norte e do workspace Pessoal. Uid ' . $ana->id());
    $this->line('bruno.lima — admin de Estúdio Norte e member da Casa Sul. Uid ' . $bruno->id());
    $this->line('carla.mendes — member de Estúdio Norte. Tem notificação lida e não lida. Uid ' . $carla->id());
    $this->line('diego.alves — viewer de Estúdio Norte. Lê e não edita. Uid ' . $diego->id());
    $this->line('elena.rios — owner da Casa Sul. Não vê o Estúdio Norte. Uid ' . $elena->id());
    $this->line('fabio.nunes — membership revogada no Estúdio Norte. Incluir de novo reativa. Uid ' . $fabio->id());
    $this->line('plataforma — administrador Drupal, só entra no próprio workspace Pessoal. Uid ' . $plataforma->id());
    $this->line('');
    $this->line('Workspace Estúdio Norte: ' . $norte->uuid());
    $this->line('Workspace Casa Sul: ' . $sul->uuid());
    $this->line('Quadros do Norte: Produto, Suporte e Arquivo 2025 (arquivado).');
    $this->line('Quadro da Casa Sul: Oficina. Quadro pessoal da Ana: Notas.');
    $this->line('');
    $this->line('Agentes e tokens Bearer. Envie o segredo em Authorization: Bearer.');
    $this->tokenLine('Redatora', 'ativo; lê e escreve no Produto; limite de 3 execuções', $redatora, $tokenRedatora);
    $this->tokenLine('Redatora', 'REVOGADO; não autentica', $redatora, $tokenRevogado);
    $this->tokenLine('Revisor', 'ativo; só leitura no Produto', $revisor, $tokenRevisor);
    $this->tokenLine('Observador', 'ativo; só leitura no Suporte', $observador, $tokenObservador);
    $this->tokenLine('Desativado', 'agente desabilitado; o token não autentica', $desativado, $tokenDesativado);
    $this->tokenLine('Marceneiro', 'ativo; lê e escreve na Oficina da Casa Sul', $marceneiro, $tokenMarceneiro);
    $this->line('');
    $this->line('API: https://agentdock.lndo.site/api/agent-board/v1');
    $this->line('Tarefa pronta para a Redatora assumir: ' . $copy);
    $this->line('Tarefa em execução (depoimentos): ' . $depoimentos);
    $this->line('Tarefa com pergunta aberta (preço promocional): ' . $preco);
    $this->line('As duas reservas já abertas duram 7 dias na interface. O segredo da reserva não fica guardado; um claim novo vale 120 segundos e devolve outro segredo.');
    $this->line('');
    $this->line('Exemplo de claim:');
    $this->line('curl -s -X POST "https://agentdock.lndo.site/api/agent-board/v1/workspaces/' . $norte->uuid() . '/tasks/' . $copy . '/claim" -H "Authorization: Bearer ' . $tokenRedatora->token . '" -H "Content-Type: application/json" -H "Idempotency-Key: ' . \Drupal::service('uuid')->generate() . '" -d "{}"');

    $path = DRUPAL_ROOT . '/../private/demo-acesso.txt';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, TRUE) && !is_dir(dirname($path))) {
      throw new \RuntimeException('Não foi possível criar o diretório private.');
    }
    $text = implode(PHP_EOL, $this->report) . PHP_EOL;
    if (file_put_contents($path, $text) === FALSE) {
      throw new \RuntimeException('Não foi possível gravar as credenciais.');
    }
    chmod($path, 0600);
    fwrite(STDOUT, $text);
  }

  /**
   * Loads a demo account or stops.
   */
  private function account(string $name): UserInterface {
    $accounts = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $name]);
    $account = reset($accounts);
    if (!$account instanceof UserInterface) {
      throw new \RuntimeException('Usuário ausente: ' . $name);
    }
    return $account;
  }

  /**
   * Loads one workspace by its unique demo name.
   */
  private function workspace(string $name): Workspace {
    $ids = \Drupal::entityTypeManager()->getStorage('ab_workspace')->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name)
      ->condition('personal', 0)
      ->execute();
    $workspace = \Drupal::entityTypeManager()->getStorage('ab_workspace')->load(reset($ids) ?: 0);
    if (!$workspace instanceof Workspace) {
      throw new \RuntimeException('Workspace ausente: ' . $name);
    }
    return $workspace;
  }

  /**
   * Loads an agent by name inside a workspace.
   */
  private function agent(Workspace $workspace, string $name): Agent {
    $ids = \Drupal::entityTypeManager()->getStorage('ab_agent')->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();
    $agent = \Drupal::entityTypeManager()->getStorage('ab_agent')->load(reset($ids) ?: 0);
    if (!$agent instanceof Agent) {
      throw new \RuntimeException('Agente ausente: ' . $name);
    }
    return $agent;
  }

  /**
   * Loads a board by name inside a workspace.
   */
  private function board(Workspace $workspace, string $name): \Drupal\ab_board\Entity\Board {
    $ids = \Drupal::entityTypeManager()->getStorage('ab_board')->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();
    $board = \Drupal::entityTypeManager()->getStorage('ab_board')->load(reset($ids) ?: 0);
    if (!$board instanceof \Drupal\ab_board\Entity\Board) {
      throw new \RuntimeException('Quadro ausente: ' . $name);
    }
    return $board;
  }

  /**
   * Reports whether a message body is already stored.
   */
  private function messageExists(string $body): bool {
    $found = \Drupal::database()->select('ab_message', 'm')
      ->fields('m', ['id'])
      ->condition('body', $body)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $found !== FALSE;
  }

  /**
   * Runs a callback as the given account.
   */
  private function asUser(UserInterface $account, callable $callback): mixed {
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($account);
    try {
      return $callback();
    }
    finally {
      $switcher->switchBack();
    }
  }

  /**
   * Revokes every active token of the given agents.
   *
   * @param \Drupal\ab_agent\Entity\Agent[] $agents
   *   Agents whose current secrets are unknown.
   */
  private function revokeActive(UserInterface $actor, array $agents): void {
    $manager = \Drupal::service(AgentTokenManager::class);
    $this->asUser($actor, function () use ($manager, $agents): void {
      foreach ($agents as $agent) {
        $ids = \Drupal::database()->select('ab_agent_token', 't')
          ->fields('t', ['token_id'])
          ->condition('agent_id', (int) $agent->id())
          ->isNull('revoked_at')
          ->execute()
          ->fetchCol();
        foreach ($ids as $tokenId) {
          $manager->revoke($agent, (string) $tokenId);
        }
      }
    });
  }

  /**
   * Issues a bearer token.
   *
   * @param string[] $scopes
   *   Token scopes.
   */
  private function issue(UserInterface $actor, Agent $agent, array $scopes): IssuedAgentToken {
    return $this->asUser($actor, fn (): IssuedAgentToken => \Drupal::service(AgentTokenManager::class)->issue($agent, $scopes));
  }

  /**
   * Reads the public id of a demo task.
   */
  private function taskUuid(Workspace $workspace, string $title): string {
    $uuid = \Drupal::database()->select('ab_task', 't')
      ->fields('t', ['uuid'])
      ->condition('workspace', $workspace->id())
      ->condition('title', $title)
      ->execute()
      ->fetchField();
    if (!is_string($uuid) || $uuid === '') {
      throw new \RuntimeException('Tarefa ausente: ' . $title);
    }
    return $uuid;
  }

  /**
   * Appends one report line.
   */
  private function line(string $text): void {
    $this->report[] = $text;
  }

  /**
   * Appends a bearer credential.
   */
  private function tokenLine(string $name, string $note, Agent $agent, IssuedAgentToken $token): void {
    $this->line($name . ' — ' . $note);
    $this->line('  agente: ' . $agent->uuid());
    $this->line('  id: ' . $token->publicId);
    $this->line('  bearer: ' . $token->token);
    $this->line('  expira em: ' . gmdate('Y-m-d', $token->expiresAt));
  }

}

(new DemoTokens())->run();
