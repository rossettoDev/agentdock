<?php

$board_uuid = '27f43bb5-e88e-4f15-98b0-37964440d701';
$boards = \Drupal::entityTypeManager()->getStorage('ab_board')->loadByProperties(['uuid' => $board_uuid]);
$board = $boards ? reset($boards) : NULL;
if (!$board) {
  throw new \RuntimeException('Board not found');
}
$workspace = (int) $board->get('workspace')->target_id;
$board_id = (int) $board->id();

$tasks = [
  [
    'title' => 'E1-T01 — Inspeção do repositório e plano da árvore DRM',
    'description' => "Contexto: Épico 1 (Núcleo e contrato) do Drupal Remote Manager.\n\nObjetivo:\n- Inspecionar o repositório alvo (Composer, PHP/Drupal, autoload, testes, CI).\n- Propor a árvore concreta de arquivos do pacote `drupal_remote_manager` e submódulos MVP.\n- Listar dependências necessárias sem instalar site completo automaticamente.\n\nReferência: ARCHITECTURE.md §3 e §12 E1; CURSOR_PROMPT Marco 1 §1.",
    'acceptance_criteria' => "- Inventário do repo documentado (versões, estrutura, harness de testes).\n- Árvore de arquivos proposta alinhada ao ARCHITECTURE.md.\n- Dependências listadas; conflitos com o ARCHITECTURE.md (se houver) explicitados.",
    'priority' => 'high',
  ],
  [
    'title' => 'E1-T02 — Contratos: CapabilityInterface, DTOs, schemas JSON e erros',
    'description' => "Contexto: Épico 1 (Núcleo e contrato).\n\nObjetivo:\n- Definir `CapabilityInterface` e `RemoteOperationInterface`.\n- Criar value objects/DTOs de cliente, autenticação e resposta de API.\n- Definir schemas JSON e envelope de erros consistente (`error.code`, `error.message`, `request_id`), sem detalhes internos.\n\nReferência: ARCHITECTURE.md §6 (envelope/erros) e §7; CURSOR_PROMPT Marco 1 §2.",
    'acceptance_criteria' => "- Interfaces e VOs/DTOs existem no módulo núcleo.\n- Envelope de sucesso e erro documentados no código (ou schema).\n- Erros nunca expõem stack traces ou infraestrutura.",
    'priority' => 'high',
  ],
  [
    'title' => 'E1-T03 — Scaffold dos módulos (.info.yml, services) e testes do núcleo',
    'description' => "Contexto: Épico 1 (Núcleo e contrato).\n\nObjetivo:\n- Criar o pacote/módulos com `.info.yml`, `services.yml` e autoload PSR-4.\n- Registrar serviços mínimos do núcleo (plugin manager / contratos).\n- Cobrir validação, mapeamento e tratamento de erro com testes unitários.\n\nMVP de módulos a scaffoldar: `drupal_remote_manager`, `drm_agent`, `drm_controller`, `drm_users`, `drm_roles`, `drm_audit` (implementação de Agent/Controller fica nos épicos seguintes).\n\nReferência: ARCHITECTURE.md §3 e §11; CURSOR_PROMPT Marco 1 §2.",
    'acceptance_criteria' => "- Módulos habilitáveis com `.info.yml` válidos para D10.3+/D11.\n- Services do núcleo registrados e injetáveis.\n- Testes unitários do núcleo passando (ou documentados se o harness ainda não existir).",
    'priority' => 'normal',
  ],
  [
    'title' => 'E1-T04 — Documentar versionamento da API e política de compatibilidade',
    'description' => "Contexto: Épico 1 (Núcleo e contrato).\n\nObjetivo:\n- Escrever `docs/API.md` com versionamento (`/api/drm/v1`), envelope, códigos HTTP e política de compatibilidade.\n- Registrar ADRs iniciais relevantes (ADR-001 a ADR-003 no mínimo) em `docs/DECISIONS.md` se ainda não existirem.\n\nReferência: ARCHITECTURE.md §6, §12 E1 e §14; CURSOR_PROMPT Marco 1 §5.",
    'acceptance_criteria' => "- `docs/API.md` descreve versionamento, envelope, erros e endpoints planejados do núcleo/descoberta.\n- Política de compatibilidade entre Controller e Agent está explícita.\n- Decisões arquiteturais iniciais registradas ou referenciadas.",
    'priority' => 'normal',
  ],
];

$storage = \Drupal::entityTypeManager()->getStorage('ab_task');
$created = [];
$position = 10;
foreach ($tasks as $spec) {
  // Skip if same title already on board
  $existing = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('board', $board_id)
    ->condition('title', $spec['title'])
    ->execute();
  if ($existing) {
    echo "SKIP|" . reset($existing) . "|" . $spec['title'] . "\n";
    continue;
  }
  /** @var \Drupal\ab_board\Entity\Task $task */
  $task = $storage->create([
    'workspace' => $workspace,
    'board' => $board_id,
    'title' => $spec['title'],
    'description' => $spec['description'],
    'acceptance_criteria' => $spec['acceptance_criteria'],
    'status' => 'backlog',
    'priority' => $spec['priority'],
    'position' => $position,
  ]);
  $task->save();
  $created[] = $task;
  echo "CREATED|" . $task->id() . "|" . $task->uuid() . "|" . $task->label() . "|status=" . $task->get('status')->value . "\n";
  $position += 10;
}

echo "DONE created=" . count($created) . "\n";
