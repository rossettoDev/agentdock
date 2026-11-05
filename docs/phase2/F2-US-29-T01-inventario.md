# F2-US-29/T01 — Inventário de serviços e pontos de extensão

Data: 2026-11-05. Marco F2-M0. Esta task só documenta. O módulo `ab_memory` não foi criado. Nenhum serviço, rota, grant, evento, contrato HTTP ou ferramenta MCP foi alterado.

O identificador `US-29/T01` já está usado no M9, com status `done`, em `docs/implementation-status.md` e em `docs/US-29-T01-matriz.md` (matriz de telas). Aquela entrega permanece. Esta é a task homônima de `docs/backlog-phase2.md`. No status ela entra como **F2-US-29/T01**.

## 1. Módulos inspecionados

| Módulo | Papel no código | Depende de |
| --- | --- | --- |
| `ab_core` | Workspace, membership, `ActorContext`, papéis | `user`, `options` |
| `ab_event` | `EventStore`, sequência, outbox, worker | `ab_core` |
| `ab_board` | Board, coluna, tarefa, dependência, transição | `ab_core`, `ab_event` |
| `ab_agent` | Agente, token, grant, claim, lease, revisão | `ab_core`, `ab_board`, `ab_event` |
| `ab_communication` | Thread, mensagem, menção, input request, notificação | `ab_core`, `ab_event`, `ab_board`, `ab_agent` |
| `ab_api` | HTTP `/api/agent-board/v1`, idempotência, limite, Problem Details | os cinco acima |
| `ab_ui` | Telas, sessão de workspace, Markdown | `ab_core`, `ab_board`, `ab_agent`, `ab_communication` |

`ab_ui` não depende de `ab_api` nem de `ab_event`. O adaptador MCP não é módulo Drupal: fica em `tools/mcp-adapter` e só fala HTTP. Não existe `web/modules/custom/ab_memory`.

Grafo que a memória precisa respeitar: `ab_core` e `ab_event` na base; `ab_board` sobre os dois; `ab_agent` sobre board; `ab_communication` sobre agent. `ab_api` e `ab_ui` compõem serviços e não são base de domínio. Um módulo novo não pode ser dependência de `ab_core`, `ab_event`, `ab_board` ou `ab_agent`.

## 2. Serviços que a memória reutiliza

Nenhuma regra abaixo deve ser reimplementada em controller, formulário ou adaptador MCP.

### Identidade

| Peça | Onde | Uso para memória |
| --- | --- | --- |
| `ActorContext` | `ab_core/src/Authorization/ActorContext.php` | Autor da revisão: `actorType`, `actorId`, `workspaceId`, `tokenId`. Tipos `human`, `agent`, `system`. |
| `HumanActorContextFactory` | `ab_core` | Humano da sessão ou da API. O id é a conta autenticada. Membership inativa gera permissões vazias. |
| `AgentActorContextFactory` | `ab_agent` | Agente lido do atributo `ab_agent_actor`, gravado pelo provider bearer. Argumento `agent_id` é ignorado. |
| `ApiCaller` | `ab_api/src/Http/ApiCaller.php` | Resolve o ator da rota. Bearer de outro workspace vira recurso ausente. Humano sem `read` também. Mutação humana exige `X-CSRF-Token`. |

O `ActorContext` do agente é construído com a lista de permissões vazia. `ActorContext::allows()` não autoriza agente. A autorização do agente é `AgentOperationGate`.

### Autorização humana

