# Plano de Execução SDD — monitoring-serpro

Execução do change `openspec/changes/monitoring-serpro` via subagent-driven development.
Fonte de verdade do escopo: `tasks.md` (este plano NÃO substitui nem contradiz as tasks; ele as agrupa e ordena por dependência e define os arquivos/interfaces).

## Global Constraints

- Rodar comandos no `backend/` (composer/php) e `frontend/` (pnpm); nunca na raiz.
- Backend: Laravel 13, PHP 8.3+, PHPUnit (não Pest), sqlite `:memory:`, cache/sessão array, queue sync nos testes (`backend/phpunit.xml`).
- Convenções do esqueleto: atributos PHP `#[Fillable]` nos models, `BelongsToAccount` + global scope, `ResolveAccount` no `/api`, JSON `snake_case`, 404 para cross-account, 403 por Role.
- Portar o runtime do `_legacy` (fonte de comportamento; ver `design.md`):
  - Código: `/home/obsidian/dev/oniscan/_legacy/apps/api/app/Contexts/Monitoring/`
  - Fixtures: `/home/obsidian/dev/oniscan/_legacy/contracts/fixtures/serpro/consultar/`
  - Specs: `/home/obsidian/dev/oniscan/_legacy/openspec/specs/{monitoring-serpro,serpro-laravel-runtime,platform-serpro-credentials,monitoring-procuracoes,monitoring-parcelamentos,monitoring-pgdas-das-emission,account-certificate}/spec.md`
- Fail-closed: `MONITORING_SERPRO_DRY_RUN=true` e transporte desligado por padrão; ambiente default `homologacao`.
- Segurança: PFX, senha, consumer secret, tokens e conteúdo fiscal nunca em log, DTO serializado, resposta de API ou fila de erros; refs de cofre `secret:`.
- Sem novos componentes de UI: usar apenas componentes do template (`UTable`, `UBadge`, `UButton`, `UDropdownMenu`, `UModal`, `USlideover`, `UCard`, `UInput`, `USelect`, `UAlert`, `UProgress`, `UIcon`) e padrões existentes (`customers.vue`, `customers/AddModal.vue`, `settings/*`); copy pt-BR.
- TDD quando a task tiver lógica; todo implementer roda os testes focados durante o trabalho e a suíte antes de commitar; `composer test` e `pnpm lint`/`pnpm typecheck` são os gates.
- Commits convencionais com escopo: `feat(monitoring): ...`, `test(monitoring): ...`, `chore(monitoring): ...`.
- Implementer nunca dispara subagent; review vem do controller.
- Se a task depender de interface ainda não criada, definir contract/interface e testar com fake; implementação real chega na task dona.
- Bridge conhecido: `multi-account-foundation` incompleta na base (sem `AuditService`, `PlanLimitService`, BFF/frontend). `AuditService` mínimo é criado na Task 1 e pode ser consolidado quando aquele change landar; quota é serviço próprio (Task 17).

## Task map (tasks.md → plano)

- 1.1–1.3 → Task 1
- 1.4 → Task 2
- 1.5 → Task 3
- 1.6 → Task 4
- 1.7 → Task 5
- 7.1–7.3 → Task 6
- 2.1–2.2 → Task 7
- 2.3 → Task 8
- 2.4 → Task 9
- 2.5 → Task 10
- 4.1 → Task 11
- 4.2 → Task 12
- 5.1 → Task 13
- 3.1 → Task 14
- 3.2 → Task 15
- 3.3 → Task 16
- 3.4 → Task 17
- 3.5 → Task 18
- 3.6 → Task 19
- 4.3 → Task 20
- 4.4 → Task 21
- 5.2 → Task 22
- 5.3 → Task 23
- 5.4 → Task 24
- 5.5 → Task 25
- 6.1–6.2 → Task 26
- 6.3–6.4 → Task 27
- 8.1, 8.6 → Task 28
- 8.2 → Task 29
- 8.3 → Task 30
- 8.4–8.5 → Task 31
- 9.1–9.4 → Task 32 (verificação final; controller conduz com evidências)

---

## Task 1 — Schema: credenciais do Contratante, Certificado Digital e Autor

**Tasks.md:** 1.1, 1.2, 1.3
**Depende:** base `multi-account-foundation` (Account, User, AuditLog, UserRole, BelongsToAccount).

