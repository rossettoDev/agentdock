# US-29/T01 — Matriz imagem, rota, dados e lacunas

Data: 2026-10-20. Inspeção dos cinco arquivos reais em `docs/screens/` e do código de `ab_ui` e do tema `agent_board`. Nenhum nome de arquivo foi inventado. Esta matriz não implementa tela.

O inventário de `docs/screens/README.md` (US-02/T02, conferido em US-27/T02) continua válido como registro do MVP. O dashboard deixa de ser “não implementar” apenas como planejamento do M9 (US-35). A rota ainda não existe.

## Arquivos inspecionados

| Arquivo | Tela |
| --- | --- |
| `Painel AI Board em modo escuro.png` | Dashboard |
| `Quadro Kanban Drupal em modo escuro.png` | Kanban |
| `Painel de Tarefa Drupal em Andamento.png` | Detalhe da tarefa |
| `Painel de Agentes IA em Modo Escuro.png` | Central de agentes |
| `Painel de Conversas com Agentes de IA.png` | Central de mensagens |

## Shell presente nas cinco imagens

Tema: `web/themes/custom/agent_board/templates/page.html.twig`. Navegação montada em `AbUiHooks::shellNav()` e `shellBoards()`. Workspace vem da sessão (`WorkspaceContext`); o header liga para `/workspaces`, onde `WorkspaceSelectForm` troca a seleção. Não há `?workspace=`.

| Elemento na imagem | Rota atual | Fonte | Ação | Disponibilidade |
| --- | --- | --- | --- | --- |
| Marca “AI Board” | front | `site_name`, fallback AgentDock | link para a front | visual; nome de produto permanece AgentDock |
| Dashboard | — | — | — | ausente; US-35 cria read-model, não há rota |
| Meus Boards | `ab_ui.boards` `/boards` | `BoardRepository::accessibleBoards` | listar e abrir | existente |
| Tarefas (item global) | — | tarefas só por board | — | ausente; tarefas reais em `/boards/{uuid}/tasks` |
| Conversas | `ab_ui.conversations` `/conversations` | `MessageService::listThreads` | listar threads do workspace | existente |
| Agentes | `ab_ui.agents` `/agents` | `AgentRepository::accessibleAgents` | listar | existente |
| Relatórios | — | — | — | ilustrativo; fora do M9 (§4 do backlog visual) |
| Lista de boards na sidebar e “Novo board” | `ab_ui.board_tasks`, `ab_ui.board_add` | boards não arquivados do workspace; `MANAGE_BOARDS` | abrir kanban; criar | existente |
| Busca global (⌘K) | — | — | — | ilustrativo nesta fase; US-30/T03 só liga busca se já houver consulta |
| Sino | `ab_ui.notifications` `/notifications` | `NotificationService::listFor` (menções, `read_at`) | marcar lida | parcial; rota existe, header não tem sino nem contagem |
| Ajuda “?” | — | — | — | ilustrativo |
| Conta (nome no header) | sessão | `currentUser->getDisplayName()` | nenhuma | parcial; sem menu de conta |
| Seletor de workspace | `/workspaces` | memberships da sessão | `WorkspaceSelectForm` | existente; não é o dropdown da imagem |

Não há componentes SDC. `ab-card` e `ab-button` são classes nos templates. Libraries: `ab_ui/kanban`, `ab_ui/sync`, CSS do tema.

## 1. Dashboard — `Painel AI Board em modo escuro.png`

Rota atual: nenhuma. US-35 define o read-model. Nada abaixo está na UI.

