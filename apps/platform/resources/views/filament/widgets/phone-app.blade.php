{{--
    Inline sizes: Filament's stylesheet only carries the classes it uses
    itself, custom Tailwind classes in this view would not exist.
--}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('Fahoba on your phone')" icon="heroicon-o-device-phone-mobile">
        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 1.5rem;">
            <div style="width: 9rem; padding: 0.5rem; background: #fff; border-radius: 0.75rem;">
                {{ $qr }}
            </div>

            <div style="flex: 1; min-width: 14rem;">
                <p>{{ __('Scan with the phone camera or open') }} <x-filament::link :href="$url" target="_blank">{{ $url }}</x-filament::link>.</p>
                <p style="margin-top: 0.5rem; opacity: 0.75;">
                    {{ __('Then choose “Add to Home Screen” in the browser menu — Fahoba then sits there like an app. The admin panel here stays a web page; you only need it now and then.') }}
                </p>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
