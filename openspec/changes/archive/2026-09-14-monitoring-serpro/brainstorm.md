# Brainstorm — monitoring-serpro

## Contexto

- Repo alvo: OneFisc (`backend/` Laravel + `frontend/` Nuxt), conforme decisão do usuário.
- Referência de comportamento: o runtime SERPRO do `_legacy` (`/home/obsidian/dev/oniscan/_legacy`), com specs canônicas `monitoring-serpro`, `serpro-laravel-runtime`, `platform-serpro-credentials`, `monitoring-procuracoes`, `monitoring-parcelamentos`, `monitoring-pgdas-das-emission`, `monitoring-frontend`, `monitoring-client-detail`, `monitoring-enrollment-screen` e `account-certificate`.
- Documentação oficial: API Integra Contador — `https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/`.
- Estado do OneFisc: `multi-account-foundation` (Account, Plan, Client, Roles, Audit, BFF de sessão) planejada/em implementação; integração SERPRO explicitamente fora de escopo lá, reservada para change futuro. Esta é a change futura.

Classificação do brainstorming: **arquitetural** (novo subsistema, novo domínio, novas interfaces).

## Perguntas e decisões

| # | Pergunta | Decisão |
|---|----------|---------|
| 1 | Onde vive a change? | **OneFisc** (`backend/` + `frontend/`), com `_legacy` como referência |
| 2 | O que significa "a parte de busca na SERPRO"? | **Consultas SERPRO por Client** — disparar consultas e exibir snapshots/status na carteira (core do Monitoring do `_legacy`) |
| 3 | Qual o escopo entregável? | **Monitoring completo do legado** — todas as famílias consultivas + parcelamentos + alertas + artefatos + UI completa |
| 4 | Como implementar? | **Portar/adaptar do `_legacy`** — preserva comportamento testado, ajustando para Account/Plan/Client e para o layout backend+frontend do OneFisc |
| 5 | Como cobrar quota? | **Volume do Plan, agregado** — toda consulta (manual ou automática) consome `monthly_query_volume` do Plan por Account/mês; sem tetos por serviço |
| 6 | Quem detém credenciais e A1? | **Fiel ao `_legacy`** — Contratante SERPRO na Account A; cada Account B cadastra Certificado Digital A1 e Autor do Pedido de Dados; procuração por Client via `OBTERPROCURACAO41` + termo `ENVIOXMLASSINADO81` |
| 7 | Onde ficam os artefatos (PDF/XML)? | **Disco local privado** do backend, atrás de abstraction (`storage_ref` + SHA-256), sem novo serviço no compose |

## Restrições registradas

- **UI sem invenção**: reutilizar exclusivamente os componentes já disponíveis no template (`UTable`, `UBadge`, `UButton`, `UDropdownMenu`, `UModal`, `USlideover`, `UCard`, `UInput`, `USelect`, `UAlert`, `UProgress`, `UIcon`) e os padrões existentes (`customers.vue`, `customers/AddModal.vue`, `customers/DeleteModal.vue`, `settings/*`), sem design system novo.
- **pt-BR** é vinculante para copy de UI (PRODUCT.md).
- **Fail-closed** obrigatório: `dry-run` por padrão, ambiente de homologação por padrão, produção somente com confirmação dupla e evidência.
- **Terminologia**: usar o glossário canônico do OneFisc (`CONTEXT.md`) e incorporar os termos SERPRO do `_legacy` (Contratante SERPRO, Autor do Pedido de Dados, Certificado Digital) quando cristalizarem.

## Desenho aprovado em conversa

Change única `monitoring-serpro`, com quatro fases como grupos de tasks:

1. **Fundação SERPRO** — credenciais do Contratante (Account A), Certificado Digital A1 por Account B, transporte OAuth+mTLS, envelope de 3 partes, catálogo versionado com fixtures, fila `serpro`, idempotência/fencing, classificação de resposta, quota do Plan, health.
2. **Consultas e resultados** — Associação de Monitoramento (Client × definição), famílias consultivas do legado (PGDAS-D, Regime, DEFIS, MEI, DCTFWeb, MIT, SITFIS, Caixa Postal, DTE, Pagamentos), normalizadores/projeções, snapshots/changes/alertas, autores e procurações com pausa/retomada, ciclo automático mensal.
3. **Parcelamentos, ações e artefatos** — 8 modalidades, detalhes normalizados, emissão de DAS como Ação Fiscal explícita, artefatos em disco privado com download auditado.
4. **UI com componentes do template** — dashboard/módulos, painel do Client, parcelamentos, painel admin SERPRO (A), certificado/autores.

## Fora de escopo (decidido)

- Eventos nativos SERPRO (somente polling/consultas).
- Famílias fora do catálogo do `_legacy`: SICALC, PERDCOMP, REINF, E-PROCESSO, PNRCONTADOR, PAEX/SIPADE, DIRF, FGTS Digital.
- CND/Certidões (outro contrato SERPRO).
- Documents/DFe (NF-e/CT-e/NFC-e/NFS-e) e portal NFS-e.
- Cobrança/pagamento; 2FA.

## Riscos levantados

- Contrato SERPRO real necessário para produção (homologação/dry-run cobre o desenvolvimento).
- PFX do A1 do escritório precisa de arquivo temporário seguro para o mTLS (lacuna conhecida do `_legacy`, onde o transporte exige caminho de arquivo).
- Corrida de quota em execuções concorrentes.
- Escopo grande: mitigado pelas quatro fases com gates de teste por fase.
- Dependência de `multi-account-foundation` implementada antes desta change.
