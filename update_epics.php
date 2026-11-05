<?php
$board_uuid = '27f43bb5-e88e-4f15-98b0-37964440d701';
$boards = \Drupal::entityTypeManager()->getStorage('ab_board')->loadByProperties(['uuid' => $board_uuid]);
$board = $boards ? reset($boards) : NULL;
if (!$board) {
  throw new \RuntimeException('Board not found');
}
$workspace = (int) $board->get('workspace')->target_id;
$board_id = (int) $board->id();
$storage = \Drupal::entityTypeManager()->getStorage('ab_task');

// --- 1) Mark E1 tasks done ---
$e1_titles = [
  'E1-T01 — Inspeção do repositório e plano da árvore DRM',
  'E1-T02 — Contratos: CapabilityInterface, DTOs, schemas JSON e erros',
  'E1-T03 — Scaffold dos módulos (.info.yml, services) e testes do núcleo',
  'E1-T04 — Documentar versionamento da API e política de compatibilidade',
];
$e1_ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('board', $board_id)
  ->condition('title', $e1_titles, 'IN')
  ->execute();
echo "e1_matched=" . count($e1_ids) . "\n";
foreach ($storage->loadMultiple($e1_ids) as $t) {
  $old = $t->get('status')->value;
  $t->set('status', 'done');
  $t->save();
  echo "DONE|#{$t->id()}|{$old}->done|{$t->label()}\n";
}

// Max position for new tasks
$all_ids = $storage->getQuery()->accessCheck(FALSE)->condition('board', $board_id)->execute();
$max = 0;
foreach ($storage->loadMultiple($all_ids) as $t) {
  $p = (int) $t->get('position')->value;
  if ($p > $max) {
    $max = $p;
  }
}
$position = $max + 10;

