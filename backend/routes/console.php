<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ciclo automático mensal: dia 1 às 06:00 America/Sao_Paulo, apenas
// definições marcadas como automáticas e operações de consulta (Task 18).
Schedule::command('monitoring:run-monthly-cycle --confirm')
    ->monthlyOn(1, '06:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();
