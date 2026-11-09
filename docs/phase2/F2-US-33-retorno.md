A F2-US-33 (T01–T03) está concluída. MCP de memória fica para a F2-US-34.

Arquivos
Modificado: ab_api (info, routing, services)
Criado: MemoryController, MemoryCommands, MemoryDocument, ApiMemoryTest
Atualizado: docs/implementation-status.md

Entrega
GET search/get/revisions e POST/PATCH draft/revise/publish/archive sob `/api/agent-board/v1/workspaces/{id}/memory`. Escopos memory:read/write, idempotência UUID, If-Match, whitelist de campos, Problem Details sem vazamento de segredo. OpenAPI 1.1.0 já cobria o contrato.

Validação
PHPUnit ApiMemoryTest + OpenApiContractTest OK. PHPCS exit 0.

Próxima task
F2-US-34 — MCP de memória. Aguardo autorização antes de iniciá-la.
