<?php

use Illuminate\Support\Facades\Schedule;

// Lee el buzón de facturas cada 2 minutos (si INBOX_ENABLED=true).
Schedule::command('inbox:fetch')
    ->everyTwoMinutes()
    ->withoutOverlapping(10)
    ->when(fn () => (bool) config('invoices.inbox.enabled'));

// Horizon: métricas de las colas para el panel.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
