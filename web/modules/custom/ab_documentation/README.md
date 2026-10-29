# AgentDock Documentation Module

Módulo que fornece documentação in-site para conectar agentes ao AgentDock.

## Funcionalidades

- **Tipo de conteúdo Wiki**: páginas de documentação com campos personalizados
- **Vocabulário de Categoria**: organize páginas por seção (Começar, Agentes, API e MCP, Operação)
- **Roteamento automático**: URLs amigáveis em `/docs/[titulo]` via Pathauto
- **View de listagem**: página `/docs` agrupa páginas por categoria
- **Conteúdo seed**: documentação inicial sobre criação de agentes, tokens, MCP e operação
- **Estilização**: CSS integrado ao tema agent_board

## Instalação

1. Adicione as dependências Pathauto e Token ao composer:
   ```bash
   composer require drupal/pathauto drupal/token
   ```

2. Habilite o módulo:
   ```bash
   drush en ab_documentation -y
   ```

3. Importe a configuração (se ainda não estiver):
   ```bash
   drush cim -y
   ```

4. Limpe o cache:
   ```bash
   drush cr
   ```

## Uso

### Criar nova página wiki

1. Acesse **Conteúdo → Adicionar conteúdo → Wiki**
2. Preencha:
   - **Título**: título da página
   - **Corpo**: conteúdo em HTML (formato Full HTML recomendado)
   - **Resumo**: descrição curta opcional
   - **Categoria**: selecione uma categoria existente
   - **Peso**: ordem de exibição (menor número = primeiro na lista)
3. Salve

A URL será gerada automaticamente como `/docs/[titulo]`.

### Visualizar documentação

Acesse `/docs` para ver a listagem completa agrupada por categoria.

## Estrutura

- **Content type**: `wiki` (machine name)
- **Vocabulary**: `wiki_category` (vid)
- **Fields**:
  - `field_summary` (string_long): resumo da página
  - `field_wiki_category` (entity_reference): referência à categoria
  - `field_wiki_weight` (integer): peso para ordenação
  - `body` (text_with_summary): corpo da página
- **View**: `wiki_docs` disponível em `/docs`
- **Pathauto pattern**: `/docs/[node:title]`

## Conteúdo seed

O módulo cria automaticamente as seguintes páginas na instalação:

1. **Documentação** (landing page)
2. **Criar um agente** (Começar)
3. **Emitir token** (Começar)
4. **Adapter stdio** (API e MCP)
5. **Ciclo de uma tarefa** (API e MCP)
6. **Acesso externo** (Operação)
7. **Aprovar ou rejeitar** (Operação)

Todas em português brasileiro, alinhadas com a arquitetura do AgentDock.

## Permissões

Por padrão:
- **Ver conteúdo publicado**: usuários autenticados
- **Criar/editar wiki**: usuários com permissão de administrar conteúdo
- **Administrar taxonomia**: administradores

Ajuste as permissões em **Pessoas → Permissões** conforme necessário.

## Estilização

O CSS está em `web/themes/custom/agent_board/css/wiki.css` e usa as variáveis CSS do tema:
- `--surface-low`, `--text-secondary` para contraste
- `--space-*` para espaçamento consistente
- `--radius-m` para bordas arredondadas
- `--primary` para links

## Manutenção

Para atualizar o conteúdo seed:
- Edite as páginas diretamente pela interface em **Conteúdo**
- Ou modifique as funções helper em `ab_documentation.install` e execute novamente (após desinstalar e reinstalar o módulo)

## Desinstalação

```bash
drush pmu ab_documentation -y
```

**Atenção**: isso removerá todas as páginas wiki e categorias criadas.
