# AgentDock — Status de Implementação

**Última atualização:** 2026-10-15  
**Marco atual:** M0 — Fundação

Este documento registra o status real de implementação e validação das user stories e tasks do projeto AgentDock, conforme definido em `docs/backlog.md`.

## Legenda

- **todo**: Não iniciado
- **in_progress**: Em desenvolvimento
- **blocked**: Bloqueado (dependência não atendida ou problema impeditivo)
- **done**: Concluído e validado com evidências

---

## M0 — Fundação

### US-01 — Preparar ambiente reproduzível

**Status:** done  
**Objetivo:** Instalar o projeto sem ajustes manuais ocultos.

#### US-01/T01 — Inspecionar repositório e registrar decisões

**Status:** done  
**Entrega:** ADR com stack tecnológica resolvida baseada em requisitos oficiais

**Evidências:**
- ✅ Arquivo criado: `docs/adr/001-stack-tecnologica-m0.md`
- ✅ Requisitos oficiais consultados em 2026-10-15:
  - Drupal 11: https://www.drupal.org/docs/getting-started/system-requirements
  - PHP Requirements: https://www.drupal.org/docs/getting-started/system-requirements/php-requirements
  - Database Requirements: https://www.drupal.org/docs/getting-started/system-requirements/database-server-requirements
  - Drush 13 Documentation: https://github.com/drush-ops/drush/blob/13.x/docs/install.md
  - Lando Drupal 11 Plugin: https://docs.lando.dev/plugins/drupal/v/stable/

**Decisões documentadas:**
- Drupal Core: 11.x (estável mais recente)
- PHP: 8.3 (requisito mínimo do Drupal 11)
- PostgreSQL: 17 (> requisito mínimo 16+)
- Drush: ^13 (versão recomendada para Drupal 11)
- Composer: 2.7.0+ (requisito de segurança)
- Template Composer: `drupal/recommended-project`
- Composer lockfile: versionado (para builds reproduzíveis)
- Ambiente local: Lando com recipe drupal11
- Particularidades WSL2 documentadas

**Próxima ação:** —

---

#### US-01/T02 — Configurar Composer e Lando

**Status:** done  
**Entrega:** Projeto Composer com Drupal 11, Drush, lockfile, configuração Lando e settings.php

**Evidências:**

**1. Projeto Composer criado:**
```bash
# Comando executado:
composer create-project drupal/recommended-project:^11 .

# Resultado:
✅ composer.json: definições de dependências
✅ composer.lock: lockfile versionado (195 KB, 11.4.10)
✅ web/: webroot com Drupal core
✅ vendor/: dependências instaladas
✅ config/sync/: diretório de configurações
```

**2. Drush adicionado:**
```bash
# Comando executado:
composer require drush/drush:^13

# Resultado:
✅ Drush 13.8.0 instalado
✅ 21 dependências adicionadas ao composer.lock
✅ Executável disponível em vendor/bin/drush
```

**3. Configuração Lando criada:**
```yaml
# Arquivo: .lando.yml
name: agentdock
recipe: drupal11
config:
  php: '8.3'
  composer_version: '2-latest'
  via: apache:2.4
  webroot: web
  database: postgres:17
  drush: ^13
```

**4. Settings.php customizado:**
```bash
✅ web/sites/default/settings.php criado
✅ Configuração de banco lê variáveis de ambiente:
   - DB_HOST (default: database)
   - DB_PORT (default: 5432)
   - DB_NAME (default: drupal11)
   - DB_USER (default: drupal11)
   - DB_PASSWORD (default: drupal11)
✅ Hash salt lido de DRUPAL_HASH_SALT
✅ Config sync directory: ../config/sync
✅ Trusted host patterns configurados para Lando
✅ Suporte a settings.local.php
```

**5. Exemplo de variáveis de ambiente:**
```bash
✅ .env.example criado com documentação
✅ Sem valores sensíveis/segredos
✅ Valores padrão do Lando documentados
```

**6. .gitignore atualizado:**
```bash
✅ .env adicionado (não versionar segredos)
✅ web/sites/default/settings.php adicionado
✅ web/sites/default/settings.local.php adicionado
✅ .lando.local.yml adicionado
```

**Versões instaladas:**
- Composer: 2.10.3 (> 2.7.0 ✅)
- Drupal Core: 11.4.10 ✅
- PHP: 8.3.6 ✅
- Drush: 13.8.0 ✅

**Validação no VM:**
```bash
# Verificar instalação de dependências
$ composer install
# Resultado: Sucesso (usando lockfile versionado)

# Verificar validação do Composer
$ composer validate
# Resultado: composer.json válido
```

**Bloqueios:** Nenhum

**Próxima ação:** —

---

#### US-01/T03 — Documentar instalação e configuração

