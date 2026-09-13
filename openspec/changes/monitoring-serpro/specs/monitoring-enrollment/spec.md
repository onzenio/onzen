## Purpose

Representa a escolha confirmada de monitorar uma capacidade de consulta para um Client, com elegibilidade, ciclo de vida e isolamento por Account.

## ADDED Requirements

### Requirement: Criação da Associação de Monitoramento

A Associação de Monitoramento SHALL vincular um Client da própria Account a uma definição disponível, exigindo Monitoring Status ativo, tipo de pessoa e regime elegíveis; SHALL existir no máximo uma associação ativa por par Client e definição.

#### Scenario: Client elegível é associado
- **WHEN** um `admin` ou `operator` associa um Client com Monitoring Status ativo a uma definição disponível e elegível
- **THEN** a associação é criada com estado pendente de primeira execução e aparece na carteira

#### Scenario: Client com Monitoring Status inativo é recusado
- **WHEN** o usuário tenta associar um Client com Monitoring Status inativo
- **THEN** a criação é recusada com erro de validação e nenhuma associação é persistida

#### Scenario: Associação duplicada é impedida
- **WHEN** já existe associação ativa para o par Client e definição
- **THEN** uma segunda criação é recusada e a associação existente permanece única

#### Scenario: Client fora da Account responde 404
- **WHEN** o usuário tenta associar um Client de outra Account
- **THEN** a resposta é 404 indistinguível

### Requirement: Ciclo de vida e pausa por outorga

A associação SHALL ter estados explícitos (ativa, pausada, encerrada); SHALL ser pausada automaticamente quando a procuração do Client estiver ausente ou expirada e SHALL ser retomada automaticamente após a outorga ser verificada.

#### Scenario: Pausa por outorga pendente
- **WHEN** a verificação de procuração do Client aponta ausência de outorga
- **THEN** a associação é pausada com motivo "outorga pendente", execuções agendadas são impedidas e o estado fica visível na carteira

#### Scenario: Retomada após outorga
- **WHEN** uma verificação posterior confirma outorga válida
- **THEN** a associação volta a ativa sem exigir recriação pelo usuário

#### Scenario: Encerramento preserva histórico
- **WHEN** uma associação é encerrada
- **THEN** execuções, snapshots e alertas anteriores permanecem consultáveis e nenhuma nova execução é permitida

### Requirement: Versionamento e fencing da associação

Alterações relevantes da associação SHALL incrementar sua versão; execuções iniciadas em versão anterior SHALL ser descartadas ao concluir, sem sobrescrever o estado vigente.

#### Scenario: Execução antiga não sobrescreve estado novo
- **WHEN** uma execução iniciada antes de uma alteração da associação conclui depois dela
- **THEN** o resultado é descartado e o estado da versão vigente permanece

### Requirement: Busca e leitura da carteira

A listagem de associações SHALL ser paginada, isolada por Account e SHALL permitir busca pelo nome ou documento do Client, refletindo estado, motivo de pausa e última mudança.

#### Scenario: Busca por nome ou CNPJ
- **WHEN** o usuário busca por parte do nome ou pelos dígitos do CNPJ de um Client
- **THEN** apenas associações dos Clients visíveis da sua Account são retornadas

#### Scenario: Associações de outra Account não aparecem
- **WHEN** existem associações equivalentes em outra Account
- **THEN** a listagem e a contagem não incluem nenhum registro de fora da Account efetiva

### Requirement: Monitoring Status governa novas execuções

Desativar o Monitoring Status de um Client SHALL impedir novas execuções de suas associações sem apagar associações nem histórico.

#### Scenario: Desativação impede consulta nova
- **WHEN** o Monitoring Status do Client é desativado
- **THEN** novas execuções para o Client são impedidas e as associações existentes continuam listadas com o histórico
