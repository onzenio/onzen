## ADDED Requirements

### Requirement: Recuperação de trabalho interrompido

Execuções e ações SERPRO SHALL possuir uma janela observável de processamento e SHALL sair de `running` quando o worker for interrompido; uma entrega concorrente dentro da janela SHALL aguardar o worker ativo, e uma entrega após a janela SHALL encerrar ou retomar o trabalho de forma segura sem repetir uma solicitação externa cujo resultado seja desconhecido.

#### Scenario: Entrega concorrente durante processamento

- **WHEN** outro worker recebe a mesma execução ou ação enquanto a janela de processamento está válida
- **THEN** ele SHALL NOT iniciar uma segunda chamada externa e SHALL aguardar nova tentativa

#### Scenario: Worker interrompido sem protocolo

- **WHEN** uma execução ou ação permanece em `running` além da janela e não possui protocolo persistido
- **THEN** ela SHALL sair de `running` com motivo operacional factual e a solicitação externa original SHALL NOT ser reenviada automaticamente

#### Scenario: Worker interrompido com protocolo

- **WHEN** uma execução ou ação permanece em `running` além da janela e possui protocolo persistido
- **THEN** ela SHALL retomar somente a consulta do protocolo, sem repetir a solicitação externa original

### Requirement: Rotinas automáticas operacionais

O ambiente operacional SHALL executar o scheduler continuamente e SHALL manter apenas uma rotina atual para cada automação de monitoramento suportada, com proteção contra sobreposição.

#### Scenario: Ciclo mensal executado

- **WHEN** chega o horário configurado do primeiro dia do mês
- **THEN** o ciclo automático mensal atual SHALL ser solicitado uma vez e uma segunda instância sobreposta SHALL NOT iniciar

#### Scenario: Renovação diária executada

- **WHEN** chega o horário configurado da rotina diária
- **THEN** a renovação atual de termos e procurações SHALL ser solicitada uma vez e uma segunda instância sobreposta SHALL NOT iniciar

#### Scenario: Rotinas legadas ausentes

- **WHEN** as rotinas agendadas são enumeradas ou executadas
- **THEN** comandos legados substituídos SHALL NOT aparecer nem executar em paralelo com as rotinas atuais
