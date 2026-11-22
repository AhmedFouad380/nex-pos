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

        FiscalYear::select($year);

        return redirect()->back();
    }
}