Entregar:
- Migrations `serpro_contracts` (environment único, `credential_ref` nullable, `updated_by_user_id`), `serpro_settings` (singleton: `environment` default `homologacao`, `transport_approved` default false, `transport_approved_at`, `transport_approved_by_user_id`), `account_certificates` (account_id único, `vault_ref`, `holder_name`, `thumbprint`, `expires_at`, `uploaded_by_user_id`), `serpro_request_authors` (account_id, `document`, `document_type` 1|2, `name`, `status` active|ineligible, `certificate_thumbprint`, `certificate_expires_at`, metadata json).
- Models com `#[Fillable]`, casts e relações; `AccountCertificate` e `SerproRequestAuthor` usam `BelongsToAccount`. `SerproContract`/`SerproSettings` são de plataforma (sem scope de Account).
- `app/Services/AuditService.php` mínimo: `record(?User $actor, string $action, array $metadata = [], ?Account $account = null): AuditLog` gravando `AuditLog` (origin_account_id = a Account afetada: `$account` > account do ator > CurrentAccount > Account A) sem lançar exceção em falha (best-effort, log técnico; insert em savepoint para não abortar transação externa em Postgres). Callers devem passar a Account afetada. Este é o bridge até o `AuditObserver` do multi-account.
- Factories para as quatro tabelas; testes de schema/persistência, troca de certificado substitui versão e registra Audit, vínculo do autor ao certificado e inelegibilidade por expiração, isolamento entre Accounts.

Gate: `php artisan test --filter=SerproContractTest|AccountCertificateTest|SerproRequestAuthorTest|AuditServiceTest` verde e suíte completa verde; `composer test`.

## Task 2 — Catálogos e definições do Integra Contador

**Tasks.md:** 1.4
**Depende:** Task 1 (definições não dependem de Account, mas o seeder roda junto do AuditService).

Entregar:
- Port de `ConsultCatalog` (definições → operações de consulta, módulos, fixture keys, operações proibidas no polling) e `ProcurationCatalog` (allowlist Serviços×Procuração, roteamento `/Consultar|/Apoiar|/Monitorar|/Declarar|/Emitir`, `versaoSistema`, `systemFor`), adaptando namespaces para `App\Integrations\Serpro`.
- `ConsultOperationResolver` (resolve operação por definição; erro explícito quando não há operação executável).
- Migration/model/factory `monitoring_definitions` + seeder com as definições do legado (pgdas-declaracoes, regime-apuracao, defis, situacao-mei, dctfweb, mit, situacao-fiscal, caixa-postal, dte, pagamentos, 8 modalidades de parcelamento, prospecção paex/sipade) e operações vindas do catálogo.
- Testes: cada definição disponível resolve operação; indisponível/prospecção não resolve; allowlist rejeita código fora; seeder reproduz os códigos oficiais (ex.: PGDAS `CONSDECLARACAO13`, SITFIS `SOLICITARPROTOCOLO91`/`RELATORIOSITFIS92`).

Gate: `php artisan test --filter=ConsultCatalogTest|MonitoringDefinitionTest` + suíte.

## Task 3 — Cofre local

**Tasks.md:** 1.5
**Depende:** Task 1.

Entregar:
- `app/Contracts/VaultResolver.php` e `app/Services/Vault/LocalVault.php`: `put(string $ref, array|string $value)`, `get(string $ref)`, `forget(string $ref)`, com cifra por chave da aplicação (`Crypt`), refs `secret:...` obrigatórias, valores array serializados; binding em `AppServiceProvider`.
- Testes: roundtrip string/array; ref inválida recusada; valor cifrado não aparece em claro no storage; `forget` remove; nada sensível em `json_encode` do model.

Gate: `php artisan test --filter=LocalVaultTest` + suíte.

## Task 4 — Configuração e variáveis de ambiente

**Tasks.md:** 1.6
**Depende:** nada além da base.

Entregar:
- `backend/config/monitoring.php` com `base_url`, `token_url`, `environment` (`homologacao`), `dry_run` (true), `transport.approved` (false), `timeout`, `connect_timeout`, `role_type`, `queue` (`serpro`), `queue_connection`, `fixtures_path` (`resources/fixtures/serpro/consultar`), `limits.max_attempts`; todas sobreponíveis por `MONITORING_SERPRO_*`.
- Bloco `MONITORING_SERPRO_*` no `backend/.env.example` com os defaults.
- Testes: defaults; override por env; `dry_run` e `transport.approved` default false.

