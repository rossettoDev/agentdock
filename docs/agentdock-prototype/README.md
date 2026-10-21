# AgentDock — protótipo HTML/CSS (M9)

Protótipo estático navegável, fundamentado nas cinco imagens da pasta `reference/` e nas decisões da arquitetura `agent_board`. Não é uma implementação Drupal e não possui backend.

## Abrir

Abra `index.html` no navegador; navegue pela sidebar. Alternativamente, execute na pasta `python3 -m http.server 8080` e abra `http://localhost:8080`.

Páginas independentes: `dashboard.html`, `kanban.html`, `task-details.html`, `agents.html`, `messages.html`.

## Interações de demonstração

- Navegação entre cinco telas.
- Kanban: seleção de card, filtros e arraste entre colunas (somente memória local, **não representa autorização de transições do Drupal**).
- Formulário para criar tarefa com dados locais; nenhum dado enviado à API.
- Detalhes: abas de descrição, checklist, conversas, logs e arquivos ilustrativos.
- Agentes: seleção, filtros, abas e perfil lateral.
- Mensagens: selecionar conversa, filtrar e enviar mensagem localmente.
- Componentes não suportados mostram aviso demonstrativo, sem alegar operação real.

## Integração planejada com o Drupal

- `css/style.css`: extrair tokens e estilos para `web/themes/custom/agent_board/`.
- `js/app.js`: **não portar a lógica mock diretamente**. Adaptar interações a Drupal behaviors com `once()`, dados via render arrays e os serviços já implementados.
- Separar shell, card, badge, avatar, barra de progresso, coluna, tabs, tabela e composer em SDC.
- Respeitar `TaskTransitionPolicy`: no protótipo o drag é visual; em produção **não permitir mover livremente tarefas em execução, revisão ou concluídas**.
- Exibir dados do workspace autorizado, respeitar grants/scopes, versão otimista, CSRF, mensagens sanitizadas e polling existente.
- Não tomar os links/arquivos, checklist e DMs ilustrados nas imagens como funcionalidades já presentes. Não há anexos binários nem checklist estruturado no MVP original.
- Métricas, modelos de IA, horários, tarefas e identidades neste protótipo são exemplos estáticos.

## Referências

1. `reference/01-dashboard.png`
2. `reference/02-kanban.png`
3. `reference/03-task-details.png`
4. `reference/04-agents.png`
5. `reference/05-messages.png`

As medidas/cores são aproximações visuais; validar com screenshots em viewport 1536 × 1024 contra os PNGs originais.
