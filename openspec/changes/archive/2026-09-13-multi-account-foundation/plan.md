# multi-account-foundation — Execution Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan phase-by-phase. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar a fundação multi-account (Accounts A/B, Roles, Plans, Invites, Clients, Account Switcher, Audit, auth via BFF) no backend Laravel e no frontend Nuxt, com testes cobrindo cada comportamento.

**Architecture:** Backend Laravel 13 expõe JSON REST sob `/api` com sessão Sanctum stateful + Fortify headless; isolamento por Account via singleton `CurrentAccount` + global scope (`BelongsToAccount`) + middleware `resolve.account`; autorizações em Policies por Role; regras de capacidade em `PlanLimitService`; auditoria best-effort em `AuditService` + `AuditObserver`. O browser nunca fala com o Laravel: server routes Nuxt (BFF) fazem proxy com cookies selados AES-256-GCM (`node:crypto`, sem nova dependência) em cookie httpOnly `onefisc_session`.

**Tech Stack:** Laravel 13 (PHP 8.3, PHPUnit, sqlite `:memory:` nos testes), Sanctum stateful, Fortify (sem registration/2FA/passkeys), Nuxt 4 + Nuxt UI 4, pnpm 12.3.4, zod (já instalado).

**Spec:** `openspec/changes/multi-account-foundation/specs/` (8 capabilities) + `design.md` (§§1–10). `tasks.md` é o contrato de escopo; este plano detalha a execução e referencia seus IDs — nunca os contradiz. Glossário canônico: `CONTEXT.md`.

## Global Constraints

- `UserRole` string-backed: `super_admin`, `admin`, `operator`, `user` — NUNCA `operador` em código, rotas ou specs.
- `AccountProfile` string-backed: `A`, `B`.
- JSON da API em `snake_case`; erros padrão Laravel (401 não autenticado, 403 negado, 422 `{message, errors}`); listas com paginador do Laravel.
- `clients.monitoring_enabled` boolean default `false` — é a representação do Monitoring Status (plan-mandated, task 1.7).
- Invites: `token_hash = sha256(token)` único; expiração 7 dias; senha definida no aceite; e-mail existente na plataforma e Invite pendente duplicado são negados; Role `super_admin` nunca convidável.
- Limites do Plan contam `users ativos + invites pendentes válidos`; expirados não contam; aceite exclui o próprio Invite da contagem; estouro → 422 com mensagem de upgrade.
- Audit best-effort: falha de registro nunca quebra a operação; sem endpoints de escrita; imutável.
- Account Switcher: só super_admin; estado em `switch_account_id` na sessão; banner persistente + saída explícita; vínculo do User inalterado.
- Sem auto-cadastro público: `Features::registration()` desligado; sem rota `/register`.
- TDD em todo backend: teste falhando primeiro, depois implementação. Commits convencionais com escopo. Nunca commitar `.env`.
- Frontend: NADA inventado — usar exclusivamente os componentes/padrões já disponíveis no template (Nuxt UI, `UDashboardSidebar`, `UForm`/padrão de `pages/settings`, `UNavigationMenu` do layout). Novas páginas = composição dos existentes; nenhum componente visual custom novo.
- Verificações: backend `composer test` (gate); higiene manual `./vendor/bin/pint --test` (sem gate); frontend `pnpm run lint` + `pnpm run typecheck` (CI gate, sem runner de testes).
- Comandos sempre do app dir (`backend/` ou `frontend/`).

## Starting state (verificado em 2026-09-13, branch `feat/multi-account-foundation`)

- `composer test`: 30 testes, 27 passam; só `AuditLogTest` falha (3 testes — faltam migration `audit_logs` + model `AuditLog`).
- Pronto (não-commitado): Sanctum/Fortify instalados, `phpunit.xml` + `TestCase` helpers, enums, migrations/models/factories/tests de plans/accounts/users/clients/invitations (tasks 1.1–1.8, 1.2–1.3).
- Falta: migration+model+factory `audit_logs` (1.9), `PlanSeeder` (1.10), tudo de 2.x–9.x, gates 10.x. Frontend intacto (template).

