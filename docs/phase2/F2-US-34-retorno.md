A F2-US-34 (T01–T03) está concluída. Busca lexical fica para a F2-US-35.

Arquivos
Modificado: tools/mcp-adapter (ToolCatalog, ToolGateway), McpAdapterTest, McpAdapterHttpTest, docs/architecture.md
Criado: McpMemoryAdapterHttpTest, docs/phase2/F2-US-34-retorno.md
Atualizado: docs/implementation-status.md

Entrega
Quatro ferramentas MCP (`search_memory`, `get_memory`, `list_memory_revisions`, `create_memory_draft`) só via HTTP. Sem publish/archive no adaptador. Autoria da credencial do processo; `author_id`/`agent_id` recusados. Dois agentes: A grava draft, humano publica, B recupera.

Validação
PHPUnit 5 testes / 154 asserções OK. PHPCS exit 0.

Próxima task
F2-US-35 — busca de memória. Aguardo autorização antes de iniciá-la.
