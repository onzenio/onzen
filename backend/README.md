<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# OneFisc API

API Laravel do OneFisc.

## Monitoramento SERPRO — operação

O monitoramento roda na fila dedicada `serpro` (conexão `serpro`, database). O worker do `docker-compose.yml` (`serpro-queue`) executa `queue:work serpro --tries=8 --timeout=270`; localmente, suba um worker equivalente:

```bash
php artisan queue:work serpro --tries=8 --timeout=270
```

O gate de transporte é fail-closed e vive em `config/monitoring.php` + painel da Account A:

- `MONITORING_SERPRO_DRY_RUN=true` (default) faz as execuções usarem fixtures em vez de tráfego real.
- `MONITORING_SERPRO_TRANSPORT_APPROVED=false` (default) mantém o transporte desligado; ligar é decisão do painel (`/api/admin/serpro/transport`), com dupla confirmação e evidência em produção.
- `GET /api/monitoring/health` expõe o estado efetivo (`gated`, `configured`, `unavailable`, `degraded`).

Comandos agendados: `monitoring:run-monthly-cycle` (dia 1, somente definições de consulta) e `monitoring:warm-procuracoes` (diário, renova termos e revalida outorgas).

## About Laravel

OneFisc backend (Laravel). Operação do monitoramento SERPRO:

- Fila dedicada `serpro` (driver database): `php artisan queue:work serpro --queue=serpro`.
  No compose, o serviço `serpro-worker` já sobe esse worker.
- Gate fail-closed: `MONITORING_SERPRO_DRY_RUN=true` e transporte desligado por
  padrão; o painel da Account A (`/api/admin/serpro`) dita ambiente e transporte.
- Agendamentos: `monitoring:cycle` (dia 1, 03:00, somente consultas) e
  `monitoring:renew-terms` (diário, 04:00, termos + procurações).
- Catálogo: `php artisan migrate --seed` inclui `MonitoringDefinitionSeeder`
  (versão em `CATALOG_VERSION`) e fixtures em `resources/fixtures/serpro/consultar/`.

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
