## 1. Fundação do backend

- [x] 1.1 Instalar Sanctum com `php artisan install:api` e Fortify com `composer require laravel/fortify`; publicar configs e verificar `php artisan route:list` sem erros e `composer test` verde.
- [x] 1.2 Garantir SQLite em memória e `MAIL_MAILER=array` no `backend/phpunit.xml` e criar `tests/TestCase.php` com helpers de criação de Account/User; verificar um teste de fumaça passando.
- [x] 1.3 Criar enum `AccountProfile` (A/B) e enum `UserRole` (super_admin/admin/operator/user); teste unitário cobre valores e rejeição de valor inválido.
- [x] 1.4 Migration, model, factory e schema test de `plans` (`max_users`, `max_clients`, `modules` JSON, `monthly_query_volume`, `price_cents`, `is_default`); teste de persistência e casts.
- [x] 1.5 Migration, model, factory e schema test de `accounts` (name, profile, `plan_id` nullable); teste cobre perfil A/B e relação com Plan.
- [x] 1.6 Migration `users.account_id` + `users.role` e ajuste do model User (relação com Account); teste cobre vínculo e default `user`.
- [x] 1.7 Migration, model e factory de `clients` (cnpj, razao_social, regime, contador_responsavel, `monitoring_enabled`, unique `account_id+cnpj`); teste cobre unique por Account e CNPJ repetido entre Accounts.
- [x] 1.8 Migration, model e factory de `invitations` (name, email, role, token_hash unique, expires_at, accepted_at, invited_by_user_id); teste cobre token único e data de expiração.
- [x] 1.9 Migration, model e factory de `audit_logs` (actor nullable, origin_account_id, target_account_id, action, metadata JSON, created_at); teste cobre persistência e ausência de updated_at.
- [x] 1.10 PlanSeeder com Básico (padrão, 3 Users, 10 Clients, Module clients, 100 consultas), Intermediário e Avançado; teste confirma os 3 planos e exatamente um padrão.

## 2. Contexto de Account e Roles

- [x] 2.1 Implementar singleton `CurrentAccount` e concern `BelongsToAccount` (global scope + preenchimento de `account_id`); teste de isolamento prova que leitura/escrita de outra Account não aparece.
- [x] 2.2 Implementar middleware `resolve.account` (Account do User ou alvo do Account Switcher válido apenas para super_admin) e registrá-lo nas rotas `/api`; teste cobre conta efetiva, alvo inválido ignorado e Role não-super_admin ignorado.
- [x] 2.3 Implementar Policies/Gates de Account, Plan, Client, Invitation e AuditLog por Role + Account; teste por Role cobre permitido e negado de cada Role conforme `specs/roles`.
- [x] 2.4 Implementar `GET /api/me` com User, Account, Plan, Role e estado do Account Switcher; teste cobre 401 sem sessão e payload correto autenticado.

## 3. Autenticação

- [x] 3.1 Configurar Fortify headless (login, logout, recuperação/redefinição de senha, rate limiting, respostas JSON) e Sanctum stateful com o domínio do BFF; testes cobrem login válido/inválido genérico, logout, reset válido e token expirado conforme `specs/authentication`.
- [x] 3.2 Testes de integração do contrato de sessão do BFF: requisição autenticada, 401 após logout e rejeição de CSRF inválido; evidência no `php artisan test`.

## 4. Onboarding e Invites

- [x] 4.1 Implementar `GET /api/onboarding/status` e `POST /api/onboarding` transacional (Account A + super_admin + sessão); testes cobrem base vazia criando A e base populada negando.
- [x] 4.2 Implementar `InvitationService::invite` (token hash de uso único, 7 dias, Role válido, e-mail único, Invite pendente único e limite de Users); testes cobrem cada negação e o Invite válido.
- [x] 4.3 Implementar `InvitationService::accept` e revogação, com notificação por e-mail (`MAIL_MAILER=array` nos testes); testes cobrem aceite válido, expirado, já usado, limite no aceite e revogação.
- [x] 4.4 Implementar `AccountProvisioningService` e `GET/POST /api/accounts` (somente super_admin; nasce perfil B no Plan padrão + Invite do admin inicial); testes cobrem criação completa e negação fora da A.

## 5. Plans e limites

