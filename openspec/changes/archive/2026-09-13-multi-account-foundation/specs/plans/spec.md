## Purpose

Define o catálogo de Plans de assinatura, seus limites e as regras de troca e de estouro para as Accounts.

## ADDED Requirements

### Requirement: Catálogo de Plans

A Account A SHALL gerenciar o catálogo de Plans, que SHALL iniciar com 3 planos: Básico marcado como padrão, Intermediário e Avançado.

#### Scenario: Catálogo inicial

- **WHEN** o sistema é instalado
- **THEN** o catálogo SHALL conter os 3 planos com o Básico marcado como padrão

#### Scenario: Novo Plan

- **WHEN** o super_admin cria um Plan com nome, preço e limites
- **THEN** ele SHALL ficar disponível para atribuição às Accounts

#### Scenario: Plan padrão

- **WHEN** uma nova Account é criada
- **THEN** ela SHALL receber o Plan marcado como padrão

### Requirement: Dimensões limitadas

Cada Plan SHALL limitar número de Users, número de Clients, Modules liberados e volume mensal de consultas.

#### Scenario: Limite respeitado

- **WHEN** uma Account dentro dos limites cria User, Client ou usa Module liberado
- **THEN** a operação SHALL ser permitida

#### Scenario: Invites pendentes contam como Users

- **WHEN** a soma de Users ativos e Invites pendentes válidos atinge o limite de Users do Plan
- **THEN** novos Invites SHALL ser bloqueados

#### Scenario: Invites expirados não contam

- **WHEN** existem Invites expirados na Account
- **THEN** eles SHALL NOT contar para o limite de Users

#### Scenario: Aceite no limite exato

- **WHEN** o aceite de um Invite leva a Account ao limite de Users, excluindo o próprio Invite da contagem
- **THEN** o aceite SHALL ser permitido

### Requirement: Estouro de limite

Quando uma operação excederia qualquer dimensão do Plan, ela SHALL ser bloqueada com aviso indicando a necessidade de upgrade.

#### Scenario: Limite estourado

- **WHEN** criar User, Client ou usar Module excederia o Plan vigente
- **THEN** a operação SHALL ser bloqueada com aviso de upgrade

#### Scenario: Verificação no backend

- **WHEN** a operação é solicitada diretamente ao backend, ignorando o frontend
- **THEN** o bloqueio SHALL ocorrer da mesma forma

### Requirement: Modules liberados

O acesso a um Module SHALL depender de o Plan vigente liberá-lo.

#### Scenario: Module liberado

- **WHEN** um User acessa um Module presente no Plan da sua Account
- **THEN** o acesso SHALL ser permitido conforme seu Role

#### Scenario: Module bloqueado

- **WHEN** um User acessa um Module ausente do Plan da sua Account
- **THEN** o acesso SHALL ser negado com aviso de upgrade

### Requirement: Troca de Plan pela A

Somente um super_admin da Account A SHALL trocar o Plan de uma Account, com efeito imediato.

#### Scenario: Troca pela A

- **WHEN** o super_admin atribui um Plan a uma Account
- **THEN** os novos limites SHALL valer imediatamente para a Account

#### Scenario: Troca negada fora da A

- **WHEN** um admin de Account B tenta trocar o próprio Plan
- **THEN** a operação SHALL ser negada
