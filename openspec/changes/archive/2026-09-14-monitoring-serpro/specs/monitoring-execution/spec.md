## Purpose

Orquestra o disparo, a execução assíncrona, a quota do Plan e a observabilidade das consultas SERPRO, garantindo idempotência e fail-closed.

## ADDED Requirements

### Requirement: Disparo manual e ciclo automático

Consultas SHALL ser executadas de forma assíncrona: disparo manual explícito por `admin` ou `operator`, e ciclo automático mensal restrito a definições marcadas como automáticas, disparado pelo sistema; a resposta de solicitação SHALL ser 202 com identificador da execução.

#### Scenario: Disparo manual responde 202
- **WHEN** um `operator` dispara a consulta de uma associação ativa
- **THEN** a resposta é 202 com o identificador da execução e nenhuma chamada externa ocorre na requisição HTTP

#### Scenario: Ciclo automático só consulta
- **WHEN** o ciclo mensal roda
- **THEN** apenas definições automáticas com associação ativa são disparadas, com origem registrada como automática

#### Scenario: User não dispara consulta
- **WHEN** um `user` tenta disparar consulta
- **THEN** a operação responde 403 e nenhuma execução é criada

### Requirement: Quota agregada do Plan

Toda execução de consulta SHALL consumir `monthly_query_volume` do Plan da Account no mês corrente, com reserva atômica antes de qualquer tráfego; esgotado o volume, a execução SHALL ser recusada com mensagem acionável de upgrade, sem débito e sem chamada externa.

#### Scenario: Consumo desconta do volume
- **WHEN** uma consulta é aceita
- **THEN** uma unidade do volume mensal da Account é reservada e o saldo fica consultável

#### Scenario: Volume esgotado bloqueia
- **WHEN** a Account não possui mais volume no ciclo
- **THEN** a execução é recusada com 422 e mensagem que orienta a troca de Plan, sem consumir volume

#### Scenario: Automática também consome
- **WHEN** o ciclo automático dispara consultas
- **THEN** cada execução reserva volume do Plan como qualquer consulta manual

#### Scenario: Corrida de quota não ultrapassa o teto
- **WHEN** duas execuções concorrentes disputam as últimas unidades do volume
- **THEN** o total reservado nunca ultrapassa `monthly_query_volume`

### Requirement: Idempotência e fencing

Execuções SHALL ser idempotentes por chave de idempotência e fencing: repetir a mesma solicitação não SHALL criar execução duplicada nem reenviar tráfego quando o resultado já existe; resultados de execuções superadas SHALL ser descartados.

#### Scenario: Repetição não duplica
- **WHEN** a mesma solicitação é repetida com a mesma chave de idempotência
- **THEN** o identificador existente é retornado e nenhuma segunda chamada externa é feita

#### Scenario: Execução superada é descartada
- **WHEN** uma execução conclui com fencing token anterior ao vigente
- **THEN** o resultado não altera o estado da associação

### Requirement: Classificação, retry e protocolo

Respostas e falhas SHALL ser classificadas; falhas transitórias (429, timeout, 5xx) SHALL ter retry com backoff e limite de tentativas; rejeições definitivas SHALL encerrar sem retry; resposta com protocolo pendente SHALL ser consultada por polling sem nova consulta original.

#### Scenario: 429 com backoff
- **WHEN** a SERPRO responde 429
- **THEN** a execução é reprogramada com backoff, o snapshot não avança e a tentativa fica visível

#### Scenario: Rejeição definitiva não repete
- **WHEN** a resposta é uma rejeição de negócio definitiva
- **THEN** a execução encerra como rejeitada, sem novas tentativas, com motivo registrado

#### Scenario: Protocolo pendente é pollado
- **WHEN** a resposta retorna protocolo sem resultado final
- **THEN** a execução fica aguardando e consulta o protocolo até obter o resultado, sem repetir a solicitação original

### Requirement: Fail-closed na execução

Sem transporte aprovado, sem credencial válida ou com Certificado Digital expirado, nenhuma chamada HTTP real SHALL ser feita; o sistema SHALL registrar o bloqueio de forma factual.

#### Scenario: Execução bloqueada não chama externo
- **WHEN** uma consulta é disparada com o transporte desligado
- **THEN** nenhuma requisição real parte e a execução registra o estado de bloqueio

#### Scenario: Credencial ausente bloqueia
- **WHEN** a credencial resolvida do Contratante não existe ou não é válida
- **THEN** a execução é bloqueada antes de qualquer tráfego e o motivo fica visível

### Requirement: Eventos operacionais e redaction

O sistema SHALL emitir eventos estruturados de início e fim de execução e de ação, contendo apenas identificadores opacos; PFX, senhas, tokens, XML e conteúdo fiscal SHALL NOT aparecer em logs, filas de erro ou respostas.

#### Scenario: Evento sem segredo
- **WHEN** uma execução termina
- **THEN** o evento emitido contém identificadores de Account, Client, execução e resultado, sem segredo, token ou conteúdo fiscal

#### Scenario: Falha preserva conteúdo sensível
- **WHEN** uma execução falha e a mensagem de erro precisa ser registrada
- **THEN** a mensagem é redigida e nenhum payload sensível é persistido
