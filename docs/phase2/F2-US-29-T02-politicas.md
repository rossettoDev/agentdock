# F2-US-29/T02 — Políticas de memória (ADRs)

Data: 2026-11-05. Marco F2-M0. Esta task só registra decisões. O módulo `ab_memory` não foi criado. Nenhum serviço, rota, grant, evento, contrato HTTP ou ferramenta MCP foi alterado.

Identificador no status: **F2-US-29/T02** (o `US-29/T02` do M9 permanece intocado).

## ADRs aceitos

| ADR | Tema |
| --- | --- |
| [003](../adr/003-memoria-escopo-e-visibilidade.md) | Escopo, visibilidade, escopos `memory:*`, matriz de acesso |
| [004](../adr/004-memoria-revisao-e-publicacao.md) | Revisão imutável, publicação humana, eventos, `If-Match` |
| [005](../adr/005-memoria-retencao-e-conteudo-sensivel.md) | Retenção durável, arquivo vs delete, conteúdo sensível |

## Matriz de acesso (piloto)

| Ator | Entry | Buscar / GET publicada | GET rascunho alheio | Draft create/revise | Publish / archive |
| --- | --- | --- | --- | --- | --- |
| Humano viewer | WS ou board do WS | ✔ | ✖ | ✖ | ✖ |
| Humano member+ | WS ou board do WS | ✔ | ✔ | ✔ | ✔ |
| Agente `memory:read` + grant `read` | board do grant | ✔ | ✖ | ✖ | ✖ |
| Agente `memory:write` + grant `write` | board do grant | só se também `memory:read` | ✖ | ✔ | ✖ |
| Agente | visibility `workspace` | ✖ | ✖ | ✖ | ✖ |
| Sem grant / outro WS | — | ✖ (404) | ✖ | ✖ | ✖ |

Humano: permissões existentes `read` / `write` / `review` via `WorkspaceRolePolicy`.  
Agente: `AgentOperationGate` + escopos novos `memory:read` / `memory:write` (OpenAPI na T03).  
Autor: só `ActorContext`; payload sem `author_id` / `agent_id`.

## Política de conteúdo sensível (resumo)

1. Memória é dado não confiável: não executa comando, não amplia grant, não é system prompt.
2. Recusar persistência (422) de padrões óbvios de segredo (bearer `ab_…`, `Authorization: Bearer`, PEM, atribuições `api_key=` / `password=` / `secret=` / `lease_token=`).
3. Sem Markdown em eventos nem em logs; retenção do feed (30 d) ≠ retenção da memória (durável).
4. Arquivar retira da busca; hard delete fica para operação administrativa futura.
5. UI sanitiza na apresentação (`TaskMarkdown`); domínio não depende de `ab_ui`.

## Pontos de extensão confirmados (código em 2026-11-05)

Reconferidos nesta task; alinhados ao inventário T01, com uma correção de caminho:

| Peça | Caminho |
| --- | --- |
| `ActorContext`, `WorkspaceRolePolicy`, `WorkspacePermission`, `MembershipScope`, `WorkspaceBoundary`, `WorkspaceQueryScope`, exceções de escopo | `ab_core/src/Authorization/` |
| `HumanActorContextFactory` | `ab_core/src/Authorization/` |
| `AgentActorContextFactory` | `ab_agent/src/AgentActorContextFactory.php` (**não** sob `Authorization/`) |
| `AgentOperationGate`, `AgentGrantManager`, `AgentTokenScope` | `ab_agent/src/` |
| `EventStoreInterface`, `EventDraft` | `ab_event/src/` |
| `ApiCaller`, `SyncReader`, `IdempotencyService`, `ApiLimits` | `ab_api` |
| `ab_memory` / `WorkspaceAccessPolicy` | ausentes, como esperado |

Dependências futuras do módulo: `ab_core`, `ab_event`, `ab_board`, `ab_agent`. `ab_agent` não depende de `ab_memory`.

## Divergências registradas

| Item | Nota |
| --- | --- |
| Inventário T01 citava `AgentActorContextFactory` sob namespace `Authorization` | Arquivo real: `ab_agent/src/AgentActorContextFactory.php` |
| Backlog Fase 2 ainda marca itens como `todo` no texto introdutório | Status canônico é `docs/implementation-status.md` com prefixo `F2-` |
| Escopos `memory:*` ainda não existem em `AgentTokenScope::all()` | Decisão na ADR 003; implementação de contrato na T03 |

## Fora desta task

Schema, `MemoryService`, OpenAPI, rotas, MCP, UI, índice lexical, embeddings. Próxima: **F2-US-29/T03**.
