# OneFisc

O OneFisc é uma plataforma SaaS para escritórios contábeis gerenciarem suas carteiras de contribuintes. Este glossário fixa a linguagem canônica do domínio.

## Language

### Tenancy e pessoas

**Account**:
O tenant da plataforma: contém Users, Clients e dados isolados dos demais.
_Evitar_: workspace, organização, empresa cliente

**Account A**:
A primeira Account, pertencente à operadora do OneFisc e sede da administração da plataforma.
_Evitar_: Account Master, tenant global

**Account B**:
Uma Account de escritório contábil contratante, isolada das demais.
_Evitar_: Account de cliente, tenant comum

**Plan**:
A assinatura de uma Account. Define quantos Users e Clients ela comporta, quais Modules libera e seu volume mensal de consultas.
_Evitar_: preço, tier

**Module**:
Uma área de produto da plataforma, liberada ou não conforme o Plan da Account.
_Evitar_: plugin, integração

**User**:
Uma pessoa com acesso à plataforma através de uma única Account. Não confundir com o Role `user`, o mais básico.
_Evitar_: login, contato

**Role**:
O conjunto de permissões de um User dentro da sua Account.
_Evitar_: nível, perfil

**super_admin**:
Um User da Account A com autoridade sobre a plataforma.
_Evitar_: administrador de Account, owner

**admin**:
O Role de um User que administra sua própria Account.
_Evitar_: super_admin

**operator**:
O Role de um User que opera a carteira e executa trabalho, sem administrar a Account.
_Evitar_: gestor, manager

**user**:
O Role mais básico de um User: executa apenas o trabalho que lhe é atribuído. Não confundir com User, a pessoa com acesso.
_Evitar_: agente, convidado

### Carteira

**Client**:
Um CNPJ pertencente à carteira de uma Account. O mesmo CNPJ em Accounts distintas forma Clients distintos.
_Evitar_: empresa agrupadora, contribuinte

**Monitoring Status**:
O estado de acompanhamento de um Client: ativo ou inativo.
_Evitar_: monitoramento executando, cliente sincronizado

### Monitoramento SERPRO

**Contratante SERPRO**:
A credencial da plataforma junto ao SERPRO, com ambiente próprio (homologação ou produção), guardada no cofre e trocada apenas por `super_admin` na Account A.
_Evitar_: conta SERPRO, usuário integra contador

**Certificado Digital**:
O certificado A1 (.pfx/.p12) de uma Account, guardado no cofre com titular, thumbprint e validade, usado para assinar termos e autenticar chamadas em nome dos Clients.
_Evitar_: certificado do escritório, e-CNPJ

**Autor do Pedido de Dados**:
A pessoa vinculada ao Certificado Digital de uma Account que assina o termo de autorização perante o SERPRO; sem certificado ativo ou vencido, fica inelegível e bloqueia execuções.
_Evitar_: signatário, procurador

**Associação de Monitoramento**:
O vínculo entre um Client e uma definição do catálogo de monitoramento, com estado (ativa, pausada, encerrada) e motivo de pausa factual.
_Evitar_: inscrição, assinatura de monitoramento

**Transporte**:
O caminho de tráfego efetivo para o SERPRO: desligado por padrão, ligado pelo painel da Account A com confirmações quando em produção; desligado, nenhuma chamada externa acontece.
_Evitar_: integração ligada, modo de envio

### Entrada

**Onboarding**:
O cadastro inicial que cria a Account A.
_Evitar_: registro, signup, criar conta

**Invite**:
O meio de entrada de um novo User após o Onboarding.
_Evitar_: link mágico, auto-cadastro

### Operação central

**Account Switcher**:
O instrumento do super_admin para atuar em outra Account sem trocar de credenciais.
_Evitar_: impersonation, trocar de User, login como

**Audit**:
O log único e imutável das ações de plataforma e operação.
_Evitar_: activity log, histórico editável

### Monitoramento

**Contratante SERPRO**:
O cadastro do contratante do Integra Contador na Account A: ambiente, documento e referências de cofre. O gate efetivo (painel sobre ambiente) dita se há tráfego real.
_Evitar_: credencial global, chave da API

**Certificado Digital**:
O A1 de cada Account, guardado no cofre com titular, thumbprint e validade. Assina termos e autentica o mTLS; expirado, bloqueia execuções de forma fail-closed.
_Evitar_: certificado do escritório em disco, PFX em ENV

**Autor do Pedido de Dados**:
A pessoa vinculada ao Certificado Digital que assina o termo de autorização e figura nas consultas em nome dos Clients. Sem autor elegível, não há execução.
_Evitar_: procurador solto, signatário genérico

**Associação de Monitoramento**:
A escolha confirmada de monitorar uma definição do catálogo para um Client: elegibilidade, ciclo de vida (ativa, pausada, encerrada) e versionamento para fencing.
_Evitar_: assinatura de monitoramento, vínculo fiscal
