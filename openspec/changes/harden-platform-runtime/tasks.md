## 1. Sessão e downloads pelo BFF

- [ ] 1.1 Unificar o transporte das server routes autenticadas para restaurar sessão e CSRF, verificando com testes automatizados que consulta do User e mutação específica chegam autenticadas ao backend.
- [ ] 1.2 Entregar artefatos por link assinado na origem do frontend, verificando com testes automatizados download válido, assinatura alterada, expiração e isolamento entre Accounts.
- [ ] 1.3 Consolidar a configuração da origem do backend no frontend e no Compose, verificando com configuração renderizada que o container recebe o valor esperado.

## 2. Entitlement de Modules

- [ ] 2.1 Aplicar no backend o entitlement de `clients` às operações de Clients, verificando com testes de API que Plan sem o Module é negado antes de ler ou alterar dados.
- [ ] 2.2 Aplicar no backend os Modules equivalentes de monitoramento às operações correspondentes, verificando com testes de API os casos liberado, bloqueado e chamada direta.
- [ ] 2.3 Cobrir o agrupamento completo das rotas protegidas, verificando com teste de arquitetura que novos endpoints de Clients e monitoramento não escapam do entitlement.

## 3. Recuperação de workers

- [ ] 3.1 Recuperar execuções SERPRO com reserva válida, expirada e protocolo persistido, verificando com testes de fila que não há envio concorrente nem repetição da solicitação original de resultado desconhecido.
- [ ] 3.2 Recuperar ações SERPRO com reserva válida, expirada e protocolo persistido, verificando com testes de fila que apenas polling seguro pode prosseguir após interrupção.
- [ ] 3.3 Tornar a janela de processamento coerente com timeout e redelivery, verificando com teste de configuração que uma execução ativa não é classificada como abandonada prematuramente.

## 4. Scheduler operacional

- [ ] 4.1 Remover os agendamentos legados e manter somente as rotinas atuais sem sobreposição, verificando com teste de console e `php artisan schedule:list` que existem apenas o ciclo mensal e a renovação diária suportados.
- [ ] 4.2 Executar continuamente o scheduler no ambiente Compose, verificando com `docker compose config` e o healthcheck que o processo dedicado usa a configuração do backend.

## 5. Onboarding atômico

- [ ] 5.1 Garantir no banco no máximo uma Account A sem impedir múltiplas Accounts B, verificando com testes de migração os casos íntegro, concorrente e base previamente inconsistente.
- [ ] 5.2 Tratar duas conclusões concorrentes do Onboarding como uma criação e um conflito, verificando com teste de integração que nenhum Account ou User adicional é persistido ou autenticado.

## 6. Verificação integrada

- [ ] 6.1 Executar toda a suíte PHPUnit e o Pint manual no backend, verificando que testes e estilo passam sem regressões.
- [ ] 6.2 Executar lint, typecheck e build no frontend, verificando que os três comandos concluem sem erros.
- [ ] 6.3 Validar sessão, entitlement, recuperação, scheduler e Onboarding contra os delta specs, verificando que todos os cenários possuem evidência automatizada ou operacional registrada.