## File structure

Backend (`backend/`):

- `app/Enums/AccountProfile.php`, `app/Enums/UserRole.php` — PRONTOS, não tocar.
- `app/Support/CurrentAccount.php` (novo, fase B) — singleton de `account_id` efetivo; `set(?int)`, `get(): ?int`, `clear()`.
- `app/Concerns/BelongsToAccount.php` (novo, fase B) — `bootBelongsToAccount`: global scope por `CurrentAccount` + `creating` preenche `account_id`. Usado por: `Account`? NÃO — `Account` é o próprio tenant (sem scope). Usado por: `Client`, `Invitation`, `User` (leitura/escrita escopadas), `AuditLog` (só escrita via service; leitura escopada manualmente no controller).
- `app/Http/Middleware/ResolveAccount.php` (novo, fase B) — resolve Account efetiva: `switch_account_id` da sessão se User é super_admin e Account existe; senão `auth()->user()->account_id`. Registrado no grupo `api` em `bootstrap/app.php`.
- `app/Policies/{AccountPolicy,ClientPolicy,InvitationPolicy,PlanPolicy,AuditLogPolicy}.php` + `app/Providers/AuthServiceProvider.php` (novo, fase B).
- `app/Services/PlanLimitService.php` (novo, fase D — ANTES dos consumidores): `canInvite(Account): ?string`, `canAccept(Invitation): ?string`, `canCreateClient(Account): ?string`, `canAccessModule(Account, string): ?string`; retornam `null` se OK ou mensagem de upgrade. Lançar `ValidationException` fica nos callers.
- `app/Services/InvitationService.php` (novo, fase D): `invite(Account, User $inviter, array $data): Invitation` (retorna model com atributo não-persistido `token` cru), `accept(string $rawToken, string $password): User`, `revoke(Invitation): void`.
- `app/Services/AccountProvisioningService.php` (novo, fase D): `provision(string $accountName, string $adminEmail, string $adminName): array{account, invitation, token}` — cria Account B no Plan padrão + Invite de admin em transação.
- `app/Services/AuditService.php` + `app/Observers/AuditObserver.php` (novos, fase F).
- `app/Http/Controllers/Api/{MeController,OnboardingController,AccountController,PlanController,ClientController,InvitationController,SwitchController,AuditController}.php` (novos, fases B/D/E/F/G).
- `app/Models/AuditLog.php` + `database/factories/AuditLogFactory.php` + migration `*_create_audit_logs_table.php` (novos, fase A).
- `database/seeders/PlanSeeder.php` (novo, fase A) + registrar no `DatabaseSeeder`.
- `routes/api.php` (reescrever, fases B–G); `config/fortify.php` (desligar registration, fase C); `app/Providers/FortifyServiceProvider.php` (JSON + zonetable, fase C).

Frontend (`frontend/`):

- `server/utils/backend.ts` (novo, fase H) — `sealCookies`/`unsealCookies` (AES-256-GCM via `node:crypto`, chave `SESSION_SECRET`), `backendFetch(event, path, opts)` (repasse + captura de `set-cookie`, reenvio de `X-XSRF-TOKEN`).
- `server/api/auth/{login,logout,forgot-password,reset-password}.post.ts`, `server/api/me.get.ts`, `server/api/[...].ts` (novos, fase H).
- `app/middleware/auth.global.ts`, `app/composables/useMe.ts`, `app/composables/usePermissions.ts` (novos, fase H/I).
- `app/pages/{login,forgot-password,reset-password,onboarding}.vue`, `app/pages/invite/[token].vue`, `app/pages/{accounts,plans,clients,audit}.vue` (novos, fase I).
- `app/components/AccountSwitcher.vue` (novo, fase I) + banner no `app/layouts/default.vue`.
- `.env.example` (acrescentar `BACKEND_URL`, `SESSION_SECRET`, fase H).

---

### Fase A — AuditLog + PlanSeeder (tasks 1.9, 1.10)

