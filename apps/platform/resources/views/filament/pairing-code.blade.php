{{--
    Pairing modal: a code to type, a QR code to scan.

    Both side by side because both happen — a tablet without a camera gets the
    code typed in, one with a camera scans and is done.
--}}
<div class="flex flex-col items-center gap-6 py-2">
    <p
        class="select-all font-mono text-5xl font-bold tracking-[0.2em] text-gray-950 dark:text-white"
    >{{ $code }}</p>

    <div class="w-56 rounded-xl bg-white p-3 text-gray-950 shadow-sm">
        {{ $qr }}
    </div>

    <div class="text-center text-sm text-gray-500 dark:text-gray-400">
        <p>{{ __('Scan it on the tablet — or enter the code under “Set up Family Home Base”.') }}</p>
        <p class="mt-1">{{ __('Valid until :time.', ['time' => $validUntil]) }}</p>
    </div>
</div>
