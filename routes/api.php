<?php

use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DocumentController;
use Illuminate\Support\Facades\Route;

Route::post('auth/token', [AuthTokenController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('auth.token');

// Toda ruta autenticada debe llevar "tenant" (lo comprueba un test).
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::delete('auth/token', [AuthTokenController::class, 'destroy'])->name('auth.logout');

    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
});
