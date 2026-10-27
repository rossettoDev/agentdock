<?php

/**
 * @file
 * Massa local para exercitar a interface e a API.
 *
 * Uso, na raiz do projeto:
 *   lando drush php:script scripts/seed-demo.php
 *
 * A senha dos humanos é fixa. O segredo de cada token de agente só existe
 * nesta execução e é gravado em private/demo-acesso.txt.
 */

declare(strict_types=1);

use Drupal\ab_agent\AgentGrantManager;
use Drupal\ab_agent\AgentManager;
use Drupal\ab_agent\AgentTokenManager;
use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_agent\Entity\Agent;
use Drupal\ab_agent\ExecutionMaintenance;
use Drupal\ab_agent\IssuedAgentToken;
use Drupal\ab_agent\TaskClaim;
use Drupal\ab_agent\TaskReview;
use Drupal\ab_board\BoardManager;
use Drupal\ab_board\Entity\Board;
use Drupal\ab_board\Entity\Task;
use Drupal\ab_board\TaskDependencyManager;
use Drupal\ab_board\TaskManager;
use Drupal\ab_communication\InputRequestService;
use Drupal\ab_communication\MessageService;
use Drupal\ab_communication\NotificationService;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\HumanActorContextFactory;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_core\MembershipManager;
use Drupal\ab_core\PersonalWorkspaceProvisionerInterface;
use Drupal\ab_core\WorkspaceCreator;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Creates the Estúdio Norte and Casa Sul demo data.
 */
final class DemoSeed {

  private const PASSWORD = 'Teste#2026';

  /**
   * Readable lines written at the end.
   *
   * @var list<string>
   */
  private array $report = [];

