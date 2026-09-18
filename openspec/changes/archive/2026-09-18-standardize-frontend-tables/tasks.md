## 1. Padrão de tabela

- [x] 1.1 Padronizar o chrome client-side em PT-BR das tabelas reais a partir do molde de customers, verificando com `pnpm run lint` e inspeção visual que busca, filtros, ordenação, seleção, visibilidade e paginação se comportam igual em todas as telas reais.
- [x] 1.2 Padronizar os estados de carregamento, vazio e erro em PT-BR com retry nas tabelas reais, verificando com `pnpm run typecheck` e simulação de cada estado que as mensagens aparecem sem dados fictícios.
- [x] 1.3 Conferir que telas fictícias de template ficam fora da migração, verificando por inspeção que nenhum dado fictício aparece nas telas reais.

## 2. Componentes por domínio

- [x] 2.1 Organizar os blocos de tabela por domínio com chrome compartilhado, verificando com `pnpm run lint` que cada página real compõe apenas blocos do próprio domínio.
- [x] 2.2 Padronizar formatação PT-BR e ações por linha com gating por permissão, verificando com inspeção por Role que valores e ações respeitam o domínio e a permissão.

## 3. Composables e verificação integrada

- [x] 3.1 Centralizar busca, filtro, ordenação, seleção, visibilidade, paginação e exportação CSV em composables reutilizáveis, verificando com `pnpm run typecheck` e teste manual que todas as tabelas reais compartilham o mesmo comportamento.
- [x] 3.2 Executar lint, typecheck e build no frontend, verificando que os três comandos concluem sem erros.
