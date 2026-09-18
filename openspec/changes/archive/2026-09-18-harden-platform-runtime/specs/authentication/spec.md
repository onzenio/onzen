## MODIFIED Requirements

### Requirement: Sessão intermediada pelo BFF

O browser SHALL interagir apenas com a origem do frontend; todas as server routes autenticadas do Nuxt SHALL restaurar a sessão protegida e intermediar consultas e mutações com o backend, mantendo o cookie de sessão httpOnly e same-origin e preservando a proteção CSRF. O backend SHALL rejeitar requisições sem sessão válida.

#### Scenario: Sessão ativa

- **WHEN** o frontend consulta o User atual com sessão válida
- **THEN** o sistema SHALL responder com identificação do User, Role e Account vinculada

#### Scenario: Rota específica autenticada

- **WHEN** uma server route específica consulta o backend em nome de um User com sessão válida
- **THEN** o backend SHALL receber a sessão restaurada e responder como User autenticado

#### Scenario: Mutação protegida por CSRF

- **WHEN** uma server route envia uma mutação autenticada ao backend
- **THEN** o backend SHALL receber os cookies da sessão e o token CSRF correspondentes

#### Scenario: Requisição sem sessão

- **WHEN** uma requisição chega sem sessão válida ou com sessão expirada
- **THEN** o sistema SHALL negar o acesso com resposta não autenticada

#### Scenario: Logout

- **WHEN** um User autenticado encerra a sessão
- **THEN** a sessão SHALL ser invalidada e o cookie de autenticação removido
