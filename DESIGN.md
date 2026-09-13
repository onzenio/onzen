---
name: OneFisc
description: Central operacional fiscal para escritórios contábeis.
colors:
  primary-soft: "#D9FBE8"
  primary: "#00C16A"
  primary-hover: "#00A155"
  primary-deep: "#007F45"
typography:
  title:
    fontFamily: "Public Sans, sans-serif"
    fontWeight: 600
  body:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: "1.25rem"
  label:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 400
    lineHeight: "1rem"
rounded:
  md: "0.375rem"
  lg: "0.5rem"
  full: "9999px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "#FFFFFF"
  badge-status:
    rounded: "{rounded.full}"
  table-header:
    rounded: "{rounded.lg}"
---

# Design System: OneFisc

## Overview

**Creative North Star: "Central Operacional"**

O OneFisc deve parecer um ambiente de trabalho contínuo e confiável, não uma coleção de dashboards promocionais. A composição é precisa e discreta: hierarquia por shell, alinhamento, densidade moderada e estados semânticos; o conteúdo fiscal recebe prioridade sobre decoração.

Este documento foi extraído do código atual de `frontend/` (valores verificados em `app/assets/css/main.css` e `app/app.config.ts`) e herda as regras do mundo `_legacy` travado no shape. O frontmatter carrega somente o que existe de fato no repo: a UI atual não define hexes neutros, escala de spacing, sombras nem biblioteca compartilhada — tudo isso chega com o porte de `shared/` do `_legacy`, quando este documento deve ser regenerado. Copy, métricas e personas do template ( inglês, USD/EUR, times Nuxt) são ficção e não fazem parte do sistema.

**Key Characteristics:**

- Shell operacional consistente e responsivo.
- Verde reservado para ação, foco e estado positivo.
- Neutros zinc via semântica do Nuxt UI, em claro e escuro.
- Densidade moderada, adequada a listas, formulários e acompanhamento diário.
- Movimento curto, funcional e sempre redutível.

## Colors

Um verde nítido e econômico sobre neutros zinc; tokens semânticos do Nuxt UI adaptam texto, fundo e borda aos temas.

### Primary

- **Verde Operacional**: ação primária, foco e sinal positivo; hover e ênfase profunda descem a rampa.
- **Verde de Contexto**: apoio tonal para estados e destaques suaves, nunca uma grande massa decorativa.

### Neutral

- **Zinc Semântico**: base, superfícies, texto e bordas resolvidos por classes do Nuxt UI (`bg-elevated`, `text-muted`, `border-default` e afins). O repo não define hexes neutros próprios; não inventar valores fora do frontmatter.

**The One-Green Rule.** Verde é a única voz cromática da marca nas superfícies operacionais; aviso e erro usam as cores semânticas do Nuxt UI, nunca novos acentos decorativos.

## Typography

**Display Font:** Public Sans (com fallback `sans-serif`)
**Body Font:** Public Sans (com fallback `sans-serif`)

**Character:** Public Sans mantém números, identificadores, filtros e textos operacionais claros. A hierarquia nasce de tamanho, peso e contraste, sem combinar famílias concorrentes.

### Hierarchy

- **Title** (semibold): títulos de página e valores de destaque; o tamanho varia por contexto (`text-2xl`–`text-3xl` observados), sem medida única fixada.
- **Body** (`text-sm`): conteúdo, células e descrição operacional.
- **Label** (`text-xs`, geralmente em caixa alta): metadados, eyebrows de cartão e títulos de estatística.

**The Single-Family Rule.** Public Sans em toda a aplicação; peso, tamanho e tom resolvem a hierarquia sem fonte de destaque.

## Layout

O shell canônico é `UDashboardGroup` → `UDashboardSidebar` colapsável e redimensionável + `UDashboardSearch` → `UDashboardPanel` → `UDashboardNavbar` com `#leading` de colapso, `UDashboardToolbar` opcional e corpo. Páginas seguem o padrão painel → navbar → toolbar → corpo.

O ritmo usa a escala numérica do Tailwind conforme observado (`gap-2`/`3`/`4`/`6`, `p-4`, `space-y-4`); busca fica à esquerda e ações à direita. Listas ocupam a área disponível e rolam internamente. Formulários usam colunas contidas e seções em cartão, sem esticar campos curtos pela viewport. Em telas estreitas, a sidebar recolhe e detalhes viram slideover (padrão inbox), sem overflow horizontal inesperado.

