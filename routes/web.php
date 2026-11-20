<?php

use App\Http\Controllers\DocumentPrintController;
use App\Http\Controllers\FiscalYearController;
use App\Http\Controllers\LabelPrintController;
use App\Http\Controllers\PayrollPrintController;
use App\Http\Controllers\UserGuideController;
use App\Http\Controllers\VoucherPrintController;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Route;

// the home page: a guest goes to the login, a signed-in user to the panel they may open (a cashier: the POS)
Route::get('/', function () {
    $user = auth()->user();
    if (! $user instanceof User) {
        return redirect()->to(Filament::getPanel('admin')->getLoginUrl() ?? '/admin/login');
    }
    $panel = collect(Filament::getPanels())->first(fn (Panel $panel) => $user->canAccessPanel($panel));

    return redirect()->to($panel?->getUrl() ?? Filament::getPanel('admin')->getLoginUrl() ?? '/admin/login');
})->name('home');

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/pos/receipt/{sale}', [DocumentPrintController::class, 'receipt'])->name('pos.receipt');
    Route::get('/print/sales/{sale}', [DocumentPrintController::class, 'sale'])->name('print.sale');
    Route::get('/print/sale-returns/{return}', [DocumentPrintController::class, 'saleReturn'])->name('print.sale_return');
    Route::get('/print/labels', LabelPrintController::class)->name('print.labels');
    Route::get('/print/payroll/{run}', [PayrollPrintController::class, 'sheet'])->name('payroll.print');
    Route::get('/print/payroll/{run}/payslips', [PayrollPrintController::class, 'payslips'])->name('payroll.payslips');
    Route::get('/guide/print', UserGuideController::class)->name('guide.print');
    Route::post('/fiscal-year', FiscalYearController::class)->name('fiscal-year.switch');
});
Route::middleware(['web', 'auth'])->get('/admin/vouchers/{payment}/print', VoucherPrintController::class)->name('vouchers.print');
