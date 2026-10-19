# Board de Agentes — Backlog para desenvolvimento no Cursor

Versão 1.0 · 15/10/2026 · Todos os itens propostos, ainda não executados.

## Como usar

Salvar no repositório como `docs/backlog.md`. Fonte de regras: `docs/architecture.md`. Referência visual: arquivos reais em `docs/screens/`. Este backlog foi derivado da arquitetura 1.0; as imagens locais não foram inspecionadas nesta elaboração. US-02/T02 fará o inventário e registrará divergências. Nome comercial permanece em aberto; manter identificadores técnicos `agent_board` e `ab_*`.

Execute uma task por interação no Cursor. Cada task tem objetivo, entrega e aceite próprios; dependências internas seguem a ordem das tasks da história, salvo indicação explícita. Dependências entre histórias estão no cabeçalho. Se uma task exceder uma alteração revisável, divida em subtasks mantendo o ID de origem. Não avançar sobre bloqueios nem marcar aceites sem evidência.

Os marcos preservam M0–M8 da arquitetura. A fundação de eventos vem antes das mutações de boards; consumo e sincronização entram em M6. M3 implementa serviços de execução e M5 expõe HTTP; um serviço pronto não implica endpoint pronto. Toda mutação de domínio a partir de M2 registra evento/outbox na mesma transação.

## Índice

| Marco | História | Entrega |
| --- | --- | --- |
| M0 | US-01 | Preparar ambiente reproduzível |
| M0 | US-02 | Criar estrutura e referências de UI |
| M0 | US-03 | Preparar qualidade e acompanhamento |
| M1 | US-04 | Persistir workspaces e membros |
| M1 | US-05 | Aplicar autorização por workspace |
| M1 | US-06 | Gerenciar workspace pela interface |
| M2 | US-07 | Criar fundação transacional de eventos |
| M2 | US-08 | Gerenciar boards |
| M2 | US-09 | Cadastrar e editar tarefas |
| M2 | US-10 | Controlar estados e dependências |
| M2 | US-11 | Exibir e operar Kanban |
| M3 | US-12 | Cadastrar agentes e grants |
| M3 | US-13 | Autenticar agentes |
| M3 | US-14 | Assumir tarefa com exclusividade |
| M3 | US-15 | Manter e recuperar execução |
| M3 | US-16 | Entregar e revisar resultado |
| M4 | US-17 | Conversar com contexto |
| M4 | US-18 | Pedir e responder informação |
| M4 | US-19 | Entregar menções e notificações |
| M5 | US-20 | Definir contrato HTTP |
| M5 | US-21 | Expor leituras e edição de tarefas |
| M5 | US-22 | Expor execução e conversas |
| M5 | US-23 | Validar runner de referência |
| M6 | US-24 | Sincronizar por eventos |
| M6 | US-25 | Atualizar interface automaticamente |
| M6 | US-26 | Operar worker e manutenção |
| M7 | US-27 | Validar piloto e produção |
| M8 | US-28 | Conectar por MCP após o MVP |

## Definition of Done comum

- Alteração limitada ao escopo da task, sem sobrescrever trabalho existente.
- Regras da arquitetura, autorização e isolamento preservados; todas as referências validadas no workspace.
- Schema novo tem instalação e caminho de update quando houver instalação existente.
- Testes proporcionais às regras alteradas; concorrência usa conexões/processos independentes.
- Validações executadas reportadas com resultado real; bloqueios e verificações não executadas explícitos.
- Documentação e `docs/implementation-status.md` atualizados com IDs e evidências.
- Mudança de decisão técnica registrada em ADR; mudança de escopo não é inferida de imagem.

## Convenções complementares

O fluxo do MVP prioriza tarefas executadas por agentes e acompanhamento humano. Execução manual completa por humanos precisa de decisão posterior; não criar uma execução falsa de agente para permitir arrastar um card. Um revisor agente pode receber tarefa própria dependente da implementação, mas a aprovação final da entrega permanece humana.

