A F2-US-32 (T01–T03) está concluída. API HTTP e MCP de memória ficam para F2-US-33/34.

Arquivos
T01: MemoryController list/view, gate em countVisible/listVisible, nav Memória
T02/T03: MemoryDraftCreateForm, MemoryDraftEditForm, MemoryStatusForm, MemoryDiff
Atualizado: rotas /memory/add e /memory/{uuid}/edit, templates, MemoryUiTest, docs

Entrega
Central lista/filtra/abre; cria e edita rascunho com diff; publica/arquiva (REVIEW); navega revisões com diff. Markdown sanitizado. Policy e eventos via MemoryService.

Validação
PHPUnit MemoryIsolationKernelTest + MemoryUiTest: 6 testes / 214 asserções OK. PHPCS exit 0.

Próxima task
F2-US-33/T01 — endpoints de busca e leitura. Aguardo autorização antes de iniciá-la.
