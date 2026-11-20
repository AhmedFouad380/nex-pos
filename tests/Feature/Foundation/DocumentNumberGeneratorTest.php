<?php

use App\Domain\Foundation\Models\DocumentSequence;
use App\Domain\Foundation\Models\Location;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Shared\Enums\DocumentType;

beforeEach(fn () => $this->numbers = app(DocumentNumberGenerator::class));

it('creates a missing sequence and hands out consecutive numbers', function () {
    expect($this->numbers->next(DocumentType::Sale))->toBe('INV-00001')
        ->and($this->numbers->next(DocumentType::Sale))->toBe('INV-00002')
        ->and($this->numbers->peek(DocumentType::Sale))->toBe('INV-00003')
        ->and($this->numbers->next(DocumentType::ReceiptVoucher))->toBe('RV-00001');
});

it('prefers a branch sequence over the global one', function () {
    $branch = Location::query()->create(['code' => 'B2', 'name' => 'فرع 2']);
    DocumentSequence::query()->create(['document_type' => DocumentType::Sale, 'location_id' => $branch->id, 'prefix' => 'B2-', 'next_number' => 50, 'padding' => 4]);

    expect($this->numbers->next(DocumentType::Sale, $branch->id))->toBe('B2-0050')
        ->and($this->numbers->next(DocumentType::Sale))->toBe('INV-00001')
        ->and($this->numbers->next(DocumentType::Sale, $branch->id))->toBe('B2-0051');
});

it('uses a yearly sequence for the current year only', function () {
    DocumentSequence::query()->create(['document_type' => DocumentType::Sale, 'year' => (int) now()->format('Y'), 'prefix' => now()->format('Y').'/', 'next_number' => 7, 'padding' => 3]);
    DocumentSequence::query()->create(['document_type' => DocumentType::Sale, 'year' => (int) now()->format('Y') - 1, 'prefix' => 'OLD/', 'next_number' => 99, 'padding' => 3]);

    expect($this->numbers->next(DocumentType::Sale))->toBe(now()->format('Y').'/007');
});

it('has a default prefix for every document type', function () {
    foreach (DocumentType::cases() as $type) {
        expect(DocumentNumberGenerator::defaultPrefix($type))->not->toBeEmpty();
    }
    expect(array_keys(DocumentNumberGenerator::DEFAULT_PREFIXES))->toEqualCanonicalizing(DocumentType::values());
});
