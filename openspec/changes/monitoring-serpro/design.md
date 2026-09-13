## Context

Ver `proposal.md` — Why. O OneFisc está no início: `backend/` é Laravel 13 com layout plano (`app/Models`, `app/Enums`, `app/Jobs`, `app/Services`, `app/Http`) e `frontend/` é o template Nuxt 4 + Nuxt UI 4. A base `multi-account-foundation` (Account, Plan, Client, Roles, Audit, BFF de sessão, concerns de escopo) é pré-requisito desta change.

O runtime SERPRO do `_legacy` é a referência de comportamento e de desenho: transporte OAuth + mTLS, envelope de 3 partes, catálogo versionado, fila dedicada, cofre local com refs `secret:`, projeções por família e UI de monitoramento. As specs desta change descrevem o comportamento observável; este documento registra como portar/adaptar aquele runtime para o OneFisc e onde o port decide diferente.

## Goals / Non-Goals

**Goals:**

- Portar o runtime SERPRO do `_legacy` para o layout plano do OneFisc, preservando comportamento e testes, sem reintroduzir a organização em Contexts.
- Encaixar o monitoramento no modelo Account/Plan/Client e no Audit do `multi-account-foundation`.
- Manter o fail-closed como invariante em todas as camadas (transporte, quota, procuração, certificado).
- Entregar UI de monitoramento composta apenas com os componentes do template existente.

**Non-Goals:**

- Reescrever o runtime do zero ou modernizar decisões que funcionam no `_legacy` (catalogos, normalizadores, classificadores, envelope).
- Criar serviço, banco ou design system novos.
- Eventos nativos SERPRO, famílias fora do catálogo e ações fiscais além de DAS.
- Definir política de retenção/expurgo de artefatos (fica como Open Question).

## Decisions

### 1. Estrutura do port no backend plano

O runtime portado vive em `app/Integrations/Serpro` (transporte, envelope, catálogos, normalizadores, projetores, classificadores, fixture provider, cofre) e `app/Services/Monitoring` (scheduler, executor de consulta, executor de ação, quota, sincronização de autores). Models ficam em `app/Models`, jobs em `app/Jobs`, comandos em `app/Console/Commands`, policies em `app/Policies` e requests em `app/Http/Requests`.

Alternativas descartadas: manter `app/Contexts/Monitoring` (o OneFisc não usa Contexts; misturar padrões piora a navegação); portar tudo para `app/Services` num único namespace (arquivos demais num só lugar, dificulta ownership).

### 2. Escopo por Account e vínculo com Client

Toda tabela de monitoramento carrega `account_id` e usa o concern `BelongsToAccount` (global scope + preenchimento) do `multi-account-foundation`; vínculos com Client usam o UUID dentro da Account efetiva e respondem 404 para fora. Uniques são compostos por `account_id`.

Alternativa descartada: escopo por Client apenas (não cobre entidades da plataforma como credenciais e autores nem permite leitura agregada de carteira).

### 3. Cofre local com referências opacas

Portar o cofre do `_legacy` como serviço do backend: valores cifrados com a chave da aplicação, escopo por Account, referências no formato `secret:...`; PFX, senha, consumer secret e token do procurador nunca são serializados em DTO, log ou resposta.

Alternativas descartadas: `encrypted` cast direto nos models (não guarda arquivo binário nem dá ref opaca reutilizável); gerenciador externo de segredos (infra adicional fora do escopo atual).

### 4. Transporte OAuth + mTLS com arquivo temporário seguro

`SerproTransport` (contrato) com implementação HTTP: token `client_credentials` com Basic auth do e-CNPJ + consumer secret, `Role-Type` configurável; chamadas com `Bearer`, `X-Request-Tag` (idempotência), `autenticar_procurador_token` e mTLS. O Certificado Digital resolvido do cofre é escrito em arquivo temporário com permissão restrita por chamada e removido em seguida; `verify=true` e timeouts configuráveis.

Alternativa descartada: exigir caminho de arquivo como o `_legacy` (a lacuna conhecida faz o A1 da Account nunca fechar com o cofre real); stream PFX direto no handler TLS (Guzzle exige arquivo; forçar isso vira gambiarra).

### 5. Catálogo e fixtures

