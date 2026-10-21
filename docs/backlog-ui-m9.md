# AgentDock — Backlog de evolução visual (M9)

Versão 1.0 · 21/10/2026 · Status: **em execução** (US-37 done).

## 1. Fontes e objetivo

Fontes: `docs/architecture.md` (arquitetura 1.0, especialmente §§ 4, 6, 8, 11, 13, 14, 17, 19 e 21), `docs/backlog.md` (US-01 a US-28) e `docs/implementation-status.md` (atualizado em 20/10/2026, M8 concluído). As cinco imagens de referência devem estar em `docs/screens/` e ser inspecionadas **no repositório** antes da implementação; não presumir nomes de arquivos. Este documento planeja a evolução das cinco telas, não declara que as telas já foram implementadas neste visual.

Objetivo: transformar a interface funcional existente na experiência visual de Dashboard, Kanban, Detalhes da Tarefa, Central de Agentes e Central de Mensagens, **sem reimplementar domínio, endpoints, autenticação ou sincronização**. Nome de produto conforme status atual: AgentDock; identificadores técnicos existentes `agent_board`, `ab_*` e tema `agent_board` permanecem.

## 2. Diagnóstico e restrições

| Tela | Base já registrada como done | Lacuna desta fase |
|---|---|---|
| Dashboard | Workspaces, boards, tasks, agents, messages, events | Tela agregadora não estava no MVP |
| Kanban | US-11 e US-25: colunas, movimento acessível, ordenação, Hoje e polling | Fidelidade visual, filtros e microinterações |
| Detalhes | US-09/10/16/18: descrição, critérios, dependências, execuções, tentativas, revisão e input requests | Layout completo com contexto e abas adequadas |
| Agentes | US-12/13/16: cadastro, grants, credenciais, execução e último sinal | Layout de gestão, métricas fundamentadas e estados |
| Mensagens | US-17/18/19/25: threads de board/tarefa, menções, pedidos, notificações e atualização | Layout com lista, conversa e contexto lateral |

**Invariantes:** workspace é a fronteira de acesso; acesso por UUID externo dá 404; política de escrita em serviços; humanos aprovam resultados; `in_progress` resulta de claim, `in_review` de submit e `done` de aprovação; `blocked` e `waiting_input` são condições, não colunas; formulário ou DnD usa versão esperada, sem last-write-wins. Polling usa infraestrutura existente (3s ativo/15s oculto). Não declarar agente online sem sinal confiável. A UI não deve revelar bearer/lease/token. Nenhuma chamada nova da UI contorna autorização de domínio.

**Recursos fora desta fase básica:** anexos binários, checklist estruturado, DMs privadas, streaming/SSE, colunas personalizáveis, calendário/timeline realmente funcionais, métricas de custo/uso de LLM, reuniões/videoconferência, links falsos, indicador de progresso percentual se a persistência atual não o suportar. Não mostrar ações decorativas que prometem operação inexistente. Tratar tais recursos em ADR/backlog posterior, jamais inferi-los da imagem.

## 3. Índice e ordem sugerida

| História | Entrega | Dependências | Prioridade | Status |
|---|---|---|---|---|
| US-29 | Auditoria visual e design system | US-28 | P0 | done |
| US-30 | Shell, navegação e componentes compartilhados | US-29 | P0 | done |
| US-31 | Kanban visual | US-30; US-11, US-25 existentes | P0 | done |
| US-32 | Detalhe da tarefa | US-30; US-16, US-18 existentes | P0 | done |
| US-33 | Central de Agentes | US-30; US-12, US-13, US-16 existentes | P1 | done |
| US-34 | Central de Mensagens | US-30; US-17–19, US-25 existentes | P0 | todo |
| US-35 | Dashboard Geral | US-31–34 | P1 | todo |
| US-36 | Integração, responsividade e validação final | US-31–35 | P0 | todo |
| US-37 | Chrome do protótipo | US-30; `docs/agentdock-prototype` | P0 | done |

