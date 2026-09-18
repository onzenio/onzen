## ADDED Requirements

### Requirement: Chrome único de tabela real

Toda tela real com tabela (carteira de Clients, gestão de Accounts, gestão de Plans, Audit, telas de monitoramento) SHALL apresentar o mesmo chrome: barra de busca, filtros por coluna, ordenação nas colunas ordenáveis, seleção de linhas, controle de visibilidade de colunas, paginação com contadores e ações em lote quando houver seleção.

#### Scenario: Buscar em tabela real

- **WHEN** o User digita um termo na busca da tabela
- **THEN** a listagem SHALL exibir somente as linhas compatíveis e SHALL voltar à primeira página

#### Scenario: Filtrar por coluna

- **WHEN** o User escolhe um valor de filtro (por exemplo regime do Client ou perfil da Account)
- **THEN** a listagem SHALL exibir somente as linhas com aquele valor e SHALL voltar à primeira página

#### Scenario: Ordenar coluna

- **WHEN** o User aciona a ordenação de uma coluna ordenável
- **THEN** as linhas SHALL ser reordenadas de forma crescente ou decrescente, alternando a cada acionamento

#### Scenario: Selecionar linhas

- **WHEN** o User seleciona linhas individuais ou todas as linhas da página
- **THEN** a interface SHALL exibir a contagem de selecionadas sobre o total filtrado e SHALL liberar as ações em lote correspondentes

#### Scenario: Controlar visibilidade de colunas

- **WHEN** o User oculta ou exibe uma coluna ocultável
- **THEN** a tabela SHALL refletir a escolha imediatamente sem perder busca, filtros ou paginação

#### Scenario: Paginar resultado

- **WHEN** o User navega entre páginas da tabela
- **THEN** a interface SHALL exibir a página solicitada mantendo busca, filtros, ordenação e seleção

### Requirement: Operação client-side em PT-BR

Toda filtragem, busca, ordenação, seleção e paginação das tabelas reais SHALL acontecer sobre os dados já carregados na tela, com todos os rótulos, placeholders, contadores e mensagens do chrome em PT-BR.

#### Scenario: Textos do chrome em PT-BR

- **WHEN** o User visualiza qualquer tabela real
- **THEN** títulos de controles, placeholders, contadores e mensagens SHALL estar em PT-BR, sem resíduos em inglês do template

#### Scenario: Interação sem recarregar dados

- **WHEN** o User busca, filtra, ordena, alterna visibilidade ou pagina
- **THEN** a tabela SHALL responder imediatamente sobre os dados já carregados, sem disparar nova consulta de listagem

### Requirement: Estados de carregamento, vazio e erro

Toda tabela real SHALL comunicar carregamento, ausência de resultados e falha de carregamento com mensagem em PT-BR e, em caso de falha, ação para tentar novamente, sem exibir dados fictícios.

#### Scenario: Carregamento

- **WHEN** a consulta da listagem está pendente
- **THEN** a região da tabela SHALL apresentar estado de carregamento

#### Scenario: Nenhum resultado

- **WHEN** a consulta termina sem linhas ou os filtros eliminam todas as linhas
- **THEN** a tabela SHALL informar em PT-BR que nada foi encontrado e sugerir ajustar busca ou filtros

#### Scenario: Falha de carregamento

- **WHEN** a consulta da listagem falha
- **THEN** a interface SHALL exibir mensagem de erro em PT-BR com ação para tentar novamente
