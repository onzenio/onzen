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
