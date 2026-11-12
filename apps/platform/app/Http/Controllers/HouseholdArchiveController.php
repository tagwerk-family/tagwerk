<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The signed link from the mail or the export page; the link is the key. */
final class HouseholdArchiveController
{
    public function __invoke(int $household, string $file): StreamedResponse
    {
        $path = "exports/{$household}/{$file}.zip";

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'fahoba-household-'.$household.'.zip');
    }
}
