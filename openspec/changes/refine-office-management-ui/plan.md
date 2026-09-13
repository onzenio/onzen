# Escritórios e Navegação Administrativa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agrupar a navegação de plataforma sob o grupo colapsável "Administração" e refinar a página "Escritórios" usando apenas componentes existentes do template.

**Architecture:** Reaproveitar o contrato do item `Settings` (`UNavigationMenu` com `type: 'trigger'`) para o novo grupo; proteger `/accounts` e `/plans` com um middleware de rota nomeado que espelha o padrão `useRequestFetch` do `auth.global.ts`; refinar `accounts.vue` no próprio arquivo sem extrair componentes. Sem runner de testes no frontend, cada task usa ciclo red/green observável: gates estáticos (`typecheck`, `lint`, `build`) + checagem de comportamento no navegador com passos e resultados esperados exatos.

**Tech Stack:** Nuxt 4 + @nuxt/ui 4.11.0 + Tailwind CSS v4 (frontend/); Laravel 13 API existente, inalterada (backend/).

**Spec:** Este plano implementa os artefatos abaixo — o executor deve ler todos antes de começar:
- `openspec/changes/refine-office-management-ui/proposal.md`
- `openspec/changes/refine-office-management-ui/specs/accounts/spec.md`
- `openspec/changes/refine-office-management-ui/specs/roles/spec.md`
- `openspec/changes/refine-office-management-ui/design.md`
- `openspec/changes/refine-office-management-ui/tasks.md` (contrato de escopo; os IDs tasks.md são referenciados em cada task)

## Global Constraints

- Comandos do frontend rodam em `frontend/` com pnpm 12.3.4; comandos do backend rodam em `backend/`.
- Nenhum componente visual, estilo global ou biblioteca novos — compor apenas componentes existentes do Nuxt UI/template.
- Copy nova em PT-BR; placeholders em inglês fora do escopo permanecem intocados.
- URLs inalteradas: `/accounts`, `/plans`, `/audit` (sem `/admin/*`, sem redirects de migração).
- Backend inalterado: nenhum endpoint, Policy, migration ou payload muda neste change.
- "Escritórios" é rótulo de interface; domínio e contratos usam o termo canônico Account.
- Não existe runner de testes no frontend — NÃO adicionar vitest/jest/playwright (fora de escopo); red/green é observável.
- Estilo eslint: sem trailing commas, chaves `1tbs` (`} else {`), no máximo 3 atributos por linha em elementos singleline.
- A árvore de trabalho contém deleções não relacionadas — cada commit adiciona (`git add`) SOMENTE os arquivos listados na task, nunca `git add -A`.

---

## Scope Check

Subsistema único (navegação administrativa + página de Accounts no frontend). Um plano só, quatro tasks sequenciais. Nenhuma decomposição adicional necessária.

## File Structure

- Create: `frontend/app/middleware/super-admin.ts` — middleware de rota nomeado; redireciona não-`super_admin` para `/`.
- Modify: `frontend/app/layouts/default.vue` (bloco de navegação, hoje linhas 31-54) — remove os itens soltos Accounts/Plans/Audit; monta `adminChildren` por permissão e adiciona o trigger "Administração".
- Modify: `frontend/app/pages/accounts.vue` — declara o middleware; PT-BR; colunas Nome/Tipo/Plano; badges Central OneFisc/Escritório; estados vazio/erro; modal refinado com reset e trava de submissão.
- Modify: `frontend/app/pages/plans.vue` (1 linha após os imports) — declara o middleware; nada mais muda nesta página.

## Mapeamento tasks.md

| Plan task | tasks.md cobertos |
|---|---|
| Task 1 — Proteção das rotas | 2.1, 2.2 |
| Task 2 — Grupo Administração | 1.1, 1.2, 1.3 |
| Task 3 — Página Escritórios | 3.1, 3.2, 3.3 |
| Task 4 — Gate final | 3.4 |

---

### Task 1: Proteção das rotas de plataforma

**Files:**
- Create: `frontend/app/middleware/super-admin.ts`
- Modify: `frontend/app/pages/accounts.vue` (inserir 1 linha após a linha 3)
- Modify: `frontend/app/pages/plans.vue` (inserir 1 linha após a linha 3)
- Test: gates estáticos + checagem observável no navegador (sem runner de testes no projeto)

**Interfaces:**
- Consumes: `GET /api/me` via BFF (`MeResponse` de `app/composables/useMe.ts`); padrão `useRequestFetch` já usado por `app/middleware/auth.global.ts`.
- Produces: middleware nomeado `super-admin` — `me?.user.role !== 'super_admin'` retorna `navigateTo('/')`; usado via `definePageMeta({ middleware: 'super-admin' })` pelas Tasks 1 e 3.