Portar `ConsultCatalog`, `ProcurationCatalog` e `SerproEnvelope`; definições também são semeadas no banco (`monitoring_definitions`) com versão, disponibilidade, estratégia, operações, códigos de procuração, tipos de pessoa, regimes e serviços — permitindo a UI ler o catálogo sem hardcode. Fixtures oficiais por operação ficam em `backend/resources/fixtures/serpro/consultar/` e são usadas no dry-run.

Alternativa descartada: catálogo só em config PHP (a UI precisaria de código para listar; o `_legacy` já demonstrou o valor do catálogo em tabela).

O provedor de fixtures devolve o envelope bruto do arquivo; a conversão para o resultado/DTO de execução acontece no executor, mantendo o dry-run sem inventar dados.

### 6. Fila dedicada e agendamentos

Conexão `serpro` no `config/queue.php` (driver database, tabela `jobs`), jobs `ExecuteSerproJob`/`ExecuteSerproActionJob` com tentativas e backoff, e serviço worker dedicado no `docker-compose.yml` (`queue:work serpro`). Comandos agendados: ciclo mensal de consultas automáticas (dia 1, somente Consultar) e rotina diária de renovação de termos/reverificação de procurações.

Alternativas descartadas: reusar a fila default (uma consulta lenta atrasa tarefas de produto); Redis queue (o compose já tem Redis, mas o `_legacy` validou database + worker dedicado e evita mais um ponto de configuração).

### 7. Quota agregada do Plan

`QueryQuotaService` reserva uma unidade do `monthly_query_volume` do Plan da Account em transação com lock, registrando origem manual/automática, antes de qualquer chamada externa; sem saldo, devolve 422 com mensagem de upgrade. O consumo é imutável (não devolve volume quando a execução falha depois de iniciada).

Alternativas descartadas: tetos por serviço do `_legacy` (decisão desta change foi volume agregado do Plan); contador em cache (perde consistência e não sobrevive a restart).

### 8. Execução com máquina de estados

Portar a máquina de estados do `_legacy` (pendente, em execução, aguardando protocolo, concluído, limitado, falho, rejeitado, expirado) com idempotência por chave, fencing token por associação e classificação de resposta (transitório/definitivo/sucesso) dirigindo retry, backoff e polling de protocolo. A reserva de quota e o gate de procuração ocorrem antes do transporte; o dry-run troca o transporte real por fixtures.

Alternativa descartada: execução síncrona na requisição (SERPRO tem protocolo e latência; o contrato de API exige 202).

### 9. Resultados, parcelamentos e ações

Normalizadores e projetores por família são portados como unidades testáveis; snapshots usam fingerprint do resultado normalizado; mudanças geram alertas; a cadeia PGDAS-D encadeia índice→recibo→extrato. Parcelamentos mantêm pedidos/parcelas/pagamentos normalizados. A emissão de DAS usa o executor de ação com confirmação, idempotency key, poll de protocolo e artefato.

Alternativa descartada: guardar só o payload bruto e normalizar na leitura (perde detecção de mudança e torna a UI refém do formato SERPRO).

### 10. Artefatos em disco privado atrás de abstraction

`ArtifactStore` (contrato) com implementação em disco privado (`storage/app/private/...`), nomeada por referência opaca e hash SHA-256; decodificação base64 no processamento da execução. O download passa pela API com autorização da Account e Audit, com URL assinada temporária; a abstraction permite S3/MinIO no futuro sem mudar specs.

Alternativas descartadas: MinIO no compose (decisão desta change: sem novo serviço); `bytea` no Postgres (pior para arquivos e para migração futura).

### 11. API e contrato

Rotas JSON `snake_case` sob `/api` com sessão por cookie e middlewares do `multi-account-foundation`: `/api/monitoring/*` (catálogo, health, associações, runs, snapshots, changes, alerts), `/api/monitoring/parcelamentos/*`, `/api/account/certificate`, `/api/monitoring/authors`, `/api/monitoring/clients/{client}/powers-of-attorney`, `/api/monitoring/actions/*` e `/api/admin/serpro/*`. Listagens paginadas; 404 cross-account; 422 de quota com mensagem de upgrade; 403 por Role.

Alternativa descartada: rotas sob `/api/serpro` (monitoramento é o domínio do produto; SERPRO é o provedor).

### 12. UI em cima do template

