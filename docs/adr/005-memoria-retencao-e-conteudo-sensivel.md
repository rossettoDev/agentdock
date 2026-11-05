# ADR 005: Retenção e política de conteúdo sensível da memória

**Status:** Aceito  
**Data:** 2026-11-05  
**Contexto:** F2-US-29/T02 — Políticas de memória (Fase 2)

## Contexto

Há três retenções distintas no produto hoje:

| Fluxo | Constante / setting | Janela |
| --- | --- | --- |
| Replay de idempotência sem segredo | `ApiLimits::RETENTION` | 24 h |
| Feed de eventos / cursor | `ApiLimits::EVENT_RETENTION` / `ab_event_retention` | 30 dias |
| Memória (entries + revisões) | *ainda indefinida* | — |

A arquitetura da Fase 2 exige que o conhecimento sobreviva a sessões e execuções, que arquivar não apague histórico, e que tokens, chaves e dados pessoais desnecessários não sejam guardados. Conteúdo recuperado é dado **não confiável**: não executa comando, não altera permissão e não vira instrução de sistema.

## Decisão

### 1. Retenção da memória vs feed

- Entries e revisões são **fonte de verdade durável** no PostgreSQL. A janela de 30 dias do feed **não** apaga memória.
- Eventos `memory.*` no feed seguem a retenção de eventos existente; após o corte, o cursor pode exigir resync, mas a entry continua no domínio.
- Índices de busca lexical (F2-US-35) são projeções reconstruíveis: podem ser refeitos a partir das revisões; expurgo de índice não apaga a entry.

### 2. Arquivamento e exclusão

| Ação | Efeito | Quem (piloto) |
| --- | --- | --- |
| Arquivar | Status `archived`; sai da busca padrão; revisões permanecem | Humano com `review` (ADR 004) |
| Exclusão lógica futura | Fora do piloto mínimo | — |
| Exclusão definitiva (hard delete) | Remove entry, revisões, links, fontes e projeções de índice | Somente owner ou admin do workspace, via operação administrativa explícita futura; **não** no MCP |

No piloto F2-M1–F2-M4: implementar draft / publish / archive. Hard delete e direito de remoção entram quando houver UI/API administrativa dedicada; até lá, arquivar é o mecanismo de retirada de circulação.

Backup e restore do banco restauram memória junto com o restante do domínio. Restore **não** desfaz efeitos externos já causados por agentes que leram a memória antes.

### 3. Limites de tamanho (contrato; detalhe numérico na T03)

Antes da implementação HTTP (F2-US-29/T03 / F2-US-33), o OpenAPI deve fixar:

- tamanho máximo do corpo Markdown por revisão (proposta inicial: **64 KiB**, abaixo do body HTTP de 256 KiB);
- tamanho máximo de título e de tags;
- limite de resultados e de caracteres de excerpt na busca.

Requisição acima do limite → 413, como no restante da API. Estes números são validados no lint do contrato da T03; se o lint ou o uso real exigir ajuste, registra-se no OpenAPI sem reabrir este ADR salvo mudança de ordem de grandeza.

### 4. Política de conteúdo sensível

#### 4.1 Proibido persistir como memória

O serviço de domínio **recusa** (422) create/revise cujo Markdown ou título, após normalização simples, contenha padrões de segredo óbvios. Lista mínima do piloto:

- blocos ou linhas que casem com prefixos de credencial do próprio produto (`ab_` + segmento de token de agente);
- cabeçalhos ou linhas `Authorization: Bearer`;
- chaves PEM (`BEGIN PRIVATE KEY` / `BEGIN RSA PRIVATE KEY`);
- campos nomeados de forma explícita no texto como atribuição de segredo (`api_key=`, `password=`, `secret=`, `lease_token=`) em linha isolada ou trecho delimitado.

A detecção é **heurística de proteção**, não classificador de PII completo. Falsos negativos são esperados; a política operacional complementa (abaixo). Não se armazena o trecho ofensor em log — só o fato da rejeição, com `request_id` / workspace / actor, sem corpo.

#### 4.2 Dados pessoais e mínimos necessários

- Não gravar CPF, números de cartão, credenciais de terceiros ou segredos de infraestrutura em memórias.
- Preferir referências a tarefas/execuções (`source_*`) em vez de colar conversas inteiras.
- Exportações futuras (F2-US-39) devem poder omitir ou redigir campos sensíveis; o piloto não exporta ainda.

#### 4.3 Conteúdo não confiável em runtime

- Memória recuperada é **dado**, não tool call e não system prompt privilegiado.
- Runners e o adaptador MCP devem apresentar o texto como contexto de usuário/recuperação, nunca como instrução que amplie escopos, grants ou workspace.
- Renderização HTML na UI usa o mesmo tipo de sanitização já adotado para tarefas (`TaskMarkdown` / `Xss::filter` na camada de apresentação). O domínio persiste Markdown bruto validado por tamanho e pela heurística 4.1; `ab_memory` **não** depende de `ab_ui`.

#### 4.4 Logs e eventos

- Logs estruturados: `workspace_id`, `actor`, `memory_id`, `revision`, `event_id` — sem corpo Markdown, sem tokens.
- Payload de `EventDraft`: mesmas chaves proibidas já existentes; sem Markdown.
- Idempotência de mutações de memória: retenção padrão de 24 h **sem** segredo de lease (memória não emite `lease_token`).

#### 4.5 Operação humana

Owners/admins orientam agentes e humanos a não colar segredos. Memória publicada com conteúdo inadequado deve ser **arquivada** e, quando a operação administrativa existir, hard-deleted e reindexada. Revogação de membership ou grant nega a **próxima** leitura imediatamente (consultas sempre reavaliam acesso atual; sem cache que preserve corpo após revogação).

### 5. Observabilidade mínima (sem métricas de produto novas nesta task)

Quando houver implementação: contar rejeições por conteúdo sensível e falhas de autorização de memória nos logs de segurança já usados pela API, sem criar dependência de LLM ou serviço externo de DLP.

## Consequências

### Positivas

- Memória sobrevive ao expurgo do feed.
- Há regra explícita contra o antipadrão de guardar bearer/PEM em Markdown.
- Separação clara entre arquivar (piloto) e apagar (futuro).

### Negativas

- Heurísticas não cobrem todo vazamento possível; dependem de disciplina operacional.
- Hard delete adiado pode deixar dado arquivado no banco até a história administrativa.

### Neutras

- Embeddings e retenção de vetores ficam fora; se F2-M5 existir, vetores seguem o ciclo de vida da revisão indexada e são apagados com ela.

## Referências

- `docs/architecture-phase2.md` §§2, 4, 8, 10
- `docs/adr/003-memoria-escopo-e-visibilidade.md`
- `docs/adr/004-memoria-revisao-e-publicacao.md`
- `ApiLimits::RETENTION`, `ApiLimits::EVENT_RETENTION`, `EventDraft`
