<?php

namespace App\Domain\Finance\Models;

use App\Domain\CRM\Models\Contact;
use App\Domain\Foundation\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    public $timestamps = false;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'debit' => 0,
        'credit' => 0,
    ];

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'entry_date',
        'debit',
        'credit',
        'contact_id',
        'location_id',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
