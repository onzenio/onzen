## Why

Fluxos críticos já especificados falham nas fronteiras entre browser, BFF, backend e workers: a sessão não chega a rotas específicas, Modules podem ser acessados fora do Plan, trabalho assíncrono pode ficar preso e rotinas automáticas podem nunca executar. Essas lacunas também afetam downloads privados e permitem uma corrida no Onboarding, por isso precisam ser fechadas antes de operar a plataforma com múltiplas Accounts.

## What Changes

- Fazer toda chamada do browser ao backend passar pela sessão intermediada pelo BFF, incluindo consulta do User atual, mutações com CSRF e downloads binários assinados.
- Aplicar no backend a liberação de Modules pelo Plan vigente, independentemente das restrições visuais do frontend.
- Recuperar execuções e ações SERPRO interrompidas após perda do worker, sem duplicar tráfego externo.
- Executar o scheduler no ambiente Compose e manter somente os comandos automáticos compatíveis com o modelo atual.
- Tornar o Onboarding atômico para que somente uma Account A e um User `super_admin` possam ser criados sob concorrência.
- Remover caminhos e comandos legados substituídos pelos fluxos atuais, sem alterar os contratos públicos válidos.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `authentication`: garantir que todas as rotas BFF autenticadas restaurem a sessão do backend e preservem a proteção CSRF.
- `plans`: garantir que o backend negue acesso direto a um Module ausente do Plan vigente.
- `monitoring-execution`: garantir recuperação segura após interrupção de worker e execução efetiva e única das rotinas automáticas suportadas.
- `monitoring-artifacts`: entregar downloads privados por meio do BFF sem perder autorização, expiração ou auditoria.
- `onboarding-invites`: garantir unicidade da Account A e do primeiro `super_admin` sob requisições concorrentes.

## Impact

- Frontend Nuxt: utilitários e server routes do BFF, tratamento de sessão/CSRF e proxy de downloads.
- Backend Laravel: autorização por Module, lifecycle de execuções e ações SERPRO, Onboarding e testes de concorrência/recuperação.
- Operação: serviços e variáveis do Compose, scheduler Laravel e remoção de schedules legados.
- APIs: nenhuma mudança intencional nos payloads REST; respostas antes incorretamente 401/403/503 passam a seguir os contratos existentes.

## Out-of-Scope

- Criar novos Plans, Modules, Roles ou operações SERPRO.
- Redesenhar telas ou componentes do frontend.
- Trocar Sanctum, o formato da sessão BFF, Redis, NATS ou o driver de filas.
- Corrigir advisories transitivos de dependências sem caminho explorável confirmado; isso exige avaliação e mudança próprias.
- Alterar frequências de negócio do ciclo mensal ou da renovação diária além de remover agendamentos legados duplicados.