Executar **uma task por interação no Cursor**, seguindo o `Definition of Done` atual. Cada task desta fase inicia como `todo`; registrar evidências em `docs/implementation-status.md` sem alterar os registros históricos de US-01 a US-28.

## M9 — Evolução visual

### US-29 — Auditar referências e definir design system

**História:** Como usuário, quero uma interface consistente que represente corretamente os estados existentes do AgentDock.

- [x] **US-29/T01 — Inventariar telas e rotas reais.** Inspecionar `docs/screens/README.md`, imagens e rotas/templates/controllers atuais. Criar matriz imagem → rota → fonte de dados → ação → disponibilidade. **Aceite:** cinco telas mapeadas; elementos apenas ilustrativos ou fora do MVP sinalizados; não inventar nomes de screenshots. Evidência: `docs/US-29-T01-matriz.md`.
- [x] **US-29/T02 — Auditar os dados realmente disponíveis.** Verificar campos e serviços para números, status, progresso, último sinal, entrega, mensagens e contagens. **Aceite:** nenhum KPI/percentual ou texto “online” é implementado sem contrato de dados documentado; indicar alternativa visual honesta. Evidência: `docs/US-29-T02-contrato-dados.md`.
- [x] **US-29/T03 — Definir tokens e componentes SDC.** Documentar paleta dark, superfícies, contraste, tipografia, escala de espaçamento, badges semânticos, botões, cards, abas, avatar/identidade humana ou agente, skeleton, estado vazio/erro. Implementar tokens no tema e SDC para padrões que se repetem; reaproveitar `ab-card`, `ab-button` e o shell existentes. **Aceite:** amostra dos componentes com estados hover/focus/disabled; visual e acessibilidade verificáveis. Evidência: `docs/US-29-T03-design-system.md`. Amostra em `/ui/amostra`.

### US-30 — Evoluir shell e navegação comum

**História:** Como usuário, quero navegar entre as cinco áreas sem perder o contexto do workspace.

- [x] **US-30/T01 — Sidebar e header.** Ajustar `agent_board`/`ab_ui` para menu Dashboard, Meus Boards, Tarefas, Conversas, Agentes; breadcrumb, seletor real de workspace, conta e indicação da página ativa. **Aceite:** links conduzem a rotas reais e respeitam acesso; selecionar workspace usa mecanismo de sessão existente, nunca `?workspace=`. Dashboard omitido até existir rota. Tarefas só aparece com um board legível na rota.
- [x] **US-30/T02 — Layout responsivo de três áreas.** Definir largura de navegação, conteúdo fluido e painel contextual reutilizável. Em telas estreitas, navegação recolhível e painel contextual acessível. **Aceite:** nenhuma ação essencial depende exclusivamente de hover; sem overflow horizontal da página; foco restaurado ao fechar painel.
- [x] **US-30/T03 — Estados globais e notificações.** Padronizar loading/empty/error/forbidden/conflito/sessão expirada; ligar sino ao sistema real de notificações. Busca global só se suportada; senão restringir placeholder a busca existente ou omitir. **Aceite:** nenhuma falsa funcionalidade; mensagens de erro claras e sem vazamento de outro workspace. Busca global omitida.

### US-31 — Kanban visual

**História:** Como membro, quero perceber status, prioridade, responsáveis e progresso de cada tarefa num quadro legível.

