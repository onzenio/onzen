## Context

O repo foi dividido em `backend/` (Laravel 13 recém-instalado, sem auth de API, sem migrations de domínio) e `frontend/` (Nuxt 4 + Nuxt UI 4, template de dashboard com server routes de exemplo). Não há base legada a migrar: o monólito Inertia anterior existe só no histórico do git e serve como referência de comportamento e testes. O banco de desenvolvimento é SQLite. Ver `proposal.md` — Why para a motivação e `specs/` para os requisitos.

Convenções do esqueleto atual: Laravel 13 com atributos PHP (`#[Fillable]`, `#[Hidden]`) nos models, PHPUnit (sem Pest), `routes/web.php` existente e sem `routes/api.php`; frontend com `pnpm`, ESLint e `nuxt typecheck`.

## Goals / Non-Goals

**Goals:**

- Fechar o contrato de comunicação Nuxt ↔ Laravel (BFF) com sessão por cookie, sem expor o backend ao browser.
- Isolar dados por Account em todas as leituras e escritas, com rede de segurança estrutural (scope global + middleware).
- Definir o modelo completo de Roles, Plans, Invites, Account Switcher e Audit de forma testável no backend.
- Entregar o shell Nuxt com navegação por Role, páginas do fluxo e a área da Account A.

**Non-Goals:**

- Integração real com SERPRO/contador, execução de monitoramento e consumo de volume de consultas (change futuro; aqui só o Monitoring Status do Client).
- Cobrança/pagamento; troca de Plan é manual pela A.
- 2FA, passkeys e login social (Fortify habilitado sem esses recursos nesta fase).
- API pública para terceiros ou tokens pessoais do Sanctum.
- Testes automatizados de frontend além de lint e typecheck.

## Decisions

### 1. Backend API + BFF de sessão (Sanctum stateful + Fortify)

O backend expõe JSON REST sob `/api`. O browser nunca fala com o Laravel: as server routes do Nuxt agem como BFF. O fluxo de login chama o Fortify headless (`/login`, `/logout`, recuperação de senha), o BFF captura os cookies de sessão e CSRF do Laravel e os guarda selados em um cookie httpOnly `onefisc_session` no domínio do frontend (mesma origem do browser). Cada requisição seguinte do BFF ao Laravel reenvia esses cookies e o header `X-XSRF-TOKEN`. O Sanctum stateful (`EnsureFrontendRequestsAreStateful` + domínio do BFF em `SANCTUM_STATEFUL_DOMAINS`) faz as rotas `/api` autenticarem pela sessão.

Alternativas descartadas: SPA stateful direto (browser→Laravel com CORS + credentials, expõe o backend e complica CSRF cross-site); Bearer token em localStorage (exposição a XSS e gestão de expiração desnecessária nesta fase).

### 2. Contexto de Account resolvido no backend

Toda entidade de negócio tem `account_id`. Um middleware `resolve.account` roda em `/api` e define a Account efetiva: a do User autenticado, ou a Account alvo da sessão quando o super_admin está atuando via Account Switcher. O valor fica em um singleton `CurrentAccount` (`app()->instance`). Um concern `BelongsToAccount` aplica global scope pelo `CurrentAccount` e preenche `account_id` na criação. Rotas de plataforma (criar/listar Accounts, trocar Plan) ignoram o escopo.

Alternativas descartadas: banco por Account (complexidade operacional sem benefício nesta escala); `account_id` explícito em cada query (risco de esquecimento e vazamento).

### 3. Roles como enum + Policies

`users.role` guarda `UserRole` string-backed (`super_admin`, `admin`, `operator`, `user`); `accounts.profile` guarda `AccountProfile` (`A`, `B`). As autorizações ficam em Policies (`AccountPolicy`, `ClientPolicy`, `InvitationPolicy`, `PlanPolicy`, `AuditLogPolicy`) e Gates auxiliares, sempre checando Role + Account efetiva. O Role super_admin só é atribuível dentro da A. A visibilidade da navegação deriva das mesmas regras expostas em `/api/me`.

Alternativas descartadas: spatie/laravel-permission (não há permissões nomeadas; quatro Roles fixos não pedem tabela); tabela própria de permissões (YAGNI).

### 4. Plans, limites e Modules

`plans` guarda `max_users`, `max_clients`, `modules` (JSON), `monthly_query_volume`, `price_cents` e `is_default`. O seeder cria Básico (padrão, 3 Users, 10 Clients, Module `clients`, 100 consultas), Intermediário e Avançado — valores do catálogo anterior. Um `PlanLimitService` checa capacidade antes de convidar, aceitar Invite, criar Client e acessar Module, lançando `ValidationException` com mensagem de upgrade (HTTP 422). Invites pendentes válidos contam para `max_users`; expirados não. A checagem vive no backend, nunca só no frontend.

Alternativas descartadas: limites em config/código (a A precisa editar sem deploy); checagem no frontend (burlável).

### 5. Invites

`invitations` guarda `account_id`, `name`, `email`, `role`, `token_hash` (único), `expires_at`, `accepted_at` e `invited_by_user_id`. O token cru só existe no link enviado por e-mail; o banco guarda o hash. Expiração em 7 dias. O aceite cria o User vinculado à Account com o Role do Invite, define a senha e marca `accepted_at`. Invite pendente é único por e-mail na Account; e-mail já existente na plataforma é rejeitado; admin revoga Invite pendente. O Invite inicial do admin de uma Account B é criado pelo `AccountProvisioningService` no ato da criação da Account.

