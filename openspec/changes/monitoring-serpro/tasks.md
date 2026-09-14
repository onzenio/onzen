## 1. Fundação: dados, cofre e catálogo

- [x] 1.1 Criar a estrutura de dados das credenciais do Contratante SERPRO (environment, refs de cofre, auditoria de troca) e verificar com teste de persistência, mascaramento e troca registrada no Audit.
- [x] 1.2 Criar a estrutura de dados do Certificado Digital por Account (ref de cofre, titular, thumbprint, validade) e verificar com teste de cadastro, substituição e isolamento entre Accounts.
- [x] 1.3 Criar a estrutura de dados do Autor do Pedido de Dados vinculado ao Certificado Digital e verificar com teste de vínculo, inelegibilidade por certificado expirado e permissões por Role.
- [x] 1.4 Criar a estrutura de dados das definições do catálogo e o seed versionado com definições, operações e allowlist de procurações e verificar com teste que cobre definições disponíveis, indisponíveis e em prospecção.
- [x] 1.5 Implementar o cofre local com referências opacas `secret:` e escopo por Account e verificar com teste de cifra, leitura, substituição e ausência de segredo em serialização e log.
- [x] 1.6 Adicionar `config/monitoring.php` e as variáveis `MONITORING_SERPRO_*` ao `backend/.env.example` com defaults de homologação e dry-run e verificar com teste de defaults e overrides.
- [x] 1.7 Versionar as fixtures oficiais por operação em `backend/resources/fixtures/serpro/consultar/` e o provedor de fixtures e verificar com teste de carga por operação e de fixture ausente falhando de forma explícita.

## 2. Transporte e gate

- [x] 2.1 Portar a montagem do envelope de 3 partes e o roteamento por operação e verificar com teste de campos, tipos de pessoa PF/PJ e caminho por tipo de operação.
- [x] 2.2 Implementar a resolução de credencial e token com cache, escopo de Account e limpeza em 401/403 e verificar com teste de resolução, expiração e renovação.
- [x] 2.3 Implementar o transporte OAuth + mTLS com arquivo temporário de certificado de permissão restrita e remoção garantida e verificar com teste com HTTP fake cobrindo headers, mTLS e remoção do arquivo em sucesso e falha.
- [x] 2.4 Implementar o gate efetivo (painel sobre ambiente) e o health com estados `gated`, `configured`, `unavailable` e `degraded` e verificar com teste dos quatro estados.
- [x] 2.5 Implementar as rotas de administração SERPRO com credenciais mascaradas, alternância de ambiente e interruptor de transporte com confirmações e verificar com teste de permissões, dupla confirmação e desligamento imediato.

## 3. Execução e quota

- [x] 3.1 Criar a estrutura de dados de execuções e tentativas com estados, idempotência e fencing e verificar com teste de transições, repetição sem duplicar e descarte de execução superada.
- [x] 3.2 Implementar os jobs de consulta e ação, a conexão de fila `serpro` e o worker no `docker-compose.yml` e verificar com teste de enfileiramento, tentativas e backoff.
- [x] 3.3 Implementar a classificação de resposta com retry, backoff, polling de protocolo e rejeição definitiva e verificar com teste de 429, timeout, protocolo pendente e rejeição.
- [ ] 3.4 Implementar a quota agregada do Plan com reserva atômica antes do tráfego e mensagem de upgrade e verificar com teste de consumo, esgotamento sem débito e corrida concorrente sem ultrapassar o teto.
- [ ] 3.5 Implementar o scheduler de disparo manual e do ciclo automático mensal somente de consulta e verificar com teste de trigger, origem automática, definições automáticas e negação de Role.
- [ ] 3.6 Implementar eventos operacionais de início e fim com redaction e verificar com teste de payload sem segredo, token, PFX ou conteúdo fiscal.

## 4. Procurações e autores

- [ ] 4.1 Implementar o termo de autorização assinado e enviado com token guardado no cofre e renovação antes do vencimento e verificar com teste com transporte fake de envio, guarda e renovação.
- [ ] 4.2 Implementar a verificação de procuração por Client com cache curto e allowlist de códigos e verificar com teste de confirmação, ausência de outorga, cache e código fora da allowlist.
- [ ] 4.3 Implementar pausa e retomada de associações por outorga e a listagem de divergências e verificar com teste de pausa com motivo, retomada e 404 cross-account.
- [ ] 4.4 Implementar a rotina diária de renovação de termos e reverificação de procurações e verificar com teste do comando agendado renovando e retomando associações elegíveis.

