<?php

/**
 * @file
 * Local sample data for the existing screens. Does not print secrets.
 */

use Drupal\ab_agent\AgentTokenScope;
use Drupal\ab_core\Authorization\ActorContext;
use Drupal\ab_core\Authorization\WorkspacePermission;
use Drupal\ab_core\Entity\Workspace;
use Drupal\ab_board\Entity\Task;
use Drupal\user\Entity\User;

$grace = User::load(2);
if (!$grace instanceof User || $grace->getAccountName() !== 'grace') {
  throw new RuntimeException('The Grace account was not found.');
}
\Drupal::currentUser()->setAccount($grace);

$storage = \Drupal::entityTypeManager()->getStorage('ab_workspace');
$workspaces = $storage->loadByProperties([
  'uuid' => '6b34cc78-24fb-45f6-ad8f-5ce22619221c',
]);
$workspace = reset($workspaces);
if (!$workspace instanceof Workspace) {
  throw new RuntimeException('Casa Grace was not found.');
}

$boards = \Drupal::entityTypeManager()->getStorage('ab_board')->loadByProperties([
  'workspace' => $workspace->id(),
  'name' => 'Demonstração das telas',
]);
if ($boards !== []) {
  echo "already\n";
  return;
}

$people = [
  'marina' => 'marina-telas@example.com',
  'heitor' => 'heitor-telas@example.com',
];
$accounts = [];
foreach ($people as $name => $mail) {
  $existing = user_load_by_name($name);
  if ($existing instanceof User) {
    $existing->setPassword('telas-demo');
    $existing->setEmail($mail);
    $existing->activate();
    $existing->save();
    $accounts[$name] = $existing;
    continue;
  }
  $created = User::create([
    'name' => $name,
    'mail' => $mail,
    'status' => 1,
    'pass' => 'telas-demo',
  ]);
  $created->save();
  $accounts[$name] = $created;
}

$members = \Drupal::service('Drupal\ab_core\MembershipManager');
foreach (array_keys($people) as $name) {
  try {
    $members->addMember($workspace, $name, 'member');
  }
  catch (InvalidArgumentException $exception) {
    if ($exception->getMessage() !== 'This user is already a member.') {
      throw $exception;
    }
  }
}

$board = \Drupal::service('Drupal\ab_board\BoardManager')->create(
  $workspace,
  'Demonstração das telas',
  'Quadro só para ver o kanban, a conversa e os agentes.',
);
$agents = \Drupal::service('Drupal\ab_agent\AgentManager');
$grants = \Drupal::service('Drupal\ab_agent\AgentGrantManager');
$revisor = $agents->create($workspace, 'Revisor das telas', 'Revisa o texto do quadro de demonstração.', 'revisão', TRUE, 1);
$redator = $agents->create($workspace, 'Redator das telas', 'Escreve o rascunho do quadro de demonstração.', 'redação', TRUE, 1);
$grants->grant($revisor, $board, ['read', 'write']);
$grants->grant($redator, $board, ['read', 'write']);

$tasks = \Drupal::service('Drupal\ab_board\TaskManager');
$now = \Drupal::time()->getRequestTime();
$specs = [
  ['Revisar o texto de abertura', 'backlog', 'high', (int) $accounts['marina']->id(), (int) $revisor->id(), $now + 86400, 'Conferir o título e o primeiro parágrafo.'],
  ['Separar as referências', 'backlog', 'normal', (int) $accounts['heitor']->id(), NULL, $now + 172800, 'Listar as fontes que entram na página.'],
  ['Escrever o rascunho', 'ready', 'urgent', (int) $grace->id(), (int) $redator->id(), $now + 43200, 'Primeira versão do texto principal.'],
  ['Montar a estrutura da página', 'ready', 'low', (int) $accounts['marina']->id(), NULL, $now - 86400, 'Definir as seções antes do texto.'],
  ['Ajustar o exemplo em andamento', 'progress', 'high', (int) $accounts['heitor']->id(), (int) $redator->id(), $now + 7200, 'O redator está neste exemplo.'],
  ['Entregar o exemplo em revisão', 'review', 'normal', (int) $grace->id(), (int) $revisor->id(), $now + 259200, 'Texto pronto para a revisão humana.'],
  ['Publicar o exemplo concluído', 'done', 'normal', (int) $accounts['marina']->id(), (int) $revisor->id(), $now - 3600, 'Exemplo já aprovado.'],
];

