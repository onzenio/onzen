## Context

See `proposal.md` - Why. O shell atual já usa a árvore canônica `UDashboardGroup` -> `UDashboardSidebar` -> `UNavigationMenu`, e o item Settings demonstra o comportamento colapsável que deve ser preservado. A navegação é computada em `frontend/app/layouts/default.vue` com permissões derivadas de `/api/me`; Accounts, Plans e Audit aparecem hoje como itens primários separados.

A página `frontend/app/pages/accounts.vue` já consome os endpoints paginados de listagem e criação, mas mistura copy em inglês, mostra o ID técnico e não cobre explicitamente vazio ou erro. Não existe suíte de testes frontend; lint, typecheck e build formam o gate automatizado disponível. A referência externa `docs/research/dashboard` não está presente no workspace, então o Settings e as telas de tabela existentes no próprio frontend são a autoridade executável do template.

## Goals / Non-Goals

**Goals:**

- Preservar o shell e compor somente componentes existentes do template Nuxt UI.
- Tornar a hierarquia administrativa previsível em todos os estados responsivos do sidebar.
- Impedir a exposição frontend das rotas de plataforma a Roles sem acesso.
- Refinar a gestão de Accounts sem alterar endpoints, payloads ou regras de domínio.

**Non-Goals:**

- Criar uma arquitetura genérica de menus ou extrair componentes para uma única tela.
- Alterar Policies, recursos Laravel, paginação ou contratos HTTP.
- Adicionar ações de edição, exclusão, desativação, busca ou filtro.
- Redesenhar Settings, Plans, Audit ou outras áreas do produto.

## Decisions

### Manter a configuração de navegação no layout

O array computado existente continuará sendo a fonte da navegação. Ele montará primeiro os filhos autorizados e adicionará o trigger Administração somente quando houver ao menos um filho. O trigger reutilizará o mesmo contrato do Settings, incluindo filhos sem ícones, fechamento explícito no mobile, accordion quando expandido e popover quando recolhido.

Alternativa considerada: extrair uma configuração global de rotas e permissões. Foi rejeitada porque o template mantém a navegação no layout e a abstração adicionaria indireção sem outro consumidor real.

### Filtrar filhos em vez de esconder o grupo inteiro

Accounts e Plans continuarão condicionados às permissões exclusivas do super_admin; Audit continuará disponível ao admin. Assim, o super_admin verá três filhos, o admin verá somente Audit e os demais Roles não verão o grupo.

Alternativa considerada: tornar todo o grupo exclusivo do super_admin. Foi rejeitada porque removeria do admin a navegação para o Audit da própria Account, hoje autorizado pelo domínio e pelo backend.

### Manter URLs e proteger rotas com middleware nomeado

As URLs `/accounts`, `/plans` e `/audit` não mudarão. Um middleware frontend reutilizável verificará o Role carregado por `/api/me`; `/accounts` e `/plans` declararão esse middleware e redirecionarão Roles não autorizados para `/`. As Policies Laravel permanecerão a fronteira de segurança e continuarão respondendo 403 a chamadas indevidas.

Alternativa considerada: mover as páginas para `/admin/*`. Foi rejeitada por exigir redirects e migração de links sem ganho funcional para este escopo.

### Refinar Accounts como uma única página composta

A página continuará concentrando tabela, paginação e modal porque não há reuso que justifique novos componentes. O painel usará copy em PT-BR, removerá o ID técnico, renderizará nome, tipo e Plan e usará badges semânticos para distinguir central OneFisc e escritório. Os estilos de tabela seguirão a tabela Customers do template, com cabeçalho tonal, bordas semânticas e rolagem segura.

O modal será controlado explicitamente para limpar valores e erros ao abrir ou fechar. A descrição informará que uma Account B e um Invite inicial serão criados. O estado pendente bloqueará nova submissão; erros de formulário permanecerão próximos dos campos e erros de listagem usarão feedback persistente com retry.

Alternativa considerada: extrair um componente de modal e outro de tabela. Foi rejeitada porque ambos teriam um único uso e fragmentariam o fluxo sem reduzir complexidade.

### Preservar paginação exclusivamente no servidor

A página continuará enviando apenas `page` e exibindo a paginação retornada pela API. Nenhum filtro local será mostrado, pois filtrar somente a página carregada produziria resultados enganosos.

Alternativa considerada: copiar a busca client-side da tela Customers do template. Foi rejeitada porque Customers recebe a coleção completa, enquanto Accounts é paginado no servidor.

## Risks / Trade-offs

- [Risk] O admin verá um grupo com apenas o filho Audit, o que adiciona um clique em relação ao item atual. -> Mitigação: manter o mesmo padrão colapsável em todos os Roles autorizados e abrir o grupo por padrão, preservando previsibilidade.
- [Risk] A checagem frontend de Role pode ficar desatualizada durante mudança de sessão. -> Mitigação: usar a fonte compartilhada `/api/me` e manter a Policy Laravel como autoridade final.
- [Risk] Redirecionar sem renderizar a página restrita pode ocultar o motivo do bloqueio. -> Mitigação: a navegação já remove itens proibidos e o redirect evita expor uma tela quebrada; respostas de API continuam fornecendo 403 para clientes diretos.
- [Risk] A Account A é apresentada em uma tela chamada Escritórios. -> Mitigação: identificá-la explicitamente como "Central OneFisc" e reservar "Escritório" às Accounts B.
- [Risk] A referência `docs/research/dashboard` pode continuar indisponível. -> Mitigação: usar os padrões do Settings, Customers e componentes Nuxt UI já instalados como verdade executável, sem criar alternativas visuais.

## Migration Plan

1. Publicar o frontend com a nova árvore de navegação, o middleware e a página refinada; não há migração de dados ou mudança de API.
2. Validar os quatro Roles em desktop e mobile antes do deploy.
3. Em rollback, reverter somente os arquivos frontend deste change; URLs, APIs e dados permanecem compatíveis.