Gate: `php artisan test --filter=MonitoringConfigTest` + suíte.

## Task 5 — Fixtures oficiais e provider

**Tasks.md:** 1.7
**Depende:** Task 2 (fixture keys) e Task 4 (path).

Entregar:
- Copiar as fixtures de `/home/obsidian/dev/oniscan/_legacy/contracts/fixtures/serpro/consultar/` para `backend/resources/fixtures/serpro/consultar/` (todas as operações existentes no legado).
- `ConsultFixtureProvider` em `App\Integrations\Serpro`: carrega fixture pela operação/chave; retorna null quando ausente; rejeita payload de declaração (`TRANSDECLARACAO11`) como no legado.
- Testes: cada operação com fixture carrega e contém os campos-base; operação sem fixture retorna null; arquivo malformado falha de forma explícita.

Gate: `php artisan test --filter=ConsultFixtureProviderTest` + suíte.

## Task 6 — Artefatos: storage privado, hash, decodificação e download

**Tasks.md:** 7.1, 7.2, 7.3
**Depende:** Task 4 (config), Task 1 (AuditService).

Entregar:
- `app/Contracts/ArtifactStore.php` + `app/Services/Artifacts/LocalArtifactStore.php`: gravar conteúdo em disco privado (`storage/app/private/monitoring`), retornar `storage_ref` opaco + `hash_sha256`; ler; apagar; nunca expor caminho físico.
- `ConsultArtifactStore` (port do legado): varre campos `*pdf|*xml|*base64`, decodifica, gera nome oficial quando `nomeArquivo*` existir; falha de decodificação registra estado de artefato e não quebra a execução.
- Rota `GET /api/monitoring/artifacts/{ref}/download`: autoriza pela Account do Client, URL/stream temporário, Audit do acesso; 404 cross-account, 403 link expirado, 503 storage indisponível.
- Testes: gravação/hash/leitura; caminho físico ausente na resposta; decodificação feliz e falha; download autorizado, 404, 403 e 503.

Gate: `php artisan test --filter=ArtifactTest|ConsultArtifactStoreTest` + suíte.

## Task 7 — Envelope de 3 partes e resolução de credencial/token

**Tasks.md:** 2.1, 2.2
**Depende:** Tasks 1, 3, 4.

Entregar:
- `SerproEnvelope`: monta `contratante`, `autorPedidoDados`, `contribuinte` (tipo 1 PF ≤11 dígitos, 2 PJ; `person_type` prevalece), `pedidoDados {idSistema, idServico, versaoSistema, dados}`; `dados` default por operação (OBTERPROCURACAO41, ENVIOXMLASSINADO81, GERARDAS12, cadeia PGDAS) e `parameters` para as demais.
- `SerproCredentialResolver` + `OAuthTokenCache`: resolve credencial do Contratante a partir do cofre (`secret:`) aceitando JSON (`client_id`/`e_cnpj`, `consumer_secret`, `contratante_doc`) ou par; cache de token por ambiente/ref com TTL `expires_in - 30`; limpa cache em 401/403; exige refs `secret:`.
- Testes: envelope PF/PJ e por operação; resolução JSON e par; cache hit/miss/TTL; limpeza em 401; ref inválida recusada.

Gate: `php artisan test --filter=SerproEnvelopeTest|SerproCredentialResolverTest` + suíte.

## Task 8 — Transporte HTTP OAuth + mTLS

**Tasks.md:** 2.3
**Depende:** Tasks 7, 3.

Entregar:
- `app/Contracts/SerproTransport.php` + `HttpOAuthMtlsTransport` (port): token `POST` form-urlencoded com Basic auth e `Role-Type`; `call()` `POST {base_url}{path}` com `Bearer`, `X-Request-Tag`, `autenticar_procurador_token` e `jwt_token` quando houver; timeout/connect_timeout.
- Certificado resolvido do cofre gravado em arquivo temporário com permissão restrita por chamada e removido em `finally` (sucesso e falha).
- Testes com HTTP fake (`Http::fake`): headers corretos; mTLS configurado com o arquivo; arquivo removido em sucesso e em exceção; timeout respeitado.

