<?php

use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\PanelController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

// Toda ruta autenticada debe llevar "tenant" (lo comprueba un test).
Route::middleware(['auth', 'tenant'])->group(function () {
    Route::redirect('/', '/panel');
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // La subida manual vive en el panel; la dirección corta sigue funcionando.
    Route::redirect('subir', '/panel/subir')->name('documents.upload.show');

    // Panel (Vue). Todas sus rutas las resuelve el router del navegador.
    Route::get('panel/{any?}', PanelController::class)->where('any', '.*')->name('panel');
});
