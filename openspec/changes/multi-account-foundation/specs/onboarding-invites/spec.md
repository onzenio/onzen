## Purpose

Define a entrada na plataforma: Onboarding da primeira Account e Invites de Users com expiração e senha no aceite.

## ADDED Requirements

### Requirement: Onboarding da primeira Account

Com a base vazia, o cadastro inicial SHALL criar a Account A com seu User super_admin e autenticá-lo.

#### Scenario: Base vazia vira Onboarding

- **WHEN** a plataforma não possui nenhuma Account e alguém conclui o cadastro inicial com nome, e-mail e senha
- **THEN** uma Account A SHALL ser criada com o cadastrante como super_admin autenticado

#### Scenario: Sem Onboarding com base populada

- **WHEN** já existe ao menos uma Account e um visitante tenta acessar o Onboarding
- **THEN** o fluxo SHALL estar indisponível e o sistema SHALL orientar a entrada por Invite

### Requirement: Invite de User

Um admin SHALL convidar Users informando nome, e-mail e Role, com expiração em 7 dias e definição de senha no aceite. O Invite SHALL registrar quem convidou e a qual Account pertence.

#### Scenario: Invite criado

- **WHEN** um admin convida alguém com nome, e-mail e Role válidos dentro dos limites do Plan
- **THEN** um Invite pendente SHALL ser criado com token de uso único e expiração em 7 dias, e o e-mail SHALL ser enviado

#### Scenario: Invite duplicado

- **WHEN** já existe Invite pendente para o mesmo e-mail na Account
- **THEN** um novo Invite SHALL ser negado

#### Scenario: E-mail já pertencente à plataforma

- **WHEN** o e-mail informado já pertence a um User de qualquer Account
- **THEN** o Invite SHALL ser negado

#### Scenario: Role inválido

- **WHEN** um admin tenta convidar para super_admin, ou alguém tenta convidar para Role fora de admin, operator e user
- **THEN** o Invite SHALL ser negado

### Requirement: Aceite de Invite

O aceite SHALL criar o User vinculado à Account do Invite com o Role informado, definir a senha e invalidar o Invite.

#### Scenario: Aceite válido

- **WHEN** um convidado com Invite válido define uma senha conforme a política
- **THEN** sua conta SHALL ser ativada vinculada à Account do Invite com o Role informado, e o Invite SHALL ser invalidado

#### Scenario: Invite expirado

- **WHEN** um convidado tenta aceitar um Invite com mais de 7 dias
- **THEN** o aceite SHALL ser negado e um novo Invite SHALL ser necessário

#### Scenario: Invite já usado

- **WHEN** um convidado tenta aceitar um Invite já aceito ou revogado
- **THEN** o aceite SHALL ser negado

### Requirement: Gestão de Invites pendentes

O admin SHALL listar e revogar Invites pendentes da sua Account.

#### Scenario: Revogação

- **WHEN** um admin revoga um Invite pendente
- **THEN** o Invite SHALL deixar de ser aceitável

#### Scenario: Invites de outra Account

- **WHEN** um admin lista Invites
- **THEN** ele SHALL ver apenas Invites da sua Account
