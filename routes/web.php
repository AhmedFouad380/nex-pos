<?php

use App\Http\Controllers\DocumentPrintController;
use App\Http\Controllers\LabelPrintController;
use App\Http\Controllers\PayrollPrintController;
use App\Http\Controllers\UserGuideController;
use App\Http\Controllers\VoucherPrintController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/pos/receipt/{sale}', [DocumentPrintController::class, 'receipt'])->name('pos.receipt');
    Route::get('/print/sales/{sale}', [DocumentPrintController::class, 'sale'])->name('print.sale');
    Route::get('/print/sale-returns/{return}', [DocumentPrintController::class, 'saleReturn'])->name('print.sale_return');
    Route::get('/print/labels', LabelPrintController::class)->name('print.labels');
    Route::get('/print/payroll/{run}', [PayrollPrintController::class, 'sheet'])->name('payroll.print');
    Route::get('/print/payroll/{run}/payslips', [PayrollPrintController::class, 'payslips'])->name('payroll.payslips');
    Route::get('/guide/print', UserGuideController::class)->name('guide.print');
});
Route::middleware(['web', 'auth'])->get('/admin/vouchers/{payment}/print', VoucherPrintController::class)->name('vouchers.print');
