<?php

use App\Http\Controllers\Api\AccountSettingsController;
use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CompanyExportController;
use App\Http\Controllers\Api\CompanyVatSummaryController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentReviewController;
use App\Http\Controllers\Api\DocumentUploadController;
use App\Http\Controllers\Api\HoldedConnectionController;
use App\Http\Controllers\Api\InboundEmailController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MetricsController;
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
    Route::get('metrics', MetricsController::class)->name('metrics');

    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::get('companies/{company}/export', [CompanyExportController::class, 'download'])->name('companies.export');
    Route::post('companies/{company}/export/email', [CompanyExportController::class, 'email'])->name('companies.export.email');
    Route::get('companies/{company}/vat-summary', CompanyVatSummaryController::class)->name('companies.vat-summary');
    Route::put('companies/{company}/holded', [HoldedConnectionController::class, 'update'])->name('companies.holded.update');
    Route::delete('companies/{company}/holded', [HoldedConnectionController::class, 'destroy'])->name('companies.holded.destroy');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/counts', [DocumentController::class, 'counts'])->name('documents.counts');
    Route::post('documents', DocumentUploadController::class)->name('documents.upload');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
    Route::put('documents/{document}/company', [DocumentController::class, 'assign'])->name('documents.assign');

    // Revisión por excepción
    Route::post('documents/{document}/approve', [DocumentReviewController::class, 'approve'])->name('documents.approve');
    Route::post('documents/{document}/not-invoice', [DocumentReviewController::class, 'notInvoice'])->name('documents.not-invoice');
    Route::post('documents/{document}/duplicate', [DocumentReviewController::class, 'duplicate'])->name('documents.duplicate');
    Route::post('documents/{document}/file', [DocumentReviewController::class, 'replaceFile'])->name('documents.replace-file');
    Route::post('documents/{document}/reprocess', [DocumentReviewController::class, 'reprocess'])->name('documents.reprocess');

    Route::get('inbound-emails', [InboundEmailController::class, 'index'])->name('inbound-emails.index');
    Route::get('inbound-emails/{inboundEmail}', [InboundEmailController::class, 'show'])->name('inbound-emails.show');
});
