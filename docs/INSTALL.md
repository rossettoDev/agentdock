# AgentDock — Guia de Instalação Local

Versão: 1.0 · Data: 2026-10-15

Este guia documenta a instalação completa do projeto AgentDock em ambiente Windows com WSL2 (Ubuntu) + Docker Desktop + Lando.

## Pré-requisitos

### 1. Sistema Operacional

- Windows 10/11 com WSL2 habilitado
- Distribuição Ubuntu no WSL2 (testado com Ubuntu 22.04/24.04)

**Verificar instalação do WSL2:**

```powershell
# No PowerShell do Windows
wsl --list --verbose
```

Se o WSL não estiver instalado, execute:

```powershell
wsl --install
```

### 2. Docker

**Opção A: Docker Desktop (mais simples)**

1. Baixar e instalar [Docker Desktop for Windows](https://www.docker.com/products/docker-desktop/)
2. Nas configurações do Docker Desktop, habilitar a integração com WSL2
3. Selecionar a distribuição Ubuntu para integração

**Opção B: Docker Engine no WSL2 (melhor desempenho)**

Esta opção oferece melhor desempenho, mas requer configuração adicional:

```bash
# Dentro do WSL2 Ubuntu
# Instalar Docker usando o script de conveniência
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Adicionar usuário ao grupo docker
sudo usermod -aG docker $USER

# Recarregar grupos (ou fazer logout/login)
newgrp docker

# Testar instalação
docker --version
docker ps
```

**Importante:** Se usar Docker Engine, NÃO instale Docker Desktop.

### 3. Lando

**Instalação no WSL2:**

```bash
# Dentro do WSL2 Ubuntu
# Baixar script de instalação
curl -fsSL https://get.lando.dev/setup-lando.sh -o setup-lando.sh
chmod u+x setup-lando.sh

# Opção A: Se usar Docker Desktop
./setup-lando.sh

# Opção B: Se usar Docker Engine
./setup-lando.sh --no-setup
```

**Se usar Docker Engine, configurar Lando:**

```bash
# Criar arquivo de configuração global
mkdir -p ~/.lando
cat > ~/.lando/config.yml << 'EOF'
setup:
  buildEngine: false
  skipInstallCa: false
EOF

# Executar setup do Lando
lando setup
```

**Importar certificado SSL no Windows:**

Para HTTPS local funcionar corretamente, importe o certificado CA do Lando no Windows:

```powershell
# No PowerShell do Windows (como Administrador)
# Substitua {seu-usuario} pelo seu usuário do Ubuntu
certutil -addstore -f "ROOT" \\wsl.localhost\Ubuntu\home\{seu-usuario}\.lando\certs\LandoCA.crt
```

**Verificar instalação:**

```bash
lando version
```

### 4. Git

Git já vem instalado no Ubuntu. Configurar credenciais:

```bash
git config --global user.name "Seu Nome"
git config --global user.email "seu@email.com"
```

## Instalação do Projeto

### 1. Clonar Repositório

**IMPORTANTE:** Clone o projeto dentro do filesystem do WSL2 (`/home/usuario/`), não em `/mnt/c/`. Isso é crucial para desempenho.

```bash
# Dentro do WSL2 Ubuntu
cd ~
git clone <url-do-repositorio> agentdock
cd agentdock
```

### 2. Iniciar Ambiente Lando

```bash
# Iniciar serviços (PHP, PostgreSQL, Apache)
lando start
```

Na primeira execução, o Lando irá:
- Baixar imagens Docker necessárias (pode demorar alguns minutos)
- Configurar rede local
- Iniciar serviços

Após conclusão, o Lando exibirá as URLs de acesso:
- Site principal: `https://agentdock.lndo.site`
- Ferramentas: `lando info` para detalhes

### 3. Instalar Dependências

As dependências já estão definidas no `composer.lock` versionado:

```bash
# Instalar dependências PHP via Composer
lando composer install
```

### 4. Criar Extensão PostgreSQL

O Drupal 11 requer a extensão `pg_trgm` no PostgreSQL:

```bash
# Conectar ao PostgreSQL e criar extensão
lando psql -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
```

Verificar instalação:

```bash
lando psql -c "SELECT * FROM pg_extension WHERE extname = 'pg_trgm';"
```

Deve retornar uma linha com `pg_trgm`.

### 5. Instalar Drupal

```bash
# Instalar site Drupal usando Drush
lando drush site:install standard \
  --db-url=pgsql://postgres@database/drupal11 \
  --site-name="AgentDock" \
  --account-name=admin \
  --account-pass=admin \
  --locale=pt-br \
  -y
```

**Credenciais padrão:**
- Usuário: `admin`
- Senha: `admin`

**IMPORTANTE:** Altere a senha do admin imediatamente após instalação.

### 6. Verificar Instalação

```bash
# Verificar status do Drupal
lando drush status

# Verificar versão do Drupal
lando drush eval "echo 'Drupal versão: ' . \Drupal::VERSION . PHP_EOL;"

# Verificar versão do PHP
lando php --version

# Verificar conexão com PostgreSQL
lando drush sql:query "SELECT version();"
```

**Resultados esperados:**
- Drupal: 11.x
- PHP: 8.3.x
- PostgreSQL: 17.x
- Drush: 13.x

### 7. Acessar o Site

Abrir no navegador: https://agentdock.lndo.site

Se aparecer aviso de certificado SSL:
1. Verificar se importou o certificado CA do Lando no Windows
2. Ou aceitar o risco temporariamente (apenas desenvolvimento local)

## Comandos Úteis

### Lando

```bash
# Iniciar ambiente
lando start

# Parar ambiente (preserva dados)
lando stop

# Reiniciar ambiente
lando restart

# Destruir ambiente (remove tudo, dados permanentes devem estar em config)
lando destroy

# Ver informações dos serviços (URLs, credenciais, portas)
lando info

# Ver logs
lando logs
lando logs -s appserver    # Logs do PHP
lando logs -s database     # Logs do PostgreSQL

# Executar comandos dentro do container
lando ssh                  # Shell interativo
lando ssh -c "ls -la"      # Comando único
```

### Composer

```bash
# Instalar dependências
lando composer install

# Atualizar dependências
lando composer update

# Adicionar nova dependência
lando composer require vendor/package

# Remover dependência
lando composer remove vendor/package

# Validar composer.json
lando composer validate
```

### Drush

```bash
# Status do site
lando drush status

# Limpar cache
lando drush cache:rebuild
lando drush cr  # Alias curto

# Executar updates do banco
lando drush updatedb
lando drush updb  # Alias curto

# Login como admin (gera link one-time)
lando drush user:login
lando drush uli  # Alias curto

# Resetar senha de usuário
lando drush user:password admin "nova-senha"
```

### PostgreSQL

```bash
# Conectar ao psql
lando psql

# Executar query
lando psql -c "SELECT * FROM users_field_data LIMIT 5;"

# Dump do banco
lando db-export dump.sql.gz

# Importar dump
lando db-import dump.sql.gz
```

## Gerenciamento de Configuração

### Exportar Configuração

Após fazer alterações pela interface do Drupal (módulos, tipos de conteúdo, views, etc.), exporte a configuração:

```bash
# Exportar toda configuração para config/sync
lando drush config:export
lando drush cex  # Alias curto
```

**IMPORTANTE:** 
- Sempre exporte configurações antes de commitar
- As configurações exportadas ficam em `config/sync/`
- Estes arquivos DEVEM ser versionados no Git

### Importar Configuração

Após clonar o projeto ou fazer pull de alterações:

```bash
# Importar configuração de config/sync
lando drush config:import
lando drush cim  # Alias curto
```

Isso sincroniza o banco de dados com as configurações versionadas.

### Workflow Típico

```bash
# 1. Fazer alterações via interface Drupal

# 2. Exportar configurações
lando drush cex -y

# 3. Verificar mudanças
git status
git diff config/sync/

# 4. Commitar
git add config/sync/
git commit -m "feat: adicionar campo X ao tipo de conteúdo Y"

# 5. Fazer push
git push

# --- Em outra máquina/desenvolvedor ---

# 1. Fazer pull
git pull

# 2. Importar configurações
lando drush cim -y

# 3. Executar updates (se houver)
lando drush updb -y

# 4. Limpar cache
lando drush cr
```

## Variáveis de Ambiente

O projeto lê configurações de variáveis de ambiente. Para desenvolvimento local com Lando, os valores padrão são adequados.

Para customizar, copie `.env.example` para `.env`:

```bash
cp .env.example .env
# Editar .env com suas configurações
```

**IMPORTANTE:** 
- `.env` NÃO deve ser versionado (já está no `.gitignore`)
- `.env.example` deve ser mantido atualizado sem valores sensíveis

### Variáveis Principais

| Variável | Descrição | Padrão Lando |
|----------|-----------|--------------|
| `DB_HOST` | Host do PostgreSQL | `database` |
| `DB_PORT` | Porta do PostgreSQL | `5432` |
| `DB_NAME` | Nome do banco | `drupal11` |
| `DB_USER` | Usuário do banco | `postgres` |
| `DB_PASSWORD` | Senha do banco | `` (vazio) |
| `DRUPAL_HASH_SALT` | Salt de segurança | (temporário) |

### Gerar Hash Salt de Produção

```bash
lando drush eval "var_export(Drupal\Component\Utility\Crypt::randomBytesBase64(55))"
```

Copie o resultado e configure na variável `DRUPAL_HASH_SALT` em produção.

## Estrutura de Diretórios

```
agentdock/
├── .lando.yml                 # Configuração do Lando
├── .env.example               # Exemplo de variáveis (sem segredos)
├── composer.json              # Dependências PHP
├── composer.lock              # Lock de versões (versionado)
├── config/
│   └── sync/                  # Configurações exportadas (versionadas)
├── docs/
│   ├── architecture.md        # Arquitetura do projeto
│   ├── backlog.md             # Backlog de desenvolvimento
│   └── adr/                   # Architecture Decision Records
├── vendor/                    # Dependências (não versionado)
└── web/                       # Webroot
    ├── core/                  # Drupal core (não versionado)
    ├── modules/
    │   ├── contrib/           # Módulos contribuídos (não versionado)
    │   └── custom/            # Módulos customizados (versionados)
    │       ├── ab_core/
    │       ├── ab_board/
    │       └── ...
    ├── themes/
    │   ├── contrib/           # Temas contribuídos (não versionado)
    │   └── custom/            # Temas customizados (versionados)
    │       └── agent_board/
    └── sites/
        └── default/
            ├── settings.php   # Configuração do site (versionado)
            └── files/         # Arquivos do usuário (não versionado)
```

## Troubleshooting

### Erro: "Cannot connect to database"

```bash
# Verificar se serviços estão rodando
lando info

# Verificar logs do PostgreSQL
lando logs -s database

# Recriar extensão pg_trgm
lando psql -c "DROP EXTENSION IF EXISTS pg_trgm; CREATE EXTENSION pg_trgm;"

# Reiniciar Lando
lando restart
```

### Erro: "Permission denied" ao executar Lando

```bash
# Adicionar usuário ao grupo docker
sudo usermod -aG docker $USER
newgrp docker

# Verificar permissões do Docker socket
ls -la /var/run/docker.sock
```

### Site lento no WSL2

**Causa:** Projeto clonado em `/mnt/c/` (filesystem do Windows).

**Solução:** Mover projeto para `/home/usuario/`:

```bash
# Parar Lando no local antigo
cd /mnt/c/Users/usuario/projeto
lando stop

# Mover para WSL filesystem
mv /mnt/c/Users/usuario/projeto ~/agentdock

# Reiniciar no novo local
cd ~/agentdock
lando start
```

### Certificado SSL não confiável

```powershell
# No PowerShell como Administrador
certutil -addstore -f "ROOT" \\wsl.localhost\Ubuntu\home\{usuario}\.lando\certs\LandoCA.crt
```

### Docker Desktop não inicia no WSL2

1. Verificar se virtualização está habilitada na BIOS
2. Verificar se WSL2 está instalado: `wsl --status`
3. Atualizar WSL: `wsl --update`
4. Reiniciar Docker Desktop

### Lando tenta instalar Docker Desktop quando uso Docker Engine

Configurar `~/.lando/config.yml`:

```yaml
setup:
  buildEngine: false
```

## Performance

### Dicas para Melhor Performance no WSL2

1. **Clone dentro do WSL2:** Use `~/agentdock`, não `/mnt/c/`
2. **Memória WSL2:** Edite `%USERPROFILE%\.wslconfig`:

```ini
[wsl2]
memory=8GB
processors=4
swap=2GB
```

3. **Docker Engine vs Desktop:** Docker Engine tem melhor performance
4. **Excluir do Windows Defender:** Adicione diretório WSL às exclusões

## Próximos Passos

Após instalação bem-sucedida:

1. Revisar `docs/architecture.md` para entender o projeto
2. Consultar `docs/backlog.md` para tarefas de desenvolvimento
3. Configurar IDE para trabalhar com WSL2
4. Instalar extensões Drupal recomendadas (se houver)

## Referências

- [Documentação oficial do Lando](https://docs.lando.dev/)
- [Drupal 11 System Requirements](https://www.drupal.org/docs/getting-started/system-requirements)
- [WSL2 Documentation](https://docs.microsoft.com/windows/wsl/)
- [Docker Desktop for Windows](https://docs.docker.com/desktop/windows/)
