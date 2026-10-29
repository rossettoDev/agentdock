# AgentDock — status vivo

O que fazer agora. O histórico com transcrições está congelado em `docs/implementation-status.md`. Evidência nova é uma linha aqui, sem colar a saída do PHPUnit.

**Atualizado:** 2026-10-29
**Marco:** Documentação in-site
**Próxima história:** US-38 em `docs/backlog-ui-m10.md` (colunas do quadro).

## Feito no M9

US-29, US-30, US-31, US-32, US-33, US-34, US-35, US-36 e US-37 estão concluídas. M0–M8 estão concluídos no arquivo histórico.

## Última evidência

Documentação, 2026-10-29: `WikiDocumentationTest` criado. Tipo de conteúdo wiki, vocabulário wiki_category, 7 páginas seed, View /docs, padrão Pathauto e estilização no tema. Módulo `ab_documentation` instalável via drush.

Ajustes do quadro, 2026-10-28: `BoardTaskPanelUiTest`, `WorkspaceBoardsUiTest`, `TaskScreensUiTest` e `ScreenFlowUiTest` OK (4 testes, 342 asserções). O link do quadro abre `/boards/{uuid}/tasks`. A tarefa selecionada fica no quadro, com a discussão. Colunas continuam as cinco do fluxo; o CRUD delas é a US-38.

US-36, 2026-10-28: `ScreenFlowUiTest` OK (1 teste, 150 asserções). PHPStan sem erros. Suíte: 170 testes, 10090 asserções; `AgentRunnerTest` e `TaskKanbanMoveUiTest` falharam juntos e passaram sozinhos.

## Como registrar a próxima

Ao fechar uma história, troque a próxima, marque a história como feita nesta página e substitua a última evidência por uma linha: data, arquivos de teste, OK e contagem. Não reabra o arquivo histórico.
