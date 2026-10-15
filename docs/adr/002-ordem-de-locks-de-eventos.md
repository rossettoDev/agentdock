# ADR 002: Ordem de locks na gravação de eventos

**Status:** Aceito  
**Data:** 2026-10-15  
**Contexto:** US-07/T02 — Fundação transacional de eventos

## Contexto

A arquitetura exige incrementar um contador por workspace sob lock, na mesma transação de negócio, e gravar evento e outbox antes do commit. Um BIGSERIAL global não serve: a ordem de commit pode furar o cursor de sincronização.

Serviços futuros, como o claim de tarefa, também tomam locks de domínio. Sem uma ordem única, essas transações podem entrar em deadlock com a gravação do evento.

## Decisão

`EventStore::record()` não abre nem confirma transação. O serviço chamador é dono do commit e do rollback.

Ordem de locks:

1. O chamador bloqueia as linhas de domínio que a operação precisa, sempre na mesma ordem entre si. O claim futuro bloqueia agente, depois tarefa e reserva.
2. Só então chama `EventStore::record()`.
3. O store bloqueia `ab_workspace_sequence` daquele workspace com `SELECT FOR UPDATE`.
4. Insere `ab_event`.
5. Insere `ab_outbox`, com destinos ordenados pelo nome.

Nenhum serviço pode bloquear uma linha de domínio depois de ter bloqueado o contador de eventos.

A primeira sequência confirmada de um workspace é 1. O destino padrão da outbox é `sync`. Rollback da transação chamadora desfaz a reserva da sequência, o evento e as linhas da outbox. Só eventos confirmados ficam visíveis.

## Consequências

US-14 e as demais mutações de domínio precisam chamar o store por último, dentro da transação já aberta. O worker de outbox continua fora deste marco.