Gate: `php artisan test --filter=HttpOAuthMtlsTransportTest` + suíte.

## Task 9 — Gate efetivo e health

**Tasks.md:** 2.4
**Depende:** Tasks 1, 4.

Entregar:
- `SerproTransportGate`: `environment()` (settings > env), `isTransportOpen()` (approved no painel vence `.env`), `isGated()`, credencial efetiva; `transportState()`.
- `GET /api/monitoring/health` retornando `gated|configured|unavailable|degraded` derivado do gate efetivo (não só `.env`).
- Testes: cada um dos quatro estados; painel ligado com env desligado → configured; credencial ausente → unavailable.

Gate: `php artisan test --filter=SerproTransportGateTest|MonitoringHealthTest` + suíte.

## Task 10 — Rotas de administração SERPRO (Account A)

**Tasks.md:** 2.5
**Depende:** Tasks 1, 9.

Entregar:
- `GET /api/admin/serpro`, `POST /api/admin/serpro/credentials`, `POST /api/admin/serpro/environment`, `POST /api/admin/serpro/transport` restritos a `super_admin` (policies/abilities existentes); credenciais somente mascaradas; produção exige dupla confirmação + evidência; desligar imediato.
- Testes: super_admin troca credenciais (Audit), alterna ambiente com/sem confirmação, liga/desliga transporte; admin/operator/user 403; payload nunca devolve segredo.

Gate: `php artisan test --filter=AdminSerproTest` + suíte.

## Task 11 — Termo de autorização e token do procurador

**Tasks.md:** 4.1
**Depende:** Tasks 1, 3, 8.

Entregar:
- `ProcuradorTermService` (port): monta XML `termoDeAutorizacao` (sistema "API Integra Contador", contratante, assinadoPor), assina PKCS#12 com RSA-SHA256 e C14N, envia via `ENVIOXMLASSINADO81`, parseia token e validade, persiste no cofre (`secret:procurador-token-*`), renova quando <24h do vencimento, suporta resposta 304/ETag.
- Smoke/dry-run: com transporte desligado, nada é enviado (fail-closed).
- Testes com transporte fake: XML assinado válido (verificação de assinatura), envio, token persistido no cofre, renovação antecipada, 304, transporte desligado recusa.

Gate: `php artisan test --filter=ProcuradorTermTest` + suíte.

## Task 12 — Verificação de procuração por Client

**Tasks.md:** 4.2
**Depende:** Tasks 7, 8, 11.

Entregar:
- `ProcurationVerificationCall` (monta `OBTERPROCURACAO41`) + `ProcurationVerifier` (parse `dtexpiracao`/`sistemas`, códigos válidos, grupos exigidos, cache 24h, aplica outorgas por Client/definição).
- `ProcurationA1Authenticator`: aceita apenas Certificado Digital ativo e válido da Account; valida client/validade.
- Testes com fixture/transporte fake: outorga confirmada, ausente, expirada, cache evita nova chamada, código fora da allowlist recusado, certificado expirado bloqueia.

Gate: `php artisan test --filter=ProcurationVerifierTest|ProcurationA1AuthenticatorTest` + suíte.

## Task 13 — Associação de Monitoramento

**Tasks.md:** 5.1
**Depende:** Tasks 1, 2.

Entregar:
- Migration/model/factory `monitoring_enrollments` (account_id, client_id, definition_id, status active|paused|ended, pause_reason, version, configuration json, last_change_at) com `BelongsToAccount`; unique(account_id, client_id, definition_id) com reativação de associação encerrada.
- `MonitoringScheduler` (parte de criação/listagem): rotas `GET/POST /api/monitoring/enrollments`, `GET/PATCH /api/monitoring/enrollments/{id}`, `DELETE` (encerrar); valida Client da Account, Monitoring Status ativo, definição disponível com operação resolvível, elegibilidade por tipo de pessoa/regime; fencing por `version`; busca por nome/CNPJ do Client; paginação; 404 cross-account; `admin`+`operator` escrevem, `user` lê.
- Testes: criação feliz, recusas (status inativo, definição indisponível, duplicada, cross-account), busca, ciclo de vida com versionamento, encerramento preserva histórico.

Gate: `php artisan test --filter=MonitoringEnrollmentTest` + suíte.

## Task 14 — Execuções, tentativas e máquina de estados

