## 1. Navegação administrativa

- [x] 1.1 Agrupar a gestão de Accounts, Plans e Audit sob o grupo colapsável Administração e verificar a visibilidade correta por Role no sidebar expandido
- [x] 1.2 Preservar somente o Audit para admin e ocultar todo o grupo de operator e user e verificar a ausência dos itens proibidos e do Account Switcher
- [x] 1.3 Manter a abertura e navegação do grupo Administração no sidebar recolhido e no mobile e verificar o acesso aos mesmos filhos permitidos por Role

## 2. Proteção das rotas de plataforma

- [x] 2.1 Redirecionar admin, operator e user de `/accounts` e `/plans` para `/` e verificar que a área restrita não é apresentada no acesso direto
- [x] 2.2 Permitir o acesso direto do super_admin a `/accounts` e `/plans` e verificar a renderização das áreas solicitadas

## 3. Gestão de Accounts

- [x] 3.1 Refinar a listagem com nome, tipo e Plan vigente distinguindo a central OneFisc dos escritórios e verificar a apresentação de Accounts A e B
- [x] 3.2 Comunicar carregamento, ausência de resultados e falha com nova tentativa e verificar cada estado sem exibir dados fictícios
- [x] 3.3 Refinar a criação com aviso de Account B e Invite inicial e verificar o bloqueio de submissão duplicada e os erros junto aos campos
- [x] 3.4 Executar lint, typecheck e build do frontend e verificar a matriz manual dos quatro Roles em desktop expandido, recolhido e mobile
