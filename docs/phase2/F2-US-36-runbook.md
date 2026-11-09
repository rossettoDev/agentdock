# F2-US-36 — Runbook do piloto de contexto de tarefa

Operação da memória relacionada à tarefa após F2-US-33…35. Não altera claim, lease nem execução.

## Fluxo do piloto (A → humano → B)

1. Agente A cria draft (`POST /memory` ou MCP `create_memory_draft`) com `board_id` e, se possível, `task_id` / `source_type=task`.
2. Humano revisa na Central de Memória e publica.
3. Agente B lê o contexto **antes** de claim:
   - `GET /workspaces/{ws}/tasks/{task}` com escopo `memory:read` → campo opcional `related_memories` (até 5 hits);
   - ou MCP `get_task` / `search_memory` / `get_memory`.
4. Só então B faz claim se for executar. Leitura de memória **não** cria `ab_execution`.

## Métricas úteis

| Sinal | Como olhar | Esperado no piloto |
| --- | --- | --- |
| Execuções por tarefa | `COUNT(*)` em `ab_execution` ligada à task | Inalterado após getTask / get_memory / search_memory |
| Hits relacionados | presença de `related_memories` no getTask | Presente com `memory:read`; omitido sem o escopo |
| Rastreio | `source` + `revision` no hit / get_memory | `source_type`/`source_id` e versão da revisão publicada |
| Índice | `drush ab-memory:reindex` se busca lexical vazia após publish | Contagem de linhas reindexadas |

## Rollback

- Desligar enriquecimento: remover consumo de `related_memories` no cliente; o campo é aditivo e omitido sem `memory:read`.
- Arquivar a entry publicada (humano) → some das sugestões e da busca padrão.
- Reverter deploy do código da US-36 não apaga entries; claim/lease da Fase 1 permanecem.

## Backup

- Tabelas de domínio: `ab_memory_entry`, `ab_memory_revision`, `ab_memory_source`, `ab_memory_link`.
- Projeção: `ab_memory_search_index` (reconstruível; não é fonte de verdade).
- Backup Postgres usual do workspace; após restore rode `drush ab-memory:reindex` se a busca lexical falhar.

## Limitações

- Sugestões são lexicais + vínculo `task_id`; sem embeddings nem ranking semântico híbrido (F2-US-38).
- Limite de 5 itens; board sem grant `memory:read` não entra na lista do agente.
- Memória workspace-only (sem board) não aparece para agentes.
- UI mostra a seção só quando há hits publicados visíveis ao humano.
- Não há métricas de produto na UI; contagens vêm de SQL/logs de teste.
