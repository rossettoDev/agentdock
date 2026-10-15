# ADR 001: Stack Tecnológica do Marco M0

**Status:** Aceito  
**Data:** 2026-10-15  
**Contexto:** US-01/T01 — Fundação do projeto AgentDock

## Contexto

Esta é a primeira implementação do projeto AgentDock (nome técnico `agent_board`). Precisamos estabelecer uma stack tecnológica compatível com Drupal 11 e validar os requisitos para desenvolvimento local em ambiente Windows com WSL2.

## Requisitos Oficiais Verificados

### Drupal 11

Consultado em: 2026-10-15  
Fontes oficiais:
- https://www.drupal.org/docs/getting-started/system-requirements/php-requirements
- https://www.drupal.org/docs/getting-started/system-requirements/database-server-requirements
- https://www.drupal.org/project/drupal/releases/11.0.0
- https://www.drupal.org/about/core/blog/drupal-110-will-require-php-83-and-mysql-80

**Versão alvo:** Drupal 11 (versão estável atual, recomendado 11.x mais recente disponível)

**Requisitos mínimos:**
- PHP: 8.3+ (requerido)
  - Extensões: zlib, PDO, pgsql (para PostgreSQL)
  - Drupal 11.1–11.4: suporta PHP 8.3 e 8.4 ✅
  - Drupal 11.3+: suporta PHP 8.5 (futuro)
- PostgreSQL: 16+ (mínimo)
  - Extensão obrigatória: `pg_trgm`
  - Comando de ativação: `CREATE EXTENSION pg_trgm;`
- Composer: 2.7.0+ (mínimo, por questões de segurança)
- Memória PHP: mínimo 64MB (recomendado muito mais para Composer)

### Drush

Consultado em: 2026-10-15  
Fonte: https://github.com/drush-ops/drush/blob/13.x/docs/install.md

**Versão escolhida:** Drush 13.x

**Compatibilidade oficial:**
- Drush 13: PHP 8.3+, Drupal 10.2+ e Drupal 11 ✅ (recomendado)
- Drush 14: PHP 8.3+, Drupal 11.3+ e Drupal 12 ✅ (futuro)

**Justificativa:** Drush 13 é a versão recomendada e suportada para Drupal 11 completo. Drush 14 requer Drupal 11.3+ (ainda não disponível na data de consulta).

### Lando

Consultado em: 2026-10-15  
Fontes:
- https://docs.lando.dev/plugins/drupal/v/stable/config.html
- https://docs.lando.dev/plugins/drupal/v/stable/getting-started.html
- https://github.com/lando/core/issues/315

**Recipe:** drupal11

**Configuração de serviços:**
- PHP: 8.3 (alinhado com requisito mínimo do Drupal 11)
- PostgreSQL: 17.5 (versão mais recente disponível no Lando, compatível com requisito 16+)
- Servidor web: Apache 2.4 (padrão do recipe drupal11)
- Composer: 2-latest

**Particularidades para WSL2 + Docker Desktop:**
- Lando no WSL2 tenta forçar instalação do Docker Desktop por padrão
- Solução: instalar Docker Engine no WSL2 e configurar Lando para não instalar Docker Desktop
- Arquivo de configuração: `~/.lando/config.yml` com `setup.buildEngine: false`

## Decisões

### 1. Versões da Stack Principal

| Componente | Versão | Justificativa |
|------------|--------|---------------|
| Drupal Core | 11.x (estável mais recente) | Versão alvo do projeto conforme arquitetura |
| PHP | 8.3 | Requisito mínimo do Drupal 11; amplamente suportado |
| PostgreSQL | 17 | Versão recente, compatível com requisito mínimo 16+ |
| Drush | ^13 | Versão recomendada e suportada para Drupal 11 completo |
| Composer | 2.7.0+ | Requisito de segurança do Drupal 11 |

### 2. Projeto Composer

**Template escolhido:** `drupal/recommended-project`

**Justificativa:**
- Template oficial recomendado pela comunidade Drupal
- Estrutura padrão com `web/` como webroot
- Separação adequada entre código raiz e código web público
- Suporte nativo ao Composer
- Permite adicionar Drush como dependência (`composer require drush/drush`)

**Estrutura de diretórios resultante:**
```
/
├── composer.json
├── composer.lock    # Versionado (decisão crítica)
├── web/             # Webroot
│   ├── core/
│   ├── modules/
│   ├── themes/
│   └── sites/
├── vendor/          # Não versionado
└── config/          # Configurações exportadas (versionado)
```

### 3. Versionamento do Lockfile

**Decisão:** `composer.lock` DEVE ser versionado no Git.

**Justificativa:**
- Garante builds reproduzíveis entre diferentes desenvolvedores
- Previne "works on my machine" por diferenças de versões de dependências
- Facilita auditorias de segurança e rastreamento de mudanças
- Recomendação oficial do Composer para projetos de aplicação (vs. bibliotecas)

