<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/** The number people know a document by, whatever the column is called (models are strict: no guessing reads). */
final class DocumentNumber
{
    public static function of(?Model $document): ?string
    {
        $attributes = $document?->getAttributes() ?? [];
        $number = $attributes['number'] ?? $attributes['invoice_no'] ?? $attributes['draft_no'] ?? null;

        return $number !== null ? (string) $number : null;
    }
}
