# Operação do AgentDock

Versão: 1.0 · Data: 2026-10-20

Instalação do zero continua em `docs/INSTALL.md`. Este texto cobre deploy, worker, rotação, rollback e a restauração que foi executada.

## Deploy

1. Exportar o banco antes de mudar código ou schema. O comando está na seção Restauração.
2. Publicar o commit do release e rodar `lando composer install` (em produção, o equivalente sem o Lando).
3. `lando drush updb -y`
4. `lando drush cim -y` só quando `config/sync` tiver mudado nesse release.
5. `lando drush cr`
6. `lando php tools/health-check.php`
7. Reiniciar o processo `drush ab:work`. Um update de schema no meio de um passe deixa o worker com código velho.

O health check confirma banco, módulos `ab_*`, schema de `ab_event` pelo menos 11002 e a classe do worker. A saída traz contagens, não segredos. Exit 0 é `ok`.

## Worker

```bash
lando drush ab:work
```

O intervalo padrão é 15 segundos. O teto padrão é 128 MiB (`134217728` bytes). `--once` faz um passe e sai. SIGTERM e SIGINT terminam o passe atual. Cron não substitui esse loop.

Não apontar o worker para um banco de ensaio sem querer: ele recupera lease vencida.

## Rotação

Token de agente: emitir outro na tela do agente e revogar o anterior. O segredo novo aparece uma vez. O runner troca a credencial fora do Git.

`DRUPAL_HASH_SALT` e `ab_api_idempotency_key` mudam por ambiente e exigem restart do PHP. Sessões e replays cifrados com a chave antiga deixam de abrir. A chave não entra no repositório.

## Rollback

Update de schema não anda para trás. Código antigo em cima de schema novo não é rollback. O caminho é restaurar o dump feito antes do deploy e só então voltar o código para o commit anterior.

No ensaio abaixo o banco `drupal11` não foi substituído. A cópia ficou em outro database e foi apagada depois do health check.

## Restauração

Dentro do serviço `database`, com o usuário `postgres`:

```bash
lando exec database -- pg_dump -U postgres -d drupal11 --no-owner --no-acl -f /tmp/agentdock-us27.sql
lando exec database -- psql -U postgres -c "DROP DATABASE IF EXISTS agentdock_restore"
lando exec database -- psql -U postgres -c "CREATE DATABASE agentdock_restore"
lando exec database -- psql -U postgres -d agentdock_restore -f /tmp/agentdock-us27.sql
lando exec appserver -- env DB_NAME=agentdock_restore php tools/health-check.php
```

`DB_NAME` já é lido por `web/sites/default/settings.php`. Sem a variável, o site continua em `drupal11`.

Resultado em 2026-10-20, contra a cópia restaurada:

```text
workspaces=3
tasks=4
schema_ab_event=11002
ok
```

O mesmo health check no banco de trabalho, depois de apagar a cópia, repetiu esses números. O dump e o database `agentdock_restore` foram removidos em seguida. O arquivo de dump não entra no Git.
