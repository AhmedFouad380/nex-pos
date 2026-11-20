<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Models\Currency;
use App\Domain\Payroll\Models\PayrollRun;
use App\Filament\Admin\Resources\PayrollRuns\PayrollRunResource;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\ArabicAmountInWords;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Printable payroll sheet (كشف الرواتب, A4 landscape) and payslips (قسائم المرتبات, two per A4 page). */
class PayrollPrintController
{
    public function sheet(Request $request, PayrollRun $run): View
    {
        return view('payroll.sheet', $this->data($request, $run));
    }

    public function payslips(Request $request, PayrollRun $run): View
    {
        $data = $this->data($request, $run);
        if ($request->filled('line')) {
            $data['lines'] = $data['lines']->where('id', (int) $request->query('line'))->values();
        }
        $data['words'] = fn ($amount) => ArabicAmountInWords::money($amount, $data['settings']->currency_code);

        return view('payroll.payslips', $data);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, PayrollRun $run): array
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can('payroll.view'), 403);
        // same branch limits as the payroll screens
        abort_unless(PayrollRunResource::getEloquentQuery()->whereKey($run->id)->exists(), 404);

        $run->load(['lines.contact.employeeProfile', 'lines.payment', 'location', 'creator', 'approver']);
        $settings = app(GeneralSettings::class);

        return [
            'run' => $run,
            'lines' => $run->lines->sortBy('contact.name')->values(),
            'settings' => $settings,
            'symbol' => Currency::query()->where('code', $settings->currency_code)->value('symbol') ?? $settings->currency_code,
            'logo' => $settings->logoDataUri(),
            'autoPrint' => $request->boolean('print', true),
        ];
    }
}