**Tasks.md:** 3.1
**Depende:** Task 13.

Entregar:
- Migrations/models `monitoring_runs` (trigger, operation_code, idempotency_key único, fencing_token, status pendente|executando|aguardando_protocolo|concluido|limitado|transitorio|rejeitado|expirado|falho, environment, dry_run, protocol, eta, parameters, external_code) e `monitoring_attempts`.
- `SerproExecutor` (port) com transições, idempotência (repetir retorna execução existente sem novo tráfego), fencing (resultado de run superado descartado), `ResultProjector` injetado por contract (fake nos testes).
- Testes: transições felizes e de erro; repetição idempotente; descarte por fencing; estado de protocolo/eta.

Gate: `php artisan test --filter=SerproExecutionTest|MonitoringRunTest` + suíte.

## Task 15 — Jobs, fila `serpro` e worker

**Tasks.md:** 3.2
**Depende:** Task 14.

Entregar:
- `ExecuteSerproJob` e `ExecuteSerproActionJob` (fila `serpro`, tries/backoff, release por ETA/retry_after, `failed()` com estado `queue_attempts_exhausted`), conexão `serpro` no `config/queue.php` (database).
- Worker `serpro` no `docker-compose.yml` (ou flag de fila no worker existente, se já houver).
- Testes: dispatch para a fila certa; tentativas/backoff; release por ETA; falha esgotada marca estado.

Gate: `php artisan test --filter=ExecuteSerproJobTest` + suíte.

## Task 16 — Classificação de resposta, retry e polling

**Tasks.md:** 3.3
**Depende:** Task 14.

Entregar:
- `ResponseClassifier` (expired, awaiting_protocol, 429, timeout/5xx transitório, 4xx definitivo, sucesso) + `ConsultMessageClassifier` (`MSG_ISN_*` inválidos/transitórios, `Sucesso-PGDASD`) + `ProtocolPoller` (poll por protocolo com cache para redelivery).
- Wires no executor: 429/timeout/5xx → retry com backoff; rejeição definitiva encerra; protocolo pendente → aguardando + polling sem nova consulta.
- Testes: cada classificação; retry e backoff; polling; rejeição sem retry.

Gate: `php artisan test --filter=ResponseClassifierTest|ProtocolPollerTest` + suíte.

## Task 17 — Quota agregada do Plan

**Tasks.md:** 3.4
**Depende:** Tasks 13, 14.

Entregar:
- `QueryQuotaService`: reserva atômica de uma unidade de `Plan.monthly_query_volume` por Account/ciclo mensal (America/Sao_Paulo), contabilizando manual e automática, com registro de consumo imutável; sem saldo → `ValidationException` 422 com mensagem de upgrade; sem débito quando bloqueado.
- Integração no scheduler/executor antes de qualquer tráfego.
- Testes: consumo e saldo; esgotamento sem débito; concorrência (duas reservas simultâneas não ultrapassam o teto); automática consome.

Gate: `php artisan test --filter=QueryQuotaTest` + suíte.

## Task 18 — Scheduler: disparo manual e ciclo automático

**Tasks.md:** 3.5
**Depende:** Tasks 13, 14, 16, 17.

Entregar:
- `POST /api/monitoring/enrollments/{id}/run` (manual; 202) e `POST /api/monitoring/sync` (lote da carteira); comando `monitoring:run-monthly-cycle` (dia 1, apenas Consultar, definições automáticas, trigger `automatic`), agendado em `routes/console.php`.
- Regras: client Monitoring Status ativo, associação ativa, quota reservada, idempotência por enrollment+fencing+trigger+minuto; `user` 403; nada externo na requisição HTTP.
- Testes: 202 e enfileiramento; ciclo só definições automáticas; trigger/origem; bloqueio por quota/status; 403.

Gate: `php artisan test --filter=MonitoringSchedulerTest|MonthlyCycleCommandTest` + suíte.

## Task 19 — Eventos operacionais e redaction

**Tasks.md:** 3.6
**Depende:** Task 14.

Entregar:
- Eventos estruturados `serpro_run_started`/`serpro_run_finished`/`serpro_action_finished` com IDs opacos (Account, Client, run, definição, resultado) e redaction aplicada a mensagens de erro.
- Testes: payload contém os IDs e não contém segredo/token/PFX/XML; mensagem sensível redigida; falha do emissor não quebra a execução.

