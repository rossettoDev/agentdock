A F2-US-32/T01 está concluída. Draft/diff e publish/archive ficam para T02/T03.

Arquivos
Modificado: ab_memory (AccessPolicy gate na contagem, listVisible, Repository)
Criado: ab_ui MemoryController, rotas /memory, templates, MemoryUiTest
Atualizado: ab_ui.info.yml (depende de ab_memory), AbUiHooks (nav Memória), docs

Entrega
countVisible/listVisible usam o mesmo gate de get (grant read + memory:read + token). Central lista, filtra e abre com status, origem e revisão; cache tag ab_workspace_memory:{id}; estados vazio/erro/loading e aria nos filtros.

Validação
PHPUnit 17 testes / 287 asserções OK. PHPCS exit 0.

Próxima task
F2-US-32/T02 — criar/editar rascunho e diff. Aguardo autorização antes de iniciá-la.
