#### US-28/T03 — Integrar um cliente real

**Status:** done  
**Entrega:** O MCP Inspector oficial (`@modelcontextprotocol/inspector` 2.11.0, modo CLI, Node 24.22.0) fala com `tools/mcp-adapter/adapter.php` por stdio no WSL, contra o site Lando local.

**Contrato:**
- cada chamada do Inspector é um processo novo. O fim do processo não libera a execução
- a credencial vem de `~/.config/agentdock/mcp-inspector.env` (`AGENT_BOARD_URL`, `AGENT_BOARD_TOKEN`, `AGENT_BOARD_WORKSPACE`, modo 600), fora do Git
- o token do "Agente MCP Inspector" tem os escopos `boards:read`, `tasks:read`, `tasks:claim` e `executions:write`, com grant read/write no quadro de ensaio

**Evidências:**
```bash
$ npx -y @modelcontextprotocol/inspector --cli php tools/mcp-adapter/adapter.php \
    -e AGENT_BOARD_URL=... -e AGENT_BOARD_TOKEN=... -e AGENT_BOARD_WORKSPACE=... \
    -e PHP_INI_SCAN_DIR=:$HOME/.local/php-ext/conf.d --method tools/list
12 ferramentas: list_boards, list_ready_tasks, get_task, claim_task, report_progress,
post_message, request_human_input, get_execution, submit_result, fail_execution,
release_task, get_agent_inbox

$ ... --method tools/call --tool-name claim_task --tool-arg task_id=5992eac0-… --tool-arg idempotency_key=<uuid novo>
{"execution_id":"9c9b9540-…","task_id":"5992eac0-…","lease_token":"<omitido>","lease_expires_at":"2026-10-20T13:38:14Z","task_version":7}
# processo encerrado: tarefa in_progress, execução running, ended_at null

$ ... --tool-name get_execution --tool-arg execution_id=9c9b9540-…
{"status":"running","attempt":3,"heartbeat_at":null,"ended_at":null}   # sem lease na resposta

$ ... --tool-name release_task --tool-arg execution_id=9c9b9540-… --tool-arg lease_token=<omitido> --tool-arg idempotency_key=<uuid novo>
{"status":"interrupted","ended_at":"2026-10-20T13:36:20Z"}
# tarefa volta a ready, assigned_agent null, active_execution null
```

**Notas de ambiente:**
- o PHP 8.1 do WSL não tinha a extensão curl. A extensão foi extraída do pacote `php8.1-curl` em `~/.local/php-ext` e carregada por `PHP_INI_SCAN_DIR`, sem sudo
- o claim exigiu `$settings['ab_api_idempotency_key']` no `settings.local.php` local, como manda `docs/runbooks/operacao.md`. A chave não entra no repositório

**Bloqueios:** nenhum.

**Próxima ação:** —
