<?php

use App\Http\Controllers\Api\DocumentUploadController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\UploadPageController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

// Toda ruta autenticada debe llevar "tenant" (lo comprueba un test).
Route::middleware(['auth', 'tenant'])->group(function () {
    Route::redirect('/', '/subir');
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('subir', UploadPageController::class)->name('documents.upload.show');
    Route::post('subir', DocumentUploadController::class)->name('documents.upload.web');
});
