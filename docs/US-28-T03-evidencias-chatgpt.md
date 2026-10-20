**Evidência com cliente de IA (ChatGPT desktop, modo Codex, Windows):**

- Servidor MCP `agentdock` configurado como STDIO em `~/.codex/config.toml`:
  ```toml
  [mcp_servers.agentdock]
  command = "wsl.exe"
  args = ["-d", "Ubuntu", "-e", "/home/rossetto/.local/bin/agentdock-mcp"]
  ```
- `agentdock-mcp` (fora do repositório) carrega `~/.config/agentdock/mcp-inspector.env` (`AGENT_BOARD_URL`, `AGENT_BOARD_TOKEN`, `AGENT_BOARD_WORKSPACE`, modo 600) e executa `php tools/mcp-adapter/adapter.php`
- o servidor só apareceu numa conversa nova, aberta depois de reiniciar o app
- ciclo pedido em linguagem natural ao ChatGPT, em 20/10/2026:
  1. `list_ready_tasks`: 1 tarefa pronta, "Tarefa para o MCP Inspector" (`5992eac0-…`)
  2. `claim_task` com chave de idempotência UUID nova
  3. `get_execution` `728d8c07-76ae-448a-8437-47a8be87e253`: `running`
  4. `release_task` com a lease devolvida pelo claim: `interrupted`
- conferência independente depois do ciclo:
  ```json
  {"id":"728d8c07-…","status":"interrupted","attempt":4,"started_at":"2026-10-20T14:08:19Z","ended_at":"2026-10-20T14:08:26Z","heartbeat_at":null}
  {"id":"5992eac0-…","status":"ready","assigned_agent":null,"active_execution":null,"version":10}
  ```