O read-model de eventos não substitui auditoria durável. Criar registro de auditoria para ações críticas (credenciais, membros, execução e aprovação) ou política documentada de preservação, antes de ativar expurgo no piloto. Tokens e corpos sensíveis nunca pertencem ao log.

Para tarefas com lease ativa, remoção de membro, grant, desativação de agente ou revogação de token deve negar a próxima mutação; a lease pode ser encerrada pelo serviço administrativo ou recuperada por expiração. Nunca manter acesso só porque uma execução começou antes da revogação.

## M0 · US-01 — Preparar ambiente reproduzível

Como desenvolvedor, quero instalar o projeto sem ajustes manuais ocultos.

**Dependências:** —. **Status:** done.

### US-01/T01 — Inspecionar repositório e registrar decisões

- [x] **Entrega:** Ler AGENTS.md, arquitetura e estrutura existente; registrar versões compatíveis em ADR, sem recriar projeto existente.
- **Aceite:** ADR identifica stack resolvida, comandos e decisões pendentes.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-01/T02 — Configurar Composer e Lando

- [x] **Entrega:** Preparar Drupal, PostgreSQL, PHP, Drush, lockfile e exemplo de variáveis sem segredos.
- **Aceite:** Instalação sobe e Drush conecta ao banco.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-01/T03 — Documentar instalação e configuração

- [x] **Entrega:** Documentar instalação, export/import de config e comandos locais.
- **Aceite:** Checkout limpo reproduz a instalação pelas instruções.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M0 · US-02 — Criar estrutura e referências de UI

Como desenvolvedor, quero uma base consistente para os módulos e telas.

**Dependências:** US-01. **Status:** done.

### US-02/T01 — Criar módulos e tema vazios

- [x] **Entrega:** Criar ab_core, ab_board, ab_agent, ab_communication, ab_event, ab_api, ab_ui e tema agent_board com dependências válidas.
- **Aceite:** Módulos habilitam sem ciclos e tema renderiza.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-02/T02 — Inventariar docs/screens

- [x] **Entrega:** Listar arquivos reais e criar docs/screens/README.md com tela, finalidade e escopo; registrar referência no documento de arquitetura.
- **Aceite:** Nenhum nome de imagem é inventado; divergências com MVP ficam explícitas.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-02/T03 — Criar shell visual

- [x] **Entrega:** Implementar navegação, seletor de workspace e estrutura responsiva conforme telas disponíveis.
- **Aceite:** Componentes compartilhados evitam duplicação; navegação funciona por teclado.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M0 · US-03 — Preparar qualidade e acompanhamento

Como desenvolvedor, quero validar cada incremento.

**Dependências:** US-01. **Status:** done.

### US-03/T01 — Configurar ferramentas e CI

- [x] **Entrega:** Composer validate, padrões Drupal, análise estática e suíte PHPUnit mínima funcional.
- **Aceite:** Pipeline executa comandos reais e falha em erro demonstrável.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-03/T02 — Criar registro de implementação

- [x] **Entrega:** Criar docs/implementation-status.md por ID com status, evidências, bloqueios e próximo passo.
- **Aceite:** Todos os itens iniciam como todo; nada é declarado implementado por existir no backlog.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M1 · US-04 — Persistir workspaces e membros

Como humano, quero uma área própria de trabalho.

**Dependências:** US-02, US-03. **Status:** done.

### US-04/T01 — Criar entidade workspace

- [x] **Entrega:** Base fields UUID, nome, owner, timezone, status e timestamps; schema e constraints.
- **Aceite:** Timezone inválido e owner ausente são rejeitados.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-04/T02 — Criar entidade membership

- [x] **Entrega:** Associação usuário/workspace com role e status; unicidade no banco.
- **Aceite:** Mesmo usuário não tem duas associações no mesmo workspace.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-04/T03 — Criar workspace pessoal

- [x] **Entrega:** Serviço idempotente para provisionar workspace e membership owner.
- **Aceite:** Repetição não duplica workspace e falha parcial não deixa associação órfã.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M1 · US-05 — Aplicar autorização por workspace

