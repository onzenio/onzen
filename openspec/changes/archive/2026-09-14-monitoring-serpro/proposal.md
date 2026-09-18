## Why

O OneFisc existe para escritórios de contabilidade acompanharem a situação fiscal da carteira; sem a integração com o Integra Contador, a plataforma entrega apenas cadastro de Clients e um Monitoring Status vazio. O `multi-account-foundation` fechou o contrato desse monitoramento e deixou a integração real fora de escopo; esta change entrega as consultas SERPRO por Client — o mecanismo que diferencia o produto. O comportamento comprovado do runtime SERPRO do `_legacy` é a referência, ajustado ao modelo Account/Plan/Client e ao layout backend+frontend do OneFisc.

## What Changes

- Credenciais do Contratante SERPRO na Account A (operadora), com alternância homologação/produção e gate de transporte fail-closed (dry-run por padrão, produção com confirmação dupla e evidência).
- Certificado Digital A1 por Account: upload do PFX + senha no cofre, thumbprint e validade, usado como signatário do Autor do Pedido de Dados e no mTLS.
- Catálogo versionado de definições e operações de consulta do Integra Contador, com fixtures oficiais para execução em dry-run.
- Associação de Monitoramento (Client × definição): elegibilidade, ciclo de vida, pausa por outorga pendente e busca/paginação na carteira.
- Execução de consultas por evento manual ou ciclo automático mensal, com fila dedicada, idempotência/fencing, classificação de resposta, retry/backoff e health observável.
- Quota agregada por Plan: toda consulta (manual ou automática) consome `monthly_query_volume` da Account no mês; esgotado, a execução é recusada com orientação de upgrade.
- Resultados normalizados por família (PGDAS-D, Regime, DEFIS, MEI, DCTFWeb, MIT, SITFIS, Caixa Postal, DTE, Pagamentos) em snapshots idempotentes, com detecção de mudanças e alertas.
- Parcelamentos: 8 modalidades, consulta de pedidos/parcelas/pagamentos e download de guias já geradas.
- Ações fiscais explícitas de emissão de DAS (PGDAS-D e parcelamentos) com confirmação, idempotency key e auditoria.
- Artefatos (PDF/XML) em disco privado do backend atrás de abstraction com `storage_ref` + SHA-256 e download auditado.
- Superfícies de UI em pt-BR reutilizando exclusivamente os componentes existentes do template (tabela, badges, modais, slideover, cards): monitoramento por módulo, painel do Client, parcelamentos e painel administrativo SERPRO da Account A.

## Capabilities

### New Capabilities

- `serpro-credentials`: credenciais do Contratante SERPRO, alternância de ambiente e gate de transporte na Account A.
- `account-certificate`: Certificado Digital A1 por Account, guardado no cofre, com validade e vínculo aos autores.
- `monitoring-catalog`: catálogo versionado de definições→operações, allowlist de procurações e fixtures de dry-run.
- `monitoring-enrollment`: Associação de Monitoramento por Client, elegibilidade, ciclo de vida e busca.
- `monitoring-execution`: disparo, execução assíncrona, classificação/retry, quota do Plan, health e eventos operacionais.
- `monitoring-procuracoes`: Autor do Pedido de Dados, termo assinado, verificação de procuração e pausa/retomada.
- `monitoring-results`: normalização por família, snapshots, mudanças e alertas idempotentes.
- `monitoring-parcelamentos`: 8 modalidades, pedidos/parcelas/pagamentos e guias já geradas.
- `monitoring-actions`: emissão de DAS como Ação Fiscal explícita com confirmação, idempotência e auditoria.
- `monitoring-artifacts`: armazenamento local privado de PDF/XML, hash, retenção e download auditado.
- `monitoring-ui`: telas de monitoramento, painel do Client, parcelamentos e administração SERPRO com os componentes do template.

### Modified Capabilities

- Nenhuma. `openspec/specs/` está vazio; o consumo de `monthly_query_volume` do Plan e o uso do Monitoring Status do Client são comportamentos desta change, sem alterar os requisitos das capabilities do `multi-account-foundation`.

## Impact

- Backend (`backend/`): migrations e models de `serpro_contracts`/credenciais, `account_certificates`, `serpro_request_authors`, `powers_of_attorney`, `monitoring_definitions`, `monitoring_enrollments`, `monitoring_runs`, `monitoring_attempts`, `monitoring_snapshots`, `monitoring_changes`, `monitoring_alerts`, `parcelment_orders`, `parcelment_installments`, `parcelment_payments` e `serpro_service_requests`; integração em `app/Integrations/Serpro` (transporte OAuth+mTLS, envelope, catálogos, normalizadores, projetores, classificadores); serviços em `app/Services/Monitoring` (scheduler, executor de consulta, executor de ação, quota); jobs e comandos de console; Policies/Requests; cofre local com refs `secret:`; conexão de fila `serpro`; fixtures em `backend/resources/fixtures/serpro`.
- Frontend (`frontend/`): novas páginas de monitoramento e de administração SERPRO, seção de monitoramento no Client e composables de API, compostas apenas com os componentes existentes do template; copy em pt-BR.
- Infra: worker de fila para a conexão `serpro` no `docker-compose.yml`; variáveis de ambiente `MONITORING_SERPRO_*` no `backend/.env.example`.
- Banco: migrations reversíveis; Postgres no compose, SQLite `:memory:` nos testes.
- Glossário: novos termos (Contratante SERPRO, Autor do Pedido de Dados, Certificado Digital, Associação de Monitoramento) entram no `CONTEXT.md` quando a change for arquivada.
- Dependência: exige `multi-account-foundation` implementada (Account, Plan, Client, Roles, Audit e BFF de sessão) antes do início desta change.
- Fora de escopo: eventos nativos SERPRO (somente consultas/polling); famílias fora do catálogo do `_legacy` (SICALC, PERDCOMP, REINF, E-PROCESSO, PNRCONTADOR, PAEX/SIPADE, DIRF, FGTS Digital); CND/Certidões (outro contrato SERPRO); Documents/DFe (NF-e/CT-e/NFC-e/NFS-e) e portal NFS-e; cobrança/pagamento; 2FA.
