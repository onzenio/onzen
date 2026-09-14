## MODIFIED Requirements

### Requirement: Navegação por Role

A navegação SHALL exibir apenas os grupos, itens e ações acessíveis ao Role do User autenticado e SHALL reunir as ações de plataforma em um grupo administrativo colapsável.

#### Scenario: Navegação da A

- **WHEN** um super_admin acessa o shell
- **THEN** ele SHALL ver o grupo Administração com a gestão de Accounts, de Plans e de Audit

#### Scenario: Navegação sem plataforma

- **WHEN** um admin, operator ou user acessa o shell
- **THEN** a gestão de Accounts, a gestão de Plans e o Account Switcher SHALL NOT aparecer

#### Scenario: Navegação do admin

- **WHEN** um admin acessa o shell
- **THEN** ele SHALL ver no grupo Administração somente Audit da sua Account e SHALL NOT ver a gestão de Accounts, de Plans ou o Account Switcher

#### Scenario: Navegação sem administração

- **WHEN** um operator ou user acessa o shell
- **THEN** o grupo Administração, a gestão de Accounts, a gestão de Plans e o Account Switcher SHALL NOT aparecer

#### Scenario: Navegação mínima do user

- **WHEN** um user acessa o shell
- **THEN** ele SHALL ver apenas os itens correspondentes ao trabalho atribuído

#### Scenario: Administração em sidebar recolhido ou mobile

- **WHEN** um User autorizado usa o shell com o sidebar recolhido ou em uma tela móvel
- **THEN** ele SHALL conseguir abrir o grupo Administração e acessar os mesmos filhos permitidos ao seu Role

## ADDED Requirements

### Requirement: Proteção frontend das rotas de plataforma

A interface SHALL impedir que um User sem Role super_admin permaneça nas rotas de gestão de Accounts ou de Plans, sem substituir a autorização do backend.

#### Scenario: Acesso direto sem permissão

- **WHEN** um admin, operator ou user navega diretamente para `/accounts` ou `/plans`
- **THEN** a interface SHALL redirecioná-lo para `/` sem apresentar a área restrita

#### Scenario: Acesso direto do super_admin

- **WHEN** um super_admin navega diretamente para `/accounts` ou `/plans`
- **THEN** a interface SHALL permitir o acesso à área solicitada