Como owner, quero que meus dados fiquem isolados.

**Dependências:** US-04. **Status:** done.

### US-05/T01 — Implementar ActorContext humano e policy

- [x] **Entrega:** Criar contexto autenticado e matriz owner/admin/member/viewer, sem usar roles globais para tenancy.
- **Aceite:** Viewer não escreve; member não administra membros.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-05/T02 — Aplicar escopo a consultas e cache

- [x] **Entrega:** Access handlers, checks, repositórios com escopo e cache contexts/tags; referências cruzadas inválidas.
- **Aceite:** Dois usuários não veem dados alheios em listagens, contagens e cache.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-05/T03 — Testar isolamento básico

- [x] **Entrega:** Fixtures de dois workspaces e acesso por UUID conhecido.
- **Aceite:** Leitura/escrita cruzada falham; acesso autorizado continua funcionando.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M1 · US-06 — Gerenciar workspace pela interface

Como owner, quero administrar colaboradores existentes.

**Dependências:** US-05. **Status:** done.

### US-06/T01 — Criar listagem e formulário de workspace

- [x] **Entrega:** Listar apenas acessíveis, criar workspace e trocar contexto.
- **Aceite:** Workspace selecionado não concede acesso por parâmetro de URL.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-06/T02 — Gerenciar memberships e ownership

- [x] **Entrega:** Adicionar usuários cadastrados, alterar papel/remover, transferir ownership; impedir perda do último owner.
- **Aceite:** Admin não promove a owner nem remove owner; transferência é transacional.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-06/T03 — Testar revogação de associação

- [x] **Entrega:** Revogar membro e invalidar cache e sessão de contexto aplicável.
- **Aceite:** Próxima requisição não mantém acesso por cache antigo.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M2 · US-07 — Criar fundação transacional de eventos

Como sistema, quero registrar alterações sem perder consistência.

**Dependências:** US-05. **Status:** done.

### US-07/T01 — Criar schema de eventos e outbox

- [x] **Entrega:** Contador por workspace, event store e outbox com índices; contrato independente das entidades consumidoras.
- **Aceite:** Instalação/update cria índices e unicidades previstos.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-07/T02 — Implementar gravação dentro da transação

- [x] **Entrega:** Reservar sequência por lock de workspace, gravar evento e outbox na transação do serviço chamador; padronizar ordem de locks.
- **Aceite:** Rollback remove alteração/evento/outbox; commits concorrentes preservam ordem.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-07/T03 — Criar testes de rollback e concorrência

- [x] **Entrega:** Usar conexões independentes para ordenação e validar ausência de eventos não confirmados.
- **Aceite:** Nenhum evento de transação revertida aparece no fluxo.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M2 · US-08 — Gerenciar boards

Como membro autorizado, quero organizar projetos em boards.

**Dependências:** US-06, US-07. **Status:** done.

### US-08/T01 — Criar entidade e serviço de board

- [x] **Entrega:** Workspace obrigatório, UUID, nome, descrição, arquivamento e eventos.
- **Aceite:** Referência cruzada rejeitada; arquivamento preserva dados.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-08/T02 — Criar listagem e formulários

- [x] **Entrega:** Consultar screens; aplicar matriz de acesso: owner/admin gerenciam boards, demais leem.
- **Aceite:** Usuário acessa somente boards do workspace permitido.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M2 · US-09 — Cadastrar e editar tarefas

Como membro, quero descrever o trabalho e seu resultado esperado.

**Dependências:** US-08. **Status:** done.

### US-09/T01 — Criar entidade tarefa

- [x] **Entrega:** Campos da arquitetura, versão, prioridade, prazo, posição e referências; agentes/execuções serão conectados em US-14.
- **Aceite:** Schema instala sem exigir classe de entidade ainda inexistente; plano de update documentado.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-09/T02 — Implementar criação e edição por serviço

- [x] **Entrega:** Whitelist de campos, autor autenticado, eventos e incremento de versão.
- **Aceite:** Workspace/status/execução não podem ser alterados pelo editor genérico.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-09/T03 — Criar formulário e detalhe básico

