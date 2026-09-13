## 1. Estrutura e configuração

- [ ] 1.1 Criar o workspace `wzap/` com módulo Go, layout de pacotes e versão pinada, verificando `go build ./...`
- [ ] 1.2 Implementar carregamento de configuração por ambiente com falha explícita em variável obrigatória, verificando teste do parser para caso válido e ausente
- [ ] 1.3 Adicionar `.dockerignore` e configuração de lint, verificando `golangci-lint run` sem erros
- [ ] 1.4 Registrar `THIRD_PARTY_NOTICES.md` com licença MIT e origem dos trechos reaproveitados, verificando o arquivo presente e citando o apime

## 2. Persistência

- [ ] 2.1 Criar as migrations iniciais (`instances`, `message_queue`, `idempotency_keys`, `contacts`, `media`, `event_outbox`), verificando aplicação em banco limpo
- [ ] 2.2 Implementar repositórios de instância e de mensagem, verificando testes de integração contra Postgres
- [ ] 2.3 Implementar repositório de idempotência com aquisição atômica e expiração, verificando testes de corrida, replay e expiração
- [ ] 2.4 Implementar cache de JID e repositório do outbox de eventos, verificando testes de expiração e de pendências

## 3. Runtime e operações

- [ ] 3.1 Subir servidor HTTP com envelope de resposta, identificador de requisição e log estruturado, verificando testes de handler
- [ ] 3.2 Implementar middleware de autenticação por token de serviço, verificando cenários de `401` e de requisição processada
- [ ] 3.3 Implementar `/healthz` e `/readyz` cobrindo banco, broker e migrações, verificando respostas saudável e indisponível
- [ ] 3.4 Implementar encerramento gracioso e subcomando de healthcheck, verificando execução local do binário
- [ ] 3.5 Empacotar imagem não-root sem shell e adicionar o serviço `wzap` ao compose com volume e banco próprio, verificando `docker compose up` e `/readyz`
- [ ] 3.6 Criar workflow de CI restrito a `wzap/**` com lint, testes e build, verificando execução verde no GitHub Actions

## 4. Sessões e instâncias

- [ ] 4.1 Definir a interface de sessão e um fake de teste, verificando uso do fake nos testes de serviço
- [ ] 4.2 Implementar o gerenciador de sessão sobre o motor WhatsApp com persistência no Postgres, verificando sobrevivência a reinício do processo
- [ ] 4.3 Implementar criação, listagem, detalhe e atualização de instância com referência externa única, verificando cenários `201` e `409`
- [ ] 4.4 Implementar início de pareamento com QR e validade, verificando contrato com fake de sessão
- [ ] 4.5 Implementar reemissão de QR expirado e conclusão do pareamento com registro do identificador público, verificando evento de conexão publicado
- [ ] 4.6 Implementar reconexão com espera crescente, desconexão definitiva e estado de erro para restrição, verificando testes de transição
- [ ] 4.7 Implementar restauração das sessões no boot com concorrência limitada, verificando estado `connected` sem novo pareamento
- [ ] 4.8 Implementar remoção de instância com limpeza de sessão, mensagens e mídias, verificando `404` nas operações seguintes

## 5. Mensageria de saída

- [ ] 5.1 Implementar normalização de destinatário com a regra do 9º dígito e cache, verificando casos brasileiros e número inexistente
- [ ] 5.2 Implementar idempotência de envio com replay, `409` e `422`, verificando cenários da semântica
- [ ] 5.3 Implementar aceite de envio com validação de instância conectada, verificando respostas `202` e `409`
- [ ] 5.4 Implementar workers do outbox com processamento concorrente, verificando teste com múltiplas mensagens enfileiradas
- [ ] 5.5 Implementar envio de texto, localização e contato, verificando testes com fake de sessão
- [ ] 5.6 Implementar upload e envio de imagem, vídeo, áudio/PTT e documento, verificando validação de tipo e tamanho
- [ ] 5.7 Implementar retries com espera crescente, falha definitiva e retomada de mensagens presas, verificando testes de estado
- [ ] 5.8 Implementar consulta de mensagem com estado, marcos temporais e último erro, verificando cenário de consulta
- [ ] 5.9 Implementar atualização por recibos de entrega e leitura, verificando cenário de mensagem lida
- [ ] 5.10 Implementar humanização opcional com presença simulada, verificando testes da lógica de atraso

## 6. Eventos

- [ ] 6.1 Implementar envelope versionado e serialização, verificando teste de contrato com os campos obrigatórios
- [ ] 6.2 Garantir stream e subjects no boot, verificando existência do stream no broker
- [ ] 6.3 Implementar outbox de eventos e relay com retry, verificando publicação após broker indisponível
- [ ] 6.4 Publicar evento de mensagem recebida com mídia referenciada, verificando payload contra a spec
- [ ] 6.5 Publicar eventos de recibo, conexão e status de envio, verificando os cenários correspondentes
- [ ] 6.6 Garantir `event_id` estável para deduplicação, verificando reentrega sem efeito duplicado no consumidor de teste

## 7. Mídia

- [ ] 7.1 Implementar armazenamento com metadados, checksum e identificador opaco, verificando testes de integridade
- [ ] 7.2 Implementar download automático de mídia recebida com limite e omissão, verificando casos dentro e acima do limite
- [ ] 7.3 Implementar download autenticado com tipo de conteúdo correto, verificando respostas `200`, `401` e `404`
- [ ] 7.4 Implementar limpeza periódica por expiração, verificando remoção de arquivo e registro

## 8. Integração e verificação final

- [ ] 8.1 Escrever o README do wzap com contrato REST, subjects de eventos e checklist de pareamento, verificando revisão dos artefatos
- [ ] 8.2 Executar verificação local completa (`docker compose up`, prontidão, criação de instância e QR), verificando evidência dos healthchecks
- [ ] 8.3 Executar `go test ./...`, `golangci-lint run` e `go build` no workspace, verificando saída limpa
- [ ] 8.4 Executar checklist manual de pareamento e envio real com um número de teste, registrando a evidência no change