- [x] 5.1 Implementar `PlanLimitService` (Users contando Invites pendentes válidos, exclusão do próprio Invite no aceite, Clients, Modules e volume) com `ValidationException` de upgrade; testes cobrem limite exato, estouro e Invites expirados ignorados.
- [x] 5.2 Implementar `GET/POST/PATCH /api/plans` e `PATCH /api/accounts/{account}/plan` restritos à A, com efeito imediato; testes cobrem catálogo, criação de Plan, troca pela A e negação para admin de B.

## 6. Clients

- [x] 6.1 Implementar CRUD `GET/POST/PATCH/DELETE /api/clients` com busca/paginação, validação de CNPJ, regime e contador, unique por Account e limite do Plan; testes cobrem cadastro completo, inválido, CNPJ repetido na Account, busca, edição, exclusão e isolamento entre Accounts.
- [x] 6.2 Implementar o toggle de Monitoring Status por Client; testes cobrem ativação/desativação persistidas e refletidas na listagem conforme `specs/clients`.
- [x] 6.3 Garantir que operator administra Clients e user é negado; testes de autorização por Role para cada operação.

## 7. Account Switcher e Audit

- [x] 7.1 Implementar `POST/DELETE /api/switch` e o estado de atuação no `/api/me` (somente super_admin, alvo validado, vínculo preservado); testes cobrem entrada, dados escopados à alvo, saída e negação para outros Roles.
- [x] 7.2 Implementar `AuditService` + `AuditObserver` cobrindo Accounts, Users, Invitations, Plans, Clients e eventos do Account Switcher, com best-effort (falha não quebra a operação); testes cobrem registro de cada grupo e operação concluída com Audit falhando.
- [x] 7.3 Implementar `GET /api/audit` com filtros de Account, ator e período, paginação e visibilidade (super_admin tudo, admin própria Account, operator/user negado); testes cobrem cada filtro, cada Role e a ausência de rotas de edição/exclusão.

## 8. Frontend: BFF e sessão

- [x] 8.1 Implementar server routes do BFF (`/api/auth/login`, `/logout`, `/forgot-password`, `/reset-password`, `/api/me` e proxy autenticado) com cookie selado httpOnly e envs `BACKEND_URL`/`SESSION_SECRET`; verificação com `pnpm typecheck` e smoke manual de login/logout.
- [x] 8.2 Implementar middleware de rota do Nuxt (redirecionar visitante para login; encaminhar para Onboarding quando a base estiver vazia; liberar aceite de Invite); verificação com `pnpm typecheck` e smoke manual dos três casos.

## 9. Frontend: shell e páginas

- [x] 9.1 Adaptar o shell do template com navegação filtrada por Role e composables `useMe`/`usePermissions`; verificação com `pnpm typecheck` e conferência visual dos menus por Role.
- [x] 9.2 Implementar páginas de login, recuperação e redefinição de senha com validação Zod e mensagens do backend; verificação com `pnpm typecheck` e smoke manual de erro/sucesso.
- [x] 9.3 Implementar página de Onboarding e página de aceite de Invite (`/invite/[token]`); verificação com `pnpm typecheck` e smoke manual de Invite válido e expirado.
- [x] 9.4 Implementar área da A: lista/criação de Accounts e catálogo/edição de Plans; verificação com `pnpm typecheck` e smoke manual de criar Account B com Invite.
- [x] 9.5 Implementar carteira de Clients (lista com busca, cadastro, edição, exclusão e toggle de Monitoring Status); verificação com `pnpm typecheck` e smoke manual do fluxo completo.
- [x] 9.6 Implementar `AccountSwitcher` com banner persistente "atuando como", saída explícita e página de Audit com filtros; verificação com `pnpm typecheck` e smoke manual de entrar/sair e ver eventos.

## 10. Verificação final

- [x] 10.1 Rodar `composer test` no backend e colar a saída como evidência; corrigir qualquer falha de Pint/PHPUnit.
- [x] 10.2 Rodar `pnpm lint` e `pnpm typecheck` no frontend e colar a saída como evidência.
- [x] 10.3 Smoke E2E manual em base limpa: Onboarding cria A, A cria B com Invite, admin da B entra, limites bloqueiam no estouro, super_admin atua via Account Switcher e o Audit registra tudo; registrar o passo a passo no change.