Pré-requisito: nada (independente do restante). Fecha o `composer test` atual.

**Interfaces consumidas:** `TestCase::createAccount/createUser`, factories existentes.
**Produz para:** fase F (`AuditService` grava via `AuditLog::create`).

- [ ] **Step 1: Migration `audit_logs` (RED já existe — `AuditLogTest` falha hoje)**

Run: `php artisan test --filter=AuditLogTest` (de `backend/`)
Expected: 1 falha + 2 erros (`AuditLog` não existe) — confirma o RED antes de codar.

- [ ] **Step 2: Criar migration + model + factory**

```bash
php artisan make:model AuditLog -f
```

```php
// database/migrations/2026_09_13_140006_create_audit_logs_table.php
Schema::create('audit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('origin_account_id')->constrained('accounts')->cascadeOnDelete();
    $table->foreignId('target_account_id')->nullable()->constrained('accounts')->nullOnDelete();
    $table->string('action');
    $table->json('metadata')->nullable();
    $table->timestamp('created_at')->useCurrent();
});
```

```php
// app/Models/AuditLog.php
use Illuminate\Database\Eloquent\Attributes\Fillable;
#[Fillable(['actor_user_id', 'origin_account_id', 'target_account_id', 'action', 'metadata'])]
class AuditLog extends Model
{
    public $timestamps = false; // só created_at — SEM updated_at
    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
```

Factory: `actor_user_id => User::factory()`, `origin_account_id => Account::factory()`, `target_account_id => null`, `action => 'account.created'`, `metadata => []`.

- [ ] **Step 3: GREEN + suíte cheia**

Run: `php artisan test --filter=AuditLogTest` → Expected: 3/3 PASS.
Run: `composer test` → Expected: 33/33 PASS (30 + 3).

- [ ] **Step 4: `PlanSeeder` (TDD)**

Teste novo `tests/Feature/PlanSeederTest.php`:

```php
public function test_seeds_three_plans_with_exactly_one_default(): void
{
    $this->seed(PlanSeeder::class);
    $this->assertCount(3, Plan::all());
    $this->assertCount(1, Plan::where('is_default', true)->get());
    $basic = Plan::where('is_default', true)->firstOrFail();
    $this->assertSame(3, $basic->max_users);
    $this->assertSame(10, $basic->max_clients);
    $this->assertSame(['clients'], $basic->modules);
    $this->assertSame(100, $basic->monthly_query_volume);
}
```

Run → FAIL (`PlanSeeder` não existe). Implementar `database/seeders/PlanSeeder.php` (Básico padrão 3/10/`['clients']`/100 + Intermediário + Avançado, `updateOrCreate` por `name` para ser idempotente), registrar em `DatabaseSeeder::run`. Run → PASS + `composer test` verde.

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_09_13_140006_create_audit_logs_table.php backend/app/Models/AuditLog.php backend/database/factories/AuditLogFactory.php backend/database/seeders/PlanSeeder.php backend/database/seeders/DatabaseSeeder.php backend/tests/Feature/PlanSeederTest.php
git commit -m "feat(multi-account): audit_logs e PlanSeeder com testes"
```

### Fase B — Contexto de Account, policies, `/api/me` (tasks 2.1–2.4)

**Interfaces:** consome models/enums da fase A; produz `CurrentAccount::get()` (fases D–G), policies (fases E–G), `GET /api/me` (fase H/I).

- [ ] **Step 1: RED — `tests/Feature/AccountContextTest.php`**

```php
use RefreshDatabase;
public function test_reads_and_writes_are_scoped_to_current_account(): void
{
    $a = $this->createAccount(); $b = $this->createAccount();
    CurrentAccount::set($a->id);
    Client::factory()->create(['account_id' => $b->id]); // escrita direta escapa de propósito? NÃO — via factory com account explícito
    $this->assertCount(0, Client::all()); // scope esconde B
    $c = Client::factory()->make(); unset($c->account_id); $c->save(); // creating preenche A
    $this->assertSame($a->id, $c->account_id);
    CurrentAccount::clear();
}
```

Run → FAIL (`CurrentAccount` não existe).

- [ ] **Step 2: `app/Support/CurrentAccount.php` + `app/Concerns/BelongsToAccount.php`**

```php
namespace App\Support;
class CurrentAccount
{
    protected static ?int $accountId = null;
    public static function set(?int $id): void { static::$accountId = $id; }
    public static function get(): ?int { return static::$accountId; }
    public static function clear(): void { static::$accountId = null; }
}
```

```php
namespace App\Concerns;
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope('account', fn ($q) => $q->when(
            \App\Support\CurrentAccount::get(),
            fn ($q, $id) => $q->where($q->getModel()->getTable().'.account_id', $id)
        ));
        static::creating(fn ($m) => $m->account_id ??= \App\Support\CurrentAccount::get());
    }
}
```

Aplicar `use BelongsToAccount` em `Client`, `Invitation`, `User` (NÃO em `Account`, `Plan`, `AuditLog`). Cuidado: factories que criam `User`/`Client` com `account_id` explícito continuam funcionando (`??=`).

- [ ] **Step 3: `ResolveAccount` + registro (task 2.2, TDD)**

Teste `test_effective_account_prefers_valid_switch_target_for_super_admin_e_ignora_outros`:

```php
$admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);
$target = $this->createAccount();
$this->actingAs($admin)->withSession(['switch_account_id' => $target->id])
    ->getJson('/api/me')->assertOk()->assertJsonPath('account.id', $target->id);
