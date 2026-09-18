## MODIFIED Requirements

### Requirement: Modules liberados

O acesso de leitura ou escrita a um Module SHALL depender de o Plan vigente da Account liberá-lo, e essa verificação SHALL ocorrer no backend além de orientar a navegação do frontend.

#### Scenario: Module liberado

- **WHEN** um User acessa um Module presente no Plan da sua Account
- **THEN** o acesso SHALL ser permitido conforme seu Role

#### Scenario: Module bloqueado

- **WHEN** um User acessa um Module ausente do Plan da sua Account
- **THEN** o acesso SHALL ser negado com aviso de upgrade

#### Scenario: Chamada direta ao backend

- **WHEN** um User solicita diretamente ao backend uma operação de um Module ausente do Plan vigente
- **THEN** o backend SHALL negar a operação sem consultar, criar ou alterar dados desse Module
