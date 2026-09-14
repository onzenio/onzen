## Purpose

Entrega a consulta de parcelamentos dos regimes Simples Nacional e MEI, com pedidos, parcelas e pagamentos normalizados e acesso às guias já geradas.

## ADDED Requirements

### Requirement: Modalidades de parcelamento suportadas

O sistema SHALL suportar as oito modalidades de parcelamento do catálogo vigente (PARCSN, PARCSN-ESP, PERTSN, RELPSN, PARCMEI, PARCMEI-ESP, PERTMEI e RELPMEI); modalidades fora da allowlist SHALL aparecer como indisponíveis, sem execução.

#### Scenario: Consulta de modalidade suportada
- **WHEN** uma associação de modalidade suportada e Client elegível é consultada
- **THEN** o resultado normalizado de pedidos de parcelamento fica disponível na Account

#### Scenario: Modalidade indisponível não executa
- **WHEN** o usuário tenta consultar modalidade sem operação executável
- **THEN** nenhuma chamada externa é feita e a indisponibilidade é informada de forma factual

### Requirement: Consulta normalizada de pedidos, parcelas e pagamentos

O resultado SHALL normalizar pedidos de parcelamento, parcelas e pagamentos, com o vínculo ao Client em cada linha e isolamento por Account.

#### Scenario: Detalhe de parcelamento
- **WHEN** o usuário abre o detalhe de um pedido de parcelamento da sua carteira
- **THEN** parcelas e pagamentos normalizados são exibidos com o Client vinculado

#### Scenario: Cross-account responde 404
- **WHEN** o usuário solicita um parcelamento de outra Account
- **THEN** a resposta é 404 indistinguível

### Requirement: Download de guia já gerada

O sistema SHALL permitir baixar guia (DAS) já existente de um parcelamento, sem disparar nova emissão.

#### Scenario: Guia existente é baixada
- **WHEN** o usuário baixa uma guia já gerada de parcela da sua carteira
- **THEN** o arquivo é entregue sem chamada de emissão e o acesso é auditado

#### Scenario: Guia inexistente não emite
- **WHEN** o usuário solicita guia que ainda não existe
- **THEN** o sistema informa ausência factual e nenhuma emissão é disparada automaticamente

### Requirement: Falhas transitórias não avançam estado

Falhas transitórias de consulta de parcelamento (429, timeout, 5xx) SHALL ser reprogramadas com backoff sem avançar o snapshot nem alterar pedidos, parcelas ou pagamentos.

#### Scenario: 429 não altera parcelamento
- **WHEN** a consulta de parcelamento recebe 429
- **THEN** o snapshot vigente e os dados de parcelamento permanecem inalterados até uma tentativa bem-sucedida

### Requirement: Detalhe normalizado por operação

As operações de detalhe de parcelamento SHALL produzir resultado normalizado consistente entre pedidos, parcelas, valores para geração e pagamentos.

#### Scenario: Campos normalizados no detalhe
- **WHEN** o detalhe de um parcelamento é consultado com sucesso
- **THEN** pedido, parcelas e pagamentos exibem os campos essenciais normalizados, com fallback neutro quando ausentes
