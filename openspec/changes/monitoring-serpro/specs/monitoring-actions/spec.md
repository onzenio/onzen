## Purpose

Executa emissão de DAS como Ação Fiscal explícita, com confirmação humana, idempotência e auditoria, nunca como efeito colateral de consulta ou monitoramento.

## ADDED Requirements

### Requirement: Emissão de DAS sempre explícita

A emissão de DAS (PGDAS-D e parcelamentos) SHALL exigir confirmação explícita e chave de idempotência fornecida pela interface; nenhuma emissão SHALL ocorrer por ciclo automático, por consulta ou sem ação humana identificada.

#### Scenario: Emissão confirmada
- **WHEN** um `admin` ou `operator` confirma a emissão de DAS de uma inscrição elegível da sua carteira
- **THEN** a ação é aceita com identificador, chave de idempotência e registro de auditoria de quem solicitou

#### Scenario: Consulta não emite
- **WHEN** uma consulta de PGDAS-D ou de parcelamento é executada
- **THEN** nenhuma guia é emitida como efeito colateral

#### Scenario: Ciclo automático nunca emite
- **WHEN** o ciclo automático roda
- **THEN** nenhuma operação de emissão é disparada

#### Scenario: User não emite
- **WHEN** um `user` tenta emitir DAS
- **THEN** a operação responde 403 e nenhuma ação é criada

### Requirement: Pré-condições da emissão

A emissão SHALL exigir transporte aprovado, credencial válida, procuração aplicável e inscrição ativa; sem as pré-condições, a ação SHALL ser recusada sem chamada externa.

#### Scenario: Transporte desligado recusa sem chamar
- **WHEN** a emissão é solicitada com o transporte desligado
- **THEN** a ação é recusada de forma fail-closed e nenhuma requisição real é feita

#### Scenario: Procuração ausente recusa
- **WHEN** a inscrição não possui a procuração exigida para a emissão
- **THEN** a ação é recusada com motivo factual

### Requirement: Idempotência da emissão

Repetir a solicitação com a mesma chave de idempotência SHALL NOT gerar uma segunda guia; a resposta existente SHALL ser devolvida.

#### Scenario: Repetição devolve o mesmo resultado
- **WHEN** a mesma chave de idempotência é reenviada após conclusão
- **THEN** a ação existente é retornada sem nova emissão

### Requirement: Guia emitida vira artefato baixável

O PDF da guia emitida SHALL ser guardado como artefato da ação e disponibilizado para download auditado; a falha de armazenamento SHALL NOT inventar sucesso.

#### Scenario: PDF baixável após emissão
- **WHEN** a emissão conclui com sucesso
- **THEN** o PDF fica baixável pela ação correspondente e o download é auditado

#### Scenario: Falha de armazenamento é factual
- **WHEN** a emissão conclui mas o armazenamento do PDF falha
- **THEN** a ação registra a falha de artefato e a guia não é apresentada como baixável

### Requirement: Escopo estrito das ações fiscais

Operações de declaração, transmissão ou emissão diferentes de DAS SHALL permanecer fora do escopo; a tentativa SHALL ser rejeitada de forma explícita.

#### Scenario: Declaração é rejeitada
- **WHEN** uma solicitação de declaração, transmissão ou emissão não prevista nesta capability é feita
- **THEN** a ação é rejeitada com motivo explícito, sem chamada externa

### Requirement: Isolamento e auditoria da ação

A ação SHALL ser restrita a inscrições da carteira da Account; solicitação fora da carteira SHALL responder 404 indistinguível; toda ação SHALL gerar evento de auditoria redigido.

#### Scenario: Inscrição fora da carteira responde 404
- **WHEN** a emissão referencia inscrição de outra Account
- **THEN** a resposta é 404 indistinguível

#### Scenario: Auditoria registra a ação
- **WHEN** uma emissão é solicitada
- **THEN** o Audit registra autor, Client, definição, resultado e data, sem segredo ou conteúdo fiscal
