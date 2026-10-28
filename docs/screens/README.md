# AgentDock — Referências Visuais da Interface

Versão: 1.0 · Data: 2026-10-15

Este diretório contém as telas de referência para implementação da interface do AgentDock. Consulte estas telas antes de implementar ou alterar qualquer interface.

## Inventário de Telas

| Arquivo | Finalidade | Escopo MVP | Observações |
|---------|------------|------------|-------------|
| `Quadro Kanban Drupal em modo escuro.png` | Board Kanban com colunas de status | ✅ M2 (US-11) | Fluxo fixo no MVP; colunas configuráveis ficam para evolução |
| `Painel de Tarefa Drupal em Andamento.png` | Detalhe completo de uma tarefa em execução | ✅ M2-M4 (US-09, US-16, US-17, US-18) | Mostra descrição, critérios, execução, conversa e ações |
| `Painel de Conversas com Agentes de IA.png` | Central de mensagens e conversas | ✅ M4 (US-17) | DMs privadas entre quaisquer participantes ficam fora do MVP |
| `Painel de Agentes IA em Modo Escuro.png` | Central de agentes com status e credenciais | ✅ M3 (US-12) | Status "online" requer heartbeat recente verificável |
| `Painel AI Board em modo escuro.png` | Dashboard/visão geral do workspace | ✅ M9 (US-35) | Contagens gravadas do workspace selecionado; sem custo, percentual nem “online” |

## Divergências Identificadas

### 1. Painel AI Board (Dashboard)

**Status:** ✅ **Entregue no M9 (US-35), com limite**

**Descrição:** A referência mostra um painel geral com métricas, atividade recente e visão consolidada.

**Decisão:** A rota `/dashboard` existe e lê contagens já gravadas no workspace da sessão: quadros, status, Hoje, atrasadas, revisão humana, execuções e notificações. Não há custo, percentual, presença “online” nem métrica sem contrato.

**Ação:** Manter o painel nesses limites. Custo, modelo e métricas de uso continuam na seção 4 de `docs/backlog-ui-m9.md`.

### 2. Anexos e Checklist

**Status:** ⚠️ **Presentes nas telas, fora do MVP**

**Observação nas telas de tarefa:**
- Anexos binários privados: fora do MVP (seção "Itens explicitamente posteriores")
- Checklist estruturado: fora do MVP (critérios de conclusão existem como texto)

**Ação:** Não implementar campos de anexos/checklist no detalhe da tarefa. Usar apenas campos de texto.

### 3. Mensagens Diretas (DMs)

**Status:** ⚠️ **Presentes na tela, fora do MVP**

**Observação na tela de conversas:**
- Interface sugere DMs privadas entre quaisquer participantes
- Arquitetura define: "mensagens diretas privadas entre quaisquer participantes" fora do MVP

**Ação:** Central de Mensagens mostra apenas conversas de boards e tarefas. Não apresentar DM como recurso funcional.

## Como Utilizar

1. **Identificar telas relacionadas ao marco em desenvolvimento**
2. **Seguir padrões visuais:** navegação, cores, tipografia, espaçamento, componentes e hierarquia
3. **Implementar estados complementares:** carregamento, vazio, erro, acesso negado, confirmação
4. **Adaptar layouts:** dispositivos móveis e uso por teclado
5. **Reutilizar componentes:** Twig, SDC e libraries Drupal

## Relação com a Arquitetura

- **docs/architecture.md:** define regras de negócio, permissões, modelo de dados e contratos
- **docs/screens/:** define referência visual e organização da interface

**Elementos visíveis nas telas não tornam uma funcionalidade automaticamente parte do MVP.**

Se uma tela apresentar recursos previstos para etapas futuras, registrar a diferença antes de implementá-los.

Em caso de conflito, preservar as regras da arquitetura e documentar o ajuste visual necessário.

## Resumo

- **5 arquivos de tela** identificados
- **5 telas** com rota no M9
- **3 recursos** presentes nas telas mas explicitamente fora do MVP (anexos, checklist, DMs)

---

**Última atualização:** 2026-10-28 · US-36

## Conferência de 2026-10-20

A tela em uso usa o shell escuro do protótipo: barra lateral com Meus boards, Conversas, Agentes e Notificações, e o kanban com a cor da coluna. Não copia métricas, mensagens diretas, anexos, checklist, tags nem status "online". O tema `agent_board` empilha a barra e o kanban abaixo de 768px. Os testes funcionais de domínio usam o tema `stark`, então o CSS do tema não é a evidência deles.

O que a tela faz e o protótipo não pede como MVP:

- o card tem o botão **Mover** além do arrastar
- agente aparece como habilitado ou desabilitado, sem status "online"
- conversa vazia diz que não há mensagem e não oferece DM
- board desconhecido responde "A página não foi encontrada", sem o título
- o painel com métricas continua fora do MVP

Não houve auditoria automática de acessibilidade. O que foi visto na sessão local: link "Pular para o conteúdo principal", **Mover** recebe foco, colunas vazias mostram contagem 0.

## Conferência de 2026-10-28

Percurso no Estúdio Norte, com a seleção só na sessão: Dashboard → quadro Pesquisa → tarefa → conversa da tarefa → agente → de volta ao quadro. Nenhuma URL usou `?workspace=`.

Diferenças deliberadas em relação às cinco imagens:

- o dashboard mostra contagens gravadas e abre filtros que já existiam; não mostra custo, percentual nem “online”
- o quadro mantém as cinco colunas e o botão **Mover**, além de **Subir**, **Descer** e **Reordenar**; no telefone as colunas continuam lado a lado e rolam dentro do quadro, sem rolar a página
- a tarefa mostra critérios como texto, sem checklist nem anexos
- a conversa é de quadro ou tarefa; não há mensagem direta
- o agente aparece habilitado ou desabilitado, com a execução gravada; não há status “online”

No viewport de 390px, 768px e 1280px a página não teve overflow horizontal. O link de pular conteúdo existe. **Mover** recebe foco. Com redução de movimento, o skeleton não anima. O contraste do texto secundário sobre o fundo escuro fica acima de 7:1. Pendências posteriores ao M9 seguem na seção 4 de `docs/backlog-ui-m9.md`. Não houve ADR novo.
