# monitoring-artifacts Specification

## Purpose

Guarda e entrega os artefatos (PDF/XML) produzidos pelas consultas e ações SERPRO, com integridade verificável, isolamento por Account e download auditado.

## Requirements

### Requirement: Armazenamento privado com integridade

Todo artefato gerado por consulta ou ação SHALL ser guardado em armazenamento privado do backend, identificado por referência opaca e hash SHA-256; o caminho físico SHALL NOT ser exposto em API, tela ou log.

#### Scenario: Artefato registrado com hash
- **WHEN** uma consulta ou ação produz PDF ou XML
- **THEN** o arquivo é guardado com referência opaca e hash, e a listagem expõe apenas a referência

#### Scenario: Conteúdo decodificado no processamento
- **WHEN** a resposta traz conteúdo em base64
- **THEN** a decodificação acontece durante o processamento da execução e o arquivo resultante é guardado no armazenamento privado

#### Scenario: Falha de decodificação não quebra a execução
- **WHEN** um campo base64 não pode ser decodificado
- **THEN** a execução registra a falha de artefato, mantém o restante do resultado e não inventa arquivo

### Requirement: Download autorizado e auditado

O download SHALL ser permitido somente a usuários autorizados da Account do Client e SHALL ser auditado com identificador do artefato, autor e data; referência de outra Account SHALL responder 404 indistinguível.

#### Scenario: Download da própria Account
- **WHEN** um usuário autorizado baixa um artefato da sua Account
- **THEN** o arquivo é entregue e o Audit registra o acesso

#### Scenario: Cross-account responde 404
- **WHEN** um usuário solicita artefato de outra Account
- **THEN** a resposta é 404 indistinguível

#### Scenario: Link expirado responde 403
- **WHEN** o link de download assinado está expirado
- **THEN** a resposta é 403 e nenhum arquivo é entregue

### Requirement: Disponibilidade do armazenamento observável

Quando o armazenamento estiver indisponível, o download SHALL responder de forma retryable, sem vazar detalhe interno, e o artefato SHALL continuar rastreável.

#### Scenario: Storage indisponível
- **WHEN** o armazenamento privado está fora do ar durante o download
- **THEN** a resposta indica indisponibilidade temporária, o evento é registrado e o artefato não é perdido da listagem

### Requirement: Redaction de conteúdo sensível

PFX, senhas, tokens e conteúdo fiscal bruto SHALL NOT ser persistidos em logs, fila de erros, eventos ou respostas de listagem.

#### Scenario: Log sem conteúdo fiscal
- **WHEN** uma consulta gera artefato e registra eventos
- **THEN** nenhum conteúdo do PDF/XML, token ou certificado aparece nos registros

#### Scenario: Fila de erros sem segredo
- **WHEN** uma execução falha definitivamente e entra na fila de erros
- **THEN** a entrada guarda apenas motivo e identificadores, sem conteúdo nem segredo

### Requirement: Vínculo do artefato ao seu contexto

Cada artefato SHALL permanecer vinculado à execução ou ação que o produziu, e SHALL deixar de ser acessível quando o Client sair da carteira da Account ou a associação for encerrada, preservando o histórico apenas conforme a visibilidade da Account.

#### Scenario: Artefato segue a ação
- **WHEN** a listagem de uma execução ou ação é aberta
- **THEN** os artefatos vinculados aparecem com seus metadados de integridade

#### Scenario: Client fora da carteira não expõe artefato
- **WHEN** o Client deixa de pertencer à carteira da Account
- **THEN** o artefato deixa de ser acessível por essa Account