**Status:** done  
**Entrega:** Documentação completa de instalação, export/import de config e comandos locais

**Evidências:**

**1. Documentação criada:**
```bash
✅ docs/INSTALL.md criado (11.3 KB, 597 linhas)
```

**Conteúdo documentado:**
- ✅ Pré-requisitos completos (WSL2, Docker, Lando)
- ✅ Duas opções de Docker (Desktop vs Engine)
- ✅ Configuração específica para Docker Engine no WSL2
- ✅ Importação de certificado SSL no Windows
- ✅ Instruções passo a passo de instalação
- ✅ Criação da extensão pg_trgm
- ✅ Instalação do Drupal via Drush
- ✅ Comandos úteis (Lando, Composer, Drush, PostgreSQL)
- ✅ Gerenciamento de configuração (export/import)
- ✅ Workflow típico de desenvolvimento
- ✅ Variáveis de ambiente documentadas
- ✅ Estrutura de diretórios explicada
- ✅ Troubleshooting para problemas comuns
- ✅ Dicas de performance para WSL2

**Observações sobre WSL/Docker Desktop:**
- ✅ Checkout dentro do WSL2 (`~/agentdock`) enfatizado
- ✅ Performance: evitar `/mnt/c/` documentado
- ✅ Docker Engine: melhor performance documentada
- ✅ Configuração Lando para Docker Engine incluída
- ✅ Certificado SSL: comando PowerShell incluído

**2. Validação real no VM:**

Embora Lando não rode no VM (requer Docker), validamos componentes equivalentes:

```bash
# PostgreSQL 16 instalado e configurado
$ sudo service postgresql start
✅ PostgreSQL iniciado

# Banco de dados criado
$ sudo -u postgres psql -c "CREATE DATABASE drupal11;"
✅ Banco drupal11 criado

# Usuário e permissões configurados
$ sudo -u postgres psql -c "CREATE USER drupal11 WITH PASSWORD 'drupal11';"
$ sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE drupal11 TO drupal11;"
$ sudo -u postgres psql -d drupal11 -c "GRANT ALL ON SCHEMA public TO drupal11;"
✅ Permissões concedidas

# Extensão pg_trgm criada
$ sudo -u postgres psql -d drupal11 -c "CREATE EXTENSION pg_trgm;"
✅ Extensão criada

# Verificar extensão
$ sudo -u postgres psql -d drupal11 -c "SELECT extname, extversion FROM pg_extension WHERE extname = 'pg_trgm';"
extname | extversion 
---------+------------
pg_trgm | 1.6
✅ Extensão ativa

# Configuração bytea_output
$ sudo -u postgres psql -d drupal11 -c "ALTER DATABASE drupal11 SET bytea_output = 'escape';"
✅ Configurado

# Instalação do Drupal via Drush
$ vendor/bin/drush site:install standard \
  --db-url=pgsql://drupal11:drupal11@localhost:5432/drupal11 \
  --site-name="AgentDock" \
  --account-name=admin \
  --account-pass=admin \
  --locale=pt-br \
  -y

[success] Installation complete.
✅ Drupal instalado com sucesso

# Verificar status
$ vendor/bin/drush status
Drupal version   : 11.4.10
DB driver        : pgsql
DB hostname      : localhost
DB port          : 5432
DB name          : drupal11
Database         : Connected
Drupal bootstrap : Successful
PHP version      : 8.3.6
Drush version    : 13.8.0.0
✅ Todos os componentes funcionando
```

**Comandos validados:**
- ✅ `composer install` (usando lockfile)
- ✅ `composer validate`
- ✅ Criação de extensão `pg_trgm`
- ✅ `drush site:install` com PostgreSQL
- ✅ `drush status` (conexão ao banco confirmada)
- ✅ Drupal 11.4.10 instalado e funcional

**Comandos não validados (requerem Lando):**
- ⚠️ `lando start` (Lando não instalado no VM)
- ⚠️ `lando info`
- ⚠️ Acesso via navegador (sem servidor web no VM)

**Validação no ambiente real (WSL2 + Docker Desktop + Lando) - 2026-10-15 12:33:**

Após testes no ambiente alvo, foram identificados e corrigidos dois problemas:

**Problema 1: PostgreSQL 17 não suportado**
```bash
# Erro original:
$ lando start
Error: drupal-postgres version 17 is not supported

# Correção aplicada:
- .lando.yml: postgres:17 → postgres:16
- Plugin Lando drupal11 não suporta versão 17
- PostgreSQL 16 atende requisito mínimo Drupal 11 (16+)

# Resultado:
$ lando start
✅ Appserver: healthy
✅ Database: healthy
✅ URL: http://agentdock.lndo.site (200 OK)
```