| Elemento na imagem | Fonte possível hoje | Ação | Disponibilidade |
| --- | --- | --- | --- |
| Saudação e data | usuário e relógio | — | visual, quando a rota existir |
| “Nova tarefa” | `ab_ui.task_add` exige board | criar tarefa num board | parcial; o botão da imagem não escolhe board |
| Tarefas hoje | `TaskDayWindow` + `due_at` + timezone do workspace | filtro Hoje já em `/boards/{uuid}/tasks?today=1` | dado por board; agregação do workspace é US-35/T01 |
| Em andamento | `ab_task.status = in_progress` | abrir kanban | contagem ainda não agregada |
| Precisam de atenção | `in_review`, `blocked` (dependências), `waiting_input` (input request aberto) | revisar, responder | sem contrato único de KPI; US-29/T02 fecha o que entra no número |
| Concluídas | `ab_task.status = done` | abrir tarefa | contagem ainda não agregada |
| Variação percentual (+12%, −20%) | — | — | ilustrativo; não há série histórica |
| Meus Boards (tarefas / em andamento / concluídas) | `ab_board` + contagem de `ab_task` no mesmo workspace | `/boards/{uuid}` e kanban | dados existem; card agregado não |
| Agentes ativos com barra % | `ab_agent.enabled`, execução `running`/`waiting_input`, `heartbeat_at` | `/agents/{uuid}` | parcial; “ativo” não é “online”; percentual não está persistido |
| Tarefas por status (donut, bloqueado e cancelado como fatias) | status de tarefa; `blocked` é condição | — | parcial; não desenhar `blocked` como coluna |
| Atividade dos agentes | eventos `execution.progressed`, `message.created`, notas em `ab_execution_progress` | — | parcial; feed ainda não montado |
| Tarefas que precisam de atenção | prioridade, `human_owner`, prazo, status | `/tasks/{uuid}` | parcial; sem “há 1 hora” agregado |
| Últimas conversas | `ab_thread` / `ab_message` | `/conversations/{uuid}` | parcial; `ThreadSummary` tem excerpt, não data nem não lidas |
| Meus próximos eventos (reunião, planejamento) | — | — | ilustrativo; calendário fica fora do M9 |

## 2. Kanban — `Quadro Kanban Drupal em modo escuro.png`

Rota: `ab_ui.board_tasks` `/boards/{uuid}/tasks`. Controller `TaskController::listing`. Template `ab-ui-task-list.html.twig`. Dados: `TaskRepository::accessibleTasks`. Polling: `ab_ui/kanban` + snapshot em `/api/agent-board/v1/workspaces/{workspace}/boards/{board}/snapshot`.

| Elemento na imagem | Fonte | Ação | Disponibilidade |
| --- | --- | --- | --- |
| Título e descrição do board | `ab_board.name`, `description` | a view `/boards/{uuid}` mostra os dois; o kanban mostra o nome | parcial |
| Avatares de membros e “+3” | `ab_membership` | gestão em `/workspaces/{uuid}/members` | dado existe; o kanban não lista membros |
| Engrenagem | — | arquivar em `/boards/{uuid}` se `MANAGE_BOARDS` | sem tela de configuração do fluxo |
| Nova tarefa | `TaskCreateForm` | `/boards/{uuid}/tasks/add` se `WRITE` | existente |
| Abas Kanban, Lista, Calendário, Timeline, Arquivos | só o quadro de colunas | — | Calendário, Timeline e Arquivos são ilustrativos (§4). Lista só entra se houver listagem funcional (US-31/T01) |
| Filtros Todos, Prioridade, Tipo, Agente/Humano, Projeto, Tags, Meus filtros | — | — | ausente. Tipo, projeto, tags e “meus filtros” são ilustrativos |
| Hoje e Canceladas | `?today=1` (`TaskDayWindow`), `?cancelled=1` | links no quadro | existente; a imagem não mostra esses dois controles |
| Colunas A fazer, Em andamento, Em revisão, Concluído | status `backlog`, `ready`, `in_progress`, `in_review`, `done` | cinco colunas, rótulos Backlog, Pronta, Em andamento, Em revisão, Concluída | parcial; não fundir `backlog` com `ready`. Cancelada não é coluna |
| Contagem no título da coluna | `count` dos cards já filtrados | — | existente |
| Card: título, prioridade, prazo, responsável | `title`, `priority`, `due_at` no timezone, `human_owner` | abrir `/tasks/{uuid}` | existente |
| Agente no card | `assigned_agent` | — | parcial; o template só diz “Agente atribuído”, sem nome |
| Bloqueada | `TaskDependencyManager::isBlocked` | — | existente como texto. `waiting_input` não aparece no card |
| Comentários, anexos, checklist e barra % no card | — | — | ilustrativo; progresso persistido é nota de texto, não percentual |
| Tags (Infraestrutura, Design, Tema) | — | — | ilustrativo |
| Arrastar e soltar | `TaskTransitionPolicy` + `task-kanban.js` | transição com versão | existente; não coloca a tarefa em andamento, revisão ou concluída por soltura arbitrária |
| Mover (teclado) | `TaskTransitionForm` | menu no card | existente; a imagem não mostra o controle |
| Ordenar | `TaskReorderForm` | sobe/desce com versão | existente |
| Painel lateral Detalhes / Discussão / Atividade | detalhe é página `/tasks/{uuid}` | — | ausente como painel; US-32/T01 |

