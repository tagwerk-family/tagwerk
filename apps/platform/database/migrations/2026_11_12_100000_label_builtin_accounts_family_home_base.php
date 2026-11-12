<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The family reads the name written out: the built-in connections were
 * labelled "Fahoba" in 0.3.0.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update("update provider_accounts set account_label = 'Family Home Base' where provider = 'builtin' and account_label = 'Fahoba'");
    }

    public function down(): void
    {
        DB::update("update provider_accounts set account_label = 'Fahoba' where provider = 'builtin' and account_label = 'Family Home Base'");
    }
};
