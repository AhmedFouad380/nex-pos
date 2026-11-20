<?php

namespace App\Domain\Legacy\Import;

/** One step of the import (archive, accounts, contacts, sales…). Must be safe to run again. */
interface Stage
{
    public function name(): string;

    public function run(LegacyPackage $package, ImportContext $context): void;
}
