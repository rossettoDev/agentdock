# ADR 004: Revisão otimista e política de publicação da memória

**Status:** Aceito  
**Data:** 2026-11-05  
**Contexto:** F2-US-29/T02 — Políticas de memória (Fase 2)

## Contexto

A arquitetura da Fase 2 exige versionamento otimista, revisões imutáveis, autoria auditável e publicação controlada. Mutações de domínio da Fase 1 já usam versão/`If-Match`, `ActorContext` e `EventStore::record()` na mesma transação (ADR 002). A memória deve seguir o mesmo padrão, sem criar execução, alterar claim/lease ou autopublicar a partir de eventos.

## Decisão

### 1. Estados da entry

| Status | Significado |
| --- | --- |
| `draft` | Rascunho; não entra na busca padrão de agentes nem no consumo pré-execução |
| `published` | Visível conforme ADR 003; conteúdo da revisão corrente é o publicado |
| `archived` | Fora da busca padrão; histórico e revisões permanecem consultáveis por UUID a quem já podia ler |

Não há exclusão silenciosa por transição de status. Exclusão definitiva é política do ADR 005.

### 2. Revisões imutáveis

- Cada escrita de conteúdo cria uma linha em `ab_memory_revision` com `version` inteiro monotônico por entry.
- Unicidade `(entry_id, version)` no banco.
- Revisões **nunca** são atualizadas in-place após insert. Correção = nova revisão.
- `content_hash` (hash criptográfico do Markdown normalizado) grava-se na revisão para integridade e deduplicação de candidatos futuros; não substitui a versão otimista.
- `current_revision_id` na entry aponta para a revisão vigente do status atual.

### 3. Autoria

- `author_type` / `author_id` saem exclusivamente do `ActorContext` autenticado.
- Controllers, MCP e formulários recusam `author_id`, `agent_id` e `workspace_id` no corpo (mesmo padrão do `ToolGateway` e da API).
- `source_type` (`manual`, `task`, `execution`, `message`) e `source_id` são metadados opcionais validados no mesmo workspace; são ids opacos, não uma volta ao serviço de execução.

### 4. Concorrência

- Mutações humanas e de agente que alteram conteúdo ou status exigem `If-Match` com a versão otimista da entry (inteiro positivo), no mesmo espírito de `ApiCaller::ifMatch()`: ausente → 428; desatualizado → 412.
- Segundo escritor não sobrescreve: a primeira revisão confirmada vence; a segunda recebe conflito.
- Ordem de locks (ADR 002): bloquear a entry (e vínculos necessários) → gravar revisão/status → `EventStore::record()` por último. Rollback remove revisão, status e evento.

### 5. Quem publica

No piloto:

| Ação | Quem |
| --- | --- |
| Criar rascunho | Humano com `write`; agente com grant `write` + `memory:write` em entry de **board** |
| Revisar rascunho (nova revisão, status permanece `draft`) | Mesmo conjunto, na entry visível |
| Publicar (`draft` → `published`) | Somente humano com `review` |
| Arquivar (`published` ou `draft` → `archived`) | Somente humano com `review` |
| Reabrir arquivada para nova edição | Fora do piloto mínimo; se necessário depois, ADR aditivo |

Agente **não** publica nem arquiva via API nem via MCP. Ferramentas MCP do piloto (F2-US-34): `search_memory`, `get_memory`, `create_memory_draft`, `list_memory_revisions` — sem publish/archive.

Sugestões automáticas a partir de eventos/entregas (F2-M5) geram no máximo candidatos/`draft`; nunca autopublicam.

### 6. Busca e status

- Busca padrão (agente e UI de consumo) retorna apenas `published`, no escopo autorizado (ADR 003).
- `archived` e `draft` não aparecem na busca padrão.
- Leitura por UUID de rascunho/arquivada segue a matriz do ADR 003 (humano com papel adequado; agente não lê rascunho).

### 7. Eventos

Na mesma transação da mutação, tipos mínimos:

- `memory.created` — rascunho criado;
- `memory.revised` — nova revisão em draft ou substituição da revisão corrente antes de publicar;
- `memory.published` — transição para publicado;
- `memory.archived` — arquivamento.

Payload: ids e metadados mínimos; **proibido** incluir o corpo Markdown ou qualquer chave listada como sensível no `EventDraft` (`token`, `secret`, `password`, `authorization`, `lease_token`).

## Consequências

### Positivas

- Histórico auditável sem last-write-wins.
- Publicação humana evita que um agente “promova” conhecimento não revisado.
- Compatível com outbox/eventos existentes.

### Negativas

- Agente depende de humano para tornar a memória pesquisável por outros agentes.
- `If-Match` obriga clientes (UI/MCP) a relerem a entry antes de mutar.

### Neutras

- Diff de UI e índice lexical ficam nas histórias F2-US-32 e F2-US-35; este ADR só fixa a regra de domínio.

## Referências

- `docs/architecture-phase2.md` §§4–5, 7–8
- `docs/adr/002-ordem-de-locks-de-eventos.md`
- `docs/adr/003-memoria-escopo-e-visibilidade.md`
