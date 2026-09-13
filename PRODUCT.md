# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary: equipe do escritório de contabilidade — contadores e operadores que gerem a carteira de clientes (CNPJ, razão social, regime tributário) e acompanham a situação fiscal no dia a dia.

Secondary: operação central (Account A — `super_admin`/`admin`) que administra a plataforma: accounts, catálogo de planos, convites e auditoria.

## Product Purpose

OneFisc é a plataforma multi-escritório: cada escritório opera sua carteira de clientes com conta isolada, papéis distintos e limites por plano de assinatura, enquanto a operação central administra tudo. Existe porque, sem esse alicerce, não há como separar carteiras, controlar acesso por nível nem monetizar por plano. Sucesso = escritórios operando carteiras isoladas + monitoramento fiscal dos clientes ativo.

## Positioning

O mecanismo que um vizinho não copiaria com verdade é o monitoramento da situação fiscal da carteira via SERPRO. Nota honesta de estado: o change atual (`multi-account-foundation`) define apenas o contrato do monitoramento; a integração SERPRO real é um change futuro e está fora de escopo agora.

## Operating Context

- Entrada: onboarding cria a primeira Account A; novos usuários entram somente por convite (7 dias, senha no aceite). Não existe auto-cadastro público.
- Operação: novas Accounts nascem no plano Básico; troca de plano é manual e exclusiva da A; `super_admin` atua em outras Accounts via seletor com banner persistente "atuando como" e saída explícita.
- Auditoria única cobrindo plataforma e operação, com filtro por Account, ator e período.
- Ambientes: dev local via `docker compose up` (backend `:8000`, frontend `:3000`, postgres/redis/nats) ou por app (`composer dev`, `pnpm dev`); SQLite fora do compose.

## Capabilities and Constraints

Planejado e ainda não implementado (`openspec/changes/multi-account-foundation/`, todas as capabilities novas, nenhuma modificada): `authentication` (Sanctum stateful + Fortify via BFF Nuxt, cookies httpOnly same-origin), `accounts` (perfis A/B), `roles` (`super_admin`/`admin`/operador/`user`, só a A tem `super_admin`), `onboarding-invites`, `plans` (3 planos: Básico padrão, Intermediário, Avançado), `clients` (carteira isolada por Account; mesmo CNPJ pode repetir), `account-switcher`, `audit`.

Terminologia canônica: Account, Plan, Client, `super_admin`, Onboarding (fonte: `openspec/config.yaml`; `CONTEXT.md` ainda não existe).

Explicitamente fora de escopo: integração SERPRO executável, monitoramento executável, cobrança/pagamento.

## Brand Commitments

- Nome OneFisc: vinculante (confirmado; hoje aparece só na infra: `docker-compose.yml`, título do `AGENTS.md`).
- Idioma PT-BR: vinculante. A UI atual em inglês é placeholder de template e deve ser substituída.
- Mundo visual herdado do `_legacy` ("Central Operacional", autoridade em `/home/obsidian/dev/oniscan/_legacy/DESIGN.md`): Public Sans, verde operacional + neutros zinc, flat-by-default — vinculante para superfícies autenticadas, login e onboarding. O template atual coincide por procedência, não por decisão própria.
- Sem logo ou assets próprios ainda (só `frontend/public/favicon.ico` placeholder e avatares remotos de placeholder).

## Evidence on Hand

- `openspec/changes/multi-account-foundation/{proposal.md,design.md,tasks.md,specs/}` — fundação planejada, tasks todas em aberto.
- A UI atual é o stock Nuxt Dashboard Template (rotas `/`, `/inbox`, `/customers`, `/settings/*`, mocks em `frontend/server/api/*`) — NÃO é evidência de produto. Copy, métricas (USD/EUR misturados), usuários e times de placeholder não devem ser tratados como verdade.
- Backend é esqueleto Laravel sem rotas de produto (`GET /` → welcome apenas).
- Ausências que trabalho futuro não deve fabricar: depoimentos, clientes, benchmarks, preços, dados fiscais reais.

## Product Principles

1. Isolamento por Account acima de tudo — dados, papéis, limites e navegação respeitam a fronteira da conta.
2. Entrada só por onboarding ou convite — nunca auto-cadastro público.
3. A central administra, o escritório opera — permissões e navegação filtradas por nível, sem vazar função da A.
4. Auditoria única — todo ato relevante de plataforma e operação é registrado e filtrável.
5. Português primeiro — o inglês do template é placeholder, não voz do produto.
