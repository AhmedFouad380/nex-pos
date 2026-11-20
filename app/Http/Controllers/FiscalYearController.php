<?php

namespace App\Http\Controllers;

use App\Support\FiscalYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The year switcher in the top bar: remembers the year to look at for this login. */
class FiscalYearController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $year = (int) $request->input('year');
        abort_unless(array_key_exists($year, FiscalYear::years()), 422);

        $year === FiscalYear::current()
            ? $request->session()->forget(FiscalYear::SESSION_KEY)
            : $request->session()->put(FiscalYear::SESSION_KEY, $year);

        return redirect()->back();
    }
}
