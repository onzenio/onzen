## MODIFIED Requirements

### Requirement: Criação de Accounts pela A

Somente um super_admin da Account A SHALL criar novas Accounts, que nascem ativas no Plan básico e com um admin inicial convidado; a interface SHALL comunicar essa consequência antes do envio e impedir submissões duplicadas enquanto a criação estiver pendente.

#### Scenario: Criação com admin inicial

- **WHEN** o super_admin informa o nome da nova Account e o e-mail do admin inicial
- **THEN** a Account SHALL nascer com perfil B, ativa no Plan padrão, e um Invite de admin SHALL ser enviado ao e-mail informado

#### Scenario: Criação pendente

- **WHEN** o super_admin envia o formulário de criação de uma Account
- **THEN** a interface SHALL indicar o processamento e SHALL NOT aceitar outra submissão até a operação terminar

#### Scenario: Erro de validação na criação

- **WHEN** a criação é rejeitada por dados inválidos
- **THEN** a interface SHALL manter o formulário aberto e SHALL exibir os erros junto aos campos correspondentes

#### Scenario: Criação negada fora da A

- **WHEN** um User que não seja super_admin tenta criar uma Account
- **THEN** a operação SHALL ser negada

### Requirement: Listagem de Accounts

O super_admin SHALL listar todas as Accounts da plataforma com nome, perfil e Plan vigente, distinguindo na interface a Account A como central OneFisc e cada Account B como escritório.

#### Scenario: Listagem na área da A

- **WHEN** o super_admin acessa a gestão de Accounts
- **THEN** ele SHALL ver todas as Accounts com nome, tipo e Plan vigente

#### Scenario: Identificação dos perfis

- **WHEN** a listagem contém Accounts A e B
- **THEN** a interface SHALL identificar a Account A como central OneFisc e as Accounts B como escritórios

## ADDED Requirements

### Requirement: Estados da listagem de Accounts

A gestão de Accounts SHALL comunicar os estados de carregamento, ausência de resultados e falha de carregamento sem apresentar dados fictícios.

#### Scenario: Listagem em carregamento

- **WHEN** a consulta de Accounts está pendente
- **THEN** a interface SHALL apresentar um estado de carregamento na região da listagem

#### Scenario: Listagem vazia

- **WHEN** a consulta termina sem Accounts
- **THEN** a interface SHALL informar que nenhuma Account foi encontrada

#### Scenario: Falha ao listar

- **WHEN** a consulta de Accounts falha
- **THEN** a interface SHALL apresentar uma mensagem persistente e uma ação para tentar novamente