Gate: `php artisan test --filter=SerproOperationalEventsTest` + suíte.

## Task 20 — Pausa/retomada por outorga e divergências

**Tasks.md:** 4.3
**Depende:** Tasks 12, 13.

Entregar:
- Pausa automática da associação com motivo `outorga pendente` quando a verificação falha; retomada após verificação positiva; rotas `GET /api/monitoring/powers-of-attorney/divergences` (por carteira) e leitura de procuração por Client.
- Testes: pausa/retomada; impedimento de execução em pausa; divergências com motivo; 404 cross-account.

Gate: `php artisan test --filter=ProcurationPauseTest|DivergencesTest` + suíte.

## Task 21 — Rotina diária de renovação

**Tasks.md:** 4.4
**Depende:** Task 20.

Entregar:
- Comando `monitoring:warm-procuracoes` (renova termos, reverifica `OBTERPROCURACAO41`, retoma pausas) agendado diariamente; fail-closed com transporte desligado.
- Testes: comando renova token vencendo; retoma associação elegível; não quebra com gate fechado.

Gate: `php artisan test --filter=WarmProcuracoesCommandTest` + suíte.

## Task 22 — Normalizadores por família

**Tasks.md:** 5.2
**Depende:** Task 5 (fixtures).

Entregar:
- `FamilyConsultNormalizer` + normalizadores de pgdasd, regime, defis, mei, dctfweb, sitfis, caixa_postal (famílias sem normalizador → resultado sinalizado como não normalizado). Port do legado adaptado.
- Testes por família com fixtures: campos essenciais extraídos; fallback neutro; sem invenção; família sem normalizador sinalizada.

Gate: `php artisan test --filter=FamilyConsultNormalizerTest` + suíte.

## Task 23 — Snapshots, mudanças e alertas

**Tasks.md:** 5.3
**Depende:** Tasks 14, 22.

Entregar:
- Migrations/models `monitoring_snapshots` (fingerprint, data, freshness, completeness, verified_at), `monitoring_changes`, `monitoring_alerts` (status pending|acknowledged, ack por admin/operator idempotente e auditado).
- `SnapshotProjector`/`SerproResultProjector` (port): repetir sem mudança não versiona; mudança gera snapshot + change + exatamente um alerta; estado incompleto/stale/bloqueado sem publicar snapshot completo.
- Testes: idempotência de snapshot; mudança gera alerta único; acknowledge idempotente; estados de completude.

Gate: `php artisan test --filter=SnapshotProjectorTest|MonitoringAlertTest` + suíte.

## Task 24 — Cadeia consultiva PGDAS-D

**Tasks.md:** 5.4
**Depende:** Task 23.

Entregar:
- `PgdasdConsultChain` (port): índice → declaração/recibo → extrato, enfileirando operações filhas com parâmetros (`numeroDeclaracao`/`periodoApuracao`, `anoCalendario`, `numeroDas`) e idempotência.
- Testes: índice novo/alterado encadeia; índice inalterado não encadeia; parâmetros corretos por operação.

Gate: `php artisan test --filter=PgdasdConsultChainTest` + suíte.

## Task 25 — Leitura da carteira, dashboard e CND

**Tasks.md:** 5.5
**Depende:** Task 23.

Entregar:
- Rotas de leitura `GET /api/monitoring/dashboard`, `GET /api/monitoring/enrollments/{id}/snapshots|changes`, `GET /api/monitoring/runs`, `GET /api/monitoring/alerts`, `POST /api/monitoring/alerts/{id}/acknowledge`; isolamento por Account; paginação; sem payload bruto; freshness/cobertura no payload.
- CND da ficha do Client lê snapshot de Situação Fiscal sem consulta ao abrir; ausência informada.
- Testes: listagens e 404 cross-account; ack; dashboard; CND de snapshot sem tráfego externo.

Gate: `php artisan test --filter=MonitoringReadTest|ClientCndTest` + suíte.

## Task 26 — Parcelamentos: consulta, detalhe e guia

**Tasks.md:** 6.1, 6.2
**Depende:** Tasks 16, 23.

