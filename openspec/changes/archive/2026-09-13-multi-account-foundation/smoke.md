# Smoke E2E — multi-account-foundation (Fase I, tasks 10.1–10.3)

Data: 2026-09-13. Base: `backend/database/database.sqlite` (DEV) após
`php artisan migrate:fresh --seed`. Servidor: `http://localhost:8001`
(`php artisan serve` já ativo no worktree; a porta `:8000` está ocupada por
outra aplicação, por isso o smoke usa `:8001`).
Contrato de sessão exercido como um browser/BFF faria: `GET /sanctum/csrf-cookie`
+ `Origin: http://localhost:3000` / `Referer: http://localhost:3000/` em todas
as chamadas e `X-XSRF-TOKEN` (valor decodificado do cookie `XSRF-TOKEN`,
renovado após cada resposta que regenera a sessão) em todo POST/PATCH/DELETE —
sem o header, a API stateful responde `419 CSRF token mismatch` (comportamento
esperado, coberto por `SessionContractTest`).
Jars: `/tmp/opencode/jarA.txt` (super_admin da A), `/tmp/opencode/jarB.txt`
(admin da B). `APP_DEBUG=true` no dev: respostas de erro trazem stack trace;
abaixo só o essencial (`message`/status).

## 0. Base limpa

```
$ php artisan migrate:fresh --seed
... DONE (migrations incl. audit_logs) ...
 Database\Seeders\PlanSeeder .. RUNNING
 Database\Seeders\PlanSeeder .. 187 ms DONE
```

## 1. Onboarding disponível

```
$ curl -s -H "Origin: http://localhost:3000" \
    http://localhost:8001/api/onboarding/status
{"available":true}
```

## 2. Onboarding cria a Account A + super_admin (201)

```
$ curl -s -c jarA -b jarA -H "Content-Type: application/json" \
    -H "Accept: application/json" -H "Origin: http://localhost:3000" \
    -H "Referer: http://localhost:3000/" -H "X-XSRF-TOKEN: $XSRF" \
    -H "X-Requested-With: XMLHttpRequest" \
    -d '{"name":"Root Admin","email":"root@onefisc.local","password":"password123","account_name":"Matriz A"}' \
    http://localhost:8001/api/onboarding
{"account":{"id":1,"name":"Matriz A","profile":"A"},
 "user":{"id":2,"name":"Root Admin","email":"root@onefisc.local","role":"super_admin"}}
```

## 3. GET /api/me autenticado (jar A)

```
{"user":{"id":2,"name":"Root Admin","email":"root@onefisc.local","role":"super_admin"},
 "account":{"id":1,"name":"Matriz A","profile":"A","plan":null},"acting_as":null}
```

## 4. Logout → 401 → login → 200 (contrato de sessão)

```
$ curl ... -X POST http://localhost:8001/logout
http=204
$ curl ... http://localhost:8001/api/me
{"message":"Unauthenticated."}
$ curl ... -d '{"email":"root@onefisc.local","password":"password123"}' \
    http://localhost:8001/login
{"two_factor":false}
$ curl ... http://localhost:8001/api/me
{"user":{"id":2,...,"role":"super_admin"},"account":{"id":1,"name":"Matriz A",...},"acting_as":null}
```

## 5. A cria B + invite de admin (201)

```
$ curl ... -d '{"name":"Filial B","admin_email":"adminb@onefisc.local","admin_name":"Admin B"}' \
    http://localhost:8001/api/accounts
{"account":{"id":2,"name":"Filial B","profile":"B","plan_id":1,...,
  "plan":{"id":1,"name":"Básico","price_cents":0,"max_users":3,"max_clients":10,
  "modules":["clients"],"monthly_query_volume":100,"is_default":true}},
 "invitation":{"account_id":2,"name":"Admin B","email":"adminb@onefisc.local",
  "role":"admin","expires_at":"2026-09-20T16:59:39.000000Z","accepted_at":null,
  "invited_by_user_id":2,...,"id":1,...}}
```

Token cru obtido do e-mail real (`MAIL_MAILER=log` →
`storage/logs/laravel.log`, subject `Você foi convidado para o OneFisc`):
`GuwWVSl3CGkj1mgc1ZD8w4PAmzJsTbvKumFOTEHlxYdV1cJTn9sW4mO01cIxdjyp`.

## 6. Aceite do invite como admin B (201) + /api/me

```
$ curl ... -d '{"password":"password123"}' \
    http://localhost:8001/api/invitations/$TOKEN/accept
{"user":{"id":3,"name":"Admin B","email":"adminb@onefisc.local","role":"admin"}}
$ curl ... http://localhost:8001/api/me   # jar B
{"user":{"id":3,"name":"Admin B","email":"adminb@onefisc.local","role":"admin"},
 "account":{"id":2,"name":"Filial B","profile":"B","plan":{"id":1,...}},"acting_as":null}
```

