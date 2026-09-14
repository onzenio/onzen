# Verificação final — monitoring-serpro (Task 32)

Evidências da seção 9 do `tasks.md`. Comandos rodados nos diretórios das apps; saídas resumidas (logs completos: `/tmp/final-*.log` na sessão de execução).

## 9.1 Backend — PHPUnit e Pint

```
$ composer test                      # backend/
{"tool":"phpunit","result":"passed","tests":658,"passed":658,"assertions":4339,"duration_ms":31824}

$ ./vendor/bin/pint --test           # backend/
{"tool":"pint","result":"passed"}
```

Correções aplicadas: `pint` em `tests/Feature/AccountTest.php` e `tests/Feature/ApiFoundationTest.php` (imports), revalidado com o filtro dos dois arquivos (15 passed).

## 9.2 Frontend — ESLint e typecheck

```
$ pnpm run lint                      # frontend/
exit 0 — eslint .

$ pnpm run typecheck                 # frontend/
exit 0 — nuxt typecheck
```

## 9.3 Smoke E2E em dry-run

Ambiente: backend em `127.0.0.1:8001` apontando para `/tmp/smoke.sqlite` (migrado e seedado com Plan/definições), `MONITORING_SERPRO_DRY_RUN=true` (default) e transporte desligado. Autenticação por token Sanctum do ator `super_admin`.

| Passo | Comando | Resultado |
|---|---|---|
| Health factual | `GET /api/monitoring/health` | `{"state":"gated","environment":"homologacao","dry_run":true,"gated":true,"transport_open":false}` |
| Associação | `POST /api/monitoring/enrollments` `{"client_id":1,"definition_id":"situacao-mei"}` | 201, associação `active` v1 |
| Disparo manual | `POST /api/monitoring/enrollments/1/run` | 202, run `pending` (fencing_token 1) |
| Execução na fila | `php artisan queue:work serpro --once` | job `ExecuteSerproJob` DONE em dry-run (fixture, sem tráfego) |
| Snapshot | `GET /api/monitoring/enrollments/1/snapshots` | 1 snapshot `fresh`/`complete`, `normalized=true`, fingerprint `68f14c06…`, dados da família `mei` |
| Mudança + alerta | fixture `DIVIDAATIVA24.json` alterada (`sem_divida`→`com_divida`, restaurada em seguida), run 2 + worker | `changes` total 1 com `before/after`; `alerts` total 1 `pending` |
| Reconhecimento | `POST /api/monitoring/alerts/1/acknowledge` | `status=acknowledged`, `acknowledged_by_user_id=2` |
| Quota bloqueando | 3º disparo com plano de 2 consultas | 422 `Volume de consultas do plano esgotado no período 2026-09 (2/2). Faça upgrade do plano para continuar consultando.` sem débito (seguia 2/2) |
| Emissão sem transporte | `POST /api/monitoring/enrollments/2/gerar-das` (período 202601, chave idempotente, `confirmed=true`) | 422 `{"message":"Emissão de DAS recusada.","error":"serpro_gated"}` — nenhuma chamada externa |

Frontend (SSR + BFF com `NUXT_BACKEND_URL=http://127.0.0.1:8001`):

```
GET /monitoring                     → 200 (dashboard renderiza)
GET /monitoring/parcelamentos       → 200
GET /monitoring/parcelamentos/1     → 200
GET /monitoring/enrollments/1       → 200
GET /monitoring/admin               → 401 (middleware super-admin exige sessão)
GET /api/monitoring/dashboard (BFF) → 401 {"message":"Unauthenticated."} repassado do Laravel
GET /api/monitoring/parcelas/1/guia → 401 (passthrough binário)
```

Dependência registrada (conforme plano da Task 28): o fluxo autenticado navegador→BFF depende do `multi-account-foundation` (Sanctum stateful + `EnsureFrontendRequestsAreStateful` no grupo `api` e login headless). Com cookie de sessão Fortify válido no backend, o BFF ainda recebe 401 porque o grupo `api` não é stateful hoje; a verificação autenticada foi feita no nível da API (tabela acima). Nenhum tráfego externo ocorreu: `transport_open=false` e `dry_run=true` em todos os passos.

## 9.4 Documentação

- `CONTEXT.md`: nova seção **Monitoramento SERPRO** com Contratante SERPRO, Certificado Digital, Autor do Pedido de Dados, Associação de Monitoramento e Transporte.
- `backend/README.md`: seção de operação do monitoramento (fila `serpro`, worker, gate e health, comandos agendados).
- `backend/.env.example`: comentário operacional do bloco `MONITORING_SERPRO_*` (worker, gate em runtime, health).
- `frontend/.env.example`: `NUXT_BACKEND_URL` documentado para o BFF.
