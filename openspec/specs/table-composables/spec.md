## ADDED Requirements

### Requirement: Comportamentos de tabela reutilizáveis

Os comportamentos repetidos de tabela (busca textual, filtro por coluna, ordenação, seleção de linhas, visibilidade de colunas, paginação e exportação CSV) SHALL ser providos de forma reutilizável, de modo que toda tabela real se comporte igual sem duplicar regras.

#### Scenario: Mesma busca em tabelas distintas

- **WHEN** o User busca na carteira de Clients e depois na gestão de Accounts
- **THEN** ambas as buscas SHALL filtrar pelo texto digitado, voltar à primeira página e exibir a contagem sobre o total filtrado

#### Scenario: Mesma seleção e contagem

- **WHEN** o User seleciona linhas em qualquer tabela real
- **THEN** a contagem de selecionadas sobre filtradas SHALL seguir a mesma regra e as ações em lote SHALL operar sobre a seleção visível

#### Scenario: Mesma paginação

- **WHEN** o User altera página ou tamanho de página em qualquer tabela real
- **THEN** a navegação SHALL preservar busca, filtros, ordenação e seleção segundo a mesma regra

### Requirement: Exportação CSV consistente

Toda tabela real que oferece exportação SHALL exportar as linhas filtradas (ou apenas as selecionadas, quando houver seleção) com cabeçalhos e valores em PT-BR, e SHALL avisar quando não houver nada para exportar.

#### Scenario: Exportar filtradas

- **WHEN** o User exporta com filtros aplicados e sem seleção
- **THEN** o arquivo SHALL conter exatamente as linhas filtradas com valores formatados como exibidos (por exemplo rótulos PT-BR de regime e estado)

#### Scenario: Exportar selecionadas

- **WHEN** o User seleciona linhas e aciona a exportação
- **THEN** o arquivo SHALL conter somente as linhas selecionadas

#### Scenario: Nada para exportar

- **WHEN** o User tenta exportar sem linhas filtradas nem selecionadas
- **THEN** a interface SHALL informar em PT-BR que não há nada para exportar e SHALL NOT gerar arquivo vazio
