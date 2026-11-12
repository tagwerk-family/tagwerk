@php
    use Filament\Support\Icons\Heroicon;

    $options = [
        'auto' => [Heroicon::OutlinedClock, __('Automatic by time of day')],
        'light' => [Heroicon::OutlinedSun, __('Always light')],
        'dark' => [Heroicon::OutlinedMoon, __('Always dark')],
    ];
@endphp

{{--
    Filament reads `localStorage.theme` on load. That is why the computed
    theme lands there, never "auto" — otherwise Filament would fall back to
    the operating system.
--}}
<div
    x-data="{
        timer: null,
        apply(theme, change) {
            localStorage.setItem('theme', theme)
            document.documentElement.classList.toggle('dark', theme === 'dark')
            clearTimeout(this.timer)

            if (change) {
                // setTimeout looks ahead at most a little over 24 days.
                const wait = Math.min(Math.max(new Date(change) - Date.now(), 0) + 1000, 2147483647)
                this.timer = setTimeout(() => $wire.refresh(), wait)
            }
        },
    }"
    x-effect="apply($wire.theme, $wire.nextChange)"
    role="radiogroup"
    aria-label="{{ __('Appearance') }}"
    class="fahoba-theme"
>
    @foreach ($options as $value => [$icon, $label])
        <button
            type="button"
            role="radio"
            aria-checked="{{ $preference === $value ? 'true' : 'false' }}"
            aria-label="{{ $label }}"
            title="{{ $label }}"
            wire:click="choose('{{ $value }}')"
            @class(['fahoba-theme-button', 'is-selected' => $preference === $value])
        >
            <x-filament::icon :icon="$icon" />
        </button>
    @endforeach
</div>