- [x] **Entrega:** Título, descrição, critérios, prioridade, responsável humano e prazo; Markdown sanitizado.
- **Aceite:** Dados persistem e script/HTML arbitrário não executa.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M2 · US-10 — Controlar estados e dependências

Como humano, quero liberar tarefas válidas para execução.

**Dependências:** US-09. **Status:** done.

### US-10/T01 — Implementar transições humanas iniciais

- [x] **Entrega:** Backlog/ready/cancelamento/reabertura conforme policy; in_progress e in_review ficam reservados ao ciclo de execução.
- **Aceite:** Não é possível simular execução apenas arrastando card.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-10/T02 — Implementar dependências sem ciclos

- [x] **Entrega:** Mesmo board/workspace, par único, detecção de ciclos e bloqueio derivado.
- **Aceite:** Autodependência e ciclo indireto são rejeitados.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-10/T03 — Adicionar versão otimista

- [x] **Entrega:** Serviços recebem versão esperada; Forms incluem versão e API usará If-Match.
- **Aceite:** Segundo editor recebe conflito sem sobrescrever a primeira alteração.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M2 · US-11 — Exibir e operar Kanban

Como humano, quero acompanhar o fluxo visual.

**Dependências:** US-10. **Status:** done.

### US-11/T01 — Renderizar colunas e cards

- [x] **Entrega:** Consultar tela Kanban; exibir estados fixos, prioridade, prazo e bloqueios.
- **Aceite:** Cards correspondem ao estado persistido; cancelled tem filtro próprio se não houver coluna na referência.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-11/T02 — Implementar movimento acessível

- [x] **Entrega:** Botão/menu por teclado chama serviço de transição; adicionar drag-and-drop sobre a mesma operação.
- **Aceite:** Conflito reverte movimento visual e informa usuário.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-11/T03 — Implementar ordenação e filtro Hoje

- [x] **Entrega:** Serializar reordenação por board, posições com intervalos; filtro por timezone e execuções em andamento.
- **Aceite:** Reordenação concorrente mantém ordem válida; virada do dia não altera tarefas.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M3 · US-12 — Cadastrar agentes e grants

Como admin, quero delegar acesso limitado a agentes.

**Dependências:** US-10. **Status:** done.

### US-12/T01 — Criar entidade agente

- [x] **Entrega:** Workspace, nome, descrição, capabilities, enabled e concurrency_limit.
- **Aceite:** Limite inválido é rejeitado e agente desabilitado não inicia trabalho.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-12/T02 — Criar grants por board

- [x] **Entrega:** Persistir permissões e validar interseção com workspace.
- **Aceite:** Grant para board externo é rejeitado.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-12/T03 — Criar Central de Agentes inicial

- [x] **Entrega:** Consultar screens; cadastro, ativação e grants; status distingue habilitado de execução/heartbeat.
- **Aceite:** Interface não afirma agente online sem sinal recente verificável.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M3 · US-13 — Autenticar agentes

Como agente, quero acessar a plataforma com identidade própria.

**Dependências:** US-12. **Status:** done.

### US-13/T01 — Implementar ciclo de tokens

- [x] **Entrega:** Gerar secret seguro, armazenar hash, scopes, validade, rotação e revogação.
- **Aceite:** Segredo aparece uma vez e nunca fica em logs ou campos públicos.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-13/T02 — Implementar provider e ActorContext agente

- [x] **Entrega:** Conta técnica restrita e identidade real do agente; aplicar grants/scopes ao acesso.
- **Aceite:** Payload não pode forjar outro agente; query string não autentica.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-13/T03 — Testar autenticação e acesso

- [x] **Entrega:** Expirado, revogado, disabled, scope ausente e board externo.
- **Aceite:** Todos os cenários negam a operação sem expor segredo.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M3 · US-14 — Assumir tarefa com exclusividade

Como agente, quero reservar uma tarefa sem disputa.

**Dependências:** US-07, US-10, US-13. **Status:** done.

### US-14/T01 — Criar execução e lease

