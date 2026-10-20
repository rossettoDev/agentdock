# US-29/T03 — Tokens e componentes

Data: 2026-10-20. Tokens no tema `agent_board`. Componentes SDC em `web/themes/custom/agent_board/components/`. A amostra está em `/ui/amostra` para quem já entrou. Ela não entra no menu e não lê o banco.

Os rótulos seguem `docs/US-29-T02-contrato-dados.md`. Não há percentual nem “online”.

## Tokens

Definidos em `:root` de `css/agent-board.css`. A escala de espaço é 0,25 / 0,5 / 0,75 / 1 / 1,5 / 2 rem. O texto usa 0,875, 1 e 1,25 rem, fonte do sistema, entrelinha 1,5.

| Token | Valor | Uso |
| --- | --- | --- |
| `--ab-bg` | `#0b1220` | Fundo da página |
| `--ab-surface` | `#111827` | Header e estado |
| `--ab-raised` | `#1e293b` | Cartão |
| `--ab-text` | `#e2e8f0` | Texto |
| `--ab-muted` | `#94a3b8` | Texto secundário |
| `--ab-border` | `#334155` | Contorno |
| `--ab-primary-strong` | `#1d4ed8` | Fundo do botão |
| `--ab-primary-hover` | `#1e40af` | Botão com ponteiro |
| `--ab-on-primary` | `#ffffff` | Texto do botão |
| `--ab-link` | `#93c5fd` | Link e botão quiet |
| `--ab-focus` | `#93c5fd` | Anel de `:focus-visible` |
| `--ab-success-text` | `#86efac` | Badge concluída / habilitado |
| `--ab-warning-text` | `#fbbf24` | Badge revisão, bloqueada, aguardando |
| `--ab-danger-text` | `#fca5a5` | Badge cancelada e falha |
| `--ab-info-text` | `#7dd3fc` | Badge pronta e em andamento |

Contraste calculado em 2026-10-20, texto normal, mínimo 4,5:1:

| Par | Razão |
| --- | --- |
| `#e2e8f0` sobre `#0b1220` | 15,19 |
| `#e2e8f0` sobre `#1e293b` | 11,87 |
| `#94a3b8` sobre `#1e293b` | 5,71 |
| `#ffffff` sobre `#1d4ed8` | 6,70 |
| `#ffffff` sobre `#1e40af` | 8,72 |
| `#93c5fd` sobre `#111827` | 9,84 |
| `#e2e8f0` sobre `#334155` | 8,40 |
| `#86efac`, `#fbbf24`, `#fca5a5`, `#7dd3fc` sobre `#1e293b` | 10,42 / 8,76 / 7,71 / 8,77 |

`#ffffff` sobre `#3b82f6` fica em 3,68 e não é fundo de botão. O azul `#3b82f6` continua só como acento. O anel de foco tem 2px e deslocamento de 2px em botão, aba, link do conteúdo e item da sidebar. `prefers-reduced-motion: reduce` desliga a animação do skeleton.

## Componentes

Cada um depende da library `agent_board/global` e reutiliza as classes `ab-button` e `ab-card`. As listas existentes continuam com essas classes no HTML, sem incluir o tema, para os testes que usam `stark` não dependerem do SDC.

| Componente | O que mostra |
| --- | --- |
| `agent_board:ab-button` | `primary` ou `quiet`. Com `url` é link. `disabled` é `<button disabled>`, sem destino |
| `agent_board:ab-card` | Título opcional e slot `body` |
| `agent_board:ab-badge` | Tom limitado aos status e condições do contrato |
| `agent_board:ab-tabs` | Links. O item `current` recebe `aria-current="page"`. Sem script de painel |
| `agent_board:ab-avatar` | Inicial, nome e rótulo Humano ou Agente. Sem ponto de presença |
| `agent_board:ab-skeleton` | `role="status"` e texto “Carregando” só para leitor de tela |
| `agent_board:ab-state` | `empty`, `error` (`role="alert"`) ou `loading` (`role="status"`) |

## Amostra

Rota `ab_ui.design_sample`, caminho `/ui/amostra`, usuário autenticado. Tema esperado: `agent_board`.

## Próxima história

US-30 evolui o menu. Esta task não adiciona Dashboard nem troca o seletor de workspace.
