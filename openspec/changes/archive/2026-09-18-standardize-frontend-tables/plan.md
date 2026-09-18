# plan.md — standardize-frontend-tables

> Detalhe de execução. O contrato de escopo é `tasks.md` — este plano apenas
> ordena, detalha arquivos/comandos e define verificação por lote. Em caso de
> conflito, vale `tasks.md`. Segue `openspec/config.yaml` (regras de `tasks`,
> `design` e `operations/apply`).

## 1. Vínculo contratual e ponto de partida

| Task | Resumo | Coberta no lote |
|------|--------|-----------------|
| 1.1 | Chrome client-side em PT-BR igual em todas as tabelas reais | A |
| 1.2 | Estados carregamento/vazio/erro em PT-BR com retry | B |
| 1.3 | Telas fictícias de template fora da migração | A |
| 2.1 | Blocos por domínio + chrome compartilhado | C |
| 2.2 | Formatação PT-BR e ações por linha com gating por permissão | C |
| 3.1 | Composables reutilizáveis + CSV | D |
| 3.2 | `lint` + `typecheck` + `build` verdes | E (final) |

Ponto de partida verificado em 2026-09-15:

- Molde comportamental intocável: `frontend/app/pages/customers.vue` (inglês,
  dados fictícios — referência, nunca migrar).
- Referência real: `frontend/app/pages/clients.vue` — já no padrão
  (busca razão social/CNPJ, filtro regime, ordenação, seleção, visibilidade,
  paginação client-side, CSV, `can('clients.write')`, estado vazio PT-BR).
- Parcialmente migrada: `frontend/app/pages/monitoring/enrollments/[id].vue`
  (ver `git log --oneline`: `4d3021f`, `98b6bbd`).
- Ainda divergentes: `frontend/app/pages/accounts.vue`,
  `frontend/app/pages/plans.vue`, `frontend/app/pages/audit.vue`,
  `frontend/app/pages/monitoring/index.vue`,
  `frontend/app/pages/monitoring/admin.vue`,
  `frontend/app/pages/monitoring/certificado.vue`,
  `frontend/app/pages/monitoring/parcelamentos/index.vue`,
  `frontend/app/pages/monitoring/parcelamentos/[id].vue`.
- Utilitários a reutilizar (não recriar): `frontend/app/utils/export-csv.ts`
  (`exportToCsv`), `frontend/app/utils/backend-error.ts`
  (`backendMessage`/`backendFormErrors`), formatação PT-BR existente
  (`formatCnpj`, moeda `pt-BR`, datas).
- Sem suite de testes no frontend (CI = `lint` + `typecheck`, sem Vitest).
  O ciclo "failing-first" aqui é: reproduzir o desvio observável
  (estado/comportamento divergente ou `typecheck` acusando) antes de corrigir;
  não inventar infra de teste — fora de escopo da proposta.

Glossário canônico (`CONTEXT.md`): Account, Plan, Client, Role
(`super_admin`, `admin`, `operator`, `user`), Audit, Onboarding.

## 2. Ordem de execução

Ordem numérica de `tasks.md` (1 → 2 → 3), do observável para o estrutural:

1. **Lote A (1.1 + 1.3)** — chrome PT-BR nas páginas divergentes + trava
   anti-contaminação do template.
2. **Lote B (1.2)** — estados de carregamento/vazio/erro + retry.
3. **Lote C (2.1 + 2.2)** — extração para blocos por domínio + formatação
   PT-BR + gating por permissão.
4. **Lote D (3.1)** — extração dos composables a partir do código consolidado
   em `clients.vue` + retrofit das demais páginas para consumi-los.
5. **Lote E (3.2)** — verificação integrada final (`lint` + `typecheck` +
   `build`) e revisão manual por Role.

Motivo: 3.1 extrai o que 1.x/2.x consolidaram; extrair antes seria
generalizar sobre código ainda divergente.

## 3. Estratégia de worktree / branch / commits

- **Um único worktree, sem paralelismo.** As telas compartilham o mesmo
  chrome/composables; dois agentes em paralelo gerariam conflito de forma
  garantida. Não criar `git worktree` adicional para esta change.
- Branch única a partir de `main` atualizada:
  `git switch -c feat-frontend-standardize-frontend-tables` (todos os comandos
  `git` na raiz `/home/obsidian/dev/onzen`; comandos de frontend sempre em
  `frontend/`).