Alternativa descartada: link mágico sem senha (o Fortify já cobre senha e recuperação).

### 6. Account Switcher do super_admin

A Account alvo da atuação vive na sessão do Laravel (`switch_account_id`), o que funciona através do cookie bridge do BFF. O middleware `resolve.account` só honra esse valor para super_admin e valida que a Account existe; qualquer outro Role é ignorado. Cada entrada/saída gera evento de Audit. O `/api/me` devolve o estado de atuação para o banner persistente; a saída é explícita (`DELETE /api/switch`). O vínculo do User não muda.

Alternativa descartada: impersonation com token separado (sessão já é o mecanismo de auth e não adiciona segurança relevante aqui).

### 7. Audit

`audit_logs` guarda `actor_user_id` (nullable para eventos de sistema), `origin_account_id`, `target_account_id` (nullable), `action`, `metadata` (JSON) e `created_at`. Um `AuditObserver` cobre eventos de modelo de Account, User, Invitation, Plan, Client e os eventos explícitos do Account Switcher/auth via `AuditService::record`. O observer nunca lança exceção: falha vira log técnico (best-effort). Não existem endpoints de escrita para Audit; a consulta filtra por Account, ator e período com paginação. Visibilidade: super_admin vê tudo, admin vê a própria Account, operator/user são negados.

Alternativa descartada: pacote de activity log (o esquema fica sob controle e evita dependência extra).

### 8. Onboarding e fechamento do registro

`GET /api/onboarding/status` informa se a base está vazia. Com base vazia, `POST /api/onboarding` cria, em transação, a Account A + o primeiro User super_admin e autentica a sessão. Com base populada, o endpoint responde indisponível. Não existe rota de registro público; a entrada posterior é sempre por Invite (`POST /api/invitations/{token}/accept`).

### 9. Contrato da API e frontend

JSON em `snake_case` (convenção Laravel/Eloquent, sem camada de transformação). Erros no formato padrão do Laravel: 401 não autenticado, 403 negado, 422 `{message, errors}`; listas usam o paginador do Laravel. Rotas principais: `/api/me`, `/api/onboarding`, `/api/auth/*` (proxy), `/api/accounts`, `/api/plans`, `/api/clients`, `/api/invitations`, `/api/audit`, `/api/switch`.

No frontend: shell do template Nuxt UI com `UDashboardSidebar` e navegação filtrada por Role; páginas `onboarding`, `login`, `forgot-password`, `reset-password`, `invite/[token]`, `accounts/` (A), `plans/` (A), `clients/`, `audit/` (A + admin); componente `AccountSwitcher` com banner "atuando como" e saída; composables `useMe`/`usePermissions` alimentados por `/api/me`; server routes em `server/api/**` fazendo o proxy com o cookie selado. Formulários validam com Zod e reproduzem as mensagens do backend.

### 10. Testes e verificação

Backend: testes de feature em PHPUnit cobrindo isolamento por Account, Onboarding, Invites (expiração, duplicidade, limite), PlanLimitService, Roles/policies, Account Switcher, Audit e autenticação. SQLite em memória conforme `phpunit.xml`; factories para todas as entidades. `composer test`/`php artisan test` como gate. Frontend: `pnpm lint` e `pnpm typecheck` (o template não traz runner de testes; risco aceito nesta fase e registrado abaixo).

## Risks / Trade-offs

- [Bridge de cookies BFF mal implementado vaza ou perde sessão] → cookie selado httpOnly, same-origin, reenvio explícito de cookie + CSRF no proxy; testes de feature cobrindo 401 pós-logout e CSRF inválido.
- [Esquecer `account_id` em entidade nova vaza dados] → concern com global scope como rede de segurança + teste de isolamento por entidade; revisão de schema.
- [Account Switcher esquecido aberto amplia o blast radius] → banner persistente, saída explícita, estado preso à sessão e Audit de cada acesso.
- [Falha no Audit quebrar a operação] → observer com try/catch e log técnico; Audit best-effort.
- [Limites de Plan sujeitos a corrida (dois Invites simultâneos)] → checagem dentro de transação; escala inicial torna o risco aceitável.
- [Frontend sem testes automatizados] → comportamentos críticos cobertos no backend; lint/typecheck no frontend; runner pode ser adicionado em change futuro.
- [SERPRO fora de escopo pode confundir expectativa na UI] → tela de Clients expõe apenas o Monitoring Status, sem prometer sincronização.

## Migration Plan

1. Backend: instalar Sanctum (`php artisan install:api`) e Fortify (`composer require laravel/fortify`), publicar configurações e habilitar o middleware stateful com o domínio do BFF.
2. Criar migrations na ordem `plans` → `accounts` → `users.account_id/role` → `clients` → `invitations` → `audit_logs` e rodar com `php artisan migrate --seed` (seeder dos 3 Plans).
3. Implementar enums, models, concern de escopo, middleware, policies, services, observer e rotas `/api`; validar com a suíte PHPUnit.
4. Frontend: implementar server routes do BFF, `/api/me`, navegação por Role e as páginas da fundação; configurar `BACKEND_URL` e `SESSION_SECRET` no `.env`.
5. Rodar Onboarding em base limpa para criar a Account A e conferir o fluxo de Invite do admin de uma Account B.
6. Rollback: `php artisan migrate:rollback` derruba as tabelas novas; sem dados legados a preservar (base de dev é recriável com `migrate:fresh --seed`). **BREAKING** já registrado no proposal: não há auto-cadastro público.
