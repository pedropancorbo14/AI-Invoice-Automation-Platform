<?php

use Illuminate\Support\Facades\Schedule;

// Lee el buzón de facturas cada 2 minutos (si INBOX_ENABLED=true).
Schedule::command('inbox:fetch')
    ->everyTwoMinutes()
    ->withoutOverlapping(10)
    ->when(fn () => (bool) config('invoices.inbox.enabled'));

// Resumen por email de lo pendiente (diario, o los lunes si es semanal).
Schedule::command('invoices:send-digests')
    ->dailyAt('08:00')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping();

// Horizon: métricas de las colas para el panel.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
