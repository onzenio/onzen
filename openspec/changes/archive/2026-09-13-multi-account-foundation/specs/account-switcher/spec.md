## Purpose

Define o Account Switcher do super_admin para configuração, suporte e manutenção nas demais Accounts, sem trocar de credenciais.

## ADDED Requirements

### Requirement: Account Switcher exclusivo do super_admin

Somente Users super_admin SHALL usar o Account Switcher.

#### Scenario: Acesso ao Account Switcher

- **WHEN** um super_admin lista as Accounts no Account Switcher
- **THEN** ele SHALL poder selecionar qualquer Account para atuar

#### Scenario: Account Switcher negado

- **WHEN** um admin, operator ou user tenta usar o Account Switcher
- **THEN** a operação SHALL ser negada

#### Scenario: Account Switcher invisível

- **WHEN** um admin, operator ou user acessa o shell
- **THEN** o Account Switcher SHALL NOT aparecer na interface

### Requirement: Atuação na Account alvo

Atuando via Account Switcher, o super_admin SHALL ter poderes de plataforma na Account alvo, com as ações registradas em nome dessa Account.

#### Scenario: Ação via Account Switcher

- **WHEN** o super_admin via Account Switcher cria User, troca Plan ou ajusta configuração na Account alvo
- **THEN** a ação SHALL ser executada no contexto da Account alvo, constando o ator e a Account alvo no Audit

#### Scenario: Dados escopados à alvo

- **WHEN** o super_admin atua via Account Switcher
- **THEN** listagens e operações SHALL enxergar os dados da Account alvo, não os da Account A

#### Scenario: Retorno à origem

- **WHEN** o super_admin encerra a atuação via Account Switcher
- **THEN** ele SHALL voltar ao contexto da Account A

### Requirement: Sinalização de atuação

Enquanto estiver atuando via Account Switcher, o sistema SHALL exibir sinalização persistente identificando a Account alvo, com ação explícita de saída.

#### Scenario: Sinalização visível

- **WHEN** o super_admin está atuando em outra Account
- **THEN** um aviso persistente "atuando como {Account}" SHALL estar visível com botão de sair

### Requirement: Vínculo preservado

O uso do Account Switcher SHALL NOT alterar o vínculo do User nem suas credenciais; o super_admin SHALL permanecer vinculado à Account A.

#### Scenario: Vínculo inalterado

- **WHEN** o super_admin usa o Account Switcher
- **THEN** seu User SHALL continuar vinculado à Account A e autenticado com as mesmas credenciais

### Requirement: Audit do Account Switcher

Toda entrada, ação e saída via Account Switcher SHALL ser registrada com ator, Account de origem, Account alvo, ação e momento.

#### Scenario: Trilha auditável

- **WHEN** o super_admin entra, age e sai de uma Account alvo
- **THEN** cada evento SHALL constar no Audit com ator, origem, alvo, ação e momento
