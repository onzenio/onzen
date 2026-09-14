# Retrospectiva — feat/monitoring-serpro

## O que foi entregue

Monitoramento SERPRO fim a fim: outorgas/procurações com cache de 24h,
termo de autorização assinado com token no cofre, associação com lifecycle
e version fencing, máquina de estados de execução idempotente, transporte
OAuth mTLS com gate efetivo, normalizadores por família, snapshots
idempotentes com mudanças/alertas, parcelamentos (8 modalidades), artefatos
privados com download auditado, emissão de DAS idempotente com poll de
protocolo, UI completa (dashboard, painel do Client, parcelamentos, admin
SERPRO, certificado, autores) com navegação filtrada por Role.

63 commits à frente do fork point; 44/44 tasks verificadas; suíte verde
(659 testes backend, lint + typecheck frontend).

## O que funcionou

- TDD por lote de tasks com `tasks.md` como contrato de escopo.
- Smoke E2E dry-run como evidência factual antes de declarar pronto.
- Archive OpenSpec com sync de specs executado ainda na branch.

## Dificuldades e aprendizados

- Fork point antigo (`2ac4f21`): `main` avançou 96 commits e a branch 63
  em linhagens divergentes sem ancestralidade mútua — o merge `--no-ff`
  exige atenção a conflitos e a arquivos duplicados.
- `main` acumulou dirty files não commitados (archive em andamento de
  `refine-office-management-ui` + deleções espúrias de `AGENTS.md`,
  `openspec/config.yaml` e `brainstorm.md`): resolver antes do merge —
  restaurar o espúrio, preservar os edits legítimos de `accounts`/`roles`.
- Gotcha estrutural: deleções em worktree alheia nunca recebem `--force`;
  existência exclusiva no worktree = perguntar antes de destruir.

## Pendências fora do escopo

- `multi-account-foundation` e `wzap-foundation` seguem ativas e não fazem
  parte deste merge.
- Fluxo autenticado navegador→BFF depende de Sanctum stateful no grupo
  `api` (registrado em `verification.md` §9.3).