## 3. Detalhe — `Painel de Tarefa Drupal em Andamento.png`

Rota: `ab_ui.task_view` `/tasks/{uuid}`. Controller `TaskController::view`. Template `ab-ui-task-view.html.twig`. UUID fora do workspace responde 404. A imagem abre um modal sobre o kanban; a rota direta precisa continuar funcionando (US-32/T01).

| Elemento na imagem | Fonte | Ação | Disponibilidade |
| --- | --- | --- | --- |
| Título | `ab_task.title` | — | existente |
| Código #DRU-123 | UUID público | — | parcial; não há código curto |
| Status e prioridade editáveis no topo | `status`, `priority`, `version` | `TaskTransitionForm`, `TaskEditForm` | parcial; transição segue a policy, não é um select livre |
| Descrição Markdown | `description` via `TaskMarkdown` | — | existente |
| Tags e tipo (Desenvolvimento) | — | — | ilustrativo |
| Responsável | `human_owner` | — | existente (nome da conta) |
| Co-responsáveis | — | — | ilustrativo; um `human_owner` |
| Projeto | board da tarefa | link de volta ao kanban | parcial |
| Data de início | `created` | — | não exibida |
| Prazo | `due_at` + timezone | — | existente (`d/m/Y`) |
| Barra de progresso e etapas | notas em `ab_execution_progress` | — | ilustrativo como percentual; notas são texto |
| Abas Descrição, Checklist, Conversas, Logs, Arquivos | descrição, critérios, dependências, tentativas, dúvidas, link de conversa | — | parcial. Checklist interativo e Arquivos ficam de fora (US-32/T04). Conversas: `/tasks/{uuid}/conversation`. Logs: tentativas e notas |
| Critérios de aceite como checklist marcado | `acceptance_criteria` (texto) | — | parcial; mostrar como texto, não como checklist |
| Site de referência / links | `ab_execution_link` (`url`, `label`) | link externo, sem fetch | existente na tentativa, não como campo da tarefa |
| Editar | `TaskEditForm` | `/tasks/{uuid}/edit` se `WRITE` | existente |
| Mover | `TaskTransitionForm` | policy + versão | existente |
| Marcar como concluída | `done` só por aprovação humana | `TaskReviewForm` quando `in_review` e `REVIEW` | a ação da imagem não mapeia para um botão que grava `done` |
| Duplicar, criar subtarefa, sprint, excluir | — | — | ilustrativo. Cancelar passa por policy |
| Criado por / criado em / atualizado em | `created`, `changed`; autor não é campo da tarefa | — | parcial |
| Agentes envolvidos (vários papéis) | `assigned_agent`; execução tem um agente | — | parcial; não há lista de papéis |
| Dependências | `ab_task_dependency` | `TaskDependencyForm` | existente; a imagem não mostra o bloco |
| Execução, tentativa, resumo, falha | `ab_execution` | aprovar, rejeitar, cancelar execução conforme policy | existente na página, sem painel “agente em execução” |
| Dúvidas | `ab_input_request` | `InputAnswerForm` se humano com escrita | existente |

## 4. Agentes — `Painel de Agentes IA em Modo Escuro.png`

Rotas: `ab_ui.agents` `/agents` e `ab_ui.agent_view` `/agents/{uuid}`. Templates `ab-ui-agent-list.html.twig` e `ab-ui-agent-view.html.twig`. A imagem é lista e painel na mesma tela; hoje são duas páginas.