**The Legacy-First Rule.** Antes de criar um padrão, portar o equivalente de `shared/` do `_legacy`; referências externas conferem API, mas não substituem decisões locais consolidadas.

## Elevation & Depth

O sistema é plano: não existe nenhuma sombra em `frontend/app` (verificado). Profundidade vem de `bg-elevated`, bordas semânticas, rings inset e contraste tonal — inclusive em overlays, que hoje se sustentam sem sombra própria.

**The Flat-by-Default Rule.** Painéis, cartões e overlays não flutuam; borda e tom resolvem a separação.

## Shapes

Raios utilitários coerentes: pill para badges, avatares, dots e FABs; `lg` para cartões e para o início/fim do cabeçalho de tabelas; `md` para linhas e controles compactos. Bordas de `1px` e rings semânticos dão definição sem peso. Tabelas arredondam o início e o fim do cabeçalho (`first:rounded-l-lg last:rounded-r-lg`), nunca cada célula.

## Components

Padrões observados no código atual. Os padrões novos da fundação (banner "Atuando como", convites, auditoria) ainda não existem em código: seguem estas gramáticas e ganham tokens na regeneração pós-porte.

### Buttons

- **Shape:** retângulo compacto; raio e alvo seguem o default do Nuxt UI.
- **Primary:** verde operacional com texto branco; a ação principal inequívoca da região (ex.: criar, enviar).
- **Secondary / Ghost:** `neutral subtle` para cancelar e secundárias, `ghost` para ações de ícone e gatilhos; destrutivas usam `error solid` com confirmação em modal.

### Chips and Status

- Badges compactos, arredondados (`UBadge subtle`, texto capitalizado) e semânticos por estado; dots de não-lido via `UChip`, com `error inset` sobre sino e avatares.
- Não usar um badge de cor como única explicação de estado: o texto permanece legível e verdadeiro.

### Cards / Containers

- `UPageCard`/`UCard` como base de seções, formulários e estatísticas; variante `subtle` em listas de cartões clicáveis.
- Cartões de estatística: eyebrow pequena em caixa alta e atenuada, valor em destaque semibold, variação em badge; não viram blocos analíticos quando o trabalho precisa aparecer na primeira dobra.

### Inputs / Fields

- Controles sobre fundo semântico com ring e raio do Nuxt UI; busca de listas no início do toolbar com ícone; erro e disabled seguem a semântica do Nuxt UI com validação Zod próxima ao campo.

### Tables and Lists

- Cabeçalho sobre `bg-elevated/50` com pills nas extremidades, células compactas com borda inferior, seleção por checkbox com contagem e paginação no rodapé sobre borda superior.
- Listas master-detail marcam seleção com borda lateral primária e fundo tonal; não-lido pesa a tipografia, nunca só a cor.

### Navigation

- Sidebar conserva a hierarquia do dashboard: grupos primários, triggers com filhos e secundários ao fim; item ativo usa tom e contraste, hover não desloca a composição.
- Busca global espelha a mesma árvore de navegação; no mobile, o shell recolhe a navegação e preserva rótulos e rota atual.

### Overlays

- `UModal` para tarefas focadas (formulário com título, descrição, ações alinhadas à direita e destrutiva confirmada); `USlideover` para detalhes contextuais e painéis mobile; `UPopover modal` para seletores ricos como calendário.
- O corpo do overlay rola; título e ações críticas permanecem estáveis; durante operação pendente, dismissal inseguro é bloqueado.

## Do's and Don'ts

### Do:

- **Do** começar pelo porte de `shared/` do `_legacy` ou por uma tela existente do mesmo domínio.
- **Do** usar tokens semânticos do Nuxt UI para preservar claro/escuro e estados.
- **Do** manter busca à esquerda, ações à direita e feedback junto da ação.
- **Do** escrever toda copy nova em PT-BR; o inglês atual é ficção de template.
- **Do** validar foco, teclado, redução de movimento e responsividade antes de concluir uma superfície.

### Don't:

- **Don't** copiar fixtures, personas, métricas, links demo ou testemunhos do template para o produto.
- **Don't** introduzir outro shell, paleta, escala ou família tipográfica quando existir equivalente neste documento.
- **Don't** usar cartões, sombras ou acentos decorativos para preencher espaço sem função operacional.
- **Don't** prometer sincronização ou execução fiscal na UI; expor apenas estado.
- **Don't** esconder erro, autorização, estado pendente ou consequência fiscal atrás de feedback genérico.