- Um commit focado por lote (A, B, C, D), mais um commit de verificação (E),
  com `requesting-code-review` antes de cada commit, conforme
  `operations/apply`. Mensagens convencionais com escopo, ex.:
  `feat(frontend): ...`.
- Ao final, `openspec-archive-change` (sincronizar deltas em
  `openspec/specs/`, registrar gotchas em `openspec/config.yaml`) — nunca
  deixar a change sem arquivar.

## 4. Detalhe por lote

### Lote A — chrome PT-BR + isolamento do template (tasks 1.1, 1.3)

Arquivos-alvo (leitura + edição):

- `frontend/app/pages/accounts.vue`
- `frontend/app/pages/plans.vue`
- `frontend/app/pages/audit.vue`
- `frontend/app/pages/monitoring/index.vue`
- `frontend/app/pages/monitoring/admin.vue`
- `frontend/app/pages/monitoring/certificado.vue`
- `frontend/app/pages/monitoring/parcelamentos/index.vue`
- `frontend/app/pages/monitoring/parcelamentos/[id].vue`
- Referências somente-leitura: `frontend/app/pages/customers.vue`,
  `frontend/app/pages/clients.vue`

Passos:

1. Em cada página divergente, replicar a sequência do molde
   (toolbar busca + filtros + visibilidade → `UTable` com bloco `ui` de
   borda/arredondamento → rodapé contadores + `UPagination`), com rótulos
   PT-BR e filtros ligados às colunas reais do domínio.
2. Trocar paginação server-side (`query: { page }` + `refresh()` por
   navegação) por client-side sobre os dados já carregados
   (`getPaginationRowModel` + `reset` de `pageIndex` em busca/filtro),
   mantendo os parâmetros de consulta atuais — sem migrar para server-side
   real (risco registrado em `design.md`).
3. Trava 1.3: `customers.vue`, `inbox.vue` e `index.vue` (vendas) não são
   tocados; nenhuma importação de bloco real entra neles e nenhum dado
   fictício entra nas páginas reais.

Comandos (em `frontend/`):

- `pnpm run lint`

Verificação do lote A (vale 1.1 + 1.3):

- `pnpm run lint` verde.
- Inspeção visual por página: busca filtra e volta à página 1; filtro por
  coluna filtra e volta à página 1; ordenação alterna crescente/decrescente;
  seleção exibe "N de M selecionado(s)" e libera ações em lote; visibilidade
  alterna sem perder busca/filtros/paginação; textos sem resíduo em inglês.
- Inspeção 1.3: nenhum texto/dado de `customers`/`inbox`/`home` aparece nas
  telas reais e vice-versa.

### Lote B — estados em PT-BR com retry (task 1.2)

Arquivos-alvo: mesmos do lote A (região da `UTable` + blocos de erro).

Passos:

1. Padronizar os três estados em cada tabela real, copiando o vocabulário de
   `clients.vue`: `:loading="status === 'pending'"`, `template #empty` com
   "Nenhum(a) … encontrado(a)" + "Ajuste a busca ou os filtros.", e bloco de
   erro com `error` do `useFetch` + botão "Tentar novamente" chamando
   `refresh()` (padrão já existente em `accounts.vue:52-57`).
2. Simular cada estado por página antes de considerar pronto: pendência
   (throttleniosk da rede no devtools), vazio (filtro impossível) e falha
   (endpoint fora / query inválida temporária em `pnpm dev`).

Comandos (em `frontend/`):

- `pnpm run typecheck`

Verificação do lote B (vale 1.2):

- `pnpm run typecheck` verde.
- Simulação dos três estados em cada tabela real, com mensagens PT-BR e
  retry funcional na falha; nenhum dado fictício exibido.

### Lote C — blocos por domínio + formatação/permissão (tasks 2.1, 2.2)

Arquivos novos propostos (criar somente o necessário, compondo Nuxt UI
existente — nada de biblioteca/estilo novo):

- `frontend/app/components/tables/TableToolbar.vue` (chrome: busca + filtros
  + visibilidade + exportação)
- `frontend/app/components/tables/TableFooter.vue` (chrome: contadores +
  `UPagination`)
- `frontend/app/components/tables/TableStates.vue` (chrome: vazio/erro/retry)
- `frontend/app/components/tables/clients/*` (colunas, células, ações,
  modais de Client)
