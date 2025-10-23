<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImportController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/imports', [ImportController::class, 'index']);
    Route::post('/imports', [ImportController::class, 'store']);
    Route::post('/imports/{importJob}/dry-run', [ImportController::class, 'dryRun'])->name('imports.dry-run');
    Route::post('/imports/{importJob}/apply', [ImportController::class, 'apply'])->name('imports.apply');
    Route::get('/imports/{importJob}/errors', [ImportController::class, 'downloadErrors'])->name('imports.errors');
    Route::post('/logout', [AuthController::class, 'logout']);
});
