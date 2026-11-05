# F2-US-29/T03 — OpenAPI incremental e plano de migração

Data: 2026-11-05. Marco F2-M0. Contrato em `docs/openapi.yaml` sobe para **1.1.0** no mesmo prefixo `/api/agent-board/v1`. Nenhum path da Fase 1 foi removido ou renomeado. O módulo `ab_memory` e as rotas Drupal **ainda não** existem.

## Compatibilidade com v1

| Regra | Aplicação |
| --- | --- |
| Base URL | Continua `/api/agent-board/v1` |
| Paths existentes | Intactos (workspaces, boards, tasks, executions, messages, events) |
| Schemas existentes | Intactos; `ETag`/`If-Match` documentam também memória |
| Erros | Mesmos Problem Details (`401`, `403`, `404`, `409`, `412`, `413`, `422`, `428`, `429`) |
| Aditivo | Tag `memory`, sete operações, schemas `Memory*`, parâmetros de filtro |
| Fora deste contrato | `GET .../memory/export` (F2-US-39); hard delete; embeddings |

Clientes que ignoram paths desconhecidos seguem válidos. Implementação HTTP só na F2-US-33; até lá as rotas novas respondem 404 de roteamento Drupal.

## Limites (contrato)

| Limite | Valor |
| --- | --- |
| Body HTTP geral | 256 KiB → 413 |
| `content_markdown` | 65536 caracteres (64 KiB) |
| `title` | 255 |
| `q` | 256 |
| `excerpt` | 480 |
| tags | até 20 × 48 caracteres |
| Página | default 50, máx. 100 |
| Idempotência sem segredo | 24 h (igual Fase 1) |

Conteúdo com padrão óbvio de segredo → **422** (ADR 005). Fora de escopo → **404**. Operação proibida em recurso visível → **403**.

## Escopos de token

Novos (aditivos):

- `memory:read` → grant `read`
- `memory:write` → grant `write`

Tokens emitidos antes da implementação **não** carregam esses escopos. Comportamento esperado após F2-US-33:

1. Chamada de memória sem escopo → 403 (recurso visível) ou 404 (fora do grant/board).
2. Rotação/emissão de token na UI deve oferecer os novos escopos (história de agentes / F2-US-33).
3. `AgentTokenScope::all()` e `grantFor()` passam a conhecê-los na mesma história que habilitar a API — **não** nesta T03.
4. MCP (F2-US-34) só encaminha HTTP; o processo precisa de token já emitido com os escopos.

Publish/archive **não** têm escopo de agente.

## Ordem de implementação (sem adiantar código aqui)

1. **F2-US-30** — schema `ab_memory`, `MemoryService`, eventos.
2. **F2-US-31** — `MemoryAccessPolicy` conforme ADRs 003–005.
3. **F2-US-33** — controllers HTTP + `AgentTokenScope` + OpenAPI já alinhado.
4. **F2-US-34** — ferramentas MCP sobre os paths.
5. **F2-US-35** — busca lexical real atrás de `searchMemory`.

## Operações do contrato 1.1.0

| operationId | Método / path | Ator piloto |
| --- | --- | --- |
| `searchMemory` | `GET /workspaces/{w}/memory` | humano `read` / agente `memory:read` |
| `createMemoryDraft` | `POST /workspaces/{w}/memory` | humano `write` / agente `memory:write` + board |
| `getMemory` | `GET /workspaces/{w}/memory/{id}` | conforme matriz ADR 003 |
| `reviseMemory` | `PATCH /workspaces/{w}/memory/{id}` | write + `If-Match` |
| `publishMemory` | `POST .../publish` | humano `review` |
| `archiveMemory` | `POST .../archive` | humano `review` |
| `listMemoryRevisions` | `GET .../revisions` | leitura autorizada |

## Validação desta task

Lint Redocly do `docs/openapi.yaml` e asserções em `OpenApiContractTest` (versão 1.1.0, paths de memória, ausência de `lease_token` nos schemas de memória, limites documentados).
