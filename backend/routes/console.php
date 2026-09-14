<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('monitoring:cycle')->monthlyOn(1, '03:00');
Schedule::command('monitoring:renew-terms')->dailyAt('04:00');

// Ciclo automático mensal: dia 1 às 06:00 America/Sao_Paulo, apenas
// definições marcadas como automáticas e operações de consulta (Task 18).
Schedule::command('monitoring:run-monthly-cycle --confirm')
    ->monthlyOn(1, '06:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

// Rotina diária de renovação: 05:00 America/Sao_Paulo, renova termos e
// reverifica procurações antes da janela comercial (Task 21).
Schedule::command('monitoring:warm-procuracoes --confirm')
    ->dailyAt('05:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();
