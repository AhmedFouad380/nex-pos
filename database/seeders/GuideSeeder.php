<?php

namespace Database\Seeders;

use App\Domain\CashManagement\Actions\OpenCashSession;
use App\Domain\CashManagement\Models\CashRegister;
use App\Domain\CRM\Actions\SaveContact;
use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Foundation\Models\Location;
use App\Domain\Payroll\Actions\ApprovePayroll;
use App\Domain\Payroll\Actions\GeneratePayroll;
use App\Domain\Payroll\Actions\GiveAdvance;
use App\Domain\Payroll\Actions\PayPayroll;
use App\Domain\Payroll\Models\EmployeeProfile;
use App\Domain\Wallet\Actions\IngestWalletMessage;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDevice;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Database\Seeder;

/**
 * Data for the screenshots of the user guide (php artisan guide:build), on top of DemoSeeder.
 * Run it only on the separate guide database, never on a client's data.
 */
class GuideSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('GuideSeeder refuses to run in production.');

            return;
        }
        $this->call(DemoSeeder::class);
        if (EmployeeProfile::query()->exists()) {
            return;
        }

        $admin = User::query()->where('email', config('newpos.seed_admin.email'))->firstOrFail();
        $admin->forceFill(['name' => 'مدير النظام', 'pin' => '1234'])->save();
        auth()->setUser($admin);

        $settings = app(GeneralSettings::class);
        $settings->business_name = 'متجر النور';
        $settings->phone = '0223456789';
        $settings->address = '15 شارع التحرير، الدقي، الجيزة';
        $settings->save();

        $location = Location::query()->firstOrFail();
        $location->update(['name' => 'الفرع الرئيسي', 'address' => '15 شارع التحرير، الدقي', 'phone' => '0223456789']);
        Location::query()->firstOrCreate(['code' => 'BR2'], ['name' => 'فرع المعادي', 'address' => 'شارع 9، المعادي']);
        $cash = PaymentMethod::query()->where('type', 'cash')->value('id');

        // employees, an advance, last month paid and this month in draft
        $employees = collect([
            ['محمد عبد الله', 'كاشير', 6000, 500],
            ['منى حسن', 'محاسبة', 8000, 700],
            ['خالد سمير', 'أمين مخزن', 5500, 400],
        ])->map(function (array $row) use ($location) {
            [$name, $job, $salary, $allowances] = $row;
            $employee = app(SaveContact::class)->handle(['type' => 'employee', 'name' => $name, 'mobile' => '010'.random_int(10000000, 99999999)]);
            $employee->employeeProfile()->create(['location_id' => $location->id, 'job_title' => $job, 'base_salary' => $salary,
                'fixed_allowances' => $allowances, 'hire_date' => now()->subYear()]);

            return $employee;
        });
        app(GiveAdvance::class)->handle(['contact_id' => $employees[0]->id, 'location_id' => $location->id, 'amount' => 1500,
            'installment_amount' => 500, 'payment_method_id' => $cash, 'advance_date' => now()->subMonths(1)->startOfMonth()->addDays(9)]);

        $last = app(GeneratePayroll::class)->handle(now()->subMonth()->startOfMonth(), $location->id);
        app(PayPayroll::class)->handle(app(ApprovePayroll::class)->handle($last), $cash, paidAt: now()->subMonth()->endOfMonth());
        app(GeneratePayroll::class)->handle(now()->startOfMonth(), $location->id);

        // a wallet line, its phone and two transfers waiting for confirmation
        $wallet = Wallet::query()->create(['provider' => WalletProvider::VodafoneCash, 'name' => 'فودافون كاش الفرع',
            'phone' => '01099638383', 'location_id' => $location->id]);
        $device = WalletDevice::query()->create(['name' => 'Samsung A15', 'location_id' => $location->id, 'platform' => 'android',
            'app_version' => '1.0.0', 'last_seen_at' => now(), 'paired_by' => $admin->id]);
        $customer = Contact::query()->where('mobile', '01001234567')->first();
        $date = now()->format('H:i y-m-d');
        app(IngestWalletMessage::class)->handle($device, "تم استلام مبلغ 750.00 جنيه من {$customer?->mobile}؛ المسجل بإسم {$customer?->name} على رقم محفظتك {$wallet->phone} بتاريخ {$date}. رصيدك الحالي: 2750.00 جنيه رقم العملية: 025845786581", 'VF-Cash');
        app(IngestWalletMessage::class)->handle($device, "تم تحويل 300 جنيه لرقم 01097746217 مصاريف الخدمة 1 جنيه رصيد حسابك فى فودافون كاش الحالي 2449. تاريخ العملية {$date} : رقم العملية 025851509705", 'VF-Cash');

        // an open shift so the cash register screen shows the sales screen
        app(OpenCashSession::class)->handle(CashRegister::query()->firstOrFail(), $admin, 500);

        auth()->forgetUser();
    }
}