Páginas em `frontend/app/pages/monitoring/` (lista/módulos, parcelamentos) e administração SERPRO na área da A, mais a seção de monitoramento na tela de Clients; composables alimentados pelo BFF. Toda composição usa `UTable`, `UBadge`, `UButton`, `UDropdownMenu`, `UModal`, `USlideover`, `UCard`, `UInput`, `USelect`, `UAlert`, `UProgress` e os padrões de `customers.vue`, `AddModal`/`DeleteModal` e `settings/*`, com copy pt-BR.

Alternativa descartada: desenhar componentes novos (decisão explícita do usuário: nada de inventar; reusar o template).

### 13. Health pelo gate efetivo

O health de monitoramento deriva o estado do gate efetivo (painel + ambiente) e do cofre, e não apenas das variáveis de ambiente — corrige a inconsistência conhecida do `_legacy`, onde o painel ligado ainda aparecia `gated`.

### 14. Configuração e defaults

`config/monitoring.php` com `base_url`, `token_url`, `environment` (default `homologacao`), `dry_run` (default `true`), `transport.approved` (default `false`), timeouts, `queue` (`serpro`), limites e `fixtures_path`; tudo sobreponível por `MONITORING_SERPRO_*` no `.env`. Ambiente e transporte efetivos vêm do gate da Account A.

## Risks / Trade-offs

- [Contrato SERPRO real é pré-requisito de produção e pode não existir no início] → dry-run e homologação cobrem todo o desenvolvimento; produção só liga com confirmação dupla e evidência, e o estado fica visível no health.
- [PFX em arquivo temporário pode vazar em disco] → arquivo criado com permissão restrita no diretório privado, removido em `finally`, nunca logado; teste cobre remoção em falha.
- [Corrida de quota em execuções concorrentes] → reserva em transação com lock e unique de consumo por execução; teste de concorrência na camada de serviço.
- [Escopo grande atrasa valor] → entrega em quatro fases com gate de testes por fase (tasks.md), cada fase utilizável sem as seguintes.
- [Port acopla o OneFisc a decisões do `_legacy`] → portar comportamento, não estrutura; onde o OneFisc difere (Account/Plan/fila/UI), adaptar e registrar neste design.
- [Sem testes de frontend no OneFisc] → comportamento crítico coberto no backend; frontend validado por `pnpm lint`/`pnpm typecheck` e smoke manual por fase (risco aceito no `multi-account-foundation`).
- [Migrations grandes e irreversíveis na prática se houver dados] → migrations reversíveis; base de dev recriável; dados SERPRO só existem após a fase 1.
- [Custo SERPRO descontrolado] → quota agregada do Plan + Audit de consumo; transporte desligado por padrão.
- [Falha de storage de artefato confundir com falha de consulta] → artefato tem estado próprio; execução registra falha de artefato sem inventar sucesso.

## Migration Plan

1. Backend: criar migrations na ordem `serpro_contracts` → `account_certificates` → `serpro_request_authors` → `powers_of_attorney` → `monitoring_definitions` → `monitoring_enrollments` → `monitoring_runs` → `monitoring_attempts` → `monitoring_snapshots` → `monitoring_changes` → `monitoring_alerts` → `parcelment_orders` → `parcelment_installments` → `parcelment_payments` → `serpro_service_requests`; seed do catálogo vigente; `php artisan migrate --seed` e suíte PHPUnit verde.
2. Portar integração e serviços em fases (tasks.md), mantendo `MONITORING_SERPRO_DRY_RUN=true` até a fase de execução real estar coberta por testes.
3. Infra: adicionar worker `serpro` ao `docker-compose.yml` e as variáveis `MONITORING_SERPRO_*` ao `.env.example`; documentar no README.
4. Frontend: implementar telas por fase com `pnpm lint`/`pnpm typecheck` e smoke manual.
5. Produção: alternar ambiente e transporte somente após evidência de homologação, com dupla confirmação; rollback desliga o transporte e reverte migrations (sem dados legados a preservar no OneFisc).
6. Arquivar a change e sincronizar os deltas em `openspec/specs/`, atualizando `CONTEXT.md` com os novos termos.

## Open Questions

- Política de retenção/expurgo de artefatos e snapshots por Plan (tempo e volume) — pode ser definida depois sem alterar specs ou abordagem.
- Estratégia de observabilidade operacional de longo prazo (métricas por Account, alertas de saúde) — a change entrega health e eventos; dashboards ficam para depois.