- [x] **US-31/T01 — Cabeçalho e filtros de board.** Layout conforme screenshot; tabs Kanban e Lista somente se houver listagem funcional; filtro Hoje existente, prioridades, agente e status quando houver consulta autorizada correspondente. **Aceite:** filtro nunca amplia workspace/grants; filtros preservam contexto e têm estado vazio. Lista, calendário e timeline ficam de fora. O status continua nas colunas.
- [x] **US-31/T02 — Colunas e cards.** Renderizar estados persistidos `backlog`, `ready`, `in_progress`, `in_review`, `done`; canceladas em filtro separado. Mapear rótulos conforme referência sem fundir `backlog` com `ready`; exibir badges de `blocked`/`waiting_input`, prazo no timezone, `human_owner`, `assigned_agent`/execução quando disponíveis. **Aceite:** contagens batem com consultas filtradas; sem dados demonstrativos fixos.
- [x] **US-31/T03 — Ações e drag-and-drop.** Manter menu de movimento por teclado e serviço `TaskTransitionPolicy`; restilizar DnD/ordenação com feedback de pending/success/conflito. **Aceite:** arrastar não coloca tarefa arbitrariamente em andamento, revisão ou concluída; versão obsoleta reverte UI e explica o conflito.
- [x] **US-31/T04 — Atualização incremental.** Usar behavior/eventos existentes para refletir tarefas e estados do agente sem apagar foco/rascunho/drag. **Aceite:** duas abas convergem conforme meta existente; sem timer duplicado; nenhuma atualização posterior à revogação permanece visível.

### US-32 — Detalhes da tarefa

**História:** Como humano, quero entender a tarefa, sua execução e as decisões no mesmo contexto visual.

- [x] **US-32/T01 — Visão geral em painel contextual.** Reorganizar detalhe existente (ou rota com abertura contextual e fallback direto): título/UUID, descrição Markdown sanitizada, critérios de aceite textuais, prioridade, prazo, owner humano, agente atribuído, status e dependências. **Aceite:** links diretos e back/forward funcionam; não perder contexto do board; acessível por teclado. A rota continua `/tasks/{uuid}`. As abas são links com `tab`.
- [x] **US-32/T02 — Progresso, histórico e entrega.** Exibir status de execução atual, tentativas, heartbeat/último sinal quando disponível, notas, resumo, links de resultado e ações humanas permitidas. **Aceite:** não há barra percentual. `approve/reject/cancel` seguem a policy e a versão. A nota continua texto.
- [x] **US-32/T03 — Conversa e pedidos de informação.** Aba/seção com thread de tarefa e input requests existentes, resposta humana vinculada e mensagens de sistema; integrar sem duplicar MessageService. **Aceite:** o agente não aprova entrega. Quem não escreve não vê enviar nem aprovar. Thread de outro workspace não aparece.
- [x] **US-32/T04 — Estados e abas honestas.** Descrição, Conversas, Execuções/Atividade e Links de entrega; mostrar critérios como texto, não checklist falso. Omitir aba Arquivos e checklist interativo até história posterior. **Aceite:** vazio, falha, conflito e execução expirada têm mensagem específica. Sem botão inerte.

### US-33 — Central de Agentes

**História:** Como admin/owner, quero gerir agentes e entender seu trabalho sem confundir cadastro com presença online.

- [x] **US-33/T01 — Lista e filtros.** Reestilizar rota `/agents`, busca por agentes do workspace, enabled/disabled, trabalho ativo, contagens calculadas em queries com escopo. **Aceite:** habilitado é atributo administrativo e trabalhando exige execução/heartbeat elegível; não inventar custo/modelo/progresso.
- [x] **US-33/T02 — Painel de agente.** Mostrar nome, descrição, capabilities, grants por board, concurrency_limit, tarefa/execução atual e último sinal quando presentes; múltiplas execuções se limite permitir. **Aceite:** nenhuma referência entre workspaces; se sem execução, indicar sem atividade e não “online”.
- [x] **US-33/T03 — Cadastro, permissões e credenciais.** Integrar formulários já existentes, visual de ativação/grants e ciclo de emissão/revogação/rotação do token. **Aceite:** token completo mostrado só uma vez no fluxo apropriado; owner/admin distinguem suas permissões; nada sensível no DOM/log de listagem.
- [x] **US-33/T04 — Estados de execução.** Badges semânticos baseados em entidades reais: aguardando tarefa, running, waiting_input, submitted, failed, interrupted. **Aceite:** tela não afirma que uma conexão MCP aberta mantém heartbeat; só dados persistidos.