  /**
   * Runs the seed once.
   */
  public function run(): void {
    if ($this->userByName('ana.costa') instanceof UserInterface) {
      throw new \RuntimeException('A massa de demonstração já existe (usuário ana.costa). Nada foi alterado.');
    }

    $ana = $this->user('ana.costa', 'Ana Costa', FALSE);
    $bruno = $this->user('bruno.lima', 'Bruno Lima', FALSE);
    $carla = $this->user('carla.mendes', 'Carla Mendes', FALSE);
    $diego = $this->user('diego.alves', 'Diego Alves', FALSE);
    $elena = $this->user('elena.rios', 'Elena Rios', FALSE);
    $fabio = $this->user('fabio.nunes', 'Fabio Nunes', FALSE);
    $plataforma = $this->user('plataforma', 'Plataforma', TRUE);

    $provisioner = \Drupal::service(PersonalWorkspaceProvisionerInterface::class);
    foreach ([$ana, $bruno, $carla, $diego, $elena, $fabio, $plataforma] as $account) {
      $provisioner->provision($account);
    }

    $norte = $this->asUser($ana, fn (): Workspace => \Drupal::service(WorkspaceCreator::class)->create($ana, 'Estúdio Norte', 'America/Sao_Paulo'));
    $sul = $this->asUser($elena, fn (): Workspace => \Drupal::service(WorkspaceCreator::class)->create($elena, 'Casa Sul', 'America/Sao_Paulo'));

    $this->asUser($ana, function () use ($norte, $fabio): void {
      $members = \Drupal::service(MembershipManager::class);
      $members->addMember($norte, 'bruno.lima', 'admin');
      $members->addMember($norte, 'carla.mendes', 'member');
      $members->addMember($norte, 'diego.alves', 'viewer');
      $revoked = $members->addMember($norte, 'fabio.nunes', 'member');
      $members->remove($norte, (string) $revoked->uuid());
    });
    $this->asUser($elena, function () use ($sul): void {
      \Drupal::service(MembershipManager::class)->addMember($sul, 'bruno.lima', 'member');
    });

    $produto = $this->asUser($ana, fn (): Board => \Drupal::service(BoardManager::class)->create(
      $norte,
      'Produto',
      'Quadro principal do lançamento. Cobre o fluxo inteiro das tarefas.',
    ));
    $suporte = $this->asUser($ana, fn (): Board => \Drupal::service(BoardManager::class)->create(
      $norte,
      'Suporte',
      'Chamados internos. O agente Observador só lê este quadro.',
    ));
    $arquivo = $this->asUser($ana, fn (): Board => \Drupal::service(BoardManager::class)->create(
      $norte,
      'Arquivo 2025',
      'Quadro encerrado, mantido só para consulta.',
    ));
    $oficina = $this->asUser($elena, fn (): Board => \Drupal::service(BoardManager::class)->create(
      $sul,
      'Oficina',
      'Workspace separado, invisível para quem não é membro.',
    ));
    $notas = $this->asUser($ana, function () use ($ana): Board {
      $personal = $this->personalWorkspace($ana);
      return \Drupal::service(BoardManager::class)->create($personal, 'Notas', 'Quadro privado do workspace pessoal da Ana.');
    });

    $redatora = $this->agent($ana, $norte, 'Redatora', 'Escreve e entrega textos do quadro Produto.', 'redige, revisa texto, entrega rascunho', TRUE, 3);
    $revisor = $this->agent($ana, $norte, 'Revisor', 'Agente com grant de revisão. O token só lê.', 'revisa tom e clareza', TRUE, 1);
    $observador = $this->agent($ana, $norte, 'Observador', 'Lê o quadro Suporte e não escreve.', 'le quadros e tarefas', TRUE, 1);
    $desativado = $this->agent($ana, $norte, 'Desativado', 'Agente desligado. O token antigo não autentica.', 'execucao suspensa', TRUE, 1);
    $marceneiro = $this->agent($elena, $sul, 'Marceneiro', 'Agente da Casa Sul.', 'corta, monta, mede', TRUE, 1);

    $this->grant($ana, $redatora, $produto, ['read', 'write']);
    $this->grant($ana, $revisor, $produto, ['read', 'write', 'review']);
    $this->grant($ana, $observador, $suporte, ['read']);
    $this->grant($ana, $desativado, $produto, ['read', 'write']);
    $this->grant($elena, $marceneiro, $oficina, ['read', 'write']);

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
    $this->asUser($ana, function () use ($desativado): void {
      \Drupal::service(AgentManager::class)->update(
        $desativado,
        'Desativado',
        'Agente desligado. O token antigo não autentica.',
        'execucao suspensa',
        FALSE,
        1,
      );
    });
    $tokenMarceneiro = $this->issue($elena, $marceneiro, AgentTokenScope::all());

    $overdue = strtotime('2026-10-18 18:00:00 UTC');
    $today = strtotime('2026-10-21 21:00:00 UTC');
    $future = strtotime('2026-11-06 15:00:00 UTC');
    if ($overdue === FALSE || $today === FALSE || $future === FALSE) {
      throw new \RuntimeException('Não foi possível calcular os prazos.');
    }

    $brief = $this->task($ana, $produto, 'Escrever o brief do lançamento', 'backlog', 'low', $ana, NULL, $future, 'O texto de partida do lançamento ainda não foi liberado.');
    $checklist = $this->task($ana, $produto, 'Fechar o checklist de publicação', 'backlog', 'normal', $bruno, NULL, $future, 'Enquanto isto não estiver feito, a publicação permanece bloqueada.');
    $publicar = $this->task($ana, $produto, 'Publicar a versão 1.4', 'ready', 'high', $ana, NULL, $today, 'Depende do checklist. O card fica pronto, mas bloqueado.');
    $this->asUser($ana, function () use ($publicar, $checklist): void {
      $waiting = $this->reloadTask($publicar);
      \Drupal::service(TaskDependencyManager::class)->add($waiting, $this->reloadTask($checklist), $this->version($waiting));
    });
    $copy = $this->task($ana, $produto, 'Preparar a copy da landing', 'ready', 'urgent', $carla, $redatora, $overdue, 'Pronta para a Redatora assumir pela API. O limite dela ainda comporta mais uma reserva.');
    $tom = $this->task($ana, $produto, 'Revisar o tom de voz', 'ready', 'normal', $diego, $revisor, $future, 'Atribuída ao Revisor. A Redatora não consegue assumir esta tarefa.');
    $depoimentos = $this->task($ana, $produto, 'Implementar o bloco de depoimentos', 'ready', 'high', $carla, $redatora, $future, 'Fica em execução, com uma nota de progresso e uma mensagem.');
    $preco = $this->task($ana, $produto, 'Definir o preço promocional', 'ready', 'urgent', $ana, $redatora, $today, 'A Redatora perguntou o preço e espera a resposta humana.');
    $pagina = $this->task($ana, $produto, 'Entregar a página de preços', 'ready', 'high', $bruno, $redatora, $future, 'Entrega submetida, aguardando aprovação humana.');
    $post = $this->task($ana, $produto, 'Publicar o post de apresentação', 'ready', 'normal', $ana, $redatora, $future, 'Entrega já aprovada.');
    $titulo = $this->task($ana, $produto, 'Ajustar o título rejeitado', 'ready', 'high', $carla, $redatora, $future, 'A entrega foi rejeitada e a tarefa voltou para Pronta.');
    $imagens = $this->task($ana, $produto, 'Gerar as imagens que falharam', 'ready', 'normal', $bruno, NULL, $future, 'A execução terminou com falha runner_timeout.');
    $rascunho = $this->task($ana, $produto, 'Retomar o rascunho liberado', 'ready', 'low', $ana, NULL, NULL, 'A Redatora liberou a reserva sem entregar.');
    $inverno = $this->task($ana, $produto, 'Cancelar a campanha de inverno', 'cancelled', 'low', $ana, NULL, NULL, 'Cancelada de propósito, para o filtro de canceladas.');
    $desligada = $this->task($ana, $produto, 'Não deve ser assumida pelo agente desativado', 'ready', 'low', $ana, $desativado, NULL, 'Atribuída ao agente desligado. O token dele é recusado.');

    $this->task($ana, $suporte, 'Responder o chamado 441', 'ready', 'normal', $carla, NULL, $today, 'Quadro de suporte. O Observador lê e não escreve.');
    $this->task($ana, $suporte, 'Catalogar dúvidas frequentes', 'backlog', 'low', $bruno, NULL, NULL, 'Ainda não liberada.');
    $this->task($ana, $arquivo, 'Campanha de março', 'backlog', 'low', $ana, NULL, NULL, 'Tarefa de um quadro que será arquivado.');
    $this->asUser($ana, function () use ($arquivo): void {
      \Drupal::service(BoardManager::class)->archive($this->reloadBoard($arquivo));
    });
    $this->task($elena, $oficina, 'Montar o protótipo da cadeira', 'ready', 'high', $elena, $marceneiro, $future, 'Tarefa da Casa Sul. Quem está só no Estúdio Norte não a vê.');
    $this->asUser($ana, function () use ($ana, $notas, $today): void {
      $this->task($ana, $notas, 'Ligar para o fornecedor', 'backlog', 'normal', $ana, NULL, $today, 'Tarefa privada do workspace pessoal.');
    });

    $actorRedatora = $this->agentActor($redatora, $norte, $tokenRedatora);
    $prazo = $this->claim($actorRedatora, $titulo);
    \Drupal::service(ExecutionMaintenance::class)->submit(
      $actorRedatora,
      $prazo->executionUuid,
      $prazo->leaseToken,
      $prazo->generation,
      'Primeira versão do título, ainda genérica.',
    );
    $this->asUser($ana, function () use ($titulo): void {
      $fresh = $this->reloadTask($titulo);
      \Drupal::service(TaskReview::class)->reject((string) $fresh->uuid(), $this->version($fresh), 'O título ainda não diferencia o produto.');
    });
    $this->edit($ana, $titulo, ['assigned_agent' => (int) $redatora->id()]);

    $falha = $this->claim($actorRedatora, $imagens);
    \Drupal::service(ExecutionMaintenance::class)->fail(
      $actorRedatora,
      $falha->executionUuid,
      $falha->leaseToken,
      $falha->generation,
      'runner_timeout',
    );

    $liberada = $this->claim($actorRedatora, $rascunho);
    \Drupal::service(ExecutionMaintenance::class)->release(
      $actorRedatora,
      $liberada->executionUuid,
      $liberada->leaseToken,
      $liberada->generation,
    );

    $respondida = $this->task($ana, $produto, 'Confirmar o prazo interno', 'ready', 'normal', $carla, $redatora, $future, 'A pergunta já foi respondida e a reserva foi liberada.');
    $duvida = $this->claim($actorRedatora, $respondida);
    $pedido = \Drupal::service(InputRequestService::class)->request(
      $actorRedatora,
      $duvida->executionUuid,
      $duvida->leaseToken,
      $duvida->generation,
      'Qual é o prazo interno para esta peça?',
    );
    $this->asUser($carla, function () use ($norte, $pedido): void {
      $actor = \Drupal::service(HumanActorContextFactory::class)->create($norte);
      \Drupal::service(InputRequestService::class)->answer($actor, $pedido->requestUuid, 'O prazo interno é sexta-feira.');
    });
    \Drupal::service(ExecutionMaintenance::class)->release(
      $actorRedatora,
      $duvida->executionUuid,
      $duvida->leaseToken,
      $duvida->generation,
    );

    $entrega = $this->claim($actorRedatora, $pagina);
    \Drupal::service(ExecutionMaintenance::class)->submit(
      $actorRedatora,
      $entrega->executionUuid,
      $entrega->leaseToken,
      $entrega->generation,
      'Página de preços pronta para revisão.',
      [['url' => 'https://agentdock.lndo.site/', 'label' => 'Rascunho da página']],
    );

    $aprovada = $this->claim($actorRedatora, $post);
    \Drupal::service(ExecutionMaintenance::class)->submit(
      $actorRedatora,
      $aprovada->executionUuid,
      $aprovada->leaseToken,
      $aprovada->generation,
      'Post de apresentação entregue.',
    );
    $this->asUser($carla, function () use ($post): void {
      $fresh = $this->reloadTask($post);
      \Drupal::service(TaskReview::class)->approve((string) $fresh->uuid(), $this->version($fresh));
    });

    $executando = $this->claim($actorRedatora, $depoimentos);
    $this->extendLease($executando->executionUuid);
    \Drupal::service(ExecutionMaintenance::class)->progress(
      $actorRedatora,
      $executando->executionUuid,
      $executando->leaseToken,
      $executando->generation,
      'Três depoimentos já estão no bloco.',
    );
    $this->asUser($ana, function () use ($norte, $depoimentos): void {
      $actor = \Drupal::service(HumanActorContextFactory::class)->create($norte);
      \Drupal::service(MessageService::class)->post(
        $actor,
        'task',
        (string) $this->reloadTask($depoimentos)->uuid(),
        'comment',
        'Pode seguir com os três depoimentos. Eu reviso o fechamento.',
      );
    });
    \Drupal::service(MessageService::class)->post(
      $actorRedatora,
      'task',
      (string) $this->reloadTask($depoimentos)->uuid(),
      'comment',
      'Bloco montado. Falta só o fechamento.',
      NULL,
      $executando->executionUuid,
      [['type' => 'human', 'id' => (string) $carla->id()]],
    );

    $esperando = $this->claim($actorRedatora, $preco);
    $this->extendLease($esperando->executionUuid);
    $aberta = \Drupal::service(InputRequestService::class)->request(
      $actorRedatora,
      $esperando->executionUuid,
      $esperando->leaseToken,
      $esperando->generation,
      'Qual preço promocional devemos usar na landing?',
    );

    $quadro = $this->asUser($ana, function () use ($norte, $produto, $carla, $redatora) {
      $actor = \Drupal::service(HumanActorContextFactory::class)->create($norte);
      $messages = \Drupal::service(MessageService::class);
      $first = $messages->post(
        $actor,
        'board',
        (string) $produto->uuid(),
        'comment',
        'Carla, a landing precisa da copy até o fim do dia.',
        NULL,
        NULL,
        [
          ['type' => 'human', 'id' => (string) $carla->id()],
          ['type' => 'agent', 'id' => (string) $redatora->uuid()],
        ],
      );
      $messages->post(
        $actor,
        'board',
        (string) $produto->uuid(),
        'comment',
        'O prazo da copy é hoje. Se travar, me chamem neste quadro.',
        $first->messageUuid,
      );
      return $first;
    });
    $this->asUser($carla, function () use ($ana, $norte, $produto): void {
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
    });
    $this->asUser($ana, function () use ($ana): void {
      $items = \Drupal::service(NotificationService::class)->listFor($ana);
      $unread = array_values(array_filter($items, static fn ($item): bool => !$item->read));
      if ($unread !== []) {
        \Drupal::service(NotificationService::class)->markRead($ana, $unread[count($unread) - 1]->uuid);
      }
    });
    $this->asUser($elena, function () use ($sul, $oficina): void {
      $actor = \Drupal::service(HumanActorContextFactory::class)->create($sul);
      \Drupal::service(MessageService::class)->post(
        $actor,
        'board',
        (string) $oficina->uuid(),
        'comment',
        'Marceneiro, o protótipo da cadeira está pronto para assumir.',
        NULL,
        NULL,
        [['type' => 'agent', 'id' => (string) $this->reloadAgent($sul, 'Marceneiro')->uuid()]],
      );
    });

    $this->line('AgentDock — massa de demonstração');
    $this->line('Site: https://agentdock.lndo.site/user/login');
    $this->line('Senha de todos os humanos: ' . self::PASSWORD);
    $this->line('Depois do login, abra https://agentdock.lndo.site/workspaces e escolha o workspace.');
    $this->line('');
    $this->line('Humanos');
    $this->line('ana.costa — owner de Estúdio Norte e do workspace Pessoal');
    $this->line('bruno.lima — admin de Estúdio Norte e member da Casa Sul');
    $this->line('carla.mendes — member de Estúdio Norte; tem notificações não lidas');
    $this->line('diego.alves — viewer de Estúdio Norte; lê e não edita');
    $this->line('elena.rios — owner da Casa Sul; não vê o Estúdio Norte');
    $this->line('fabio.nunes — membership revogada no Estúdio Norte; incluir de novo reativa');
    $this->line('plataforma — administrador Drupal, sem membership no Estúdio Norte');
    $this->line('');
    $this->line('Workspaces: Estúdio Norte, Casa Sul e um Pessoal para cada humano.');
    $this->line('Quadros do Norte: Produto, Suporte e Arquivo 2025 (arquivado).');
    $this->line('');
    $this->line('Agentes e tokens Bearer');
    $this->tokenLine('Redatora', 'ativo, leitura e escrita no Produto', $tokenRedatora);
    $this->tokenLine('Redatora', 'REVOGADO, não autentica', $tokenRevogado);
    $this->tokenLine('Revisor', 'ativo, só leitura no Produto', $tokenRevisor);
    $this->tokenLine('Observador', 'ativo, só leitura no Suporte', $tokenObservador);
    $this->tokenLine('Desativado', 'agente desabilitado, não autentica', $tokenDesativado);
    $this->tokenLine('Marceneiro', 'ativo, leitura e escrita na Oficina da Casa Sul', $tokenMarceneiro);
    $this->line('');
    $this->line('Reservas vivas por 7 dias, para a interface não perder o estado. Um claim novo continua valendo 120 segundos.');
    $this->leaseLine('Implementar o bloco de depoimentos', 'em execução', $executando);
    $this->leaseLine('Definir o preço promocional', 'aguardando resposta ' . $aberta->requestUuid, $esperando);
    $this->line('');
    $this->line('API: https://agentdock.lndo.site/api/agent-board/v1');
    $this->line('Workspace Estúdio Norte: ' . $norte->uuid());
    $this->line('Tarefa pronta para claim (Preparar a copy da landing): ' . $copy->uuid());
    $this->line('');
    $this->line('Estados no quadro Produto: backlog, pronta, bloqueada, em execução, aguardando entrada, em revisão, concluída, cancelada, rejeitada, falha e reserva liberada.');
    unset($brief, $inverno, $desligada, $tom, $quadro, $plataforma, $fabio, $diego);

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
   * Creates an active user with the shared password.
   */
  private function user(string $name, string $display, bool $administrator): UserInterface {
    $account = User::create([
      'name' => $name,
      'mail' => $name . '@demo.agentdock.test',
      'status' => 1,
      'preferred_langcode' => 'pt-br',
      'timezone' => 'America/Sao_Paulo',
    ]);
    $account->setPassword(self::PASSWORD);
    if ($administrator) {
      $account->addRole('administrator');
    }
    $account->save();
    return $account;
  }

  /**
   * Loads a user by the login name.
   */
  private function userByName(string $name): ?UserInterface {
    $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $name]);
    $account = reset($users);
    return $account instanceof UserInterface ? $account : NULL;
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
   * Loads the personal workspace of an account.
   */
  private function personalWorkspace(UserInterface $account): Workspace {
    $ids = \Drupal::entityTypeManager()->getStorage('ab_workspace')->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner', $account->id())
      ->condition('personal', 1)
      ->range(0, 1)
      ->execute();
    $workspace = \Drupal::entityTypeManager()->getStorage('ab_workspace')->load(reset($ids) ?: 0);
    if (!$workspace instanceof Workspace) {
      throw new \RuntimeException('O workspace pessoal não foi criado.');
    }
    return $workspace;
  }

