## Purpose

Define como Users entram e mantêm sessão na plataforma, autenticando contra o backend Laravel por meio do BFF do frontend Nuxt, sem auto-cadastro público.

## Requirements

### Requirement: Autenticação por credenciais

O sistema SHALL autenticar Users por e-mail e senha, criando uma sessão de servidor válida para as requisições seguintes.

#### Scenario: Login válido

- **WHEN** um User informa e-mail e senha corretos
- **THEN** uma sessão SHALL ser criada e o User SHALL acessar a plataforma autenticado

#### Scenario: Login inválido

- **WHEN** um User informa senha incorreta ou e-mail inexistente
- **THEN** a autenticação SHALL ser negada com mensagem genérica que não revela se o e-mail existe

### Requirement: Sessão intermediada pelo BFF

O browser SHALL interagir apenas com a origem do frontend; as server routes do Nuxt SHALL intermediar autenticação, consulta de sessão e logout com o backend, mantendo o cookie de sessão httpOnly e same-origin. O backend SHALL rejeitar requisições sem sessão válida.

#### Scenario: Sessão ativa

- **WHEN** o frontend consulta o User atual com sessão válida
- **THEN** o sistema SHALL responder com identificação do User, Role e Account vinculada

#### Scenario: Requisição sem sessão

- **WHEN** uma requisição chega sem sessão válida ou com sessão expirada
- **THEN** o sistema SHALL negar o acesso com resposta não autenticada

#### Scenario: Logout

- **WHEN** um User autenticado encerra a sessão
- **THEN** a sessão SHALL ser invalidada e o cookie de autenticação removido

### Requirement: Recuperação de senha

O sistema SHALL permitir redefinir a senha por e-mail com token de uso único e expiração, e a resposta à solicitação SHALL ser a mesma exista ou não o e-mail.

#### Scenario: Solicitação de redefinição

- **WHEN** um visitante solicita redefinição informando um e-mail
- **THEN** o sistema SHALL responder de forma genérica e, se o e-mail existir, enviar o link de redefinição

#### Scenario: Redefinição válida

- **WHEN** o titular usa um token válido e define uma nova senha conforme a política
- **THEN** a senha SHALL ser alterada e as sessões anteriores SHALL ser invalidadas

#### Scenario: Token inválido ou expirado

- **WHEN** o token informado é inválido, já usado ou expirado
- **THEN** a redefinição SHALL ser negada com orientação a solicitar novo link

### Requirement: Ausência de auto-cadastro

O sistema SHALL NOT oferecer registro público; contas de User surgem apenas pelo Onboarding da primeira Account ou pelo aceite de Invite.

#### Scenario: Registro público negado

- **WHEN** um visitante tenta acessar um fluxo de auto-cadastro
- **THEN** o sistema SHALL negar o acesso e orientar a entrada por Onboarding ou Invite

#### Scenario: Visitante sem Invite

- **WHEN** um visitante sem Invite válido tenta criar uma conta de User
- **THEN** o sistema SHALL negar a criação