### US-34 — Central de Mensagens

**História:** Como membro, quero coordenar humanos e agentes em conversas contextualizadas por board ou tarefa.

- [ ] **US-34/T01 — Lista de threads.** Reestilizar listagem autorizada por workspace com tipos board/task, título, preview permitido, data e indicação de não lidas somente se suportada pela projeção existente. **Aceite:** nenhum contato/DM privado inventado; paginação `before` mantida.
- [ ] **US-34/T02 — Área de conversa.** Exibir autores `human`/`agent`/`system`, timestamps, kinds, respostas e @menções estruturadas; composer existente com envio e feedback. **Aceite:** sanitização, 16 KiB, identidade da autenticação, imutabilidade e deduplicação preservadas.
- [ ] **US-34/T03 — Contexto lateral da tarefa.** Quando thread tiver tarefa, exibir link real, status, prioridade, owner/agente, dependências e pedidos de informação; quando for thread geral, mostrar board em vez de criar uma tarefa fictícia. **Aceite:** painel respeita a mesma policy e não amplia acesso.
- [ ] **US-34/T04 — Quase tempo real e atenção humana.** Reutilizar eventos, input requests, menções e notificações para atualizar thread sem perder rascunho/foco. **Aceite:** duas abas sincronizam, sem duplicar mensagens; perda de acesso limpa conteúdo revogado.

### US-35 — Dashboard Geral

**História:** Como usuário, quero uma visão consolidada dos boards e tarefas que tenho permissão de acompanhar.

- [ ] **US-35/T01 — Definir read-model agregado.** Criar serviço de leitura/consultas, não nova fonte de verdade: contagens por status, boards acessíveis, tarefas do dia/atrasadas, revisão aguardando humano, agentes com execução atual, atividade recente e notificações. **Aceite:** todos os números rastreáveis a dados reais, filtrados pelo workspace selecionado; sem custo de IA ou métricas arbitrárias.
- [ ] **US-35/T02 — Montar cards e sessões.** Cards KPI, Meus Boards, agentes/execuções, distribuição de estados, tarefas que precisam de atenção, conversas e atividade recente; links para rotas efetivas. **Aceite:** primeira tela útil com banco vazio; nenhum dado mock fica em produção.
- [ ] **US-35/T03 — Drill-down e preferências.** Cada indicador abre filtro correspondente; adaptar a dashboard para owner/admin/member/viewer e mobile. **Aceite:** viewer não vê criação/gestão; denominadores e filtros das contagens documentados; não revelar métricas de workspace alheio.
- [ ] **US-35/T04 — Sincronização do painel.** Invalidar apenas regiões pertinentes com infraestrutura de eventos existente; evitar N+1 queries. **Aceite:** atualização consistente com polling atual; medições de consultas documentadas sem prometer capacidade que não foi testada.

### US-36 — Integração visual e qualidade

**História:** Como usuário, quero uma experiência consistente e utilizável nas cinco telas.

- [ ] **US-36/T01 — Navegação e integração end-to-end.** Percorrer Dashboard → Board → Card → Conversa → Agente → Board, com URLs de recurso reais, acessos e estados. **Aceite:** fluxo preserva o workspace da sessão, sem criar seleções implícitas por query string.
- [ ] **US-36/T02 — Responsividade e acessibilidade.** Validar desktop/tablet/telefone, teclado, foco, `aria`/labels, contraste, redução de movimento e alternativa ao DnD. **Aceite:** evidenciação em viewport real e teste funcional; nenhuma operação exclusiva de mouse.
- [ ] **US-36/T03 — Segurança, regressão e sincronização.** Rodar testes proporcionais dos módulos tocados, isolamento entre dois workspaces, revogação, sanitização, conflito 412, polling/410, sessão/CSRF e credenciais. **Aceite:** relatar comandos e resultados reais, corrigir regressões sem mudar serviço de domínio por estética.
- [ ] **US-36/T04 — Comparação visual e documentação.** Comparar screenshots reais com telas; registrar diferenças deliberadas por limite de MVP. Atualizar `docs/screens/README.md`, backlog, status e ADR quando necessário. **Aceite:** cinco telas validadas; pendências do pós-M9 registradas separadamente.