| Peça | Onde | Uso |
| --- | --- | --- |
| `WorkspaceRolePolicy` | `ab_core` | Papel de membership ativo: viewer `read`; member acrescenta `write` e `review`; admin acrescenta gestão de membros, agentes e boards; owner acrescenta workspace, credenciais e transferência. |
| `WorkspacePermission` | `ab_core` | Ids estáveis: `read`, `write`, `review`, `membership.manage`, `membership.transfer`, `workspace.manage`, `agent.manage`, `credential.manage`, `board.manage`. |
| `MembershipScope` | `ab_core` | Fonte de tenancy do humano. Consulta membership sem `accessCheck` de entidade. |
| `WorkspaceBoundary` | `ab_core` | Recusa referência cujo campo `workspace` aponta para outro workspace. |
| `WorkspaceQueryScope` | `ab_core` | Restringe query de entidade a workspaces visíveis. Hoje só `ab_workspace`, `ab_membership`, `ab_board`, `ab_task`, `ab_agent` e `ab_execution`. |
| `ScopedResourceNotFoundException` | `ab_core` | Fora de escopo. A API responde 404 com detalhe fixo. |
| `ScopedOperationDeniedException` | `ab_core` | Operação proibida em recurso visível. A API responde 403. |
| `ApiExceptionSubscriber` | `ab_api` | Traduz essas exceções e `AgentAccessDeniedException` para Problem Details, sem mensagem de domínio nem stack. |

`WorkspaceAccessPolicy`, nome da arquitetura da Fase 1, não existe como classe. O papel está dividido em `WorkspaceRolePolicy`, `MembershipScope`, `WorkspaceBoundary` e `WorkspaceQueryScope`.

### Grants e credencial de agente

| Peça | Onde | Uso |
| --- | --- | --- |
| `AgentGrantManager` | `ab_agent` | Grava `ab_agent_board_grant`. Permissões delegáveis: só `read`, `write`, `review`. Board de outro workspace é rejeitado. Eventos `agent.grant_added`, `agent.grant_updated`, `agent.grant_removed`. |
| `AgentOperationGate::assert()` | `ab_agent` | Interseção de token utilizável, escopo, workspace do board e grant. Conta Drupal da requisição não entra. |
| `AgentTokenScope` | `ab_agent` | Escopos atuais: `boards:read`, `tasks:read`, `tasks:write`, `tasks:claim`, `executions:write`, `messages:read`, `messages:write`, `events:read`. Cada um ainda exige `read` ou `write` no grant. |
| `AgentTokenAuthenticationProvider` | `ab_agent` | Provider `ab_agent_token`. Não há login interativo do agente. |

Não há grant de workspace. Não há escopo `memory:*`. Memória presa a um board reusa `AgentOperationGate` com um escopo já existente só se a política da US-29/T02 disser que `read`/`write` de board cobre memória. Criar escopo novo é mudança de contrato e fica fora desta task.

### Eventos e outbox

| Peça | Onde | Uso |
| --- | --- | --- |
| `EventStoreInterface::record()` | `ab_event` | Grava `ab_event` e `ab_outbox` na transação do chamador. Não abre nem confirma transação. |
| `EventDraft` | `ab_event` | Tipo `^[a-z][a-z0-9]*(\.[a-z][a-z0-9_]*)+$`, no máximo 64 caracteres. Ator `human`, `agent` ou `system`. Payload sem as chaves `authorization`, `lease_token`, `password`, `secret`, `token`. |
| Destino `sync` | constante do store | Destino padrão. `OutboxProcessor` despacha destinos etiquetados `ab_event.outbox_destination`. |
| `SyncReader` | `ab_api` | Filtra o feed por board legível agora. |
| ADR 002 | `docs/adr/002-ordem-de-locks-de-eventos.md` | Locks de domínio primeiro. `record()` por último. Ninguém bloqueia linha de domínio depois de `ab_workspace_sequence`. |

O vocabulário de tipos não é um enum fechado. Um tipo `memory.published` cabe no `EventDraft` sem alterar o store. O payload de sincronização que deve obedecer ao grant precisa da chave `board_id` com UUID do board. `SyncReader::boardOf()` lê `board_id`, `task_id` e `thread_id`. A chave `board`, usada hoje em `AgentGrantManager`, não entra nesse filtro: o evento fica visível a humanos do workspace e oculto para todo agente.