$this->actingAs($this->createUser())->withSession(['switch_account_id' => $target->id])
    ->getJson('/api/me')->assertJsonPath('account.id', <própria>);
```

Middleware `app/Http/Middleware/ResolveAccount.php`: `handle` lê `session('switch_account_id')`, honra só se `auth()->user()?->isSuperAdmin()` e a Account existir; `CurrentAccount::set($efetiva)`. Registrar em `bootstrap/app.php`: `$middleware->api(append: [\App\Http\Middleware\ResolveAccount::class])` — verificar que `routes/api.php` já é carregado com prefixo `api` (sim, `api:` em `withRouting`).

- [ ] **Step 4: Policies (task 2.3, TDD por Role)**

`tests/Feature/RolePolicyTest.php` — matriz mínima: super_admin pode tudo na A + plataforma; admin gerencia a própria Account (clients/invites/users) mas 403 em `POST /api/accounts`, `*/plans`, `*/switch`; operator CRUD clients mas 403 em invites/users; user 403 em clients-write/invites/audit. Policies: `AccountPolicy` (create/list só super_admin), `ClientPolicy` (viewAny/create admin+operator da Account; user negado), `InvitationPolicy` (admin da Account), `PlanPolicy` (tudo só super_admin), `AuditLogPolicy` (view super_admin tudo; admin própria). Registrar em `app/Providers/AuthServiceProvider.php` (`$policies` + `Gate::before` para super_admin? NÃO — super_admin NÃO bypassa escopo de dados; só tem acesso às rotas de plataforma. Não criar bypass global).

- [ ] **Step 5: `GET /api/me` (task 2.4, TDD)**

`MeController`: 401 sem sessão (Sanctum); autenticado retorna `{user:{id,name,email,role}, account:{id,name,profile,plan}, acting_as:{account_id}|null}`. Rota `Route::middleware('auth:sanctum')->get('/me', MeController::class)` (invokable).

- [ ] **Step 6: GREEN geral + commit**

Run: `composer test` → Expected: tudo verde. `./vendor/bin/pint --test` → Expected: limpo (se acusar, rodar `./vendor/bin/pint` nos arquivos tocados e re-rodar).
Commit `feat(multi-account): contexto de Account, policies e GET /api/me`.

### Fase C — Fortify headless + Sanctum stateful (tasks 3.1–3.2)

**Produz:** contrato de sessão consumido pelas fases D–I.

- [ ] **Step 1: Desligar registration + JSON**

Em `backend/config/fortify.php`: comentar `Features::registration()`, `updateProfileInformation`, `updatePasswords`, `twoFactorAuthentication`, `passkeys` (fora de escopo); manter `resetPasswords()`. Em `FortifyServiceProvider`: garantir respostas JSON (Fortify responde JSON quando `Accept: application/json`; registrar views inexistentes NÃO — headless puro: `Fortify::loginView(fn () => abort(404))`? NÃO — em vez disso, expor só rotas de API: em `routes/api.php` NÃO recriar login; Fortify registra `/login`, `/logout`, `/forgot-password`, `/reset-password` como web. Para JSON, o BFF sempre envia `Accept: application/json`, e os testes usam `postJson`. Verificar com teste.)

- [ ] **Step 2: Sanctum stateful**

`config/sanctum.php`: `stateful` inclui `localhost:3000`, `127.0.0.1:3000` (+ `SANCTUM_STATEFUL_DOMAINS` no `.env.example`). Garantir `EnsureFrontendRequestsAreStateful` no grupo `api` (Laravel 13: `$middleware->api(append: [\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class])` — só se os testes de sessão exigirem; o essencial é cookie-based `web`-style? NÃO — rotas `/api` com `auth:sanctum` + sessão funcionam quando a requisição carrega cookies de sessão e CSRF válidos. Teste cobre.)
Sessões: `SESSION_DRIVER=database` exige tabela `sessions` — gerar (`php artisan session:table`, se o comando não existir nesta versão criar a migration padrão manualmente) + migrate.

- [ ] **Step 3: RED→GREEN `tests/Feature/AuthenticationTest.php`**

Login válido (`postJson('/login', [...])` → 200 + autenticado em `getJson('/api/me')`), login inválido (422 + mensagem genérica, sem revelar existência), logout (204 + `getJson('/api/me')` → 401), reset válido (notificação via `Notification::fake`, `postJson('/reset-password')` → 200 + sessões invalidadas), token expirado/inválido (422). Rate limiting: 1 teste de throttle no login (6 POSTs rápidos → 429) — se flaky, fixar com `RateLimiter::clear` no setUp.

- [ ] **Step 4: Contrato de sessão (task 3.2)**

`tests/Feature/SessionContractTest.php`: fluxo cookie — `postJson('/login')` captura `Set-Cookie` (laravel_session + XSRF-TOKEN), reenvia em `getJson('/api/me')` com header `X-XSRF-TOKEN` → 200; após `postJson('/logout')` → 401; CSRF inválido → 419. É o contrato que o BFF (fase H) implementará.

- [ ] **Step 5: Commit** `feat(multi-account): Fortify headless e Sanctum stateful com contrato de sessao`.

### Fase D — Limits, onboarding, invites, provisioning (tasks 5.1, 4.1–4.4)

Ordem interna OBRIGATÓRIA: 5.1 primeiro (Ruling R1), depois 4.2/4.3, depois 4.1, depois 4.4.

- [ ] **Step 1: `PlanLimitService` (task 5.1, TDD)**

`tests/Unit/PlanLimitServiceTest.php` (ou Feature com RefreshDatabase): limite exato permite; estouro de users (ativos + pendentes válidos) bloqueia; expirados ignorados; aceite excluindo o próprio invite permite no limite exato; `max_clients`, `modules`, `monthly_query_volume`.

```php
class PlanLimitService
{
    public function canInvite(Account $a): ?string { return $this->usersOk($a, 1) ? null : 'Limite de usuários do plano atingido. Faça upgrade do plano.'; }
    public function canAccept(Invitation $i): ?string { /* conta users + pendentes válidos EXCETO este invite */ }
    public function canCreateClient(Account $a): ?string { ... }
    public function canAccessModule(Account $a, string $module): ?string { ... }
}
```

Pendência válida: `accepted_at IS NULL AND expires_at > now()`.

- [ ] **Step 2: `InvitationService::invite/accept/revoke` (tasks 4.2–4.3, TDD)**

`tests/Feature/InvitationServiceTest.php`: invite válido cria pendente (expires_at ~7 dias, `token` cru com 64 chars, só hash no banco), e-mail existente (qualquer Account) negado, pendente duplicado negado, role `super_admin`/inválido negado, estouro de limite negado (usa `PlanLimitService`); aceite válido cria User (role do invite, senha hasheada, `accepted_at` setado), expirado negado, já-usado/revogado negado, limite no aceite. `InviteCreated` → `Mail::to(...)->send(new InvitationMail($invitation, $rawToken))` com `MAIL_MAILER=array` (assert via `Mail::fake`). Token: `Str::random(64)`, hash `sha256`.

- [ ] **Step 3: Onboarding (task 4.1, TDD)**

`tests/Feature/OnboardingTest.php`: base vazia → `POST /api/onboarding {name,email,password,account_name}` cria Account A + super_admin em transação + autentica (201 + `GET /api/me` 200); base populada → 409/422 indisponível + `GET /api/onboarding/status` → `{available:false}`.

- [ ] **Step 4: Provisioning + `GET/POST /api/accounts` (task 4.4, TDD)**

`AccountProvisioningService::provision` (transação: Account B + `plan_id` do default + `InvitationService::invite` admin). `POST /api/accounts` só super_admin (`AccountPolicy@create`); resposta 201 com account + invite enviado; não-super_admin → 403.

- [ ] **Step 5: Rotas `GET/POST /api/invitations`, `POST /api/invitations/{token}/accept`, `DELETE /api/invitations/{id}`** com `InvitationPolicy`. Commit `feat(multi-account): limits, onboarding, invites e provisioning`.

### Fase E — Plans API + Clients (tasks 5.2, 6.1–6.3)

- [ ] **Step 1: `GET/POST/PATCH /api/plans` + `PATCH /api/accounts/{account}/plan` (task 5.2, TDD)**

Só super_admin (`PlanPolicy`); troca com efeito imediato (assert lendo `account->plan` após PATCH); admin de B → 403. `POST /api/plans` valida `name, price_cents, max_users, max_clients, modules(array), monthly_query_volume, is_default` — se `is_default=true`, desmarca os demais (transação).

- [ ] **Step 2: CRUD Clients (task 6.1, TDD)**

`tests/Feature/ClientApiTest.php`: cadastro completo (CNPJ 14 dígitos válidos — validação: `regex:/^\d{14}$/` + dígitos verificadores; rejeita incompleto com `errors` por campo), CNPJ repetido na Account → 422, mesmo CNPJ em outra Account → 201, busca `?search=` por razão/CNPJ + filtro `regime`, paginação (`?per_page`, assert `meta`), edição, exclusão (assert evento no Audit? Audit só existe na fase F — NÃO asserir audit aqui; fase F cobre retroativamente), isolamento (outra Account → 404, nunca 403 com leak — usar `findOrFail` no escopo).

- [ ] **Step 3: Toggle Monitoring Status (task 6.2, TDD)**

`PATCH /api/clients/{client}/monitoring {monitoring_enabled:bool}` → persistido + refletido no GET; cobre ativo→inativo e inverso.

- [ ] **Step 4: Authz operator/user (task 6.3, TDD)**

operator: CRUD clients 200/201; user: `POST/PATCH/DELETE` → 403, `GET` → 200 só leitura? Spec roles: user executa só o atribuído — nesta fase sem atribuições, user lê a carteira (200 no index/show) e 403 em escrita. Travar assim com teste.

- [ ] **Step 5: Commit** `feat(multi-account): plans API e carteira de clients`.

### Fase F — Switch + Audit (tasks 7.1–7.3)

- [ ] **Step 1: `POST/DELETE /api/switch` (task 7.1, TDD)**

`tests/Feature/SwitchTest.php`: super_admin entra (`POST /api/switch {account_id}` → 200, `GET /api/me` mostra `acting_as` + dados da alvo), dados escopados (clients listados são da alvo), saída (`DELETE /api/switch` → volta à A), alvo inexistente → 422, não-super_admin → 403 (e sessão ignorada). Sessão: `session(['switch_account_id' => ...])`.

- [ ] **Step 2: `AuditService` + observer (task 7.2, TDD)**

`AuditService::record(?User $actor, ?int $origin, ?int $target, string $action, array $meta = [])` com try/catch interno (best-effort, log técnico em falha — teste: mockar `AuditLog::create` lançando? Simular via `DB::spy`? Mais simples: teste de integração que força exceção com `Event::fake`? NÃO — teste direto: `AuditService` com conexão quebrada? Pragmático: testar que `record` retorna `null` sem lançar quando o model lança — injetar closure? Manter simples e real: `AuditObserver` com `$auditService` mockado via container lançando exceção → operação (criar Client) conclui 201. + teste de cobertura: criar Account/User/Invite/Plan/Client + switch enter/exit geram linhas com todos os campos.
Eventos explícitos: `account.created`, `plan.changed`, `account.switch.enter/exit`, auth login/logout (listener no `Login`/`Logout`? Registrar `AuditAuthListener` nos eventos `Illuminate\Auth\Events\Login/Logout` — concreto e testável).
Observer: `AuditObserver` registrado em `AppServiceProvider::boot` para Account, User, Invitation, Plan, Client (`created/updated/deleted` → `record` com ator `auth()->user()`, origin = account efetiva).

- [ ] **Step 3: `GET /api/audit` (task 7.3, TDD)**

Filtros `account_id`, `actor_user_id`, `from`, `to` + paginação; super_admin tudo, admin só própria Account, operator/user 403; `PUT/DELETE /api/audit/*` inexistentes (assert 404/405). `AuditLogPolicy@viewAny/view`.

- [ ] **Step 4: Commit** `feat(multi-account): account switcher e audit`.

### Fase G — BFF + sessão (tasks 8.1–8.2)

Pré-requisito: `.env.example` do frontend ganha `BACKEND_URL=http://localhost:8000`, `SESSION_SECRET=` (32+ bytes hex).

- [ ] **Step 1: `server/utils/backend.ts`**

`seal/unseal` AES-256-GCM (`node:crypto`, `SESSION_SECRET` como key 32 bytes via `scryptSync`): payload `{cookies: string[], xsrf: string}`. `backendFetch(event, path, init)`: lê `onefisc_session`, injeta `cookie` + `X-XSRF-TOKEN` (+ `Accept: application/json`, `X-Requested-With: XMLHttpRequest`), `fetch(BACKEND_URL + path)` via `$fetch.raw`, repassa `set-cookie` capturando e re-selando no `onefisc_session` httpOnly (`httpOnly, path=/, sameSite=lax, secure=<prod>`).

- [ ] **Step 2: Rotas BFF (task 8.1)**

`server/api/auth/login.post.ts` (repasse `/login`, sela cookies), `logout.post.ts`, `forgot-password.post.ts`, `reset-password.post.ts`, `me.get.ts`, `server/api/[...].ts` (proxy autenticado genérico; sem sessão → 401 sem tocar o backend).

- [ ] **Step 3: Middleware Nuxt (task 8.2)**

`app/middleware/auth.global.ts`: sem sessão (`/api/me` 401) → `/login` (exceto `/login`, `/forgot-password`, `/reset-password`, `/invite/*`, `/onboarding`); base vazia (`GET /api/onboarding/status` via BFF) → `/onboarding`; logado em `/login` → `/`.

- [ ] **Step 4: Verificação** `pnpm run lint` + `pnpm run typecheck` verdes + smoke manual login/logout (registrar no relatório). Commit `feat(multi-account): BFF de sessao e middleware Nuxt`.

### Fase H — Shell e páginas (tasks 9.1–9.6)

Restrição de design (usuário): não inventar nada — só componentes/padrões do template. `AccountSwitcher` = `UDropdownMenu`/`UModal` + `UBadge` do template; banner = `UBanner` (ou o padrão de aviso já usado no template); páginas = layout `default` + `UCard`/`UTable`/`UForm` como em `pages/customers` e `pages/settings`.

Padrão de página: `<script setup lang="ts">` + `useMe()` + `$fetch('/api/...')` + componentes Nuxt UI; formulários com `zod` (`z.object`, `useForm`? template usa `@nuxt/ui` Form — seguir o padrão de `pages/settings` existente).

- [ ] **Step 1: `useMe`/`usePermissions` + shell (task 9.1)**

`app/composables/useMe.ts` (`useFetch('/api/me')` + `refresh`), `usePermissions.ts` (`isSuperAdmin/isAdmin/can(acao)` derivado de `me.role` + `plan.modules`). `app/layouts/default.vue`: `links` filtrados por Role (plataforma Accounts/Plans/Audit só super_admin; Audit também admin; Clients admin+operator+user-leitura) + `<AccountSwitcher v-if="isSuperAdmin"/>`.

- [ ] **Step 2: Auth pages (task 9.2)**

`login.vue`, `forgot-password.vue`, `reset-password.vue` (token via query) — zod + mensagens do backend (`error.data.errors`).

- [ ] **Step 3: `onboarding.vue` + `invite/[token].vue` (task 9.3)**

Onboarding: indisponível quando base populada (redirect `/login`). Aceite: `route.params.token` + campo senha → `POST /api/invitations/{token}/accept` via BFF → redirect `/login`; expirado → mensagem "solicite novo convite".

- [ ] **Step 4: Área da A (task 9.4)**

`accounts.vue` (lista + criar B com admin e-mail), `plans.vue` (catálogo + criar/editar + trocar plan da Account).

- [ ] **Step 5: `clients.vue` (task 9.5)**

Tabela com busca/regime, criar/editar/excluir, toggle `monitoring_enabled`, aviso de upgrade em 422 (`error.data.message`).

- [ ] **Step 6: `AccountSwitcher` + `audit.vue` (task 9.6)**

Banner "Atuando como {name}" + sair; audit com filtros account/ator/período + paginação. `pnpm run lint` + `typecheck` verdes. Commit `feat(multi-account): shell e paginas do frontend`.

### Fase I — Gates + smoke (tasks 10.1–10.3)

- [ ] **Step 1:** `composer test` (de `backend/`) verde — colar saída no relatório; `./vendor/bin/pint --test` limpo (higiene, sem gate).
- [ ] **Step 2:** `pnpm run lint` + `pnpm run typecheck` (de `frontend/`) verdes — colar saídas.
- [ ] **Step 3:** Smoke E2E em base limpa (`php artisan migrate:fresh --seed` no sqlite de dev): onboarding cria A → login → A cria B com invite → aceitar invite como admin B → estouro de limite bloqueia com upgrade → switch entra/sai → audit lista tudo. Registrar passo a passo em `openspec/changes/multi-account-foundation/smoke.md` (NOVO arquivo permitido: evidência de verificação, não planejamento).
- [ ] **Step 4:** Commit `test(multi-account): gates verdes e smoke E2E registado`.

## Self-review (writing-plans)

1. **Cobertura spec:** accounts→D/Fase B; roles→B/E; onboarding-invites→D/H; plans→A/D/E; clients→B/E; account-switcher→B/F/H; authentication→C/G/H; audit→A/F. Todos os WHEN/THEN têm teste correspondente nas fases. Nenhum gap.
2. **Placeholders:** nenhum "TBD/similar" — valores, queries, asserts e comandos estão inline. Fallbacks honestos onde a versão exata do framework manda: `session:table` (fallback: migration manual) e `EnsureFrontendRequestsAreStateful` (fallback: só se o teste de sessão exigir).
3. **Consistência:** `InvitationService::invite` (fase D) é o único criador de invites (provisioning e controller chamam ele); `PlanLimitService` nasce na fase D antes de todos os consumidores; `AuditLog` sem `updated_at` em migration+model+factory; `monitoring_enabled` em migration/model/factory/testes; `UserRole::Operator = 'operator'`.
