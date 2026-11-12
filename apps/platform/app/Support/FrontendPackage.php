<?php

declare(strict_types=1);

namespace App\Support;

use Composer\InstalledVersions;
use OutOfBoundsException;

/**
 * A frontend in place of the official app: a Composer package whose
 * `extra.fahoba.app` names a directory of built files with an
 * `index.html` (`FAHOBA_FRONTEND`, `fahoba:frontend`).
 *
 * Checked before nginx serves it, and refused rather than half served:
 * the app's Content Security Policy allows scripts from this origin only,
 * so an inline or foreign script would leave a blank page.
 */
final class FrontendPackage
{
    /** Where nginx looks for the files besides `index.html`. */
    public const ASSETS = 'assets';

    /** The built app's directory, or why it cannot be served. */
    public static function resolve(string $name): string
    {
        try {
            $path = InstalledVersions::getInstallPath($name);
        } catch (OutOfBoundsException) {
            $path = null;
        }

        return $path === null ? "{$name} is not installed." : self::check($name, $path);
    }

    /** @return string the directory (absolute, starting with "/") or the reason */
    public static function check(string $name, string $packagePath): string
    {
        $package = realpath($packagePath);
        $composer = $package === false || ! is_file("{$package}/composer.json")
            ? null
            : json_decode((string) file_get_contents("{$package}/composer.json"), true);
        $app = is_array($composer) ? ($composer['extra']['fahoba']['app'] ?? null) : null;

        if (! is_string($app) || $app === '') {
            return "{$name} names no built app (extra.fahoba.app in composer.json).";
        }

        $directory = realpath("{$package}/{$app}");

        if ($directory === false || ! str_starts_with($directory.'/', $package.'/') || ! is_file("{$directory}/index.html")) {
            return "{$name}: {$app}/index.html does not exist inside the package.";
        }

        $html = (string) file_get_contents("{$directory}/index.html");

        preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $scripts, PREG_SET_ORDER);

        foreach ($scripts as [, $attributes, $body]) {
            if (preg_match('/\bsrc\s*=\s*["\']?([^"\'\s>]+)/i', $attributes, $source) !== 1) {
                if (trim($body) !== '' && ! preg_match('/\btype\s*=\s*["\']?(application\/(ld\+)?json|importmap)/i', $attributes)) {
                    return "{$name}: index.html has an inline script; the app's Content Security Policy allows scripts from files of this origin only.";
                }

                if (preg_match('/\btype\s*=\s*["\']?importmap/i', $attributes)) {
                    return "{$name}: index.html has an import map, which can only be inline and is blocked by the Content Security Policy.";
                }

                continue;
            }

            if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $source[1]) === 1) {
                return "{$name}: index.html loads {$source[1]}; scripts must come from this origin.";
            }
        }

        return $directory;
    }
}
