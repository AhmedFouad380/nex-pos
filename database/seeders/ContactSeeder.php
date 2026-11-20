<?php

namespace Database\Seeders;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Models\CustomerGroup;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Shared\Enums\DocumentType;
use Illuminate\Database\Seeder;

class ContactSeeder extends Seeder
{
    public function run(): void
    {
        CustomerGroup::query()->firstOrCreate(['name' => 'عملاء الجملة'], ['discount_percent' => 0]);

        if (Contact::query()->where('is_default', true)->exists()) {
            return;
        }

        Contact::query()->create([
            'type' => ContactType::Customer,
            'code' => app(DocumentNumberGenerator::class)->next(DocumentType::Contact),
            'name' => 'عميل نقدي',
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
