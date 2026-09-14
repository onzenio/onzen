<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ambiente SERPRO
    |--------------------------------------------------------------------------
    |
    | homologacao por padrão (fail-closed). Produção exige ação explícita
    | no painel da Account A com dupla confirmação.
    |
    */
    'environment' => env('MONITORING_SERPRO_ENVIRONMENT', 'homologacao'),

    /*
    |--------------------------------------------------------------------------
    | Dry-run
    |--------------------------------------------------------------------------
    |
    | Com dry-run ativo (padrão) nenhuma chamada HTTP real parte para a
    | SERPRO: as execuções usam fixtures oficiais do catálogo.
    |
    */
    'dry_run' => env('MONITORING_SERPRO_DRY_RUN', true),

    'base_url' => env(
        'MONITORING_SERPRO_BASE_URL',
        'https://homologacao.serpro.gov.br/integra-contador/v1'
    ),

    'token_url' => env(
        'MONITORING_SERPRO_TOKEN_URL',
        'https://homologacao.serpro.gov.br/oauth/token'
    ),

    'transport' => [
        // Interruptor de transporte: desligado por padrão (fail-closed).
        'approved' => env('MONITORING_SERPRO_TRANSPORT_APPROVED', false),
        'connect_timeout' => env('MONITORING_SERPRO_CONNECT_TIMEOUT', 5),
        'request_timeout' => env('MONITORING_SERPRO_REQUEST_TIMEOUT', 30),
        'role_type' => env('MONITORING_SERPRO_ROLE_TYPE', 'terceiro'),
    ],

    'queue' => env('MONITORING_SERPRO_QUEUE', 'serpro'),

    'limits' => [
        'max_attempts' => env('MONITORING_SERPRO_MAX_ATTEMPTS', 5),
        'backoff_base_seconds' => env('MONITORING_SERPRO_BACKOFF_BASE', 60),
    ],

    'fixtures_path' => env(
        'MONITORING_SERPRO_FIXTURES_PATH',
        resource_path('fixtures/serpro/consultar')
    ),

];