- [x] **Entrega:** Entidade execution, tabela lease única por task, generation, token hash e campos de referência da task.
- **Aceite:** Uma única reserva por tarefa é garantida no banco.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-14/T02 — Implementar claim transacional

- [x] **Entrega:** Locks em ordem documentada compatível com contador de eventos; validar estado, dependências, grants, atribuição e concorrência.
- **Aceite:** Claim cria execução, muda tarefa e grava evento/outbox atomicamente.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-14/T03 — Testar disputas reais

- [x] **Entrega:** Dois processos disputam tarefa e duas tarefas disputam concurrency_limit do mesmo agente.
- **Aceite:** Apenas claims permitidos vencem; nenhum registro parcial.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M3 · US-15 — Manter e recuperar execução

Como sistema, quero detectar interrupções de agentes.

**Dependências:** US-14. **Status:** done.

### US-15/T01 — Implementar heartbeat e validação de lease

- [x] **Entrega:** Checar identidade, token, generation, estado e prazo a cada mutação; usar clock injetável.
- **Aceite:** Lease vencida falha mesmo antes do worker.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-15/T02 — Implementar fail/release e expiração

- [x] **Entrega:** Encerrar execução, liberar lease e retornar tarefa a ready com impedimentos preservados; serviço invocável por Drush.
- **Aceite:** Repetir recuperação não duplica transição/evento.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-15/T03 — Testar executor obsoleto

- [x] **Entrega:** Expirar, recuperar e assumir novamente; tentar heartbeat e submit do executor antigo.
- **Aceite:** Executor antigo não altera a tarefa da nova execução.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M3 · US-16 — Entregar e revisar resultado

Como humano, quero aprovar o trabalho executado.

**Dependências:** US-15. **Status:** done.

### US-16/T01 — Implementar progresso e submit

- [x] **Entrega:** Progresso estruturado limitado; resumo e links de resultado; submit encerra lease e leva a in_review.
- **Aceite:** Agente não envia resultado sem lease válida nem aprova a entrega.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-16/T02 — Implementar approve/reject/cancel

- [x] **Entrega:** Rejeição exige motivo e retorna ready; cancelamento encerra reserva; correção abre nova tentativa.
- **Aceite:** Histórico das tentativas é preservado e done não conserva execução ativa.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-16/T03 — Completar detalhe e Central de Agentes

- [x] **Entrega:** Exibir execução, tentativas, resultado e ações humanas conforme screens.
- **Aceite:** Ações permitidas refletem policy; campos de anexos/checklist não implementados ficam fora do fluxo.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M4 · US-17 — Conversar com contexto

Como humano ou agente, quero registrar mensagens de board e tarefa.

**Dependências:** US-16. **Status:** done.

### US-17/T01 — Criar threads e mensagens

- [x] **Entrega:** Thread único por board/tarefa; autor derivado do contexto; reply_to no mesmo thread; limites de body.
- **Aceite:** Retries de criação estrutural não duplicam threads.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-17/T02 — Implementar MessageService

- [x] **Entrega:** Tipos permitidos, Markdown sanitizado, imutabilidade e evento transacional.
- **Aceite:** Agente não cria mensagens system/answer humanas por payload forjado.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-17/T03 — Criar Central de Mensagens

- [x] **Entrega:** Consultar screens; listar conversas autorizadas e abrir contexto da tarefa; composer e paginação.
- **Aceite:** Não apresenta DM privada como recurso funcional do MVP.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M4 · US-18 — Pedir e responder informação

Como agente, quero interromper meu trabalho para esclarecer uma dúvida.

**Dependências:** US-17. **Status:** done.

### US-18/T01 — Criar input request transacional

- [x] **Entrega:** Pergunta + solicitação + estado de espera + evento; manter lease com heartbeat.
- **Aceite:** Várias dúvidas abertas mantêm waiting_input até resolução de todas.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-18/T02 — Implementar resposta humana

