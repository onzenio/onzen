# Brainstorm — refine-office-management-ui

## Contexto

- Pedido do usuário: refinar o frontend usando sempre os componentes do template; a gestão de escritórios deve ficar visível somente para o super_admin, dentro de um item colapsável do sidebar igual ao de Settings.
- Estado encontrado na investigação: o sidebar atual já replica o `Settings` colapsável do template via `UNavigationMenu` (`type: 'trigger'` + `children`); o Role chega ao frontend por `/api/me` e as permissões vivem em `usePermissions.ts`; não existe rota, item ou permissão de "Office Management".
- A referência indicada (`docs/research/dashboard`) não existe no workspace; o grupo `Settings` e as telas de tabela existentes no próprio frontend viraram a autoridade executável do template.

Classificação do brainstorming: **arquitetural** (padrão durável de navegação + autorização por Role, não um ajuste isolado).

## Perguntas e decisões

| # | Pergunta | Decisão |
|---|----------|---------|
| 1 | Como agrupar a administração exclusiva do `super_admin` no sidebar? | **Administração** — grupo colapsável próprio com Escritórios, Planos e Auditoria |
| 2 | Além do sidebar, quanto da tela `/accounts` refinar? | **Sidebar + tela completa** — reorganizar a navegação e refazer listar/criar com os padrões do template |
| 3 | O grupo inteiro deve ser exclusivo do `super_admin`? | **Preservar Auditoria** — filhos filtrados: `admin` vê somente Audit; `super_admin` vê os três; demais Roles não veem o grupo |
| 4 | A Account A aparece na tela "Escritórios"? | **Todas as Accounts** — A identificada como Central OneFisc, B como Escritório |
| 5 | Manter só listar/criar ou incluir novas ações? | **Listar e criar** — sem ampliar o backend |
| 6 | Qual abordagem de implementação? | **Abordagem 1** — ajuste direto no shell atual, mantendo rotas e sem componentes próprios |
| 7 | Arquitetura e matriz de visibilidade corretas? | **Aprovado** |
| 8 | Tela, estados e proteção de rota corretos? | **Aprovado** |
| 9 | Desenho completo aprovado como base do plano? | **Aprovado** |

## Restrições registradas

- **UI sem invenção**: compor exclusivamente componentes existentes do Nuxt UI e padrões do template; nenhum componente, estilo global ou biblioteca novos.
- **pt-BR** vinculante para a copy da interface (PRODUCT.md).
- **Terminologia**: "Escritórios" é só rótulo de interface; o domínio e os contratos usam o termo canônico Account (`CONTEXT.md`).
- **Backend inalterado**: endpoints, payloads e Policies existentes; a Policy Laravel permanece a fronteira real de segurança.

## Desenho aprovado em conversa

- Trigger "Administração" no `links` computado do layout, montando primeiro os filhos autorizados e adicionando o grupo só quando houver ao menos um filho; mesmo contrato do `Settings` (accordion expandido/mobile, popover recolhido).
- Matriz: `super_admin` vê Escritórios + Planos + Auditoria; `admin` vê somente Auditoria; `operator`/`user` não veem o grupo.
- Middleware frontend reutilizável de `super_admin` em `/accounts` e `/plans`, redirecionando para `/`; URLs inalteradas.
- Página "Escritórios": colunas Nome, Tipo e Plano (sem ID técnico), badges Central OneFisc/Escritório, paginação do servidor, estados de loading/vazio/erro com retry.
- Modal de criação com aviso de que nasce uma Account B com Invite inicial; limpeza de estado ao fechar; bloqueio de submissão duplicada; erros junto aos campos.

## Fora de escopo (decidido)

- Editar, desativar ou excluir Accounts.
- Busca ou filtros sem suporte completo do backend.
- Alteração das URLs `/accounts`, `/plans` ou `/audit`.
- Refino de outras páginas ou remoção de todos os placeholders do template.
- Restauração da referência ausente `docs/research/dashboard`.

## Riscos levantados

- Referência `docs/research/dashboard` indisponível — mitigado pelo uso do `Settings` e das telas de tabela existentes como verdade executável.
- `admin` com grupo de um único filho (Audit) — mitigado por `defaultOpen: true` e padrão colapsável uniforme.
- Checagem frontend de Role pode defasar com a sessão — mitigado pela fonte compartilhada `/api/me` e pela Policy como autoridade final.
- Account A exibida numa tela chamada "Escritórios" — mitigado pelo badge explícito "Central OneFisc".
