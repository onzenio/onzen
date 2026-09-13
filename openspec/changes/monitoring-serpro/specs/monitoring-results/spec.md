## Purpose

Transforma as respostas das consultas em resultados normalizados, snapshots idempotentes, mudanças detectadas e alertas acionáveis, sem inventar dados.

## ADDED Requirements

### Requirement: Normalização por família

O sistema SHALL normalizar os resultados por família de consulta (PGDAS-D, Regime de Apuração, DEFIS, MEI, DCTFWeb, MIT, Situação Fiscal e Caixa Postal, incluindo DTE), extraindo os campos essenciais de cada família com fallback neutro quando um campo não existir, sem fabricar valores. Pagamentos é catalogada e executável, mas permanece sem normalizador (resultado sinalizado como não normalizado) até um change futuro.

#### Scenario: Campos essenciais extraídos
- **WHEN** uma consulta de uma família suportada conclui com sucesso
- **THEN** o resultado normalizado expõe os campos essenciais da família e os ausentes ficam nulos ou vazios, nunca inventados

#### Scenario: Família sem normalizador
- **WHEN** uma consulta de família sem normalizador conclui
- **THEN** o resultado é registrado de forma bruta controlada e sinalizado como não normalizado, e a execução permanece rastreável

### Requirement: Snapshots idempotentes

Cada execução bem-sucedida SHALL produzir um snapshot versionado com fingerprint; repetir a consulta sem mudança SHALL NOT criar nova versão nem alerta.

#### Scenario: Repetição sem mudança não versiona
- **WHEN** uma nova execução retorna o mesmo fingerprint do snapshot vigente
- **THEN** nenhum novo snapshot nem mudança é criado e a última verificação é atualizada

#### Scenario: Mudança gera snapshot e mudança
- **WHEN** o fingerprint difere do vigente
- **THEN** um novo snapshot é persistido e uma mudança correspondente é registrada

### Requirement: Alertas idempotentes e acknowledge

Mudanças relevantes SHALL gerar alertas para a Account; o alerta SHALL poder ser reconhecido por `admin` ou `operator`, de forma idempotente e auditada.

#### Scenario: Alerta gerado uma única vez
- **WHEN** uma mudança relevante é detectada
- **THEN** exatamente um alerta é criado para a associação

#### Scenario: Reconhecimento idempotente
- **WHEN** o usuário reconhece um alerta já reconhecido
- **THEN** a operação permanece bem-sucedida sem duplicar registro nem alterar o histórico

### Requirement: Freshness e completude visíveis

O snapshot SHALL expor freshness, cobertura e estado de completude, distinguindo resultado completo, incompleto, stale e bloqueado.

#### Scenario: Resultado aguardando protocolo fica incompleto
- **WHEN** a consulta ainda aguarda protocolo
- **THEN** o snapshot vigente permanece o último completo e o estado corrente indica verificação em andamento

#### Scenario: Resultado bloqueado não vira completo
- **WHEN** a execução foi bloqueada por transporte ou quota
- **THEN** nenhum snapshot completo é publicado e o motivo factual fica visível

### Requirement: Leitura isolada e sem payload bruto

Listagens e detalhes de snapshots, mudanças e alertas SHALL ser isolados por Account e escopados ao Client; o payload bruto e referências internas de armazenamento SHALL NOT ser expostos na leitura.

#### Scenario: Cross-account responde 404
- **WHEN** o usuário solicita snapshot de Client de outra Account
- **THEN** a resposta é 404 indistinguível

#### Scenario: Listagem sem payload bruto
- **WHEN** a listagem de snapshots é exibida
- **THEN** apenas campos normalizados e metadados de estado são retornados

### Requirement: CND lida do snapshot

A seção de CND na ficha do Client SHALL ler o snapshot vigente de Situação Fiscal e SHALL NOT disparar consulta ao abrir a tela.

#### Scenario: Abrir a ficha não consulta
- **WHEN** o usuário abre a ficha do Client que possui snapshot de Situação Fiscal
- **THEN** a seção de CND mostra o snapshot vigente sem chamada externa

#### Scenario: Sem snapshot, estado explícito
- **WHEN** o Client ainda não possui snapshot de Situação Fiscal
- **THEN** a seção informa ausência factual, sem prometer cobertura

### Requirement: Cadeia consultiva PGDAS-D

A consulta PGDAS-D SHALL encadear índice de declarações, declaração/recibo e extrato quando o índice indicar novidade, registrando cada passo com idempotência.

#### Scenario: Cadeia avança após índice novo
- **WHEN** o índice de PGDAS-D detecta período novo ou alterado
- **THEN** as consultas seguintes da cadeia são enfileiradas de forma idempotente para o mesmo Client
