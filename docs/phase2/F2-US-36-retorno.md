A F2-US-36 (T01–T03) está concluída. Captura assistida fica para a F2-US-37.

Arquivos
Criado: TaskMemoryContext, ApiTaskMemoryPilotTest, F2-US-36-runbook.md, F2-US-36-retorno.md
Modificado: MemoryService, MemoryRepository, ApiController, TaskController, AbUiHooks, templates ab-ui-task-view, openapi.yaml, TaskDetailUiTest, ab_api.services.yml
Atualizado: docs/implementation-status.md

Entrega
Sugestões de memória no detalhe da tarefa e `related_memories` opcional no getTask/MCP `get_task`, sem claim/lease. Piloto A → humano → B com fonte e revisão rastreáveis. Runbook com métricas, rollback, backup e limitações.

Validação
PHPUnit 3 testes / 136 asserções OK. PHPCS exit 0.

Próxima task
F2-US-37 — captura assistida. Aguardo autorização antes de iniciá-la.
