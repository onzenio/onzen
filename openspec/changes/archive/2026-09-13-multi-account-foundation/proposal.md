## Why

A plataforma atende múltiplos escritórios de contabilidade e precisa de Accounts isoladas, Roles distintos, Plans de assinatura e administração central pela operação principal. O repo foi reestruturado em `backend/` (Laravel API) e `frontend/` (Nuxt UI), e a base multi-account anterior não existe mais nesta estrutura. Sem esse alicerce não há como separar carteiras, controlar acesso por Role nem monetizar por Plan.

## What Changes

- Fundação de autenticação por sessão: o backend Laravel expõe Sanctum stateful + Fortify; o frontend Nuxt autentica via BFF (server routes que repassam cookies httpOnly same-origin). Registro público fechado.
- Modelo de Account com dois perfis: A (principal, criada no Onboarding) e B (escritório comum).
- Quatro Roles de User: super_admin (só na A), admin, operator e user, com fronteiras de permissão e navegação por Role.
- Onboarding cria a primeira Account A com super_admin; novos Users entram somente por Invite (7 dias, senha no aceite).
- Catálogo de Plans gerenciado pela A (3 Plans: Básico padrão, Intermediário, Avançado); novas Accounts nascem no Básico com admin convidado no ato; troca de Plan só pela A.
- Carteira de Clients isolada por Account (mesmo CNPJ pode repetir), com dados cadastrais (CNPJ, razão social, regime tributário, contador) e limites por Plan.
- Account Switcher exclusivo do super_admin, com banner persistente "atuando como" e saída explícita.
- Audit único cobrindo plataforma e operação, com filtro por Account, ator e período.
- **BREAKING**: não existe mais auto-cadastro público (`/register`); a entrada é Onboarding (base vazia) ou Invite.

## Capabilities

### New Capabilities

- `authentication`: login/logout por sessão contra o backend via BFF, User atual, recuperação de senha; sem auto-cadastro.
- `accounts`: criação e perfis de Account (A/B), vínculo User↔Account, criação de Accounts B pela A.
- `roles`: Roles super_admin/admin/operator/user, permissões por Role e visibilidade de navegação.
- `onboarding-invites`: Onboarding da primeira Account A, fechamento do registro público, Invites com expiração e senha no aceite.
- `plans`: catálogo de 3 Plans, limites (Users, Clients, Modules, volume), Plan padrão e troca exclusiva pela A.
- `clients`: carteira de Clients isolada por Account, dados cadastrais e Monitoring Status configurável por Client (estado; integração SERPRO fica para change futuro).
- `account-switcher`: Account Switcher do super_admin, contexto de atuação e Audit de acesso.
- `audit`: log único de plataforma e operação com filtros por Account, ator e período.

### Modified Capabilities

- Nenhuma. `openspec/specs/` está vazio; todas as capabilities acima são novas.

## Impact

- Backend (`backend/`): migrations `accounts`, `plans`, `clients`, `invitations`, `audit_logs` + colunas `account_id`/`role` em `users`; models e global scopes por Account; middleware de contexto de Account e do Account Switcher; Gates/Policies por Role; services de provisionamento de Account, limites de Plan e Invites; Sanctum + Fortify; observer de Audit; seed dos 3 Plans.
- Frontend (`frontend/`): server routes BFF (`/api/auth/*`, `/api/*`) para o Laravel; páginas de login/Onboarding/aceite de Invite; área da A (Accounts, Plans, Audit); carteira de Clients; `AccountSwitcher` com banner; navegação do shell filtrada por Role.
- Banco: SQLite em dev; migrations reversíveis.
- Contrato frontend↔backend: JSON REST sob `/api`, sessão por cookie; documentado no design.
- Fora de escopo: integração real SERPRO, monitoramento executável, cobrança/pagamento (troca de plano é manual pela A).
