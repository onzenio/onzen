## Context

Veja `proposal.md` para a motivação. A aplicação usa o Nuxt como BFF: o browser guarda apenas `onefisc_session`, enquanto os cookies Sanctum e o token CSRF do Laravel ficam selados dentro dela. O caminho genérico já restaura essa sessão, mas proxies específicos mantêm uma segunda implementação que encaminha o cookie selado e lê outra variável de ambiente.

O backend já representa Modules no Plan e possui uma consulta de entitlement, mas as rotas aplicam somente autenticação e Role. Execuções e ações SERPRO persistem `running` antes da chamada externa e tratam qualquer registro nesse estado como ainda pertencente a outro worker, mesmo após a reserva da fila expirar. O scheduler define comandos atuais e legados, porém o Compose não mantém um processo que o execute. O Onboarding verifica a base antes da transação e não possui uma garantia de unicidade para a Account A.

## Goals / Non-Goals

**Goals:**

- Ter um único caminho de transporte BFF para sessão, CSRF, erros e configuração do backend, com uma variante binária que preserve os mesmos invariantes.
- Tornar entitlement de Module uma decisão do backend aplicada de forma consistente a grupos de rotas.
- Resolver estados `running` abandonados sem assumir garantia externa de exactly-once.
- Tornar scheduler e Onboarding seguros sob concorrência e verificáveis nos ambientes usados pelo projeto.

**Non-Goals:**

- Mudar payloads públicos, nomenclatura do domínio, horários de negócio ou regras de Role existentes.
- Introduzir outro mecanismo de autenticação, fila, lock distribuído ou scheduler.
- Repetir automaticamente uma emissão SERPRO de resultado desconhecido.

## Decisions

### Unificar o transporte do BFF

Os proxies JSON e binário usarão a mesma resolução por `BACKEND_URL`, restauração de `onefisc_session`, propagação dos cookies Laravel e inclusão de CSRF nas mutações. A variante binária preservará apenas cabeçalhos seguros de download e converterá erros do backend sem expor detalhes internos. Os handlers específicos podem permanecer quando adaptam caminhos ou corpos, mas não implementarão transporte próprio.

Isso elimina a divergência entre `BACKEND_URL` e `NUXT_BACKEND_URL` e mantém um único contrato operacional no Compose e fora dele. A alternativa de encaminhar `onefisc_session` diretamente foi rejeitada porque o Laravel não conhece seu formato; remover todos os handlers específicos também foi rejeitado porque alguns fazem adaptação binária ou de rota.

### Usar links assinados relativos através do BFF

O backend fornecerá links relativos de artefato e validará assinaturas relativas. O browser chamará a server route Nuxt na mesma origem; o BFF restaurará a sessão e encaminhará caminho e query assinados sem reconstruí-los. A assinatura continuará sendo validada no backend, que continuará responsável por autorização por Account e Audit.

A alternativa de expor a origem do Laravel foi rejeitada porque transfere ao browser cookies que deliberadamente ficam selados no BFF. Assinar uma URL nova no Nuxt também foi rejeitado por duplicar a confiança e a configuração criptográfica do backend.

### Aplicar entitlement por middleware de rota

Um middleware autenticado resolverá a Account efetiva e aceitará um ou mais Modules equivalentes para o grupo de rotas. Rotas de Clients exigirão `clients`; rotas de monitoramento aceitarão `monitoring` ou `clients`, preservando a semântica já usada pelo frontend e pelos Plans iniciais. A ausência de Plan continuará seguindo a regra atual da Account A; quando há Plan, ausência do Module produzirá uma resposta de upgrade sem executar o controller.

Centralizar em middleware foi escolhido sobre repetir verificações em controllers ou policies porque o entitlement pertence ao Module inteiro e deve cobrir leituras, escritas e novas rotas por agrupamento. Policies continuarão responsáveis por Role e propriedade dos registros.

### Tratar `running` como reserva com prazo

O instante de início persistido será a referência de uma janela configurável, coerente com `retry_after` e timeout do worker. Uma entrega durante a janela não executará tráfego e será liberada para depois do vencimento. Após o vencimento, um claim atômico decidirá entre apenas um worker recuperador e concorrentes.

Se houver protocolo persistido, a recuperação volta ao estado retryable apropriado e faz somente polling. Sem protocolo, o resultado externo é indeterminado: o registro termina com um código operacional explícito e nenhum envio original é repetido. A alternativa de reenviar com a mesma chave de idempotência foi rejeitada porque não há garantia documentada de exactly-once pelo SERPRO; deixar `running` intocado foi rejeitado porque perde o trabalho silenciosamente.

### Executar somente as automações atuais

O Compose ganhará um serviço dedicado a `schedule:work`, compartilhando imagem, configuração e dependência de saúde do backend. Os dois schedules legados serão removidos; o ciclo mensal e a rotina diária atuais permanecem com timezone e proteção contra sobreposição. Uma instância separada foi escolhida sobre incorporar scheduler ao processo HTTP ou ao worker porque mantém lifecycle e falhas observáveis e independentes.

### Garantir uma única Account A no banco

Uma restrição única parcial para o perfil A será a autoridade final contra concorrência, e o Onboarding converterá a violação esperada em conflito sem autenticar o perdedor. A verificação de disponibilidade continuará existindo para UX, mas não será usada como garantia de integridade. A migração fará preflight de dados existentes e falhará de forma explícita se já houver múltiplas Accounts A.

Um lock apenas em aplicação foi rejeitado porque a base inicialmente vazia não oferece uma linha estável para bloquear e locks por processo não protegem múltiplas instâncias. Tornar `profile` globalmente único foi rejeitado porque impediria múltiplas Accounts B.

## Risks / Trade-offs

- [Risk] Uma execução externa pode ter sido concluída antes da queda, mas sem protocolo persistido → Mitigação: encerrar como resultado indeterminado e exigir reconciliação explícita, priorizando não duplicar uma ação fiscal.
- [Risk] Uma janela curta pode classificar trabalho legítimo como abandonado → Mitigação: derivar o prazo de valores superiores ao timeout do transporte/worker e testar a relação com `retry_after`.
- [Risk] Assinaturas relativas podem ser aceitas fora do BFF se a rota for conhecida → Mitigação: manter autenticação Sanctum, autorização por Account, expiração curta e Audit no backend.
- [Risk] Uma rota nova pode ficar fora do grupo protegido por Module → Mitigação: testes de rota cobrem todos os endpoints de Clients e monitoramento e falham quando um endpoint protegido não recebe entitlement.
- [Risk] A restrição da Account A pode falhar ao migrar uma base já inconsistente → Mitigação: detectar duplicatas antes de criar o índice e abortar sem apagar ou escolher dados automaticamente.
- [Risk] Mais um processo Compose aumenta consumo local → Mitigação: o scheduler é leve, reutiliza a imagem e não agrega dependências.

## Migration Plan

1. Verificar que bases existentes possuem no máximo uma Account A e aplicar a restrição de unicidade antes de liberar o Onboarding concorrente.
2. Publicar backend e frontend compatíveis no mesmo rollout, pois links relativos dependem do proxy binário autenticado.
3. Subir o serviço de scheduler e confirmar que apenas as duas rotinas atuais aparecem antes de remover qualquer cron externo equivalente.
4. Manter rollback da aplicação compatível com o índice parcial; remover o índice somente se for necessário reverter também a garantia de Onboarding.
