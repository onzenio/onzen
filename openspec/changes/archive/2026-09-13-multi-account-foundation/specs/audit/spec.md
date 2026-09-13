## Purpose

Define o log único de Audit cobrindo ações de plataforma e da operação, com filtros por Account, ator e período.

## ADDED Requirements

### Requirement: Log único

Toda ação relevante de plataforma e da operação SHALL gerar um registro com ator, Account de origem, Account alvo quando aplicável, ação, alvo, momento e metadados.

#### Scenario: Registro de plataforma

- **WHEN** uma Account é criada, um Plan é trocado ou o Account Switcher é usado
- **THEN** o evento SHALL constar no log com todos os campos

#### Scenario: Registro de operação

- **WHEN** um User é convidado, um Role é alterado ou um Client é criado, editado ou excluído
- **THEN** o evento SHALL constar no log com todos os campos

#### Scenario: Cobertura mínima desta fase

- **WHEN** o sistema registra eventos nesta fase
- **THEN** a cobertura SHALL incluir Accounts, Users, Invites, Plans, Clients e uso do Account Switcher

### Requirement: Registro best-effort

A falha ao registrar um evento SHALL NOT impedir a operação principal.

#### Scenario: Falha de Audit

- **WHEN** o registro de Audit falha durante uma operação válida
- **THEN** a operação SHALL ser concluída e a falha SHALL ser registrada em log técnico

### Requirement: Consulta com filtros

Users autorizados SHALL consultar o Audit filtrando por Account, ator e período, com resultado paginado.

#### Scenario: Filtro por Account

- **WHEN** o super_admin filtra por uma Account
- **THEN** apenas eventos daquela Account SHALL ser listados

#### Scenario: Filtro por ator e período

- **WHEN** o super_admin filtra por ator e intervalo de datas
- **THEN** apenas os eventos correspondentes SHALL ser listados

### Requirement: Visibilidade do Audit

O super_admin SHALL ver eventos de todas as Accounts; o admin SHALL ver apenas eventos da sua Account; operator e user SHALL NOT acessar o Audit.

#### Scenario: Visão do super_admin

- **WHEN** o super_admin acessa o Audit
- **THEN** ele SHALL ver eventos de qualquer Account

#### Scenario: Visão restrita do admin

- **WHEN** um admin acessa o Audit
- **THEN** ele SHALL ver apenas eventos da sua Account

#### Scenario: Acesso negado

- **WHEN** um operator ou user tenta acessar o Audit
- **THEN** o acesso SHALL ser negado

### Requirement: Imutabilidade

O registro de Audit SHALL NOT ser editável ou removível pela aplicação.

#### Scenario: Alteração bloqueada

- **WHEN** qualquer User tenta editar ou excluir um evento de Audit
- **THEN** a operação SHALL ser negada
