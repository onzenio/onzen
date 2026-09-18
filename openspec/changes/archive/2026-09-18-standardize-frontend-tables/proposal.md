## Why

As listagens de tabelas do frontend divergiram: a carteira de Clients tem busca, filtro, ordenação, seleção, paginação e exportação em PT-BR, enquanto a gestão de Accounts, a gestão de Plans, o Audit e as telas de monitoramento repetem variações próprias com paginação, filtros, estados de vazio/carregamento/erro e rótulos inconsistentes. O template `customers` permanece em inglês com dados fictícios e não serve de referência direta para operação. Essa inconsistência aumenta o custo de manutenção, confunde Users com Roles distintos (`super_admin`, `admin`, `operator`, `user`) e dificulta aplicar o mesmo comportamento de carteira, Plan e Audit em todas as telas.

## What Changes

- Definir um padrão único de tabela client-side em PT-BR para as telas reais, tendo `app/pages/customers.vue` como referência comportamental (busca, filtro, ordenação, seleção, visibilidade de colunas, paginação, contadores, estados de vazio/carregamento), sem incorporar seus dados fictícios nem seus textos em inglês.
- Organizar os blocos de tabela por domínio (Client, Account, Plan, Audit, monitoramento), com cada tela compondo apenas blocos do seu domínio e blocos compartilhados de chrome da tabela.
- Centralizar os comportamentos repetidos de tabela (busca, filtro, ordenação, seleção, paginação, visibilidade, exportação CSV) em composables reutilizáveis com texto PT-BR.

## Capabilities

### New Capabilities

- `table-pattern`: padrão observável único das tabelas reais (chrome, estados e textos PT-BR, operação client-side).
- `table-components`: estrutura de componentes de tabela por domínio mais chrome compartilhado.
- `table-composables`: comportamentos de tabela compartilhados via composables.

### Modified Capabilities

Nenhuma.

## Impact

- Frontend Nuxt: páginas reais com tabela (`clients`, `accounts`, `plans`, `audit`, monitoramento) passam a seguir o mesmo padrão client-side em PT-BR.
- Backend Laravel: nenhuma mudança em APIs, payloads ou autorização.
- Operação: nenhum serviço, variável ou rotina nova.

## Out-of-Scope

- Alterar tabelas fictícias de template (`customers`, lista de e-mails de `inbox`, vendas de `home`): servem apenas como referência comportamental, não entram na migração.
- Migrar paginação, busca ou filtros para server-side; o escopo é client-side sobre os dados já carregados em cada tela real.
- Alterar contratos REST, regras de Plan, isolamento por Account, Account Switcher, Onboarding ou escrita no Audit.
- Internacionalização geral ou troca de idioma fora do chrome das tabelas (o padrão é PT-BR fixo, sem seletor de idioma).
- Introduzir biblioteca, componente ou estilo de UI fora do template Nuxt UI já disponível em `frontend/`; toda tabela nova compõe o que já existe.
