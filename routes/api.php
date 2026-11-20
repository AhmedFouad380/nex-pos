<?php

use App\Http\Controllers\Api\WalletDeviceController;
use App\Http\Middleware\AuthenticateWalletDevice;
use Illuminate\Support\Facades\Route;

// Mobile wallet app (mobile/): pairs with a one-time QR code, then calls everything as its device.
Route::prefix('wallet')->name('api.wallet.')->group(function () {
    Route::post('pair', [WalletDeviceController::class, 'pair'])->middleware('throttle:10,1')->name('pair');

    Route::middleware(['auth:sanctum', AuthenticateWalletDevice::class, 'throttle:120,1'])->group(function () {
        Route::get('config', [WalletDeviceController::class, 'showConfig'])->name('config');
        Route::post('heartbeat', [WalletDeviceController::class, 'heartbeat'])->name('heartbeat');
        Route::post('messages', [WalletDeviceController::class, 'messages'])->name('messages');
        Route::get('transactions', [WalletDeviceController::class, 'transactions'])->name('transactions');
        Route::get('transactions/{transaction}', [WalletDeviceController::class, 'show'])->name('transactions.show');
        Route::post('transactions/{transaction}/confirm', [WalletDeviceController::class, 'confirm'])->name('transactions.confirm');
        Route::post('transactions/{transaction}/reject', [WalletDeviceController::class, 'reject'])->name('transactions.reject');
        Route::get('targets', [WalletDeviceController::class, 'targets'])->name('targets');
        Route::patch('device', [WalletDeviceController::class, 'moveDevice'])->name('device');
    });
});
