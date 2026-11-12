<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Livewire\ThemeSwitcher;
use App\Models\User;
use Filament\FontProviders\LocalFontProvider;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

/** Look and theme of every Fahoba panel — household admin and system alike. */
final class FahobaBrand
{
    public static function apply(Panel $panel): Panel
    {
        return $panel
            /*
             * The wall's design (packages/shared/app/assets/css/fahoba.css):
             * terracotta as the only accent, near-white surfaces and a deep
             * neutral grey. Filament uses 50/600 in light and 950/400 in dark
             * mode — the shades therefore hit background, accent and ink of
             * both themes exactly.
             *
             * Primary 500 stays deliberately dark: Filament only uses 600 as
             * button colour when the hover shade 500 also carries light text.
             * If 500 is light enough for dark text, every button falls back to
             * a pale 400.
             */
            ->brandName('Family Home Base')
            ->brandLogo(fn (): string => asset('logo-light.svg'))
            ->darkModeBrandLogo(fn (): string => asset('logo-dark.svg'))
            ->brandLogoHeight('2rem')
            ->favicon(fn (): string => asset('favicon.svg'))
            ->font('Plus Jakarta Sans Variable', provider: LocalFontProvider::class)
            ->colors([
                'primary' => [
                    50 => '#fdf1e9',
                    100 => '#fbe3d3',
                    200 => '#fed7aa',
                    300 => '#fdc894',
                    400 => '#fdba74',
                    500 => '#cc4510',
                    600 => '#c2410c',
                    700 => '#9a3412',
                    800 => '#7c2d12',
                    900 => '#5c220e',
                    950 => '#33231a',
                ],
                'gray' => [
                    50 => '#f6f6f4',
                    100 => '#efefec',
                    200 => '#e3e3de',
                    300 => '#cfcfc9',
                    400 => '#a0a09a',
                    500 => '#7a7a74',
                    600 => '#55554f',
                    700 => '#3d3d39',
                    800 => '#232327',
                    900 => '#1a1a1d',
                    950 => '#101012',
                ],
                'danger' => Color::hex('#b42318'),
                'success' => Color::hex('#067647'),
                'warning' => Color::hex('#b45309'),
            ])
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): HtmlString => new HtmlString(sprintf(
                '<link rel="stylesheet" href="%s?v=%d">',
                asset('css/fahoba-admin.css'),
                filemtime(public_path('css/fahoba-admin.css')),
            )))

            /*
             * Light and dark as on the wall: our own switcher instead of
             * Filament's, which only knows "system". The script in the head
             * sets the theme before the page paints — otherwise the old one
             * flashes briefly after a change in the morning.
             */
            ->themeSwitcher(false)
            ->renderHook(PanelsRenderHook::HEAD_END, function (): HtmlString {
                $user = auth()->user();

                if (! $user instanceof User || $user->membership() === null) {
                    return new HtmlString('');
                }

                $theme = ThemeSwitcher::stateFor($user)['theme'];

                return new HtmlString(sprintf(
                    '<script>localStorage.setItem("theme", %s); document.documentElement.classList.toggle("dark", %s)</script>',
                    Js::from($theme),
                    Js::from($theme === 'dark'),
                ));
            })
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): HtmlString => new HtmlString(
                Blade::render('@livewire(\App\Livewire\ThemeSwitcher::class)'),
            ));
    }
}