### US-37 — Chrome do protótipo

**História:** Como usuário, quero que as telas já existentes usem o enquadramento de `docs/agentdock-prototype`, sem ganhar função que o protótipo só ilustra.

- [x] **US-37/T01 — Sidebar e barra superior.** Sidebar de 196px, fixa na altura da janela, com marca, navegação e boards. Barra de 60px com o seletor de workspace, o sino e a conta. **Aceite:** não há busca global nem link de Dashboard. O botão Menu só aparece na largura estreita. O workspace continua na sessão.
- [x] **US-37/T02 — Superfícies das telas existentes.** Colunas do kanban, filtros, contagens e linhas de agente seguem as superfícies do protótipo. **Aceite:** cinco colunas de status permanecem. Não há percentual, “online”, calendário, arquivos, checklist nem relatório. O botão primário continua no azul que passa contraste.

## 4. Evoluções opcionais — não iniciar como parte do M9

Estudar em marco e ADR próprios: (a) checklist estruturado e percentual derivado; (b) anexos binários privados com política de acesso, upload seguro e retenção; (c) mensagens diretas privadas, nova fronteira de autorização e semântica de participantes; (d) vistas Calendário/Timeline e colunas customizáveis; (e) custo/modelo/métricas reais de uso; (f) SSE. Antes de criar uma dessas histórias, especificar entidades, migração, API, policies, eventos e testes.

## 5. Definition of Done da fase

- A task altera o menor conjunto revisável e reutiliza os módulos, serviços, endpoints e behavior existentes.
- Visual segue a imagem correspondente em `docs/screens/`, ressalvadas divergências documentadas com a arquitetura.
- Dados de produção vêm do banco/read-model, nunca de mocks ilustrativos; placeholders somente em fixtures/testes.
- Cada leitura, busca, contagem, autocomplete e evento respeita workspace e grants; ações respeitam policy, CSRF, version e comandos de domínio.
- Estados loading, vazio, erro, 403/404/410, sessão expirada, conflito e desconexão são tratados conforme contexto.
- Teclado, tela reduzida e contraste validados; DnD possui alternativa por botão.
- Executar testes relevantes, PHPCS/PHPStan e documentar **resultado real** em `docs/implementation-status.md`; não declarar teste não executado.
- Não sobrescrever os registros de M0–M8 e não marcar US-29–36 como done pela existência da imagem.

## 6. Prompt inicial para o Cursor

```text
Leia AGENTS.md, docs/architecture.md, docs/backlog.md,
docs/implementation-status.md e docs/screens/README.md.

Este trabalho é a fase M9 de evolução visual do AgentDock. Leia também
agentdock-ui-backlog.md (ou docs/backlog-ui-m9.md após incluí-lo no repositório).
Não recrie entidades, endpoints, serviços ou componentes existentes.
M0–M8 estão registrados como concluídos; verifique o código atual antes de usar.

Implemente SOMENTE US-29/T01.

1. Inspecione as cinco imagens reais de docs/screens/ e o estado atual das rotas,
   controllers, templates, libraries e componentes.
2. Produza a matriz imagem → rota → fonte de dados → ação → lacunas de MVP.
3. Preserve isolamento por workspace, políticas de acesso, comandos de domínio,
   controle de versão e polling existente.
4. Não implemente funcionalidade só porque aparece no mockup.
5. Rode as validações pertinentes; registre arquivos alterados, testes e resultados
   reais, pendências e status por ID em docs/implementation-status.md.
6. Não faça push/deploy nem use credenciais reais.
```
