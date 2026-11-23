---
name: agentdock-agente
description: >-
  Opera o quadro AgentDock pelo servidor MCP agentdock (listar quadros,
  assumir tarefa pronta, consultar memoria antes do claim, registrar
  progresso, entregar resultado e gravar o rascunho do que mudou) e
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
4. Crie o token com: Ler boards, Ler tarefas, Escrever tarefas, Assumir tarefas, Escrever execucao, Ler mensagens, Escrever mensagens, Ler memoria e Escrever memoria. Copie o segredo uma vez. Sem os dois de memoria, o agente para antes do claim.
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
3. `get_task` e leia titulo, descricao, criterios e `related_memories`.
4. Antes de assumir, busque o contexto no quadro. So entao `claim_task`.
5. Durante a lease, registre progresso e entregue. Nao chame memoria nesse intervalo.
6. Depois de `submit_result`, grave um rascunho com o que mudou.
7. Se nao der para concluir, `fail_execution` ou `release_task`, um dos dois. Sem rascunho nesse caso.

Cada escrita usa um `idempotency_key` novo. Nao marque a tarefa como Concluida e nao mova entre Backlog e Pronta. Se a API recusar a lease, pare e informe.

### Antes de assumir

Depois de `get_task` e antes de `claim_task`:

1. Leia `related_memories` da tarefa. Sao no maximo 5 e so aparecem com `memory:read` no token.
2. `search_memory` com o `board_id` do quadro e poucas palavras distintivas do titulo. A busca e lexical, sem radical: "renovacao" nao acha "renovar".
3. `get_memory` em cada hit util para a tarefa.
4. `claim_task` com um `idempotency_key` UUID novo. Guarde `execution_id` e `lease_token` so para as chamadas seguintes. A lease dura cerca de 120 segundos e este adaptador nao renova. Ler memoria nao cria execucao.

Busca vazia: siga para o claim. Resposta 403 em `search_memory` ou `get_memory`: pare e peca os escopos Ler memoria e Escrever memoria no token, alem do grant Ler e Escrever no quadro. Nao assuma a tarefa sem contexto por falta de permissao.

### Durante

`report_progress` para notas curtas. `post_message` para registrar no fio. `request_human_input` quando faltar decisao humana; pare e espere. `submit_result` com resumo e links. Quem aprova e um humano. Nao chame `search_memory`, `get_memory` nem `create_memory_draft` enquanto a lease estiver ativa.

### Depois de entregar

Depois de `submit_result` bem-sucedido, `create_memory_draft` com:

- `idempotency_key` UUID novo
- `board_id` do quadro
- `task_id` e `source_id` iguais ao UUID da tarefa
- `source_type` `task`
- `kind` `lesson`, ou `decision` quando a entrega for uma decisao
- `title` e `content_markdown` com o que mudou

Nao inclua segredo, bearer, lease nem token no texto. O agente nao publica, nao arquiva e nao altera memoria ja publicada. Informe o UUID do rascunho para um humano publicar em `/memory`. Enquanto estiver rascunho, a busca do proximo agente volta vazia, inclusive para o autor. Resposta 403: pare e peca o escopo Escrever memoria.