## 5. Associações e resultados

- [ ] 5.1 Implementar criação, lifecycle e busca de Associação de Monitoramento com elegibilidade, Monitoring Status e limite por Plan e verificar com teste de criação, recusas, unicidade, pausa e busca por nome/CNPJ.
- [ ] 5.2 Portar os normalizadores por família com fallback neutro e verificar com teste por família usando as fixtures oficiais, incluindo família sem normalizador sinalizada.
- [ ] 5.3 Implementar snapshots com fingerprint, detecção de mudanças e alertas com acknowledge idempotente e verificar com teste de repetição sem versionar, mudança com alerta e reconhecimento repetido.
- [ ] 5.4 Implementar a cadeia consultiva PGDAS-D (índice→declaração/recibo→extrato) com idempotência e verificar com teste de encadeamento quando o índice muda e de não encadeamento sem novidade.
- [ ] 5.5 Implementar as leituras da carteira (dashboard, snapshots, changes, alerts) isoladas por Account e a CND lida do snapshot sem consulta ao abrir e verificar com teste de listagem, 404 cross-account e ausência de chamada externa.

## 6. Parcelamentos e ações

- [ ] 6.1 Criar a estrutura de dados de parcelamentos e a consulta normalizada das oito modalidades e verificar com teste por modalidade suportada, indisponível e isolamento por Account.
- [ ] 6.2 Implementar o detalhe normalizado de pedidos, parcelas e pagamentos e o download de guia já gerada sem reemissão e verificar com teste de detalhe, download e guia ausente sem emissão.
- [ ] 6.3 Implementar a emissão de DAS de PGDAS-D e de parcelamentos com confirmação, idempotency key, pré-condições fail-closed e poll de protocolo e verificar com teste de emissão confirmada, repetição idêntica, recusa sem transporte e recusa de Role.
- [ ] 6.4 Implementar o Audit das ações e o escopo de carteira e verificar com teste de evento registrado, redaction e 404 fora da carteira.

## 7. Artefatos

- [ ] 7.1 Implementar o armazenamento privado de artefatos com referência opaca e hash SHA-256 e verificar com teste de gravação, leitura e ausência de caminho físico na API.
- [ ] 7.2 Implementar a decodificação de conteúdo no processamento e o estado de falha de artefato e verificar com teste de artefato gerado e de falha que não quebra a execução.
- [ ] 7.3 Implementar o download autorizado e auditado com URL temporária e verificar com teste de sucesso, 403 expirado, 404 cross-account e 503 com storage indisponível.

## 8. UI com o template

- [ ] 8.1 Implementar a tela de monitoramento com módulos, associações e busca usando os componentes do template e verificar com `pnpm typecheck` e smoke manual de lista, indisponibilidade e disparo.
- [ ] 8.2 Implementar o painel do Client com snapshots, mudanças, alertas e CND sem consulta ao abrir e verificar com smoke manual de leitura e reconhecimento de alerta.
- [ ] 8.3 Implementar a tela de parcelamentos com detalhe e download de guia e verificar com smoke manual de listagem, detalhe e guia ausente.
- [ ] 8.4 Implementar a administração SERPRO da Account A e a gestão de Certificado Digital e autores e verificar com smoke manual de mascaramento, confirmações e validade.
- [ ] 8.5 Implementar a exibição de quota e as mensagens de bloqueio/upgrade e verificar com smoke manual de saldo, esgotamento e erro acionável.
- [ ] 8.6 Implementar a navegação de monitoramento filtrada por Role e Module e verificar com smoke manual por papel e `pnpm typecheck`.

## 9. Verificação final

- [ ] 9.1 Rodar `composer test` no backend e anexar a saída como evidência, corrigindo falhas de PHPUnit e Pint.
- [ ] 9.2 Rodar `pnpm lint` e `pnpm typecheck` no frontend e anexar a saída como evidência.
- [ ] 9.3 Executar o smoke E2E em dry-run (Client associado, consulta disparada, snapshot e alerta visíveis, quota bloqueando, transporte desligado sem chamada real) e registrar o passo a passo no change.
- [ ] 9.4 Atualizar `CONTEXT.md` com os termos novos e o `README`/`.env.example` com a operação da fila e do gate e verificar por revisão do diff.
