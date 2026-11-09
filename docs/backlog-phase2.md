# AgentDock — Backlog da Fase 2 (proposta)

Versão 0.2 — 06/11/2026. O status de cada task está em `docs/implementation-status.md`. F2-US-29, F2-US-30, F2-US-31 e F2-US-32/T01 estão `done`. Não altera o status da Fase 1.

Arquitetura de referência: `docs/architecture-phase2.md`. Padrão de execução: uma task por interação com o Cursor, com evidências e atualização de `docs/implementation-status.md`. Não declarar testes que não foram executados.

## F2-M0 — Contratos e decisões

### US-29 — Definir políticas de memória
- **US-29/T01:** inventariar serviços de API, ActorContext, grants, outbox e MCP existentes e documentar pontos de extensão. Aceite: nenhuma regra de domínio duplicada.
- **US-29/T02:** registrar ADRs para escopo, visibilidade, revisão, publicação e retenção. Aceite: matriz de acesso e política para conteúdo sensível.
- **US-29/T03:** definir OpenAPI incremental, limites, erros e plano de migração. Aceite: lint do contrato e mudanças compatíveis com v1.

## F2-M1 — Domínio e revisões

### US-30 — Persistir memória versionada
- **US-30/T01:** criar `ab_memory` e schema de entries/revisions com updates. Aceite: instalação nova e atualização existente.
- **US-30/T02:** implementar `MemoryService` (draft, revise, publish, archive), hash e versão otimista. Aceite: revisão imutável, conflito e rollback.
- **US-30/T03:** integrar EventStore/outbox, autoria ActorContext e links de origem. Aceite: evento transacional sem segredo.
- Residuais da validação de 06/11/2026 não reabrem esta história. Fecham na US-31.

### US-31 — Aplicar isolamento de conhecimento
- **US-31/T01:** corrigir `MemoryAccessPolicy` no padrão de `Catalog::visible()`. Sem grant `read`, responder 404 antes de `AgentOperationGate`; o gate sozinho devolve 403 e confirma o recurso. `publish` e `archive` de entry que o agente não pode ver (sem board ou sem grant) também respondem 404. Agente com `memory:read` e grant lê entry `archived` por UUID (ADR 004); rascunho continua 404 para agente, inclusive o próprio. Aceite: consultas por UUID protegidas.
- **US-31/T02:** filtrar queries, contagens, revisões e cache. Evento de entry sem board não leva `task_id` nem `thread_id` — `memory.created` hoje deixa `task_id` e o `SyncReader` deriva o board. `MemoryRepository::revisions()` não é caminho de leitura; a listagem passa pela policy. Aceite: agente sem grant não descobre memória por query, contagem, revisão, cache nem feed.
- **US-31/T03:** testes funcionais e kernel de cross-workspace, revogação e conteúdo malicioso. `source_id` de task, execution ou message existe no mesmo workspace; UUID solto é recusado. `link()` trava as duas entries pelo menor id e só trata violação de unicidade como link duplicado. `MemoryContentGuard` também recusa segredo em tags e `change_reason`. Aceite: nenhuma leitura após revogação, com teste dos residuais acima.
- Residual da validação de 06/11/2026 (`countVisible` sem gate) fechado na US-32/T01.

## F2-M2 — Interface humana

### US-32 — Central de Memória
- **US-32/T01:** antes da tela, fazer `countVisible()` passar pelo mesmo gate de `get()`: grant `read`, escopo `memory:read` e token ainda válido. Sem isso a contagem revela memória que a leitura recusa. A listagem usa a tag `ab_workspace_memory:{id}` ao renderizar; hoje ela só é invalidada. Depois, listar, filtrar e abrir memórias com status, origem e última revisão. Aceite: estados vazio, erro, loading e acessibilidade; contagem e lista obedecem ao gate.
- **US-32/T02:** criar/editar rascunho e revisar alterações com diff simples. Aceite: render Markdown sanitizado.
- **US-32/T03:** publicar, arquivar e navegar por versões. Aceite: policy respeitada, ação auditável.

## F2-M3 — Integração dos agentes

