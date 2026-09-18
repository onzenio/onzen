## MODIFIED Requirements

### Requirement: Onboarding da primeira Account

Com a base vazia, o cadastro inicial SHALL criar exatamente uma Account A com seu User `super_admin` e autenticá-lo; a decisão e a criação SHALL ser atômicas sob requisições concorrentes.

#### Scenario: Base vazia vira Onboarding

- **WHEN** a plataforma não possui nenhuma Account e alguém conclui o cadastro inicial com nome, e-mail e senha
- **THEN** uma Account A SHALL ser criada com o cadastrante como `super_admin` autenticado

#### Scenario: Sem Onboarding com base populada

- **WHEN** já existe ao menos uma Account e um visitante tenta acessar o Onboarding
- **THEN** o fluxo SHALL estar indisponível e o sistema SHALL orientar a entrada por Invite

#### Scenario: Onboarding concorrente

- **WHEN** duas requisições válidas tentam concluir o Onboarding simultaneamente com a base inicialmente vazia
- **THEN** exatamente uma SHALL criar a Account A e seu `super_admin`, e a outra SHALL receber conflito sem criar Account ou User adicional