- [ ] **Step 1: Subir o ambiente e criar os usuários de teste**

```bash
# terminal 1 — backend (workdir: backend/)
composer dev
```

```bash
# terminal 2 — frontend (workdir: frontend/)
pnpm dev
```

```bash
# terminal 3 — usuários de teste (workdir: backend/, com o banco dev migrado)
php artisan migrate
php artisan tinker --execute='$a = App\Models\Account::where("profile", "B")->first(); if (!$a) { $a = App\Models\Account::factory()->create(); } foreach (["admin", "operator", "user"] as $r) { App\Models\User::firstOrCreate(["email" => "$r@example.com"], ["name" => ucfirst($r), "password" => "password", "account_id" => $a->id, "role" => $r]); } echo "users-ok";'
```

Expected: `migrate` termina com tabelas atualizadas; o tinker imprime `users-ok`. Garanta ainda um `super_admin`: se o banco estiver vazio, complete o Onboarding em `http://localhost:3000/onboarding` (cria a Account A e o primeiro `super_admin`). Logins de teste: `http://localhost:3000/login` com `admin@example.com`, `operator@example.com` ou `user@example.com`, senha `password`.

- [ ] **Step 2: Demonstrar o comportamento atual falho (red)**

Logado como `admin@example.com`, digite `http://localhost:3000/accounts` na barra de endereço e pressione Enter. Depois repita com `http://localhost:3000/plans`.

Expected (falho, antes da correção): as duas páginas renderizam seu conteúdo em vez de redirecionar para `/`.

- [ ] **Step 3: Criar o middleware `super-admin`**

Criar `frontend/app/middleware/super-admin.ts` com exatamente este conteúdo:

```ts
import type { MeResponse } from '../composables/useMe'

export default defineNuxtRouteMiddleware(async () => {
  const api = useRequestFetch()
  const me = await api<MeResponse>('/api/me').catch(() => null)
  if (me?.user.role !== 'super_admin') {
    return navigateTo('/')
  }
})
```

Decisão registrada: espelha o padrão `useRequestFetch` do `auth.global.ts` (seguro em SSR e SPA) em vez de reutilizar `useMe()`, para evitar sutilezas de cache do `useFetch` dentro de middleware. Custo aceito: uma chamada extra a `/api/me` nestas duas rotas.

- [ ] **Step 4: Declarar o middleware nas duas páginas**

Em `frontend/app/pages/accounts.vue`, inserir após a linha 3 (`import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'`):

```ts
definePageMeta({ middleware: 'super-admin' })
```

Em `frontend/app/pages/plans.vue`, inserir após a linha 3 (`import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'`):

```ts
definePageMeta({ middleware: 'super-admin' })
```

Nada mais muda em `plans.vue` neste change.

- [ ] **Step 5: Rodar os gates estáticos**

```bash
# workdir: frontend/
pnpm run typecheck
pnpm run lint
```

Expected: ambos passam sem erros.

- [ ] **Step 6: Verificar a proteção (green)**

 1. Logado como `admin@example.com`: digitar `/accounts` na barra de endereço + Enter → cai em `/`, sem vestígio da página restrita. Repetir com `/plans` → cai em `/`.
 2. Repetir o passo 1 como `operator@example.com` e como `user@example.com` → mesmo redirecionamento nas duas rotas.
 3. Logado como `super_admin`: `/accounts` e `/plans` renderizam normalmente, por digitação direta e por navegação interna.

Expected: 2.1 (redirect sem exibir a área) e 2.2 (acesso do `super_admin`) atendidos.

- [ ] **Step 7: Commit**

```bash
git add frontend/app/middleware/super-admin.ts frontend/app/pages/accounts.vue frontend/app/pages/plans.vue
git commit -m "feat(frontend): protect platform routes with super-admin middleware"
```

---

### Task 2: Grupo Administração no sidebar

**Files:**
- Modify: `frontend/app/layouts/default.vue:31-54` (substituir os três `if` de Accounts/Plans/Audit pelo bloco do grupo)
- Test: gates estáticos + checagem observável no navegador por Role e viewport

**Interfaces:**
- Consumes: `can('accounts.view')`, `can('plans.view')`, `can('audit.view')` de `usePermissions()` (existentes, inalterados); `closeSidebar()` e o contrato do trigger `Settings` no mesmo arquivo.
- Produces: item `Administração` (`type: 'trigger'`, `defaultOpen: true`, sem `to`) com filhos `Escritórios → /accounts`, `Planos → /plans`, `Auditoria → /audit`, filtrados por permissão.

