## MODIFIED Requirements

### Requirement: Download autorizado e auditado

O download SHALL ser permitido somente a Users autorizados da Account do Client, SHALL ocorrer pela origem do frontend com a sessão intermediada pelo BFF e SHALL ser auditado com identificador do artefato, autor e data; a assinatura e a expiração SHALL permanecer válidas até o backend, e referência de outra Account SHALL responder 404 indistinguível.

#### Scenario: Download da própria Account

- **WHEN** um User autorizado baixa um artefato da sua Account por um link válido
- **THEN** o BFF SHALL entregar o arquivo e o Audit SHALL registrar o acesso

#### Scenario: Download autenticado na mesma origem

- **WHEN** o browser segue o link de download fornecido pela API com uma sessão válida no frontend
- **THEN** a requisição SHALL permanecer na origem do frontend e o backend SHALL reconhecer o User autenticado

#### Scenario: Cross-account responde 404

- **WHEN** um User solicita artefato de outra Account
- **THEN** a resposta SHALL ser 404 indistinguível

#### Scenario: Link expirado responde 403

- **WHEN** o link de download assinado está expirado
- **THEN** a resposta SHALL ser 403 e nenhum arquivo SHALL ser entregue

#### Scenario: Assinatura alterada responde 403

- **WHEN** o caminho, a referência ou os parâmetros assinados do link são alterados
- **THEN** a resposta SHALL ser 403 e nenhum arquivo SHALL ser entregue
