A F2-US-29/T01 está concluída. O módulo ab_memory não foi criado e nenhum serviço da Fase 1 foi alterado.

O identificador US-29/T01 já pertence ao M9 (matriz de telas, done). Esta task da Fase 2 ficou registrada como F2-US-29/T01 para não reabrir essa história.

Arquivos
Criado: docs/phase2/F2-US-29-T01-inventario.md
Atualizado: docs/implementation-status.md (seção nova da Fase 2)
Preservado: docs/US-29-T01-matriz.md
Serviços reutilizáveis
A memória deve chamar estes serviços, sem copiar a regra:

Identidade: ActorContext, HumanActorContextFactory, AgentActorContextFactory, ApiCaller. O autor sai da sessão ou do bearer.
Humano: WorkspaceRolePolicy, MembershipScope, WorkspaceBoundary. Fora de escopo é ScopedResourceNotFoundException (404). Operação proibida em recurso visível é ScopedOperationDeniedException (403).
Agente: AgentOperationGate e AgentGrantManager. O grant é por board e só aceita read, write e review. O ActorContext do agente nasce com permissões vazias; allows() não autoriza agente.
Eventos: EventStoreInterface::record() na transação do chamador, depois dos locks de domínio (ADR 002). O payload não leva Markdown nem as chaves token, secret, password, authorization e lease_token.
API e MCP, nas histórias seguintes: IdempotencyService, ApiCaller::ifMatch(), ProblemDetails, ApiLimits. O adaptador em tools/mcp-adapter só encaminha HTTP e recusa author_id, agent_id e workspace_id.
Interface, na história da central: WorkspaceContext (sessão, sem ?workspace=) e TaskMarkdown para renderizar.
WorkspaceAccessPolicy, citada na arquitetura da Fase 1, não existe. O papel está em WorkspaceRolePolicy, MembershipScope, WorkspaceBoundary e WorkspaceQueryScope.

Encaixe proposto
ab_memory dependeria de ab_core, ab_event, ab_board e ab_agent, com MemoryAccessPolicy, MemoryService e MemoryRepository. ab_api e ab_ui só passariam a depender dele nas histórias de API e de tela. ab_agent não pode depender de ab_memory. Memória com board usa AgentOperationGate::assert(). O evento usa a chave board_id, que é a chave que SyncReader filtra.

Validação
Conferência dos 45 caminhos citados no inventário: 45 encontrados, 0 ausentes. ab_memory e WorkspaceAccessPolicy.php continuam ausentes, como esperado. Nenhum PHP foi alterado. PHPUnit, PHPCS e PHPStan não foram executados.

Riscos
Os IDs US-29 a US-37 da Fase 2 colidem com o M9. O status precisa do prefixo F2-.
Não há grant de agente sem board nem escopo memory:*. Memória só de workspace não passa pelo AgentOperationGate. A política fica para a T02.
Evento sem board_id fica visível a todo humano do workspace e oculto para todo agente.
WorkspaceQueryScope não cobre entidade nova. A query de memória filtra o workspace no repositório.
Próxima task
F2-US-29/T02 — ADRs de escopo, visibilidade, revisão, publicação e retenção. Aguardo autorização antes de iniciá-la. O M9 continua na US-34.