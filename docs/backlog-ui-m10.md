# Backlog — colunas do quadro

Status: aberto. O M9 continua fechado. Esta história existe porque criar, renomear e apagar colunas muda o fluxo gravado da tarefa.

## US-38 — Colunas do criador do quadro

Como criador ou administrador do quadro, quero criar, renomear, reordenar e arquivar colunas, para o quadro refletir o trabalho do estúdio.

O quadro de hoje tem cinco colunas fixas: backlog, ready, in_progress, in_review e done. Cancelada fica no filtro. `in_progress` continua vindo de claim, `in_review` de submit e `done` de aprovação humana. Arrastar um card não pode inventar esses três estados.

Antes de implementar:

- Entidade da coluna, migração e quadro existente com as cinco colunas atuais.
- API, permissão no workspace e eventos.
- Testes de quem pode alterar a coluna e de quem recebe 404 fora do workspace.
- O arraste continua obedecendo a transição já permitida.

Fora desta história: porcentagem, checklist, anexos, mensagens diretas, custo e agente online.
