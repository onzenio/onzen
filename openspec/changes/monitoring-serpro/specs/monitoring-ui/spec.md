## Purpose

Expõe o monitoramento SERPRO nas telas do OneFisc em pt-BR, compostas exclusivamente com os componentes já existentes do template, com estados factuais e sem prometer cobertura.

## ADDED Requirements

### Requirement: Reuso dos componentes do template

As telas de monitoramento SHALL ser compostas exclusivamente com os componentes já disponíveis no template (tabela, badges, botões, menus, modais, slideover, cards, inputs, selects, alertas e ícones), seguindo os padrões visuais existentes, sem introduzir design system ou componente de UI novo.

#### Scenario: Tela de monitoramento usa padrão existente
- **WHEN** uma tela de monitoramento é renderizada
- **THEN** ela reutiliza os componentes do template e mantém consistência visual com as telas existentes

#### Scenario: Nenhum componente novo de UI
- **WHEN** a equipe revisa a implementação das telas
- **THEN** não há componente de design system novo, apenas composição dos existentes

### Requirement: Navegação e visibilidade por Role

A navegação de monitoramento SHALL respeitar Role e Module liberado: `admin` e `operator` operam consultas e associações, `user` consulta o que lhe é atribuído, `super_admin` administra as credenciais do Contratante na área central.

#### Scenario: Menu filtrado por Role
- **WHEN** um `user` autentica
- **THEN** as ações de disparo e emissão não aparecem e o acesso direto às rotas correspondentes é negado

#### Scenario: Área central restrita
- **WHEN** um `admin` de Account B acessa a administração SERPRO
- **THEN** a área não é exibida e a rota responde 403

### Requirement: Lista de módulos e associações

A tela principal de monitoramento SHALL listar módulos/definições com disponibilidade factual, associações do Client, estado, motivo de pausa e última mudança, com busca local e paginação.

#### Scenario: Módulos com indisponibilidade factual
- **WHEN** o usuário abre a tela de monitoramento
- **THEN** módulos sem operação executável aparecem como indisponíveis, com motivo, sem ação de consulta

#### Scenario: Busca na carteira
- **WHEN** o usuário busca por nome ou CNPJ
- **THEN** a lista é filtrada para os Clients visíveis da Account e o estado de carregamento/vazio/erro é exibido

#### Scenario: Disparo manual pela tela
- **WHEN** o usuário elegível dispara uma consulta
- **THEN** a tela confirma o aceite, atualiza o estado da associação e reflete o progresso sem bloquear a navegação

### Requirement: Painel do Client

O painel do Client SHALL exibir associações, snapshot vigente, mudanças e alertas, sem disparar consulta ao abrir a tela; a seção de CND SHALL ler o snapshot de Situação Fiscal.

#### Scenario: Abrir painel não consulta
- **WHEN** o usuário abre o painel de um Client
- **THEN** os dados exibidos vêm do acervo local, sem chamada externa

#### Scenario: Alertas e reconhecimento
- **WHEN** o painel mostra alertas pendentes
- **THEN** o usuário elegível pode reconhecê-los pela interface e o estado é refletido na hora

### Requirement: Tela de parcelamentos

A tela de parcelamentos SHALL listar modalidades, pedidos e parcelas, permitir abrir o detalhe normalizado e baixar guias existentes.

#### Scenario: Detalhe e download
- **WHEN** o usuário abre um parcelamento da carteira
- **THEN** parcelas e pagamentos são exibidos e a guia existente fica baixável

#### Scenario: Guia ausente informa estado
- **WHEN** não há guia gerada para a parcela
- **THEN** a tela informa ausência factual, sem oferecer emissão automática

### Requirement: Administração SERPRO da Account A

A administração SERPRO SHALL exibir credenciais mascaradas, ambiente vigente com selo, estado do transporte e o Certificado Digital com validade, permitindo as ações de troca com as confirmações exigidas.

#### Scenario: Estado factual no painel
- **WHEN** um `super_admin` abre a administração SERPRO
- **THEN** ambiente, transporte e credenciais mascaradas são exibidos com o estado efetivo

#### Scenario: Troca com confirmação
- **WHEN** o `super_admin` altera ambiente ou transporte
- **THEN** a interface exige as confirmações necessárias e exibe o novo estado após a aplicação

### Requirement: Quota e upgrade visíveis

A interface SHALL exibir o consumo de consultas do Plan e SHALL apresentar mensagem acionável de upgrade quando o volume estiver esgotado.

#### Scenario: Consumo visível
- **WHEN** o usuário abre a tela de monitoramento ou dispara uma consulta
- **THEN** o consumo e o limite do Plan são exibidos de forma compreensível

#### Scenario: Volume esgotado orienta upgrade
- **WHEN** o volume mensal está esgotado e o usuário tenta consultar
- **THEN** a interface explica o bloqueio e orienta a troca de Plan, sem falhar silenciosamente

### Requirement: Erros acionáveis e sem vazamento

Estados de erro, bloqueio e validação SHALL ser apresentados com mensagem compreensível em pt-BR, sem segredo, token, PFX ou conteúdo fiscal.

#### Scenario: Bloqueio explicado
- **WHEN** uma consulta é recusada por transporte desligado, quota ou outorga pendente
- **THEN** a tela mostra o motivo factual e o próximo passo, sem detalhe sensível

#### Scenario: Validação sem vazamento
- **WHEN** um formulário de certificado ou credencial falha na validação
- **THEN** a mensagem orienta a correção sem ecoar o valor sensível