Evento sem `board_id` e com tipo fora de `board.`, `task.`, `execution.`, `message.` e `input.` também fica visível a humanos e oculto para agentes. Isso esconde memória de workspace de todo agente, inclusive de quem tem grant em algum board.

Corpo Markdown não entra no payload. O evento leva UUID, revisão, status e ids de origem.

### API e MCP

| Peça | Onde | Uso |
| --- | --- | --- |
| Rotas | `ab_api.routing.yml` | Prefixo `/api/agent-board/v1`. `_access: TRUE`; 401 fica no controller quando não há credencial. |
| `IdempotencyService::execute()` | `ab_api` | Chave por ator, token, workspace, operação e recurso. Mesmo corpo repete a resposta. Corpo diferente com a mesma chave responde 409. Retenção sem segredo: 24 h (`ApiLimits::RETENTION`). |
| `ApiCaller::ifMatch()` | `ab_api` | `If-Match` inteiro positivo. Ausente ou fraco responde 428. |
| `ApiLimits` | `ab_api` | Corpo 256 KiB, página 50/100, 120 req/min. Mensagem de conversa é 16 KiB e não é o limite de memória. |
| `ProblemDetails` | `ab_api` | `application/problem+json`. |
| `ApiResponseSubscriber` | `ab_api` | `Cache-Control: private, no-store` nas respostas da API. |
| `ToolCatalog` e `ToolGateway` | `tools/mcp-adapter` | Doze ferramentas atuais. O gateway rejeita `agent_id`, `author_id`, `workspace_id`, `authorization`, `password`, `secret`, `token` e `bearer`. Workspace e bearer vêm de `AGENT_BOARD_WORKSPACE` e `AGENT_BOARD_TOKEN`. |
| `WorkspaceContext` | `ab_ui` | Workspace da interface na sessão (`ab_workspace`). Não lê `?workspace=`. |
| `TaskMarkdown` | `ab_ui` | Subconjunto Markdown sanitizado com `Xss::filter`. É apresentação. O texto persistido continua cru. |

Claim, heartbeat, lease, submit e revisão não são pontos de extensão desta fase. Memória não cria execução nem move card.

## 3. Estrutura proposta para `ab_memory`

Não implementar neste passo.

```text
web/modules/custom/ab_memory/
  ab_memory.info.yml          # ab_core, ab_event, ab_board, ab_agent
  ab_memory.services.yml
  ab_memory.install           # schema novo e update de instalação existente
  src/MemoryAccessPolicy.php  # humano por ActorContext; board por AgentOperationGate
  src/MemoryService.php       # draft, revise, publish, archive; evento na mesma transação
  src/MemoryRepository.php    # leitura sempre com workspace_id
```

Dependências futuras, só nas histórias que as usam:

- `ab_api` passa a depender de `ab_memory` na API (F2-US-33). Rotas novas sob `/workspaces/{w}/memory`. Comandos novos em `ab_api/src/Http/`, no padrão de `TaskCommands`: `ApiCaller`, `IdempotencyService`, exceções de escopo. Sem SQL de grant no controller.
- `ab_ui` passa a depender de `ab_memory` na central (F2-US-32). Workspace por `WorkspaceContext`. HTML por `TaskMarkdown`.
- `tools/mcp-adapter` ganha ferramentas na F2-US-34, cada uma um caminho HTTP. Sem acesso a banco. Sem publicar nem arquivar no piloto.

`MemoryAccessPolicy` chama `AgentOperationGate::assert()` para memória com board. Não copia o `SELECT` de `ab_agent_board_grant`. Para humano, usa `ActorContext::allows()` montado por `HumanActorContextFactory`. `WorkspaceBoundary` valida board e tarefa opcionais no mesmo workspace.

`MemoryService` abre a transação, bloqueia a entry, grava revisão e só então chama `EventStoreInterface::record()`, destino `sync`. Rollback desfaz revisão e evento, como no ADR 002.

