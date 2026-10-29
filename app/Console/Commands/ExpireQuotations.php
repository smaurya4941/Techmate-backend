<?php

namespace App\Console\Commands;

use App\Services\QuotationService;
use Illuminate\Console\Command;

class ExpireQuotations extends Command
{
    protected $signature = 'techmate:expire-quotations';

    protected $description = 'Mark sent quotations past their validity date as expired.';

    public function handle(QuotationService $quotations): int
    {
        $this->info('Expired '.$quotations->expireOverdue().' quotation(s).');

        return self::SUCCESS;
    }
}