  /**
   * Creates an agent while acting as the owner.
   */
  private function agent(UserInterface $owner, Workspace $workspace, string $name, string $description, string $capabilities, bool $enabled, int $limit): Agent {
    return $this->asUser($owner, fn (): Agent => \Drupal::service(AgentManager::class)->create(
      $workspace,
      $name,
      $description,
      $capabilities,
      $enabled,
      $limit,
    ));
  }

  /**
   * Stores a board grant.
   *
   * @param string[] $permissions
   *   Grant permissions.
   */
  private function grant(UserInterface $owner, Agent $agent, Board $board, array $permissions): void {
    $this->asUser($owner, function () use ($agent, $board, $permissions): void {
      \Drupal::service(AgentGrantManager::class)->grant($agent, $board, $permissions);
    });
  }

  /**
   * Issues a bearer token.
   *
   * @param string[] $scopes
   *   Token scopes.
   */
  private function issue(UserInterface $owner, Agent $agent, array $scopes): IssuedAgentToken {
    return $this->asUser($owner, fn (): IssuedAgentToken => \Drupal::service(AgentTokenManager::class)->issue($agent, $scopes));
  }

  /**
   * Creates a task and optionally moves it out of the backlog.
   */
  private function task(
    UserInterface $actor,
    Board $board,
    string $title,
    string $status,
    string $priority,
    UserInterface $owner,
    ?Agent $agent,
    ?int $due,
    string $summary,
  ): Task {
    return $this->asUser($actor, function () use ($board, $title, $status, $priority, $owner, $agent, $due, $summary): Task {
      $fields = [
        'title' => $title,
        'description' => $summary . "\n\nCritério visível no detalhe da tarefa.",
        'acceptance_criteria' => "- [ ] " . $summary,
        'priority' => $priority,
        'position' => $this->nextPosition(),
        'human_owner' => (int) $owner->id(),
      ];
      if ($agent instanceof Agent) {
        $fields['assigned_agent'] = (int) $agent->id();
      }
      if ($due !== NULL) {
        $fields['due_at'] = $due;
      }
      $created = \Drupal::service(TaskManager::class)->create($board, $fields);
      if ($status === 'backlog') {
        return $created;
      }
      $fresh = $this->reloadTask($created);
      return \Drupal::service(TaskManager::class)->transition($fresh, $status, $this->version($fresh));
    });
  }