Índice lexical, embeddings e handoff ficam nas histórias posteriores. Nesta fase não há destino novo de outbox.

## 4. Restrições

- Workspace é a fronteira. UUID de outro workspace responde como ausente.
- Operação proibida num recurso visível responde 403.
- Autor é sessão ou bearer. Payload não traz `author_id` nem `agent_id`.
- Grant de agente é por board e só `read`, `write`, `review`.
- `WorkspaceQueryScope` não cobre uma entidade nova. O repositório de memória filtra por workspace na query, como `BoardRepository`.
- `EventDraft` recusa segredo no payload e exige transação aberta.
- Ordem de lock do ADR 002.
- API existente, as doze ferramentas MCP, claim, heartbeat e lease permanecem.
- Sem vetor, sem LLM, sem cliente de embeddings.

## 5. Riscos de integração

1. **Colisão de IDs.** F2-US-29 a F2-US-37 reutilizam números do M9. Status e commits precisam do prefixo `F2-` para não reabrir história visual já `done`.
2. **Memória de workspace e agente.** Não existe grant sem board. `SyncReader` esconde do agente o evento sem `board_id`. A política (humano apenas, ou outro critério) é decisão da F2-US-29/T02, não um `SELECT` novo nesta task.
3. **Escopo de token.** Os oito escopos atuais não nomeiam memória. Reusar `tasks:read` misturaria domínios. Escopo novo é contrato e entra no OpenAPI da T03, com interseção no grant já existente.
4. **Chave do payload.** Sincronização só filtra board pela chave `board_id`. Outro nome publica o evento para todo humano do workspace.
5. **Conteúdo no feed.** Payload mínimo. O Markdown fica na revisão. Retenção do feed é 30 dias (`ApiLimits::EVENT_RETENTION`) e não é a retenção da memória.
6. **`ActorContext` do agente.** Permissões sempre vazias. Uma policy que só chame `allows()` nega todo agente, ou, se alguém preencher a lista no factory, muda o claim. O factory não deve ser alterado para memória.
7. **Sanitização.** `TaskMarkdown` vive em `ab_ui`. O módulo de domínio não pode depender da UI. A API guarda Markdown; a UI renderiza.
8. **Ciclo de módulos.** `ab_memory` depende de `ab_agent` para o gate. `ab_agent` não pode depender de `ab_memory`. Link de origem para tarefa ou mensagem é id opaco conferido no workspace, não uma chamada de volta ao serviço de execução.

## 6. Divergências entre documentação e código

| Documento | Código em 2026-11-05 |
| --- | --- |
| Arquitetura Fase 1 cita `WorkspaceAccessPolicy` | Não há essa classe. Há `WorkspaceRolePolicy` e os serviços da seção 2. |
| Arquitetura Fase 1 lista serviços centrais sem coluna | `ColumnManager` e `ColumnRepository` existem em `ab_board` e têm rotas na API. Fora do escopo de memória. |
| Fase 2 fala em `US-29/T01` | O mesmo id no M9 já está `done` e aponta para `docs/US-29-T01-matriz.md`. |
| `ab_event.info.yml` declara `ab_core` | Os outros módulos declaram `ab_core:ab_core`. Os dois formatos instalam. Não mudar o arquivo nesta task. |
| Fase 2 sugere visibilidade `workspace` ou `board` | O gate e o feed só sabem filtrar agente por board. |
| Backlog da Fase 2 diz que todos os itens estão `todo` | Verdadeiro para a Fase 2. O M9 homônimo não volta a `todo`. |

## 7. Fora desta task

Schema, `MemoryService`, ADR, OpenAPI, rotas, ferramentas MCP, UI, índice e embeddings. A próxima task do marco, se autorizada, é a F2-US-29/T02: ADRs de escopo, visibilidade, revisão, publicação e retenção.