- [x] **Entrega:** Vincular resposta à solicitação e resolver; preservar respostas tardias no histórico.
- **Aceite:** Execução expirada não revive; nova execução pode consultar resposta.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-18/T03 — Adicionar ações de pergunta/resposta na UI

- [x] **Entrega:** Detalhe da tarefa mostra pendências e autor da resposta.
- **Aceite:** Humano responde sem manipular manualmente status de execução.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M4 · US-19 — Entregar menções e notificações

Como participante, quero descobrir mensagens que exigem minha atenção.

**Dependências:** US-18. **Status:** done.

### US-19/T01 — Persistir menções validadas

- [x] **Entrega:** Referenciar humano/agente do workspace com acesso ao contexto; evento de menção.
- **Aceite:** @texto isolado não executa nem cria comandos.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-19/T02 — Criar notificações internas

- [x] **Entrega:** Projeção idempotente por evento/destinatário; listar e marcar como lida.
- **Aceite:** Reprocessamento não duplica notificação; revogação de acesso protege conteúdo.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-19/T03 — Aplicar limites de comunicação

- [x] **Entrega:** Contador de mensagens e duração máxima; limite encerra reserva e exige intervenção humana.
- **Aceite:** Dois agentes não podem produzir loop ilimitado por menções.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M5 · US-20 — Definir contrato HTTP

Como integrador, quero uma API previsível.

**Dependências:** US-13, US-19. **Status:** done.

### US-20/T01 — Escrever OpenAPI de leitura e comandos

- [x] **Entrega:** Documentar rotas da arquitetura, schemas, autenticação, leases, erros e exemplos; incluir GET de execução para runner/MCP.
- **Aceite:** Contrato permite consultar execução atual sem banco e tem lint válido.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-20/T02 — Criar infraestrutura HTTP

- [x] **Entrega:** DTOs, validação, Problem Details, request_id, limites de body e cache private/no-store.
- **Aceite:** Erros não incluem stack trace; 404/403 seguem regra de escopo.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-20/T03 — Implementar idempotência atômica

- [x] **Entrega:** Reservar key por ator/workspace/operação/recurso e hash; resolver concorrência; criptografar replay de claim com TTL e chave externa.
- **Aceite:** Mesma key simultânea não duplica efeito; payload divergente retorna 409.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M5 · US-21 — Expor leituras e edição de tarefas

Como cliente autorizado, quero consultar e atualizar trabalho.

**Dependências:** US-20. **Status:** done.

### US-21/T01 — Implementar endpoints de consulta

- [x] **Entrega:** Workspaces, boards, tasks, detalhe e execução; filtros e paginação por cursor estável.
- **Aceite:** Filtros não ampliam grants; limite máximo 100.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-21/T02 — Implementar criação/PATCH/transição

- [x] **Entrega:** Whitelist, If-Match/ETag, sessão com CSRF ou Bearer com scopes; chamar serviços existentes.
- **Aceite:** 412 para versão antiga; 428 para precondição ausente; status não muda via PATCH.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-21/T03 — Testar transporte e autenticação cruzada

- [x] **Entrega:** Testes HTTP de isolamento, CSRF, scope, campos proibidos e paginação.
- **Aceite:** API genérica Drupal não oferece caminho alternativo de escrita.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M5 · US-22 — Expor execução e conversas

Como runner, quero realizar todo o ciclo por HTTP.

**Dependências:** US-21. **Status:** done.

### US-22/T01 — Expor comandos de execução

- [x] **Entrega:** Claim, heartbeat, progress, submit, fail e release com idempotência e lease.
- **Aceite:** Repetir operação não duplica efeitos; lease inválida é recusada.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-22/T02 — Expor conversas e revisão

- [x] **Entrega:** Mensagens, request-input, answer, approve e reject com roles corretos.
- **Aceite:** Agente não responde como humano nem aprova resultado.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-22/T03 — Aplicar rate limit e retenção idempotente

- [x] **Entrega:** Storage compartilhado, Retry-After, limites específicos de mensagens e limpeza dos replays secretos.
- **Aceite:** Orçamento permite polling/heartbeat e expurgo remove segredos encerrados.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M5 · US-23 — Validar runner de referência