| Elemento na imagem | Fonte | Ação | Disponibilidade |
| --- | --- | --- | --- |
| Lista nome, descrição, capabilities | `ab_agent` | abrir detalhe | parcial; a lista mostra nome, habilitado/desabilitado e `concurrency_limit` |
| Novo agente | `AgentCreateForm` | `/agents/add` se `MANAGE_AGENTS` | existente no detalhe/lista, não no painel |
| KPIs ativos, em execução, aguardando, erro, desconectado | `enabled`; execução `running`, `waiting_input`, `failed`, `interrupted` | — | parcial; “desconectado” e “online” não têm contrato. US-29/T02 define o que pode virar número |
| Filtros e busca | — | — | ausente (US-33/T01), sempre no workspace da sessão |
| Colunas tipo, progresso %, última atividade | capabilities é texto; não há percentual | — | tipo e % são ilustrativos. Último sinal: `heartbeat_at` de execução `running`/`waiting_input`, já no detalhe |
| Tarefa atual | execução ativa ligada à tarefa | — | dado existe; a lista não mostra |
| Status Trabalhando / Em execução / Aguardando / Erro / Desconectado | `enabled`; status da execução | — | não copiar esses rótulos. Habilitado é administrativo. Trabalhando exige execução elegível |
| Visão geral, Tarefas, Permissões, Configurações | uma página só | editar, grants, token | parcial; sem abas |
| Modelo e versão (GPT-4o, 1.2.0) | — | — | ilustrativo; métricas de LLM fora do M9 |
| Grants | `ab_agent_board_grant` | `AgentGrantForm` | existente; permissões da imagem (deploy, gerenciar usuários) não são o modelo |
| Credencial | `ab_agent_token` (id público, hash, revogação) | `AgentTokenForm`; segredo uma vez | existente. Não renderizar bearer na listagem |
| Uso de recursos e donuts | — | — | ilustrativo |
| Tags | — | capabilities em texto | ilustrativo como tags |

## 5. Mensagens — `Painel de Conversas com Agentes de IA.png`

Rotas: `/conversations`, `/conversations/{uuid}`, `/boards/{uuid}/conversation`, `/tasks/{uuid}/conversation`. Controller `ConversationController`. Templates `ab-ui-conversation-list.html.twig` e `ab-ui-conversation.html.twig`. Threads: um geral por board e um por tarefa. Sem DM.

| Elemento na imagem | Fonte | Ação | Disponibilidade |
| --- | --- | --- | --- |
| Lista com título | `ThreadSummary`: rótulo da tarefa ou do board | abrir thread | existente |
| Preview | `excerpt` do último corpo permitido | — | existente |
| Data e não lidas | `ab_message.created` existe; projeção da lista não traz data nem contagem | notificações de menção em `/notifications` (`read_at`) | parcial; badge de não lidas só se a projeção passar a expô-lo (US-34/T01) |
| Filtros Todas, Não lidas, Grupos | — | — | Grupos e DM são ilustrativos |
| Nova conversa | thread nasce com o board ou a tarefa | — | ilustrativo como botão solto |
| Busca na lista | — | — | ausente |
| Autor human / agent / system | `actor_type`, `actor_id` resolvido no workspace | — | existente; template não separa o tipo visualmente além do nome |
| Kind e resposta | `kind`, `reply_to` | — | existente |
| Horário da mensagem | `StoredMessage.created` | — | dado existe; template não mostra |
| Composer | `MessageComposeForm` | `MessageService` se `WRITE`; 16 KiB; autor da sessão | existente. Viewer não envia |
| Menções | `ab_message_mention` | notificação ao humano | existente no serviço; o composer da UI não desenha o menu @ da imagem |
| Paginação | cursor `before` | “Mensagens anteriores” | existente |
| Abas Arquivos, Links, Reuniões | — | — | ilustrativo |
| Imagem e anexo no balão | — | — | ilustrativo (§4) |
| Checklist dentro da mensagem | — | — | ilustrativo |
| Barra “progresso total” | — | — | ilustrativo |
| Painel da tarefa (status, prioridade, responsável, prazo) | tarefa do thread | link “Tarefa” para `/tasks/{uuid}` | parcial; painel rico é US-34/T03. Thread de board aponta para o kanban, sem tarefa fictícia |
| Participantes com papéis Owner/Revisor | membros e agente da tarefa | — | ilustrativo como lista editável |
| “Online” no rodapé | — | — | ilustrativo |
| Arquivos compartilhados | — | — | ilustrativo |
| Links úteis | `ab_execution_link` da tarefa | — | parcial; não estão no painel da conversa |
| Atividade recente | eventos do workspace | polling `ab_ui/sync` | parcial; o painel da imagem não existe |

## Fora do M9 em todas as telas

Anexos binários, checklist estruturado, DMs, grupos, reuniões, calendário, timeline, colunas personalizáveis, tags, tipo de trabalho, subtarefa, duplicar tarefa, custo/modelo/uso de LLM, percentual sem campo persistido, status “online”/“desconectado”, busca global e relatórios. Não criar ação decorativa para nenhum desses.

## Próxima task

US-29/T02 audita quais contagens e textos (“online”, percentual, não lidas, atenção) têm contrato de dados antes de qualquer KPI entrar no tema.
