<?php

/**
 * @file
 * Acrescenta massa de teste sem alterar os registros já gravados.
 *
 * Uso: lando drush php:script scripts/seed-demo-mais.php
 *
 * A senha continua Teste#2026. Os tokens novos são anexados a
 * private/demo-acesso.txt. Os tokens já emitidos permanecem válidos.
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
 * Adds the Ateliê Leste workspace and more cards on the current ones.
 */
final class DemoSeedMore {

  private const PASSWORD = 'Teste#2026';

  /**
   * Report lines.
   *
   * @var list<string>
   */
  private array $report = [];

  /**
   * Next task position.
   */
  private int $slot = 1024;

  /**
   * Creates the extra demo data.
   */
  public function run(): void {
    if ($this->accountOrNull('gisele.martins') instanceof UserInterface) {
      throw new \RuntimeException('A massa extra já existe (usuário gisele.martins). Nada foi alterado.');
    }
    $ana = $this->account('ana.costa');
    $bruno = $this->account('bruno.lima');
    $elena = $this->account('elena.rios');
    $norte = $this->workspace('Estúdio Norte');
    $sul = $this->workspace('Casa Sul');

    $gisele = $this->user('gisele.martins');
    $iris = $this->user('iris.nogueira');
    $hugo = $this->user('hugo.pacheco');
    $joao = $this->user('joao.freitas');
    $provisioner = \Drupal::service(PersonalWorkspaceProvisionerInterface::class);
    foreach ([$gisele, $iris, $hugo, $joao] as $account) {
      $provisioner->provision($account);
    }

    $atelie = $this->asUser($gisele, fn (): Workspace => \Drupal::service(WorkspaceCreator::class)->create($gisele, 'Ateliê Leste', 'America/Sao_Paulo'));
    $this->asUser($gisele, function () use ($atelie): void {
      $members = \Drupal::service(MembershipManager::class);
      $members->addMember($atelie, 'iris.nogueira', 'admin');
      $members->addMember($atelie, 'hugo.pacheco', 'member');
      $members->addMember($atelie, 'ana.costa', 'viewer');
    });
    $this->asUser($ana, function () use ($norte): void {
      \Drupal::service(MembershipManager::class)->addMember($norte, 'hugo.pacheco', 'viewer');
    });
    $this->asUser($elena, function () use ($sul): void {
      \Drupal::service(MembershipManager::class)->addMember($sul, 'joao.freitas', 'member');
    });

    $colecao = $this->board($gisele, $atelie, 'Coleção', 'Quadro principal do ateliê, com várias tarefas em cada coluna.');
    $campanha = $this->board($gisele, $atelie, 'Campanha', 'Quadro só de humanos. A Costureira não tem grant aqui.');
    $mostruario = $this->board($gisele, $atelie, 'Mostruário', 'Quadro que fica arquivado.');
    $entregas = $this->board($elena, $sul, 'Entregas', 'Segundo quadro da Casa Sul.');
    $pesquisa = $this->board($ana, $norte, 'Pesquisa', 'Quadro novo no Estúdio Norte. O Produto permanece como estava.');
    $lembretes = $this->asUser($bruno, function () use ($bruno): Board {
      return \Drupal::service(BoardManager::class)->create($this->personalWorkspace($bruno), 'Lembretes', 'Quadro do workspace pessoal do Bruno.');
    });
    $recados = $this->asUser($elena, function () use ($elena): Board {
      return \Drupal::service(BoardManager::class)->create($this->personalWorkspace($elena), 'Recados', 'Quadro do workspace pessoal da Elena.');
    });
    $notasAtelie = $this->asUser($gisele, function () use ($gisele): Board {
      return \Drupal::service(BoardManager::class)->create($this->personalWorkspace($gisele), 'Notas do ateliê', 'Quadro do workspace pessoal da Gisele.');
    });

    $costureira = $this->agent($gisele, $atelie, 'Costureira', 'Corta, costura e entrega no quadro Coleção.', 'corta, costura, entrega', TRUE, 3);
    $estilista = $this->agent($gisele, $atelie, 'Estilista', 'Escreve na Coleção e só lê a Campanha.', 'desenha, revisa, consulta', TRUE, 1);
    $arquivista = $this->agent($gisele, $atelie, 'Arquivista', 'Agente desligado do ateliê.', 'consulta arquivo', TRUE, 1);
    $acabador = $this->agent($elena, $sul, 'Acabador', 'Lixa e entrega no quadro Entregas.', 'lixa, enverniza, entrega', TRUE, 2);
    $pesquisador = $this->agent($ana, $norte, 'Pesquisador', 'Pesquisa clientes no quadro Pesquisa e só lê o Suporte.', 'entrevista, sintetiza, entrega', TRUE, 2);

    $this->grant($gisele, $costureira, $colecao, ['read', 'write']);
    $this->grant($gisele, $estilista, $colecao, ['read', 'write', 'review']);
    $this->grant($gisele, $estilista, $campanha, ['read']);
    $this->grant($gisele, $arquivista, $colecao, ['read', 'write']);
    $this->grant($elena, $acabador, $entregas, ['read', 'write']);
    $this->grant($ana, $pesquisador, $pesquisa, ['read', 'write']);
    $suporte = $this->boardByName($norte, 'Suporte');
    $this->grant($ana, $pesquisador, $suporte, ['read']);

    $tokenCostureira = $this->issue($gisele, $costureira, AgentTokenScope::all());
    $tokenRevogado = $this->issue($gisele, $costureira, AgentTokenScope::all());
    $this->asUser($gisele, function () use ($costureira, $tokenRevogado): void {
      \Drupal::service(AgentTokenManager::class)->revoke($costureira, $tokenRevogado->publicId);
    });
    $tokenEstilista = $this->issue($gisele, $estilista, AgentTokenScope::all());
    $tokenArquivista = $this->issue($gisele, $arquivista, AgentTokenScope::all());
    $this->asUser($gisele, function () use ($arquivista): void {
      \Drupal::service(AgentManager::class)->update(
        $arquivista,
        'Arquivista',
        'Agente desligado do ateliê.',
        'consulta arquivo',
        FALSE,
        1,
      );
    });
    $tokenAcabador = $this->issue($elena, $acabador, AgentTokenScope::all());
    $tokenPesquisador = $this->issue($ana, $pesquisador, AgentTokenScope::all());

    $overdue = strtotime('2026-10-24 18:00:00 UTC');
    $today = strtotime('2026-10-27 21:00:00 UTC');
    $future = strtotime('2026-11-20 15:00:00 UTC');
    if ($overdue === FALSE || $today === FALSE || $future === FALSE) {
      throw new \RuntimeException('Não foi possível calcular os prazos.');
    }

    $this->task($gisele, $colecao, 'Escolher o tecido de linho', 'backlog', 'low', $gisele, NULL, $future, 'Ainda não liberado para corte.');
    $this->task($gisele, $colecao, 'Separar aviamentos', 'backlog', 'normal', $iris, NULL, NULL, 'Botões e linhas ainda no estoque.');
    $this->task($gisele, $colecao, 'Catalogar botões', 'backlog', 'low', $hugo, NULL, $future, 'Lista de botões para a próxima prova.');
    $this->task($gisele, $colecao, 'Medir o manequim', 'backlog', 'normal', $iris, NULL, $today, 'Medidas do manequim da oficina.');
    $cortar = $this->task($gisele, $colecao, 'Cortar o linho', 'backlog', 'high', $iris, NULL, $future, 'Primeiro elo da cadeia. Enquanto ficar no backlog, as tarefas seguintes permanecem bloqueadas.');
    $costurar = $this->task($gisele, $colecao, 'Costurar a amostra', 'ready', 'high', $iris, $costureira, $today, 'Depende do corte do linho.');
    $fotografar = $this->task($gisele, $colecao, 'Fotografar o look', 'ready', 'urgent', $gisele, NULL, $overdue, 'Depende da amostra costurada. Prazo já vencido.');
    $this->depend($gisele, $costurar, $cortar);
    $this->depend($gisele, $fotografar, $costurar);
    $molde = $this->task($gisele, $colecao, 'Riscar o molde', 'ready', 'normal', $iris, $costureira, $today, 'Pronta para a Costureira assumir. O limite dela ainda comporta mais uma reserva.');
    $this->task($gisele, $colecao, 'Passar a ferro a amostra', 'ready', 'low', $hugo, NULL, $future, 'Pronta e sem agente. Qualquer agente com escrita na Coleção pode assumir.');
    $this->task($gisele, $colecao, 'Aprovar a cartela de cores', 'ready', 'urgent', $iris, $estilista, $future, 'Atribuída à Estilista. A Costureira não consegue assumir.');
    $this->task($gisele, $campanha, 'Planejar o desfile de novembro', 'backlog', 'normal', $gisele, NULL, $future, 'Quadro Campanha, sem agente com escrita.');
    $this->task($gisele, $campanha, 'Escrever o convite', 'ready', 'high', $iris, NULL, $today, 'Pronta para um humano mover.');
    $this->task($gisele, $campanha, 'Montar a lista de convidados', 'ready', 'normal', $hugo, NULL, $future, 'Segunda tarefa pronta da Campanha.');
    $this->task($gisele, $campanha, 'Adiar o look de chuva', 'cancelled', 'low', $gisele, NULL, NULL, 'Cancelada no quadro Campanha.');
    $this->task($gisele, $mostruario, 'Peça de 2024', 'backlog', 'low', $gisele, NULL, NULL, 'Tarefa do quadro que será arquivado.');
    $this->asUser($gisele, function () use ($mostruario): void {
      \Drupal::service(BoardManager::class)->archive($this->reloadBoard($mostruario));
    });

    $ator = $this->agentActor($costureira, $atelie, $tokenCostureira);
    $lavar = $this->task($gisele, $colecao, 'Lavar a peça piloto', 'ready', 'normal', $hugo, $costureira, NULL, 'Será concluída para desbloquear a embalagem.');
    $this->submit($ator, $lavar, 'Peça piloto lavada e seca.');
    $this->approve($iris, $lavar);
    $embalar = $this->task($gisele, $colecao, 'Embalar a peça piloto', 'ready', 'high', $gisele, $costureira, $future, 'A dependência já está concluída, então a Costureira pode assumir.');
    $this->depend($gisele, $embalar, $lavar);

    $lookbook = $this->task($gisele, $colecao, 'Publicar o lookbook interno', 'ready', 'normal', $gisele, $costureira, $future, 'Entrega aprovada pela Iris.');
    $this->submit($ator, $lookbook, 'Lookbook interno publicado.');
    $this->approve($iris, $lookbook);

    $punho = $this->task($gisele, $colecao, 'Reabrir o ajuste de punho', 'ready', 'normal', $iris, $costureira, $future, 'Foi concluída e uma humana reabriu para Pronta.');
    $this->submit($ator, $punho, 'Punho ajustado na primeira prova.');
    $this->approve($iris, $punho);
    $this->reopen($gisele, $punho);

    $gola = $this->task($gisele, $colecao, 'Refazer a gola rejeitada', 'ready', 'high', $iris, $costureira, $today, 'A entrega foi rejeitada e a tarefa voltou para Pronta.');
    $this->submit($ator, $gola, 'Primeira gola, ainda alta demais.');
    $this->asUser($gisele, function () use ($gola): void {
      $fresh = $this->reloadTask($gola);
      \Drupal::service(TaskReview::class)->reject((string) $fresh->uuid(), $this->version($fresh), 'A gola ficou alta demais para o molde.');
    });
    $this->edit($gisele, $gola, ['assigned_agent' => (int) $costureira->id()]);

    $etiqueta = $this->task($gisele, $colecao, 'Gerar a etiqueta que falhou', 'ready', 'normal', $hugo, NULL, NULL, 'A execução terminou com falha cut_error.');
    $falha = $this->claim($ator, $etiqueta);
    \Drupal::service(ExecutionMaintenance::class)->fail($ator, $falha->executionUuid, $falha->leaseToken, $falha->generation, 'cut_error');

    $prova = $this->task($gisele, $colecao, 'Soltar a prova de vestibilidade', 'ready', 'low', $gisele, NULL, $future, 'A Costureira liberou a reserva sem entregar.');
    $liberada = $this->claim($ator, $prova);
    \Drupal::service(ExecutionMaintenance::class)->release($ator, $liberada->executionUuid, $liberada->leaseToken, $liberada->generation);

    $croqui = $this->task($gisele, $colecao, 'Entregar o croqui da manga', 'ready', 'high', $iris, $costureira, $future, 'Entrega submetida, aguardando a Iris.');
    $this->submit($ator, $croqui, 'Croqui da manga pronto para revisão.', [['url' => 'https://agentdock.lndo.site/', 'label' => 'Croqui da manga']]);

    $botoes = $this->task($gisele, $colecao, 'Pregar os botões', 'ready', 'high', $hugo, $costureira, $today, 'Fica em execução, com nota de progresso e conversa.');
    $executando = $this->claim($ator, $botoes);
    $this->extendLease($executando->executionUuid);
    \Drupal::service(ExecutionMaintenance::class)->progress($ator, $executando->executionUuid, $executando->leaseToken, $executando->generation, 'Quatro botões já estão pregados.');
    $this->asUser($iris, function () use ($atelie, $botoes): void {
      $actor = \Drupal::service(HumanActorContextFactory::class)->create($atelie);
      \Drupal::service(MessageService::class)->post($actor, 'task', (string) $this->reloadTask($botoes)->uuid(), 'comment', 'Pode seguir. O quinto botão é o da pala.');
    });
    \Drupal::service(MessageService::class)->post(
      $ator,
      'task',
      (string) $this->reloadTask($botoes)->uuid(),
      'comment',
      'Pala marcada. Falta o botão de baixo.',
      NULL,
      $executando->executionUuid,
      [['type' => 'human', 'id' => (string) $hugo->id()]],
    );

    $barra = $this->task($gisele, $colecao, 'Definir o comprimento da barra', 'ready', 'urgent', $gisele, $costureira, $today, 'A Costureira fez uma pergunta e espera a resposta.');
    $esperando = $this->claim($ator, $barra);
    $this->extendLease($esperando->executionUuid);
    $aberta = \Drupal::service(InputRequestService::class)->request(
      $ator,
      $esperando->executionUuid,
      $esperando->leaseToken,
      $esperando->generation,
      'A barra fica no tornozelo ou no meio da panturrilha?',
    );

    $this->task($gisele, $colecao, 'Cancelar a linha de inverno', 'cancelled', 'low', $gisele, NULL, NULL, 'Cancelada de propósito no quadro Coleção.');
    $this->task($gisele, $notasAtelie, 'Ligar para a tecelagem', 'backlog', 'normal', $gisele, NULL, $today, 'Recado do workspace pessoal da Gisele.');
    $this->task($bruno, $lembretes, 'Pagar o domínio', 'ready', 'high', $bruno, NULL, $today, 'Tarefa do workspace pessoal do Bruno.');
    $this->task($elena, $recados, 'Comprar lixa fina', 'backlog', 'normal', $elena, NULL, $future, 'Tarefa do workspace pessoal da Elena.');

    $verniz = $this->task($elena, $entregas, 'Comprar verniz', 'backlog', 'normal', $joao, NULL, $future, 'Enquanto isto não estiver feito, o pé do banco permanece bloqueado.');
    $this->task($elena, $entregas, 'Escolher o pano de lixa', 'backlog', 'low', $elena, NULL, NULL, 'Ainda no backlog da Casa Sul.');
    $pe = $this->task($elena, $entregas, 'Fixar o pé do banco', 'ready', 'high', $bruno, $acabador, $overdue, 'Depende da compra do verniz.');
    $this->depend($elena, $pe, $verniz);
    $envernizar = $this->task($elena, $entregas, 'Envernizar o tampo', 'ready', 'urgent', $joao, $acabador, $today, 'Pronta para o Acabador assumir.');
    $banco = $this->task($elena, $entregas, 'Entregar o banco', 'ready', 'high', $elena, $acabador, $future, 'Entrega submetida, aguardando revisão.');
    $lixar = $this->task($elena, $entregas, 'Lixar o protótipo', 'ready', 'normal', $bruno, $acabador, NULL, 'Entrega já aprovada.');
    $this->task($elena, $entregas, 'Cancelar o banco alto', 'cancelled', 'low', $elena, NULL, NULL, 'Cancelada na Casa Sul.');

    $atorAcabador = $this->agentActor($acabador, $sul, $tokenAcabador);
    $this->submit($atorAcabador, $lixar, 'Protótipo lixado.');
    $this->approve($elena, $lixar);
    $this->submit($atorAcabador, $banco, 'Banco pronto para a Elena revisar.');
    $duvidaVerniz = $this->task($elena, $entregas, 'Confirmar o tipo de verniz', 'ready', 'normal', $joao, $acabador, $today, 'Pergunta aberta para o João ou a Elena.');
    $esperaVerniz = $this->claim($atorAcabador, $duvidaVerniz);
    $this->extendLease($esperaVerniz->executionUuid);
    \Drupal::service(InputRequestService::class)->request(
      $atorAcabador,
      $esperaVerniz->executionUuid,
      $esperaVerniz->leaseToken,
      $esperaVerniz->generation,
      'O verniz é fosco ou brilhante?',
    );

    $this->task($ana, $pesquisa, 'Mapear concorrentes', 'backlog', 'normal', $ana, NULL, $future, 'Ainda não liberado.');
    $this->task($ana, $pesquisa, 'Ler as entrevistas antigas', 'backlog', 'low', $bruno, NULL, NULL, 'Material de arquivo.');
    $entrevistar = $this->task($ana, $pesquisa, 'Entrevistar cinco clientes', 'ready', 'urgent', $ana, $pesquisador, $today, 'Pronta para o Pesquisador assumir.');
    $relatorio = $this->task($ana, $pesquisa, 'Publicar o relatório', 'ready', 'high', $bruno, $pesquisador, $overdue, 'Depende das cinco entrevistas.');
    $this->depend($ana, $relatorio, $entrevistar);
    $roteiro = $this->task($ana, $pesquisa, 'Entregar o roteiro', 'ready', 'normal', $ana, $pesquisador, $future, 'Entrega submetida no quadro Pesquisa.');
    $amostra = $this->task($ana, $pesquisa, 'Fechar a amostra de outubro', 'ready', 'normal', $bruno, $pesquisador, NULL, 'Entrega já aprovada.');
    $this->task($ana, $pesquisa, 'Descartar a pesquisa de 2024', 'cancelled', 'low', $ana, NULL, NULL, 'Cancelada no quadro Pesquisa.');
    $atorPesquisa = $this->agentActor($pesquisador, $norte, $tokenPesquisador);
    $this->submit($atorPesquisa, $amostra, 'Amostra de outubro fechada.');
    $this->approve($ana, $amostra);
    $this->submit($atorPesquisa, $roteiro, 'Roteiro das entrevistas entregue.');

    $this->asUser($gisele, function () use ($atelie, $colecao, $iris, $costureira): void {
      $actor = \Drupal::service(HumanActorContextFactory::class)->create($atelie);
      $messages = \Drupal::service(MessageService::class);
      $first = $messages->post($actor, 'board', (string) $colecao->uuid(), 'comment', 'Iris, a prova da barra precisa ficar definida hoje.', NULL, NULL, [
        ['type' => 'human', 'id' => (string) $iris->id()],
        ['type' => 'agent', 'id' => (string) $costureira->uuid()],
      ]);
      $messages->post($actor, 'board', (string) $colecao->uuid(), 'comment', 'Deixo o prazo da prova neste quadro.', $first->messageUuid);
    });
    $this->asUser($iris, function () use ($atelie, $colecao, $hugo): void {
      $actor = \Drupal::service(HumanActorContextFactory::class)->create($atelie);
      \Drupal::service(MessageService::class)->post($actor, 'board', (string) $colecao->uuid(), 'comment', 'Hugo, separa os botões da pala antes da prova.', NULL, NULL, [
        ['type' => 'human', 'id' => (string) $hugo->id()],
      ]);
    });
    $this->asUser($hugo, function () use ($atelie, $colecao, $gisele, $iris): void {
      $actor = \Drupal::service(HumanActorContextFactory::class)->create($atelie);
      \Drupal::service(MessageService::class)->post($actor, 'board', (string) $colecao->uuid(), 'comment', 'Gisele, os botões da pala já estão separados.', NULL, NULL, [
        ['type' => 'human', 'id' => (string) $gisele->id()],
        ['type' => 'human', 'id' => (string) $iris->id()],
      ]);
    });
    $this->asUser($iris, function () use ($iris): void {
      $items = \Drupal::service(NotificationService::class)->listFor($iris);
      $unread = array_values(array_filter($items, static fn ($item): bool => !$item->read));
      if (count($unread) >= 2) {
        \Drupal::service(NotificationService::class)->markRead($iris, $unread[count($unread) - 1]->uuid);
      }
    });
    $this->asUser($ana, function () use ($norte, $pesquisa, $bruno): void {
      $actor = \Drupal::service(HumanActorContextFactory::class)->create($norte);
      \Drupal::service(MessageService::class)->post($actor, 'board', (string) $pesquisa->uuid(), 'comment', 'Bruno, o roteiro das entrevistas está em revisão.', NULL, NULL, [
        ['type' => 'human', 'id' => (string) $bruno->id()],
      ]);
    });
    $this->asUser($elena, function () use ($sul, $entregas, $joao, $acabador): void {
      $actor = \Drupal::service(HumanActorContextFactory::class)->create($sul);
      \Drupal::service(MessageService::class)->post($actor, 'board', (string) $entregas->uuid(), 'comment', 'João, confirma o verniz do tampo ainda hoje.', NULL, NULL, [
        ['type' => 'human', 'id' => (string) $joao->id()],
        ['type' => 'agent', 'id' => (string) $acabador->uuid()],
      ]);
    });

    $this->line('AgentDock — massa extra, somada à anterior');
    $this->line('Senha dos humanos novos, a mesma de antes: ' . self::PASSWORD);
    $this->line('Site: https://agentdock.lndo.site/user/login');
    $this->line('');
    $this->line('Humanos novos');
    $this->line('gisele.martins — owner do Ateliê Leste e do workspace Pessoal');
    $this->line('iris.nogueira — admin do Ateliê Leste. Tem notificação lida e não lida');
    $this->line('hugo.pacheco — member do Ateliê Leste e viewer do Estúdio Norte');
    $this->line('joao.freitas — member da Casa Sul');
    $this->line('ana.costa — agora também é viewer do Ateliê Leste');
    $this->line('');
    $this->line('Workspace Ateliê Leste: ' . $atelie->uuid());
    $this->line('Quadros: Coleção, Campanha e Mostruário (arquivado).');
    $this->line('Casa Sul ganhou o quadro Entregas. Estúdio Norte ganhou o quadro Pesquisa.');
    $this->line('Pessoais novos: Lembretes do Bruno, Recados da Elena, Notas do ateliê da Gisele.');
    $this->line('');
    $this->line('Agentes novos. Os tokens antigos continuam válidos.');
    $this->tokenLine('Costureira', 'ativo; lê e escreve na Coleção; limite 3', $tokenCostureira);
    $this->tokenLine('Costureira', 'REVOGADO; não autentica', $tokenRevogado);
    $this->tokenLine('Estilista', 'ativo; escreve na Coleção e só lê a Campanha', $tokenEstilista);
    $this->tokenLine('Arquivista', 'agente desabilitado; o token não autentica', $tokenArquivista);
    $this->tokenLine('Acabador', 'ativo; lê e escreve em Entregas, na Casa Sul', $tokenAcabador);
    $this->tokenLine('Pesquisador', 'ativo; escreve em Pesquisa e só lê o Suporte', $tokenPesquisador);
    $this->line('');
    $this->line('Tarefa pronta para a Costureira (Riscar o molde): ' . $molde->uuid());
    $this->line('Tarefa pronta com dependência já concluída (Embalar a peça piloto): ' . $embalar->uuid());
    $this->line('Pergunta aberta da barra: ' . $aberta->requestUuid . ' tarefa ' . $esperando->taskUuid);
    $this->line('Tarefa pronta para o Acabador (Envernizar o tampo): ' . $envernizar->uuid());
    $this->line('Tarefa pronta para o Pesquisador (Entrevistar cinco clientes): ' . $entrevistar->uuid());
    $this->line('As reservas vivas novas duram 7 dias na interface. Um claim novo vale 120 segundos.');
    unset($croqui, $botoes);

    $path = DRUPAL_ROOT . '/../private/demo-acesso.txt';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, TRUE) && !is_dir(dirname($path))) {
      throw new \RuntimeException('Não foi possível criar o diretório private.');
    }
    $text = implode(PHP_EOL, $this->report) . PHP_EOL;
    if (file_put_contents($path, PHP_EOL . $text, FILE_APPEND) === FALSE) {
      throw new \RuntimeException('Não foi possível gravar as credenciais.');
    }
    fwrite(STDOUT, $text);
  }

  /**
   * Creates an active user with the shared password.
   */
  private function user(string $name): UserInterface {
    $account = User::create([
      'name' => $name,
      'mail' => $name . '@demo.agentdock.test',
      'status' => 1,
      'preferred_langcode' => 'pt-br',
      'timezone' => 'America/Sao_Paulo',
    ]);
    $account->setPassword(self::PASSWORD);
    $account->save();
    return $account;
  }

  /**
   * Loads a required account.
   */
  private function account(string $name): UserInterface {
    $account = $this->accountOrNull($name);
    if (!$account instanceof UserInterface) {
      throw new \RuntimeException('Usuário ausente: ' . $name);
    }
    return $account;
  }

  /**
   * Loads an account by login name.
   */
  private function accountOrNull(string $name): ?UserInterface {
    $accounts = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $name]);
    $account = reset($accounts);
    return $account instanceof UserInterface ? $account : NULL;
  }

  /**
   * Loads a shared workspace by name.
   */
  private function workspace(string $name): Workspace {
    $ids = \Drupal::entityTypeManager()->getStorage('ab_workspace')->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name)
      ->condition('personal', 0)
      ->range(0, 1)
      ->execute();
    $workspace = \Drupal::entityTypeManager()->getStorage('ab_workspace')->load(reset($ids) ?: 0);
    if (!$workspace instanceof Workspace) {
      throw new \RuntimeException('Workspace ausente: ' . $name);
    }
    return $workspace;
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
   * Creates a board.
   */
  private function board(UserInterface $owner, Workspace $workspace, string $name, string $description): Board {
    return $this->asUser($owner, fn (): Board => \Drupal::service(BoardManager::class)->create($workspace, $name, $description));
  }

  /**
   * Loads a board by name inside a workspace.
   */
  private function boardByName(Workspace $workspace, string $name): Board {
    $ids = \Drupal::entityTypeManager()->getStorage('ab_board')->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspace->id())
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();
    $board = \Drupal::entityTypeManager()->getStorage('ab_board')->load(reset($ids) ?: 0);
    if (!$board instanceof Board) {
      throw new \RuntimeException('Quadro ausente: ' . $name);
    }
    return $board;
  }

  /**
   * Creates an agent.
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
        'acceptance_criteria' => '- [ ] ' . $summary,
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
   * Adds a predecessor on the same board.
   */
  private function depend(UserInterface $actor, Task $task, Task $predecessor): void {
    $this->asUser($actor, function () use ($task, $predecessor): void {
      $waiting = $this->reloadTask($task);
      \Drupal::service(TaskDependencyManager::class)->add($waiting, $this->reloadTask($predecessor), $this->version($waiting));
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
   * Claims a task and submits the result.
   *
   * @param array<int, array{url: string, label: string}> $links
   *   Links stored with the delivery.
   */
  private function submit(ActorContext $actor, Task $task, string $summary, array $links = []): void {
    $claimed = $this->claim($actor, $task);
    \Drupal::service(ExecutionMaintenance::class)->submit(
      $actor,
      $claimed->executionUuid,
      $claimed->leaseToken,
      $claimed->generation,
      $summary,
      $links,
    );
  }

  /**
   * Approves the current delivery.
   */
  private function approve(UserInterface $actor, Task $task): void {
    $this->asUser($actor, function () use ($task): void {
      $fresh = $this->reloadTask($task);
      \Drupal::service(TaskReview::class)->approve((string) $fresh->uuid(), $this->version($fresh));
    });
  }

  /**
   * Reopens a done task back to ready.
   */
  private function reopen(UserInterface $actor, Task $task): void {
    $this->asUser($actor, function () use ($task): void {
      $fresh = $this->reloadTask($task);
      \Drupal::service(TaskManager::class)->transition($fresh, 'ready', $this->version($fresh));
    });
  }

  /**
   * Claims a ready task.
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
    $this->line($agent . ' — ' . $note);
    $this->line('  id: ' . $token->publicId);
    $this->line('  bearer: ' . $token->token);
    $this->line('  expira em: ' . gmdate('Y-m-d', $token->expiresAt));
  }

}

(new DemoSeedMore())->run();