$stored = [];
foreach ($specs as $index => $spec) {
  $fields = [
    'title' => $spec[0],
    'description' => $spec[6],
    'acceptance_criteria' => 'O texto está legível e cabe na tela.',
    'priority' => $spec[2],
    'human_owner' => $spec[3],
    'position' => $index,
    'due_at' => $spec[5],
  ];
  if ($spec[4] !== NULL) {
    $fields['assigned_agent'] = $spec[4];
  }
  $task = $tasks->create($board, $fields);
  if ($spec[1] !== 'backlog') {
    $task = $tasks->transition($task, 'ready', (int) $task->get('version')->getString());
  }
  $stored[] = [$spec[1], $task, $spec[4]];
}

$tokens = \Drupal::service('Drupal\ab_agent\AgentTokenManager');
$claim = \Drupal::service('Drupal\ab_agent\TaskClaim');
$executions = \Drupal::service('Drupal\ab_agent\ExecutionMaintenance');
$issued = [];
foreach ([$redator, $revisor] as $agent) {
  $token = $tokens->issue($agent, [
    AgentTokenScope::TASKS_CLAIM,
    AgentTokenScope::EXECUTIONS_WRITE,
  ]);
  $issued[] = [$agent, $token->publicId];
  $actor = new ActorContext(
    ActorContext::TYPE_AGENT,
    (string) $agent->uuid(),
    (string) $workspace->uuid(),
    [WorkspacePermission::READ, WorkspacePermission::WRITE],
    $token->publicId,
  );
  foreach ($stored as $row) {
    if ($row[0] === 'backlog' || $row[0] === 'ready' || (int) $row[2] !== (int) $agent->id()) {
      continue;
    }
    $claimed = $claim->claim($actor, (string) $row[1]->uuid());
    if ($row[0] === 'review' || $row[0] === 'done') {
      $executions->submit($actor, $claimed->executionUuid, $claimed->leaseToken, $claimed->generation, 'Entrega de demonstração.');
    }
    unset($claimed);
  }
  unset($token, $actor);
}

$review = \Drupal::service('Drupal\ab_agent\TaskReview');
foreach ($stored as $row) {
  if ($row[0] !== 'done') {
    continue;
  }
  $task = reload_task((string) $row[1]->uuid());
  $review->approve((string) $task->uuid(), (int) $task->get('version')->getString());
}

foreach ($issued as $row) {
  $tokens->revoke($row[0], $row[1]);
}

$actor = \Drupal::service('Drupal\ab_core\Authorization\HumanActorContextFactory')->create($workspace);
$messages = \Drupal::service('Drupal\ab_communication\MessageService');
$messages->post($actor, 'board', (string) $board->uuid(), 'comment', 'Este quadro é só para olhar as telas.');
$progress = NULL;
foreach ($stored as $row) {
  if ($row[0] === 'progress') {
    $progress = $row[1];
  }
}
if ($progress instanceof Task) {
  $messages->post($actor, 'task', (string) $progress->uuid(), 'comment', 'O exemplo em andamento continua nesta tarefa.');
}

echo "board=" . $board->uuid() . "\n";
echo "users=marina,heitor\n";
echo "agents=2\n";
echo "tasks=7\n";

/**
 * Reloads one task after another command changed it.
 */
function reload_task(string $uuid): Task {
  $storage = \Drupal::entityTypeManager()->getStorage('ab_task');
  $storage->resetCache();
  $ids = $storage->getQuery()->accessCheck(FALSE)->condition('uuid', $uuid)->execute();
  $task = $storage->load(reset($ids) ?: 0);
  if (!$task instanceof Task) {
    throw new RuntimeException('The sample task was not stored.');
  }
  return $task;
}
