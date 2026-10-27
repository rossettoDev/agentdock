# AgentDock — instruções para o agente

No M9, leia este arquivo, `docs/status.md` e só o bloco da história atual em `docs/backlog-ui-m9.md`. Não abra `docs/backlog.md`, o corpo de `docs/implementation-status.md`, os ADR nem as evidências do MCP, salvo se a história citar um trecho.

## Ritmo

Uma história por interação. US-34, US-35 e US-36 fecham cada uma num commit `feat(m9):`, em português, focado no porquê. Não comece a história seguinte. Não faça push. Não altere a configuração do git.

Evidência nova vai em `docs/status.md`: uma linha por história, com o arquivo de teste, OK e a data. Não cole a saída do PHPUnit. Não atualize `docs/architecture.md` nem o backlog de M0–M8 no meio do M9. `docs/implementation-status.md` está congelado.

## Testes

O PHP do host é 8.1. Os testes rodam no Lando, em PHP 8.3:

```bash
lando exec appserver -- env \
  SIMPLETEST_DB='pgsql://postgres:@database:5432/drupal11' \
  SIMPLETEST_BASE_URL=http://appserver \
  php vendor/bin/phpunit CAMINHO
```

Por história: PHPUnit dos testes dos arquivos tocados, PHPCS nesses arquivos e o browser só na tela que mudou. PHPStan e a suíte inteira ficam para a US-36.

```bash
lando php vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,yml CAMINHOS
```

Avisos de `@group` em docblock são esperados. Falha isolada de login na suíte cheia, em `WorkspaceUiTest`, `WorkspaceMembersUiTest` ou `WorkspaceBoardsUiTest`, não é defeito do produto se o teste passa sozinho.

## Invariantes

O workspace é a fronteira de autorização. A seleção fica na sessão. Não use `?workspace=`. Fora de escopo responde 404. Operação proibida num recurso visível responde 403. O ator é a sessão ou o bearer, nunca um `author_id` ou `agent_id` do payload.

`in_progress` vem de claim, `in_review` de submit e `done` de aprovação humana. Não crie execução falsa para mover um card. Não declare agente online. Não implemente métricas, DMs, anexos, checklist nem tags. O dashboard só entra na US-35.

Não rode `drush ab:work` no banco local: o recover interrompe a lease vencida da verificação. Não apague, reivindique nem revise as tarefas do Quadro Casa nem do quadro Verificacao US-16. Não cancele a execução de "Duvida da verificacao".

Não imprima bearer, lease, token nem a chave de idempotência. Não versione `web/sites/simpletest/` nem `settings.local.php`. A interface do site é pt-br.
