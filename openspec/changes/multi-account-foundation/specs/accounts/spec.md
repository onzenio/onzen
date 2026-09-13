## Purpose

Define a criação, os perfis e o vínculo de Users das Accounts, garantindo isolamento de dados entre a operação principal e os escritórios.

## ADDED Requirements

### Requirement: Perfis de Account

O sistema SHALL suportar dois perfis de Account: A (principal) e B (escritório comum).

#### Scenario: Primeira Account é A

- **WHEN** a plataforma está vazia e o Onboarding é concluído
- **THEN** a Account criada SHALL ter perfil A

#### Scenario: Novas Accounts são B

- **WHEN** a Account A cria uma nova Account
- **THEN** a Account criada SHALL ter perfil B

### Requirement: Vínculo User-Account

Cada User SHALL pertencer a exatamente uma Account.

#### Scenario: User vinculado

- **WHEN** um User é criado pelo Onboarding ou aceita um Invite
- **THEN** ele SHALL estar vinculado a uma única Account

#### Scenario: Sem trânsito entre Accounts

- **WHEN** um User autenticado de uma Account tenta acessar dados de outra Account
- **THEN** o acesso SHALL ser negado, exceto via Account Switcher do super_admin

### Requirement: Criação de Accounts pela A

Somente um super_admin da Account A SHALL criar novas Accounts, que nascem ativas no Plan básico e com um admin inicial convidado.

#### Scenario: Criação com admin inicial

- **WHEN** o super_admin informa o nome da nova Account e o e-mail do admin inicial
- **THEN** a Account SHALL nascer com perfil B, ativa no Plan padrão, e um Invite de admin SHALL ser enviado ao e-mail informado

#### Scenario: Criação negada fora da A

- **WHEN** um User que não seja super_admin tenta criar uma Account
- **THEN** a operação SHALL ser negada

### Requirement: Listagem de Accounts

O super_admin SHALL listar as Accounts da plataforma com nome, perfil e Plan vigente.

#### Scenario: Listagem na área da A

- **WHEN** o super_admin acessa a gestão de Accounts
- **THEN** ele SHALL ver todas as Accounts com seus dados de perfil e Plan vigente

### Requirement: Isolamento de dados por Account

Toda entidade de negócio SHALL pertencer a uma Account, e leituras e escritas SHALL ser escopadas à Account efetiva do User.

#### Scenario: Leitura escopada

- **WHEN** um User lista Clients, Users ou qualquer entidade da sua Account
- **THEN** ele SHALL enxergar apenas registros da Account efetiva

#### Scenario: Escrita escopada

- **WHEN** um User cria ou altera uma entidade
- **THEN** ela SHALL ser vinculada à Account efetiva, nunca a outra Account