- [ ] **Step 1: Demonstrar a navegação atual (red)**

Logado como `super_admin`, observe o sidebar expandido em desktop.

Expected (atual, a mudar): `Accounts`, `Plans` e `Audit` aparecem como itens soltos de primeiro nível, em inglês, fora de qualquer grupo.

- [ ] **Step 2: Substituir os itens soltos pelo grupo Administração**

Em `frontend/app/layouts/default.vue`, substituir exatamente este bloco (linhas 31-54):

```ts
  if (can('accounts.view')) {
    main.push({
      label: 'Accounts',
      icon: 'i-lucide-building-2',
      to: '/accounts',
      onSelect: closeSidebar
    })
  }
  if (can('plans.view')) {
    main.push({
      label: 'Plans',
      icon: 'i-lucide-layers',
      to: '/plans',
      onSelect: closeSidebar
    })
  }
  if (can('audit.view')) {
    main.push({
      label: 'Audit',
      icon: 'i-lucide-scroll-text',
      to: '/audit',
      onSelect: closeSidebar
    })
  }
```

por:

```ts
  const adminChildren: NavigationMenuItem[] = []
  if (can('accounts.view')) {
    adminChildren.push({
      label: 'Escritórios',
      to: '/accounts',
      onSelect: closeSidebar
    })
  }
  if (can('plans.view')) {
    adminChildren.push({
      label: 'Planos',
      to: '/plans',
      onSelect: closeSidebar
    })
  }
  if (can('audit.view')) {
    adminChildren.push({
      label: 'Auditoria',
      to: '/audit',
      onSelect: closeSidebar
    })
  }
  if (adminChildren.length > 0) {
    main.push({
      label: 'Administração',
      icon: 'i-lucide-shield-check',
      defaultOpen: true,
      type: 'trigger',
      children: adminChildren
    })
  }
```

Decisões registradas: (a) o trigger NÃO tem `to` — no sidebar recolhido o `to` do pai vira link clicável, e apontá-lo para `/accounts` ejetaria o `admin` para `/` ao clicar no ícone; sem `to`, o ícone abre apenas o popover com os filhos permitidos; (b) filhos sem ícone, igual ao `Settings`; (c) `defaultOpen: true` para o grupo de filho único do `admin` não exigir clique extra à toa.

- [ ] **Step 3: Rodar os gates estáticos**

```bash
# workdir: frontend/
pnpm run typecheck
pnpm run lint
```

Expected: ambos passam sem erros.

- [ ] **Step 4: Verificar a matriz de visibilidade (green)**

Com os servidores da Task 1 e os mesmos usuários:

 1. `super_admin`, sidebar expandido: grupo `Administração` aberto por padrão com `Escritórios`, `Planos`, `Auditoria`; `Settings` continua separado e inalterado.
 2. `admin`: grupo `Administração` com somente `Auditoria`; sem `Escritórios`, `Planos` ou Account Switcher no header.
 3. `operator` e `user`: nenhum grupo `Administração`, nenhum item de plataforma.
 4. `super_admin`, sidebar recolhido (arrastar até colapsar): hover no ícone `Administração` abre popover com os três filhos; clicar num filho navega.
 5. `admin`, sidebar recolhido: popover mostra somente `Auditoria`; clicar no ícone NÃO navega para lugar nenhum.
 6. Largura mobile (< `lg`): abrir o menu, tocar `Administração` (accordion), tocar um filho → navega E fecha o menu.

Expected: 1.1, 1.2 e 1.3 atendidos nos três estados do sidebar.

- [ ] **Step 5: Commit**

```bash
git add frontend/app/layouts/default.vue
git commit -m "feat(frontend): group platform navigation under Administração"
```

---

### Task 3: Refinar a página Escritórios

**Files:**
- Modify: `frontend/app/pages/accounts.vue` (fetch, colunas, ações de retry, modal, tabela, título)
- Test: gates estáticos + checagem observável (tabela, estados, criação)

**Interfaces:**
- Consumes: middleware `super-admin` da Task 1 (já declarado nesta página); `GET /api/accounts` paginado e `POST /api/accounts` (inalterados); `backendMessage`/`backendFormErrors` de `app/utils/backend-error.ts`; estilos de tabela copiados verbatim de `app/pages/customers.vue`.
- Produces: `/accounts` refinada — título `Escritórios`, colunas Nome/Tipo/Plano, badges `Central OneFisc`/`Escritório`, `empty`/`UAlert` com retry, modal com reset e trava de envio.

