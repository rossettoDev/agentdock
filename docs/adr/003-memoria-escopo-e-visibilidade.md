# ADR 003: Escopo e visibilidade da memória compartilhada

**Status:** Aceito  
**Data:** 2026-11-05  
**Contexto:** F2-US-29/T02 — Políticas de memória (Fase 2)

## Contexto

A Fase 2 introduz conhecimento durável consultável por humanos e agentes. O inventário F2-US-29/T01 mostrou que:

- a fronteira de autorização continua sendo o workspace;
- o grant de agente existe apenas por board (`AgentOperationGate` + `ab_agent_board_grant`);
- não há grant de workspace nem escopo `memory:*`;
- o feed de sincronização (`SyncReader`) só filtra board pela chave `board_id` (ou derivação por `task_id`/`thread_id`); evento sem board fica visível a humanos do workspace e oculto a todo agente;
- `ActorContext` de agente nasce com permissões vazias; `allows()` não autoriza agente.

A arquitetura da Fase 2 propõe visibilidade `workspace` ou `board` no piloto (`private` fica fora).

## Decisão

### 1. Fronteira

Todo `ab_memory_entry` pertence a exatamente um workspace. Referências a board ou tarefa de outro workspace são inválidas e respondem como recurso ausente (404), via `WorkspaceBoundary` e o mesmo padrão de `ScopedResourceNotFoundException` da Fase 1.

### 2. Visibilidade no piloto

| Valor | Campo `board_id` | Quem lê no piloto |
| --- | --- | --- |
| `board` | obrigatório, mesmo workspace | Humanos com `read` no workspace; agentes com grant `read` nesse board e escopo de token de memória de leitura |
| `workspace` | ausente (`NULL`) | Somente humanos com `read` no workspace |

Visibilidade `private` e memória pessoal entre workspaces ficam fora do piloto.

**Agente e memória só de workspace:** no piloto, agentes **não** leem, criam, revisam nem descobrem por busca/UUID/revisões uma entrada com visibilidade `workspace`. Não se inventa grant sem board nem se altera `AgentOperationGate` para aceitar board nulo. Evolução futura exigiria ADR próprio.

### 3. Escopos de token novos

Não reutilizar `tasks:read` / `tasks:write` para memória: misturaria domínios. Introduzir na F2-US-29/T03 (OpenAPI) e na implementação do token:

- `memory:read` — busca e leitura de entradas publicadas no board concedido;
- `memory:write` — criar e revisar rascunho no board concedido.

Interseção com o grant existente, sem nova permissão de grant:

| Escopo | Grant exigido (`AgentTokenScope::grantFor`) |
| --- | --- |
| `memory:read` | `read` |
| `memory:write` | `write` |

Publicar e arquivar **não** recebem escopo de agente. Continuam operações humanas.

### 4. Autorização humana (papéis existentes)

Sem criar permissões Drupal globais nem ids novos em `WorkspacePermission` no piloto. Mapear operações de memória aos ids já emitidos por `WorkspaceRolePolicy`:

| Operação | Permissão humana |
| --- | --- |
| Buscar / ler publicada (e revisões publicadas) | `read` |
| Criar rascunho / editar rascunho (nova revisão em `draft`) | `write` |
| Publicar / arquivar | `review` |
| Ler rascunho de outro autor | `review` (ou o próprio autor com `write`) |

Viewer (`read`) não cria nem publica. Member (`write`+`review`) cria, revisa e publica. Admin e owner herdam o mesmo conjunto operacional de member para memória de conteúdo; gestão de membros/agentes/boards não é requisito extra para memória.

### 5. Matriz de acesso (piloto)

Legenda: ✔ permitido · ✖ negado · — não aplicável.

| Ator | Escopo da entry | Buscar / GET publicada | GET rascunho alheio | Criar / revisar draft | Publicar / arquivar |
| --- | --- | --- | --- | --- | --- |
| Humano viewer | workspace ou board do WS | ✔ | ✖ | ✖ | ✖ |
| Humano member+ | workspace ou board do WS | ✔ | ✔ (`review`) | ✔ | ✔ |
| Agente com grant+`memory:read` | board do grant | ✔ | ✖ | ✖ | ✖ |
| Agente com grant+`memory:write` | board do grant | ✔ se também `memory:read` | ✖ | ✔ | ✖ |
| Agente qualquer | workspace (`board_id` nulo) | ✖ | ✖ | ✖ | ✖ |
| Agente sem grant no board | board alheio no mesmo WS | ✖ (404) | ✖ | ✖ | ✖ |
| Qualquer ator de outro WS | qualquer | ✖ (404) | ✖ | ✖ | ✖ |

Autoria nunca vem do payload (`author_id` / `agent_id` rejeitados). Identidade = sessão humana ou bearer do agente (`ActorContext`).

### 6. Eventos de sincronização

Tipos novos no vocabulário aberto do `EventDraft`, por exemplo `memory.created`, `memory.revised`, `memory.published`, `memory.archived`. Payload mínimo: UUID da entry, revisão, status, `kind` e ids de origem — **sem** Markdown.

- Entry com visibilidade `board`: payload **obrigatoriamente** com `board_id` (UUID). Assim o `SyncReader` aplica o grant.
- Entry com visibilidade `workspace`: sem `board_id`; humanos do workspace veem o evento; agentes não (comportamento atual do reader quando o board resolve para string vazia).

Não alterar a lógica de claim/lease. Memória não cria execução.

### 7. Encaixe em `MemoryAccessPolicy` (ainda não implementado)

Conforme inventário T01:

- humano: `ActorContext::allows()` com o mapa da seção 4;
- agente + board: `AgentOperationGate::assert($actor, $board, 'memory:read'|'memory:write')`;
- agente + entry sem board: negar sem consultar grant;
- repositório filtra `workspace_id` na query (`WorkspaceQueryScope` não cobre entidade nova).

## Consequências

### Positivas

- Reusa gate, grants e papéis sem duplicar SQL de autorização.
- Agente nunca descobre memória de board sem grant nem memória só de workspace no piloto.
- Escopos novos deixam o contrato explícito para OpenAPI (T03) sem misturar com tarefas.

### Negativas

- Memória útil a agentes exige `board_id` no piloto.
- Tokens existentes não terão `memory:*` até rotação/emissão com os novos escopos (plano na T03).
- `SyncReader` não precisa mudar para o caso board; memória workspace continua invisível a agentes no feed — alinhado à matriz.

### Neutras

- `private` e grant de workspace ficam para ADR futuro.
- Caminho real de `AgentActorContextFactory`: `web/modules/custom/ab_agent/src/AgentActorContextFactory.php` (não sob `Authorization/`).

## Referências

- `docs/architecture-phase2.md` §§2–6
- `docs/phase2/F2-US-29-T01-inventario.md`
- `docs/adr/002-ordem-de-locks-de-eventos.md`
- `AgentOperationGate`, `AgentTokenScope`, `WorkspaceRolePolicy`, `SyncReader`
