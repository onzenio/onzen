## Purpose

Define o catálogo versionado de serviços de consulta do Integra Contador disponíveis no OneFisc, com sua disponibilidade factual, allowlist de procurações e dados de dry-run.

## ADDED Requirements

### Requirement: Catálogo versionado de definições e operações

O sistema SHALL manter um catálogo versionado de definições de monitoramento e das operações de consulta de cada definição, incluindo tipo de pessoa, regime e serviços requeridos; uma Associação de Monitoramento SHALL referenciar apenas definição disponível no catálogo vigente.

#### Scenario: Definição indisponível não aceita associação
- **WHEN** um usuário tenta associar um Client a uma definição marcada como indisponível ou em prospecção
- **THEN** a criação é recusada com erro de validação e nenhuma associação é persistida

#### Scenario: Catálogo é versionado
- **WHEN** o catálogo muda de versão
- **THEN** associações existentes continuam legíveis e novas associações usam a versão vigente

### Requirement: Mapa definição → operações oficiais

O catálogo SHALL mapear cada definição às operações oficiais de consulta, cobrindo PGDAS-D, Regime de Apuração, DEFIS, MEI, DCTFWeb, MIT, Situação Fiscal, Caixa Postal, DTE, Pagamentos e as modalidades de parcelamento, com encadeamentos oficiais quando existirem.

#### Scenario: Operação resolvida para a definição
- **WHEN** uma execução de consulta é preparada para uma definição suportada
- **THEN** o sistema resolve a operação oficial de consulta da definição e falha de forma explícita quando a definição não possui operação executável

### Requirement: Escritas fora do fluxo de consulta

Operações de declaração, transmissão ou emissão SHALL permanecer fora do fluxo de consulta e do ciclo automático; somente ações fiscais explícitas podem executá-las.

#### Scenario: Ciclo automático não declara
- **WHEN** o ciclo automático roda uma definição que possui operações de escrita
- **THEN** apenas a operação de consulta é disparada e nenhuma declaração, transmissão ou emissão ocorre

### Requirement: Allowlist oficial de serviços por procuração

A elegibilidade de uma Associação de Monitoramento SHALL considerar a allowlist oficial de serviços versus procurações; associação cujo serviço não conste da allowlist SHALL ser recusada ou permanecer pausada com motivo factual.

#### Scenario: Serviço fora da allowlist
- **WHEN** a associação exige um código de procuração ausente da allowlist oficial
- **THEN** a associação não é executada e o motivo factual é exibido

### Requirement: Fixtures oficiais para dry-run

O catálogo SHALL dispor de fixtures oficiais por operação, usadas quando o transporte está desligado ou o modo dry-run está ativo, sem contato com a SERPRO.

#### Scenario: Execução em dry-run usa fixture
- **WHEN** uma consulta é executada com o transporte desligado e existe fixture para a operação
- **THEN** o resultado processado vem da fixture e é registrado como execução de dry-run

#### Scenario: Fixture ausente falha de forma explícita
- **WHEN** uma consulta é executada em dry-run sem fixture para a operação
- **THEN** a execução é encerrada com estado factual de bloqueio, sem inventar resultado

### Requirement: Catálogo exposto sem exagero de cobertura

O catálogo exposto à UI SHALL declarar a disponibilidade real de cada definição e SHALL indicar indisponibilidades conhecidas, sem prometer consulta que a plataforma não executa.

#### Scenario: Módulo indisponível é visível
- **WHEN** o usuário abre a lista de módulos de monitoramento
- **THEN** módulos sem operação executável aparecem como indisponíveis com motivo factual, sem ação de consulta disponível
