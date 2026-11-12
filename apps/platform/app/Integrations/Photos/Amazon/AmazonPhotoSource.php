<?php

declare(strict_types=1);

namespace App\Integrations\Photos\Amazon;

use App\Integrations\Photos\Folder\FolderPhotoSource;

/**
 * Amazon Photos — through a folder of downloaded pictures.
 *
 * The truth up front: Amazon Photos has no public API. The Amazon Drive API
 * was shut down in 2023 and not replaced. A provider that signs in there and
 * fetches pictures could not be built honestly; it would be a replica of the
 * web UI and would break on the next redesign there.
 *
 * What remains is a folder. The desktop app no longer syncs down to the
 * computer and rclone dropped Amazon along with Amazon Drive, so the pictures
 * get there by downloading them in the browser. That is why Amazon has an entry of its
 * own here and is not just a footnote under the folder — in the list people
 * look for "Amazon Photos", not "Ordner am Server".
 */
final class AmazonPhotoSource extends FolderPhotoSource
{
    public const KEY = 'amazon';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Amazon Photos';
    }

    public function icon(): string
    {
        return 'heroicon-o-cloud';
    }

    public function description(): string
    {
        return __('Pictures downloaded from Amazon Photos.');
    }

    /** @return array<int, string> */
    public function setupNotes(): array
    {
        return [
            __('Amazon Photos has no interface for third parties, not even through AWS. That is why Family Home Base reads a folder on the server.'),
            __('The Amazon Photos app no longer syncs to the computer automatically. Download albums and the Family Vault in the browser (Select → Download), always into the same folder — pictures already there are not downloaded twice.'),
            __('Put this folder into the household\'s photo folder on the server and enter the subfolder here.'),
        ];
    }

    protected function placeholder(): string
    {
        return 'Amazon Photos/Pictures';
    }
}
