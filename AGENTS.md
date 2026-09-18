# OneFisc — Agent Instructions

Split repo: `backend/` (Laravel 13 API, PHP ^8.3) + `frontend/` (standalone Nuxt 4 app). No root `composer.json`/`package.json` — always run commands from the app dir. No `routes/api.php` yet; backend is a fresh skeleton (`GET /` → welcome only).

## Commands

Backend (`backend/`, composer + npm):

- `composer dev` — serve + queue:listen + pail + vite (Laravel multiplex)
- `composer test` — `config:clear` then `php artisan test` (PHPUnit, not Pest)
- `php artisan test --filter=Name` / `--testsuite=Unit|Feature` — single test/suite
- `./vendor/bin/pint` — manual style fix; no composer script, no `pint.json` (defaults)
- `npm run dev` / `npm run build` — backend Vite assets only (npm, not pnpm)
- No PHPStan/Pint gate exists; do not claim one passes

Frontend (`frontend/`, pnpm `12.3.4` — pinned in `package.json`):

- `pnpm install` first — `postinstall: nuxt prepare` generates `.nuxt/`, required by lint and typecheck
- `pnpm dev` (`:3000`) / `pnpm build` / `pnpm preview`
- `pnpm run lint` (`eslint .`) / `pnpm run typecheck` (`nuxt typecheck`) — this is the CI gate (`frontend/.github/workflows/ci.yml`; no root `.github/`, no frontend tests)

Full stack: `docker-compose.yml` at root — postgres:18, redis:8, nats:2 (JetStream), backend `:8000`, queue worker, frontend `:3000`. Compose overrides backend env to pgsql/redis/nats; local default is sqlite. **Dev profile: always add `-f docker-compose.dev.yml`** (host ports `5433/6380/4223/8223` to avoid colliding with the wzap stack on `5432/4222/8222/8081`; app ports stay `8000/3000`). Live bind mounts (`./backend:/app` + `backend-vendor` for vendor, `./frontend:/app` + node_modules volume) — local edits reflect without rebuild. See `README.md` for up/logs/artisan/pnpm usage.

## Architecture notes

- Apps are currently decoupled: no CORS/`BACKEND_URL` wiring; `frontend/server/api/*` are template mocks, not a Laravel proxy. The planned contract (REST `/api` snake_case, Sanctum stateful + Fortify headless, Nuxt BFF cookie) lives only in `openspec/changes/multi-account-foundation/` — read it before wiring auth/API.
- Backend entrypoint: `bootstrap/app.php` (Laravel 13 streamlined: web + console + health `/up`, JSON for `api/*`). Routes: `routes/web.php`, `routes/console.php` only.
- Frontend: Nuxt 4 `app/` dir (`pages/`, `layouts/`, `components/`, `composables/`), not root `pages/`. Template is Nuxt Dashboard Template.
- Tests force sqlite `:memory:` + array cache/session + sync queue (`backend/phpunit.xml`).

## Conventions & gotchas

- `backend/AGENTS.md` is an uninstalled laravel-boost stub — ignore its `composer require boost` instructions.
- Tailwind v4 CSS-first in both apps; no `tailwind.config.*`.
- Frontend eslint: no `commaDangle`, `1tbs` braces, `vue/max-attributes-per-line` singleline 3 (`nuxt.config.ts`, `eslint.config.mjs`).
- Generated — do not edit: `backend/vendor/`, `backend/storage/framework/views/`, `backend/database/database.sqlite`, `frontend/.nuxt/`, `frontend/node_modules/`, `frontend/pnpm-lock.yaml`.
- Commits: conventional with scope (`feat(work): ...`, `docs(specs): ...`, `chore(repo): ...`) per git history.
- Never commit `.env` (gitignored); each app has its own `.env.example`.

## Spec-Driven Workflow (OpenSpec + Superpowers)

- OpenSpec owns WHAT + WHY: `proposal.md`, `specs/`, `design.md`, `tasks.md`, plus the Delta/Archive lifecycle (`openspec-propose`, `openspec-apply-change`, `openspec-archive-change`). It is the only planning system; do not run Superpowers `brainstorming` or `writing-plans` as a parallel planner. Per-artifact rules (Out-of-Scope, `**BREAKING**`, WHEN/THEN scenarios, `[Risk] → Mitigation`) live in `openspec/config.yaml`.
- Superpowers owns HOW WELL: `brainstorming` (only to discover fuzzy requirements before proposing), `test-driven-development` (failing test first on every implementation task — no code before its test), `systematic-debugging` (on any failure), `requesting-code-review` (before each commit), `verification-before-completion` (before claiming done).
- Routing: fuzzy requirements → `brainstorming` first, output to `openspec/changes/<name>/brainstorm.md`, then `openspec-propose`. Settled requirements → `openspec-propose` directly. Applying → TDD + review per task batch with `tasks.md` as the scope contract. Finished → `openspec-archive-change` always last (sync delta specs into `openspec/specs/`); never leave a change un-archived.
- Homes (all inside `openspec/changes/<name>/`): `brainstorm.md` (pre-propose discovery), `proposal.md` + `specs/` + `design.md` + `tasks.md` (official plan), `plan.md` (execution detail via `writing-plans`, only when needed, referencing task IDs). No planning docs outside the change folder; the spec library lives only in `openspec/specs/` after archive (`specs/` is empty until first archive).
- `tasks.md` format: one sentence per task, `- [ ] X.Y ...` checkboxes, each stating how to verify; no implementation detail (that goes in `plan.md`).
- Glossary: `CONTEXT.md` is canonical (Account, Plan, Client, super_admin, Onboarding) — use its terms in every artifact; update it and `docs/adr/` when terms or decisions crystallize.
- Every mistake becomes structure: lift new gotchas into `openspec/config.yaml` rules.
