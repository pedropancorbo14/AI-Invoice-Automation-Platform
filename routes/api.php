<?php

use App\Http\Controllers\Api\AccountSettingsController;
use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentUploadController;
use App\Http\Controllers\Api\InboundEmailController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

Route::post('auth/token', [AuthTokenController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('auth.token');

// Toda ruta autenticada debe llevar "tenant" (lo comprueba un test).
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::delete('auth/token', [AuthTokenController::class, 'destroy'])->name('auth.logout');
    Route::get('me', MeController::class)->name('me');
    Route::get('account/settings', [AccountSettingsController::class, 'show'])->name('account.settings.show');
    Route::patch('account/settings', [AccountSettingsController::class, 'update'])->name('account.settings.update');

    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', DocumentUploadController::class)->name('documents.upload');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
    Route::put('documents/{document}/company', [DocumentController::class, 'assign'])->name('documents.assign');

    Route::get('inbound-emails', [InboundEmailController::class, 'index'])->name('inbound-emails.index');
    Route::get('inbound-emails/{inboundEmail}', [InboundEmailController::class, 'show'])->name('inbound-emails.show');
});