Como desenvolvedor, quero testar integração sem depender de LLM.

**Dependências:** US-22. **Status:** done.

### US-23/T01 — Criar cliente CLI determinístico

- [x] **Entrega:** Config via env, HTTP tipado, timeouts e escolha de tarefa elegível.
- **Aceite:** Executa claim/progress/submit sem ler o banco.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-23/T02 — Adicionar heartbeat e recuperação

- [x] **Entrega:** Manter heartbeat independente da execução; persistir cursor/chaves; parar ao perder lease.
- **Aceite:** Reinício valida lease antes de continuar; retry não duplica efeitos.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-23/T03 — Criar cenário interativo completo

- [x] **Entrega:** Runner pede ajuda, humano responde e aprova; segundo runner revisa por tarefa dependente separada.
- **Aceite:** Dois agentes usam tokens distintos; aprovação final segue humana.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M6 · US-24 — Sincronizar por eventos

Como cliente, quero recuperar mudanças sem recarregar tudo.

**Dependências:** US-07, US-22. **Status:** done.

### US-24/T01 — Criar snapshot consistente

- [x] **Entrega:** Ler board e cursor na mesma visão transacional do banco.
- **Aceite:** Commit durante snapshot não é perdido na próxima consulta.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-24/T02 — Expor eventos e inbox

- [x] **Entrega:** Cursor com escopo, filtro por acesso atual, avanço em eventos inspecionados e retenção/410.
- **Aceite:** Agente não recebe mensagens de outros boards e não fica preso em página filtrada vazia.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-24/T03 — Testar paginação e reconexão

- [x] **Entrega:** Eventos concorrentes, cursor expirado, revogação e duplicação.
- **Aceite:** Resync recupera estado sem omitir commits.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M6 · US-25 — Atualizar interface automaticamente

Como humano, quero acompanhar agentes quase em tempo real.

**Dependências:** US-24, US-11, US-19. **Status:** done.

### US-25/T01 — Implementar polling compartilhado

- [x] **Entrega:** Drupal behavior com attach/detach, 3s/15s, backoff/jitter, sem sobreposição.
- **Aceite:** Navegação não multiplica timers; aba oculta reduz frequência.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-25/T02 — Atualizar cards, conversas e notificações

- [x] **Entrega:** Deduplicar event_id, buscar recursos invalidados e preservar rascunhos/foco.
- **Aceite:** Duas abas refletem alterações em até 5s no teste normal.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-25/T03 — Tratar perda de acesso e rede

- [x] **Entrega:** 401, 403/404, 410 e falhas temporárias com estado visual.
- **Aceite:** Reconexão não duplica mensagens nem mantém conteúdo revogado visível após revalidação.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M6 · US-26 — Operar worker e manutenção

Como operador, quero processamento recuperável.

**Dependências:** US-15, US-19, US-24. **Status:** todo.

### US-26/T01 — Criar worker Drush supervisionável

- [ ] **Entrega:** Loop com shutdown gracioso, outbox e expiração a cada 15s; limites de recursos.
- **Aceite:** Reinício não perde trabalho confirmado nem deixa lease eterna.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-26/T02 — Implementar retries e fila de falhas

- [ ] **Entrega:** Claim de outbox, timeout, backoff, tentativas máximas e reprocessamento.
- **Aceite:** Dois workers não produzem projeções duplicadas.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-26/T03 — Criar limpeza e observabilidade

- [ ] **Entrega:** Expurgar eventos 30 dias/replays expirados; logs sem secrets; métricas da outbox/leases.
- **Aceite:** Limpeza respeita cursores e separa auditoria durável de feed transitório.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M7 · US-27 — Validar piloto e produção

Como owner, quero usar o MVP com confiança.

**Dependências:** US-23, US-25, US-26. **Status:** todo.

### US-27/T01 — Executar regressão de segurança e domínio

- [ ] **Entrega:** Isolamento, tokens, concorrência, transições, sanitização, grants e idempotência.
- **Aceite:** Checklist com comandos/evidências e falhas registradas.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-27/T02 — Validar telas e acessibilidade