- `frontend/app/components/tables/accounts/*`
- `frontend/app/components/tables/plans/*`
- `frontend/app/components/tables/audit/*`
- `frontend/app/components/tables/monitoring/*` (colunas densas de
  parcelas/pagamentos/snapshots permanecem no domínio, só ganham chrome)

Passos:

1. Extrair o chrome de `clients.vue` para `tables/*` compartilhado.
2. Extrair cada página para seus blocos de domínio; cada página compõe
   apenas `tables/*` + blocos do próprio domínio.
3. Padronizar formatação com utilitários existentes (CNPJ, moeda `pt-BR`,
   datas) e ações por linha com gating (`can('<domínio>.write')`, seguindo o
   padrão `can('clients.write')` de `clients.vue:29-30`): sem permissão,
   somente leitura ("Ver detalhes").

Comandos (em `frontend/`):

- `pnpm run lint`

Verificação do lote C (vale 2.1 + 2.2):

- `pnpm run lint` verde.
- Inspeção de imports por página: nenhuma página importa bloco de outro
  domínio; chrome idêntico alternando entre Clients e Accounts.
- Inspeção por Role (`super_admin`, `admin`, `operator`, `user` básico):
  valores formatados em PT-BR e ações de escrita ausentes sem permissão.

### Lote D — composables + CSV (task 3.1)

Arquivos novos propostos:

- `frontend/app/composables/tables/useTableState.ts` (busca textual, filtro
  por coluna, ordenação, seleção, visibilidade, paginação — com reset para a
  primeira página e contagem selecionadas/filtradas)
- `frontend/app/composables/tables/useTableCsv.ts` (exportação sobre
  `exportToCsv`, já existente)

Arquivos-alvo: páginas do lote A + blocos do lote C (retrofit para consumir
os composables; `clients.vue` é a primeira a migrar, como prova).

Passos:

1. Extrair `useTableState`/`useTableCsv` do código consolidado de
   `clients.vue` (não do template em inglês).
2. Migrar `clients.vue` para os composables; confirmar paridade total de
   comportamento.
3. Migrar demais páginas, uma por vez, removendo lógica duplicada local.
4. Regra CSV única: filtradas por padrão; selecionadas quando houver
   seleção; valores como exibidos (rótulos PT-BR); aviso "Nada para
   exportar" sem gerar arquivo vazio.

Comandos (em `frontend/`):

- `pnpm run typecheck`

Verificação do lote D (vale 3.1):

- `pnpm run typecheck` verde.
- Teste manual por domínio: mesma busca (filtra + página 1 + contagem),
  mesma seleção/contagem, mesma paginação (preserva busca/filtros/
  ordenação/seleção); cenários CSV: filtradas, selecionadas, nada a
  exportar (aviso PT-BR, sem arquivo).

### Lote E — verificação integrada (task 3.2)

Sem edição de código, salvo regressões encontradas.

Comandos (em `frontend/`, nesta ordem):

1. `pnpm run lint`
2. `pnpm run typecheck`
3. `pnpm run build`

Verificação do lote E (vale 3.2 + aceite da change):

- Os três comandos concluem sem erros.
- Varredura final WHEN/THEN de `specs/table-pattern`, `table-components` e
  `table-composables`History por página.
- `requesting-code-review` + `verification-before-completion` antes de
  declarar pronto; depois, `openspec-archive-change`.

## 5. Riscos → mitigações (de `design.md`, com reflexo no plano)

- Paginação server-side (Accounts/Plans/Audit) → lote A mantém consultas
  atuais, client-side só sobre dados carregados; server-side fica para change
  futura.
- Tradução quebra hábito do inglês → lote A revisa cada rótulo contra os
  cenários PT-BR das specs.
- Ações unificadas expõem escrita → lote C exige gating por permissão com
  inspeção por Role.
- CSV diverge por domínio → lote D impõe regra única + aviso.
- Tabelas densas de monitoramento → lote C padroniza só chrome/estados;
  colunas densas ficam no domínio.

## 6. Fora de escopo (não fazer — ver `proposal.md`)

- Tocar `customers.vue`, lista de e-mails de `inbox.vue`, vendas de
  `index.vue`; traduzir o template inteiro.
- Server-side real, mudanças em APIs/payloads/autorização do backend Laravel,
  regras de Plan, isolamento por Account, Account Switcher, Onboarding,
  escrita no Audit.
- i18n geral ou seletor de idioma (PT-BR fixo no chrome).
- Nova biblioteca/componente/estilo fora do Nuxt UI já disponível.