$tasks = [
  // E2 — Agent seguro (ready)
  [
    'title' => 'E2-T01 — Pareamento: clientes confiáveis, segredo/hash e revogação',
    'description' => "Contexto: Épico 2 (Agent seguro) / Marco 1 — drm_agent.\n\nObjetivo:\n- Registrar clients/Controllers confiáveis com escopos explícitos.\n- Gerar segredo forte exibido uma vez; persistir apenas hash/verificador (fora de config exportável).\n- Revogação e plano de rotação (ou documentar pendência se a UI for além do escopo).\n- Permissões Drupal para administrar clientes Agent.\n\nReferência: ARCHITECTURE.md §5/§12 E2; CURSOR_PROMPT Marco 1 §3.",
    'acceptance_criteria' => "- Client confiável cadastrável com escopos.\n- Segredo nunca em YAML exportável nem em logs/respostas.\n- Revogação impede autenticação subsequente.\n- Permissão administrativa cobre o fluxo.",
    'priority' => 'high',
    'status' => 'ready',
  ],
  [
    'title' => 'E2-T02 — Autenticação Bearer e autorização por capability/escopo',
    'description' => "Contexto: Épico 2 (Agent seguro).\n\nObjetivo:\n- Autenticador Bearer integrado à verificação de client e scopes.\n- Autorização por capability: falhar fechado (401 sem auth, 403 autenticado sem escopo).\n- Limites básicos de requisição, validação de método e erros sanitizados.\n\nReferência: ARCHITECTURE.md §5–§7 e §12 E2; CURSOR_PROMPT regras 6–7 e Marco 1 §3.",
    'acceptance_criteria' => "- Sem token / token inválido ou revogado → 401.\n- Token válido sem escopo → 403, sem efeitos colaterais.\n- Erros sem stack trace nem detalhes de infraestrutura.",
    'priority' => 'high',
    'status' => 'ready',
  ],
  [
    'title' => 'E2-T03 — Endpoints GET /status e /capabilities',
    'description' => "Contexto: Épico 2 (Agent seguro).\n\nObjetivo:\n- Expor `GET /api/drm/v1/status` e `GET /api/drm/v1/capabilities`.\n- Capabilities somente com recursos efetivamente habilitados/autorizados para o cliente.\n- Headers e códigos HTTP corretos; envelope alinhado ao núcleo.\n- Não implementar endpoints mutáveis de users/roles/content neste épico.\n\nReferência: ARCHITECTURE.md §6 e §12 E2; CURSOR_PROMPT Marco 1 §3.",
    'acceptance_criteria' => "- Ambos endpoints autenticados e documentados.\n- `/capabilities` não lista escopos não concedidos.\n- Respostas seguem o envelope do núcleo.",
    'priority' => 'high',
    'status' => 'ready',
  ],
  [
    'title' => 'E2-T04 — Testes de acesso do Agent e documentação de entrega do marco',
    'description' => "Contexto: Épico 2 (Agent seguro).\n\nObjetivo:\n- Cobrir 401/403/200, ausência de escopos indevidos e inexistência de tokens/hashes em logs/YAML/respostas.\n- Atualizar README (instalação Agent, cURL fictício), API.md, DECISIONS.md e NEXT_STEPS.md (Marco 2).\n\nReferência: CURSOR_PROMPT Marco 1 §4–§5 e formato de conclusão.",
    'acceptance_criteria' => "- Suíte mínima de testes de acesso passando ou comandos documentados.\n- Docs de entrega do marco Agent Discovery completas.\n- Nenhum segredo em artefatos exportáveis.",
    'priority' => 'normal',
    'status' => 'ready',
  ],

  // E3 — Controller (backlog)
  [
    'title' => 'E3-T01 — Entidade drm_site, cadastro e teste de conexão',
    'description' => "Contexto: Épico 3 (Controller e inventário).\n\nObjetivo:\n- Criar `drm_site` (config entity): ID, nome, URL base HTTPS, status, referência a credencial, metadados e cache de capabilities.\n- Formulário de cadastro e teste de conexão com o Agent.\n- Credenciais nunca no YAML exportável.\n\nReferência: ARCHITECTURE.md §4 e §12 E3; Marco 2.",
    'acceptance_criteria' => "- Site cadastrável e editável no Controller.\n- Teste de conexão reporta sucesso/falha sanitizada.\n- Credencial fora de config exportável.",
    'priority' => 'high',
    'status' => 'backlog',
  ],
  [
    'title' => 'E3-T02 — HTTP client com SSRF, TLS e timeouts',
    'description' => "Contexto: Épico 3 (Controller e inventário).\n\nObjetivo:\n- Cliente HTTP do Controller com política anti-SSRF, TLS obrigatório e timeouts.\n- Integração com autenticação do Agent e tratamento de erros de rede.\n\nReferência: ARCHITECTURE.md §5/§12 E3; CURSOR_PROMPT regra 6.",
    'acceptance_criteria' => "- URLs internas/privadas bloqueadas conforme política.\n- TLS e timeouts configuráveis/documentados.\n- Falhas de rede não vazam detalhes sensíveis.",
    'priority' => 'high',
    'status' => 'backlog',
  ],
  [
    'title' => 'E3-T03 — Dashboard de sites, estado e capabilities',
    'description' => "Contexto: Épico 3 (Controller e inventário).\n\nObjetivo:\n- Dashboard listando sites, estado administrativo e capabilities detectadas (com timestamp de cache).\n- Fluxo para atualizar descoberta (`/status`, `/capabilities`).\n\nReferência: ARCHITECTURE.md §12 E3; Marco 2.",
    'acceptance_criteria' => "- Dashboard mostra versão/capabilities por site.\n- Refresh de descoberta atualiza cache com timestamp.\n- UI respeita permissões do Controller.",
    'priority' => 'normal',
    'status' => 'backlog',
  ],

  // E4 — Usuários
  [
    'title' => 'E4-T01 — API Agent: listagem e detalhe de usuários',
    'description' => "Contexto: Épico 4 (Usuários) / módulo drm_users.\n\nObjetivo:\n- GET list/detail de usuários no Agent com paginação e campos allowlisted.\n- Autorização por capability; proteção do user 1 / superuser.\n\nReferência: ARCHITECTURE.md §6 e §12 E4; Marco 3.",
    'acceptance_criteria' => "- List/detail autenticados e autorizados.\n- User 1 / contas protegidas não são expostos indevidamente conforme política.\n- Paginação e erros alinhados ao envelope.",
    'priority' => 'high',
    'status' => 'backlog',
  ],
  [
    'title' => 'E4-T02 — API Agent: criar/atualizar/bloquear usuários',
    'description' => "Contexto: Épico 4 (Usuários).\n\nObjetivo:\n- POST/PATCH com validação de campos permitidos, status e roles permitidas.\n- Bloqueio remoto; prevenção de alterações indevidas no superuser.\n\nReferência: ARCHITECTURE.md §6 e §12 E4.",
    'acceptance_criteria' => "- Create/update/block respeitam allowlists.\n- Tentativas de alterar user 1 / elevação indevida são rejeitadas.\n- Testes cobrem happy path e rejeições de segurança.",
    'priority' => 'high',
    'status' => 'backlog',
  ],
  [
    'title' => 'E4-T03 — UI remota de usuários no Controller',
    'description' => "Contexto: Épico 4 (Usuários).\n\nObjetivo:\n- UI no Controller para listar/criar/editar/bloquear usuários remotos com paginação e feedback de erro.\n\nReferência: ARCHITECTURE.md §12 E4; Marco 3.",
    'acceptance_criteria' => "- Fluxos principais usáveis na UI.\n- Erros remotos exibidos de forma sanitizada.\n- Operações respeitam capabilities do site.",
    'priority' => 'normal',
    'status' => 'backlog',
  ],

  // E5 — Roles
  [
    'title' => 'E5-T01 — API Agent: catálogo e mutação allowlisted de roles',
    'description' => "Contexto: Épico 5 (Roles e permissões) / drm_roles.\n\nObjetivo:\n- GET catálogo local; POST/PATCH allowlisted.\n- Proteção contra privilege escalation.\n\nReferência: ARCHITECTURE.md §12 E5; Marco 4.",
    'acceptance_criteria' => "- Catálogo autenticado.\n- Mutações só com permissões/roles na allowlist.\n- Escalada perigosa rejeitada com 403.",
    'priority' => 'high',
    'status' => 'backlog',
  ],
  [
    'title' => 'E5-T02 — Preview/dry-run e comparação de roles entre sites',
    'description' => "Contexto: Épico 5 (Roles e permissões).\n\nObjetivo:\n- Preview/dry-run de alterações e comparação entre sites no Controller.\n\nReferência: ARCHITECTURE.md §12 E5.",
    'acceptance_criteria' => "- Dry-run não aplica mudanças.\n- Comparação entre pelo menos dois sites é legível.\n- Diff não inclui dados sensíveis.",
    'priority' => 'normal',
    'status' => 'backlog',
  ],
  [
    'title' => 'E5-T03 — UI de roles e testes de privilege escalation',
    'description' => "Contexto: Épico 5 (Roles e permissões).\n\nObjetivo:\n- UI no Controller e testes automatizados focados em escalada de privilégios.\n\nReferência: ARCHITECTURE.md §12 E5 e §13 critérios 5/9.",
    'acceptance_criteria' => "- UI cobre catálogo e alteração allowlisted.\n- Testes de escalada falham fechados.\n- Evidência de execução documentada.",
    'priority' => 'normal',
    'status' => 'backlog',
  ],

  // E6 — Orquestração
  [
    'title' => 'E6-T01 — Entidades de operação e resultados por site',
    'description' => "Contexto: Épico 6 (Operações em lote e auditoria).\n\nObjetivo:\n- `drm_operation` e `drm_operation_target` (ou equivalente): UUID, capability, payload hash, estados pending/running/succeeded/failed/partial, resultados por site.\n\nReferência: ARCHITECTURE.md §4 e §12 E6; Marco 5.",
    'acceptance_criteria' => "- Operação registra alvos e estados individuais.\n- Estado `partial` quando aplicável.\n- Sem tokens no armazenamento de auditoria/resultado.",
    'priority' => 'high',
    'status' => 'backlog',
  ],
  [
    'title' => 'E6-T02 — Filas, idempotência e retry limitado',
    'description' => "Contexto: Épico 6 (Operações em lote e auditoria).\n\nObjetivo:\n- Execução em fila, idempotency key por cliente, retry limitado e falha parcial.\n\nReferência: ARCHITECTURE.md §6/§12 E6; §13 critérios 6–7.",
    'acceptance_criteria' => "- Mesma idempotency key não duplica efeitos.\n- Key reusada com payload diferente → 409.\n- Retry limitado e documentado.",
    'priority' => 'high',
    'status' => 'backlog',
  ],
  [
    'title' => 'E6-T03 — Auditoria (drm_audit) sem segredos',
    'description' => "Contexto: Épico 6 (Operações em lote e auditoria) / drm_audit.\n\nObjetivo:\n- Trilha `drm_audit_entry`: ator, ação, alvo, timestamp, resultado, request ID; sem tokens/payload sensível.\n\nReferência: ARCHITECTURE.md §4 e §12 E6.",
    'acceptance_criteria' => "- Eventos relevantes auditados.\n- Logs/auditoria sem segredos.\n- Consulta administrativa básica disponível ou documentada.",
    'priority' => 'normal',
    'status' => 'backlog',
  ],

  // E7 — Conteúdo (fase 2)
  [
    'title' => 'E7-T01 — Mapeamento de bundles/campos e UUIDs (drm_content)',
    'description' => "Contexto: Épico 7 (Conteúdo — fase 2).\n\nObjetivo:\n- Módulo `drm_content`: mapeamento de bundles/campos permitidos e UUIDs; base para CRUD seguro.\n\nReferência: ARCHITECTURE.md §3 e §12 E7; Marco 6.",
    'acceptance_criteria' => "- Apenas bundles/campos allowlisted.\n- Mapeamento UUID documentado.\n- Fora do MVP até liberação explícita da fase 2.",
    'priority' => 'low',
    'status' => 'backlog',
  ],
  [
    'title' => 'E7-T02 — CRUD seguro, conflitos/revisões e publicação autorizada',
    'description' => "Contexto: Épico 7 (Conteúdo — fase 2).\n\nObjetivo:\n- CRUD de nodes com validação; tratamento de conflitos/revisões e publicação autorizada.\n\nReferência: ARCHITECTURE.md §12 E7.",
    'acceptance_criteria' => "- Conflitos detectados e reportados sem corrupção.\n- Publicação só com autorização local.\n- Testes de segurança e validação cobertos.",
    'priority' => 'low',
    'status' => 'backlog',
  ],
];

$created = 0;
$skipped = 0;
foreach ($tasks as $spec) {
  $existing = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('board', $board_id)
    ->condition('title', $spec['title'])
    ->execute();
  if ($existing) {
    echo "SKIP|" . reset($existing) . "|" . $spec['title'] . "\n";
    $skipped++;
    continue;
  }
  $task = $storage->create([
    'workspace' => $workspace,
    'board' => $board_id,
    'title' => $spec['title'],
    'description' => $spec['description'],
    'acceptance_criteria' => $spec['acceptance_criteria'],
    'status' => $spec['status'],
    'priority' => $spec['priority'],
    'position' => $position,
  ]);
  $task->save();
  echo "CREATED|#{$task->id()}|{$spec['status']}|{$task->label()}\n";
  $created++;
  $position += 10;
}

echo "SUMMARY created={$created} skipped={$skipped}\n";

// Final board snapshot
$ids = $storage->getQuery()->accessCheck(FALSE)->condition('board', $board_id)->sort('position')->execute();
foreach ($storage->loadMultiple($ids) as $t) {
  echo "BOARD|#{$t->id()}|{$t->get('status')->value}|{$t->label()}\n";
}
