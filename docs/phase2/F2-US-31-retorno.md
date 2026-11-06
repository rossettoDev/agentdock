A F2-US-31 (T01–T03) está concluída. API HTTP, MCP e UI de memória não foram criados.

Arquivos
Modificado: web/modules/custom/ab_memory/ (AccessPolicy, Service, Repository, ContentGuard, services.yml)
Criado: web/modules/custom/ab_memory/tests/src/Kernel/MemoryIsolationKernelTest.php
Atualizado: docs/implementation-status.md

Entrega
Policy 404-before-gate; agente lê archived e não lê draft. Contagens/revisões filtradas; evento workspace-only sem task_id. source_id validado no WS; link pelo menor id; guard em tags e change_reason.

Validação
PHPUnit 15 testes / 207 asserções OK. PHPCS exit 0.

Próxima task
F2-US-32/T01 — Central de Memória. Aguardo autorização antes de iniciá-la.
