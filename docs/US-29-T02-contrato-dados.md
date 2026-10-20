# US-29/T02 — Contrato dos dados que a interface pode mostrar

Data: 2026-10-20. Conferência dos campos e serviços já persistidos. Nenhum KPI foi implementado nesta task. Um número ou rótulo só entra na UI se estiver na coluna “Pode mostrar”.

Escopo de toda contagem futura: workspace selecionado na sessão, membership com leitura. UUID de outro workspace continua 404. Boards arquivados entram em `BoardRepository::accessibleBoards` e ficam de fora da sidebar. Contagem de tarefa do dashboard usa só boards não arquivados, salvo rótulo que diga o contrário. Não existe serviço agregado; US-35/T01 é quem cria o read-model em cima destas regras.

## 1. Status de tarefa

Campo `ab_task.status`: `backlog`, `ready`, `in_progress`, `in_review`, `done`, `cancelled`.

| Número | Pode mostrar | Não mostrar |
| --- | --- | --- |
| Quantidade por status, no board ou no workspace | Rótulo do status e o inteiro | Fatia “bloqueado” no mesmo gráfico. `blocked` não é status |
| Contagem da coluna do kanban | Cards já filtrados daquela coluna | Somar `backlog` com `ready` sob “A fazer” |

`blocked` é predicado de `TaskDependencyManager::isBlocked`: existe predecessora no mesmo board com status diferente de `done`. Alternativa: badge “Bloqueada” no card. Sem percentual de dependências.

`waiting_input` também não é coluna. São dois fatos distintos:

- `ab_execution.status = waiting_input`: a execução pediu informação e a reserva continua.
- `ab_input_request.status = open`: há pergunta sem resposta (`open` ou `answered`).

Alternativa: badge “Aguardando resposta” só quando existir pedido `open`. Não usar a mesma palavra para os dois fatos.

## 2. Hoje, prazo e prioridade

`due_at` é timestamp UTC. O dia é o timezone do workspace (`America/Sao_Paulo` se o valor estiver vazio ou inválido na formatação já usada). Prioridade: `low`, `normal`, `high`, `urgent`.

`TaskDayWindow::includes` é o único contrato do filtro Hoje. A tarefa entra se o status é `in_progress`, ou se tem prazo até o fim do dia local e o status não é `cancelled`. Sem prazo e fora de `in_progress`, fica de fora. A chamada não grava nem move tarefa.

| Número | Pode mostrar | Não mostrar |
| --- | --- | --- |
| “Hoje” | Inteiro das tarefas que `includes` aceita, com o mesmo critério | “Tarefas hoje” com outra fórmula, e a variação “+12%” |
| Atrasadas | `due_at` anterior ao início do dia local, status diferente de `done` e `cancelled` | Relógio relativo inventado (“há 1 hora”) sem `created` ou `changed` |

Não há série histórica. Seta de tendência fica de fora.

## 3. Progresso e entrega

Não existe coluna de percentual em tarefa, execução ou nota.

| Dado | Onde | Pode mostrar | Não mostrar |
| --- | --- | --- | --- |
| Nota | `ab_execution_progress.body`, até 500 caracteres, `sequence`, `created` | Texto da nota e o horário | Barra ou “70%” derivado do texto |
| Tentativa | `ab_execution.attempt` | “Tentativa N” | Fração de um total que não está gravado |
| Status da execução | `running`, `waiting_input`, `submitted`, `failed`, `interrupted`, `cancelled` | O rótulo do status | “Trabalhando” como presença |
| Entrega | `result_summary` e `ab_execution_link` (`url` http/https, `label`) | Resumo e links | Buscar a URL no servidor |
| Falha | `failure_code`, até 64 caracteres | O código | Classe genérica “Erro” sem o registro |

`active_execution` é o id da execução ativa da tarefa. Uma tarefa não tem várias execuções ativas.

## 4. Agente e último sinal

`ab_agent.enabled` é administrativo. O detalhe já usa “Habilitado” ou “Desabilitado”. `concurrency_limit` é o teto de execuções `running` ou `waiting_input` daquele agente.

`AgentController::signalLabel` é o texto permitido sobre sinal:

- nenhuma execução `running` ou `waiting_input`: “Não há sinal de execução.”
- execução nesses status e `heartbeat_at` nulo ou zero: “Execução em andamento.”
- `heartbeat_at` positivo: “Último sinal:” com data e hora no timezone do workspace.

Heartbeat nulo não vira “online”, “desconectado” nem “trabalhando”. Conexão MCP aberta não é sinal. `last_used_at` do token é uso da credencial, não presença.

Contagens permitidas na central de agentes, sempre no workspace:

- habilitados e desabilitados;
- agentes com ao menos uma execução `running`;
- agentes com execução `waiting_input` e nenhuma `running`;
- execuções `failed` ou `interrupted` que ainda não tenham outra execução ativa na mesma tarefa.

“Aguardando tarefa” só para agente habilitado sem execução `running` ou `waiting_input`. Várias execuções aparecem se o limite permitir; a lista não inventa uma barra por tarefa.

Modelo, versão, custo e uso de LLM não têm campo.

## 5. Mensagens, não lidas e atividade

Mensagem: `actor_type` (`human`, `agent`, `system`), `kind` (`comment`, `progress`, `question`, `answer`, `result`, `system`), `body`, `reply_to`, `created`. Não há cursor de leitura por thread. `ThreadSummary` guarda uuid, board, tarefa e excerpt de 80 caracteres. A consulta lê `created` da última mensagem e não o repassa ao valor. Data na lista só depois dessa projeção passar a expor `created`. Até lá, omitir “há 5 min” e o badge de não lidas.

Notificação é projeção de menção: `NotificationService::project` no envio, uma linha por evento e pessoa. `NotificationItem` tem uuid, lida (`read_at`) e nome do workspace. Não traz corpo, tipo nem `created`, embora a tabela tenha `created`. O sino pode contar linhas não lidas do workspace da sessão. O rótulo continua “Menção” / “Não lida”. Não é contador de conversa.

Eventos duráveis (`task.created`, `task.updated`, `task.claimed`, `execution.progressed`, `execution.interrupted`, `execution.submitted`, `task.approved`, `task.rejected`, `message.created`, `input.requested`, `input.answered`, `membership.changed`) podem alimentar “atividade recente” com tipo, recurso e horário. Payload mínimo, sem texto privado. Não há entidade de reunião ou compromisso.

## 6. Alternativa visual por elemento da imagem

| Elemento da referência | No lugar |
| --- | --- |
| Barra de progresso e donut com percentual | Status da execução, número da tentativa e nota em texto |
| “Online”, “Desconectado”, “Trabalhando” | As três frases da seção 4, mais habilitado ou desabilitado |
| “+12%” e gráfico de 7 dias | Omitir |
| “Precisam de atenção” como KPI único | Três inteiros separados, se US-35 os consultar: em revisão, pedidos `open`, bloqueadas |
| Não lidas na thread | Sino de menções não lidas do workspace |
| Checklist “3/5” | Critérios como texto |
| Participante “Online” | Nome e tipo `human` ou `agent` |

## Próxima task

US-29/T03 define tokens e componentes em cima deste contrato. Nenhum componente novo exibe percentual nem “online”.
