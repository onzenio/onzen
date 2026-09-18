## Context

Veja `proposal.md` para a motivação. Hoje `app/pages/customers.vue` (template em inglês, dados fictícios) concentra o comportamento mais completo de tabela: busca por coluna, filtro por status, ordenação no cabeçalho, seleção, visibilidade de colunas, paginação client-side e contadores. A carteira real de Clients (`app/pages/clients.vue`) já replica esse comportamento em PT-BR com busca por razão social/CNPJ, filtro por regime, exportação CSV e modais; Accounts, Plans, Audit e as telas de monitoramento usam variações menores (paginação por página, filtros próprios, sem seleção/visibilidade/exportação padronizadas). Os utilitários `export-csv`, `backendMessage`/`backendFormErrors` e formatação PT-BR já existem em `frontend/app/utils/`.

## Goals / Non-Goals

**Goals:**

- Eleger `customers.vue` como referência comportamental e republicá-la em PT-BR client-side apenas nas telas reais.
- Separar chrome compartilhado (barra, filtros, paginação, estados) de blocos por domínio (Client, Account, Plan, Audit, monitoramento).
- Reutilizar estritamente o template Nuxt UI existente, sem nova biblioteca ou estilo próprio.

**Non-Goals:**

- Converter as telas fictícias de template em telas reais ou traduzir o template inteiro.
- Mudar para paginação/filtragem server-side ou alterar APIs do BFF/backend.

## Decisions

### Adotar `customers.vue` como molde comportamental, não como código a copiar

O padrão replica a sequência de `customers.vue` (toolbar com busca + filtros + visibilidade, tabela, rodapé com contadores + paginação) e o bloco de `ui` que arredonda e borda a tabela, traduzindo rótulos para PT-BR e ligando filtros às colunas reais de cada domínio. A carteira de Clients é a segunda referência por já trazer CSV, estado vazio PT-BR e reset de página ao filtrar.

Escolhido sobre criar um padrão do zero porque o molde já é conhecido e usa só o template existente; rejeitado copiar o arquivo tal qual porque ele carrega dados fictícios e inglês.

### Componentizar por domínio com chrome compartilhado

Cada domínio ganha sua pasta de blocos (colunas, células e ações da linha, modais de confirmação), e o chrome (toolbar, painel de visibilidade, rodapé de paginação, estados de vazio/erro) é compartilhado. Páginas compõem domínio + chrome, sem importar blocos de outro domínio.

Escolhido sobre um componente genérico único porque colunas e ações de Client, Account, Plan, Audit e monitoramento não são intercambiáveis; rejeitado manter tudo inline nas páginas porque perpetua a divergência atual.

### Centralizar comportamento em composables

Busca, filtro, ordenação, seleção, visibilidade, paginação e exportação CSV passam a sair de composables compartilhados com texto PT-BR, consumidos pelos blocos de cada domínio. Formatação (`formatCnpj`, moeda `pt-BR`, datas) e erros (`backendMessage`) continuam nos utilitários existentes.

Escolhido sobre duplicar lógica por página porque o comportamento precisa ser idêntico e testado uma vez; rejeitado um plugin global porque acoplaria todas as tabelas a um estado único.

## Risks / Trade-offs

- [Risk] Tabelas com paginação por página no backend (Accounts, Plans, Audit) perdem navegação total ao virar client-side → Mitigação: escopo limita o client-side aos dados já carregados por tela e mantém os parâmetros de consulta atuais; server-side fica para change futura.
- [Risk] Tradução do chrome quebra expectativas de quem decorou rótulos em inglês do template → Mitigação: PT-BR é o padrão exigido do escopo e cada spec traça textos observáveis para revisão.
- [Risk] Unificar ações por linha expõe escrita a Role sem permissão → Mitigação: spec exige gating por permissão e as telas reais já distinguem leitura de escrita (ex. `can('clients.write')`).
- [Risk] Exportação CSV diverge por domínio (colunas e formatos) → Mitigação: regra única de exportar filtradas ou selecionadas com valores como exibidos e aviso de nada a exportar.
- [Risk] Monitoramento tem tabelas densas (parcelas, pagamentos, snapshots) que não cabem no molde simples → Mitigação: molde cobre chrome e estados; colunas densas permanecem nos blocos do domínio sem forçar colapso.