### US-33 — API de memória
- **US-33/T01:** criar endpoints de busca e leitura com paginação e Problem Details. Aceite: escopo de autorização em todos os resultados.
- **US-33/T02:** criar endpoints de draft/revisão com idempotência, If-Match e sanitização. Aceite: conflito 412 e segredo ausente de logs.
- **US-33/T03:** testes HTTP e atualização OpenAPI. Aceite: testes de autoria forjada, revogação e limites.

### US-34 — MCP de memória
- **US-34/T01:** adicionar `search_memory`, `get_memory` e `list_memory_revisions` ao adaptador. Aceite: chamadas somente pela API.
- **US-34/T02:** adicionar `create_memory_draft` com identidade da credencial. Aceite: não permite atribuir author_id/agent_id.
- **US-34/T03:** validar com dois clientes/agentes e registrar evidências. Aceite: informação criada pelo agente A é recuperada pelo B.

## F2-M4 — Recuperação e piloto

### US-35 — Busca de memória
- **US-35/T01:** criar índice lexical reconstruível com busca PostgreSQL e ordenação relevância/recência. Aceite: filtros de acesso aplicados antes de retornar resultados.
- **US-35/T02:** retornar snippets, fonte, versão e limites de contexto. Aceite: benchmark com consultas conhecidas.
- **US-35/T03:** reindexação, revogação e testes de concorrência. Aceite: revisão publicada aparece e versão arquivada não aparece em pesquisa padrão.

### US-36 — Contexto de tarefa e piloto
- **US-36/T01:** integrar sugestões de memórias relacionadas ao detalhe da tarefa e leitura pré-execução sem alterar claim/lease. Aceite: memória não inicia execução.
- **US-36/T02:** testar o cenário completo A → humano → B via HTTP/MCP. Aceite: fonte e revisão rastreáveis.
- **US-36/T03:** documentar operação, métricas, rollback, backup e limitações. Aceite: runbook e evidências reais.

## F2-M5 — Melhoria opcional (não bloqueia entrega)

### US-37 — Captura assistida
- **US-37/T01:** gerar candidatos de memória a partir de eventos/entregas, com deduplicação. Aceite: não autopublica.
- **US-37/T02:** revisão humana de candidatos. Aceite: pode rejeitar sem gravar como publicado.

### US-38 — Busca híbrida
- **US-38/T01:** medir baseline lexical e definir dataset de relevância, custo e privacidade.
- **US-38/T02:** realizar POC de embeddings locais/serviço independente, com fallback lexical.
- **US-38/T03:** habilitar por configuração apenas se a POC melhorar métricas justificadamente.

## F2-M6 — Futuro

### US-39 — Handoff e portabilidade
- **US-39/T01:** especificar handoff único por sessão, distinto da lease de tarefa.
- **US-39/T02:** implementar exportação Markdown + manifest e validar compatibilidade com formato aberto escolhido.
- **US-39/T03:** testes de isolamento pessoal, recuperação de handoff e restauração de exportação.

## Definition of Done

- Cada task altera somente o incremento previsto, preserva M0–M8 e tem testes de autorização, comportamento e atualização de schema quando cabível.
- Mudança de contrato documentada em OpenAPI/ADR.
- Todos os outputs registram arquivos alterados, comandos realmente executados, resultados e bloqueios.
- Não realizar deploy/push, não inventar resultados e não usar credenciais reais em testes.

## Prompt-base para o Cursor

```text
Leia AGENTS.md, docs/architecture.md, docs/backlog.md,
docs/implementation-status.md, docs/architecture-phase2.md e docs/backlog-phase2.md.
Inspecione os serviços existentes antes de propor alterações.
Implemente somente a task US-29/T01.
Preserve o comportamento da Fase 1, as regras de escopo, grants, ActorContext,
transações, outbox, API e MCP. Não adicione vetores, embeddings nem dependências
LLM nesta task. Não replique regras em controller ou adaptador.
Execute testes pertinentes; documente evidências reais, arquivos modificados,
pendências e atualize o status pelo ID. Não faça push ou deploy.
```
