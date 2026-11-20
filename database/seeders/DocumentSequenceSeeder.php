<?php

namespace Database\Seeders;

use App\Domain\Foundation\Models\DocumentSequence;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Shared\Enums\DocumentType;
use Illuminate\Database\Seeder;

/** One global sequence per document type. Per-branch / yearly sequences are configured from the UI. */
class DocumentSequenceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (DocumentType::cases() as $type) {
            DocumentSequence::query()->firstOrCreate(
                ['document_type' => $type, 'location_id' => null, 'year' => null],
                [
                    'prefix' => DocumentNumberGenerator::defaultPrefix($type),
                    'next_number' => 1,
                    'padding' => 5,
                ],
            );
        }
    }
}
