# account-certificate Specification

## Purpose

Mantém o Certificado Digital (A1) de cada Account no cofre, com validade e vínculo aos Autores do Pedido de Dados, permitindo que o escritório assine termos e autentique chamadas em nome de seus Clients.

## Requirements

### Requirement: Upload e guarda do Certificado Digital

Cada Account SHALL poder cadastrar ou substituir seu Certificado Digital (tipo arquivo .pfx/.p12) com senha; o arquivo e a senha SHALL ser guardados no cofre e referenciados apenas por identificador opaco; o sistema SHALL inspecionar o certificado e expor titular, thumbprint e data de validade.

#### Scenario: Cadastro válido
- **WHEN** um `admin` da Account envia um PFX válido com a senha correta
- **THEN** o Certificado Digital fica ativo na Account com titular, thumbprint e validade exibidos e nenhum arquivo ou senha é exposto fora do cofre

#### Scenario: Arquivo ou senha inválidos
- **WHEN** o PFX não pode ser lido ou a senha não confere
- **THEN** o cadastro é recusado com erro de validação e o Certificado Digital anterior permanece ativo

#### Scenario: Substituição mantém uma única versão ativa
- **WHEN** um novo Certificado Digital é cadastrado para a mesma Account
- **THEN** a versão anterior deixa de ser usada e o Audit registra a troca

### Requirement: Vínculo do Certificado Digital com o Autor do Pedido de Dados

O cadastro do Autor do Pedido de Dados SHALL usar o Certificado Digital ativo da Account como signatário; troca ou expiração do Certificado Digital SHALL atualizar o estado do autor.

#### Scenario: Autor herda o thumbprint
- **WHEN** um Autor do Pedido de Dados é cadastrado em uma Account com Certificado Digital ativo
- **THEN** o autor fica vinculado ao thumbprint e à validade do certificado vigente

#### Scenario: Certificado expirado impede execução
- **WHEN** o Certificado Digital de uma Account vence
- **THEN** as execuções que dependem do autor vinculado são bloqueadas de forma fail-closed e o estado factual fica visível

### Requirement: Escopo e isolamento do Certificado Digital

O Certificado Digital SHALL pertencer a uma única Account; leitura, troca ou remoção por outra Account SHALL responder 404 indistinguível, e somente `admin` e `super_admin` SHALL gerenciá-lo.

#### Scenario: Account estranha não vê o certificado
- **WHEN** um usuário de outra Account solicita o Certificado Digital
- **THEN** a resposta é 404, sem confirmar a existência do recurso

#### Scenario: Operator não gerencia certificado
- **WHEN** um `operator` tenta cadastrar, trocar ou remover o Certificado Digital
- **THEN** a operação responde 403 e nada muda