- [ ] **Entrega:** Comparar com docs/screens; mobile, teclado e estados vazio/loading/erro/negado.
- **Aceite:** Divergências e recursos fora do MVP documentados sem fingir implementação.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-27/T03 — Criar runbooks e restaurar backup

- [ ] **Entrega:** Instalação, deploy, worker, rotação, rollback e restauração em ambiente de teste.
- **Aceite:** Backup restaurado de fato e health check confirma operação.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-27/T04 — Executar carga e piloto

- [ ] **Entrega:** Meta de teste da arquitetura: 20 humanos, 10 agentes, 10 boards e 5.000 tasks; medir p95 e erros.
- **Aceite:** Relatório declara ambiente, método, resultados e limitações; sem promessa de escala não medida.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## M8 · US-28 — Conectar por MCP após o MVP

Como agente compatível, quero usar ferramentas de domínio.

**Dependências:** US-27. **Status:** todo.

### US-28/T01 — Criar adaptador stdio sobre HTTP

- [ ] **Entrega:** Mapear ferramentas da arquitetura, schema de entrada/saída e identidade por agente.
- **Aceite:** Nenhuma ferramenta acessa banco ou implementa regra paralela.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-28/T02 — Validar manutenção da lease

- [ ] **Entrega:** Definir runner responsável pelo heartbeat; cancelamento e encerramento de sessão.
- **Aceite:** Chamada MCP isolada não é tratada como execução permanentemente ativa.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

### US-28/T03 — Integrar um cliente real

- [ ] **Entrega:** Validar recursos efetivamente disponíveis, executar ciclo e documentar configuração/limitações.
- **Aceite:** Integração funciona no ambiente testado sem pressupor controle remoto de editor.
- **Verificação:** demonstrar o aceite com teste de regra/integração quando aplicável; para documentação ou apresentação, revisão direta e validação funcional proporcional.

## Itens explicitamente posteriores

Anexos binários privados; checklist estruturado (critérios de conclusão em texto já existem); DMs privadas; streaming/SSE; configuração livre de colunas; agendamento recorrente; marketplace; cobrança e execução de código no Drupal. Elementos dessas funções presentes nas telas não autorizam sua implementação no MVP.

## Prompt por task para o Cursor

```text
Leia AGENTS.md, docs/architecture.md, docs/backlog.md e docs/implementation-status.md.
Implemente somente a task US-01/T01. Nas próximas interações, substitua esse ID pelo item escolhido.

Antes de alterar código:
1. Verifique dependências concluídas e inspecione a implementação existente.
2. Se houver UI, identifique e examine as referências aplicáveis em docs/screens/.
3. Resuma o escopo da task e as validações necessárias.

Implemente o menor incremento completo. Reutilize serviços existentes; mantenha regras fora dos controllers/JavaScript. Não implemente tasks futuras para preencher telas. Se faltar uma decisão essencial, registre o bloqueio e conclua apenas trabalho independente.

Execute as verificações pertinentes e atualize o status pelo ID. Ao terminar, informe arquivos alterados, comportamento entregue, comandos/resultados e pendências. Não marque como done uma task com aceite não validado. Não faça push/deploy nem use credenciais reais como parte desta task.
```

## Modelo de acompanhamento

Criar esta tabela em `docs/implementation-status.md`, acrescentando uma linha para cada task na primeira inicialização:

| ID | Status | Evidência | Bloqueio | Próxima ação |
| --- | --- | --- | --- | --- |
| US-01/T01 | todo | — | — | Inspecionar repositório |

Estados: todo, in_progress, blocked, done. Apenas uma task in_progress por executor. Commit sugerido: `feat(ab_core): ... [US-04/T01]`, adaptando tipo e módulo. O backlog é planejamento; o registro de implementação é evidência do que realmente foi entregue.

**Resumo:** 28 histórias e 83 tasks. M8 é evolução pós-MVP; M0–M7 compõem o caminho do primeiro piloto.

