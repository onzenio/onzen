## Why

A navegação atual mistura ações da operação central com itens operacionais e a gestão de Accounts ainda conserva copy e apresentação inconsistentes com o produto. O refinamento torna a área administrativa compreensível por Role e melhora a gestão de Accounts sem ampliar suas capacidades funcionais.

## What Changes

- Agrupar a gestão de Accounts, de Plans e de Audit em um item colapsável "Administração" no sidebar, preservando somente os filhos permitidos para cada Role.
- Apresentar "Escritórios" como rótulo de interface para a gestão de Accounts, mantendo a terminologia Account nos contratos e no domínio.
- Restringir as rotas frontend de gestão de Accounts e Plans ao super_admin, mantendo a autorização Laravel como fronteira de segurança.
- Refinar a listagem e a criação de Accounts com os componentes e padrões responsivos já disponíveis no template Nuxt UI.
- Exibir Accounts A e B na listagem, distinguindo a central OneFisc dos escritórios e preservando nome, perfil e Plan vigente.
- Cobrir estados de carregamento, vazio, erro, validação e envio da criação de uma Account B.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `accounts`: detalhar a experiência observável de listagem e criação de Accounts na interface administrativa.
- `roles`: detalhar o agrupamento e a visibilidade da navegação administrativa por Role e o bloqueio das rotas frontend de plataforma.

## Impact

- Frontend Nuxt: shell do dashboard, página de Accounts, página de Plans e middleware de rota por Role.
- APIs Laravel e modelos de dados permanecem inalterados; os endpoints e Policies existentes continuam sendo usados.
- Não há novas dependências; a interface compõe exclusivamente componentes existentes do Nuxt UI e padrões do template.

## Out-of-Scope

- Editar, desativar ou excluir Accounts.
- Adicionar busca ou filtros sem suporte completo do backend.
- Alterar as URLs `/accounts`, `/plans` ou `/audit`.
- Refinar outras páginas do frontend ou remover todos os placeholders herdados do template.
- Criar componentes visuais, estilos globais ou bibliotecas de UI novos.
- Restaurar ou versionar a referência ausente `docs/research/dashboard`.
