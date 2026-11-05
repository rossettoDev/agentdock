---
name: agentdock-agente
description: >-
  Opera o quadro AgentDock pelo servidor MCP agentdock (listar quadros,
  assumir tarefa pronta, registrar progresso, entregar resultado) e
  cadastra um Cursor externo no mesmo quadro. Use quando o usuario pedir
  para conectar um agente ou outro Cursor, operar o board, assumir uma
  tarefa, ou quando as ferramentas MCP agentdock estiverem disponiveis.
---

# Agente no quadro AgentDock

Quem altera o codigo deste repositorio segue o `AGENTS.md`. Este fluxo vale para operar o quadro ou conectar outro Cursor.

A credencial fica em `.cursor/agentdock.env`. Nao abra esse arquivo, nao copie o bearer, a lease nem a chave de idempotencia para o chat, para commits ou para arquivos versionados.

## Conectar outro Cursor

No AgentDock, com o workspace selecionado na sessao:

1. Crie o quadro em `/boards/add`. O UUID esta em `/boards/{uuid}`.
2. Crie o agente em `/agents/add`, habilitado, limite 1.
3. Na ficha, conceda nesse quadro **Ler** e **Escrever**.
4. Crie o token com: Ler boards, Ler tarefas, Escrever tarefas, Assumir tarefas, Escrever execucao, Ler mensagens, Escrever mensagens. Copie o segredo uma vez.
5. O UUID do workspace esta em `/workspaces/{uuid}`.

Neste clone:

1. Copie `.cursor/agentdock.env.example` para `.cursor/agentdock.env` e preencha URL, token e workspace.
2. Copie `.cursor/agentdock.board.example` para `.cursor/agentdock.board` e preencha `BOARD_ID`.
3. Recarregue o MCP. O servidor `agentdock` usa `php tools/mcp-adapter/cursor.php`. E preciso de PHP com curl.

O outro projeto nao recebe a pasta `tools`. Extraia o pacote numa pasta fixa, fora desse projeto. Copie so `.cursor/skills/agentdock-agente` para `~/.cursor/skills/` (no Windows, `%USERPROFILE%\.cursor\skills\`). No MCP do usuario, o `args` aponta para o caminho absoluto de `tools/mcp-adapter/cursor.php` dentro da pasta extraida. O modelo esta em `.cursor/mcp.outro-projeto.example.json`. O arquivo `.cursor/agentdock.env` fica na pasta extraida: o `cursor.php` acha ele sozinho. Copie apenas `.cursor/agentdock.board` para o `.cursor/` do outro projeto.

## Operar o quadro

Leia `BOARD_ID` em `.cursor/agentdock.board` do projeto aberto. Se esse arquivo nao existir, peca o UUID do quadro uma vez. Nao procure nem abra `agentdock.env`.

As ferramentas MCP sao o unico acesso. O card e uma tarefa. A coluna muda assim:

| Acao | Coluna |
|---|---|
| Humano cria e move ate Pronta | Backlog ou Pronta |
| `claim_task` | Em andamento |
| `submit_result` | Em revisao |
| Humano aprova | Concluida |
| `release_task` ou `fail_execution` | devolve a tarefa |

1. `list_boards` e confira o `BOARD_ID`.
2. `list_ready_tasks` com esse `board_id`. Lista vazia: pare e diga que nao ha tarefa pronta.
3. `get_task` e leia titulo, descricao e criterios.
4. `claim_task` com um `idempotency_key` UUID novo. Guarde `execution_id` e `lease_token` so para as chamadas seguintes. A lease dura cerca de 120 segundos e este adaptador nao renova.
5. `report_progress` para notas curtas. `post_message` para registrar no fio. `request_human_input` quando faltar decisao humana; pare e espere.
6. `submit_result` com resumo e links. Quem aprova e um humano.
7. Se nao der para concluir, `fail_execution` ou `release_task`, um dos dois.

Cada escrita usa um `idempotency_key` novo. Nao marque a tarefa como Concluida e nao mova entre Backlog e Pronta. Se a API recusar a lease, pare e informe.