## 7. Estouro de limite bloqueado com mensagem de upgrade

Plano Básico: `max_users=3`. B tem 1 usuário ativo; 2 invites extras passam
(1+2=3, no limite exato), o 3.º é negado:

```
$ curl ... -d '{"name":"Extra 1","email":"extra1@onefisc.local","role":"user"}' \
    http://localhost:8001/api/invitations   # → 201 (id 2)
$ curl ... -d '{"name":"Extra 2","email":"extra2@onefisc.local","role":"user"}' \
    http://localhost:8001/api/invitations   # → 201 (id 3)
$ curl ... -d '{"name":"Overflow","email":"overflow@onefisc.local","role":"user"}' \
    http://localhost:8001/api/invitations   # → 422
{"message":"Limite de usuários do plano atingido. Faça upgrade do plano.",
 "errors":{"email":["Limite de usuários do plano atingido. Faça upgrade do plano."]}}
```

## 8. Toggle monitoring com re-verificação de persistência (item aberto da Fase H)

Client criado em B (`POST /api/clients` → 201, `monitoring_enabled:false`,
`created_at=updated_at=17:00:12`).

```
# GET antes: monitoring_enabled=false, updated_at=17:00:12
$ curl ... http://localhost:8001/api/clients/1
{"id":1,"account_id":2,"cnpj":"11222333000181",...,"monitoring_enabled":false,
 "created_at":"2026-09-13T17:00:12.000000Z","updated_at":"2026-09-13T17:00:12.000000Z"}
# (sleep 1.2s) PATCH monitoring true:
$ curl ... -X PATCH -d '{"monitoring_enabled":true}' \
    http://localhost:8001/api/clients/1/monitoring
{"id":1,...,"monitoring_enabled":true,...,"updated_at":"2026-09-13T17:00:18.000000Z"}
# GET após toggle ON: monitoring_enabled=true persisted, updated_at avançou 17:00:12 → 17:00:18
# (sleep 1.2s) PATCH monitoring false:
{"id":1,...,"monitoring_enabled":false,...,"updated_at":"2026-09-13T17:00:24.000000Z"}
# GET após toggle OFF: monitoring_enabled=false persisted, updated_at 17:00:24
```

Resultado: toggle persiste nas duas direções e `updated_at` avança a cada
mudança — item da Fase H FECHADO, nada bloqueado.

## 9. Switch enter (dados escopados = B) → exit (volta a A)

```
$ curl ... -d '{"account_id":2}' http://localhost:8001/api/switch   # jar A
{"account":{"id":2,"name":"Filial B","profile":"B"},"acting_as":{"account_id":2}}
$ curl ... http://localhost:8001/api/me   # jar A em switch
{"user":{"id":2,...,"role":"super_admin"},
 "account":{"id":2,"name":"Filial B","profile":"B","plan":{...}},"acting_as":{"account_id":2}}
$ curl ... "http://localhost:8001/api/clients?per_page=5"   # escopo = B
{"data":[{"id":1,"account_id":2,...}],"meta":{"current_page":1,...,"total":1}}
$ curl ... -X DELETE http://localhost:8001/api/switch
{"account":{"id":1,"name":"Matriz A","profile":"A"},"acting_as":null}
$ curl ... http://localhost:8001/api/me   # de volta a A
{"user":{"id":2,...},"account":{"id":1,"name":"Matriz A","profile":"A","plan":null},"acting_as":null}
$ curl ... "http://localhost:8001/api/clients?per_page=5"   # A vazio
{"data":[],"meta":{...,"total":0}}
```

## 10. Audit lista tudo (21 linhas)

`GET /api/audit?per_page=50` → `meta.total=21`, cobrindo a cadeia inteira
(ordem cronológica): `account.created` + `user.created` (onboarding A),
`auth.login` (onboarding), `auth.logout` + `auth.login` (ciclo §4),
`account.created` + `invitation.created` (provisioning B),
`user.created` + `invitation.updated` + `auth.login` (aceite B),
2× `invitation.created` (extras), `client.created`, 2× `client.updated`
(toggles), `account.switch.enter`, `account.switch.exit`.

## Observação (não-bloqueante, follow-up sugerido)

Cada `auth.login`/`auth.logout` gera 2 linhas idênticas no audit (pares
3/4, 5/6, 7/8, 13/14 — mesmo timestamp). Registro único em
`AppServiceProvider::boot` (`Event::listen(Login|Logout →
AuditAuthListener`); sem registro duplo visível) — o framework parece
disparar o evento 2× por ação neste stack. Recomendado investigar/dedupar
fora desta fase (nenhum teste de contagem exata quebrou; gates verdes).