  /**
   * Updates whitelist fields of a task.
   *
   * @param array<string, mixed> $fields
   *   Writable task fields.
   */
  private function edit(UserInterface $actor, Task $task, array $fields): Task {
    return $this->asUser($actor, function () use ($task, $fields): Task {
      $fresh = $this->reloadTask($task);
      return \Drupal::service(TaskManager::class)->update($fresh, $fields, $this->version($fresh));
    });
  }

  /**
   * Claims a ready task for an agent actor.
   */
  private function claim(ActorContext $actor, Task $task): \Drupal\ab_agent\ClaimedTask {
    return \Drupal::service(TaskClaim::class)->claim($actor, (string) $this->reloadTask($task)->uuid());
  }

  /**
   * Builds the agent actor carried by a bearer token.
   */
  private function agentActor(Agent $agent, Workspace $workspace, IssuedAgentToken $token): ActorContext {
    return new ActorContext(
      ActorContext::TYPE_AGENT,
      (string) $agent->uuid(),
      (string) $workspace->uuid(),
      [],
      $token->publicId,
    );
  }

  /**
   * Keeps a demo lease usable for a week.
   */
  private function extendLease(string $executionUuid): void {
    $ids = \Drupal::entityTypeManager()->getStorage('ab_execution')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $executionUuid)
      ->execute();
    $id = (int) reset($ids);
    $until = \Drupal::time()->getRequestTime() + (7 * 86400);
    \Drupal::database()->update('ab_execution')
      ->fields([
        'lease_expires_at' => $until,
        'heartbeat_at' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('id', $id)
      ->execute();
    \Drupal::database()->update('ab_execution_lease')
      ->fields(['expires_at' => $until])
      ->condition('execution_id', $id)
      ->execute();
    \Drupal::entityTypeManager()->getStorage('ab_execution')->resetCache([$id]);
  }

  /**
   * Reloads a task after another service wrote it.
   */
  private function reloadTask(Task $task): Task {
    $storage = \Drupal::entityTypeManager()->getStorage('ab_task');
    $storage->resetCache([(int) $task->id()]);
    $fresh = $storage->load($task->id());
    if (!$fresh instanceof Task) {
      throw new \RuntimeException('A tarefa não foi recarregada.');
    }
    return $fresh;
  }

  /**
   * Reloads a board after another service wrote it.
   */
  private function reloadBoard(Board $board): Board {
    $storage = \Drupal::entityTypeManager()->getStorage('ab_board');
    $storage->resetCache([(int) $board->id()]);
    $fresh = $storage->load($board->id());
    if (!$fresh instanceof Board) {
      throw new \RuntimeException('O quadro não foi recarregado.');
    }
    return $fresh;
  }

  /**
   * Reloads an agent by name inside a workspace.
   */
  private function reloadAgent(Workspace $workspace, string $name): Agent {
    $ids = \Drupal::entityTypeManager()->getStorage('ab_agent')->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();
    $agent = \Drupal::entityTypeManager()->getStorage('ab_agent')->load(reset($ids) ?: 0);
    if (!$agent instanceof Agent) {
      throw new \RuntimeException('O agente não foi encontrado.');
    }
    return $agent;
  }

  /**
   * Reads the current optimistic version.
   */
  private function version(Task $task): int {
    return (int) $this->reloadTask($task)->get('version')->getString();
  }

  /**
   * Returns the next kanban position.
   */
  private function nextPosition(): int {
    $position = $this->slot;
    $this->slot += 1024;
    return $position;
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
  private function tokenLine(string $agent, string $note, IssuedAgentToken $token): void {
    $expires = gmdate('Y-m-d', $token->expiresAt);
    $this->line($agent . ' — ' . $note);
    $this->line('  id: ' . $token->publicId);
    $this->line('  bearer: ' . $token->token);
    $this->line('  expira em: ' . $expires);
  }

  /**
   * Appends a live lease.
   */
  private function leaseLine(string $title, string $note, \Drupal\ab_agent\ClaimedTask $claimed): void {
    $this->line($title . ' — ' . $note);
    $this->line('  tarefa: ' . $claimed->taskUuid);
    $this->line('  execução: ' . $claimed->executionUuid);
    $this->line('  geração: ' . $claimed->generation);
    $this->line('  lease: ' . $claimed->leaseToken);
  }

  /**
   * Position cursor.
   */
  private int $slot = 1024;

}

(new DemoSeed())->run();
