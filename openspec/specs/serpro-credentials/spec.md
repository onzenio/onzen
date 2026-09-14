# serpro-credentials Specification

## Purpose

Governa as credenciais do Contratante SERPRO, a alternância de ambiente e o interruptor de transporte do Integra Contador, mantendo o sistema fail-closed até que a operação aprove o tráfego real.

## Requirements

### Requirement: Credenciais do Contratante guardadas no cofre

As credenciais do Contratante SERPRO SHALL ser mantidas no cofre e referenciadas apenas por identificadores opacos; a interface e as respostas de API SHALL exibir identificadores mascarados, nunca o segredo, a senha do Certificado Digital ou o conteúdo do PFX.

#### Scenario: Troca de credenciais auditada
- **WHEN** um `super_admin` substitui as credenciais do Contratante SERPRO
- **THEN** o novo valor passa a valer no próximo uso, o valor anterior deixa de ser acessível e o Audit registra autor, data e identificador mascarado

#### Scenario: Segredo nunca é devolvido
- **WHEN** as credenciais são lidas pela API ou exibidas na administração SERPRO
- **THEN** somente versões mascaradas dos identificadores são retornadas, sem segredo, senha ou PFX

### Requirement: Alternância explícita de ambiente

A plataforma SHALL operar em ambiente de homologação por padrão e SHALL exigir ação explícita e selo visível para operar em produção.

#### Scenario: Homologação por padrão
- **WHEN** o OneFisc é provisionado sem decisão explícita de ambiente
- **THEN** o ambiente registrado é homologação e a administração SERPRO exibe o selo correspondente

#### Scenario: Produção exige dupla confirmação
- **WHEN** um `super_admin` alterna de homologação para produção sem confirmar duas vezes ou sem registrar evidência
- **THEN** a alternância é recusada, o ambiente permanece homologação e o Audit registra a tentativa

### Requirement: Interruptor de transporte fail-closed

O transporte para o Integra Contador SHALL iniciar desligado; com o transporte desligado, nenhuma chamada HTTP real SHALL partir do OneFisc e as execuções SHALL usar fixtures de dry-run; desligar SHALL ter efeito imediato.

#### Scenario: Transporte desligado não chama a SERPRO
- **WHEN** uma execução de consulta é disparada com o transporte desligado
- **THEN** nenhuma requisição sai para a SERPRO, a execução é resolvida com a fixture do dry-run e o estado factual é exibido

#### Scenario: Religar em produção exige confirmação dupla e evidência
- **WHEN** um `super_admin` religa o transporte em produção sem a dupla confirmação e a evidência exigida
- **THEN** o transporte permanece desligado e o Audit registra a recusa

#### Scenario: Desligar é imediato
- **WHEN** um `super_admin` desliga o transporte enquanto há execuções agendadas
- **THEN** nenhuma nova chamada real é feita a partir dessa decisão

### Requirement: Estado de transporte observável

O health da plataforma SHALL distinguir, no mínimo, `gated`, `configured`, `unavailable` e `degraded`, refletindo o gate efetivo e não apenas variáveis de ambiente.

#### Scenario: Gate do painel prevalece sobre ambiente
- **WHEN** o transporte está ligado no painel e a variável de ambiente permanece desligada
- **THEN** o health reporta o estado configurado pelo gate efetivo

#### Scenario: Credencial ausente é observável
- **WHEN** o transporte está aprovado mas a credencial do Contratante não resolve
- **THEN** o health reporta indisponibilidade e nenhuma execução real é concluída com sucesso

### Requirement: Administração restrita à Account A

Somente `super_admin` SHALL gerenciar credenciais, ambiente e transporte do Contratante SERPRO; `admin`, `operator` e `user` SHALL receber 403 ao tentar qualquer alteração.

#### Scenario: Role sem permissão é negado
- **WHEN** um `admin` de outra Account tenta alterar credenciais, ambiente ou transporte
- **THEN** a operação responde 403 e nenhum valor é alterado