Entregar:
- Migrations/models `parcelment_orders`, `parcelment_installments`, `parcelment_payments`; `ParcelmentNormalizer` + `ParcelmentConsultProjector` (port), cobrindo as 8 modalidades (PEDIDOSPARC*, OBTERPARC*, PARCELASPARAGERAR*, DETPAGTOPARC*), `client_id` por linha.
- Rotas `GET /api/monitoring/parcelamentos`, `GET /api/monitoring/parcelamentos/{id}`, `.../parcelas`, `.../parcelas/{id}/pagamentos`, `GET /api/monitoring/parcelas/{id}/guia/download` (sem reemissão; 404 quando não existe).
- Testes por modalidade; 429 não avança estado; detalhe normalizado; download sem emissão; isolamento.

Gate: `php artisan test --filter=ParcelmentTest` + suíte.

## Task 27 — Ações fiscais de emissão de DAS

**Tasks.md:** 6.3, 6.4
**Depende:** Tasks 6, 16, 26.

Entregar:
- `SerproActionExecutor` (port): emissão `GERARDAS12` (PGDAS) e `GERARDAS*` de parcelamento com confirmação explícita, idempotency key, pré-condições fail-closed (transporte, procuração, inscrição), poll de protocolo; PDF vira artefato; falha de armazenamento registrada sem inventar sucesso; rejeita declaração/transmissão fora de DAS.
- Rotas `POST /api/monitoring/enrollments/{id}/gerar-das`, `POST /api/monitoring/parcelas/{id}/gerar-das`, `GET /api/monitoring/actions/{id}`; Audit de autor/Client/resultado; 404 cross-account; `user` 403.
- Testes: emissão confirmada com PDF; repetição idempotente; recusas (transporte, procuração, Role, cross-account); declaração rejeitada; auditoria redigida.

Gate: `php artisan test --filter=SerproActionTest|DasEmissionTest` + suíte.

## Task 28 — UI: monitoramento e navegação

**Tasks.md:** 8.1, 8.6
**Depende:** Tasks 13, 25.

Entregar:
- Página `frontend/app/pages/monitoring/index.vue` com lista de módulos/definições (disponibilidade factual), associações, estado/motivo de pausa, busca e paginação, disparo manual; composable `useMonitoring`; navegação filtrada por Role/Module no shell.
- Somente componentes do template; copy pt-BR; estados loading/empty/erro; `user` não vê ações de escrita.
- Gate: `pnpm lint` + `pnpm typecheck` verdes; smoke manual de lista/indisponibilidade/disparo (registrar dependência do BFF do multi-account se indisponível).

## Task 29 — UI: painel do Client e CND

**Tasks.md:** 8.2
**Depende:** Task 28.

Entregar:
- Seção/página de monitoramento do Client com associações, snapshot vigente, mudanças, alertas (reconhecer) e CND lida do snapshot; abrir a tela não dispara consulta.
- Gate: `pnpm lint` + `pnpm typecheck`; smoke manual.

## Task 30 — UI: parcelamentos

**Tasks.md:** 8.3
**Depende:** Task 29.

Entregar:
- Página de parcelamentos com modalidades, pedidos, detalhe (parcelas/pagamentos) e download de guia existente; guia ausente informa estado sem oferecer emissão automática.
- Gate: `pnpm lint` + `pnpm typecheck`; smoke manual.

## Task 31 — UI: administração SERPRO, certificado/autores e quota

**Tasks.md:** 8.4, 8.5
**Depende:** Tasks 10, 29.

Entregar:
- Área admin SERPRO da Account A: credenciais mascaradas, ambiente com selo, transporte, confirmações; Certificado Digital e autores na Account; consumo de quota com mensagem de upgrade e erros acionáveis sem vazamento.
- Gate: `pnpm lint` + `pnpm typecheck`; smoke manual de mascaramento/confirmações/validade/quota.

## Task 32 — Verificação final e documentação

**Tasks.md:** 9.1, 9.2, 9.3, 9.4
**Depende:** todas.

- Rodar `composer test` (backend) e `pnpm lint` + `pnpm typecheck` (frontend), anexando a saída como evidência.
- Smoke E2E em dry-run (Client associado → consulta → snapshot/alerta → quota bloqueando → transporte desligado sem chamada real).
- Atualizar `CONTEXT.md` (termos Contratante SERPRO, Autor do Pedido de Dados, Certificado Digital, Associação de Monitoramento) e `README`/`.env.example` com operação da fila e do gate.
- Controller conduz; findings viram ledger.