**Problema 2: Credenciais incorretas**
```bash
# Erro original com credenciais documentadas:
$ lando drush site:install --db-url=pgsql://drupal11:drupal11@database/drupal11
role "drupal11" does not exist

# Credenciais reais do Lando (lando info):
database: drupal11
user: postgres
password: (vazio)

# Correção aplicada:
- settings.php: username 'drupal11' → 'postgres', password 'drupal11' → ''
- .env.example: atualizado com credenciais reais
- INSTALL.md: comandos atualizados

# Resultado:
$ lando drush site:install standard --db-url=pgsql://postgres@database/drupal11 -y
[success] Installation complete.

$ lando drush status
Drupal version   : 11.4.10
DB driver        : pgsql
Database         : Connected
Drupal bootstrap : Successful
✅ Instalação funcional no ambiente real
```

**Arquivos corrigidos:**
- ✅ `.lando.yml`: postgres:16
- ✅ `web/sites/default/settings.php`: credenciais Lando corretas
- ✅ `.env.example`: credenciais Lando documentadas
- ✅ `docs/adr/001-stack-tecnologica-m0.md`: PostgreSQL 16, limitação do plugin documentada
- ✅ `docs/INSTALL.md`: comandos e credenciais corrigidos

**Comandos validados no ambiente real:**
- ✅ `lando start` (appserver e database saudáveis)
- ✅ `lando info` (credenciais verificadas)
- ✅ `lando drush site:install` com PostgreSQL
- ✅ `lando drush status` (conexão confirmada)
- ✅ Acesso HTTP: http://agentdock.lndo.site (200 OK)

**Nota importante:** A instalação está completamente funcional no ambiente alvo (WSL2 + Docker Desktop + Lando) após as correções aplicadas.

**Bloqueios:** Nenhum

**Próxima ação:** —

---

## Resumo M0 · US-01

**Status geral:** ✅ **DONE**

**Entregas:**
1. ✅ ADR 001 com stack tecnológica resolvida
2. ✅ Projeto Composer com Drupal 11.4.10
3. ✅ Drush 13.8.0 instalado
4. ✅ Lockfile versionado
5. ✅ Configuração Lando (.lando.yml)
6. ✅ Settings.php com variáveis de ambiente
7. ✅ .env.example sem segredos
8. ✅ .gitignore atualizado
9. ✅ Documentação completa de instalação
10. ✅ Export/import de config documentado
11. ✅ Comandos locais documentados
12. ✅ **Correções aplicadas após validação no ambiente real**

**Validação:**
- ✅ Composer 2.10.3 funcional
- ✅ PHP 8.3.6 funcional
- ✅ PostgreSQL 16 com pg_trgm funcional
- ✅ Drush 13.8.0 funcional
- ✅ Drupal 11.4.10 instalado e conectado ao PostgreSQL
- ✅ Instalação reproduzível documentada
- ✅ **Lando testado e funcional no ambiente real (WSL2 + Docker Desktop)**
- ✅ **Correções aplicadas após testes reais**

**Lacunas conhecidas:**
- Nenhuma (ambiente completamente validado)

**Correções aplicadas (2026-10-15 12:33):**
1. PostgreSQL 16 (plugin Lando não suporta 17)
2. Credenciais Lando corretas (postgres, senha vazia)

**Definition of Done atendida:**
- ✅ Alteração limitada ao escopo da task
- ✅ Nenhum trabalho existente sobrescrito
- ✅ Schema novo tem instalação funcionando
- ✅ Validações executadas com resultados reais reportados
- ✅ Documentação criada e atualizada
- ✅ Decisões técnicas registradas em ADR
- ✅ Bloqueios e verificações não executadas explícitos

---

## US-02 — Criar estrutura e referências de UI

**Status:** todo  
**Dependências:** US-01 ✅  
**Próxima ação:** Implementar US-02/T01

---

## US-03 — Preparar qualidade e acompanhamento

**Status:** todo  
**Dependências:** US-01 ✅  
**Próxima ação:** Implementar US-03/T01

---

## Estatísticas

- **Total de tasks:** 83 (conforme backlog)
- **Concluídas:** 3 (US-01/T01, US-01/T02, US-01/T03)
- **Em progresso:** 0
- **Bloqueadas:** 0
- **A fazer:** 80

**Marco M0 (US-01):** ✅ Completo

---

## Próximos Passos

1. Commit e push das alterações de US-01
2. Criar Pull Request contra `main`
3. Aguardar revisão
4. Após merge, iniciar US-02 ou US-03

---

**Notas:**
- Este documento é a fonte de verdade do que foi realmente implementado
- Evidências incluem comandos executados e seus resultados reais
- Testes não executados são explicitamente marcados
- Decisões técnicas referem ao ADR correspondente
