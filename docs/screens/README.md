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
| `Painel AI Board em modo escuro.png` | Dashboard/visão geral do workspace | ⚠️ Evolução | Não identificado em nenhuma US do MVP; pode ser tela futura |

## Divergências Identificadas

### 1. Painel AI Board (Dashboard)

**Status:** ⚠️ **Não está no MVP**

**Descrição:** Tela mostra dashboard geral com métricas, atividade recente e visão consolidada.

**Decisão:** Esta tela não corresponde a nenhuma US do backlog M0-M7. Pode ser:
- Evolução pós-MVP (dashboard com métricas)
- Tela inicial antes de selecionar um board específico
- Visão de workspace (não especificada no MVP)

**Ação:** Não implementar no MVP. Registrar como requisito futuro se necessário.

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
- **4 telas mapeadas para US específicas** do MVP
- **1 tela (Dashboard)** não identificada no backlog do MVP
- **3 recursos** presentes nas telas mas explicitamente fora do MVP (anexos, checklist, DMs)

---

**Última atualização:** 2026-10-20 · US-27/T02

## Conferência de 2026-10-20

A tela em uso não copia o protótipo escuro. O tema `agent_board` empilha o kanban abaixo de 768px. Os testes funcionais de domínio usam o tema `stark`, então o CSS do tema não é a evidência deles.

O que a tela faz e o protótipo não pede como MVP:

- o card tem o botão **Mover** além do arrastar
- agente aparece como habilitado ou desabilitado, sem status "online"
- conversa vazia diz que não há mensagem e não oferece DM
- board desconhecido responde "A página não foi encontrada", sem o título
- o painel com métricas continua fora do MVP

Não houve auditoria automática de acessibilidade. O que foi visto na sessão local: link "Pular para o conteúdo principal", **Mover** recebe foco, colunas vazias mostram contagem 0.