- [ ] **Step 1: Demonstrar a página atual (red)**

Logado como `super_admin`, abrir `/accounts`.

Expected (atual, a mudar): título `Accounts` em inglês; coluna `ID` técnica; badges `Conta A`/`Conta B`; sem estado de erro (falha de rede rende tabela silenciosamente vazia); fechar e reabrir o modal preserva valores digitados.

- [ ] **Step 2: Capturar o erro da listagem**

Substituir a linha 25:

```ts
const { data, status, refresh } = await useFetch<AccountsResponse>('/api/accounts', {
```

por:

```ts
const { data, error, status, refresh } = await useFetch<AccountsResponse>('/api/accounts', {
```

- [ ] **Step 3: Reescrever as colunas (Nome/Tipo/Plano, sem ID)**

Substituir o bloco `columns` (linhas 33-49):

```ts
const columns: TableColumn<AccountRow>[] = [
  { accessorKey: 'id', header: 'ID' },
  { accessorKey: 'name', header: 'Nome' },
  {
    accessorKey: 'profile',
    header: 'Perfil',
    cell: ({ row }) => {
      const profile = row.original.profile
      return h(UBadge, { variant: 'subtle', color: profile === 'A' ? 'primary' : 'neutral' }, () => `Conta ${profile}`)
    }
  },
  {
    id: 'plan',
    header: 'Plano',
    cell: ({ row }) => row.original.plan?.name ?? '—'
  }
]
```

por:

```ts
const columns: TableColumn<AccountRow>[] = [
  { accessorKey: 'name', header: 'Nome' },
  {
    accessorKey: 'profile',
    header: 'Tipo',
    cell: ({ row }) => {
      const central = row.original.profile === 'A'
      return h(UBadge, { variant: 'subtle', color: central ? 'primary' : 'neutral' }, () => central ? 'Central OneFisc' : 'Escritório')
    }
  },
  {
    id: 'plan',
    header: 'Plano',
    cell: ({ row }) => row.original.plan?.name ?? '—'
  }
]

const retryActions = [{
  label: 'Tentar novamente',
  color: 'error' as const,
  variant: 'outline' as const,
  onClick: () => refresh()
}]
```

- [ ] **Step 4: Resetar o modal ao fechar e travar submissão dupla**

4a. Após a função `resetState` (linhas 69-73), adicionar:

```ts
watch(open, (value) => {
  if (!value) {
    resetState()
    form.value?.clear()
  }
})
```

4b. Em `onSubmit`, trocar o início:

```ts
async function onSubmit(event: FormSubmitEvent<Schema>) {
  creating.value = true
```

por:

```ts
async function onSubmit(event: FormSubmitEvent<Schema>) {
  if (creating.value) {
    return
  }
  creating.value = true
```

4c. No sucesso do `onSubmit`, remover a chamada explícita (o watcher do passo 4a assume):

```ts
    open.value = false
    resetState()
    await refresh()
```

por:

```ts
    open.value = false
    await refresh()
```

- [ ] **Step 5: Atualizar título, botão e copy do modal**

5a. Linha 95: `<UDashboardNavbar title="Accounts">` → `<UDashboardNavbar title="Escritórios">`.

5b. Linha 101: `title="Nova conta" description="Criar uma conta B e convidar o administrador"` → `title="Novo escritório" description="Cria um escritório (Account B) e envia o convite ao administrador"`. A linha final fica (3 atributos, singleline permitido):

```vue
<UModal v-model:open="open" title="Novo escritório" description="Cria um escritório (Account B) e envia o convite ao administrador">
```

5c. Linha 102: `<UButton label="Nova conta" icon="i-lucide-plus" />` → `<UButton label="Novo escritório" icon="i-lucide-plus" />`.

- [ ] **Step 6: Adicionar erro com retry, empty e estilos da tabela**

Substituir o bloco da tabela (linhas 144-148):

```vue
      <UTable
        :data="accounts"
        :columns="columns"
        :loading="status === 'pending'"
      />
```

por:

```vue
      <UAlert
        v-if="error"
        color="error"
        title="Não foi possível carregar as contas"
        :description="backendMessage(error)"
        :actions="retryActions"
      />

      <UTable
        v-else
        :data="accounts"
        :columns="columns"
        :loading="status === 'pending'"
        empty="Nenhuma conta encontrada."
        :ui="{
          base: 'table-fixed border-separate border-spacing-0',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
          td: 'border-b border-default',
          separator: 'h-0'
        }"
      />
```

