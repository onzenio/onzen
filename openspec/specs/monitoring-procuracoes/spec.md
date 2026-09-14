# monitoring-procuracoes Specification

## Purpose

Gerencia Autores do Pedido de Dados e procurações, permitindo que o Contratante SERPRO consulte em nome dos Clients com autorização verificada e termo assinado.

## Requirements

### Requirement: Autor do Pedido de Dados por Account

Cada Account SHALL poder cadastrar Autores do Pedido de Dados com documento, nome e vínculo ao Certificado Digital ativo; o autor SHALL ter status e validade; somente `admin` e `super_admin` SHALL gerenciá-los.

#### Scenario: Cadastro de autor
- **WHEN** um `admin` cadastra um Autor do Pedido de Dados com documento válido e Certificado Digital ativo
- **THEN** o autor fica ativo, vinculado ao certificado vigente e selecionável para assinar termos

#### Scenario: Autor sem certificado válido é inelegível
- **WHEN** o Certificado Digital da Account expira ou é removido
- **THEN** os autores vinculados ficam inelegíveis e as consultas que dependem deles são bloqueadas

### Requirement: Termo de autorização assinado

O sistema SHALL montar o termo de autorização em nome do autor, assiná-lo digitalmente com o Certificado Digital e enviá-lo ao serviço oficial; o token de autenticação do procurador SHALL ser guardado no cofre, nunca exibido, e renovado antes do vencimento.

#### Scenario: Termo assinado e enviado
- **WHEN** um autor sem token vigente solicita a habilitação
- **THEN** o termo é assinado com o Certificado Digital, enviado, o token retornado é guardado no cofre e a validade fica registrada

#### Scenario: Token vence e é renovado
- **WHEN** o token está próximo de expirar
- **THEN** o sistema renova o termo automaticamente antes do vencimento sem intervenção do usuário

#### Scenario: Token nunca aparece
- **WHEN** o token é obtido ou renovado
- **THEN** nenhum log, resposta de API ou tela exibe o token

### Requirement: Verificação de procuração por Client

O sistema SHALL verificar a outorga de cada Client junto ao serviço oficial, usando cache de curta duração; sem outorga, a Associação de Monitoramento SHALL ser pausada com motivo factual e retomada após verificação positiva.

#### Scenario: Verificação confirmada habilita
- **WHEN** a verificação oficial retorna outorga válida para o Client e os serviços exigidos
- **THEN** as associações pausadas por outorga são retomadas

#### Scenario: Outorga pendente pausa
- **WHEN** a verificação oficial não retorna outorga para um serviço exigido
- **THEN** a associação é pausada com motivo "outorga pendente" e nenhuma consulta é disparada

#### Scenario: Cache evita chamadas repetidas
- **WHEN** a verificação é solicitada novamente dentro da janela de cache
- **THEN** o resultado em cache é usado sem nova chamada externa

### Requirement: Allowlist de códigos de serviço

A assinatura e a verificação de procuração SHALL aceitar somente códigos de serviço da allowlist oficial; código fora da allowlist SHALL ser recusado com erro explícito.

#### Scenario: Código fora da allowlist
- **WHEN** um cadastro ou verificação referencia código de serviço fora da allowlist
- **THEN** a operação é recusada e nenhuma chamada externa é feita

### Requirement: Divergências de procuração visíveis

A plataforma SHALL listar Clients com procuração faltante, expirada ou divergente, isolada por Account, com 404 para Clients fora da carteira.

#### Scenario: Divergências da carteira
- **WHEN** o usuário consulta divergências de procuração
- **THEN** somente Clients visíveis da sua Account são retornados com o motivo da divergência

### Requirement: Renovação diária de termos

O sistema SHALL executar rotina diária de renovação dos termos e reverificação de procurações, retomando associações pausadas por outorga quando a verificação voltar a passar.

#### Scenario: Rotina diária renova
- **WHEN** a rotina diária roda e existem termos próximos do vencimento
- **THEN** os termos são renovados e as associações elegíveis permanecem executáveis
