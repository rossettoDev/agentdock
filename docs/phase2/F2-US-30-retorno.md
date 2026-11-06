A F2-US-30 (T01–T03) está concluída. API HTTP, MCP e UI de memória não foram criados.

Arquivos
Criado: web/modules/custom/ab_memory/ (módulo, schema, MemoryService, AccessPolicy, ContentGuard, Repository, testes)
Modificado: web/modules/custom/ab_agent/src/AgentTokenScope.php (memory:read / memory:write)
Atualizado: docs/implementation-status.md

Entrega
Schema entry/revision/source/link com update 11001. Serviço draft/revise/publish/archive com hash, versão otimista e rollback. Eventos memory.* via EventStore; autoria ActorContext; links de origem e supersedes.

Validação
PHPUnit 10 testes / 119 asserções OK. PHPCS exit 0. drush en ab_memory OK; quatro tabelas no PostgreSQL.

Próxima task
F2-US-31/T01 — isolamento de conhecimento. Aguardo autorização antes de iniciá-la.