O objeto `:ui` é cópia verbatim de `customers.vue` (linhas 303-310). `UAlert` aceita `actions?: ButtonProps[]` e `UForm` expõe `clear()` — ambos confirmados no `@nuxt/ui` instalado.

- [ ] **Step 7: Rodar os gates estáticos**

```bash
# workdir: frontend/
pnpm run typecheck
pnpm run lint
```

Expected: ambos passam sem erros.

- [ ] **Step 8: Verificar a página (green)**

Logado como `super_admin` em `/accounts`:

 1. Tabela: título `Escritórios`; colunas `Nome`, `Tipo`, `Plano` (sem `ID`); linha da Account A com badge `Central OneFisc` (primary); linhas de Account B com badge `Escritório` (neutral); plano exibido ou `—`.
 2. Erro: parar o backend (Ctrl+C no terminal 1), recarregar `/accounts` → `UAlert` vermelho persistente com botão `Tentar novamente`; subir o backend de novo, clicar `Tentar novamente` → tabela carrega. (Estado vazio: verificado por código — prop `empty` presente e o `UTable` instalado renderiza `props.empty` quando sem linhas; esvaziar a tabela Accounts em dev é inseguro porque os Users pertencem a Accounts.)
 3. Criação: abrir `Novo escritório` → description menciona Account B e convite; enviar vazio → erros junto aos campos, modal permanece aberto; preencher nome + e-mail válido, clicar `Criar` duas vezes rápido → um único toast de sucesso e uma única nova linha; fechar e reabrir → campos vazios e sem erros.
 4. Fechamento: digitar valores, fechar pelo X → reabrir → campos vazios e sem erros. Repetir fechando por `Cancelar` e por Escape.

Expected: 3.1, 3.2 e 3.3 atendidos.

- [ ] **Step 9: Commit**

```bash
git add frontend/app/pages/accounts.vue
git commit -m "feat(frontend): refine Escritórios listing and creation"
```

---

### Task 4: Gate final de verificação

**Files:** nenhum (verificação apenas; se algum ajuste for necessário, voltar à task dona do arquivo).

**Interfaces:**
- Consumes: entregáveis das Tasks 1-3.
- Produces: evidência de `lint` + `typecheck` + `build` verdes e matriz 4 Roles × 3 viewports conferida.

- [ ] **Step 1: Rodar o gate automatizado completo**

```bash
# workdir: frontend/
pnpm run lint
pnpm run typecheck
pnpm run build
```

Expected: os três passam; `build` gera `.output` sem erros de SSR/prerender.

- [ ] **Step 2: Conferir a matriz 4 Roles × 3 viewports**

Com os servidores das tasks anteriores e os usuários `super_admin`, `admin@example.com`, `operator@example.com`, `user@example.com` (senha `password`):

| Role | Desktop expandido | Desktop recolhido | Mobile (< `lg`) |
|---|---|---|---|
| `super_admin` | `Administração` aberta com 3 filhos | popover com 3 filhos | accordion com 3 filhos; filho fecha o menu |
| `admin` | `Administração` com só `Auditoria` | popover com só `Auditoria`; ícone não navega | accordion com só `Auditoria`; filho fecha o menu |
| `operator`/`user` | sem grupo | sem grupo | sem grupo |

URLs diretas (digitação + Enter): `/accounts` e `/plans` → `super_admin` entra, demais caem em `/`. `/audit` → `super_admin` e `admin` entram; `operator`/`user` não têm guarda frontend (fora de escopo aprovado) — a API nega com 403.

Expected: toda a matriz conforme a tabela; item ativo correto em cada rota; paginação da `/accounts` sem overflow horizontal no mobile.

- [ ] **Step 3: Conferir teclado e foco**

 1. Com Tab até o trigger `Administração`, Enter alterna abrir/fechar; filhos alcançáveis por Tab.
 2. Em `/accounts`, Tab até `Novo escritório`, Enter abre o modal; Escape fecha; foco retorna ao botão.
 3. No estado de erro (backend parado, Task 3 passo 2), o botão `Tentar novamente` é alcançável por Tab e ativável por Enter.

Expected: sem armadilhas de foco; tudo operável por teclado.

- [ ] **Step 4: Confirmar árvore limpa do change**

```bash
git status --short -- frontend/app/middleware/super-admin.ts frontend/app/layouts/default.vue frontend/app/pages/accounts.vue frontend/app/pages/plans.vue
```

Expected: nenhuma saída (tudo commitado nas Tasks 1-3). Se o Step 1-3 exigiu ajustes, commitar com `fix(frontend): ...` adicionando somente o arquivo da task dona, e repetir os Steps 1-3 afetados. Sem commit vazio de verificação.
