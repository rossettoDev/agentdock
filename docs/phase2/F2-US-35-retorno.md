A F2-US-35 (T01–T03) está concluída. Contexto de tarefa/piloto fica para a F2-US-36.

Arquivos
Criado: MemorySearchIndex, MemoryReindexCommands, MemorySearchKernelTest, F2-US-35-retorno.md
Modificado: MemorySchema, MemoryService, MemoryRecord, ab_memory.install/services, testes kernel, MemoryDocument
Atualizado: docs/implementation-status.md

Entrega
Projeção `ab_memory_search_index` reconstruível (tsvector no PostgreSQL). Busca com `q` filtra workspace/board/status no SQL, ordena por rank e recência; padrão só published. Snippet ≤ 480, fonte e revisão no hit. Publish/archive sincronizam; `ab-memory:reindex` e update 11002 reconstroem; arquivado some da busca padrão; grant revogado esconde o hit.

Validação
PHPUnit 13 testes / 283 asserções (kernel memória) OK. PHPCS exit 0. Rodar `drush updb` no ambiente local para criar o índice.

Próxima task
F2-US-36 — contexto de tarefa e piloto. Aguardo autorização antes de iniciá-la.
