<?php

return [
    'base_url' => env('MONITORING_SERPRO_BASE_URL', 'https://gateway.apiserpro.serpro.gov.br/integra-contador/v1'),
    'token_url' => env('MONITORING_SERPRO_TOKEN_URL', 'https://autenticacao.sapi.serpro.gov.br/authenticate'),
    'environment' => env('MONITORING_SERPRO_ENVIRONMENT', 'homologacao'),
    'dry_run' => filter_var(env('MONITORING_SERPRO_DRY_RUN', true), FILTER_VALIDATE_BOOLEAN),
    // Versão do cache de token: bump operacional (env) para invalidar tokens
    // emitidos antes de uma rotação de credenciais.
    'credential_version' => (int) env('MONITORING_SERPRO_CREDENTIAL_VERSION', 1),
    'transport' => [
        'approved' => filter_var(env('MONITORING_SERPRO_TRANSPORT_APPROVED', false), FILTER_VALIDATE_BOOLEAN),
        'timeout' => (int) env('MONITORING_SERPRO_TIMEOUT', 15),
        'connect_timeout' => (int) env('MONITORING_SERPRO_CONNECT_TIMEOUT', 5),
        'role_type' => env('MONITORING_SERPRO_ROLE_TYPE', 'TERCEIROS'),
    ],
    'queue' => env('MONITORING_SERPRO_QUEUE', 'serpro'),
    'queue_connection' => env('MONITORING_SERPRO_QUEUE_CONNECTION', 'serpro'),
    // Caminhos relativos são resolvidos contra base_path(); valores absolutos passam direto.
    'fixtures_path' => env('MONITORING_SERPRO_FIXTURES_PATH', 'resources/fixtures/serpro/consultar'),
    'limits' => [
        'max_attempts' => (int) env('MONITORING_SERPRO_MAX_ATTEMPTS', 8),
    ],
];
