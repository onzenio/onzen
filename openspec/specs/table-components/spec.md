## ADDED Requirements

### Requirement: Blocos de tabela por domínio

Cada domínio com tabela real (Client, Account, Plan, Audit, monitoramento) SHALL ter seus blocos próprios de tabela (definição de colunas, formatação de valores do domínio e ações por linha), e cada página SHALL compor somente blocos do seu domínio além do chrome compartilhado.

#### Scenario: Página usa apenas blocos do próprio domínio

- **WHEN** o User abre a carteira de Clients
- **THEN** ele SHALL ver colunas, formatos (por exemplo CNPJ e regime) e ações exclusivas de Client, sem colunas ou ações de Account, Plan, Audit ou monitoramento

#### Scenario: Valor de domínio formatado em PT-BR

- **WHEN** a tabela exibe um valor específico do domínio (por exemplo preço de Plan em reais, data de Audit, estado de monitoramento de Client)
- **THEN** ele SHALL aparecer formatado em padrão brasileiro legível ao User

#### Scenario: Ação por linha respeita permissão

- **WHEN** um User sem permissão de escrita visualiza uma linha com ações (por exemplo um `user` básico na carteira de Clients)
- **THEN** ele SHALL ver apenas as ações permitidas ao seu Role, sem opção de alterar ou excluir

### Requirement: Chrome compartilhado entre domínios

Barras de busca, filtros, controles de visibilidade, paginação com contadores, estados de vazio/carregamento/erro e confirmações de exclusão SHALL ter aparência e texto idênticos em todos os domínios, variando apenas os dados e as colunas de cada domínio.

#### Scenario: Mesmo chrome em domínios distintos

- **WHEN** o User alterna entre a carteira de Clients e a gestão de Accounts
- **THEN** ele SHALL encontrar o mesmo chrome de tabela (busca, filtros, paginação, contadores, mensagens PT-BR), mudando apenas colunas e dados

#### Scenario: Tabelas fictícias de template isoladas

- **WHEN** o User abre uma tela fictícia de template (clientes de demonstração, e-mails, vendas)
- **THEN** ela SHALL NOT compartilhar colunas, dados ou ações com os blocos reais de Client, Account, Plan, Audit ou monitoramento
