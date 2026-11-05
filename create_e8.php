<?php
$board_uuid = '27f43bb5-e88e-4f15-98b0-37964440d701';
$boards = \Drupal::entityTypeManager()->getStorage('ab_board')->loadByProperties(['uuid' => $board_uuid]);
$board = $boards ? reset($boards) : NULL;
if (!$board) { throw new \RuntimeException('Board not found'); }
$workspace = (int) $board->get('workspace')->target_id;
$board_id = (int) $board->id();
$storage = \Drupal::entityTypeManager()->getStorage('ab_task');
$max = 0;
foreach ($storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition('board', $board_id)->execute()) as $t) { $max = max($max, (int) $t->get('position')->value); }
$ref = "Referência: ARCHITECTURE.md v0.2 — §API \"Rotina de deploy (Épico 8)\", §8 \"Rotina de deploy em N sites\", §12 E8 e ADR-007 (também ADR-002/005/006).";
$tasks = [
 ['title' => 'E8-T01 — Capabilities e endpoints de deploy no Agent (db_update, config_import, cache_rebuild)',
  'description' => "Contexto: Épico 8 (Rotina de deploy). Necessidade: executar em sites Agent o update do banco, a importação de configs e a limpeza de caches, com retorno visível e sem sobrecarregar banco/servidor.\n\nObjetivo:\n- Implementar no `drm_agent` três capabilities tipadas: `deploy.db_update`, `deploy.config_import`, `deploy.cache_rebuild`, usando apenas APIs Drupal locais (Update API / Config import / Cache rebuild).\n- Expor `POST /ops/db-update`, `POST /ops/config-import`, `POST /ops/cache-rebuild` e `GET /ops/{operation_id}` (status, logs sanitizados, resultado).\n- Aceitar payload `{ \"steps\": [...], \"dry_run\": false }`; rejeitar passos não liberados no escopo do cliente (allowlist, deny-by-default).\n- Passos longos retornam `202 Accepted` + operação consultável (polling).\n- Rate limit por cliente (especialmente cache_rebuild) e lock para impedir execução concorrente no mesmo site.\n- Logs sanitizados: sem segredos, caminhos internos ou stack traces.\n- Proibido: shell, Drush, SSH, `/execute-command`, nomes arbitrários de service/classe/plugin/SQL.\n\n$ref",
  'acceptance_criteria' => "- As três capabilities aparecem na descoberta de capabilities do Agent.\n- Cliente sem a capability recebe 403 sem efeito colateral; token inválido 401.\n- Endpoints retornam 202 + operation_id e `GET /ops/{id}` mostra status/log por passo.\n- `dry_run` suportado onde fizer sentido (ex.: listar updates/config pendentes).\n- Segunda execução simultânea no mesmo site é rejeitada (lock) e rate limit aplicado.\n- Nenhum uso de shell/Drush/SSH no código; logs sanitizados.\n- Testes kernel/funcionais: happy path de cada passo, 403 sem capability, falha de db_update reportada corretamente.",
  'priority' => 'high'],
 ['title' => 'E8-T02 — UI Rotina de deploy no Controller (multi-select, concorrência e log ao vivo)',
  'description' => "Contexto: Épico 8 (Rotina de deploy).\n\nObjetivo:\n- Criar no `drm_controller` a tela \"Rotina de deploy\" listando os Agents elegíveis (com as capabilities de deploy liberadas), com status/versão.\n- Multi-select de sites (um, vários ou todos).\n- Escolha dos passos (db-update, config-import, cache-rebuild — um, dois ou os três) e ordem (padrão: db-update → config-import → cache-rebuild).\n- Concorrência entre sites: padrão `1` (sequencial), opcional `2+` limitado por teto configurável.\n- Painel de retorno ao vivo: log sanitizado por site e por passo, com estado (pendente, executando, sucesso, aviso, erro) e destaque de erros.\n- Botão para reexecutar apenas os sites que falharam.\n- Acesso restrito por permissão Drupal dedicada.\n\n$ref",
  'acceptance_criteria' => "- Tela lista apenas Agents elegíveis; sites sem capability aparecem desabilitados ou ocultos com motivo.\n- Operador consegue selecionar N sites e qualquer subconjunto dos três passos.\n- Concorrência padrão = 1 e não ultrapassa o teto configurado.\n- Log atualiza em tempo quase real (polling/stream) por site/passo, com erros destacados.\n- \"Reexecutar falhas\" cria nova execução só com os sites que falharam.\n- Usuário sem permissão não acessa a tela (403).\n- Testes funcionais da UI (formulário, validação, permissão).",
  'priority' => 'high'],
 ['title' => 'E8-T03 — Orquestração em lote da Rotina de deploy reutilizando E6 (fila, estados e auditoria)',
  'description' => "Contexto: Épico 8 (Rotina de deploy), reutilizando a infraestrutura de operações em lote do Épico 6.\n\nObjetivo:\n- Ao disparar a rotina, criar uma `drm_operation` com um target por site.\n- Enfileirar o fan-out respeitando o teto de concorrência entre sites (padrão sequencial).\n- Em cada site, executar os passos em série; falha em um passo interrompe os passos seguintes daquele site, enquanto os demais sites seguem na fila.\n- Site offline/timeout não bloqueia os demais; retry limitado e idempotência conforme E6.\n- Fazer poll do `GET /ops/{id}` do Agent e persistir status/log sanitizado por passo para a UI.\n- Estado agregado: `succeeded`, `failed` ou `partial`.\n- Auditoria (`drm_audit`): ator, sites, passos, duração e resumo do log, sem segredos.\n\n$ref",
  'acceptance_criteria' => "- Uma execução gera 1 operação com targets independentes por site.\n- Concorrência configurada é respeitada (teste com 1 e com 2+).\n- Falha no db-update interrompe config-import/cache-rebuild daquele site apenas.\n- Site offline vira target failed sem travar a fila.\n- Estado agregado correto (succeeded/failed/partial).\n- Registro de auditoria completo e sem tokens.\n- Testes: happy path em 1 site e em N sites; falha de passo; site offline; 403 sem capability; concorrência respeitada.",
  'priority' => 'normal'],
];
$position = $max + 10;
foreach ($tasks as $spec) {
  $existing = $storage->getQuery()->accessCheck(FALSE)->condition('board', $board_id)->condition('title', $spec['title'])->execute();
  if ($existing) { echo "SKIP|" . reset($existing) . "|" . $spec['title'] . "\n"; continue; }
  $task = $storage->create(['workspace' => $workspace, 'board' => $board_id, 'title' => $spec['title'], 'description' => $spec['description'], 'acceptance_criteria' => $spec['acceptance_criteria'], 'status' => 'ready', 'priority' => $spec['priority'], 'position' => $position]);
  $task->save();
  $position += 10;
  $r = $storage->loadUnchanged($task->id());
  echo "CREATED|" . $r->id() . "|" . $r->uuid() . "|" . $r->label() . "|board=" . $r->get('board')->target_id . "|status=" . $r->get('status')->value . "\n";
}
echo "DONE\n";