### 4. Ambiente de Desenvolvimento Local

**Nome do app Lando:** `agentdock`

**Configuração `.lando.yml`:**
```yaml
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

**Justificativa:**
- Recipe drupal11: suporte nativo a Drupal 11
- PHP 8.3: requisito mínimo, estável, amplamente testado
- PostgreSQL 17: versão recente (> requisito mínimo 16)
- Apache 2.4: servidor padrão, bem documentado

### 5. Configuração de Banco de Dados

**Decisões de settings.php:**
- Credenciais lidas de variáveis de ambiente
- Fallback para valores padrão do Lando em desenvolvimento
- Prefixo de tabelas: vazio (padrão)
- Schema PostgreSQL: public (padrão)

**Variáveis de ambiente para produção:**
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`

**Valores padrão Lando (desenvolvimento):**
- Host: `database` (nome do serviço)
- Port: `5432`
- Database: `drupal11`
- Username: `drupal11`
- Password: `drupal11`

### 6. Ambiente Alvo do Desenvolvedor

**Plataforma:** Windows com WSL2 (Ubuntu) + Docker Desktop + Lando

**Checkout:** `~/agentdock` (dentro do WSL2)

**Particularidades documentadas:**
1. **Desempenho:** Checkout DEVE ser dentro do filesystem do WSL2 (`/home/usuario/`), não em `/mnt/c/`
   - Motivo: Evitar latência severa entre sistemas de arquivos WSL/Windows
2. **Docker:** Instalar Docker Engine no WSL2, NÃO Docker Desktop
   - Motivo: Melhor desempenho, evitar overhead do Docker Desktop
3. **Configuração Lando:** Adicionar `setup.buildEngine: false` em `~/.lando/config.yml`
   - Motivo: Prevenir instalação automática do Docker Desktop pelo Lando
4. **Certificado SSL:** Importar CA do Lando no Windows para HTTPS local
   - Comando PowerShell: `certutil -addstore -f "ROOT" \\wsl.localhost\Ubuntu\home\{user}\.lando\certs\LandoCA.crt`

## Decisões Pendentes

As seguintes decisões serão tomadas em marcos posteriores:

1. **Redis** (opcional, M6): Versão e papel (cache/queue/lock)
2. **Worker de outbox** (M6): Supervisor vs. systemd vs. Docker
3. **Servidor de produção** (M7): Nginx vs. Apache, tuning PHP-FPM
4. **Ambiente de CI** (US-03/T01): GitHub Actions vs. alternativas
5. **Imagem Docker de produção** (M7): Alpine vs. Debian, multi-stage build

## Validação

A validação completa será realizada em US-01/T02 e US-01/T03.

**Comandos de verificação planejados:**
```bash
# Verificar versões após setup
composer --version  # Deve ser >= 2.7.0
php --version       # Deve ser 8.3.x
lando info          # Verificar serviços ativos

# Instalar Drupal
lando drush site:install --db-url=pgsql://drupal11:drupal11@database/drupal11 -y

# Verificar instalação
lando drush status
lando drush eval "echo 'OK: ' . \Drupal::VERSION;"

# Verificar extensão PostgreSQL
lando psql -c "SELECT * FROM pg_extension WHERE extname = 'pg_trgm';"
```

## Referências

- [Drupal 11 PHP Requirements](https://www.drupal.org/docs/getting-started/system-requirements/php-requirements)
- [Drupal 11 Database Requirements](https://www.drupal.org/docs/getting-started/system-requirements/database-server-requirements)
- [Drupal 11.0.0 Release Notes](https://www.drupal.org/project/drupal/releases/11.0.0)
- [Drush 13 Documentation](https://github.com/drush-ops/drush/blob/13.x/docs/install.md)
- [Lando Drupal 11 Plugin](https://docs.lando.dev/plugins/drupal/v/stable/)
- [Lando + WSL2 Setup](https://github.com/lando/core/issues/315)

## Consequências

### Positivas

- Stack moderna e suportada oficialmente
- Ambiente de desenvolvimento reproduzível
- Documentação oficial abundante
- Compatibilidade com requisitos do projeto

### Negativas

- PHP 8.3 pode exigir atualizações de módulos contrib
- WSL2 adiciona complexidade no setup inicial
- Lando requer ajustes para funcionar bem com Docker Engine

### Neutras

- Lockfile versionado aumenta tamanho do repositório (aceitável)
- PostgreSQL menos comum em tutoriais Drupal (mas bem suportado)

## Notas de Implementação

1. Este ADR deve ser revisado ao atualizar para Drupal 11.3+ (quando disponível)
2. Considerar PHP 8.4 quando Drupal 11 adicionar suporte completo
3. Revisar requisitos antes de cada marco significativo (M1, M2, etc.)
